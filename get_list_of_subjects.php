<?php
// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include 'connect.php';

// Check if professor_id parameter exists
if (!isset($_GET['professor_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing professor_id parameter"
    ]);
    exit();
}

$professor_id = $_GET['professor_id'];

// Query to get subjects assigned to this professor from schedules table
$sql = "SELECT s.subj_id, s.subj_code, s.subj_name, s.subj_description 
        FROM subjects s
        INNER JOIN schedules sc ON s.subj_id = sc.subj_id
        WHERE sc.prof_id = ?
        GROUP BY s.subj_id"; // Group by to avoid duplicates if multiple schedules

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $professor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $subjects = [];
    
    while ($row = $result->fetch_assoc()) {
        $subjects[] = [
            "subj_id" => $row["subj_id"],
            "subj_code" => $row["subj_code"],
            "subj_name" => $row["subj_name"],
            "subj_description" => $row["subj_description"]
        ];
    }
    
    echo json_encode([
        "status" => "success",
        "subjects" => $subjects
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