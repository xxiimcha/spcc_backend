<?php
// rooms.php - API endpoint for room management (no prepared statements) + school_year support

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Database connection
include 'connect.php';
$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

// Small helpers
function esc_str($conn, $v) { return mysqli_real_escape_string($conn, (string)$v); }
function as_int($v) { return (int)$v; }
function bad_request($msg){ http_response_code(400); echo json_encode(["status"=>"error","message"=>$msg]); exit(); }

// Load school year helper
require_once __DIR__ . '/system_settings_helper.php';

// Fetch current school year once per request
$currentSY = ss_get_current_school_year($conn);
if ($currentSY === null || $currentSY === '') {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Current school year is not configured."]);
    exit();
}
$currentSY_safe = esc_str($conn, $currentSY);

// Handle different HTTP methods
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getRoom($conn, $_GET['id'], $currentSY_safe);
        } else {
            getAllRooms($conn, $currentSY_safe);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) bad_request("Invalid JSON data");
        createRoom($conn, $data, $currentSY_safe);
        break;

    case 'PUT':
        if (!isset($_GET['id'])) bad_request("Room ID is required");
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) bad_request("Invalid JSON data");
        updateRoom($conn, $_GET['id'], $data, $currentSY_safe);
        break;

    case 'DELETE':
        if (!isset($_GET['id'])) bad_request("Room ID is required");
        deleteRoom($conn, $_GET['id'], $currentSY_safe);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        break;
}

// Function to get all rooms (scoped to current school year)
function getAllRooms($conn, $currentSY_safe): void {
    $sql = "SELECT r.*,
            (SELECT COUNT(*) FROM schedules WHERE room_id = r.room_id) AS schedule_count
            FROM rooms r
            WHERE r.school_year = '{$currentSY_safe}'";
    $result = $conn->query($sql);

    if ($result) {
        $rooms = [];
        while ($row = $result->fetch_assoc()) {
            $rooms[] = buildRoomWithSections($conn, $row, $currentSY_safe);
        }

        echo json_encode([
            "success" => true,
            "data" => $rooms
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Failed to fetch rooms"]);
    }
}

function buildRoomWithSections($conn, $row, $currentSY_safe) {
    $room = [
        'id' => as_int($row['room_id']),
        'number' => as_int($row['room_number']),
        'type' => $row['room_type'],
        'capacity' => as_int($row['room_capacity']),
        'school_year' => $row['school_year'],
        'schedule_count' => isset($row['schedule_count']) ? as_int($row['schedule_count']) : 0,
    ];

    $rid = as_int($row['room_id']);
    $sections_sql = "SELECT s.section_id, s.section_name, s.grade_level, s.strand
                     FROM sections s
                     JOIN section_room_assignments sra ON s.section_id = sra.section_id
                     WHERE sra.room_id = {$rid}";
    $sections_result = $conn->query($sections_sql);

    $sections = [];
    if ($sections_result) {
        while ($section_row = $sections_result->fetch_assoc()) {
            $sections[] = [
                'section_id' => as_int($section_row['section_id']),
                'section_name' => $section_row['section_name'],
                'grade_level' => $section_row['grade_level'],
                'strand' => $section_row['strand']
            ];
        }
    }
    $room['sections'] = $sections;

    return $room;
}

// Function to get a specific room (scoped to current school year)
function getRoom($conn, $id, $currentSY_safe) {
    $rid = as_int($id);
    $sql = "SELECT r.*,
            (SELECT COUNT(*) FROM schedules WHERE room_id = r.room_id) AS schedule_count
            FROM rooms r
            WHERE r.room_id = {$rid} AND r.school_year = '{$currentSY_safe}'
            LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $row = $result->fetch_assoc()) {
        $room = buildRoomWithSections($conn, $row, $currentSY_safe);

        // Get current schedules for this room (no SY filter here unless your schedules table also has a school_year)
        $schedules_sql = "SELECT s.*, subj.subj_code, subj.subj_name,
                          p.prof_name AS professor_name, p.subj_count AS professor_subject_count,
                          sec.section_name, sec.grade_level, sec.strand
                          FROM schedules s
                          JOIN subjects subj ON s.subj_id = subj.subj_id
                          JOIN professors p ON s.prof_id = p.prof_id
                          JOIN sections sec ON s.section_id = sec.section_id
                          WHERE s.room_id = {$rid}";
        $schedules_result = $conn->query($schedules_sql);

        $schedules = [];
        if ($schedules_result) {
            while ($schedule_row = $schedules_result->fetch_assoc()) {
                $sid = as_int($schedule_row['schedule_id']);
                // Get days per schedule
                $days_sql = "SELECT d.day_name
                             FROM schedule_days sd
                             JOIN days d ON sd.day_id = d.day_id
                             WHERE sd.schedule_id = {$sid}";
                $days_result = $conn->query($days_sql);
                $days = [];
                if ($days_result) {
                    while ($day_row = $days_result->fetch_assoc()) {
                        $days[] = $day_row['day_name'];
                    }
                }

                $schedules[] = [
                    'schedule_id' => $sid,
                    'subject' => [
                        'id' => as_int($schedule_row['subj_id']),
                        'code' => $schedule_row['subj_code'],
                        'name' => $schedule_row['subj_name']
                    ],
                    'professor' => [
                        'id' => as_int($schedule_row['prof_id']),
                        'name' => $schedule_row['professor_name'],
                        'subject_count' => as_int($schedule_row['professor_subject_count'])
                    ],
                    'section' => [
                        'id' => as_int($schedule_row['section_id']),
                        'name' => $schedule_row['section_name'],
                        'grade_level' => $schedule_row['grade_level'],
                        'strand' => $schedule_row['strand']
                    ],
                    'schedule_type' => $schedule_row['schedule_type'],
                    'start_time' => $schedule_row['start_time'],
                    'end_time' => $schedule_row['end_time'],
                    'days' => $days
                ];
            }
        }

        $room['schedules'] = $schedules;

        echo json_encode(["success" => true, "data" => $room]);
    } else {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Room not found"]);
    }
}

// Function to create a new room (inserts current school year)
function createRoom($conn, $data, $currentSY_safe) {
    // Validate required fields
    foreach (['number','type','capacity'] as $field) {
        if (!isset($data[$field])) bad_request("Missing required field: $field");
    }

    // Validate room type
    if (!in_array($data['type'], ['Lecture', 'Laboratory'], true)) {
        bad_request("Room type must be either 'Lecture' or 'Laboratory'");
    }

    // Validate capacity & number
    $capacity = as_int($data['capacity']);
    if ($capacity <= 0) bad_request("Room capacity must be a positive integer");

    $number = as_int($data['number']);
    if ($number <= 0) bad_request("Room number must be a positive integer");

    $type = esc_str($conn, $data['type']);

    // Check duplicate room number within the same school year
    $checkSql = "SELECT room_id FROM rooms WHERE room_number = {$number} AND school_year = '{$currentSY_safe}' LIMIT 1";
    $checkRes = $conn->query($checkSql);
    if ($checkRes && $checkRes->num_rows > 0) {
        bad_request("Room number already exists for the current school year");
    }

    // Insert with school_year
    $sql = "INSERT INTO rooms (room_number, room_type, room_capacity, school_year)
            VALUES ({$number}, '{$type}', {$capacity}, '{$currentSY_safe}')";

    if ($conn->query($sql) === TRUE) {
        $id = as_int($conn->insert_id);
        $room = [
            'id' => $id,
            'number' => $number,
            'type' => $data['type'],
            'capacity' => $capacity,
            'school_year' => $currentSY_safe
        ];
        http_response_code(201);
        echo json_encode(["status" => "success", "message" => "Room added successfully", "room" => $room]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $conn->error]);
    }
}

// Function to update a room (scoped to current school year)
function updateRoom($conn, $id, $data, $currentSY_safe) {
    $rid = as_int($id);

    $conn->begin_transaction();

    try {
        // Exists in current SY?
        $checkSql = "SELECT room_id FROM rooms WHERE room_id = {$rid} AND school_year = '{$currentSY_safe}' LIMIT 1";
        $checkRes = $conn->query($checkSql);
        if (!$checkRes || $checkRes->num_rows === 0) {
            throw new Exception("Room not found for the current school year");
        }

        // Validate optional fields
        if (isset($data['type']) && !in_array($data['type'], ['Lecture','Laboratory'], true)) {
            throw new Exception("Room type must be either 'Lecture' or 'Laboratory'");
        }
        if (isset($data['capacity'])) {
            $cap = as_int($data['capacity']);
            if ($cap <= 0) throw new Exception("Room capacity must be a positive integer");
        }
        if (isset($data['number'])) {
            $num = as_int($data['number']);
            if ($num <= 0) throw new Exception("Room number must be a positive integer");
        }

        // Duplicate number (if updating number) within current SY
        if (isset($data['number'])) {
            $num = as_int($data['number']);
            $dupSql = "SELECT room_id FROM rooms WHERE room_number = {$num} AND school_year = '{$currentSY_safe}' AND room_id != {$rid} LIMIT 1";
            $dupRes = $conn->query($dupSql);
            if ($dupRes && $dupRes->num_rows > 0) {
                throw new Exception("Room number already exists for the current school year");
            }
        }

        // Current values
        $curSql = "SELECT * FROM rooms WHERE room_id = {$rid} LIMIT 1";
        $curRes = $conn->query($curSql);
        if (!$curRes || !$curRes->num_rows) {
            throw new Exception("Room not found");
        }
        $current = $curRes->fetch_assoc();

        $number = isset($data['number']) ? as_int($data['number']) : as_int($current['room_number']);
        $type   = isset($data['type']) ? esc_str($conn, $data['type']) : esc_str($conn, $current['room_type']);
        $capacity = isset($data['capacity']) ? as_int($data['capacity']) : as_int($current['room_capacity']);

        // Update (school_year remains as originally inserted/current SY)
        $updSql = "UPDATE rooms SET
                   room_number = {$number},
                   room_type = '{$type}',
                   room_capacity = {$capacity}
                   WHERE room_id = {$rid} AND school_year = '{$currentSY_safe}'";
        if ($conn->query($updSql) !== TRUE) {
            throw new Exception("Failed to update room");
        }

        // Return updated with schedule count
        $retSql = "SELECT r.*,
                   (SELECT COUNT(*) FROM schedules WHERE room_id = r.room_id) AS schedule_count
                   FROM rooms r
                   WHERE r.room_id = {$rid} AND r.school_year = '{$currentSY_safe}'
                   LIMIT 1";
        $retRes = $conn->query($retSql);
        if (!$retRes || !($row = $retRes->fetch_assoc())) {
            throw new Exception("Failed to fetch updated room");
        }

        $room = [
            'id' => as_int($row['room_id']),
            'number' => as_int($row['room_number']),
            'type' => $row['room_type'],
            'capacity' => as_int($row['room_capacity']),
            'school_year' => $row['school_year'],
            'schedule_count' => as_int($row['schedule_count'])
        ];

        $conn->commit();
        echo json_encode(["status" => "success", "message" => "Room updated successfully", "room" => $room]);

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
    }
}

// Function to delete a room (scoped to current school year)
function deleteRoom($conn, $id, $currentSY_safe) {
    $rid = as_int($id);

    // Exists in current SY?
    $checkSql = "SELECT room_id FROM rooms WHERE room_id = {$rid} AND school_year = '{$currentSY_safe}' LIMIT 1";
    $checkRes = $conn->query($checkSql);
    if (!$checkRes || $checkRes->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Room not found for the current school year"]);
        exit();
    }

    $delSql = "DELETE FROM rooms WHERE room_id = {$rid} AND school_year = '{$currentSY_safe}'";
    if ($conn->query($delSql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Room deleted successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $conn->error]);
    }
}

// Close the database connection
$conn->close();
?>
