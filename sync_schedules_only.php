<?php
// Lightweight sync endpoint for schedules only
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'connect.php';
require_once 'firebase_config.php';
require_once 'firebase_sync.php';

try {
    $sync = new FirebaseSync($firebaseConfig, $conn);
    
    // Only sync schedules for faster response
    $result = $sync->syncSchedules();
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Schedules synced to Firebase successfully',
            'data' => $result
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to sync schedules',
            'error' => $result['message'] ?? 'Unknown error'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Sync failed',
        'error' => $e->getMessage()
    ]);
}
?>
