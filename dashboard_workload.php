<?php
// dashboard_workload.php

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle OPTIONS preflight request
if($_SERVER['REQUEST_METHOD'] == 'OPTIONS'){
    exit(0);
}

include 'connect.php';

// Function to get workload alerts
function getWorkloadAlerts($conn) {
    $alerts = [];
    
    // Get professors with high workload (6-7 subjects)
    $sql = "SELECT prof_id, prof_name, subj_count FROM professors WHERE subj_count >= 6 AND subj_count < 8 ORDER BY subj_count DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $alerts[] = [
                'professor_name' => $row['prof_name'],
                'subject_count' => (int)$row['subj_count'],
                'alert_level' => 'high'
            ];
        }
    }
    
    // Get professors with maximum workload (8 subjects)
    $sql = "SELECT prof_id, prof_name, subj_count FROM professors WHERE subj_count >= 8 ORDER BY subj_count DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $alerts[] = [
                'professor_name' => $row['prof_name'],
                'subject_count' => (int)$row['subj_count'],
                'alert_level' => 'max'
            ];
        }
    }
    
    return $alerts;
}

// Handle GET request
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    try {
        $alerts = getWorkloadAlerts($conn);
        echo json_encode([
            "success" => true,
            "data" => $alerts
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Error: " . $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed"
    ]);
}

// Close the connection
$conn->close();
?>