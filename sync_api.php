<?php
/**
 * Firebase Sync API Endpoint
 * Handles manual and automatic sync requests
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'realtime_firebase_sync.php';

try {
    $sync = new RealtimeFirebaseSync();
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            // Handle different sync operations
            $action = $_GET['action'] ?? 'all';
            
            switch ($action) {
                case 'all':
                    $result = $sync->syncAllTables();
                    break;
                    
                case 'schedules':
                    $result = $sync->syncSchedules();
                    break;
                    
                case 'professors':
                    $result = $sync->syncProfessors();
                    break;
                    
                case 'subjects':
                    $result = $sync->syncSubjects();
                    break;
                    
                case 'sections':
                    $result = $sync->syncSections();
                    break;
                    
                case 'rooms':
                    $result = $sync->syncRooms();
                    break;
                    
                default:
                    http_response_code(400);
                    $result = [
                        'success' => false,
                        'message' => 'Invalid action. Use: all, schedules, professors, subjects, sections, or rooms'
                    ];
                    break;
            }
            break;
            
        case 'POST':
            // Handle specific item sync
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data || !isset($data['action'])) {
                http_response_code(400);
                $result = [
                    'success' => false,
                    'message' => 'Missing action parameter'
                ];
                break;
            }
            
            switch ($data['action']) {
                case 'sync_schedule':
                    if (!isset($data['schedule_id'])) {
                        http_response_code(400);
                        $result = [
                            'success' => false,
                            'message' => 'Missing schedule_id parameter'
                        ];
                        break;
                    }
                    $result = $sync->syncSingleSchedule($data['schedule_id']);
                    break;
                    
                case 'delete_schedule':
                    if (!isset($data['schedule_id'])) {
                        http_response_code(400);
                        $result = [
                            'success' => false,
                            'message' => 'Missing schedule_id parameter'
                        ];
                        break;
                    }
                    $result = $sync->deleteSingleSchedule($data['schedule_id']);
                    break;
                    
                default:
                    http_response_code(400);
                    $result = [
                        'success' => false,
                        'message' => 'Invalid POST action'
                    ];
                    break;
            }
            break;
            
        default:
            http_response_code(405);
            $result = [
                'success' => false,
                'message' => 'Method not allowed'
            ];
            break;
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sync error: ' . $e->getMessage()
    ]);
}
?>
