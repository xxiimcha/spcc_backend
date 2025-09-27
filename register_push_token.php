<?php
/**
 * Register Push Token API
 * Handles registration and management of push notification tokens for professors
 */

include 'cors_helper.php';
handleCORS();
include 'connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Only POST method is allowed'
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON data'
    ]);
    exit;
}

// Validate required fields
if (!isset($data['professor_id']) || !isset($data['push_token'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Professor ID and push token are required'
    ]);
    exit;
}

$professorId = $conn->real_escape_string($data['professor_id']);
$pushToken = $conn->real_escape_string($data['push_token']);
$deviceType = isset($data['device_type']) ? $conn->real_escape_string($data['device_type']) : 'unknown';
$deviceInfo = isset($data['device_info']) ? $conn->real_escape_string($data['device_info']) : null;

try {
    // Check if professor exists
    $checkProfessor = "SELECT prof_id FROM professors WHERE prof_id = ?";
    $stmt = $conn->prepare($checkProfessor);
    $stmt->bind_param("s", $professorId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Professor not found'
        ]);
        exit;
    }
    
    // Create push_tokens table if it doesn't exist
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS push_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            professor_id VARCHAR(50) NOT NULL,
            push_token TEXT NOT NULL,
            device_type VARCHAR(20) DEFAULT 'unknown',
            device_info TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_professor_token (professor_id, push_token(255)),
            FOREIGN KEY (professor_id) REFERENCES professors(prof_id) ON DELETE CASCADE
        )
    ";
    
    $conn->query($createTableSQL);
    
    // Check if token already exists for this professor
    $checkToken = "SELECT id, is_active FROM push_tokens WHERE professor_id = ? AND push_token = ?";
    $stmt = $conn->prepare($checkToken);
    $stmt->bind_param("ss", $professorId, $pushToken);
    $stmt->execute();
    $tokenResult = $stmt->get_result();
    
    if ($tokenResult->num_rows > 0) {
        // Update existing token
        $tokenRow = $tokenResult->fetch_assoc();
        $updateToken = "UPDATE push_tokens SET device_type = ?, device_info = ?, is_active = TRUE, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $conn->prepare($updateToken);
        $stmt->bind_param("ssi", $deviceType, $deviceInfo, $tokenRow['id']);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Push token updated successfully',
                'action' => 'updated'
            ]);
        } else {
            throw new Exception('Failed to update push token: ' . $conn->error);
        }
    } else {
        // Insert new token
        $insertToken = "INSERT INTO push_tokens (professor_id, push_token, device_type, device_info) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($insertToken);
        $stmt->bind_param("ssss", $professorId, $pushToken, $deviceType, $deviceInfo);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Push token registered successfully',
                'action' => 'created',
                'token_id' => $conn->insert_id
            ]);
        } else {
            throw new Exception('Failed to register push token: ' . $conn->error);
        }
    }
    
    // Clean up old/inactive tokens for this professor (keep only last 3 active tokens)
    $cleanupTokens = "
        DELETE FROM push_tokens 
        WHERE professor_id = ? 
        AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM push_tokens 
                WHERE professor_id = ? AND is_active = TRUE 
                ORDER BY updated_at DESC 
                LIMIT 3
            ) as recent_tokens
        )
    ";
    $stmt = $conn->prepare($cleanupTokens);
    $stmt->bind_param("ss", $professorId, $professorId);
    $stmt->execute();
    
} catch (Exception $e) {
    error_log("Push token registration error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to register push token: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
