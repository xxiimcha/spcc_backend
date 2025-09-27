<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit();
}

// Extract parameters
$school_year = $input['school_year'] ?? '';
$semester = $input['semester'] ?? '';
$days = $input['days'] ?? [];
$prof_id = $input['prof_id'] ?? null;
$room_id = $input['room_id'] ?? null;
$section_id = $input['section_id'] ?? null;
$exclude_schedule_id = $input['exclude_schedule_id'] ?? null;

// Validate required parameters
if (empty($school_year) || empty($semester) || empty($days) || !is_array($days)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters']);
    exit();
}

try {
    // Database connection
    $host = 'localhost';
    $dbname = 'spcc_scheduling_system';
    $dbuser = 'root';
    $dbpass = '';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Generate all possible time slots (8:00 AM to 5:00 PM, 1-hour slots)
    $timeSlots = [];
    for ($hour = 8; $hour <= 17; $hour++) {
        $startTime = sprintf('%02d:00:00', $hour);
        $endTime = sprintf('%02d:00:00', $hour + 1);
        $timeSlots[] = [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'display' => date('g:i A', strtotime($startTime)) . ' - ' . date('g:i A', strtotime($endTime))
        ];
    }
    
    // Check for conflicts and filter available slots
    $availableSlots = [];
    
    foreach ($days as $day) {
        $availableSlots[$day] = [];
        
        foreach ($timeSlots as $slot) {
            $isAvailable = true;
            $conflictReasons = [];
            
            // Check professor conflicts
            if ($prof_id) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as conflict_count 
                    FROM schedules s 
                    WHERE s.school_year = ? 
                    AND s.semester = ? 
                    AND JSON_CONTAINS(s.days, ?) 
                    AND s.prof_id = ? 
                    AND s.start_time = ? 
                    AND s.end_time = ?
                    " . ($exclude_schedule_id ? "AND s.id != ?" : "")
                );
                
                $params = [$school_year, $semester, json_encode($day), $prof_id, $slot['start_time'], $slot['end_time']];
                if ($exclude_schedule_id) {
                    $params[] = $exclude_schedule_id;
                }
                
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['conflict_count'] > 0) {
                    $isAvailable = false;
                    $conflictReasons[] = 'Professor has another class';
                }
            }
            
            // Check room conflicts
            if ($room_id && $isAvailable) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as conflict_count 
                    FROM schedules s 
                    WHERE s.school_year = ? 
                    AND s.semester = ? 
                    AND JSON_CONTAINS(s.days, ?) 
                    AND s.room_id = ? 
                    AND s.start_time = ? 
                    AND s.end_time = ?
                    " . ($exclude_schedule_id ? "AND s.id != ?" : "")
                );
                
                $params = [$school_year, $semester, json_encode($day), $room_id, $slot['start_time'], $slot['end_time']];
                if ($exclude_schedule_id) {
                    $params[] = $exclude_schedule_id;
                }
                
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['conflict_count'] > 0) {
                    $isAvailable = false;
                    $conflictReasons[] = 'Room is booked';
                }
            }
            
            // Check section conflicts
            if ($section_id && $isAvailable) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as conflict_count 
                    FROM schedules s 
                    WHERE s.school_year = ? 
                    AND s.semester = ? 
                    AND JSON_CONTAINS(s.days, ?) 
                    AND s.section = ? 
                    AND s.start_time = ? 
                    AND s.end_time = ?
                    " . ($exclude_schedule_id ? "AND s.id != ?" : "")
                );
                
                $params = [$school_year, $semester, json_encode($day), $section_id, $slot['start_time'], $slot['end_time']];
                if ($exclude_schedule_id) {
                    $params[] = $exclude_schedule_id;
                }
                
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['conflict_count'] > 0) {
                    $isAvailable = false;
                    $conflictReasons[] = 'Section has another class';
                }
            }
            
            // Add to available slots if no conflicts
            if ($isAvailable) {
                $availableSlots[$day][] = [
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'display' => $slot['display']
                ];
            }
        }
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'available_slots' => $availableSlots,
        'message' => 'Available time slots retrieved successfully',
        'total_slots' => array_sum(array_map('count', $availableSlots))
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Database error in get_available_time_slots.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database connection error. Please try again later.',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("General error in get_available_time_slots.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again later.',
        'error' => $e->getMessage()
    ]);
}
?>
