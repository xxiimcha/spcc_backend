<?php
// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include 'connect.php';

// Check if room_id parameter exists
if (!isset($_GET['room_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing room_id parameter"
    ]);
    exit();
}

$room_id = $_GET['room_id'];

// Query to get sections assigned to this room
$sql = "SELECT DISTINCT s.section_id, s.section_name, s.grade_level, s.strand, s.number_of_students,
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
        "status" => "success",
        "sections" => $sections
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Error: " . $conn->error
    ]);
}

$stmt->close();
$conn->close();
?>
