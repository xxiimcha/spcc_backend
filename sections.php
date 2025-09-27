<?php
// sections.php - API endpoint for section management

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}
include 'connect.php';

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

// Handle different HTTP methods
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get all sections or a specific section
        if (isset($_GET['id'])) {
            getSection($conn, $_GET['id']);
        } else {
            getAllSections($conn);
        }
        break;
    
    case 'POST':
        // Create a new section
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        createSection($conn, $data);
        break;
    
    case 'PUT':
        // Update an existing section
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Section ID is required"]);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        
        updateSection($conn, $_GET['id'], $data);
        break;
    
    case 'DELETE':
        // Delete a section
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Section ID is required"]);
            exit();
        }
        
        deleteSection($conn, $_GET['id']);
        break;
    
    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        break;
}

// Function to get all sections with room information and schedule count
function getAllSections($conn): void {
    // Get all sections with room information and schedule count
    $sql = "SELECT s.*, 
            (SELECT COUNT(*) FROM schedules WHERE section_id = s.section_id) as schedule_count,
            GROUP_CONCAT(DISTINCT r.room_id, ':', r.room_number, ':', r.room_type, ':', r.room_capacity) as rooms,
            MIN(r.room_id) as primary_room_id
            FROM sections s
            LEFT JOIN section_room_assignments sra ON s.section_id = sra.section_id
            LEFT JOIN rooms r ON sra.room_id = r.room_id
            GROUP BY s.section_id
            ORDER BY s.grade_level, s.strand, s.section_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        $sections = [];
        while ($row = $result->fetch_assoc()) {
            $rooms = [];
            if ($row['rooms']) {
                $roomData = explode(',', $row['rooms']);
                foreach ($roomData as $room) {
                    list($id, $number, $type, $capacity) = explode(':', $room);
                    $rooms[] = [
                        'id' => (int)$id,
                        'number' => (int)$number,
                        'type' => $type,
                        'capacity' => (int)$capacity
                    ];
                }
            }
            
            $section = [
                'section_id' => (int)$row['section_id'],
                'section_name' => $row['section_name'],
                'grade_level' => $row['grade_level'],
                'strand' => $row['strand'],
                'number_of_students' => (int)$row['number_of_students'],
                'schedule_count' => (int)$row['schedule_count'],
                'primary_room_id' => $row['primary_room_id'] ? (int)$row['primary_room_id'] : null,
                'rooms' => $rooms
            ];
            
            $sections[] = $section;
        }
        
        echo json_encode([
            "success" => true,
            "data" => $sections
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to fetch sections"
        ]);
    }
    
    $stmt->close();
}

// Function to get a specific section
function getSection($conn, $id) {
    $sql = "SELECT s.*, 
            (SELECT COUNT(*) FROM schedules WHERE section_id = s.section_id) as schedule_count,
            GROUP_CONCAT(DISTINCT r.room_id, ':', r.room_number, ':', r.room_type, ':', r.room_capacity) as rooms,
            MIN(r.room_id) as primary_room_id
            FROM sections s
            LEFT JOIN section_room_assignments sra ON s.section_id = sra.section_id
            LEFT JOIN rooms r ON sra.room_id = r.room_id
            WHERE s.section_id = ?
            GROUP BY s.section_id";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $row = $result->fetch_assoc()) {
        $rooms = [];
        if ($row['rooms']) {
            $roomData = explode(',', $row['rooms']);
            foreach ($roomData as $room) {
                list($id, $number, $type, $capacity) = explode(':', $room);
                $rooms[] = [
                    'id' => (int)$id,
                    'number' => (int)$number,
                    'type' => $type,
                    'capacity' => (int)$capacity
                ];
            }
        }
        
        $section = [
            'section_id' => (int)$row['section_id'],
            'section_name' => $row['section_name'],
            'grade_level' => $row['grade_level'],
            'strand' => $row['strand'],
            'number_of_students' => (int)$row['number_of_students'],
            'schedule_count' => (int)$row['schedule_count'],
            'primary_room_id' => $row['primary_room_id'] ? (int)$row['primary_room_id'] : null,
            'rooms' => $rooms
        ];
        
        // Get current schedules for this section
        $schedules_sql = "SELECT s.*, subj.subj_code, subj.subj_name, 
                         p.prof_name as professor_name, p.subj_count as professor_subject_count,
                         r.room_number, r.room_type, r.room_capacity
                         FROM schedules s
                         JOIN subjects subj ON s.subj_id = subj.subj_id
                         JOIN professors p ON s.prof_id = p.prof_id
                         LEFT JOIN rooms r ON s.room_id = r.room_id
                         WHERE s.section_id = ?";
        
        $schedules_stmt = $conn->prepare($schedules_sql);
        $schedules_stmt->bind_param("i", $id);
        $schedules_stmt->execute();
        $schedules_result = $schedules_stmt->get_result();
        
        $schedules = [];
        while ($schedule_row = $schedules_result->fetch_assoc()) {
            // Get days for this schedule
            $days_sql = "SELECT d.day_name 
                        FROM schedule_days sd 
                        JOIN days d ON sd.day_id = d.day_id 
                        WHERE sd.schedule_id = ?";
            $days_stmt = $conn->prepare($days_sql);
            $days_stmt->bind_param("i", $schedule_row['schedule_id']);
            $days_stmt->execute();
            $days_result = $days_stmt->get_result();
            
            $days = [];
            while ($day_row = $days_result->fetch_assoc()) {
                $days[] = $day_row['day_name'];
            }
            
            $schedules[] = [
                'schedule_id' => (int)$schedule_row['schedule_id'],
                'subject' => [
                    'id' => (int)$schedule_row['subj_id'],
                    'code' => $schedule_row['subj_code'],
                    'name' => $schedule_row['subj_name']
                ],
                'professor' => [
                    'id' => (int)$schedule_row['prof_id'],
                    'name' => $schedule_row['professor_name'],
                    'subject_count' => (int)$schedule_row['professor_subject_count']
                ],
                'room' => $schedule_row['room_id'] ? [
                    'id' => (int)$schedule_row['room_id'],
                    'number' => (int)$schedule_row['room_number'],
                    'type' => $schedule_row['room_type'],
                    'capacity' => (int)$schedule_row['room_capacity']
                ] : null,
                'schedule_type' => $schedule_row['schedule_type'],
                'start_time' => $schedule_row['start_time'],
                'end_time' => $schedule_row['end_time'],
                'days' => $days
            ];
            
            $days_stmt->close();
        }
        
        $section['schedules'] = $schedules;
        
        echo json_encode([
            "success" => true,
            "data" => $section
        ]);
        
        $schedules_stmt->close();
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Section not found"
        ]);
    }
    
    $stmt->close();
}

// Function to create a new section
function createSection($conn, $data) {
    $conn->begin_transaction();
    
    try {
        // Prepare values
        $name = $data['section_name'];
        $gradeLevel = isset($data['grade_level']) ? $data['grade_level'] : null;
        $numberOfStudents = isset($data['number_of_students']) ? (int)$data['number_of_students'] : null;
        $strand = isset($data['strand']) ? $data['strand'] : null;
        $roomIds = isset($data['room_ids']) ? $data['room_ids'] : [];
        
        // Validate number of students if provided
        if (isset($data['number_of_students']) && (!is_numeric($data['number_of_students']) || $data['number_of_students'] < 0)) {
            throw new Exception("Number of students must be a non-negative integer");
        }
        
        // Validate room_ids if provided
        if (isset($data['room_ids'])) {
            if (!is_array($data['room_ids'])) {
                throw new Exception("room_ids must be an array");
            }
            
            // Check if all rooms exist
            $roomIds = array_map('intval', $data['room_ids']);
            $placeholders = str_repeat('?,', count($roomIds) - 1) . '?';
            $roomCheckStmt = $conn->prepare("SELECT room_id FROM rooms WHERE room_id IN ($placeholders)");
            $roomCheckStmt->bind_param(str_repeat('i', count($roomIds)), ...$roomIds);
            $roomCheckStmt->execute();
            $roomCheckResult = $roomCheckStmt->get_result();
            
            if ($roomCheckResult->num_rows !== count($roomIds)) {
                throw new Exception("One or more room IDs do not exist");
            }
            $roomCheckStmt->close();
        }
        
        // Insert section
        $sql = "INSERT INTO sections (section_name, grade_level, number_of_students, strand)
                VALUES (?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Failed to prepare SQL statement");
        }
        
        $stmt->bind_param("ssis", $name, $gradeLevel, $numberOfStudents, $strand);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create section");
        }
        
        $sectionId = $conn->insert_id;
        $stmt->close();

        // Insert room assignments if any
        if (!empty($roomIds)) {
            $assignSql = "INSERT INTO section_room_assignments (section_id, room_id) VALUES (?, ?)";
            $assignStmt = $conn->prepare($assignSql);
            
            foreach ($roomIds as $roomId) {
                $assignStmt->bind_param("ii", $sectionId, $roomId);
                if (!$assignStmt->execute()) {
                    throw new Exception("Failed to assign room to section");
                }
            }
            $assignStmt->close();
        }

        // Get the created section with room information
        $sql = "SELECT s.*, 
                GROUP_CONCAT(DISTINCT r.room_id, ':', r.room_number, ':', r.room_type, ':', r.room_capacity) as rooms
                FROM sections s
                LEFT JOIN section_room_assignments sra ON s.section_id = sra.section_id
                LEFT JOIN rooms r ON sra.room_id = r.room_id
                WHERE s.section_id = ?
                GROUP BY s.section_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $sectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
            $rooms = [];
            if ($row['rooms']) {
                $roomData = explode(',', $row['rooms']);
                foreach ($roomData as $room) {
                    list($id, $number, $type, $capacity) = explode(':', $room);
                    $rooms[] = [
                        'id' => (int)$id,
                        'number' => (int)$number,
                        'type' => $type,
                        'capacity' => (int)$capacity
                    ];
                }
            }
            
            $section = [
                'section_id' => (int)$row['section_id'],
                'section_name' => $row['section_name'],
                'grade_level' => $row['grade_level'],
                'strand' => $row['strand'],
                'number_of_students' => (int)$row['number_of_students'],
                'rooms' => $rooms
            ];
            
            // Commit transaction
            $conn->commit();
            
            // Sync to Firebase
            try {
                require_once 'firebase_sync.php';
                $sync = new FirebaseSync($firebaseConfig, $conn);
                $firebaseResult = $sync->syncSections();
                
                if ($firebaseResult['success']) {
                    http_response_code(201);
                    echo json_encode([
                        "status" => "success", 
                        "message" => "Section added successfully and synced to Firebase", 
                        "section" => $section,
                        "firebase_sync" => $firebaseResult
                    ]);
                } else {
                    http_response_code(201);
                    echo json_encode([
                        "status" => "success", 
                        "message" => "Section added successfully but Firebase sync failed", 
                        "section" => $section,
                        "firebase_sync" => $firebaseResult
                    ]);
                }
            } catch (Exception $firebaseError) {
                http_response_code(201);
                echo json_encode([
                    "status" => "success", 
                    "message" => "Section added successfully but Firebase sync failed", 
                    "section" => $section,
                    "firebase_error" => $firebaseError->getMessage()
                ]);
            }
        } else {
            throw new Exception("Failed to fetch created section");
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
    }
    
    if (isset($stmt)) {
        $stmt->close();
    }
}

// Function to update a section
function updateSection($conn, $id, $data) {
    $conn->begin_transaction();
    
    try {
        // Check if section exists
        $checkStmt = $conn->prepare("SELECT section_id FROM sections WHERE section_id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            throw new Exception("Section not found");
        }
        $checkStmt->close();
        
        // Validate section_name length
        if (strlen($data['section_name']) > 50) {
            throw new Exception("Section name must not exceed 50 characters");
        }
        
        // Validate grade level if provided
        if (isset($data['grade_level']) && !in_array($data['grade_level'], ['11', '12'])) {
            throw new Exception("Grade level must be either '11' or '12'");
        }
        
        // Validate strand if provided
        if (isset($data['strand']) && strlen($data['strand']) > 10) {
            throw new Exception("Strand must not exceed 10 characters");
        }
        
        // Validate number of students if provided
        if (isset($data['number_of_students']) && (!is_numeric($data['number_of_students']) || $data['number_of_students'] < 0)) {
            throw new Exception("Number of students must be a non-negative integer");
        }
        
        // Validate room_ids if provided
        if (isset($data['room_ids'])) {
            if (!is_array($data['room_ids'])) {
                throw new Exception("room_ids must be an array");
            }
            
            // Check if all rooms exist
            $roomIds = array_map('intval', $data['room_ids']);
            $placeholders = str_repeat('?,', count($roomIds) - 1) . '?';
            $roomCheckStmt = $conn->prepare("SELECT room_id FROM rooms WHERE room_id IN ($placeholders)");
            $roomCheckStmt->bind_param(str_repeat('i', count($roomIds)), ...$roomIds);
            $roomCheckStmt->execute();
            $roomCheckResult = $roomCheckStmt->get_result();
            
            if ($roomCheckResult->num_rows !== count($roomIds)) {
                throw new Exception("One or more room IDs do not exist");
            }
            $roomCheckStmt->close();
        }
        
        // Get current section data
        $currentStmt = $conn->prepare("SELECT * FROM sections WHERE section_id = ?");
        $currentStmt->bind_param("i", $id);
        $currentStmt->execute();
        $currentResult = $currentStmt->get_result();
        $currentData = $currentResult->fetch_assoc();
        $currentStmt->close();
        
        // Prepare values
        $name = $data['section_name'];
        $gradeLevel = isset($data['grade_level']) ? $data['grade_level'] : $currentData['grade_level'];
        $numberOfStudents = isset($data['number_of_students']) ? (int)$data['number_of_students'] : $currentData['number_of_students'];
        $strand = isset($data['strand']) ? $data['strand'] : $currentData['strand'];
        $roomIds = isset($data['room_ids']) ? array_unique($data['room_ids']) : [];
        
        // Update section
        $sql = "UPDATE sections SET 
                section_name = ?, 
                grade_level = ?,
                number_of_students = ?,
                strand = ?
                WHERE section_id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Failed to prepare SQL statement");
        }
        
        $stmt->bind_param("ssisi", $name, $gradeLevel, $numberOfStudents, $strand, $id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update section");
        }
        $stmt->close();

        // Update room assignments
        // First, delete existing assignments
        $deleteStmt = $conn->prepare("DELETE FROM section_room_assignments WHERE section_id = ?");
        $deleteStmt->bind_param("i", $id);
        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to remove existing room assignments");
        }
        $deleteStmt->close();

        // Then, insert new assignments if any
        if (!empty($roomIds)) {
            $assignSql = "INSERT INTO section_room_assignments (section_id, room_id) VALUES (?, ?)";
            $assignStmt = $conn->prepare($assignSql);
            
            foreach ($roomIds as $roomId) {
                $assignStmt->bind_param("ii", $id, $roomId);
                if (!$assignStmt->execute()) {
                    throw new Exception("Failed to assign room to section");
                }
            }
            $assignStmt->close();
        }

        // Get updated section with room information
        $sql = "SELECT s.*, 
                GROUP_CONCAT(DISTINCT r.room_id, ':', r.room_number, ':', r.room_type, ':', r.room_capacity) as rooms
                FROM sections s
                LEFT JOIN section_room_assignments sra ON s.section_id = sra.section_id
                LEFT JOIN rooms r ON sra.room_id = r.room_id
                WHERE s.section_id = ?
                GROUP BY s.section_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
            $rooms = [];
            if ($row['rooms']) {
                $roomData = explode(',', $row['rooms']);
                foreach ($roomData as $room) {
                    list($id, $number, $type, $capacity) = explode(':', $room);
                    $rooms[] = [
                        'id' => (int)$id,
                        'number' => (int)$number,
                        'type' => $type,
                        'capacity' => (int)$capacity
                    ];
                }
            }
            
            $section = [
                'section_id' => (int)$row['section_id'],
                'section_name' => $row['section_name'],
                'grade_level' => $row['grade_level'],
                'strand' => $row['strand'],
                'number_of_students' => (int)$row['number_of_students'],
                'rooms' => $rooms
            ];
            
            // Commit transaction
            $conn->commit();
            
            echo json_encode([
                "status" => "success", 
                "message" => "Section updated successfully",
                "section" => $section
            ]);
        } else {
            throw new Exception("Failed to fetch updated section");
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
    }
    
    if (isset($stmt)) {
        $stmt->close();
    }
}

// Function to delete a section
function deleteSection($conn, $id) {
    $cascade = isset($_GET['cascade']) && $_GET['cascade'] == '1';

    // Does the section exist?
    $checkStmt = $conn->prepare("SELECT section_id FROM sections WHERE section_id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->num_rows > 0;
    $checkStmt->close();

    if (!$exists) {
        http_response_code(404);
        echo json_encode(["success" => false, "status" => "error", "message" => "Section not found"]);
        return;
    }

    // How many schedules reference this section?
    $cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM schedules WHERE section_id = ?");
    $cntStmt->bind_param("i", $id);
    $cntStmt->execute();
    $c = (int)($cntStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $cntStmt->close();

    if ($c > 0 && !$cascade) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "status"  => "error",
            "message" => "This section is referenced by $c schedule(s). Delete them first or pass cascade=1."
        ]);
        return;
    }

    $conn->begin_transaction();
    try {
        if ($c > 0) {
            // delete children in the right order
            // 1) schedule_days → depends on schedules
            $sdStmt = $conn->prepare("DELETE sd FROM schedule_days sd
                                      INNER JOIN schedules s ON s.schedule_id = sd.schedule_id
                                      WHERE s.section_id = ?");
            $sdStmt->bind_param("i", $id);
            $sdStmt->execute();
            $sdStmt->close();

            // 2) schedules
            $schStmt = $conn->prepare("DELETE FROM schedules WHERE section_id = ?");
            $schStmt->bind_param("i", $id);
            $schStmt->execute();
            $schStmt->close();
        }

        // 3) room assignments
        $delAssign = $conn->prepare("DELETE FROM section_room_assignments WHERE section_id = ?");
        $delAssign->bind_param("i", $id);
        $delAssign->execute();
        $delAssign->close();

        // 4) section
        $delSec = $conn->prepare("DELETE FROM sections WHERE section_id = ?");
        $delSec->bind_param("i", $id);
        $delSec->execute();
        $delSec->close();

        $conn->commit();
        echo json_encode(["success" => true, "status" => "success", "message" => "Section deleted successfully"]);
    } catch (Throwable $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["success" => false, "status" => "error", "message" => "Error: " . $e->getMessage()]);
    }
}

// Close the database connection   
$conn->close();
?>