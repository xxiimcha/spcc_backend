<?php
// get_subject_professors.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

include 'connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Method not allowed"
    ]);
    exit;
}

$subj_id = $_GET['subj_id'] ?? $_GET['subject_id'] ?? null;
if ($subj_id !== null && !is_numeric($subj_id)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Invalid subject_id"
    ]);
    exit;
}

try {
    // Fetch all professors
    $profResult = mysqli_query($conn, "SELECT * FROM professors");
    if (!$profResult) throw new Exception("Failed to query professors: " . mysqli_error($conn));

    $professors = [];
    while ($row = mysqli_fetch_assoc($profResult)) {
        $ids = [];
        if (!empty($row['prof_subject_ids'])) {
            $decoded = json_decode($row['prof_subject_ids'], true);
            if (is_array($decoded)) $ids = array_map('intval', $decoded);
        }
        $row['prof_subject_ids'] = $ids;
        $professors[] = $row;
    }

    // Fetch all subjects
    $subjResult = mysqli_query($conn, "SELECT subj_id, subj_code, subj_name FROM subjects WHERE is_active = 1");
    if (!$subjResult) throw new Exception("Failed to query subjects: " . mysqli_error($conn));

    $subjects = [];
    while ($row = mysqli_fetch_assoc($subjResult)) {
        $subjects[$row['subj_id']] = $row;
    }

    // If a specific subject_id is requested
    if ($subj_id !== null) {
        $subj_id_int = (int)$subj_id;
        $assigned = [];
        foreach ($professors as $p) {
            if (in_array($subj_id_int, $p['prof_subject_ids'])) {
                $assigned[] = [
                    "prof_id"            => $p["prof_id"],
                    "prof_name"          => $p["prof_name"],
                    "prof_email"         => $p["prof_email"],
                    "prof_phone"         => $p["prof_phone"],
                    "prof_qualifications"=> $p["prof_qualifications"]
                ];
            }
        }

        echo json_encode([
            "success" => true,
            "status"  => "success",
            "data"    => [ "professors" => $assigned ],
            "message" => "Professors for subject $subj_id fetched successfully"
        ]);
        exit;
    }

    // Otherwise: return all subjects with their professors
    $out = [];
    foreach ($subjects as $sid => $s) {
        $assigned = [];
        foreach ($professors as $p) {
            if (in_array((int)$sid, $p['prof_subject_ids'])) {
                $assigned[] = [
                    "prof_id"            => $p["prof_id"],
                    "prof_name"          => $p["prof_name"],
                    "prof_email"         => $p["prof_email"],
                    "prof_phone"         => $p["prof_phone"],
                    "prof_qualifications"=> $p["prof_qualifications"]
                ];
            }
        }
        $out[] = [
            "subj_id"   => $s["subj_id"],
            "subj_code" => $s["subj_code"],
            "subj_name" => $s["subj_name"],
            "professors"=> $assigned
        ];
    }

    echo json_encode([
        "success" => true,
        "status"  => "success",
        "data"    => ["subjects" => $out],
        "message" => "All subjects with professors fetched successfully"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Failed to fetch professors",
        "error"   => $e->getMessage()
    ]);
} finally {
    mysqli_close($conn);
}
