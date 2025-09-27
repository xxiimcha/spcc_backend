<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once 'config.php';

try {
    $query = "SELECT p.id, p.name, p.email 
              FROM professors p 
              LEFT JOIN professor_subjects ps ON p.id = ps.professor_id 
              WHERE ps.professor_id IS NULL";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $teachers = array();
    while ($row = $result->fetch_assoc()) {
        $teachers[] = array(
            "id" => $row["id"],
            "name" => $row["name"],
            "email" => $row["email"]
        );
    }
    
    echo json_encode(array(
        "success" => true,
        "data" => $teachers
    ));
} catch (Exception $e) {
    echo json_encode(array(
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ));
}

$conn->close();
?> 