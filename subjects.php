<?php
// subjects.php (no prepared statements)
include 'cors_helper.php';
handleCORS();

include 'connect.php';

$conn = new mysqli($host, $user, $password, $database);
header('Content-Type: application/json; charset=utf-8');

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["success" => false, "status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}
$conn->set_charset('utf8mb4');

// ---- helpers ---------------------------------------------------------------
function esc($conn, $val) {
  return mysqli_real_escape_string($conn, (string)$val);
}
function in_arrayi($needle, $haystack) {
  return in_array(strtolower((string)$needle), array_map('strtolower', $haystack), true);
}
function read_field($data, $snake, $camel, $default = null) {
  return $data[$snake] ?? $data[$camel] ?? $default;
}
function read_field_multi($data, $keys, $default = null) {
  foreach ($keys as $k) { if (array_key_exists($k, $data)) return $data[$k]; }
  return $default;
}

$ALLOWED_TYPES = ['core','applied','specialized','contextualized','elective'];

// Handle different HTTP methods
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
  case 'GET':
    if (isset($_GET['id'])) {
      getSubject($conn, (int)$_GET['id']);
    } else {
      getAllSubject($conn);
    }
    break;

  case 'POST':
    $data = json_decode(file_get_contents('php://input'), true);
    createSubject($conn, is_array($data) ? $data : [], $ALLOWED_TYPES);
    break;

  case 'PUT':
    $data = json_decode(file_get_contents('php://input'), true);
    if (isset($_GET['id'])) {
      updateSubject($conn, (int)$_GET['id'], is_array($data) ? $data : [], $ALLOWED_TYPES);
    } else {
      http_response_code(400);
      echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Subject ID is required']);
    }
    break;

  case 'DELETE':
    if (isset($_GET['id'])) {
      deleteSubject($conn, (int)$_GET['id']);
    } else {
      http_response_code(400);
      echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Subject ID is required']);
    }
    break;

  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Method not allowed']);
    break;
}

// ---------------------------------------------------------------------------

function getAllSubject($conn) {
  $sql = "SELECT s.*,
                 (SELECT COUNT(*) FROM schedules WHERE subj_id = s.subj_id) AS schedule_count
          FROM subjects s";
  $result = $conn->query($sql);

  if ($result) {
    $subjects = [];
    while ($row = $result->fetch_assoc()) {
      $subjects[] = [
        'subj_id'          => (int)$row['subj_id'],
        'subj_code'        => $row['subj_code'],
        'subj_name'        => $row['subj_name'],
        'subj_description' => $row['subj_description'],
        'grade_level'      => $row['grade_level'],
        'strand'           => $row['strand'],
        'subj_type'        => $row['subj_type'],
        'is_active'        => isset($row['is_active']) ? (int)$row['is_active'] : 1,
        'schedule_count'   => (int)$row['schedule_count'],
      ];
    }
    echo json_encode(["success" => true, "status" => "success", "data" => $subjects]);
  } else {
    http_response_code(500);
    echo json_encode(["success" => false, "status" => "error", "message" => "Failed to fetch subjects"]);
  }
}

function getSubject($conn, $id) {
  $id = (int)$id;

  $sql = "SELECT s.*,
                 (SELECT COUNT(*) FROM schedules WHERE subj_id = s.subj_id) AS schedule_count
          FROM subjects s
          WHERE s.subj_id = {$id}
          LIMIT 1";
  $result = $conn->query($sql);

  if ($result && $row = $result->fetch_assoc()) {
    $subject = [
      'id'              => (int)$row['subj_id'],
      'code'            => $row['subj_code'],
      'name'            => $row['subj_name'],
      'description'     => $row['subj_description'],
      'grade_level'     => $row['grade_level'],
      'strand'          => $row['strand'],
      'subj_type'       => $row['subj_type'],
      'is_active'       => isset($row['is_active']) ? (int)$row['is_active'] : 1,
      'schedule_count'  => (int)$row['schedule_count']
    ];

    // schedules list (read-only view)
    $sched_sql = "SELECT s.*, 
                         p.prof_name AS professor_name, p.subj_count AS professor_subject_count,
                         sec.section_name, sec.grade_level, sec.strand,
                         r.room_number, r.room_type, r.room_capacity
                  FROM schedules s
                  JOIN professors p ON s.prof_id = p.prof_id
                  JOIN sections   sec ON s.section_id = sec.section_id
                  LEFT JOIN rooms r   ON s.room_id = r.room_id
                  WHERE s.subj_id = {$id}";
    $sched_res = $conn->query($sched_sql);

    $schedules = [];
    if ($sched_res) {
      while ($sr = $sched_res->fetch_assoc()) {
        $days = json_decode($sr['days'] ?? '[]', true) ?: [];
        $schedules[] = [
          'schedule_id' => (int)$sr['schedule_id'],
          'professor'   => [
            'id'            => (int)$sr['prof_id'],
            'name'          => $sr['professor_name'],
            'subject_count' => (int)$sr['professor_subject_count']
          ],
          'section'     => [
            'id'          => (int)$sr['section_id'],
            'name'        => $sr['section_name'],
            'grade_level' => $sr['grade_level'],
            'strand'      => $sr['strand']
          ],
          'room' => $sr['room_id'] ? [
            'id'       => (int)$sr['room_id'],
            'number'   => (int)$sr['room_number'],
            'type'     => $sr['room_type'],
            'capacity' => (int)$sr['room_capacity']
          ] : null,
          'schedule_type' => $sr['schedule_type'],
          'start_time'    => $sr['start_time'],
          'end_time'      => $sr['end_time'],
          'days'          => $days
        ];
      }
    }
    $subject['schedules'] = $schedules;

    echo json_encode(["success" => true, "data" => $subject]);
  } else {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Subject not found"]);
  }
}

function createSubject($conn, $data, $ALLOWED_TYPES) {
  // Accept both naming styles
  $code        = read_field($data, 'subj_code', 'code');
  $name        = read_field($data, 'subj_name', 'name');
  $description = read_field($data, 'subj_description', 'description', '');
  $gradeLevel  = read_field($data, 'grade_level', 'gradeLevel', null);   // optional
  $strand      = read_field($data, 'strand', 'strand', null);            // optional
  $subjType    = read_field_multi($data, ['subj_type','type','subjectType'], null);
  $isActive    = (int)read_field($data, 'is_active', 'isActive', 1);

  // Normalize
  if (is_string($subjType)) $subjType = strtolower($subjType);

  // Basic validation
  if (!$code) { http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Missing required field: code"]); return; }
  if (!$name) { http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Missing required field: name"]); return; }
  if ($gradeLevel !== null && !in_array($gradeLevel, ['11','12'], true)) {
    http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid grade_level (allowed: '11','12')"]); return;
  }
  if (!$subjType || !in_arrayi($subjType, $ALLOWED_TYPES)) {
    http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid or missing type (allowed: ".implode(', ', $ALLOWED_TYPES).")"]); return;
  }

  // Escape
  $codeEsc  = esc($conn, $code);
  $nameEsc  = esc($conn, $name);
  $descEsc  = esc($conn, $description);
  $glEsc    = $gradeLevel !== null ? "'".esc($conn, $gradeLevel)."'" : "NULL";
  $strandEsc= $strand !== null ? "'".esc($conn, $strand)."'" : "NULL";
  $typeEsc  = esc($conn, $subjType);

  $sql = "INSERT INTO subjects
            (subj_code, subj_name, subj_description, grade_level, strand, subj_type, is_active)
          VALUES
            ('{$codeEsc}', '{$nameEsc}', '{$descEsc}', {$glEsc}, {$strandEsc}, '{$typeEsc}', {$isActive})";

  if ($conn->query($sql)) {
    http_response_code(201);
    echo json_encode([
      "success" => true,
      "status"  => "success",
      "message" => "Subject added successfully.",
      "id"      => (int)$conn->insert_id
    ]);
  } else {
    http_response_code(500);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Insert failed: ".$conn->error]);
  }
}

function updateSubject($conn, $id, $data, $ALLOWED_TYPES) {
  $id = (int)$id;

  // Ensure exists
  $exists = $conn->query("SELECT subj_id FROM subjects WHERE subj_id = {$id} LIMIT 1");
  if (!$exists || $exists->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Subject not found"]);
    return;
  }

  // Accept both naming styles; allow partial updates
  $code        = read_field($data, 'subj_code', 'code', null);
  $name        = read_field($data, 'subj_name', 'name', null);
  $description = read_field($data, 'subj_description', 'description', null);
  $gradeLevel  = read_field($data, 'grade_level', 'gradeLevel', null);
  $strand      = read_field($data, 'strand', 'strand', null);
  $subjType    = read_field_multi($data, ['subj_type','type','subjectType'], null);
  $isActive    = read_field($data, 'is_active', 'isActive', null);

  $sets = [];

  if ($code !== null)        $sets[] = "subj_code = '".esc($conn, $code)."'";
  if ($name !== null)        $sets[] = "subj_name = '".esc($conn, $name)."'";
  if ($description !== null) $sets[] = "subj_description = '".esc($conn, $description)."'";

  if ($gradeLevel !== null) {
    if (!in_array($gradeLevel, ['11','12'], true)) {
      http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid grade_level (allowed: '11','12')"]); return;
    }
    $sets[] = "grade_level = '".esc($conn, $gradeLevel)."'";
  }

  if ($strand !== null) {
    // allow empty string to clear
    $sets[] = "strand = ".($strand === '' ? "NULL" : "'".esc($conn, $strand)."'");
  }

  if ($subjType !== null) {
    if (is_string($subjType)) $subjType = strtolower($subjType);
    if (!in_arrayi($subjType, $ALLOWED_TYPES)) {
      http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid type (allowed: ".implode(', ', $ALLOWED_TYPES).")"]); return;
    }
    $sets[] = "subj_type = '".esc($conn, $subjType)."'";
  }

  if ($isActive !== null) {
    $sets[] = "is_active = ".((int)$isActive);
  }

  if (!$sets) {
    echo json_encode(["success"=>false,"status"=>"error","message"=>"No fields to update"]);
    return;
  }

  $sql = "UPDATE subjects SET ".implode(", ", $sets)." WHERE subj_id = {$id}";

  if ($conn->query($sql)) {
    echo json_encode(["success"=>true,"status"=>"success","message"=>"Subject updated successfully"]);
  } else {
    http_response_code(500);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Update failed: ".$conn->error]);
  }
}

function deleteSubject($conn, $id) {
  $id = (int)$id;

  // Ensure exists
  $exists = $conn->query("SELECT subj_id FROM subjects WHERE subj_id = {$id} LIMIT 1");
  if (!$exists || $exists->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Subject not found"]);
    return;
  }

  $sql = "DELETE FROM subjects WHERE subj_id = {$id}";
  if ($conn->query($sql)) {
    echo json_encode(["success" => true, "message" => "Subject deleted successfully"]);
  } else {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Delete failed: " . $conn->error]);
  }
}
