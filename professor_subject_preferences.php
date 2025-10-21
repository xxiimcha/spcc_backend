<?php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

include __DIR__ . '/connect.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Database connection not available"]);
  exit();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$esc = fn($v) => $conn->real_escape_string((string)$v);

function resolve_prof_id(mysqli $conn, int $prof_id, int $user_id): int {
  if ($prof_id > 0) return $prof_id;
  if ($user_id > 0) {
    $q = $conn->prepare("SELECT prof_id FROM professors WHERE user_id = ? LIMIT 1");
    $q->bind_param("i", $user_id);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) return (int)$row['prof_id'];
    $q->close();
  }
  return 0;
}

function ensure_prof_exists(mysqli $conn, int $prof_id): bool {
  $q = $conn->prepare("SELECT 1 FROM professors WHERE prof_id = ? LIMIT 1");
  $q->bind_param("i", $prof_id);
  $q->execute();
  $ok = $q->get_result()->num_rows > 0;
  $q->close();
  return $ok;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
  $prof_id = isset($_GET['prof_id']) ? (int)$_GET['prof_id'] : 0;
  $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
  $prof_id = resolve_prof_id($conn, $prof_id, $user_id);
  if ($prof_id <= 0) {
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>"prof_id is required (or provide user_id)"]);
    exit();
  }
  if (!ensure_prof_exists($conn, $prof_id)) {
    http_response_code(404);
    echo json_encode(["status"=>"error","message"=>"Professor not found (prof_id=".$prof_id.")"]);
    exit();
  }

  $sql = "SELECT prof_id, subj_id, proficiency FROM professor_subject_preferences WHERE prof_id = ".$prof_id." ORDER BY subj_id";
  $res = $conn->query($sql);
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $out[] = [
      "prof_id" => (int)$row["prof_id"],
      "subj_id" => (int)$row["subj_id"],
      "proficiency" => (string)$row["proficiency"],
    ];
  }
  echo json_encode(["status"=>"success","data"=>$out]);
  exit();
}

if ($method === 'POST') {
  $raw  = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];

  $prof_id = isset($data['prof_id']) ? (int)$data['prof_id'] : 0;
  $user_id = isset($data['user_id']) ? (int)$data['user_id'] : 0;
  $prof_id = resolve_prof_id($conn, $prof_id, $user_id);

  $prefs = isset($data['preferences']) && is_array($data['preferences']) ? $data['preferences'] : [];
  if ($prof_id <= 0) {
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>"prof_id is required (or provide user_id)"]);
    exit();
  }
  if (!ensure_prof_exists($conn, $prof_id)) {
    http_response_code(404);
    echo json_encode(["status"=>"error","message"=>"Professor not found (prof_id=".$prof_id.")"]);
    exit();
  }

  $allowed = ['beginner','intermediate','advanced'];
  $wanted  = [];
  foreach ($prefs as $p) {
    $sid = isset($p['subj_id']) ? (int)$p['subj_id'] : 0;
    $lvl = isset($p['proficiency']) ? strtolower(trim((string)$p['proficiency'])) : '';
    if ($sid > 0 && in_array($lvl, $allowed, true)) $wanted[$sid] = $lvl;
  }

  $validSubjIds = [];
  if (!empty($wanted)) {
    $in = implode(',', array_map('intval', array_keys($wanted)));
    $rs = $conn->query("SELECT subj_id FROM subjects WHERE subj_id IN ($in)");
    while ($row = $rs->fetch_assoc()) $validSubjIds[(int)$row['subj_id']] = true;
  }

  $conn->begin_transaction();
  try {
    $del = $conn->prepare("DELETE FROM professor_subject_preferences WHERE prof_id = ?");
    $del->bind_param("i", $prof_id);
    $del->execute();
    $del->close();

    $inserted = 0;
    if (!empty($wanted)) {
      $stmt = $conn->prepare("INSERT INTO professor_subject_preferences (prof_id, subj_id, proficiency) VALUES (?,?,?)");
      foreach ($wanted as $sid => $lvl) {
        if (!isset($validSubjIds[$sid])) continue;
        $sidInt = (int)$sid;
        $lvlStr = $esc($lvl);
        $stmt->bind_param("iis", $prof_id, $sidInt, $lvlStr);
        $stmt->execute();
        if ($stmt->affected_rows > 0) $inserted++;
      }
      $stmt->close();
    }

    $conn->commit();
    echo json_encode([
      "status"=>"success",
      "message"=>"Preferences saved",
      "data"=>[
        "prof_id"=>$prof_id,
        "inserted_count"=>$inserted,
        "skipped_count"=>max(0, count($wanted) - $inserted)
      ]
    ]);
  } catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["status"=>"error","message"=>"Failed to save preferences","error"=>$e->getMessage()]);
  }
  exit();
}

http_response_code(405);
echo json_encode(["status"=>"error","message"=>"Method not allowed"]);
