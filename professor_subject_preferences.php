<?php
declare(strict_types=1);

include 'cors_helper.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit();
}

header('Content-Type: application/json; charset=utf-8');

include __DIR__ . '/connect.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => "Database connection not available"]);
  exit();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Resolve professor id from prof_id or user_id (works with either)
 */
function resolve_prof_id(mysqli $conn, int $prof_id_in, int $user_id_in): int {
  if ($prof_id_in > 0) {
    $q = $conn->prepare("SELECT prof_id FROM professors WHERE prof_id = ? LIMIT 1");
    $q->bind_param("i", $prof_id_in);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) {
      $q->close();
      return (int)$row['prof_id'];
    }
    $q->close();
  }

  if ($user_id_in > 0) {
    $q = $conn->prepare("SELECT prof_id FROM professors WHERE user_id = ? LIMIT 1");
    $q->bind_param("i", $user_id_in);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) {
      $q->close();
      return (int)$row['prof_id'];
    }
    $q->close();
  }

  // fallback: maybe swapped
  if ($prof_id_in > 0) {
    $q = $conn->prepare("SELECT prof_id FROM professors WHERE user_id = ? LIMIT 1");
    $q->bind_param("i", $prof_id_in);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) {
      $q->close();
      return (int)$row['prof_id'];
    }
    $q->close();
  }

  return 0;
}

$method = $_SERVER['REQUEST_METHOD'];

/* ===========================================================
   GET: Fetch subject preferences
   =========================================================== */
if ($method === 'GET') {
  $prof_id_in = isset($_GET['prof_id']) ? (int)$_GET['prof_id'] : 0;
  $user_id_in = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

  $prof_id = resolve_prof_id($conn, $prof_id_in, $user_id_in);
  if ($prof_id <= 0) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Professor not found (provide prof_id or user_id)"]);
    exit();
  }

  $res = $conn->prepare("
    SELECT prof_id, subj_id, proficiency, willingness
    FROM professor_subject_preferences
    WHERE prof_id = ?
    ORDER BY subj_id
  ");
  $res->bind_param("i", $prof_id);
  $res->execute();
  $result = $res->get_result();

  $out = [];
  while ($row = $result->fetch_assoc()) {
    $out[] = [
      "prof_id"     => (int)$row["prof_id"],
      "subj_id"     => (int)$row["subj_id"],
      "proficiency" => (string)$row["proficiency"],
      "willingness" => isset($row["willingness"]) ? (string)$row["willingness"] : null,
    ];
  }
  $res->close();

  echo json_encode(["status" => "success", "data" => $out, "resolved_prof_id" => $prof_id]);
  exit();
}

/* ===========================================================
   POST: Save subject preferences
   =========================================================== */
if ($method === 'POST') {
  $raw  = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];

  $prof_id_in = isset($data['prof_id']) ? (int)$data['prof_id'] : 0;
  $user_id_in = isset($data['user_id']) ? (int)$data['user_id'] : 0;

  $prof_id = resolve_prof_id($conn, $prof_id_in, $user_id_in);
  if ($prof_id <= 0) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Professor not found (provide prof_id or user_id)"]);
    exit();
  }

  $prefs = isset($data['preferences']) && is_array($data['preferences']) ? $data['preferences'] : [];
  $allowedProf = ['beginner', 'intermediate', 'advanced'];
  $allowedWill = ['willing', 'maybe', 'not_willing'];

  $wanted = [];           // subj_id => ['prof' => string, 'will' => string]
  $missingOrInvalid = []; // subj_id[]

  foreach ($prefs as $p) {
    $sid    = isset($p['subj_id']) ? (int)$p['subj_id'] : 0;
    $lvl    = isset($p['proficiency']) ? strtolower(trim((string)$p['proficiency'])) : '';
    $wilRaw = array_key_exists('willingness', $p) ? $p['willingness'] : null;
    $wil    = is_null($wilRaw) ? null : strtolower(trim((string)$wilRaw));

    if ($sid > 0 && in_array($lvl, $allowedProf, true)) {
      if ($wil === null || !in_array($wil, $allowedWill, true)) {
        $missingOrInvalid[] = $sid;
      } else {
        $wanted[$sid] = ['prof' => $lvl, 'will' => $wil];
      }
    }
  }

  if (!empty($missingOrInvalid)) {
    http_response_code(400);
    echo json_encode([
      "status"  => "error",
      "message" => "Willingness is required for all selected subjects.",
      "data"    => ["invalid_subj_ids" => $missingOrInvalid]
    ]);
    exit();
  }

  /* ===========================================================
     Assigned subjects: must always be kept (cannot be removed)
     =========================================================== */

  // 1) Assigned from professors.prof_subject_ids
  $assignedIds = [];
  $chk = $conn->prepare("SELECT prof_subject_ids FROM professors WHERE prof_id = ? LIMIT 1");
  $chk->bind_param("i", $prof_id);
  $chk->execute();
  $rs = $chk->get_result();
  if ($row = $rs->fetch_assoc()) {
    $arr = json_decode($row['prof_subject_ids'] ?? '[]', true) ?: [];
    foreach ($arr as $x) { $assignedIds[] = (int)$x; }
  }
  $chk->close();

  // 2) Assigned from schedules (current schedules with this prof)
  $qsch = $conn->query("SELECT DISTINCT subj_id FROM schedules WHERE prof_id = {$prof_id}");
  while ($r = $qsch->fetch_assoc()) {
    $assignedIds[] = (int)$r['subj_id'];
  }
  $assignedIds = array_values(array_unique(array_filter($assignedIds, fn($v)=>$v>0)));

  // Previous preferences for assigned ids (to preserve levels/willingness where possible)
  $prevPref = [];
  if (!empty($assignedIds)) {
    $inAssigned = implode(',', array_map('intval', $assignedIds));
    $qprev = $conn->query("
      SELECT subj_id, proficiency, willingness
      FROM professor_subject_preferences
      WHERE prof_id = {$prof_id} AND subj_id IN ({$inAssigned})
    ");
    while ($r = $qprev->fetch_assoc()) {
      $sid = (int)$r['subj_id'];
      $prevPref[$sid] = [
        'prof' => (string)$r['proficiency'],
        'will' => (string)$r['willingness'],
      ];
    }
  }

  // Validate subject ids of payload + assigned exist in subjects table
  $validSubjIds = [];
  $allCheckIds  = array_values(array_unique(array_merge(array_keys($wanted), $assignedIds)));
  if (!empty($allCheckIds)) {
    $in = implode(',', array_map('intval', $allCheckIds));
    $rs = $conn->query("SELECT subj_id FROM subjects WHERE subj_id IN ($in)");
    while ($row = $rs->fetch_assoc()) {
      $validSubjIds[(int)$row['subj_id']] = true;
    }
  }

  // Final to save = client $wanted UNION auto-kept assigned
  $finalWanted = $wanted;

  foreach ($assignedIds as $sid) {
    if (!isset($finalWanted[$sid])) {
      $profLvl = $prevPref[$sid]['prof'] ?? 'beginner';
      $will    = $prevPref[$sid]['will'] ?? 'willing';
      $finalWanted[$sid] = ['prof' => $profLvl, 'will' => $will];
    }
  }

  /* ===========================================================
     Save preferences (wipe then insert finalWanted)
     =========================================================== */
  $conn->begin_transaction();
  try {
    // wipe previous preferences
    $del = $conn->prepare("DELETE FROM professor_subject_preferences WHERE prof_id = ?");
    $del->bind_param("i", $prof_id);
    $del->execute();
    $del->close();

    $inserted = 0;

    if (!empty($finalWanted)) {
      $stmt = $conn->prepare("
        INSERT INTO professor_subject_preferences (prof_id, subj_id, proficiency, willingness)
        VALUES (?,?,?,?)
      ");
      foreach ($finalWanted as $sid => $info) {
        if (!isset($validSubjIds[$sid])) continue; // skip unknown subject ids
        $sidInt = (int)$sid;
        $lvlStr = $info['prof'];
        $wilStr = $info['will'];
        $stmt->bind_param("iiss", $prof_id, $sidInt, $lvlStr, $wilStr);
        $stmt->execute();
        if ($stmt->affected_rows > 0) $inserted++;
      }
      $stmt->close();
    }

    $conn->commit();
    echo json_encode([
      "status"  => "success",
      "message" => "Preferences saved",
      "data"    => [
        "resolved_prof_id" => $prof_id,
        "inserted_count"   => $inserted,
        "kept_assigned"    => array_values($assignedIds) // FYI
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

/* ===========================================================
   Invalid method
   =========================================================== */
http_response_code(405);
echo json_encode(["status" => "error", "message" => "Method not allowed"]);
