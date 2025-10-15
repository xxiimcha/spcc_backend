<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once '../connect.php';
require_once '../system_settings_helper.php';

$action = isset($_GET['action']) ? strtolower(trim($_GET['action'])) : 'all';

function teachers_without_subjects(mysqli $conn, string $schoolYear): array {
    $sy = mysqli_real_escape_string($conn, $schoolYear);

    $sql = "
        SELECT p.prof_id   AS id,
               p.prof_name AS name,
               p.prof_email AS email
        FROM professors p
        LEFT JOIN schedules sch
               ON sch.prof_id = p.prof_id
              AND sch.school_year = '$sy'
        WHERE sch.prof_id IS NULL
          AND p.school_year = '$sy'          
        ORDER BY p.prof_name ASC
    ";

    $res = $conn->query($sql);
    if (!$res) throw new Exception($conn->error);

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            "id"    => (string)$row["id"],
            "name"  => $row["name"],
            "email" => $row["email"] ?? null,
        ];
    }
    return $out;
}

function subjects_without_rooms(mysqli $conn, string $schoolYear): array {
    $sy = mysqli_real_escape_string($conn, $schoolYear);

    $sql = "
        SELECT s.subj_id   AS id,
               s.subj_code AS code,
               s.subj_name AS name
        FROM subjects s
        LEFT JOIN schedules sch
               ON sch.subj_id = s.subj_id
              AND sch.room_id IS NOT NULL
              AND sch.school_year = '$sy'
        WHERE sch.subj_id IS NULL
        GROUP BY s.subj_id, s.subj_code, s.subj_name
        ORDER BY s.subj_code ASC
    ";

    $res = $conn->query($sql);
    if (!$res) throw new Exception($conn->error);

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            "id"   => (string)$row["id"],
            "code" => $row["code"],
            "name" => $row["name"],
        ];
    }
    return $out;
}

try {
    $currentSY = ss_get_current_school_year($conn);
    if (!$currentSY) {
        throw new Exception("No current school year is set in system settings.");
    }

    if ($action === 'teachers' || $action === 'teachers_without_subjects') {
        echo json_encode([
            "success" => true,
            "school_year" => $currentSY,
            "data" => teachers_without_subjects($conn, $currentSY)
        ]);
    } elseif ($action === 'subjects' || $action === 'subjects_without_rooms') {
        echo json_encode([
            "success" => true,
            "school_year" => $currentSY,
            "data" => subjects_without_rooms($conn, $currentSY)
        ]);
    } else { 
        echo json_encode([
            "success" => true,
            "school_year" => $currentSY,
            "data" => [
                "teachers_without_subjects" => teachers_without_subjects($conn, $currentSY),
                "subjects_without_rooms"    => subjects_without_rooms($conn, $currentSY),
            ]
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ]);
}

$conn->close();
