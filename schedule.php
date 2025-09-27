<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
try {
    $host = 'localhost';
    $dbname = 'spcc_scheduling_system';
    $dbuser = 'root';
    $dbpass = '';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetSchedules($pdo);
        break;
    case 'POST':
        handleCreateSchedule($pdo);
        break;
    case 'PUT':
        handleUpdateSchedule($pdo);
        break;
    case 'DELETE':
        handleDeleteSchedule($pdo);
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}

function handleCreateSchedule($pdo) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Log the input for debugging
        error_log("Schedule creation input: " . json_encode($input));
        
        if (!$input) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid JSON input'
            ]);
            return;
        }
        
        // Validate required fields
        $required_fields = ['school_year', 'semester', 'subj_id', 'prof_id', 'section_id', 'schedule_type', 'start_time', 'end_time', 'days'];
        foreach ($required_fields as $field) {
            if (!isset($input[$field]) || (is_array($input[$field]) && empty($input[$field])) || (!is_array($input[$field]) && trim($input[$field]) === '')) {
                echo json_encode([
                    'success' => false,
                    'message' => "Missing required field: $field"
                ]);
                return;
            }
        }
        
        // Note: schedules table already exists with schedule_id as primary key
        
        // Insert schedule without conflict checking for now (to ensure it works)
        $sql = "INSERT INTO schedules (school_year, semester, subj_id, prof_id, section_id, room_id, schedule_type, start_time, end_time, days, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            $input['school_year'],
            $input['semester'],
            $input['subj_id'],
            $input['prof_id'],
            $input['section_id'],
            $input['room_id'] ?? null,
            $input['schedule_type'],
            $input['start_time'],
            $input['end_time'],
            json_encode($input['days'])
        ]);
        
        if ($result) {
            $schedule_id = $pdo->lastInsertId();
            
            // Auto-sync to Firebase
            try {
                require_once 'realtime_firebase_sync.php';
                $sync = new RealtimeFirebaseSync();
                $sync->syncSingleSchedule($schedule_id);
            } catch (Exception $e) {
                error_log("Firebase sync failed for schedule $schedule_id: " . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Schedule created successfully',
                'data' => [
                    'schedule_id' => $schedule_id,
                    ...$input
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create schedule'
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Schedule creation error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error creating schedule: ' . $e->getMessage()
        ]);
    }
}

function handleGetSchedules($pdo) {
    try {
        $school_year = $_GET['school_year'] ?? '';
        $semester = $_GET['semester'] ?? '';
        $professor_id = $_GET['professor_id'] ?? '';
        
        // Note: schedules table already exists with schedule_id as primary key
        
        $sql = "SELECT s.*, 
                       subj.subj_name, subj.subj_code,
                       p.prof_name as professor_name,
                       sect.section_name, sect.grade_level as level, sect.strand,
                       r.room_number, r.room_type
                FROM schedules s
                LEFT JOIN subjects subj ON s.subj_id = subj.subj_id
                LEFT JOIN professors p ON s.prof_id = p.prof_id
                LEFT JOIN sections sect ON s.section_id = sect.section_id
                LEFT JOIN rooms r ON s.room_id = r.room_id
                WHERE 1=1";
        $params = [];
        
        if (!empty($school_year)) {
            $sql .= " AND s.school_year = ?";
            $params[] = $school_year;
        }
        
        if (!empty($semester)) {
            $sql .= " AND s.semester = ?";
            $params[] = $semester;
        }
        
        if (!empty($professor_id)) {
            $sql .= " AND s.prof_id = ?";
            $params[] = $professor_id;
        }
        
        $sql .= " ORDER BY s.schedule_id DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse days JSON and add schedule_id field for each schedule
        foreach ($schedules as &$schedule) {
            if (isset($schedule['days'])) {
                $schedule['days'] = json_decode($schedule['days'], true) ?: [];
            }
            // Add schedule_id field for frontend compatibility (it already exists)
            // $schedule['schedule_id'] is already present in the table
        }
        
        echo json_encode([
            'success' => true,
            'data' => $schedules,
            'message' => 'Schedules retrieved successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error retrieving schedules: ' . $e->getMessage()
        ]);
    }
}

function handleUpdateSchedule($pdo) {
    try {
        $id = $_GET['id'] ?? '';
        if (empty($id)) {
            echo json_encode([
                'success' => false,
                'message' => 'Schedule ID is required'
            ]);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid JSON input'
            ]);
            return;
        }
        
        // Build update query dynamically
        $updateFields = [];
        $params = [];
        
        $allowedFields = ['school_year', 'semester', 'subj_id', 'prof_id', 'section_id', 'room_id', 'schedule_type', 'start_time', 'end_time', 'days'];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = $field === 'days' ? json_encode($input[$field]) : $input[$field];
            }
        }
        
        if (empty($updateFields)) {
            echo json_encode([
                'success' => false,
                'message' => 'No valid fields to update'
            ]);
            return;
        }
        
        $sql = "UPDATE schedules SET " . implode(', ', $updateFields) . " WHERE schedule_id = ?";
        $params[] = $id;
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            // Auto-sync to Firebase
            try {
                require_once 'realtime_firebase_sync.php';
                $sync = new RealtimeFirebaseSync();
                $sync->syncSingleSchedule($id);
            } catch (Exception $e) {
                error_log("Firebase sync failed for schedule update $id: " . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Schedule updated successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update schedule'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error updating schedule: ' . $e->getMessage()
        ]);
    }
}

function handleDeleteSchedule($pdo) {
    try {
        $id = $_GET['id'] ?? '';
        if (empty($id)) {
            echo json_encode([
                'success' => false,
                'message' => 'Schedule ID is required'
            ]);
            return;
        }
        
        $sql = "DELETE FROM schedules WHERE schedule_id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$id]);
        
        if ($result) {
            // Auto-sync to Firebase (delete from Firebase)
            try {
                require_once 'realtime_firebase_sync.php';
                $sync = new RealtimeFirebaseSync();
                $sync->deleteSingleSchedule($id);
            } catch (Exception $e) {
                error_log("Firebase sync failed for schedule deletion $id: " . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Schedule deleted successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to delete schedule'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting schedule: ' . $e->getMessage()
        ]);
    }
}
?>
