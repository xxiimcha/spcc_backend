<?php
// sections.php - API endpoint for section management (MySQLi, NO prepared stmts)

declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

include 'connect.php';
include 'activity_logger.php';

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
  @log_activity($conn, 'sections', 'error', 'DB connection failed: '.$conn->connect_error, null, null);
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => "Database connection failed: ".$conn->connect_error]);
  exit();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/system_settings_helper.php';

function q(mysqli $c, $v): string { return "'".$c->real_escape_string((string)$v)."'"; }
function ok($payload, int $code=200): void { http_response_code($code); echo json_encode($payload); }
function fail(string $msg, int $code=400, array $extra=[]): void {
  http_response_code($code); echo json_encode(["success"=>false,"status"=>"error","message"=>$msg]+$extra);
}
function ss(string $key, $default=null) { global $conn; $v = ss_get_setting($conn,$key); return $v!==null ? $v : $default; }
function ss_bool(string $key, bool $default=false): bool {
  $v = ss($key, null); if ($v===null) return $default;
  if (is_bool($v)) return $v; $v = strtolower(trim((string)$v));
  return in_array($v,['1','true','yes','on','y'],true);
}
function parseRooms(?string $gc): array {
  if (!$gc) return [];
  $out=[]; foreach (explode(',', $gc) as $room) {
    [$id,$num,$type,$cap] = array_pad(explode(':',$room),4,null);
    $out[] = ['id'=>(int)$id,'number'=>$num!==null?(int)$num:null,'type'=>$type,'capacity'=>$cap!==null?(int)$cap:null];
  } return $out;
}
function parseSubjectIds(?string $raw): array {
  if ($raw===null || $raw==='') return [];
  $tmp = json_decode($raw,true); return is_array($tmp) ? array_values(array_map('intval',$tmp)) : [];
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
  case 'GET':
    if (isset($_GET['id'])) { getSection($conn, (int)$_GET['id']); }
    else { getAllSections($conn); }
    break;

  case 'POST':
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) { @log_activity($conn,'sections','create','FAILED create: invalid JSON',null,null); fail("Invalid JSON data", 400); break; }
    createSection($conn, $data);
    break;

  case 'PUT':
    if (!isset($_GET['id'])) { @log_activity($conn,'sections','update','FAILED update: missing ID',null,null); fail("Section ID is required", 400); break; }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) { @log_activity($conn,'sections','update','FAILED update ID '.$_GET['id'].': invalid JSON',(int)$_GET['id'],null); fail("Invalid JSON data", 400); break; }
    updateSection($conn, (int)$_GET['id'], $data);
    break;

  case 'DELETE':
    if (!isset($_GET['id'])) { @log_activity($conn,'sections','delete','FAILED delete: missing ID',null,null); fail("Section ID is required", 400); break; }
    deleteSection($conn, (int)$_GET['id']);
    break;

  default:
    fail("Method not allowed", 405);
    break;
}

function getAllSections(mysqli $conn): void {
  $requestedSY = isset($_GET['school_year']) ? trim((string)$_GET['school_year']) : '';
  $currentSY   = ss_get_current_school_year($conn);

  $where = '';
  if ($requestedSY !== '') {
    if (strtolower($requestedSY)==='current') { $val = $currentSY; }
    elseif (strtolower($requestedSY)==='all')  { $val = null; }
    else                                       { $val = $requestedSY; }
    if ($val !== null) $where = "WHERE s.school_year ".($val==='' ? "IS NULL" : "= ".q($conn,$val));
  }

  $sql = "SELECT s.*,
          (SELECT COUNT(*) FROM schedules WHERE section_id = s.section_id) AS schedule_count,
          GROUP_CONCAT(DISTINCT r.room_id, ':', r.room_number, ':', r.room_type, ':', r.room_capacity) AS rooms,
          MIN(r.room_id) AS primary_room_id
          FROM sections s
          LEFT JOIN section_room_assignments sra ON s.section_id = sra.section_id
          LEFT JOIN rooms r ON sra.room_id = r.room_id
          $where
          GROUP BY s.section_id
          ORDER BY s.grade_level, s.strand, s.section_name";

  $result = $conn->query($sql);
  $sections = [];
  while ($row = $result->fetch_assoc()) {
    $sections[] = [
      'section_id'         => (int)$row['section_id'],
      'section_name'       => $row['section_name'],
      'grade_level'        => $row['grade_level'],
      'strand'             => $row['strand'],
      'number_of_students' => isset($row['number_of_students']) ? (int)$row['number_of_students'] : 0,
      'schedule_count'     => (int)$row['schedule_count'],
      'primary_room_id'    => $row['primary_room_id'] ? (int)$row['primary_room_id'] : null,
      'rooms'              => parseRooms($row['rooms'] ?? null),
      'subject_ids_raw'    => $row['subject_ids'] ?? null,
      'subject_ids'        => parseSubjectIds($row['subject_ids'] ?? null),
      'school_year'        => $row['school_year'] ?? null,
    ];
  }

  @log_activity($conn, 'sections', 'read', 'Listed sections (count='.count($sections).', filter='.$requestedSY.')', null, null);
  ok(["success"=>true,"data"=>$sections,"current_school_year"=>$currentSY,"applied_school_year"=>$requestedSY===''?'(none)':$requestedSY]);
}

function getSection(mysqli $conn, int $id): void {
  $sql = "SELECT s.*,
          (SELECT COUNT(*) FROM schedules WHERE section_id = s.section_id) AS schedule_count,
          GROUP_CONCAT(DISTINCT r.room_id, ':', r.room_number, ':', r.room_type, ':', r.room_capacity) AS rooms,
          MIN(r.room_id) AS primary_room_id
          FROM sections s
          LEFT JOIN section_room_assignments sra ON s.section_id = sra.section_id
          LEFT JOIN rooms r ON sra.room_id = r.room_id
          WHERE s.section_id = $id
          GROUP BY s.section_id";
  $result = $conn->query($sql);

  if (!$result || $result->num_rows === 0) { @log_activity($conn,'sections','read','FAILED view: section not found (ID '.$id.')',$id,null); fail("Section not found", 404); return; }

  $row = $result->fetch_assoc();

  $section = [
    'section_id'         => (int)$row['section_id'],
    'section_name'       => $row['section_name'],
    'grade_level'        => $row['grade_level'],
    'strand'             => $row['strand'],
    'number_of_students' => isset($row['number_of_students']) ? (int)$row['number_of_students'] : 0,
    'schedule_count'     => (int)$row['schedule_count'],
    'primary_room_id'    => $row['primary_room_id'] ? (int)$row['primary_room_id'] : null,
    'rooms'              => parseRooms($row['rooms'] ?? null),
    'subject_ids_raw'    => $row['subject_ids'] ?? null,
    'subject_ids'        => parseSubjectIds($row['subject_ids'] ?? null),
    'school_year'        => $row['school_year'] ?? null,
  ];

  $schedules_sql = "SELECT s.*, subj.subj_code, subj.subj_name,
                    p.prof_name AS professor_name, p.subj_count AS professor_subject_count,
                    r.room_number, r.room_type, r.room_capacity
                    FROM schedules s
                    JOIN subjects subj ON s.subj_id = subj.subj_id
                    JOIN professors p ON s.prof_id = p.prof_id
                    LEFT JOIN rooms r ON s.room_id = r.room_id
                    WHERE s.section_id = $id";
  $schedules_result = $conn->query($schedules_sql);

  $schedules = [];
  while ($sr = $schedules_result->fetch_assoc()) {
    $days = [];
    $days_sql = "SELECT d.day_name
                 FROM schedule_days sd
                 JOIN days d ON sd.day_id = d.day_id
                 WHERE sd.schedule_id = ".(int)$sr['schedule_id'];
    $days_result = $conn->query($days_sql);
    while ($dr = $days_result->fetch_assoc()) { $days[] = $dr['day_name']; }

    $schedules[] = [
      'schedule_id'  => (int)$sr['schedule_id'],
      'subject'      => ['id'=>(int)$sr['subj_id'],'code'=>$sr['subj_code'],'name'=>$sr['subj_name']],
      'professor'    => ['id'=>(int)$sr['prof_id'],'name'=>$sr['professor_name'],'subject_count'=>(int)$sr['professor_subject_count']],
      'room'         => $sr['room_id'] ? ['id'=>(int)$sr['room_id'],'number'=>(int)$sr['room_number'],'type'=>$sr['room_type'],'capacity'=>(int)$sr['room_capacity']] : null,
      'schedule_type'=> $sr['schedule_type'],
      'start_time'   => $sr['start_time'],
      'end_time'     => $sr['end_time'],
      'days'         => $days,
    ];
  }

  $section['schedules'] = $schedules;
  @log_activity($conn,'sections','read','Viewed section ID: '.$id,$id,null);
  ok(["success"=>true,"data"=>$section]);
}

function createSection(mysqli $conn, array $data): void {
  $conn->begin_transaction();
  try {
    $name = (string)($data['section_name'] ?? '');
    if ($name === '') throw new Exception("section_name is required");

    $gradeLevel       = isset($data['grade_level']) ? (string)$data['grade_level'] : null;
    $numberOfStudents = isset($data['number_of_students']) ? (int)$data['number_of_students'] : null;
    $strand           = isset($data['strand']) ? (string)$data['strand'] : null;
    $roomIds          = isset($data['room_ids']) ? $data['room_ids'] : [];
    $subjectIds       = isset($data['subject_ids']) ? $data['subject_ids'] : []; // <-- NEW

    if (isset($data['number_of_students']) && (!is_numeric($data['number_of_students']) || $data['number_of_students'] < 0)) {
      throw new Exception("Number of students must be a non-negative integer");
    }

    if (isset($data['room_ids'])) {
      if (!is_array($data['room_ids'])) throw new Exception("room_ids must be an array");
      $roomIds = array_values(array_unique(array_map('intval', $data['room_ids'])));
      if (count($roomIds) > 0) {
        $in = implode(',', $roomIds);
        $rc = $conn->query("SELECT room_id FROM rooms WHERE room_id IN ($in)")->num_rows;
        if ($rc !== count($roomIds)) throw new Exception("One or more room IDs do not exist");
      }
    }

    $subjectIds = array_values(array_unique(array_filter(array_map('intval', (array)$subjectIds), fn($v) => $v > 0)));
    $subjectIdsJson = json_encode($subjectIds, JSON_UNESCAPED_UNICODE);

    $schoolYear    = ss_get_current_school_year($conn);
    $schoolYearSQL = $schoolYear !== null ? q($conn, $schoolYear) : "NULL";

    $sql = "INSERT INTO sections (section_name, grade_level, number_of_students, strand, subject_ids, school_year)
            VALUES (
              " . q($conn, $name) . ",
              " . ($gradeLevel === null ? "NULL" : q($conn, $gradeLevel)) . ",
              " . ($numberOfStudents === null ? "NULL" : (int)$numberOfStudents) . ",
              " . ($strand === null ? "NULL" : q($conn, $strand)) . ",
              " . q($conn, $subjectIdsJson) . ",
              " . $schoolYearSQL . "
            )";

    $conn->query($sql);
    $sectionId = (int)$conn->insert_id;

    if (!empty($roomIds)) {
      foreach ($roomIds as $rid) {
        $conn->query("INSERT INTO section_room_assignments (section_id, room_id) VALUES ($sectionId, " . (int)$rid . ")");
      }
    }

    $row = $conn->query("SELECT * FROM sections WHERE section_id = $sectionId")->fetch_assoc();

    $section = [
      'section_id'         => (int)$row['section_id'],
      'section_name'       => $row['section_name'],
      'grade_level'        => $row['grade_level'],
      'strand'             => $row['strand'],
      'number_of_students' => isset($row['number_of_students']) ? (int)$row['number_of_students'] : 0,
      'subject_ids'        => parseSubjectIds($row['subject_ids'] ?? null),
      'school_year'        => $row['school_year'] ?? null,
    ];

    $conn->commit();
    @log_activity($conn, 'sections', 'create', 'Created section: ' . $name . ' (ID: ' . $sectionId . ') | SY: ' . ($schoolYear ?? 'NULL'), $sectionId, null);
    ok([
      "status" => "success",
      "message" => "Section added successfully",
      "section_id" => $sectionId, 
      "section" => $section
    ], 201);
  } catch (Throwable $e) {
    $conn->rollback();
    @log_activity($conn, 'sections', 'create', 'FAILED create: ' . $e->getMessage(), null, null);
    fail("Error: " . $e->getMessage(), 500);
  }
}

function updateSection(mysqli $conn, int $id, array $data): void {
  $conn->begin_transaction();
  try {
    $curRes = $conn->query("SELECT * FROM sections WHERE section_id = $id");
    if ($curRes->num_rows === 0) { throw new Exception("Section not found"); }

    $sets = [];

    if (array_key_exists('section_name', $data)) {
      $name = trim((string)$data['section_name']);
      if ($name === '') throw new Exception("section_name cannot be empty");
      if (strlen($name) > 50) throw new Exception("Section name must not exceed 50 characters");
      $sets[] = "section_name = ".q($conn,$name);
    }

    if (array_key_exists('grade_level', $data)) {
      $gl = (string)$data['grade_level'];
      if ($gl !== '' && !in_array($gl, ['11','12'], true)) throw new Exception("Grade level must be either '11' or '12'");
      $sets[] = "grade_level = ".($gl===''? "NULL" : q($conn,$gl));
    }

    if (array_key_exists('strand', $data)) {
      $strand = (string)$data['strand'];
      if ($strand !== '' && strlen($strand) > 10) throw new Exception("Strand must not exceed 10 characters");
      $sets[] = "strand = ".($strand===''? "NULL" : q($conn,$strand));
    }

    if (array_key_exists('number_of_students', $data)) {
      if ($data['number_of_students'] === null || $data['number_of_students'] === '') {
        $sets[] = "number_of_students = NULL";
      } else {
        $nos = (int)$data['number_of_students'];
        if ($nos < 0) throw new Exception("Number of students must be a non-negative integer");
        $sets[] = "number_of_students = $nos";
      }
    }

    if (array_key_exists('subj_ids', $data)) {
      if (is_array($data['subj_ids'])) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $data['subj_ids']), fn($v)=>$v>0)));
        $json = json_encode($ids, JSON_UNESCAPED_UNICODE);
      } elseif (is_string($data['subj_ids']) && trim($data['subj_ids']) !== '') {
        $json = trim($data['subj_ids']);
      } else {
        $json = '[]';
      }
      $sets[] = "subject_ids = ".q($conn,$json);
    }

    if (array_key_exists('school_year', $data)) {
      $sy = trim((string)$data['school_year']);
      $sets[] = "school_year = ".($sy==='' ? "NULL" : q($conn,$sy));
    }

    $updateRooms = false;
    $roomIds = [];
    if (array_key_exists('room_ids', $data)) {
      if (!is_array($data['room_ids'])) throw new Exception("room_ids must be an array");
      $roomIds = array_values(array_unique(array_map('intval',$data['room_ids'])));
      if (count($roomIds) > 0) {
        $in = implode(',', $roomIds);
        $rc = $conn->query("SELECT room_id FROM rooms WHERE room_id IN ($in)")->num_rows;
        if ($rc !== count($roomIds)) throw new Exception("One or more room IDs do not exist");
      }
      $updateRooms = true;
    }

    if (!empty($sets)) {
      $sql = "UPDATE sections SET ".implode(', ', $sets).", updated_at = NOW() WHERE section_id = $id";
      $conn->query($sql);
    }

    if ($updateRooms) {
      $conn->query("DELETE FROM section_room_assignments WHERE section_id = $id");
      foreach ($roomIds as $rid) {
        $conn->query("INSERT INTO section_room_assignments (section_id, room_id) VALUES ($id, ".(int)$rid.")");
      }
    }

    $row = $conn->query("SELECT * FROM sections WHERE section_id = $id")->fetch_assoc();
    $subject_ids_raw = $row['subject_ids'] ?? null;

    $out = [
      "section_id"         => (int)$row["section_id"],
      "section_name"       => $row["section_name"],
      "grade_level"        => $row["grade_level"],
      "strand"             => $row["strand"],
      "number_of_students" => isset($row["number_of_students"]) ? (int)$row["number_of_students"] : null,
      "subject_ids_raw"    => $subject_ids_raw,
      "subject_ids"        => parseSubjectIds($subject_ids_raw),
      "school_year"        => $row["school_year"] ?? null,
    ];

    $conn->commit();
    @log_activity($conn,'sections','update','Updated section ID: '.$id,$id,null);
    ok(["success"=>true, "status"=>"success", "message"=>"Section updated successfully", "section"=>$out]);
  } catch (Throwable $e) {
    $conn->rollback();
    @log_activity($conn,'sections','update','FAILED update ID '.$id.': '.$e->getMessage(),$id,null);
    fail("Error: ".$e->getMessage(), 500);
  }
}

function deleteSection(mysqli $conn, int $id): void {
  // 1) Ensure the section exists
  $exists = $conn->query("SELECT section_id FROM sections WHERE section_id = $id")->num_rows > 0;
  if (!$exists) {
    @log_activity($conn,'sections','delete','FAILED delete: section not found (ID '.$id.')',$id,null);
    http_response_code(404);
    echo json_encode(["success"=>false, "status"=>"error", "message"=>"Section not found"]);
    return;
  }

  // 2) Count schedules (just for reporting)
  $cRow = $conn->query("SELECT COUNT(*) AS c FROM schedules WHERE section_id = $id")->fetch_assoc();
  $schedCount = (int)($cRow['c'] ?? 0);

  $conn->begin_transaction();
  try {
    // 3) Delete schedule_days for schedules under this section
    //    (do this first to avoid orphan rows)
    $conn->query("
      DELETE sd
      FROM schedule_days sd
      INNER JOIN schedules s ON s.schedule_id = sd.schedule_id
      WHERE s.section_id = $id
    ");

    // 4) Delete schedules under this section
    $conn->query("DELETE FROM schedules WHERE section_id = $id");

    // 5) Remove room assignments
    $conn->query("DELETE FROM section_room_assignments WHERE section_id = $id");

    // 6) Finally delete the section
    $conn->query("DELETE FROM sections WHERE section_id = $id");

    $conn->commit();

    @log_activity(
      $conn,
      'sections',
      'delete',
      'Deleted section ID: '.$id.' with '.$schedCount.' related schedule(s)',
      $id,
      null
    );

    http_response_code(200);
    echo json_encode([
      "success" => true,
      "status"  => "success",
      "message" => "Section and its related schedules were deleted successfully",
      "deleted_schedules" => $schedCount
    ]);
  } catch (Throwable $e) {
    $conn->rollback();
    @log_activity($conn,'sections','delete','FAILED delete ID '.$id.': '.$e->getMessage(),$id,null);
    http_response_code(500);
    echo json_encode(["success"=>false, "status"=>"error", "message"=>"Error: ".$e->getMessage()]);
  }
}

$conn->close();
