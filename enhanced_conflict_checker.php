<?php
// Enhanced conflict detection API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

// Extract parameters
$type = $input['type'] ?? '';
$school_year = $input['school_year'] ?? '';
$semester = $input['semester'] ?? '';
$days = $input['days'] ?? [];
$start_time = $input['start_time'] ?? '';
$end_time = $input['end_time'] ?? '';
$prof_id = $input['prof_id'] ?? null;
$room_id = $input['room_id'] ?? null;
$section_id = $input['section_id'] ?? null;
$exclude_schedule_id = $input['exclude_schedule_id'] ?? null;

// Validate required parameters
if (empty($school_year) || empty($semester) || empty($days) || !is_array($days) || empty($start_time) || empty($end_time)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Missing required parameters: school_year, semester, days, start_time, end_time'
    ]);
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
    
    $hasConflicts = false;
    $conflictingDays = [];
    $conflictDetails = [];
    
    // Check conflicts based on type
    switch ($type) {
        case 'professor':
            if ($prof_id) {
                $result = checkProfessorConflicts($pdo, $school_year, $semester, $prof_id, $days, $start_time, $end_time, $exclude_schedule_id);
                $hasConflicts = $result['hasConflicts'];
                $conflictingDays = $result['conflictingDays'];
                $conflictDetails = $result['details'];
            }
            break;
            
        case 'room':
            if ($room_id) {
                $result = checkRoomConflicts($pdo, $school_year, $semester, $room_id, $days, $start_time, $end_time, $exclude_schedule_id);
                $hasConflicts = $result['hasConflicts'];
                $conflictingDays = $result['conflictingDays'];
                $conflictDetails = $result['details'];
            }
            break;
            
        case 'section':
            if ($section_id) {
                $result = checkSectionConflicts($pdo, $school_year, $semester, $section_id, $days, $start_time, $end_time, $exclude_schedule_id);
                $hasConflicts = $result['hasConflicts'];
                $conflictingDays = $result['conflictingDays'];
                $conflictDetails = $result['details'];
            }
            break;
            
        case 'all':
        default:
            // Check all types of conflicts
            $allConflicts = [];
            
            if ($prof_id) {
                $profResult = checkProfessorConflicts($pdo, $school_year, $semester, $prof_id, $days, $start_time, $end_time, $exclude_schedule_id);
                if ($profResult['hasConflicts']) {
                    $hasConflicts = true;
                    $conflictingDays = array_merge($conflictingDays, $profResult['conflictingDays']);
                    $allConflicts[] = [
                        'type' => 'professor',
                        'message' => 'Professor has conflicting schedules',
                        'details' => $profResult['details']
                    ];
                }
            }
            
            if ($room_id) {
                $roomResult = checkRoomConflicts($pdo, $school_year, $semester, $room_id, $days, $start_time, $end_time, $exclude_schedule_id);
                if ($roomResult['hasConflicts']) {
                    $hasConflicts = true;
                    $conflictingDays = array_merge($conflictingDays, $roomResult['conflictingDays']);
                    $allConflicts[] = [
                        'type' => 'room',
                        'message' => 'Room is already booked',
                        'details' => $roomResult['details']
                    ];
                }
            }
            
            if ($section_id) {
                $sectionResult = checkSectionConflicts($pdo, $school_year, $semester, $section_id, $days, $start_time, $end_time, $exclude_schedule_id);
                if ($sectionResult['hasConflicts']) {
                    $hasConflicts = true;
                    $conflictingDays = array_merge($conflictingDays, $sectionResult['conflictingDays']);
                    $allConflicts[] = [
                        'type' => 'section',
                        'message' => 'Section already has a class scheduled',
                        'details' => $sectionResult['details']
                    ];
                }
            }
            
            $conflictDetails = $allConflicts;
            break;
    }
    
    // Remove duplicate days
    $conflictingDays = array_unique($conflictingDays);
    
    echo json_encode([
        'success' => true,
        'hasConflicts' => $hasConflicts,
        'conflictingDays' => $conflictingDays,
        'conflicts' => $conflictDetails,
        'message' => $hasConflicts ? 'Conflicts detected' : 'No conflicts found'
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in enhanced_conflict_checker.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database connection error',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("General error in enhanced_conflict_checker.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}

// Helper function to check professor conflicts
function checkProfessorConflicts($pdo, $school_year, $semester, $prof_id, $days, $start_time, $end_time, $exclude_schedule_id = null) {
    $hasConflicts = false;
    $conflictingDays = [];
    $details = [];
    
    foreach ($days as $day) {
        $stmt = $pdo->prepare("
            SELECT schedule_id, days, start_time, end_time, subj_id
            FROM schedules s 
            WHERE s.school_year = ? 
            AND s.semester = ? 
            AND s.prof_id = ? 
            AND (JSON_CONTAINS(s.days, ?) OR JSON_CONTAINS(s.days, ?))
            AND (
                (s.start_time < ? AND s.end_time > ?) OR
                (s.start_time < ? AND s.end_time > ?) OR
                (s.start_time >= ? AND s.end_time <= ?)
            )
            " . ($exclude_schedule_id ? "AND s.schedule_id != ?" : "")
        );
        
        $params = [
            $school_year, 
            $semester, 
            $prof_id, 
            json_encode(strtolower($day)), 
            json_encode(ucfirst(strtolower($day))),
            $end_time, $start_time,    // Existing starts before new ends AND existing ends after new starts
            $start_time, $end_time,    // Existing starts before new ends AND existing ends after new ends  
            $start_time, $end_time     // New schedule completely contains existing
        ];
        
        if ($exclude_schedule_id) {
            $params[] = $exclude_schedule_id;
        }
        
        $stmt->execute($params);
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($conflicts) > 0) {
            $hasConflicts = true;
            $conflictingDays[] = $day;
            
            foreach ($conflicts as $conflict) {
                $details[] = [
                    'day' => $day,
                    'schedule_id' => $conflict['schedule_id'],
                    'existing_time' => $conflict['start_time'] . ' - ' . $conflict['end_time'],
                    'requested_time' => $start_time . ' - ' . $end_time
                ];
            }
        }
    }
    
    return [
        'hasConflicts' => $hasConflicts,
        'conflictingDays' => $conflictingDays,
        'details' => $details
    ];
}

// Helper function to check room conflicts
function checkRoomConflicts($pdo, $school_year, $semester, $room_id, $days, $start_time, $end_time, $exclude_schedule_id = null) {
    $hasConflicts = false;
    $conflictingDays = [];
    $details = [];
    
    foreach ($days as $day) {
        $stmt = $pdo->prepare("
            SELECT schedule_id, days, start_time, end_time, prof_id
            FROM schedules s 
            WHERE s.school_year = ? 
            AND s.semester = ? 
            AND s.room_id = ? 
            AND (JSON_CONTAINS(s.days, ?) OR JSON_CONTAINS(s.days, ?))
            AND (
                (s.start_time < ? AND s.end_time > ?) OR
                (s.start_time < ? AND s.end_time > ?) OR
                (s.start_time >= ? AND s.end_time <= ?)
            )
            " . ($exclude_schedule_id ? "AND s.schedule_id != ?" : "")
        );
        
        $params = [
            $school_year, 
            $semester, 
            $room_id, 
            json_encode(strtolower($day)), 
            json_encode(ucfirst(strtolower($day))),
            $end_time, $start_time,
            $start_time, $end_time,
            $start_time, $end_time
        ];
        
        if ($exclude_schedule_id) {
            $params[] = $exclude_schedule_id;
        }
        
        $stmt->execute($params);
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($conflicts) > 0) {
            $hasConflicts = true;
            $conflictingDays[] = $day;
            
            foreach ($conflicts as $conflict) {
                $details[] = [
                    'day' => $day,
                    'schedule_id' => $conflict['schedule_id'],
                    'existing_time' => $conflict['start_time'] . ' - ' . $conflict['end_time'],
                    'requested_time' => $start_time . ' - ' . $end_time
                ];
            }
        }
    }
    
    return [
        'hasConflicts' => $hasConflicts,
        'conflictingDays' => $conflictingDays,
        'details' => $details
    ];
}

// Helper function to check section conflicts
function checkSectionConflicts($pdo, $school_year, $semester, $section_id, $days, $start_time, $end_time, $exclude_schedule_id = null) {
    $hasConflicts = false;
    $conflictingDays = [];
    $details = [];
    
    foreach ($days as $day) {
        $stmt = $pdo->prepare("
            SELECT schedule_id, days, start_time, end_time, prof_id
            FROM schedules s 
            WHERE s.school_year = ? 
            AND s.semester = ? 
            AND s.section_id = ? 
            AND (JSON_CONTAINS(s.days, ?) OR JSON_CONTAINS(s.days, ?))
            AND (
                (s.start_time < ? AND s.end_time > ?) OR
                (s.start_time < ? AND s.end_time > ?) OR
                (s.start_time >= ? AND s.end_time <= ?)
            )
            " . ($exclude_schedule_id ? "AND s.schedule_id != ?" : "")
        );
        
        $params = [
            $school_year, 
            $semester, 
            $section_id, 
            json_encode(strtolower($day)), 
            json_encode(ucfirst(strtolower($day))),
            $end_time, $start_time,
            $start_time, $end_time,
            $start_time, $end_time
        ];
        
        if ($exclude_schedule_id) {
            $params[] = $exclude_schedule_id;
        }
        
        $stmt->execute($params);
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($conflicts) > 0) {
            $hasConflicts = true;
            $conflictingDays[] = $day;
            
            foreach ($conflicts as $conflict) {
                $details[] = [
                    'day' => $day,
                    'schedule_id' => $conflict['schedule_id'],
                    'existing_time' => $conflict['start_time'] . ' - ' . $conflict['end_time'],
                    'requested_time' => $start_time . ' - ' . $end_time
                ];
            }
        }
    }
    
    return [
        'hasConflicts' => $hasConflicts,
        'conflictingDays' => $conflictingDays,
        'details' => $details
    ];
}
?>
