<?php
// subjects.php
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

// ---- helpers ---------------------------------------------------------------
function in_arrayi($needle, $haystack) {
  return in_array(strtolower($needle), array_map('strtolower', $haystack), true);
}
function read_field($data, $snake, $camel, $default = null) {
  return $data[$snake] ?? $data[$camel] ?? $default;
}

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
    createSubject($conn, is_array($data) ? $data : []);
    break;

  case 'PUT':
    $data = json_decode(file_get_contents('php://input'), true);
    if (isset($_GET['id'])) {
      updateSubject($conn, (int)$_GET['id'], is_array($data) ? $data : []);
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
        'grade_level'      => $row['grade_level'],           // '11' | '12'
        'strand'           => $row['strand'],
        'subj_type'        => $row['subj_type'],              // enum
        'is_active'        => isset($row['is_active']) ? (int)$row['is_active'] : 1,
        'schedule_count'   => (int)$row['schedule_count'],
      ];
    }
    echo json_encode([
      "success" => true,
      "status" => "success",
      "data" => $subjects
    ]);
  } else {
    http_response_code(500);
    echo json_encode([
      "success" => false,
      "status" => "error",
      "message" => "Failed to fetch subjects"
    ]);
  }
}

function getSubject($conn, $id) {
  $stmt = $conn->prepare(
    "SELECT s.*,
            (SELECT COUNT(*) FROM schedules WHERE subj_id = s.subj_id) AS schedule_count
     FROM subjects s
     WHERE s.subj_id = ?"
  );
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $result = $stmt->get_result();

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

    $schedules_sql = "SELECT s.*, p.prof_name AS professor_name, p.subj_count AS professor_subject_count,
                             sec.section_name, sec.grade_level, sec.strand,
                             r.room_number, r.room_type, r.room_capacity
                      FROM schedules s
                        JOIN professors p ON s.prof_id = p.prof_id
                        JOIN sections sec ON s.section_id = sec.section_id
                        LEFT JOIN rooms r ON s.room_id = r.room_id
                      WHERE s.subj_id = ?";
    $schedules_stmt = $conn->prepare($schedules_sql);
    $schedules_stmt->bind_param("i", $id);
    $schedules_stmt->execute();
    $schedules_result = $schedules_stmt->get_result();

    $schedules = [];
    while ($schedule_row = $schedules_result->fetch_assoc()) {
      $days = json_decode($schedule_row['days'], true) ?: [];
      $schedules[] = [
        'schedule_id' => (int)$schedule_row['schedule_id'],
        'professor'   => [
          'id'             => (int)$schedule_row['prof_id'],
          'name'           => $schedule_row['professor_name'],
          'subject_count'  => (int)$schedule_row['professor_subject_count']
        ],
        'section'     => [
          'id'          => (int)$schedule_row['section_id'],
          'name'        => $schedule_row['section_name'],
          'grade_level' => $schedule_row['grade_level'],
          'strand'      => $schedule_row['strand']
        ],
        'room' => $schedule_row['room_id'] ? [
          'id'       => (int)$schedule_row['room_id'],
          'number'   => (int)$schedule_row['room_number'],
          'type'     => $schedule_row['room_type'],
          'capacity' => (int)$schedule_row['room_capacity']
        ] : null,
        'schedule_type' => $schedule_row['schedule_type'],
        'start_time'    => $schedule_row['start_time'],
        'end_time'      => $schedule_row['end_time'],
        'days'          => $days
      ];
    }
    $subject['schedules'] = $schedules;

    echo json_encode(["success" => true, "data" => $subject]);
    $schedules_stmt->close();
  } else {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Subject not found"]);
  }
  $stmt->close();
}

function createSubject($conn, $data) {
  // Accept both naming styles
  $code        = read_field($data, 'subj_code', 'code');
  $name        = read_field($data, 'subj_name', 'name');
  $description = read_field($data, 'subj_description', 'description', '');
  $gradeLevel  = read_field($data, 'grade_level', 'gradeLevel');  // '11' | '12'
  $strand      = read_field($data, 'strand', 'strand', null);
  $subjType    = read_field($data, 'subj_type', 'subjectType');   // enum
  $isActive    = read_field($data, 'is_active', 'isActive', 1);

  // Normalize type
  if (is_string($subjType)) $subjType = strtolower($subjType);

  // Basic validation
  if (!$code) { http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Missing required field: code"]); return; }
  if (!$name) { http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Missing required field: name"]); return; }
  if (!$gradeLevel || !in_array($gradeLevel, ['11','12'], true)) {
    http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid or missing grade_level (allowed: '11','12')"]); return;
  }
  if (!$subjType || !in_arrayi($subjType, ['core','contextualized','specialized'])) {
    http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid or missing subj_type (allowed: core, contextualized, specialized)"]); return;
  }

  $sql = "INSERT INTO subjects
          (subj_code, subj_name, subj_description, grade_level, strand, subj_type, is_active)
          VALUES (?, ?, ?, ?, ?, ?, ?)";
  $stmt = $conn->prepare($sql);
  if (!$stmt) { http_response_code(500); echo json_encode(["success"=>false,"status"=>"error","message"=>"Prepare failed: ".$conn->error]); return; }

  $stmt->bind_param("ssssssi", $code, $name, $description, $gradeLevel, $strand, $subjType, $isActive);
  if ($stmt->execute()) {
    http_response_code(201);
    echo json_encode([
      "success" => true,
      "status"  => "success",
      "message" => "Subject added successfully.",
      "id"      => $stmt->insert_id
    ]);
  } else {
    http_response_code(500);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Execute failed: ".$stmt->error]);
  }
  $stmt->close();
}

function updateSubject($conn, $id, $data) {
  // Ensure exists
  $chk = $conn->prepare("SELECT subj_id FROM subjects WHERE subj_id = ?");
  $chk->bind_param("i", $id);
  $chk->execute();
  $res = $chk->get_result();
  if (!$res || $res->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Subject not found"]);
    $chk->close();
    return;
  }
  $chk->close();

  // Accept both naming styles; allow partial updates
  $code        = read_field($data, 'subj_code', 'code', null);
  $name        = read_field($data, 'subj_name', 'name', null);
  $description = read_field($data, 'subj_description', 'description', null);
  $gradeLevel  = read_field($data, 'grade_level', 'gradeLevel', null);
  $strand      = read_field($data, 'strand', 'strand', null);
  $subjType    = read_field($data, 'subj_type', 'subjectType', null);
  $isActive    = read_field($data, 'is_active', 'isActive', null);

  if (is_string($subjType)) $subjType = strtolower($subjType);

  $sets  = [];
  $types = "";
  $vals  = [];

  if ($code !== null)        { $sets[] = "subj_code = ?";        $types .= "s"; $vals[] = $code; }
  if ($name !== null)        { $sets[] = "subj_name = ?";        $types .= "s"; $vals[] = $name; }
  if ($description !== null) { $sets[] = "subj_description = ?"; $types .= "s"; $vals[] = $description; }
  if ($gradeLevel !== null)  {
    if (!in_array($gradeLevel, ['11','12'], true)) {
      http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid grade_level (allowed: '11','12')"]); return;
    }
    $sets[] = "grade_level = ?"; $types .= "s"; $vals[] = $gradeLevel;
  }
  if ($strand !== null)      { $sets[] = "strand = ?";           $types .= "s"; $vals[] = $strand; }
  if ($subjType !== null)    {
    if (!in_arrayi($subjType, ['core','contextualized','specialized'])) {
      http_response_code(400); echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid subj_type (allowed: core, contextualized, specialized)"]); return;
    }
    $sets[] = "subj_type = ?"; $types .= "s"; $vals[] = $subjType;
  }
  if ($isActive !== null)    { $sets[] = "is_active = ?";        $types .= "i"; $vals[] = (int)$isActive; }

  if (!$sets) {
    echo json_encode(["success"=>false,"status"=>"error","message"=>"No fields to update"]);
    return;
  }

  $sql = "UPDATE subjects SET ".implode(", ", $sets)." WHERE subj_id = ?";
  $types .= "i";
  $vals[] = $id;

  $stmt = $conn->prepare($sql);
  if (!$stmt) { http_response_code(500); echo json_encode(["success"=>false,"status"=>"error","message"=>"Prepare failed: ".$conn->error]); return; }
  $stmt->bind_param($types, ...$vals);

  if ($stmt->execute()) {
    echo json_encode(["success"=>true,"status"=>"success","message"=>"Subject updated successfully"]);
  } else {
    http_response_code(500);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Execute failed: ".$stmt->error]);
  }
  $stmt->close();
}

function deleteSubject($conn, $id) {
  // Ensure exists
  $checkStmt = $conn->prepare("SELECT subj_id FROM subjects WHERE subj_id = ?");
  $checkStmt->bind_param("i", $id);
  $checkStmt->execute();
  $checkResult = $checkStmt->get_result();

  if ($checkResult->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Subject not found"]);
    $checkStmt->close();
    return;
  }
  $checkStmt->close();

  $stmt = $conn->prepare("DELETE FROM subjects WHERE subj_id = ?");
  if (!$stmt) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Failed to prepare SQL statement: " . $conn->error]);
    return;
  }
  $stmt->bind_param("i", $id);

  if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Subject deleted successfully"]);
  } else {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "ERROR: " . $stmt->error]);
  }
  $stmt->close();
}
