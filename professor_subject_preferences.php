<?php
declare(strict_types=1);

include 'cors_helper.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit();
}

header('Content-Type: application/json; charset=utf-8');

include __DIR__ . '/connect.php';
require_once __DIR__ . '/system_settings_helper.php'; // ← add helper for SY/Sem

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

/**
 * Normalize and decide the school_year and semester to use for GET filtering.
 * - 'all' -> returns null (meaning "do not filter" for that dimension)
 * - 'current' or ''/not set -> returns the current setting from DB
 * - any other string -> returns that explicit value
 */
function resolve_filters_for_get(mysqli $conn): array {
  $syParam  = isset($_GET['school_year']) ? trim((string)$_GET['school_year']) : '';
  $semParam = isset($_GET['semester']) ? trim((string)$_GET['semester']) : '';

  $currentSY  = ss_get_current_school_year($conn);
  $currentSem = ss_get_current_semester($conn);

  // SY
  if ($syParam === '' || strtolower($syParam) === 'current') {
    $filterSY = $currentSY;
    $appliedSY = $currentSY;
  } elseif (strtolower($syParam) === 'all') {
    $filterSY = null; // no filter
    $appliedSY = 'all';
  } else {
    $filterSY = $syParam;
    $appliedSY = $syParam;
  }

  // Semester
  if ($semParam === '' || strtolower($semParam) === 'current') {
    $filterSem = $currentSem;
    $appliedSem = $currentSem;
  } elseif (strtolower($semParam) === 'all') {
    $filterSem = null; // no filter
    $appliedSem = 'all';
  } else {
    $filterSem = $semParam;
    $appliedSem = $semParam;
  }

  return [
    'filterSY'    => $filterSY,
    'filterSem'   => $filterSem,
    'currentSY'   => $currentSY,
    'currentSem'  => $currentSem,
    'appliedSY'   => $appliedSY,
    'appliedSem'  => $appliedSem,
  ];
}

/**
 * Resolve SY+Sem to use for POST saving.
 * - If payload has 'school_year' or 'semester':
 *     - 'current' or '' -> map to current setting
 *     - explicit value  -> use it
 * - If missing entirely -> use current settings
 */
function resolve_sy_sem_for_post(mysqli $conn, array $data): array {
  $currentSY  = ss_get_current_school_year($conn);
  $currentSem = ss_get_current_semester($conn);

  $syIn  = array_key_exists('school_year', $data) ? trim((string)$data['school_year']) : '';
  $semIn = array_key_exists('semester', $data) ? trim((string)$data['semester']) : '';

  $useSY = ($syIn === '' || strtolower($syIn) === 'current') ? $currentSY : $syIn;
  $useSem = ($semIn === '' || strtolower($semIn) === 'current') ? $currentSem : $semIn;

  return ['school_year' => $useSY, 'semester' => $useSem, 'currentSY' => $currentSY, 'currentSem' => $currentSem];
}

$method = $_SERVER['REQUEST_METHOD'];

/* ===========================================================
   GET: Fetch subject preferences (now scoped by SY & Semester)
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

  $f = resolve_filters_for_get($conn);
  $wheres = ["prof_id = ?"];
  $types = "i";
  $params = [$prof_id];

  if ($f['filterSY'] !== null) {
    if ($f['filterSY'] === '') {
      $wheres[] = "school_year IS NULL";
    } else {
      $wheres[] = "school_year = ?";
      $types   .= "s";
      $params[] = $f['filterSY'];
    }
  }
  if ($f['filterSem'] !== null) {
    if ($f['filterSem'] === '') {
      $wheres[] = "semester IS NULL";
    } else {
      $wheres[] = "semester = ?";
      $types   .= "s";
      $params[] = $f['filterSem'];
    }
  }

  $sql = "
    SELECT prof_id, subj_id, proficiency, willingness, school_year, semester
    FROM professor_subject_preferences
    WHERE " . implode(' AND ', $wheres) . "
    ORDER BY subj_id
  ";

  $res = $conn->prepare($sql);
  $res->bind_param($types, ...$params);
  $res->execute();
  $result = $res->get_result();

  $out = [];
  while ($row = $result->fetch_assoc()) {
    $out[] = [
      "prof_id"     => (int)$row["prof_id"],
      "subj_id"     => (int)$row["subj_id"],
      "proficiency" => (string)$row["proficiency"],
      "willingness" => isset($row["willingness"]) ? (string)$row["willingness"] : null,
      "school_year" => $row["school_year"] ?? null,
      "semester"    => $row["semester"] ?? null,
    ];
  }
  $res->close();

  echo json_encode([
    "status" => "success",
    "data" => $out,
    "resolved_prof_id"     => $prof_id,
    "current_school_year"  => $f['currentSY'],
    "current_semester"     => $f['currentSem'],
    "applied_school_year"  => $f['appliedSY'],
    "applied_semester"     => $f['appliedSem'],
  ]);
  exit();
}

/* ===========================================================
   POST: Save subject preferences (scoped by SY & Semester)
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

  // Determine which SY/Sem to save into
  $sysem = resolve_sy_sem_for_post($conn, $data);
  $useSY   = $sysem['school_year'];   // may be null if not set in settings
  $useSem  = $sysem['semester'];      // may be null if not set in settings

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
  // TODO (optional): If your schedules table has school_year/semester, add filters here.
  $qsch = $conn->query("SELECT DISTINCT subj_id FROM schedules WHERE prof_id = {$prof_id}");
  while ($r = $qsch->fetch_assoc()) {
    $assignedIds[] = (int)$r['subj_id'];
  }
  $assignedIds = array_values(array_unique(array_filter($assignedIds, fn($v)=>$v>0)));

  // Previous preferences for assigned ids (to preserve levels/willingness where possible) — scoped by SY/Sem
  $prevPref = [];
  if (!empty($assignedIds)) {
    $inAssigned = implode(',', array_map('intval', $assignedIds));

    $where = "prof_id = {$prof_id} AND subj_id IN ({$inAssigned})";
    if ($useSY !== null) {
      $syEsc = $useSY === '' ? null : "'" . $conn->real_escape_string($useSY) . "'";
      $where .= $useSY === '' ? " AND school_year IS NULL" : " AND school_year = {$syEsc}";
    }
    if ($useSem !== null) {
      $semEsc = $useSem === '' ? null : "'" . $conn->real_escape_string($useSem) . "'";
      $where .= $useSem === '' ? " AND semester IS NULL" : " AND semester = {$semEsc}";
    }

    $qprev = $conn->query("
      SELECT subj_id, proficiency, willingness
      FROM professor_subject_preferences
      WHERE {$where}
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
     Save preferences (wipe then insert finalWanted) for THIS SY+Sem
     =========================================================== */
  $conn->begin_transaction();
  try {
    // wipe previous preferences only for this professor + SY + Sem
    $delSql = "DELETE FROM professor_subject_preferences WHERE prof_id = ?";
    $types  = "i";
    $params = [$prof_id];

    if ($useSY !== null) {
      if ($useSY === '') {
        $delSql .= " AND school_year IS NULL";
      } else {
        $delSql .= " AND school_year = ?";
        $types  .= "s";
        $params[] = $useSY;
      }
    }
    if ($useSem !== null) {
      if ($useSem === '') {
        $delSql .= " AND semester IS NULL";
      } else {
        $delSql .= " AND semester = ?";
        $types  .= "s";
        $params[] = $useSem;
      }
    }

    $del = $conn->prepare($delSql);
    $del->bind_param($types, ...$params);
    $del->execute();
    $del->close();

    $inserted = 0;

    if (!empty($finalWanted)) {
      // Insert includes school_year & semester columns
      $stmt = $conn->prepare("
        INSERT INTO professor_subject_preferences (prof_id, subj_id, proficiency, willingness, school_year, semester, created_at, updated_at)
        VALUES (?,?,?,?,?,?,NOW(),NOW())
      ");
      foreach ($finalWanted as $sid => $info) {
        if (!isset($validSubjIds[$sid])) continue; // skip unknown subject ids
        $sidInt = (int)$sid;
        $lvlStr = $info['prof'];
        $wilStr = $info['will'];

        // bind: iissss (prof_id, subj_id, proficiency, willingness, school_year, semester)
        $stmt->bind_param(
          "iissss",
          $prof_id,
          $sidInt,
          $lvlStr,
          $wilStr,
          $useSY,   // can be null; mysqli will send as NULL if variable is null & types 's' still acceptable
          $useSem
        );
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
        "kept_assigned"    => array_values($assignedIds),
        "school_year"      => $useSY,
        "semester"         => $useSem,
        "current_school_year" => $sysem['currentSY'],
        "current_semester"    => $sysem['currentSem'],
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
