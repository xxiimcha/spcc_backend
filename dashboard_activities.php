<?php
// dashboard_activities.php - FIXED VERSION

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

// Enable error reporting for debugging - REMOVE IN PRODUCTION
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    // Include database connection
    include 'connect.php';
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Function to get recent activities
    function getRecentActivities($conn) {
        $activities = [];
        
        // Try-catch blocks for each query to prevent entire script failure
        try {
            // Get latest professors
            $sql = "SELECT prof_id, prof_name FROM professors ORDER BY prof_id DESC LIMIT 5";
            $result = $conn->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $daysAgo = rand(0, 7);
                    $timestamp = date('Y-m-d H:i:s', strtotime("-$daysAgo days"));
                    
                    $activities[] = [
                        'id' => $row['prof_id'],
                        'description' => "New professor added: " . $row['prof_name'],
                        'timestamp' => $timestamp,
                        'type' => 'professor'
                    ];
                }
            }
        } catch (Exception $e) {
            // Log error but continue with other queries
            error_log("Error fetching professors: " . $e->getMessage());
        }
        
        try {
            // Get latest subjects
            $sql = "SELECT subj_id, subj_code, subj_name FROM subjects ORDER BY subj_id DESC LIMIT 5";
            $result = $conn->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $daysAgo = rand(0, 7);
                    $timestamp = date('Y-m-d H:i:s', strtotime("-$daysAgo days"));
                    
                    $activities[] = [
                        'id' => $row['subj_id'],
                        'description' => "New subject created: " . $row['subj_code'] . " - " . $row['subj_name'],
                        'timestamp' => $timestamp,
                        'type' => 'subject'
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching subjects: " . $e->getMessage());
        }
        
        try {
            // Get latest schedules
            $sql = "SELECT s.schedule_id, subj.subj_code, p.prof_name
                    FROM schedules s
                    JOIN subjects subj ON s.subj_id = subj.subj_id
                    JOIN professors p ON s.prof_id = p.prof_id
                    ORDER BY s.schedule_id DESC LIMIT 5";
            $result = $conn->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $daysAgo = rand(0, 7);
                    $timestamp = date('Y-m-d H:i:s', strtotime("-$daysAgo days"));
                    
                    $activities[] = [
                        'id' => $row['schedule_id'],
                        'description' => "Schedule created: " . $row['subj_code'] . " assigned to " . $row['prof_name'],
                        'timestamp' => $timestamp,
                        'type' => 'schedule'
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching schedules: " . $e->getMessage());
        }

        try {
            // Get latest rooms
            $sql = "SELECT room_id, room_number, room_type FROM rooms ORDER BY room_id DESC LIMIT 5";
            $result = $conn->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $daysAgo = rand(0, 7);
                    $timestamp = date('Y-m-d H:i:s', strtotime("-$daysAgo days"));
                    
                    $activities[] = [
                        'id' => $row['room_id'],
                        'description' => "New room added: Room " . $row['room_number'] . " (" . $row['room_type'] . ")",
                        'timestamp' => $timestamp,
                        'type' => 'room'
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching rooms: " . $e->getMessage());
        }

        try {
            // Get latest sections
            $sql = "SELECT s.section_id, s.section_name, s.grade_level, s.strand, r.room_number
                    FROM sections s
                    LEFT JOIN rooms r ON s.room_id = r.room_id
                    ORDER BY s.section_id DESC LIMIT 5";
            $result = $conn->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $daysAgo = rand(0, 7);
                    $timestamp = date('Y-m-d H:i:s', strtotime("-$daysAgo days"));
                    
                    $roomInfo = isset($row['room_number']) && $row['room_number'] ? " in Room " . $row['room_number'] : "";
                    $strandInfo = isset($row['strand']) && $row['strand'] ? " - " . $row['strand'] : "";
                    
                    $activities[] = [
                        'id' => $row['section_id'],
                        'description' => "New section created: " . $row['section_name'] . " (Grade " . $row['grade_level'] . $strandInfo . ")" . $roomInfo,
                        'timestamp' => $timestamp,
                        'type' => 'section'
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching sections: " . $e->getMessage());
        }
        
        // Sort by timestamp (most recent first)
        if (!empty($activities)) {
            usort($activities, function($a, $b) {
                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
            });
            
            // Return at most 10 activities
            return array_slice($activities, 0, 10);
        }
        
        return $activities;
    }

    // Handle GET request
    if ($_SERVER['REQUEST_METHOD'] == 'GET') {
        $activities = getRecentActivities($conn);
        echo json_encode([
            "success" => true,
            "data" => $activities
        ]);
    } else {
        http_response_code(405);
        echo json_encode([
            "success" => false,
            "message" => "Method not allowed"
        ]);
    }

    // Close the connection
    $conn->close();
} catch (Exception $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ]);
}
?>