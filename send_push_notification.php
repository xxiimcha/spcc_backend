<?php
/**
 * Send Push Notification API
 * Handles sending push notifications to professors via Expo Push Notification Service
 */

include 'cors_helper.php';
handleCORS();
include 'connect.php';

header('Content-Type: application/json');

class PushNotificationService {
    private $conn;
    private $expoApiUrl = 'https://exp.host/--/api/v2/push/send';
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Send notification to specific professor
     */
    public function sendToProfessor($professorId, $notification) {
        try {
            // Get active push tokens for professor
            $tokens = $this->getProfessorTokens($professorId);
            
            if (empty($tokens)) {
                return [
                    'success' => false,
                    'message' => 'No active push tokens found for professor'
                ];
            }
            
            return $this->sendToTokens($tokens, $notification);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to send notification: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Send notification to multiple professors
     */
    public function sendToMultipleProfessors($professorIds, $notification) {
        try {
            $allTokens = [];
            $results = [];
            
            foreach ($professorIds as $professorId) {
                $tokens = $this->getProfessorTokens($professorId);
                $allTokens = array_merge($allTokens, $tokens);
            }
            
            if (empty($allTokens)) {
                return [
                    'success' => false,
                    'message' => 'No active push tokens found for any professor'
                ];
            }
            
            return $this->sendToTokens($allTokens, $notification);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to send notifications: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Send schedule change notification
     */
    public function sendScheduleChangeNotification($professorId, $scheduleData) {
        $notification = [
            'title' => 'Schedule Updated',
            'body' => "Your schedule for {$scheduleData['subject']} has been updated",
            'data' => [
                'type' => 'schedule_change',
                'schedule_id' => $scheduleData['schedule_id'],
                'subject' => $scheduleData['subject'],
                'start_time' => $scheduleData['start_time'],
                'end_time' => $scheduleData['end_time'],
                'room' => $scheduleData['room'] ?? null,
                'date' => $scheduleData['date'] ?? null
            ],
            'sound' => 'default',
            'priority' => 'high'
        ];
        
        return $this->sendToProfessor($professorId, $notification);
    }
    
    /**
     * Send new schedule notification
     */
    public function sendNewScheduleNotification($professorId, $scheduleData) {
        $notification = [
            'title' => 'New Schedule Assigned',
            'body' => "You have been assigned to teach {$scheduleData['subject']}",
            'data' => [
                'type' => 'new_schedule',
                'schedule_id' => $scheduleData['schedule_id'],
                'subject' => $scheduleData['subject'],
                'start_time' => $scheduleData['start_time'],
                'end_time' => $scheduleData['end_time'],
                'room' => $scheduleData['room'] ?? null,
                'section' => $scheduleData['section'] ?? null
            ],
            'sound' => 'default',
            'priority' => 'high'
        ];
        
        return $this->sendToProfessor($professorId, $notification);
    }
    
    /**
     * Send urgent notification to all professors
     */
    public function sendUrgentNotification($message, $data = []) {
        try {
            // Get all active professor tokens
            $sql = "SELECT DISTINCT push_token FROM push_tokens WHERE is_active = TRUE";
            $result = $this->conn->query($sql);
            
            $tokens = [];
            while ($row = $result->fetch_assoc()) {
                $tokens[] = $row['push_token'];
            }
            
            if (empty($tokens)) {
                return [
                    'success' => false,
                    'message' => 'No active push tokens found'
                ];
            }
            
            $notification = [
                'title' => 'Urgent Notice',
                'body' => $message,
                'data' => array_merge($data, ['type' => 'urgent']),
                'sound' => 'default',
                'priority' => 'high'
            ];
            
            return $this->sendToTokens($tokens, $notification);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to send urgent notification: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get active push tokens for a professor
     */
    private function getProfessorTokens($professorId) {
        $sql = "SELECT push_token FROM push_tokens WHERE professor_id = ? AND is_active = TRUE";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $professorId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tokens = [];
        while ($row = $result->fetch_assoc()) {
            $tokens[] = $row['push_token'];
        }
        
        return $tokens;
    }
    
    /**
     * Send notifications to array of tokens using Expo Push API
     */
    private function sendToTokens($tokens, $notification) {
        try {
            // Prepare messages for Expo API
            $messages = [];
            foreach ($tokens as $token) {
                $messages[] = [
                    'to' => $token,
                    'title' => $notification['title'],
                    'body' => $notification['body'],
                    'data' => $notification['data'] ?? [],
                    'sound' => $notification['sound'] ?? 'default',
                    'priority' => $notification['priority'] ?? 'normal'
                ];
            }
            
            // Send to Expo API in batches (max 100 per request)
            $batches = array_chunk($messages, 100);
            $allResults = [];
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($batches as $batch) {
                $result = $this->sendBatchToExpo($batch);
                $allResults[] = $result;
                
                if ($result['success']) {
                    $successCount += count($batch);
                } else {
                    $errorCount += count($batch);
                }
            }
            
            // Log notification
            $this->logNotification($notification, $tokens, $successCount, $errorCount);
            
            return [
                'success' => $successCount > 0,
                'message' => "Sent to {$successCount} devices, {$errorCount} failed",
                'sent_count' => $successCount,
                'error_count' => $errorCount,
                'results' => $allResults
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to send notifications: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Send batch of messages to Expo Push API
     */
    private function sendBatchToExpo($messages) {
        try {
            $postData = json_encode($messages);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->expoApiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_error($ch)) {
                throw new Exception('Curl error: ' . curl_error($ch));
            }
            
            curl_close($ch);
            
            if ($httpCode !== 200) {
                throw new Exception("Expo API returned HTTP {$httpCode}: {$response}");
            }
            
            $responseData = json_decode($response, true);
            
            return [
                'success' => true,
                'response' => $responseData
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Log notification for tracking
     */
    private function logNotification($notification, $tokens, $successCount, $errorCount) {
        try {
            // Create notification_logs table if it doesn't exist
            $createTableSQL = "
                CREATE TABLE IF NOT EXISTS notification_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(255),
                    body TEXT,
                    notification_type VARCHAR(50),
                    tokens_sent INT DEFAULT 0,
                    success_count INT DEFAULT 0,
                    error_count INT DEFAULT 0,
                    data JSON,
                    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ";
            $this->conn->query($createTableSQL);
            
            // Insert log entry
            $sql = "INSERT INTO notification_logs (title, body, notification_type, tokens_sent, success_count, error_count, data) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            
            $notificationType = $notification['data']['type'] ?? 'general';
            $tokenCount = count($tokens);
            $dataJson = json_encode($notification['data'] ?? []);
            
            $stmt->bind_param("sssiiss", 
                $notification['title'],
                $notification['body'],
                $notificationType,
                $tokenCount,
                $successCount,
                $errorCount,
                $dataJson
            );
            
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Failed to log notification: " . $e->getMessage());
        }
    }
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON data'
        ]);
        exit;
    }
    
    $pushService = new PushNotificationService($conn);
    
    $action = $data['action'] ?? 'send';
    
    switch ($action) {
        case 'send_to_professor':
            if (!isset($data['professor_id']) || !isset($data['notification'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Professor ID and notification data are required'
                ]);
                break;
            }
            
            $result = $pushService->sendToProfessor($data['professor_id'], $data['notification']);
            echo json_encode($result);
            break;
            
        case 'send_to_multiple':
            if (!isset($data['professor_ids']) || !isset($data['notification'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Professor IDs and notification data are required'
                ]);
                break;
            }
            
            $result = $pushService->sendToMultipleProfessors($data['professor_ids'], $data['notification']);
            echo json_encode($result);
            break;
            
        case 'schedule_change':
            if (!isset($data['professor_id']) || !isset($data['schedule_data'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Professor ID and schedule data are required'
                ]);
                break;
            }
            
            $result = $pushService->sendScheduleChangeNotification($data['professor_id'], $data['schedule_data']);
            echo json_encode($result);
            break;
            
        case 'new_schedule':
            if (!isset($data['professor_id']) || !isset($data['schedule_data'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Professor ID and schedule data are required'
                ]);
                break;
            }
            
            $result = $pushService->sendNewScheduleNotification($data['professor_id'], $data['schedule_data']);
            echo json_encode($result);
            break;
            
        case 'urgent':
            if (!isset($data['message'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Message is required for urgent notifications'
                ]);
                break;
            }
            
            $result = $pushService->sendUrgentNotification($data['message'], $data['data'] ?? []);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action. Supported actions: send_to_professor, send_to_multiple, schedule_change, new_schedule, urgent'
            ]);
            break;
    }
    
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Only POST method is allowed'
    ]);
}

$conn->close();
?>
