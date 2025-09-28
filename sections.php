<?php
// sections.php - API endpoint for section management (MySQLi, NO prepared stmts)

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit(0); }

include 'connect.php';
$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => "Database connection failed: ".$conn->connect_error]);
  exit();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function esc(mysqli $c, $v) {
  return "'".$c->real_escape_string($v)."'";
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
  case 'GET':
    if (isset($_GET['id'])) {
      getSection($conn, (int)$_GET['id']);
    } else {
      getAllSections($conn);
    }
    break;

  case 'POST':
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
      http_response_code(400);
      echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
      exit();
    }
    createSection($conn, $data);
    break;

  case 'PUT':
    if (!isset($_GET['id'])) {
      http_response_code(400);
      echo json_encode(["status" => "error", "message" => "Section ID is required"]);
      exit();
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
      http_response_code(400);
      echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
      exit();
    }
    updateSection($conn, (int)$_GET['id'], $data);
    break;

  case 'DELETE':
    if (!isset($_GET['id'])) {
      http_response_code(400);
      echo json_encode(["status" => "error", "message" => "Section ID is required"]);
      exit();
    }
    deleteSection($conn, (int)$_GET['id']);
    break;

  default:
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    break;
}

function getAllSections(mysqli $conn): void {
  $sql = "SELECT s.*,
          (SELECT COUNT(*) FROM schedules WHERE section_id = s.section_id) AS schedule_count,
          GROUP_CONCAT(DISTINCT r.room_id, ':', r.room_number, ':', r.room_type, ':', r.room_capacity) AS rooms,
          MIN(r.room_id) AS primary_room_id
          FROM sections s
          LEFT JOIN section_room_assignments sra ON s.section_id = sra.section_id
          LEFT JOIN rooms r ON sra.room_id = r.room_id
          GROUP BY s.section_id
          ORDER BY s.grade_level, s.strand, s.section_name";

  $result = $conn->query($sql);
  $sections = [];
  while ($row = $result->fetch_assoc()) {
    $rooms = [];
    if (!empty($row['rooms'])) {
      foreach (explode(',', $row['rooms']) as $room) {
        [$id, $number, $type, $capacity] = explode(':', $room);
        $rooms[] = [
          'id' => (int)$id,
          'number' => (int)$number,
          'type' => $type,
          'capacity' => (int)$capacity
        ];
      }
    }

    $subject_ids_raw = $row['subject_ids'] ?? null;
    $subject_ids = [];
    if ($subject_ids_raw !== null && $subject_ids_raw !== '') {
      $tmp = json_decode($subject_ids_raw, true);
      if (is_array($tmp)) $subject_ids = array_values(array_map('intval', $tmp));
    }

    $sections[] = [
      'section_id' => (int)$row['section_id'],
      'section_name' => $row['section_name'],
      'grade_level' => $row['grade_level'],
      'strand' => $row['strand'],
      'number_of_students' => (int)$row['number_of_students'],
      'schedule_count' => (int)$row['schedule_count'],
      'primary_room_id' => $row['primary_room_id'] ? (int)$row['primary_room_id'] : null,
      'rooms' => $rooms,
      'subject_ids_raw' => $subject_ids_raw,
      'subject_ids' => $subject_ids
    ];
  }

  echo json_encode(["success" => true, "data" => $sections]);
}

function getSection(mysqli $conn, int $id) {
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

  if ($row = $result->fetch_assoc()) {
    $rooms = [];
    if (!empty($row['rooms'])) {
      foreach (explode(',', $row['rooms']) as $room) {
        [$rid, $number, $type, $capacity] = explode(':', $room);
        $rooms[] = [
          'id' => (int)$rid,
          'number' => (int)$number,
          'type' => $type,
          'capacity' => (int)$capacity
        ];
      }
    }

    $section = [
      'section_id' => (int)$row['section_id'],
      'section_name' => $row['section_name'],
      'grade_level' => $row['grade_level'],
      'strand' => $row['strand'],
      'number_of_students' => (int)$row['number_of_students'],
      'schedule_count' => (int)$row['schedule_count'],
      'primary_room_id' => $row['primary_room_id'] ? (int)$row['primary_room_id'] : null,
      'rooms' => $rooms
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
      $days_sql = "SELECT d.day_name
                   FROM schedule_days sd
                   JOIN days d ON sd.day_id = d.day_id
                   WHERE sd.schedule_id = ".(int)$sr['schedule_id'];
      $days_result = $conn->query($days_sql);
      $days = [];
      while ($dr = $days_result->fetch_assoc()) {
        $days[] = $dr['day_name'];
      }

      $schedules[] = [
        'schedule_id' => (int)$sr['schedule_id'],
        'subject' => [
          'id' => (int)$sr['subj_id'],
          'code' => $sr['subj_code'],
          'name' => $sr['subj_name']
        ],
        'professor' => [
          'id' => (int)$sr['prof_id'],
          'name' => $sr['professor_name'],
          'subject_count' => (int)$sr['professor_subject_count']
        ],
        'room' => $sr['room_id'] ? [
          'id' => (int)$sr['room_id'],
          'number' => (int)$sr['room_number'],
          'type' => $sr['room_type'],
          'capacity' => (int)$sr['room_capacity']
        ] : null,
        'schedule_type' => $sr['schedule_type'],
        'start_time' => $sr['start_time'],
        'end_time' => $sr['end_time'],
        'days' => $days
      ];
    }

    $subject_ids_raw = $row['subject_ids'] ?? null;
    $subject_ids = [];
    if ($subject_ids_raw !== null && $subject_ids_raw !== '') {
      $tmp = json_decode($subject_ids_raw, true);
      if (is_array($tmp)) $subject_ids = array_values(array_map('intval', $tmp));
    }
    $section['subject_ids_raw'] = $subject_ids_raw;
    $section['subject_ids'] = $subject_ids;
    $section['schedules'] = $schedules;

    echo json_encode(["success" => true, "data" => $section]);
  } else {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Section not found"]);
  }
}

function createSection(mysqli $conn, array $data) {
  $conn->begin_transaction();
  try {
    $name = $data['section_name'];
    $gradeLevel = isset($data['grade_level']) ? $data['grade_level'] : null;
    $numberOfStudents = isset($data['number_of_students']) ? (int)$data['number_of_students'] : null;
    $strand = isset($data['strand']) ? $data['strand'] : null;
    $roomIds = isset($data['room_ids']) ? $data['room_ids'] : [];

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

    $sql = "INSERT INTO sections (section_name, grade_level, number_of_students, strand)
            VALUES (".esc($conn,$name).",".($gradeLevel===null?"NULL":esc($conn,$gradeLevel)).",".
            ($numberOfStudents===null?"NULL":(int)$numberOfStudents).",".
            ($strand===null?"NULL":esc($conn,$strand)).")";
    $conn->query($sql);
    $sectionId = (int)$conn->insert_id;

    if (!empty($roomIds)) {
      foreach ($roomIds as $rid) {
        $conn->query("INSERT INTO section_room_assignments (section_id, room_id) VALUES ($sectionId, ".(int)$rid.")");
      }
    }

    $sql = "SELECT s.*,
            GROUP_CONCAT(DISTINCT r.room_id, ':', r.room_number, ':', r.room_type, ':', r.room_capacity) AS rooms
            FROM sections s
            LEFT JOIN section_room_assignments sra ON s.section_id = sra.section_id
            LEFT JOIN rooms r ON sra.room_id = r.room_id
            WHERE s.section_id = $sectionId
            GROUP BY s.section_id";
    $row = $conn->query($sql)->fetch_assoc();

    $rooms = [];
    if (!empty($row['rooms'])) {
      foreach (explode(',', $row['rooms']) as $room) {
        [$id, $number, $type, $capacity] = explode(':', $room);
        $rooms[] = [
          'id' => (int)$id,
          'number' => (int)$number,
          'type' => $type,
          'capacity' => (int)$capacity
        ];
      }
    }

    $section = [
      'section_id' => (int)$row['section_id'],
      'section_name' => $row['section_name'],
      'grade_level' => $row['grade_level'],
      'strand' => $row['strand'],
      'number_of_students' => (int)$row['number_of_students'],
      'rooms' => $rooms
    ];

    $conn->commit();

    try {
      require_once 'firebase_sync.php';
      $sync = new FirebaseSync($firebaseConfig, $conn);
      $firebaseResult = $sync->syncSections();
      http_response_code(201);
      echo json_encode([
        "status" => "success",
        "message" => $firebaseResult['success']
          ? "Section added successfully and synced to Firebase"
          : "Section added successfully but Firebase sync failed",
        "section" => $section,
        "firebase_sync" => $firebaseResult
      ]);
    } catch (Exception $firebaseError) {
      http_response_code(201);
      echo json_encode([
        "status" => "success",
        "message" => "Section added successfully but Firebase sync failed",
        "section" => $section,
        "firebase_error" => $firebaseError->getMessage()
      ]);
    }
  } catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error: ".$e->getMessage()]);
  }
}

function updateSection(mysqli $conn, int $id, array $data) {
  $conn->begin_transaction();
  try {
    $curRes = $conn->query("SELECT * FROM sections WHERE section_id = $id");
    if ($curRes->num_rows === 0) throw new Exception("Section not found");
    $cur = $curRes->fetch_assoc();

    $sets = [];

    if (array_key_exists('section_name', $data)) {
      $name = trim((string)$data['section_name']);
      if (strlen($name) > 50) throw new Exception("Section name must not exceed 50 characters");
      $sets[] = "section_name = ".esc($conn,$name);
    }

    if (array_key_exists('grade_level', $data)) {
      $gl = (string)$data['grade_level'];
      if ($gl !== '' && !in_array($gl, ['11','12'], true)) throw new Exception("Grade level must be either '11' or '12'");
      $sets[] = "grade_level = ".($gl===''? "NULL" : esc($conn,$gl));
    }

    if (array_key_exists('strand', $data)) {
      $strand = (string)$data['strand'];
      if (strlen($strand) > 10) throw new Exception("Strand must not exceed 10 characters");
      $sets[] = "strand = ".($strand===''? "NULL" : esc($conn,$strand));
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
        $json = trim($data['subj_ids']); // assume valid JSON
      } else {
        $json = '[]';
      }
      $sets[] = "subject_ids = ".esc($conn,$json);
    }

    $updateRooms = false;
    $roomIds = [];
    if (array_key_exists('room_ids', $data)) {
      if (!is_array($data['room_ids'])) throw new Exception("room_ids must be an array");
      $roomIds = array_values(array_unique(array_map('intval', $data['room_ids'])));
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
    $subject_ids_arr = [];
    if ($subject_ids_raw !== null && $subject_ids_raw !== '') {
      $decoded = json_decode($subject_ids_raw, true);
      if (is_array($decoded)) $subject_ids_arr = array_values(array_map('intval', $decoded));
    }

    $conn->commit();
    echo json_encode([
      "success" => true,
      "status"  => "success",
      "message" => "Section updated successfully",
      "section" => [
        "section_id" => (int)$row["section_id"],
        "section_name" => $row["section_name"],
        "grade_level" => $row["grade_level"],
        "strand" => $row["strand"],
        "number_of_students" => isset($row["number_of_students"]) ? (int)$row["number_of_students"] : null,
        "subject_ids_raw" => $subject_ids_raw,
        "subject_ids" => $subject_ids_arr,
      ],
    ]);
  } catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["success" => false, "status" => "error", "message" => "Error: ".$e->getMessage()]);
  }
}

function deleteSection(mysqli $conn, int $id) {
  $cascade = isset($_GET['cascade']) && $_GET['cascade'] == '1';

  $exists = $conn->query("SELECT section_id FROM sections WHERE section_id = $id")->num_rows > 0;
  if (!$exists) {
    http_response_code(404);
    echo json_encode(["success" => false, "status" => "error", "message" => "Section not found"]);
    return;
  }

  $c = (int)($conn->query("SELECT COUNT(*) AS c FROM schedules WHERE section_id = $id")->fetch_assoc()['c'] ?? 0);
  if ($c > 0 && !$cascade) {
    http_response_code(409);
    echo json_encode([
      "success" => false,
      "status"  => "error",
      "message" => "This section is referenced by $c schedule(s). Delete them first or pass cascade=1."
    ]);
    return;
  }

  $conn->begin_transaction();
  try {
    if ($c > 0) {
      $conn->query("DELETE sd FROM schedule_days sd INNER JOIN schedules s ON s.schedule_id = sd.schedule_id WHERE s.section_id = $id");
      $conn->query("DELETE FROM schedules WHERE section_id = $id");
    }
    $conn->query("DELETE FROM section_room_assignments WHERE section_id = $id");
    $conn->query("DELETE FROM sections WHERE section_id = $id");

    $conn->commit();
    echo json_encode(["success" => true, "status" => "success", "message" => "Section deleted successfully"]);
  } catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["success" => false, "status" => "error", "message" => "Error: ".$e->getMessage()]);
  }
}

$conn->close();
