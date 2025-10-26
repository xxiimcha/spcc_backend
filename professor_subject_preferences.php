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
  echo json_encode(["status"=>"error","message"=>"Database connection not available"]);
  exit();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** Resolve professor id from prof_id or user_id */
function resolve_prof_id(mysqli $conn, int $prof_id_in, int $user_id_in): int {
  if ($prof_id_in > 0) {
    $q = $conn->prepare("SELECT prof_id FROM professors WHERE prof_id = ? LIMIT 1");
    $q->bind_param("i", $prof_id_in);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) { $q->close(); return (int)$row['prof_id']; }
    $q->close();
  }

  if ($user_id_in > 0) {
    $q = $conn->prepare("SELECT prof_id FROM professors WHERE user_id = ? LIMIT 1");
    $q->bind_param("i", $user_id_in);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) { $q->close(); return (int)$row['prof_id']; }
    $q->close();
  }

  // try swapped
  if ($prof_id_in > 0) {
    $q = $conn->prepare("SELECT prof_id FROM professors WHERE user_id = ? LIMIT 1");
    $q->bind_param("i", $prof_id_in);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) { $q->close(); return (int)$row['prof_id']; }
    $q->close();
  }

  return 0;
}

/** Check if a table exists */
function table_exists(mysqli $conn, string $table): bool {
  $stmt = $conn->prepare("SHOW TABLES LIKE ?");
  $stmt->bind_param("s", $table);
  $stmt->execute();
  $res = $stmt->get_result();
  $exists = (bool)$res->fetch_row();
  $stmt->close();
  return $exists;
}

/** Check if a table has a specific column */
function column_exists(mysqli $conn, string $table, string $column): bool {
  $stmt = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $stmt->bind_param("ss", $table, $column);
  $stmt->execute();
  $res = $stmt->get_result();
  $exists = (bool)$res->fetch_row();
  $stmt->close();
  return $exists;
}

/**
 * Return DISTINCT subj_id that are already assigned to the professor.
 * Prefers schedules(prof_id, subj_id[, school_year, semester]).
 * Falls back to professor_assigned_subjects(prof_id, subj_id) if present.
 */
function get_locked_assigned_subj_ids(
  mysqli $conn,
  int $prof_id,
  ?string $school_year = null,
  ?string $semester = null
): array {
  $locked = [];

  if (table_exists($conn, 'schedules') && column_exists($conn, 'schedules', 'subj_id') && column_exists($conn, 'schedules', 'prof_id')) {
    $hasSY = column_exists($conn, 'schedules', 'school_year');
    $hasSem = column_exists($conn, 'schedules', 'semester');

    $sql = "SELECT DISTINCT subj_id FROM schedules WHERE prof_id = ?";
    $types = "i";
    $args  = [$prof_id];

    if ($hasSY && $school_year !== null && $school_year !== '') {
      $sql .= " AND school_year = ?";
      $types .= "s";
      $args[] = $school_year;
    }
    if ($hasSem && $semester !== null && $semester !== '') {
      $sql .= " AND semester = ?";
      $types .= "s";
      $args[] = $semester;
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $locked[(int)$row['subj_id']] = true;
    }
    $stmt->close();
  } elseif (table_exists($conn, 'professor_assigned_subjects')) {
    // fallback mapping table
    $stmt = $conn->prepare("SELECT DISTINCT subj_id FROM professor_assigned_subjects WHERE prof_id = ?");
    $stmt->bind_param("i", $prof_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $locked[(int)$row['subj_id']] = true;
    }
    $stmt->close();
  }

  return array_keys($locked); // list of ints
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
  $prof_id_in = isset($_GET['prof_id']) ? (int)$_GET['prof_id'] : 0;
  $user_id_in = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

  // optional filters to scope "locked" detection
  $school_year = isset($_GET['school_year']) ? trim((string)$_GET['school_year']) : null;
  $semester    = isset($_GET['semester'])    ? trim((string)$_GET['semester'])    : null;

  $prof_id = resolve_prof_id($conn, $prof_id_in, $user_id_in);
  if ($prof_id <= 0) {
    http_response_code(404);
    echo json_encode(["status"=>"error","message"=>"Professor not found (provide prof_id or user_id)"]);
    exit();
  }

  // current preferences
  $res = $conn->prepare(
    "SELECT prof_id, subj_id, proficiency, willingness
     FROM professor_subject_preferences
     WHERE prof_id = ?
     ORDER BY subj_id"
  );
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

  // include locked subjects (for UI convenience)
  $locked_ids = get_locked_assigned_subj_ids($conn, $prof_id, $school_year, $semester);

  echo json_encode([
    "status" => "success",
    "data" => $out,
    "resolved_prof_id" => $prof_id,
    "locked_subj_ids" => $locked_ids
  ]);
  exit();
}

if ($method === 'POST') {
  $raw  = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];

  $prof_id_in = isset($data['prof_id']) ? (int)$data['prof_id'] : 0;
  $user_id_in = isset($data['user_id']) ? (int)$data['user_id'] : 0;

  // optional scope for lock detection
  $school_year = isset($data['school_year']) ? trim((string)$data['school_year']) : null;
  $semester    = isset($data['semester'])    ? trim((string)$data['semester'])    : null;

  $prof_id = resolve_prof_id($conn, $prof_id_in, $user_id_in);
  if ($prof_id <= 0) {
    http_response_code(404);
    echo json_encode(["status"=>"error","message"=>"Professor not found (provide prof_id or user_id)"]);
    exit();
  }

  $prefs = isset($data['preferences']) && is_array($data['preferences']) ? $data['preferences'] : [];
  $allowedProf = ['beginner','intermediate','advanced'];
  $allowedWill = ['willing','not_willing']; // align with UI

  $wanted = [];           // subj_id => ['prof' => string, 'will' => string]
  $missingOrInvalid = []; // subj_id[]

  foreach ($prefs as $p) {
    $sid   = isset($p['subj_id']) ? (int)$p['subj_id'] : 0;
    $lvl   = isset($p['proficiency']) ? strtolower(trim((string)$p['proficiency'])) : '';
    $wilRaw = array_key_exists('willingness', $p) ? $p['willingness'] : null;
    $wil   = is_null($wilRaw) ? null : strtolower(trim((string)$wilRaw));

    if ($sid > 0 && in_array($lvl, $allowedProf, true)) {
      if ($wil === null || !in_array($wil, $allowedWill, true)) {
        $missingOrInvalid[] = $sid; // require valid willingness
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

  // Fetch locked/assigned subject IDs that must be preserved
  $locked_ids = get_locked_assigned_subj_ids($conn, $prof_id, $school_year, $semester);
  $locked_set = [];
  foreach ($locked_ids as $lid) { $locked_set[(int)$lid] = true; }

  // Verify incoming subject ids exist
  $validSubjIds = [];
  if (!empty($wanted)) {
    $in = implode(',', array_map('intval', array_keys($wanted)));
    $rs = $conn->query("SELECT subj_id FROM subjects WHERE subj_id IN ($in)");
    while ($row = $rs->fetch_assoc()) {
      $validSubjIds[(int)$row['subj_id']] = true;
    }
  }

  // Get existing preferences (to reuse for locked subjects if the client omitted them)
  $prevPrefs = []; // subj_id => ['prof'=>..., 'will'=>...]
  $stmtPrev = $conn->prepare("
    SELECT subj_id, proficiency, willingness
    FROM professor_subject_preferences
    WHERE prof_id = ?
  ");
  $stmtPrev->bind_param("i", $prof_id);
  $stmtPrev->execute();
  $resPrev = $stmtPrev->get_result();
  while ($row = $resPrev->fetch_assoc()) {
    $sid = (int)$row['subj_id'];
    $prevPrefs[$sid] = [
      'prof' => (string)$row['proficiency'],
      'will' => (string)$row['willingness'],
    ];
  }
  $stmtPrev->close();

  // Ensure all locked subjects are kept — if client omitted, re-add from previous or default
  foreach ($locked_set as $sid => $_) {
    if (!isset($wanted[$sid])) {
      $fallbackProf = isset($prevPrefs[$sid]['prof']) ? $prevPrefs[$sid]['prof'] : 'beginner';
      $fallbackWill = isset($prevPrefs[$sid]['will']) ? $prevPrefs[$sid]['will'] : 'willing';
      // normalize just in case
      if (!in_array($fallbackProf, $allowedProf, true)) $fallbackProf = 'beginner';
      if (!in_array($fallbackWill, $allowedWill, true)) $fallbackWill = 'willing';
      $wanted[$sid] = ['prof' => $fallbackProf, 'will' => $fallbackWill];
      // also mark as valid even if not in the earlier validation list
      $validSubjIds[$sid] = true;
    }
  }

  // If, after merging, nothing to save, return early
  if (empty($wanted)) {
    http_response_code(400);
    echo json_encode([
      "status"  => "error",
      "message" => "Nothing to save.",
    ]);
    exit();
  }

  $conn->begin_transaction();
  try {
    // Delete ONLY non-locked rows for this professor
    if (!empty($locked_set)) {
      $placeholders = implode(',', array_fill(0, count($locked_set), '?'));
      $types = 'i' . str_repeat('i', count($locked_set));
      $sql = "DELETE FROM professor_subject_preferences WHERE prof_id = ? AND subj_id NOT IN ($placeholders)";
      $stmtDel = $conn->prepare($sql);

      $params = [ $prof_id ];
      foreach (array_keys($locked_set) as $sid) $params[] = (int)$sid;
      $stmtDel->bind_param($types, ...$params);
      $stmtDel->execute();
      $stmtDel->close();
    } else {
      // No locked subjects — delete all
      $del = $conn->prepare("DELETE FROM professor_subject_preferences WHERE prof_id = ?");
      $del->bind_param("i", $prof_id);
      $del->execute();
      $del->close();
    }

    // Insert/Upsert merged set (locked + requested)
    $inserted = 0;
    $stmt = $conn->prepare("
      INSERT INTO professor_subject_preferences (prof_id, subj_id, proficiency, willingness)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE
        proficiency = VALUES(proficiency),
        willingness = VALUES(willingness)
    ");

    foreach ($wanted as $sid => $info) {
      if (!isset($validSubjIds[$sid])) continue; // skip invalid subject IDs
      $sidInt = (int)$sid;
      $lvlStr = $info['prof'];
      $wilStr = $info['will'];
      $stmt->bind_param("iiss", $prof_id, $sidInt, $lvlStr, $wilStr);
      $stmt->execute();
      if ($stmt->affected_rows >= 0) $inserted++; // >=0 because of upsert
    }
    $stmt->close();

    $conn->commit();

    echo json_encode([
      "status"  => "success",
      "message" => "Preferences saved",
      "data"    => [
        "resolved_prof_id" => $prof_id,
        "inserted_count"   => $inserted,
        "locked_subj_ids"  => array_keys($locked_set),
        "note"             => "Assigned subjects are preserved even if omitted in the request."
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
echo json_encode(["status"=>"error","message"=>"Method not allowed"]);
