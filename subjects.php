<?php
include 'cors_helper.php';

include 'connect.php';
include 'activity_logger.php';

require_once __DIR__ . '/system_settings_helper.php';
require_once __DIR__ . '/firebase_config.php';
require_once __DIR__ . '/firebase_sync_lib.php';

$conn = new mysqli($host, $user, $password, $database);
header('Content-Type: application/json; charset=utf-8');

if ($conn->connect_error) {
    @log_activity($conn, 'subjects', 'error', 'DB connection failed: '.$conn->connect_error, null, null);
    http_response_code(500);
    echo json_encode(["success" => false, "status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}
$conn->set_charset('utf8mb4');

function firebaseSync(mysqli $conn): FirebaseSync {
    global $firebaseConfig;
    return new FirebaseSync($firebaseConfig, $conn);
}

function esc($conn, $val) { return mysqli_real_escape_string($conn, (string)$val); }
function in_arrayi($needle, $haystack) { return in_array(strtolower((string)$needle), array_map('strtolower', $haystack), true); }
function read_field($data, $snake, $camel, $default = null) { return $data[$snake] ?? $data[$camel] ?? $default; }
function read_field_multi($data, $keys, $default = null) { foreach ($keys as $k) { if (array_key_exists($k, $data)) return $data[$k]; } return $default; }

$ALLOWED_TYPES = ['Core','Specialized','Contextualized'];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['setting_key'])) {
  $k = trim((string)$_GET['setting_key']);
  $val = $k !== '' ? ss_get_setting($conn, $k) : null;
  echo json_encode(["success"=>true,"setting_key"=>$k,"setting_value"=>$val]);
  $conn->close();
  exit();
}

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
      @log_activity($conn, 'subjects', 'update', 'FAILED update: missing ID', null, null);
      http_response_code(400);
      echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Subject ID is required']);
    }
    break;
  case 'DELETE':
    if (isset($_GET['id'])) {
      deleteSubject($conn, (int)$_GET['id']);
    } else {
      @log_activity($conn, 'subjects', 'delete', 'FAILED delete: missing ID', null, null);
      http_response_code(400);
      echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Subject ID is required']);
    }
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Method not allowed']);
    break;
}

function current_sy_sql($conn) {
  $sy = ss_get_current_school_year($conn);
  $sy = $sy !== null ? trim($sy) : '';
  return $sy === '' ? null : esc($conn, $sy);
}

function getAllSubject($conn) {
  $strandParam   = isset($_GET['strand']) ? trim($_GET['strand']) : null;
  $gradeLevel    = isset($_GET['grade_level']) ? trim($_GET['grade_level']) : (isset($_GET['grade']) ? trim($_GET['grade']) : null);
  $typeParam     = isset($_GET['type']) ? trim($_GET['type']) : (isset($_GET['subj_type']) ? trim($_GET['subj_type']) : null);
  $isActiveParam = isset($_GET['is_active']) ? $_GET['is_active'] : null;
  $q             = isset($_GET['q']) ? trim($_GET['q']) : null;

  $where = [];
  $syEsc = current_sy_sql($conn);
  if ($syEsc !== null) $where[] = "s.school_year = '{$syEsc}'";

  if ($strandParam !== null && $strandParam !== '') {
    $parts = array_filter(array_map('trim', explode(',', $strandParam)));
    if ($parts) {
      $in = [];
      foreach ($parts as $p) { $in[] = "LOWER('".esc($conn, $p)."')"; }
      $where[] = "LOWER(s.strand) IN (".implode(',', $in).")";
    }
  }
  if ($gradeLevel !== null && $gradeLevel !== '') {
    if (in_array($gradeLevel, ['11','12'], true)) {
      $where[] = "s.grade_level = '".esc($conn, $gradeLevel)."'";
    }
  }
  if ($typeParam !== null && $typeParam !== '') {
    $where[] = "LOWER(s.subj_type) = LOWER('".esc($conn, $typeParam)."')";
  }
  if ($isActiveParam !== null && $isActiveParam !== '') {
    $where[] = "s.is_active = ".((int)$isActiveParam);
  }
  if ($q !== null && $q !== '') {
    $qEsc = esc($conn, $q);
    $where[] = "(s.subj_code LIKE '%{$qEsc}%' OR s.subj_name LIKE '%{$qEsc}%' OR s.subj_description LIKE '%{$qEsc}%')";
  }

  $sql = "SELECT s.*,
                 (SELECT COUNT(*) FROM schedules WHERE subj_id = s.subj_id) AS schedule_count
          FROM subjects s";
  if (!empty($where)) { $sql .= " WHERE ".implode(" AND ", $where); }
  $sql .= " ORDER BY s.strand IS NULL, s.strand, s.grade_level, s.subj_code";

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
        'school_year'      => $row['school_year'] ?? null
      ];
    }
    @log_activity($conn, 'subjects', 'list', 'Listed subjects (count: '.count($subjects).')'.($syEsc!==null?' | SY: '.$syEsc:''), null, null);
    $currentSY = ss_get_current_school_year($conn);
    echo json_encode(["success" => true, "status" => "success", "data" => $subjects, "current_school_year" => $currentSY]);
  } else {
    @log_activity($conn, 'subjects', 'error', 'Failed to fetch subjects: '.$conn->error, null, null);
    http_response_code(500);
    echo json_encode(["success" => false, "status" => "error", "message" => "Failed to fetch subjects"]);
  }
}

function getSubject($conn, $id) {
  $id = (int)$id;
  $syEsc = current_sy_sql($conn);
  $syClause = $syEsc !== null ? " AND s.school_year = '{$syEsc}'" : "";
  $sql = "SELECT s.*,
                 (SELECT COUNT(*) FROM schedules WHERE subj_id = s.subj_id) AS schedule_count
          FROM subjects s
          WHERE s.subj_id = {$id}{$syClause}
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
      'schedule_count'  => (int)$row['schedule_count'],
      'school_year'     => $row['school_year'] ?? null
    ];

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

    @log_activity($conn, 'subjects', 'read', 'Viewed subject: '.$row['subj_code'].' — '.$row['subj_name'].' (ID '.$row['subj_id'].')'.($syEsc!==null?' | SY: '.$syEsc:''), (int)$row['subj_id'], null);
    $currentSY = ss_get_current_school_year($conn);
    echo json_encode(["success" => true, "data" => $subject, "current_school_year" => $currentSY]);
  } else {
    @log_activity($conn, 'subjects', 'read', 'Subject not found (ID '.$id.')'.($syEsc!==null?' | SY: '.$syEsc:''), $id, null);
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Subject not found"]);
  }
}

function createSubject($conn, $data, $ALLOWED_TYPES) {
  $code        = read_field($data, 'subj_code', 'code');
  $name        = read_field($data, 'subj_name', 'name');
  $description = read_field($data, 'subj_description', 'description', '');
  $gradeLevel  = read_field($data, 'grade_level', 'gradeLevel', null);
  $strand      = read_field($data, 'strand', 'strand', null);
  $subjType    = read_field_multi($data, ['subj_type','type','subjectType'], null);
  $isActive    = (int)read_field($data, 'is_active', 'isActive', 1);

  if (is_string($subjType)) $subjType = strtolower($subjType);
  if (!$code) { @log_activity($conn, 'subjects', 'create', 'FAILED create: missing code', null, null); http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Missing required field: code"]); return; }
  if (!$name) { @log_activity($conn, 'subjects', 'create', 'FAILED create: missing name', null, null); http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Missing required field: name"]); return; }
  if ($gradeLevel !== null && !in_array($gradeLevel, ['11','12'], true)) {
    @log_activity($conn, 'subjects', 'create', 'FAILED create: invalid grade_level', null, null);
    http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid grade_level (allowed: '11','12')"]); return;
  }
  if (!$subjType || !in_arrayi($subjType, $ALLOWED_TYPES)) {
    @log_activity($conn, 'subjects', 'create', 'FAILED create: invalid type', null, null);
    http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid or missing type (allowed: ".implode(', ', $ALLOWED_TYPES).")"]); return;
  }

  $codeEsc   = esc($conn, $code);
  $nameEsc   = esc($conn, $name);
  $descEsc   = esc($conn, $description);
  $glEsc     = $gradeLevel !== null ? "'".esc($conn, $gradeLevel)."'" : "NULL";
  $strandEsc = $strand !== null ? "'".esc($conn, $strand)."'" : "NULL";
  $typeEsc   = esc($conn, $subjType);

  $syEsc = current_sy_sql($conn);
  $sySQL = $syEsc !== null ? "'{$syEsc}'" : "NULL";

  $sql = "INSERT INTO subjects
            (subj_code, subj_name, subj_description, grade_level, strand, subj_type, is_active, school_year)
          VALUES
            ('{$codeEsc}', '{$nameEsc}', '{$descEsc}', {$glEsc}, {$strandEsc}, '{$typeEsc}', {$isActive}, {$sySQL})";

  if ($conn->query($sql)) {
    $newId = (int)$conn->insert_id;
    $syncResult = firebaseSync($conn)->syncSingleSubject($newId);
    @log_activity($conn, 'subjects', 'create', "Created subject: {$code} — {$name} (ID {$newId})".($syEsc!==null?' | SY: '.$syEsc:''), $newId, null);

    http_response_code(201);
    $currentSY = ss_get_current_school_year($conn);
    echo json_encode([
      "success" => true,
      "status"  => "success",
      "message" => "Subject added successfully.",
      "id"      => $newId,
      "current_school_year" => $currentSY,
      "firebase_sync" => $syncResult
    ]);
  } else {
    @log_activity($conn, 'subjects', 'create', "FAILED create {$code} — {$name}: ".$conn->error, null, null);
    http_response_code(500);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Insert failed: ".$conn->error]);
  }
}

function updateSubject($conn, $id, $data, $ALLOWED_TYPES) {
  $id = (int)$id;
  $exists = $conn->query("SELECT subj_id, subj_code, subj_name FROM subjects WHERE subj_id = {$id} LIMIT 1");
  if (!$exists || $exists->num_rows === 0) {
    @log_activity($conn, 'subjects', 'update', "FAILED update: subject not found (ID {$id})", $id, null);
    http_response_code(404);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Subject not found"]);
    return;
  }
  $existing = $exists->fetch_assoc();
  $existingLabel = $existing ? ($existing['subj_code'].' — '.$existing['subj_name']) : ('ID '.$id);

  $code        = read_field($data, 'subj_code', 'code', null);
  $name        = read_field($data, 'subj_name', 'name', null);
  $description = read_field($data, 'subj_description', 'description', null);
  $gradeLevel  = read_field($data, 'grade_level', 'gradeLevel', null);
  $strand      = read_field($data, 'strand', 'strand', null);
  $subjType    = read_field_multi($data, ['subj_type','type','subjectType'], null);
  $isActive    = read_field($data, 'is_active', 'isActive', null);
  $schoolYear  = array_key_exists('school_year', $data) ? (string)$data['school_year'] : null;

  $sets = [];
  if ($code !== null)        $sets[] = "subj_code = '".esc($conn, $code)."'";
  if ($name !== null)        $sets[] = "subj_name = '".esc($conn, $name)."'";
  if ($description !== null) $sets[] = "subj_description = '".esc($conn, $description)."'";
  if ($gradeLevel !== null) {
    if (!in_array($gradeLevel, ['11','12'], true)) {
      @log_activity($conn, 'subjects', 'update', "FAILED update {$existingLabel}: invalid grade_level", $id, null);
      http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid grade_level (allowed: '11','12')"]); return;
    }
    $sets[] = "grade_level = '".esc($conn, $gradeLevel)."'";
  }
  if ($strand !== null)      $sets[] = "strand = ".($strand === '' ? "NULL" : "'".esc($conn, $strand)."'");
  if ($subjType !== null) {
    if (is_string($subjType)) $subjType = strtolower($subjType);
    if (!in_arrayi($subjType, $ALLOWED_TYPES)) {
      @log_activity($conn, 'subjects', 'update', "FAILED update {$existingLabel}: invalid type", $id, null);
      http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid type (allowed: ".implode(', ', $ALLOWED_TYPES).")"]); return;
    }
    $sets[] = "subj_type = '".esc($conn, $subjType)."'";
  }
  if ($isActive !== null)    $sets[] = "is_active = ".((int)$isActive);
  if ($schoolYear !== null)  $sets[] = trim($schoolYear) === '' ? "school_year = NULL" : "school_year = '".esc($conn, $schoolYear)."'";

  if (!$sets) {
    @log_activity($conn, 'subjects', 'update', "No fields to update for {$existingLabel}", $id, null);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"No fields to update"]);
    return;
  }

  $sql = "UPDATE subjects SET ".implode(", ", $sets)." WHERE subj_id = {$id}";
  if ($conn->query($sql)) {
    $syncResult = firebaseSync($conn)->syncSingleSubject($id);
    @log_activity($conn, 'subjects', 'update', "Updated subject: {$existingLabel}", $id, null);

    $currentSY = ss_get_current_school_year($conn);
    echo json_encode([
      "success"=>true,
      "status"=>"success",
      "message"=>"Subject updated successfully",
      "current_school_year"=>$currentSY,
      "firebase_sync"=>$syncResult
    ]);
  } else {
    @log_activity($conn, 'subjects', 'update', "FAILED update {$existingLabel}: ".$conn->error, $id, null);
    http_response_code(500);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Update failed: ".$conn->error]);
  }
}

function deleteSubject($conn, $id) {
  $id = (int)$id;
  $exists = $conn->query("SELECT subj_id, subj_code, subj_name FROM subjects WHERE subj_id = {$id} LIMIT 1");
  if (!$exists || $exists->num_rows === 0) {
    @log_activity($conn, 'subjects', 'delete', "FAILED delete: subject not found (ID {$id})", $id, null);
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Subject not found"]);
    return;
  }
  $row = $exists->fetch_assoc();
  $label = $row['subj_code'].' — '.$row['subj_name'];

  $sql = "DELETE FROM subjects WHERE subj_id = {$id}";
  if ($conn->query($sql)) {
    $syncResult = firebaseSync($conn)->deleteSubjectInFirebase($id);
    @log_activity($conn, 'subjects', 'delete', "Deleted subject: {$label} (ID {$id})", $id, null);

    $currentSY = ss_get_current_school_year($conn);
    echo json_encode([
      "success" => true,
      "message" => "Subject deleted successfully",
      "current_school_year"=>$currentSY,
      "firebase_sync"=>$syncResult
    ]);
  } else {
    @log_activity($conn, 'subjects', 'delete', "FAILED delete {$label} (ID {$id}): ".$conn->error, $id, null);
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Delete failed: " . $conn->error]);
  }
}

