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
  $prefs   = isset($data['preferences']) && is_array($data['preferences']) ? $data['preferences'] : [];

  if ($prof_id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "prof_id is required"]);
    exit();
  }

  // 1) Ensure the professor exists (prevents FK failure)
  $chk = $conn->prepare("SELECT 1 FROM professors WHERE prof_id = ? LIMIT 1");
  $chk->bind_param("i", $prof_id);
  $chk->execute();
  $exists = $chk->get_result()->num_rows > 0;
  $chk->close();

  if (!$exists) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Professor not found (prof_id=".$prof_id.")"]);
    exit();
  }

  // 2) Normalize and validate payload
  $allowed = ['beginner','intermediate','advanced'];
  $wantedSubjIds = [];
  $cleanPrefs = [];
  foreach ($prefs as $p) {
    $sid = isset($p['subj_id']) ? (int)$p['subj_id'] : 0;
    $lvl = isset($p['proficiency']) ? strtolower(trim((string)$p['proficiency'])) : '';
    if ($sid > 0 && in_array($lvl, $allowed, true)) {
      $wantedSubjIds[$sid] = true;
      $cleanPrefs[$sid] = $lvl; // de-dupe by subj_id
    }
  }

  // If you have FK to subjects, keep only existing subjects to avoid FK errors
  $validSubjIds = [];
  if (!empty($wantedSubjIds)) {
    $in  = implode(',', array_map('intval', array_keys($wantedSubjIds)));
    $rs  = $conn->query("SELECT subj_id FROM subjects WHERE subj_id IN ($in)");
    while ($row = $rs->fetch_assoc()) {
      $validSubjIds[(int)$row['subj_id']] = true;
    }
  }

  // 3) Write inside a transaction
  $conn->begin_transaction();
  try {
    // Clear old prefs for this professor
    $del = $conn->prepare("DELETE FROM professor_subject_preferences WHERE prof_id = ?");
    $del->bind_param("i", $prof_id);
    $del->execute();
    $del->close();

    // Insert new prefs (prepared statement, one execute per row for safety)
    $inserted = 0;
    if (!empty($cleanPrefs)) {
      $stmt = $conn->prepare(
        "INSERT INTO professor_subject_preferences (prof_id, subj_id, proficiency) VALUES (?,?,?)"
      );
      foreach ($cleanPrefs as $sid => $lvl) {
        if (!empty($validSubjIds) && !isset($validSubjIds[$sid])) {
          // skip non-existing subject ids if FK to subjects is present
          continue;
        }
        $sidInt = (int)$sid;
        $lvlStr = $lvl; // already validated against $allowed
        $stmt->bind_param("iis", $prof_id, $sidInt, $lvlStr);
        $stmt->execute();
        $inserted += $stmt->affected_rows > 0 ? 1 : 0;
      }
      $stmt->close();
    }

    $conn->commit();

    echo json_encode([
      "status"  => "success",
      "message" => "Preferences saved",
      "data"    => [
        "prof_id"        => $prof_id,
        "inserted_count" => $inserted,
        "skipped_count"  => max(0, count($cleanPrefs) - $inserted)
      ]
    ]);
  } catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
      "status"  => "error",
      "message" => "Failed to save preferences",
      "error"   => $e->getMessage()
    ]);
  }
  exit();
}

http_response_code(405);
echo json_encode(["status" => "error", "message" => "Method not allowed"]);
