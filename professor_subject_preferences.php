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
  echo json_encode(["status" => "error", "message" => "Database connection not available"]);
  exit();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$esc = fn($v) => $conn->real_escape_string((string)$v);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
  $prof_id = isset($_GET['prof_id']) ? (int)$_GET['prof_id'] : 0;
  if ($prof_id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "prof_id is required"]);
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

  echo json_encode(["status" => "success", "data" => $out]);
  exit();
}

if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) { $data = []; }

  $prof_id = isset($data['prof_id']) ? (int)$data['prof_id'] : 0;
  $prefs = isset($data['preferences']) && is_array($data['preferences']) ? $data['preferences'] : [];

  if ($prof_id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "prof_id is required"]);
    exit();
  }

  $allowed = ['beginner','intermediate','advanced'];
  $values = [];
  foreach ($prefs as $p) {
    $sid = isset($p['subj_id']) ? (int)$p['subj_id'] : 0;
    $lvl = isset($p['proficiency']) ? strtolower(trim((string)$p['proficiency'])) : '';
    if ($sid > 0 && in_array($lvl, $allowed, true)) {
      $values[] = "($prof_id, $sid, '".$esc($lvl)."')";
    }
  }

  $conn->begin_transaction();
  try {
    $conn->query("DELETE FROM professor_subject_preferences WHERE prof_id = ".$prof_id);
    if (!empty($values)) {
      $ins = "INSERT INTO professor_subject_preferences (prof_id, subj_id, proficiency) VALUES ".implode(",", $values);
      $conn->query($ins);
    }
    $conn->commit();
    echo json_encode(["status" => "success", "message" => "Preferences saved", "data" => ["prof_id" => $prof_id, "count" => count($values)]]);
  } catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to save preferences", "error" => $e->getMessage()]);
  }
  exit();
}

http_response_code(405);
echo json_encode(["status" => "error", "message" => "Method not allowed"]);
