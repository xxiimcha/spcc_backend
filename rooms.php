<?php
// rooms.php - API endpoint for room management

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

// Database connection
$host = "localhost";
$user = "root";
$password = "";
$database = "spcc_scheduling_system";

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
        // Get all rooms or a specific room
        if (isset($_GET['id'])) {
            getRoom($conn, $_GET['id']);
        } else {
            getAllRooms($conn);
        }
        break;
    
    case 'POST':
        // Create a new room
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        createRoom($conn, $data);
        break;
    
    case 'PUT':
        // Update an existing room
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Room ID is required"]);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        
        updateRoom($conn, $_GET['id'], $data);
        break;
    
    case 'DELETE':
        // Delete a room
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Room ID is required"]);
            exit();
        }
        
        deleteRoom($conn, $_GET['id']);
        break;
    
    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        break;
}

// Function to get all rooms
function getAllRooms($conn): void {
    $sql = "SELECT r.*, 
            (SELECT COUNT(*) FROM schedules WHERE room_id = r.room_id) as schedule_count
            FROM rooms r";
    
    $result = $conn->query($sql);
    
    if ($result) {
        $rooms = [];
        while ($row = $result->fetch_assoc()) {
            $room = [
                'id' => (int)$row['room_id'],
                'number' => (int)$row['room_number'],
                'type' => $row['room_type'],
                'capacity' => (int)$row['room_capacity'],
                'schedule_count' => (int)$row['schedule_count']
            ];
            
            // Get sections assigned to this room
            $sections_sql = "SELECT s.section_id, s.section_name, s.grade_level, s.strand
                           FROM sections s
                           JOIN section_room_assignments sra ON s.section_id = sra.section_id
                           WHERE sra.room_id = ?";
            $sections_stmt = $conn->prepare($sections_sql);
            $sections_stmt->bind_param("i", $room['id']);
            $sections_stmt->execute();
            $sections_result = $sections_stmt->get_result();
            
            $sections = [];
            while ($section_row = $sections_result->fetch_assoc()) {
                $sections[] = [
                    'section_id' => (int)$section_row['section_id'],
                    'section_name' => $section_row['section_name'],
                    'grade_level' => $section_row['grade_level'],
                    'strand' => $section_row['strand']
                ];
            }
            
            $room['sections'] = $sections;
            $rooms[] = $room;
            
            $sections_stmt->close();
        }
        
        echo json_encode([
            "success" => true,
            "data" => $rooms
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to fetch rooms"
        ]);
    }
}

// Function to get a specific room
function getRoom($conn, $id) {
    $sql = "SELECT r.*, 
            (SELECT COUNT(*) FROM schedules WHERE room_id = r.room_id) as schedule_count
            FROM rooms r 
            WHERE r.room_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $row = $result->fetch_assoc()) {
        $room = [
            'id' => (int)$row['room_id'],
            'number' => (int)$row['room_number'],
            'type' => $row['room_type'],
            'capacity' => (int)$row['room_capacity'],
            'schedule_count' => (int)$row['schedule_count']
        ];
        
        // Get sections assigned to this room
        $sections_sql = "SELECT s.section_id, s.section_name, s.grade_level, s.strand
                       FROM sections s
                       JOIN section_room_assignments sra ON s.section_id = sra.section_id
                       WHERE sra.room_id = ?";
        $sections_stmt = $conn->prepare($sections_sql);
        $sections_stmt->bind_param("i", $id);
        $sections_stmt->execute();
        $sections_result = $sections_stmt->get_result();
        
        $sections = [];
        while ($section_row = $sections_result->fetch_assoc()) {
            $sections[] = [
                'section_id' => (int)$section_row['section_id'],
                'section_name' => $section_row['section_name'],
                'grade_level' => $section_row['grade_level'],
                'strand' => $section_row['strand']
            ];
        }
        
        $room['sections'] = $sections;
        
        // Get current schedules for this room
        $schedules_sql = "SELECT s.*, subj.subj_code, subj.subj_name, 
                         p.prof_name as professor_name, p.subj_count as professor_subject_count,
                         sec.section_name, sec.grade_level, sec.strand
                         FROM schedules s
                         JOIN subjects subj ON s.subj_id = subj.subj_id
                         JOIN professors p ON s.prof_id = p.prof_id
                         JOIN sections sec ON s.section_id = sec.section_id
                         WHERE s.room_id = ?";
        
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
                'section' => [
                    'id' => (int)$schedule_row['section_id'],
                    'name' => $schedule_row['section_name'],
                    'grade_level' => $schedule_row['grade_level'],
                    'strand' => $schedule_row['strand']
                ],
                'schedule_type' => $schedule_row['schedule_type'],
                'start_time' => $schedule_row['start_time'],
                'end_time' => $schedule_row['end_time'],
                'days' => $days
            ];
            
            $days_stmt->close();
        }
        
        $room['schedules'] = $schedules;
        
        echo json_encode([
            "success" => true,
            "data" => $room
        ]);
        
        $schedules_stmt->close();
        $sections_stmt->close();
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Room not found"
        ]);
    }
    
    $stmt->close();
}

// Function to create a new room
function createRoom($conn, $data) {
    // Validate required fields
    $requiredFields = ['number', 'type', 'capacity'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
            exit();
        }
    }
    
    // Validate room type
    if (!in_array($data['type'], ['Lecture', 'Laboratory'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Room type must be either 'Lecture' or 'Laboratory'"]);
        exit();
    }
    
    // Validate room capacity
    if (!is_numeric($data['capacity']) || $data['capacity'] <= 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Room capacity must be a positive integer"]);
        exit();
    }

    // Validate room number
    if (!is_numeric($data['number']) || $data['number'] <= 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Room number must be a positive integer"]);
        exit();
    }
    
    // Check if room number already exists
    $checkStmt = $conn->prepare("SELECT room_id FROM rooms WHERE room_number = ?");
    $checkStmt->bind_param("i", $data['number']);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Room number already exists"]);
        $checkStmt->close();
        exit();
    }
    $checkStmt->close();
    
    // Prepare values
    $number = $data['number'];
    $type = $data['type'];
    $capacity = $data['capacity'];
    
    // Prepare the SQL statement
    $sql = "INSERT INTO rooms (room_number, room_type, room_capacity) VALUES (?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to prepare SQL statement"]);
        exit();
    }
    
    $stmt->bind_param("isi", $number, $type, $capacity);
    
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        $room = [
            'id' => (int)$id,
            'number' => (int)$number,
            'type' => $type,
            'capacity' => (int)$capacity
        ];
        
        http_response_code(201);
        echo json_encode([
            "status" => "success", 
            "message" => "Room added successfully", 
            "room" => $room
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
    }
    
    $stmt->close();
}

// Function to update a room
function updateRoom($conn, $id, $data) {
    $conn->begin_transaction();
    
    try {
    // Check if room exists
    $checkStmt = $conn->prepare("SELECT room_id FROM rooms WHERE room_id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
            throw new Exception("Room not found");
    }
    $checkStmt->close();
    
    // Validate room type if provided
    if (isset($data['type']) && !in_array($data['type'], ['Lecture', 'Laboratory'])) {
            throw new Exception("Room type must be either 'Lecture' or 'Laboratory'");
    }
    
    // Validate room capacity if provided
    if (isset($data['capacity']) && (!is_numeric($data['capacity']) || $data['capacity'] <= 0)) {
            throw new Exception("Room capacity must be a positive integer");
    }

    // Validate room number if provided
    if (isset($data['number']) && (!is_numeric($data['number']) || $data['number'] <= 0)) {
            throw new Exception("Room number must be a positive integer");
    }

    // Check if the new room number already exists (if room number is being updated)
    if (isset($data['number'])) {
        $checkStmt = $conn->prepare("SELECT room_id FROM rooms WHERE room_number = ? AND room_id != ?");
        $checkStmt->bind_param("ii", $data['number'], $id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
                throw new Exception("Room number already exists");
        }
        $checkStmt->close();
    }
    
    // Get current room data
    $currentStmt = $conn->prepare("SELECT * FROM rooms WHERE room_id = ?");
    $currentStmt->bind_param("i", $id);
    $currentStmt->execute();
    $currentResult = $currentStmt->get_result();
    $currentData = $currentResult->fetch_assoc();
    $currentStmt->close();
    
    // Prepare values
    $number = isset($data['number']) ? $data['number'] : $currentData['room_number'];
    $type = isset($data['type']) ? $data['type'] : $currentData['room_type'];
    $capacity = isset($data['capacity']) ? $data['capacity'] : $currentData['room_capacity'];
    
        // Update room
    $sql = "UPDATE rooms SET 
            room_number = ?, 
            room_type = ?,
            room_capacity = ?
            WHERE room_id = ?";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
            throw new Exception("Failed to prepare SQL statement");
    }
    
    $stmt->bind_param("isii", $number, $type, $capacity, $id);
    
        if (!$stmt->execute()) {
            throw new Exception("Failed to update room");
        }
        $stmt->close();

        // Get updated room data with schedule count
        $sql = "SELECT r.*, 
                (SELECT COUNT(*) FROM schedules WHERE room_id = r.room_id) as schedule_count
                FROM rooms r 
                WHERE r.room_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
        $room = [
                'id' => (int)$row['room_id'],
                'number' => (int)$row['room_number'],
                'type' => $row['room_type'],
                'capacity' => (int)$row['room_capacity'],
                'schedule_count' => (int)$row['schedule_count']
            ];
            
            // Commit transaction
            $conn->commit();
        
        echo json_encode([
            "status" => "success", 
            "message" => "Room updated successfully",
            "room" => $room
        ]);
    } else {
            throw new Exception("Failed to fetch updated room");
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

// Function to delete a room
function deleteRoom($conn, $id) {
    // Check if room exists
    $checkStmt = $conn->prepare("SELECT room_id FROM rooms WHERE room_id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Room not found"]);
        $checkStmt->close();
        exit();
    }
    $checkStmt->close();
    
    // Prepare the SQL statement
    $sql = "DELETE FROM rooms WHERE room_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Room deleted successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
    }
    
    $stmt->close();
}

// Close the database connection   
$conn->close();
?>