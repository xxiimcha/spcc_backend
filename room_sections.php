<?php
// room_sections.php - API endpoint for room section assignments

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

include 'connect.php';

// Get room_id parameter
$room_id = isset($_GET['room_id']) ? intval($_GET['room_id']) : 0;

// Handle different HTTP methods
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get sections assigned to a room
        if ($room_id <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Missing or invalid room_id parameter"
            ]);
            exit();
        }
        
        getSectionsForRoom($conn, $room_id);
        break;
        
    case 'POST':
        // Add a section to a room
        if ($room_id <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Missing or invalid room_id parameter"
            ]);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['section_id'])) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Missing section_id in request body"
            ]);
            exit();
        }
        
        addSectionToRoom($conn, $room_id, $data['section_id']);
        break;
        
    case 'DELETE':
        // Remove a section from a room
        if ($room_id <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Missing or invalid room_id parameter"
            ]);
            exit();
        }
        
        $section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
        if ($section_id <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Missing or invalid section_id parameter"
            ]);
            exit();
        }
        
        removeSectionFromRoom($conn, $room_id, $section_id);
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            "success" => false,
            "message" => "Method not allowed"
        ]);
        break;
}

// Function to get sections assigned to a room
function getSectionsForRoom($conn, $room_id) {
    // First check if the room exists
    $roomCheckStmt = $conn->prepare("SELECT room_id FROM rooms WHERE room_id = ?");
    $roomCheckStmt->bind_param("i", $room_id);
    $roomCheckStmt->execute();
    $roomResult = $roomCheckStmt->get_result();
    
    if ($roomResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Room not found"
        ]);
        $roomCheckStmt->close();
        exit();
    }
    $roomCheckStmt->close();
    
    // Query to get sections assigned to this room
    $sql = "SELECT s.section_id, s.section_name, s.grade_level, s.strand, s.number_of_students,
            (SELECT COUNT(*) FROM schedules WHERE section_id = s.section_id) as schedule_count
            FROM sections s
            INNER JOIN section_room_assignments sra ON s.section_id = sra.section_id
            WHERE sra.room_id = ?
            ORDER BY s.grade_level, s.strand, s.section_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        $sections = [];
        
        while ($row = $result->fetch_assoc()) {
            $sections[] = [
                "section_id" => (int)$row["section_id"],
                "section_name" => $row["section_name"],
                "grade_level" => $row["grade_level"],
                "strand" => $row["strand"],
                "number_of_students" => (int)$row["number_of_students"],
                "schedule_count" => (int)$row["schedule_count"]
            ];
        }
        
        echo json_encode([
            "success" => true,
            "data" => $sections
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Error: " . $conn->error
        ]);
    }

    $stmt->close();
}

// Function to add a section to a room
function addSectionToRoom($conn, $room_id, $section_id) {
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Check if room exists
        $roomCheckStmt = $conn->prepare("SELECT room_id FROM rooms WHERE room_id = ?");
        $roomCheckStmt->bind_param("i", $room_id);
        $roomCheckStmt->execute();
        $roomResult = $roomCheckStmt->get_result();
        
        if ($roomResult->num_rows === 0) {
            throw new Exception("Room not found");
        }
        $roomCheckStmt->close();
        
        // Check if section exists
        $sectionCheckStmt = $conn->prepare("SELECT section_id FROM sections WHERE section_id = ?");
        $sectionCheckStmt->bind_param("i", $section_id);
        $sectionCheckStmt->execute();
        $sectionResult = $sectionCheckStmt->get_result();
        
        if ($sectionResult->num_rows === 0) {
            throw new Exception("Section not found");
        }
        $sectionCheckStmt->close();
        
        // Check if assignment already exists
        $assignmentCheckStmt = $conn->prepare("SELECT * FROM section_room_assignments WHERE room_id = ? AND section_id = ?");
        $assignmentCheckStmt->bind_param("ii", $room_id, $section_id);
        $assignmentCheckStmt->execute();
        $assignmentResult = $assignmentCheckStmt->get_result();
        
        if ($assignmentResult->num_rows > 0) {
            throw new Exception("Section is already assigned to this room");
        }
        $assignmentCheckStmt->close();
        
        // Create the assignment
        $assignmentStmt = $conn->prepare("INSERT INTO section_room_assignments (room_id, section_id) VALUES (?, ?)");
        $assignmentStmt->bind_param("ii", $room_id, $section_id);
        
        if (!$assignmentStmt->execute()) {
            throw new Exception("Failed to assign section to room");
        }
        $assignmentStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "Section successfully assigned to room"
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Error: " . $e->getMessage()
        ]);
    }
}

// Function to remove a section from a room
function removeSectionFromRoom($conn, $room_id, $section_id) {
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Check if assignment exists
        $assignmentCheckStmt = $conn->prepare("SELECT * FROM section_room_assignments WHERE room_id = ? AND section_id = ?");
        $assignmentCheckStmt->bind_param("ii", $room_id, $section_id);
        $assignmentCheckStmt->execute();
        $assignmentResult = $assignmentCheckStmt->get_result();
        
        if ($assignmentResult->num_rows === 0) {
            throw new Exception("Section is not assigned to this room");
        }
        $assignmentCheckStmt->close();
        
        // Check if section has schedules in this room
        $scheduleCheckStmt = $conn->prepare("SELECT * FROM schedules WHERE room_id = ? AND section_id = ?");
        $scheduleCheckStmt->bind_param("ii", $room_id, $section_id);
        $scheduleCheckStmt->execute();
        $scheduleResult = $scheduleCheckStmt->get_result();
        
        if ($scheduleResult->num_rows > 0) {
            throw new Exception("Cannot unassign section because it has schedules in this room");
        }
        $scheduleCheckStmt->close();
        
        // Remove the assignment
        $assignmentStmt = $conn->prepare("DELETE FROM section_room_assignments WHERE room_id = ? AND section_id = ?");
        $assignmentStmt->bind_param("ii", $room_id, $section_id);
        
        if (!$assignmentStmt->execute()) {
            throw new Exception("Failed to unassign section from room");
        }
        $assignmentStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "Section successfully unassigned from room"
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Error: " . $e->getMessage()
        ]);
    }
}

// Close the database connection
$conn->close();
?>