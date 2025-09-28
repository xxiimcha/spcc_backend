<?php
/**
 * schedule_autogen.php (MySQLi, NO prepared stmts)
 * - Requires a room mapping per section (latest in section_room_assignments)
 * - Seeds (section, subj, prof) combos for the same school_year + semester
 *   - default: from existing schedules (your current behavior)
 *   - optional: from section_subjects if seedFrom="section_subjects"
 * - Writes one row per (day, slot) using JSON days: JSON_ARRAY('<lowercased-day>')
 * - Idempotent: avoids duplicates via NOT EXISTS guard
 * - Adds flags: ignoreExistingMinutes (bool), seedFrom ("schedules" | "section_subjects")
 * - Adds diagnostics: details.metrics per (section, subj)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/connect.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "Database connection not available"]);
  exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  // ---- utilities -----------------------------------------------------------
  $esc = function($v) use ($conn) { return $conn->real_escape_string($v); };

  $queryAll = function(string $sql) use ($conn) {
    $res = $conn->query($sql);
    if ($res === false) throw new Exception("Query failed: " . $conn->error);
    $rows = [];
    while ($row = $res->fetch_assoc()) { $rows[] = $row; }
    return $rows;
  };

  $queryScalar = function(string $sql, $default = 0) use ($conn) {
    $res = $conn->query($sql);
    if ($res === false) throw new Exception("Query failed: " . $conn->error);
    $row = $res->fetch_row();
    return $row && isset($row[0]) ? $row[0] : $default;
  };

  // ---- input ---------------------------------------------------------------
  $raw = file_get_contents('php://input');
  $input = json_decode($raw, true);
  if (!$input || !is_array($input)) throw new Exception('Invalid JSON body');

  $schoolYear = trim((string)($input['school_year'] ?? ''));
  $semester   = trim((string)($input['semester'] ?? ''));
  $days       = $input['days'] ?? [];
  $startTime  = trim((string)($input['startTime'] ?? '07:30'));
  $endTime    = trim((string)($input['endTime'] ?? '16:30'));
  $slotMin    = (int)($input['slotMinutes'] ?? 60);
  $maxDaily   = isset($input['maxDailyLoad']) ? (int)$input['maxDailyLoad'] : null;

  $enfSameSec = (bool)($input['preventSameTimeSameSection'] ?? true);
  $enfProf    = (bool)($input['preventProfDoubleBooking'] ?? true);
  $subCapHrs  = (int)($input['subjectWeeklyHourCap'] ?? 4);

  // new flags
  $ignoreExisting = (bool)($input['ignoreExistingMinutes'] ?? false);
  $seedFrom       = strtolower(trim((string)($input['seedFrom'] ?? 'schedules'))); // "schedules" | "section_subjects"

  $requireRoom = true;

  if ($schoolYear === '' || $semester === '') throw new Exception('Missing school_year or semester');
  if (!is_array($days) || count($days) === 0) throw new Exception('At least one active day is required');
  if ($slotMin <= 0) throw new Exception('slotMinutes must be positive');

  // ---- slots ---------------------------------------------------------------
  $toMinutes = function(string $t): int {
    if (!preg_match('/^\d{2}:\d{2}$/', $t)) return -1;
    [$h,$m] = explode(':', $t);
    return ((int)$h)*60 + ((int)$m);
  };
  $fromMins = $toMinutes($startTime);
  $toMins   = $toMinutes($endTime);
  if ($fromMins < 0 || $toMins < 0 || $fromMins >= $toMins) throw new Exception('Invalid start/end time');

  $slots = [];
  for ($m = $fromMins; $m + $slotMin <= $toMins; $m += $slotMin) {
    $sH = str_pad((string)floor($m/60), 2, '0', STR_PAD_LEFT);
    $sN = str_pad((string)($m%60), 2, '0', STR_PAD_LEFT);
    $eM = $m + $slotMin;
    $eH = str_pad((string)floor($eM/60), 2, '0', STR_PAD_LEFT);
    $eN = str_pad((string)($eM%60), 2, '0', STR_PAD_LEFT);
    $slots[] = [$sH.':'.$sN, $eH.':'.$eN, $slotMin]; // [start, end, minutes]
  }

  // ---- sections with rooms (latest mapping) --------------------------------
  $sqlSections = "
    SELECT s.section_id, sra.room_id
    FROM sections s
    JOIN (
      SELECT section_id, MAX(assignment_id) AS latest_assignment_id
      FROM section_room_assignments
      GROUP BY section_id
    ) t ON t.section_id = s.section_id
    JOIN section_room_assignments sra ON sra.assignment_id = t.latest_assignment_id
  ";
  $sections = $queryAll($sqlSections);
  if (!$sections) {
    echo json_encode(["success"=>true,"inserted"=>0,"skipped"=>0,"details"=>["reason"=>"no sections found with room"]]);
    exit();
  }
  $sectionRoom = [];
  foreach ($sections as $r) { $sectionRoom[(int)$r['section_id']] = (int)$r['room_id']; }

  // ---- seed combos ---------------------------------------------------------
  $sy  = $esc($schoolYear);
  $sem = $esc($semester);

  if ($seedFrom === 'section_subjects') {
    // optional curriculum-based seeding
    // expects: section_subjects(section_id, subj_id, prof_id, weekly_hours_needed) or similar
    // adjust table/column names as needed for your schema
    $sqlSeed = "
      SELECT ss.section_id, ss.subj_id, ss.prof_id,
             COALESCE(ss.weekly_hours_needed, COALESCE(s.subj_hours_per_week, 3)) AS weekly_hours_needed
      FROM section_subjects ss
      LEFT JOIN subjects s ON s.subj_id = ss.subj_id
      WHERE ss.school_year = '{$sy}' AND ss.semester = '{$sem}'
    ";
  } else {
    // default: seed from existing schedules (original behavior)
    $sqlSeed = "
      SELECT x.section_id, x.subj_id, x.prof_id, COALESCE(s.subj_hours_per_week, 3) AS weekly_hours_needed
      FROM (
        SELECT section_id, subj_id, prof_id
        FROM schedules
        WHERE school_year = '{$sy}' AND semester = '{$sem}'
        GROUP BY section_id, subj_id, prof_id
      ) x
      JOIN subjects s ON s.subj_id = x.subj_id
    ";
  }

  $assignments = $queryAll($sqlSeed);
  if (!$assignments) {
    echo json_encode([
      "success"=>true,
      "inserted"=>0,
      "skipped"=>0,
      "details"=>["reason"=>"no seed combos found for this term (source: {$seedFrom}); create a manual row or use section_subjects"]
    ]);
    exit();
  }

  // group by section
  $bySection = [];
  foreach ($assignments as $row) {
    $secId = (int)$row['section_id'];
    if (!isset($bySection[$secId])) $bySection[$secId] = [];
    $bySection[$secId][] = [
      "subj_id" => (int)$row['subj_id'],
      "prof_id" => (int)$row['prof_id'],
      "weekly_minutes" => max(0, (int)$row['weekly_hours_needed'] * 60),
    ];
  }

  // ---- transaction ----------------------------------------------------------
  $conn->begin_transaction();

  $inserted = 0;
  $skipped  = 0;
  $conflictDetails = [];
  $metrics = []; // diagnostics per (section, subj)

  foreach ($bySection as $sectionId => $asgList) {
    if ($requireRoom && !isset($sectionRoom[$sectionId])) { continue; }
    $roomId = (int)$sectionRoom[$sectionId];

    foreach ($asgList as $asg) {
      $subjId = (int)$asg['subj_id'];
      $profId = (int)$asg['prof_id'];

      $targetMinutes = min((int)$asg['weekly_minutes'], $subCapHrs * 60);
      if ($targetMinutes <= 0) continue;

      $sqlMinsBase = "
        SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time))/60),0)
        FROM schedules
        WHERE school_year = '{$sy}' AND semester = '{$sem}'
          AND section_id = {$sectionId} AND subj_id = {$subjId}
      ";
      $minsSoFar = $ignoreExisting ? 0 : (int)$queryScalar($sqlMinsBase, 0);
      $remaining = max(0, $targetMinutes - $minsSoFar);

      $generatedForThisPair = 0;

      if ($remaining > 0) {
        foreach ($days as $dayName) {
          if ($remaining <= 0) break;
          $dayKey = strtolower((string)$dayName);
          $dayEsc = $esc($dayKey);

          if ($maxDaily !== null) {
            $sqlDaily = "
              SELECT COUNT(*)
              FROM schedules
              WHERE school_year = '{$sy}' AND semester = '{$sem}'
                AND section_id = {$sectionId}
                AND JSON_CONTAINS(days, JSON_QUOTE('{$dayEsc}'))
            ";
            $dailyCnt = (int)$queryScalar($sqlDaily, 0);
            if ($dailyCnt >= $maxDaily) continue;
          }

          foreach ($slots as [$s, $e, $lenMins]) {
            if ($remaining <= 0) break;

            $sEsc = $esc($s);
            $eEsc = $esc($e);

            if ($enfSameSec) {
              $sqlCSec = "
                SELECT COUNT(*)
                FROM schedules
                WHERE school_year = '{$sy}' AND semester = '{$sem}' AND section_id = {$sectionId}
                  AND JSON_CONTAINS(days, JSON_QUOTE('{$dayEsc}'))
                  AND NOT (end_time <= '{$sEsc}' OR start_time >= '{$eEsc}')
              ";
              $cSec = (int)$queryScalar($sqlCSec, 0);
              if ($cSec > 0) { $skipped++; $conflictDetails[] = ["type"=>"section_conflict","section_id"=>$sectionId,"day"=>$dayKey,"start"=>$s,"end"=>$e]; continue; }
            }

            if ($enfProf) {
              $sqlCProf = "
                SELECT COUNT(*)
                FROM schedules
                WHERE school_year = '{$sy}' AND semester = '{$sem}' AND prof_id = {$profId}
                  AND JSON_CONTAINS(days, JSON_QUOTE('{$dayEsc}'))
                  AND NOT (end_time <= '{$sEsc}' OR start_time >= '{$eEsc}')
              ";
              $cProf = (int)$queryScalar($sqlCProf, 0);
              if ($cProf > 0) { $skipped++; $conflictDetails[] = ["type"=>"prof_conflict","prof_id"=>$profId,"day"=>$dayKey,"start"=>$s,"end"=>$e]; continue; }
            }

            // cap with proposed slot
            $currentMins = $ignoreExisting ? ($targetMinutes - $remaining) : (int)$queryScalar($sqlMinsBase, 0);
            if ($currentMins + $lenMins > $targetMinutes) continue;

            // idempotency guard (exact duplicate row)
            $sqlExists = "
              SELECT 1
              FROM schedules
              WHERE school_year = '{$sy}' AND semester = '{$sem}'
                AND section_id = {$sectionId} AND subj_id = {$subjId}
                AND start_time = '{$sEsc}' AND end_time = '{$eEsc}'
                AND JSON_LENGTH(days) = 1
                AND JSON_CONTAINS(days, JSON_QUOTE('{$dayEsc}'))
              LIMIT 1
            ";
            $already = (int)$queryScalar($sqlExists, 0);
            if ($already > 0) { $skipped++; continue; }

            $scheduleType = 'Onsite';
            $status = 'pending';
            $origin = 'auto';

            $sqlInsert = "
              INSERT INTO schedules
                (school_year, semester, subj_id, prof_id, schedule_type, start_time, end_time, room_id, section_id, days, status, origin)
              VALUES
                ('{$sy}', '{$sem}', {$subjId}, {$profId}, '{$esc($scheduleType)}', '{$sEsc}', '{$eEsc}', {$roomId}, {$sectionId},
                 JSON_ARRAY('{$dayEsc}'), '{$esc($status)}', '{$esc($origin)}')
            ";
            $ok = $conn->query($sqlInsert);
            if ($ok === false) throw new Exception("Insert failed: " . $conn->error);

            $inserted++;
            $generatedForThisPair += $lenMins;
            $remaining -= $lenMins;

            // refresh only when not ignoring existing
            if (!$ignoreExisting) {
              $sqlMinsBase = "
                SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time))/60),0)
                FROM schedules
                WHERE school_year = '{$sy}' AND semester = '{$sem}'
                  AND section_id = {$sectionId} AND subj_id = {$subjId}
              ";
            }
          } // slots
        } // days
      }

      $metrics[] = [
        "section_id"    => $sectionId,
        "subj_id"       => $subjId,
        "prof_id"       => $profId,
        "target_mins"   => $targetMinutes,
        "current_mins"  => $ignoreExisting ? 0 : $minsSoFar,
        "generated_mins"=> $generatedForThisPair,
        "remaining_after"=> max(0, ($targetMinutes - ($ignoreExisting ? $generatedForThisPair : ($minsSoFar + $generatedForThisPair))))
      ];
    }
  }

  $conn->commit();

  echo json_encode([
    "success"  => true,
    "inserted" => $inserted,
    "skipped"  => $skipped,
    "details"  => [
      "conflicts" => $conflictDetails,
      "params" => [
        "school_year" => $schoolYear,
        "semester" => $semester,
        "days" => $days,
        "startTime" => $startTime,
        "endTime" => $endTime,
        "slotMinutes" => $slotMin,
        "maxDailyLoad" => $maxDaily,
        "requireRoom" => true,
        "subjectWeeklyHourCap" => $subCapHrs,
        "ignoreExistingMinutes" => $ignoreExisting,
        "seedFrom" => $seedFrom
      ],
      "metrics" => $metrics
    ]
  ]);

} catch (Throwable $e) {
  if ($conn instanceof mysqli) {
    try { $conn->rollback(); } catch (\Throwable $ignore) {}
  }
  http_response_code(500);
  echo json_encode(["success"=>false,"message"=>$e->getMessage()]);
}
