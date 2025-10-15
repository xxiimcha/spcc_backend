<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/connect.php';
@include_once __DIR__ . '/activity_logger.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit();
}

function bind_params(mysqli_stmt $stmt, string $types, array &$params): bool {
    $refs = [];
    $refs[] = $types;
    foreach ($params as $k => &$v) $refs[] = &$params[$k];
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

function json_out($ok, $payload = []) {
    echo json_encode(array_merge(['success' => (bool)$ok], $payload));
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetSchedules($conn);
        break;
    case 'POST':
        handleCreateSchedule($conn);
        break;
    case 'PUT':
        handleUpdateSchedule($conn);
        break;
    case 'DELETE':
        handleDeleteSchedule($conn);
        break;
    default:
        http_response_code(405);
        json_out(false, ['message' => 'Method not allowed']);
}

function handleCreateSchedule(mysqli $conn) {
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);

        if (!$input || !is_array($input)) json_out(false, ['message' => 'Invalid JSON input']);

        $required = ['school_year','semester','subj_id','prof_id','section_id','schedule_type','start_time','end_time','days'];
        foreach ($required as $f) {
            if (!array_key_exists($f, $input) || (is_array($input[$f]) ? empty($input[$f]) : trim((string)$input[$f]) === '')) {
                json_out(false, ['message' => "Missing required field: $f"]);
            }
        }

        $school_year   = (string)$input['school_year'];
        $semester      = (string)$input['semester'];
        $subj_id       = (int)$input['subj_id'];
        $prof_id       = (int)$input['prof_id'];
        $schedule_type = (string)$input['schedule_type'];
        $start_time    = (string)$input['start_time'];
        $end_time      = (string)$input['end_time'];
        $room_id       = isset($input['room_id']) && $input['room_id'] !== '' ? (int)$input['room_id'] : null;
        $section_id    = (int)$input['section_id'];
        $days_json     = json_encode($input['days']);
        $online_mode   = isset($input['online_mode']) && $input['online_mode'] !== '' ? (string)$input['online_mode'] : null;

        if (!in_array($schedule_type, ['Onsite', 'Online'], true)) json_out(false, ['message' => "Invalid schedule_type. Must be 'Onsite' or 'Online'."]);

        if ($schedule_type === 'Online') {
            if (!$online_mode || !in_array($online_mode, ['Synchronous','Asynchronous'], true)) {
                json_out(false, ['message' => "online_mode is required for Online schedules (Synchronous/Asynchronous)."]);
            }
            $room_id = null;
        } else {
            $online_mode = null;
        }

        $sql = "INSERT INTO schedules
                (school_year, semester, subj_id, prof_id, schedule_type, online_mode, start_time, end_time, created_at, room_id, section_id, days)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            @log_activity($conn, 'schedules', 'error', 'Create prepare failed: '.$conn->error, null, null);
            json_out(false, ['message' => 'Failed to prepare insert']);
        }

        $types = 'ssiissssiis';
        $params = [
            $school_year,
            $semester,
            $subj_id,
            $prof_id,
            $schedule_type,
            $online_mode,
            $start_time,
            $end_time,
            $room_id,
            $section_id,
            $days_json
        ];

        if (!bind_params($stmt, $types, $params)) {
            @log_activity($conn, 'schedules', 'error', 'Create bind failed: '.$stmt->error, null, null);
            json_out(false, ['message' => 'Failed to bind parameters']);
        }

        $ok = $stmt->execute();
        if (!$ok) {
            @log_activity($conn, 'schedules', 'error', 'Create execute failed: '.$stmt->error, null, null);
            json_out(false, ['message' => 'Failed to create schedule']);
        }

        $id = $conn->insert_id;

        try {
            $syncFile = __DIR__ . '/realtime_firebase_sync.php';
            if (file_exists($syncFile)) {
                require_once $syncFile;
                if (class_exists('RealtimeFirebaseSync')) {
                    $sync = new RealtimeFirebaseSync();
                    $sync->syncSingleSchedule($id);
                }
            }
        } catch (Throwable $e) {
            error_log("Firebase sync failed for schedule $id: " . $e->getMessage());
        }

        @log_activity($conn, 'schedules', 'success', 'Schedule created', null, $id);

        json_out(true, [
            'message' => 'Schedule created successfully',
            'data' => [
                'schedule_id'   => (int)$id,
                'school_year'   => $school_year,
                'semester'      => $semester,
                'subj_id'       => $subj_id,
                'prof_id'       => $prof_id,
                'section_id'    => $section_id,
                'room_id'       => $room_id,
                'schedule_type' => $schedule_type,
                'online_mode'   => $online_mode,
                'start_time'    => $start_time,
                'end_time'      => $end_time,
                'days'          => json_decode($days_json, true),
            ]
        ]);
    } catch (Throwable $e) {
        @log_activity($conn, 'schedules', 'error', 'Create exception: '.$e->getMessage(), null, null);
        json_out(false, ['message' => 'Error creating schedule: ' . $e->getMessage()]);
    }
}

function handleGetSchedules(mysqli $conn) {
    try {
        $school_year  = $_GET['school_year']  ?? '';
        $semester     = $_GET['semester']     ?? '';
        $professor_id = $_GET['professor_id'] ?? '';

        $sql = "SELECT s.*,
                       subj.subj_name, subj.subj_code,
                       p.prof_name AS professor_name,
                       sect.section_name, sect.grade_level AS level, sect.strand,
                       r.room_number, r.room_type
                  FROM schedules s
             LEFT JOIN subjects   subj ON s.subj_id   = subj.subj_id
             LEFT JOIN professors p    ON s.prof_id   = p.prof_id
             LEFT JOIN sections   sect ON s.section_id= sect.section_id
             LEFT JOIN rooms      r    ON s.room_id   = r.room_id
                 WHERE 1=1";
        $conds = [];
        $types = '';
        $params = [];

        if ($school_year !== '') { $conds[] = " AND s.school_year = ?"; $types .= 's'; $params[] = $school_year; }
        if ($semester    !== '') { $conds[] = " AND s.semester    = ?"; $types .= 's'; $params[] = $semester; }
        if ($professor_id!== '') { $conds[] = " AND s.prof_id     = ?"; $types .= 'i'; $params[] = (int)$professor_id; }

        $sql .= implode('', $conds) . " ORDER BY s.schedule_id DESC";

        if ($types === '') {
            $result = $conn->query($sql);
            if (!$result) {
                @log_activity($conn, 'schedules', 'error', 'Get query failed: '.$conn->error, null, null);
                json_out(false, ['message' => 'Failed to retrieve schedules']);
            }
        } else {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                @log_activity($conn, 'schedules', 'error', 'Get prepare failed: '.$conn->error, null, null);
                json_out(false, ['message' => 'Failed to prepare query']);
            }
            if (!bind_params($stmt, $types, $params)) {
                @log_activity($conn, 'schedules', 'error', 'Get bind failed: '.$stmt->error, null, null);
                json_out(false, ['message' => 'Failed to bind parameters']);
            }
            if (!$stmt->execute()) {
                @log_activity($conn, 'schedules', 'error', 'Get execute failed: '.$stmt->error, null, null);
                json_out(false, ['message' => 'Failed to execute query']);
            }
            $result = $stmt->get_result();
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (isset($row['days'])) $row['days'] = json_decode($row['days'], true) ?: [];
            $rows[] = $row;
        }

        @log_activity($conn, 'schedules', 'success', 'Schedules retrieved', null, null);

        json_out(true, ['data' => $rows, 'message' => 'Schedules retrieved successfully']);
    } catch (Throwable $e) {
        @log_activity($conn, 'schedules', 'error', 'Get exception: '.$e->getMessage(), null, null);
        json_out(false, ['message' => 'Error retrieving schedules: ' . $e->getMessage()]);
    }
}

function handleUpdateSchedule(mysqli $conn) {
    try {
        $id = $_GET['id'] ?? '';
        if ($id === '') json_out(false, ['message' => 'Schedule ID is required']);
        $id = (int)$id;

        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        if (!$input) json_out(false, ['message' => 'Invalid JSON input']);

        $allowed = [
            'school_year','semester','subj_id','prof_id','section_id','room_id',
            'schedule_type','online_mode','start_time','end_time','days'
        ];
        $set = [];
        $types = '';
        $params = [];

        $incoming_type = array_key_exists('schedule_type', $input) ? (string)$input['schedule_type'] : null;
        $incoming_mode = array_key_exists('online_mode',   $input) ? (string)$input['online_mode']   : null;

        if ($incoming_type !== null && !in_array($incoming_type, ['Onsite','Online'], true)) {
            json_out(false, ['message' => "Invalid schedule_type. Must be 'Onsite' or 'Online'."]);
        }
        if ($incoming_type === 'Online') {
            if (!$incoming_mode || !in_array($incoming_mode, ['Synchronous','Asynchronous'], true)) {
                json_out(false, ['message' => "online_mode is required for Online schedules (Synchronous/Asynchronous)."]);
            }
            $input['room_id'] = null;
        }
        if ($incoming_type === 'Onsite') {
            $input['online_mode'] = null;
        }

        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                $set[] = "$f = ?";
                if ($f === 'subj_id' || $f === 'prof_id' || $f === 'section_id' || $f === 'room_id') {
                    $types .= 'i';
                    $params[] = ($input[$f] === null || $input[$f] === '') ? null : (int)$input[$f];
                } elseif ($f === 'days') {
                    $types .= 's';
                    $params[] = json_encode($input[$f]);
                } else {
                    $types .= 's';
                    $params[] = ($input[$f] === null ? null : (string)$input[$f]);
                }
            }
        }

        if (empty($set)) json_out(false, ['message' => 'No valid fields to update']);

        $sql = "UPDATE schedules SET " . implode(', ', $set) . " WHERE schedule_id = ?";
        $types .= 'i';
        $params[] = $id;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            @log_activity($conn, 'schedules', 'error', 'Update prepare failed: '.$conn->error, null, $id);
            json_out(false, ['message' => 'Failed to prepare update']);
        }

        if (!bind_params($stmt, $types, $params)) {
            @log_activity($conn, 'schedules', 'error', 'Update bind failed: '.$stmt->error, null, $id);
            json_out(false, ['message' => 'Failed to bind parameters']);
        }

        if (!$stmt->execute()) {
            @log_activity($conn, 'schedules', 'error', 'Update execute failed: '.$stmt->error, null, $id);
            json_out(false, ['message' => 'Failed to update schedule']);
        }

        try {
            $syncFile = __DIR__ . '/realtime_firebase_sync.php';
            if (file_exists($syncFile)) {
                require_once $syncFile;
                if (class_exists('RealtimeFirebaseSync')) {
                    $sync = new RealtimeFirebaseSync();
                    $sync->syncSingleSchedule($id);
                }
            }
        } catch (Throwable $e) {
            error_log("Firebase sync failed for schedule update $id: " . $e->getMessage());
        }

        @log_activity($conn, 'schedules', 'success', 'Schedule updated', null, $id);

        json_out(true, ['message' => 'Schedule updated successfully']);
    } catch (Throwable $e) {
        @log_activity($conn, 'schedules', 'error', 'Update exception: '.$e->getMessage(), null, null);
        json_out(false, ['message' => 'Error updating schedule: ' . $e->getMessage()]);
    }
}

function handleDeleteSchedule(mysqli $conn) {
    try {
        $id = $_GET['id'] ?? '';
        if ($id === '') json_out(false, ['message' => 'Schedule ID is required']);
        $id = (int)$id;

        $sql = "DELETE FROM schedules WHERE schedule_id = $id";
        $ok  = $conn->query($sql);

        if ($ok === false) {
            $err = $conn->error ?: 'Failed to delete schedule';
            @log_activity($conn, 'schedules', 'error', 'Delete failed: '.$err, null, $id);
            json_out(false, ['message' => "Failed to delete schedule: $err"]);
        }

        if ($conn->affected_rows < 1) {
            @log_activity($conn, 'schedules', 'error', 'Delete affected_rows=0', null, $id);
            json_out(false, ['message' => 'No schedule deleted (id not found).']);
        }

        try {
            $syncFile = __DIR__ . '/realtime_firebase_sync.php';
            if (file_exists($syncFile)) {
                require_once $syncFile;
                if (class_exists('RealtimeFirebaseSync')) {
                    $sync = new RealtimeFirebaseSync();
                    $sync->deleteSingleSchedule($id);
                }
            }
        } catch (Throwable $e) {
            error_log("Firebase sync failed for schedule deletion $id: " . $e->getMessage());
        }

        @log_activity($conn, 'schedules', 'success', 'Schedule deleted', null, $id);

        json_out(true, ['message' => 'Schedule deleted successfully']);
    } catch (Throwable $e) {
        @log_activity($conn, 'schedules', 'error', 'Delete exception: '.$e->getMessage(), null, null);
        json_out(false, ['message' => 'Error deleting schedule: ' . $e->getMessage()]);
    }
}
