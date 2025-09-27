<?php
// dashboard_metrics.php

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

// Function to get metrics for dashboard
function getDashboardMetrics($conn) {
    $metrics = [
        'totalSchedules' => 0,
        'totalProfessors' => 0,
        'totalSubjects' => 0,
        'totalRooms' => 0,
        'totalSections' => 0
    ];
    
    // Get total schedules
    $sql = "SELECT COUNT(*) as count FROM schedules";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $metrics['totalSchedules'] = (int)$row['count'];
    }
    
    // Get total professors
    $sql = "SELECT COUNT(*) as count FROM professors";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $metrics['totalProfessors'] = (int)$row['count'];
    }
    
    // Get total subjects
    $sql = "SELECT COUNT(*) as count FROM subjects";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $metrics['totalSubjects'] = (int)$row['count'];
    }

    // Get total rooms
    $sql = "SELECT COUNT(*) as count FROM rooms";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $metrics['totalRooms'] = (int)$row['count'];
    }

    // Get total sections
    $sql = "SELECT COUNT(*) as count FROM sections";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $metrics['totalSections'] = (int)$row['count'];
    }
    
    return $metrics;
}

// Handle GET request
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    try {
        $metrics = getDashboardMetrics($conn);
        echo json_encode([
            "success" => true,
            "data" => $metrics
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