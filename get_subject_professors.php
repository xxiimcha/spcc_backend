<?php
// get_subject_professors.php

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

include 'connect.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $subj_id = $_GET['subj_id'] ?? $_GET['subject_id'] ?? null;

    if (!$subj_id || !is_numeric($subj_id)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "status" => "error",
            "message" => "Valid subject ID is required"
        ]);
        exit;
    }

    try {
        $sql = "SELECT 
                    p.prof_id, 
                    p.prof_name, 
                    p.prof_email, 
                    p.prof_phone, 
                    p.prof_qualifications,
                    GROUP_CONCAT(
                        CONCAT(
                            s.schedule_type, ' - ', s.start_time, ' to ', s.end_time,
                            CASE 
                                WHEN s.semester IS NOT NULL AND s.school_year IS NOT NULL
                                THEN CONCAT(' (', s.semester, ' ', s.school_year, ')')
                                ELSE ''
                            END
                        ) SEPARATOR '; '
                    ) as schedules
                FROM professors p
                JOIN schedules s ON p.prof_id = s.prof_id
                WHERE s.subj_id = ?
                GROUP BY p.prof_id";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $subj_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $professors = $result->fetch_all(MYSQLI_ASSOC);

        echo json_encode([
            "success" => true,
            "status" => "success",
            "data" => [ "professors" => $professors ],
            "message" => "Professors fetched successfully"
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "status" => "error",
            "message" => "Failed to fetch professors",
            "error" => $e->getMessage()
        ]);
    } finally {
        $conn->close();
    }
} else {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Method not allowed"
    ]);
}
