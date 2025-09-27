<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "config.php";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $prof_id = $_GET['prof_id'] ?? '';
    $school_year = $_GET['school_year'] ?? '';
    $semester = $_GET['semester'] ?? '';
    
    if (empty($prof_id) || empty($school_year) || empty($semester)) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required parameters'
        ]);
        exit;
    }
    
    $query = "SELECT 
                s.schedule_id,
                s.school_year,
                s.semester,
                subj.subj_code,
                subj.subj_name,
                s.start_time,
                s.end_time,
                s.days,
                r.room_number,
                sec.section_name
              FROM schedules s
              JOIN subjects subj ON s.subj_id = subj.subj_id
              LEFT JOIN rooms r ON s.room_id = r.room_id
              JOIN sections sec ON s.section_id = sec.section_id
              WHERE s.prof_id = ?
              AND s.school_year = ?
              AND s.semester = ?
              ORDER BY 
                CASE 
                    WHEN s.days[1] = 'Monday' THEN 1
                    WHEN s.days[1] = 'Tuesday' THEN 2
                    WHEN s.days[1] = 'Wednesday' THEN 3
                    WHEN s.days[1] = 'Thursday' THEN 4
                    WHEN s.days[1] = 'Friday' THEN 5
                    WHEN s.days[1] = 'Saturday' THEN 6
                    WHEN s.days[1] = 'Sunday' THEN 7
                END,
                s.start_time";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sss", $prof_id, $school_year, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $schedules = [];
    while ($row = $result->fetch_assoc()) {
        $row['days'] = json_decode($row['days']);
        $schedules[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'schedules' => $schedules
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
?>