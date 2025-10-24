<?php
include 'cors_helper.php';

include 'connect.php';
require_once __DIR__ . '/system_settings_helper.php'; // ✅ import helper

function getDashboardMetrics($conn) {
    $metrics = [
        'totalSchedules'  => 0,
        'totalProfessors' => 0,
        'totalSubjects'   => 0,
        'totalRooms'      => 0,
        'totalSections'   => 0,
        'schoolYear'      => null
    ];

    // ✅ Get current school year from system settings
    $schoolYear = ss_get_current_school_year($conn);
    $metrics['schoolYear'] = $schoolYear;

    if (!$schoolYear) {
        return $metrics;
    }

    // Get total schedules for that school year
    $sql = "SELECT COUNT(*) as count FROM schedules WHERE school_year = '$schoolYear'";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $metrics['totalSchedules'] = (int)$row['count'];
    }

    // Get total professors for that school year (only if professors table has school_year)
    $sql = "SELECT COUNT(*) as count FROM professors WHERE school_year = '$schoolYear'";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $metrics['totalProfessors'] = (int)$row['count'];
    }

    // Get total subjects for that school year (only if subjects table has school_year)
    $sql = "SELECT COUNT(*) as count FROM subjects WHERE school_year = '$schoolYear'";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $metrics['totalSubjects'] = (int)$row['count'];
    }

    // Get total rooms for that school year (only if rooms table has school_year)
    $sql = "SELECT COUNT(*) as count FROM rooms WHERE school_year = '$schoolYear'";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        $metrics['totalRooms'] = (int)$row['count'];
    }

    // Get total sections for that school year (only if sections table has school_year)
    $sql = "SELECT COUNT(*) as count FROM sections WHERE school_year = '$schoolYear'";
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

$conn->close();
?>
