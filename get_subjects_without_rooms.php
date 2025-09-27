<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once 'config.php';

try {
    $query = "SELECT s.id, s.code, s.name 
              FROM subjects s 
              LEFT JOIN schedules sch ON s.id = sch.subject_id 
              WHERE sch.room_id IS NULL 
              GROUP BY s.id";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $subjects = array();
    while ($row = $result->fetch_assoc()) {
        $subjects[] = array(
            "id" => $row["id"],
            "code" => $row["code"],
            "name" => $row["name"]
        );
    }
    
    echo json_encode(array(
        "success" => true,
        "data" => $subjects
    ));
} catch (Exception $e) {
    echo json_encode(array(
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ));
}

$conn->close();
?> 