<?php
// subjects.php
include 'cors_helper.php';
handleCORS();

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

switch($method){
    case 'GET':
        // GET all subjects or a specific subject
        if(isset($_GET['id'])) {
            getSubject($conn, $_GET['id']);
        } else {
            getAllSubject($conn);
        }
        break;
        
    case 'POST':
        // Create a new subject
        $data = json_decode(file_get_contents('php://input'), true);
        createSubject($conn, $data);
        break;

    case 'PUT':
        // Update an existing subject
        $data = json_decode(file_get_contents('php://input'), true);
        if(isset($_GET['id'])) {
            updateSubject($conn, $_GET['id'], $data);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Subject ID is required']);
        }
        break;

    case 'DELETE':
        // Delete a subject
        if(isset($_GET['id'])) {
            deleteSubject($conn, $_GET['id']);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Subject ID is required']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}

function getAllSubject($conn){
    $sql = "SELECT s.*, 
            (SELECT COUNT(*) FROM schedules WHERE subj_id = s.subj_id) as schedule_count
            FROM subjects s";
    $result = $conn->query($sql);

    if($result){
        $subjects = [];
        while($row = $result->fetch_assoc()) {
            $subject = [
                'subj_id' => (int)$row['subj_id'],
                'subj_code'=> $row['subj_code'],
                'subj_name' => $row['subj_name'],
                'subj_description'=> $row['subj_description'],
                'schedule_count' => (int)$row['schedule_count']
            ];
            $subjects[] = $subject; 
        }
        echo json_encode([
            "success" => true,
            "status" => "success",
            "data" => $subjects
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "status" => "error",
            "message" => "Failed to fetch subjects"
        ]);
    }
}

function getSubject($conn, $id){
    $stmt = $conn->prepare("SELECT s.*, 
                           (SELECT COUNT(*) FROM schedules WHERE subj_id = s.subj_id) as schedule_count
                           FROM subjects s 
                           WHERE s.subj_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result && $row = $result->fetch_assoc()) {
        $subject = [
            'id' => (int)$row['subj_id'],
            'code'=> $row['subj_code'],
            'name' => $row['subj_name'],
            'description'=> $row['subj_description'],
            'schedule_count' => (int)$row['schedule_count']
        ];
        
        // Get current schedules for this subject
        $schedules_sql = "SELECT s.*, p.prof_name as professor_name, p.subj_count as professor_subject_count,
                         sec.section_name, sec.grade_level, sec.strand,
                         r.room_number, r.room_type, r.room_capacity
                         FROM schedules s
                         JOIN professors p ON s.prof_id = p.prof_id
                         JOIN sections sec ON s.section_id = sec.section_id
                         LEFT JOIN rooms r ON s.room_id = r.room_id
                         WHERE s.subj_id = ?";
        
        $schedules_stmt = $conn->prepare($schedules_sql);
        $schedules_stmt->bind_param("i", $id);
        $schedules_stmt->execute();
        $schedules_result = $schedules_stmt->get_result();
        
        $schedules = [];
        while ($schedule_row = $schedules_result->fetch_assoc()) {
            // Get days from JSON column
            $days = json_decode($schedule_row['days'], true) ?: [];
            
            $schedules[] = [
                'schedule_id' => (int)$schedule_row['schedule_id'],
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
            
            // No need to close statement as we're not using prepared statement anymore
        }
        
        $subject['schedules'] = $schedules;
        
        echo json_encode([
            "success" => true,
            "data" => $subject
        ]);
        
        $schedules_stmt->close();
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Subject not found"
        ]);
    }
    $stmt->close();
}

function createSubject($conn, $data){
    // Validate required fields
    $requiredFields = ['code', 'name'];
    foreach($requiredFields as $field){
        if(!isset($data[$field]) || empty($data[$field])){
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Missing required field: $field"]);
            exit();
        }
    }

    $code = $data["code"];
    $name = $data["name"];
    $description = isset($data["description"]) ? $data["description"] : '';

    $sql = "INSERT INTO subjects (subj_code, subj_name, subj_description)
            VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if($stmt === false){
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to prepare SQL statement: " . $conn->error]);
        exit();
    }

    $stmt->bind_param("sss", $code, $name, $description);

    if($stmt->execute()){
        $id = $conn->insert_id;
        http_response_code(201);
        echo json_encode(["status" => "success", "message" => "Subject added successfully.", "id" => $id]);    
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "ERROR: " . $stmt->error]);
    }
    $stmt->close();
}

// Function to update a subject
function updateSubject($conn, $id, $data){
    // Check if the subject exists
    $checkStmt = $conn->prepare("SELECT subj_id FROM subjects WHERE subj_id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if($checkResult->num_rows === 0){
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Subject not found"]);
        $checkStmt->close();
        exit();
    }
    $checkStmt->close();

    // Validate required fields
    $requiredFields = ['code', 'name'];
    foreach($requiredFields as $field){
        if(!isset($data[$field]) || empty($data[$field])){
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Missing required field: $field"]);
            exit();
        }
    }

    // Prepare values
    $code = $data["code"];
    $name = $data["name"];
    $description = isset($data["description"]) ? $data["description"] : '';

    $sql = "UPDATE subjects SET
            subj_code = ?,
            subj_name = ?,
            subj_description = ?
            WHERE subj_id = ?";
    
    $stmt = $conn->prepare($sql);
    if($stmt === false){
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to prepare SQL statement: " . $conn->error]);
        exit();
    }

    $stmt->bind_param("sssi", $code, $name, $description, $id);

    if($stmt->execute()){
        http_response_code(200);
        echo json_encode(["status" => "success", "message" => "Subject updated successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "ERROR: " . $stmt->error]);
    }
    $stmt->close();
}

// Function to delete a subject
function deleteSubject($conn, $id){
    // Check if the subject exists
    $checkStmt = $conn->prepare("SELECT subj_id FROM subjects WHERE subj_id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if($checkResult->num_rows === 0){
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Subject not found"]);
        $checkStmt->close();
        exit();
    }
    $checkStmt->close();

    // Delete the subject
    $sql = "DELETE FROM subjects WHERE subj_id = ?";
    $stmt = $conn->prepare($sql);
    
    if($stmt === false){
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to prepare SQL statement: " . $conn->error]);
        exit();
    }

    $stmt->bind_param("i", $id);

    if($stmt->execute()){
        http_response_code(200);
        echo json_encode(["status" => "success", "message" => "Subject deleted successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "ERROR: " . $stmt->error]);
    }
    $stmt->close();
}