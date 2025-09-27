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
$start_time = $input['start_time'] ?? '';
$end_time = $input['end_time'] ?? '';
$exclude_schedule_id = $input['exclude_schedule_id'] ?? null;

// Validate required parameters
if (empty($school_year) || empty($semester) || empty($days) || !is_array($days) || 
    empty($start_time) || empty($end_time)) {
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
    
    $conflicts = [
        'professorConflicts' => [],
        'roomConflicts' => [],
        'sectionConflicts' => [],
        'hasConflicts' => false,
        'solutions' => []
    ];
    
    // Check professor conflicts
    if ($prof_id) {
        $stmt = $pdo->prepare("
            SELECT s.*, subj.subject_name, subj.subject_code, r.number as room_number
            FROM schedules s
            LEFT JOIN subjects subj ON s.subj_id = subj.id
            LEFT JOIN rooms r ON s.room_id = r.id
            WHERE s.school_year = ? 
            AND s.semester = ? 
            AND JSON_CONTAINS(s.days, ?) 
            AND s.prof_id = ? 
            AND s.start_time = ? 
            AND s.end_time = ?
            " . ($exclude_schedule_id ? "AND s.id != ?" : "")
        );
        
        $params = [$school_year, $semester, json_encode($days[0]), $prof_id, $start_time, $end_time];
        if ($exclude_schedule_id) {
            $params[] = $exclude_schedule_id;
        }
        
        $stmt->execute($params);
        $profConflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($profConflicts)) {
            $conflicts['professorConflicts'] = $profConflicts;
            $conflicts['hasConflicts'] = true;
            
            // Add solution for professor conflict
            $conflicts['solutions'][] = [
                'type' => 'professor_conflict',
                'title' => 'Professor Time Conflict',
                'description' => 'The selected professor already has a class at this time.',
                'suggestions' => [
                    'Select a different professor',
                    'Choose a different time slot',
                    'Consider online scheduling if appropriate'
                ]
            ];
        }
    }
    
    // Check room conflicts
    if ($room_id) {
        $stmt = $pdo->prepare("
            SELECT s.*, subj.subject_name, subj.subject_code, p.prof_name
            FROM schedules s
            LEFT JOIN subjects subj ON s.subj_id = subj.id
            LEFT JOIN professors p ON s.prof_id = p.id
            WHERE s.school_year = ? 
            AND s.semester = ? 
            AND JSON_CONTAINS(s.days, ?) 
            AND s.room_id = ? 
            AND s.start_time = ? 
            AND s.end_time = ?
            " . ($exclude_schedule_id ? "AND s.id != ?" : "")
        );
        
        $params = [$school_year, $semester, json_encode($days[0]), $room_id, $start_time, $end_time];
        if ($exclude_schedule_id) {
            $params[] = $exclude_schedule_id;
        }
        
        $stmt->execute($params);
        $roomConflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($roomConflicts)) {
            $conflicts['roomConflicts'] = $roomConflicts;
            $conflicts['hasConflicts'] = true;
            
            // Add solution for room conflict
            $conflicts['solutions'][] = [
                'type' => 'room_conflict',
                'title' => 'Room Already Booked',
                'description' => 'The selected room is already occupied at this time.',
                'suggestions' => [
                    'Choose a different room',
                    'Select a different time slot',
                    'Consider online scheduling'
                ]
            ];
        }
    }
    
    // Check section conflicts
    if ($section_id) {
        $stmt = $pdo->prepare("
            SELECT s.*, subj.subject_name, subj.subject_code, p.prof_name, r.number as room_number
            FROM schedules s
            LEFT JOIN subjects subj ON s.subj_id = subj.id
            LEFT JOIN professors p ON s.prof_id = p.id
            LEFT JOIN rooms r ON s.room_id = r.id
            WHERE s.school_year = ? 
            AND s.semester = ? 
            AND JSON_CONTAINS(s.days, ?) 
            AND s.section = ? 
            AND s.start_time = ? 
            AND s.end_time = ?
            " . ($exclude_schedule_id ? "AND s.id != ?" : "")
        );
        
        $params = [$school_year, $semester, json_encode($days[0]), $section_id, $start_time, $end_time];
        if ($exclude_schedule_id) {
            $params[] = $exclude_schedule_id;
        }
        
        $stmt->execute($params);
        $sectionConflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($sectionConflicts)) {
            $conflicts['sectionConflicts'] = $sectionConflicts;
            $conflicts['hasConflicts'] = true;
            
            // Add solution for section conflict
            $conflicts['solutions'][] = [
                'type' => 'section_conflict',
                'title' => 'Section Already Has Class',
                'description' => 'The selected section already has a class scheduled at this time.',
                'suggestions' => [
                    'Choose a different time slot',
                    'Select a different section',
                    'Consider online scheduling'
                ]
            ];
        }
    }
    
    // Generate alternative time suggestions
    if ($conflicts['hasConflicts']) {
        $conflicts['alternativeTimes'] = generateAlternativeTimes($pdo, $school_year, $semester, $days, $prof_id, $room_id, $section_id);
    }
    
    echo json_encode([
        'success' => true,
        'hasConflicts' => $conflicts['hasConflicts'],
        'conflicts' => $conflicts,
        'message' => $conflicts['hasConflicts'] ? 'Conflicts detected' : 'No conflicts found'
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in enhanced_conflict_detection.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database connection error. Please try again later.',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("General error in enhanced_conflict_detection.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again later.',
        'error' => $e->getMessage()
    ]);
}

function generateAlternativeTimes($pdo, $school_year, $semester, $days, $prof_id, $room_id, $section_id) {
    $alternatives = [];
    $timeSlots = [
        ['08:00:00', '09:00:00'],
        ['09:00:00', '10:00:00'],
        ['10:00:00', '11:00:00'],
        ['11:00:00', '12:00:00'],
        ['13:00:00', '14:00:00'],
        ['14:00:00', '15:00:00'],
        ['15:00:00', '16:00:00'],
        ['16:00:00', '17:00:00']
    ];
    
    foreach ($days as $day) {
        foreach ($timeSlots as $slot) {
            $isAvailable = true;
            
            // Check if this time slot is available
            if ($prof_id) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count FROM schedules 
                    WHERE school_year = ? AND semester = ? 
                    AND JSON_CONTAINS(days, ?) AND prof_id = ? 
                    AND start_time = ? AND end_time = ?
                ");
                $stmt->execute([$school_year, $semester, json_encode($day), $prof_id, $slot[0], $slot[1]]);
                if ($stmt->fetch()['count'] > 0) $isAvailable = false;
            }
            
            if ($room_id && $isAvailable) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count FROM schedules 
                    WHERE school_year = ? AND semester = ? 
                    AND JSON_CONTAINS(days, ?) AND room_id = ? 
                    AND start_time = ? AND end_time = ?
                ");
                $stmt->execute([$school_year, $semester, json_encode($day), $room_id, $slot[0], $slot[1]]);
                if ($stmt->fetch()['count'] > 0) $isAvailable = false;
            }
            
            if ($section_id && $isAvailable) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count FROM schedules 
                    WHERE school_year = ? AND semester = ? 
                    AND JSON_CONTAINS(days, ?) AND section = ? 
                    AND start_time = ? AND end_time = ?
                ");
                $stmt->execute([$school_year, $semester, json_encode($day), $section_id, $slot[0], $slot[1]]);
                if ($stmt->fetch()['count'] > 0) $isAvailable = false;
            }
            
            if ($isAvailable) {
                $alternatives[] = [
                    'day' => $day,
                    'start_time' => $slot[0],
                    'end_time' => $slot[1],
                    'isAvailable' => true
                ];
            }
        }
    }
    
    return $alternatives;
}
?>
