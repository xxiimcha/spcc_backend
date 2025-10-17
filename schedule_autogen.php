<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/system_settings_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "Database connection not available"]);
  exit();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$esc = fn($v) => $conn->real_escape_string($v);
$qAll = function(string $sql) use ($conn) {
  $res = $conn->query($sql);
  if ($res === false) throw new Exception("Query failed: ".$conn->error);
  $out = [];
  while ($row = $res->fetch_assoc()) $out[] = $row;
  return $out;
};
$qOne = function(string $sql, $default = 0) use ($conn) {
  $res = $conn->query($sql);
  if ($res === false) throw new Exception("Query failed: ".$conn->error);
  $row = $res->fetch_row();
  return $row && isset($row[0]) ? $row[0] : $default;
};

try {
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw, true);
  if (!is_array($in)) throw new Exception('Invalid JSON body');

  // ------------------ School Year / Semester resolution (system_settings-first) ------------------
  $forceSystemSettings = array_key_exists('forceSystemSettings', $in) ? (bool)$in['forceSystemSettings'] : true;

  $computeCurrentSY = function(): string {
    $y = (int)date('Y');
    $m = (int)date('n'); // 1..12
    return ($m >= 6) ? sprintf('%d-%d', $y, $y + 1) : sprintf('%d-%d', $y - 1, $y);
  };

  $sys = ss_get_current_school_info($conn);        // ['school_year'=>?, 'semester'=>?]
  $sysSY  = $sys['school_year'] ?? null;
  $sysSem = $sys['semester'] ?? null;

  $inSY   = trim((string)($in['school_year'] ?? ''));
  $inSem  = trim((string)($in['semester'] ?? ''));

  if ($forceSystemSettings) {
    $schoolYear = $sysSY ?: $computeCurrentSY();
    $semester   = $sysSem ?: $inSem; // if system setting missing, fall back to input
  } else {
    $schoolYear = $inSY !== '' ? $inSY : ($sysSY ?: $computeCurrentSY());
    $semester   = $inSem !== '' ? $inSem : ($sysSem ?? '');
  }

  // ------------------ Other inputs ------------------
  $daysIn     = $in['days'] ?? [];
  $startTime  = trim((string)($in['startTime'] ?? '07:30'));
  $endTime    = trim((string)($in['endTime'] ?? '16:30'));
  $slotMin    = (int)($in['slotMinutes'] ?? 60);
  $secMaxDaily= isset($in['maxDailyLoad']) ? (int)$in['maxDailyLoad'] : null;
  $profMaxDaily= isset($in['profMaxDailyLoad']) ? (int)$in['profMaxDailyLoad'] : null;

  $enfSec     = (bool)($in['preventSameTimeSameSection'] ?? true);
  $enfProf    = (bool)($in['preventProfDoubleBooking'] ?? true);
  $subCapHrs  = (int)($in['subjectWeeklyHourCap'] ?? 4);
  $ignoreExisting = (bool)($in['ignoreExistingMinutes'] ?? false);
  $seedFrom   = strtolower((string)($in['seedFrom'] ?? 'assignments'));
  $lunchStart = trim((string)($in['lunchStart'] ?? '12:00'));
  $lunchEnd   = trim((string)($in['lunchEnd']   ?? '13:00'));
  $enforceLunch = ($lunchStart !== '' && $lunchEnd !== '');

  $addFixedBlocks = (bool)($in['addFixedBlocks'] ?? true);
  $fixedBlocks = [
    ['label' => 'Homeroom', 'start' => '07:30', 'end' => '08:00', 'status' => 'fixed'],
    ['label' => 'Recess',   'start' => '08:00', 'end' => '08:30', 'status' => 'fixed'],
  ];

  // Weekly cap (defaults to 8) — this is a cap on *rows* scheduled for a prof within the week
  $profMaxWeeklySchedules = isset($in['profMaxWeeklySchedules']) ? (int)$in['profMaxWeeklySchedules'] : 8;
  if ($profMaxWeeklySchedules <= 0) { $profMaxWeeklySchedules = 8; }

  // Minimum gap for same professor (minutes, default 10)
  $profMinGapMinutes = isset($in['profMinGapMinutes']) ? (int)$in['profMinGapMinutes'] : 10;
  if ($profMinGapMinutes < 0) $profMinGapMinutes = 0;

  // Avoid same subject at same time across different days
  $avoidSameTimeAcrossDays = (bool)($in['avoidSameTimeAcrossDays'] ?? true);

  // NEW: per-prof-per-subject distinct section load window (per week)
  $profPerSubjectSectionMax = isset($in['profPerSubjectSectionMax']) ? (int)$in['profPerSubjectSectionMax'] : 8; // hard cap
  $profPerSubjectSectionMin = isset($in['profPerSubjectSectionMin']) ? (int)$in['profPerSubjectSectionMin'] : 6; // soft target (report)

  // ------------------ Validation ------------------
  if ($schoolYear === '' || $semester === '') throw new Exception('Missing school_year or semester');
  if (!is_array($daysIn) || count($daysIn) === 0) throw new Exception('At least one active day is required');
  if ($slotMin <= 0) throw new Exception('slotMinutes must be positive');

  $sy  = $esc($schoolYear);
  $sem = $esc($semester);

  $toMin = function(string $t): int {
    if (!preg_match('/^\d{2}:\d{2}$/', $t)) return -1;
    [$h,$m] = explode(':',$t); return (int)$h*60+(int)$m;
  };
  $fromM = $toMin($startTime);
  $toM   = $toMin($endTime);
  if ($fromM < 0 || $toM < 0 || $fromM >= $toM) throw new Exception('Invalid start/end time window');

  // ------------------ Build slots ------------------
  $SLOTS = [];
  for ($m=$fromM; $m+$slotMin <= $toM; $m += $slotMin) {
    $sH=str_pad((string)intdiv($m,60),2,'0',STR_PAD_LEFT);
    $sN=str_pad((string)($m%60),2,'0',STR_PAD_LEFT);
    $e=$m+$slotMin;
    $eH=str_pad((string)intdiv($e,60),2,'0',STR_PAD_LEFT);
    $eN=str_pad((string)($e%60),2,'0',STR_PAD_LEFT);
    $SLOTS[] = [$sH.':'.$sN, $eH.':'.$eN, $slotMin];
  }
  $DAYS  = array_map(fn($d)=>strtolower((string)$d), $daysIn);
  $spanM = max(1, $toM - $fromM);

  $overlapsLunch = function(string $s, string $e) use ($enforceLunch, $lunchStart, $lunchEnd) {
    return $enforceLunch ? ($s < $lunchEnd && $e > $lunchStart) : false;
  };

  // ------------------ Section → latest room assignment ------------------
  $sectionRoom = [];
  foreach ($qAll("
      SELECT s.section_id, sra.room_id
      FROM sections s
      JOIN (
        SELECT section_id, MAX(assignment_id) AS latest_assignment_id
        FROM section_room_assignments GROUP BY section_id
      ) t ON t.section_id = s.section_id
      JOIN section_room_assignments sra ON sra.assignment_id = t.latest_assignment_id
  ") as $r) {
    $sectionRoom[(int)$r['section_id']] = (int)$r['room_id'];
  }

  $sectionInfo = [];
  foreach ($qAll("SELECT section_id, grade_level, strand FROM sections") as $r) {
    $sectionInfo[(int)$r['section_id']] = [
      'grade_level' => (string)$r['grade_level'],
      'strand'      => (string)$r['strand']
    ];
  }

  $subjectMeta = [];
  foreach ($qAll("SELECT subj_id, grade_level, strand FROM subjects") as $r) {
    $subjectMeta[(int)$r['subj_id']] = [
      'grade_level' => (string)$r['grade_level'],
      'strand'      => (string)$r['strand'],
      'hours'       => 4
    ];
  }

  // ------------------ Existing load in minutes (for balancing) ------------------
  $profLoad = [];
  foreach ($qAll("
    SELECT prof_id, COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(end_time,start_time))/60),0) AS m
    FROM schedules WHERE school_year='{$sy}' AND semester='{$sem}'
    GROUP BY prof_id
  ") as $r) $profLoad[(int)$r['prof_id']] = (int)$r['m'];
  foreach ($qAll("SELECT prof_id FROM professors") as $r) {
    $pid = (int)$r['prof_id'];
    if (!isset($profLoad[$pid])) $profLoad[$pid] = 0;
  }

  // ------------------ Weekly schedule COUNT per professor (cap) ------------------
  $profWeeklyCount = [];
  foreach ($qAll("
    SELECT prof_id, COUNT(*) AS c
    FROM schedules
    WHERE school_year='{$sy}' AND semester='{$sem}'
    GROUP BY prof_id
  ") as $r) {
    $profWeeklyCount[(int)$r['prof_id']] = (int)$r['c'];
  }
  foreach ($qAll("SELECT prof_id FROM professors") as $r) {
    $pid = (int)$r['prof_id'];
    if (!isset($profWeeklyCount[$pid])) $profWeeklyCount[$pid] = 0;
  }

  // ------------------ NEW: per-prof-per-subject distinct section counts ------------------
  // Counts how many DISTINCT sections a prof already handles for a given subject this SY/semester.
  $profSubjSecCount = []; // [prof_id][subj_id] => distinct section_count
  foreach ($qAll("
    SELECT prof_id, subj_id, COUNT(DISTINCT section_id) AS c
    FROM schedules
    WHERE school_year='{$sy}' AND semester='{$sem}'
      AND subj_id IS NOT NULL AND prof_id IS NOT NULL AND section_id IS NOT NULL
    GROUP BY prof_id, subj_id
  ") as $r) {
    $pid = (int)$r['prof_id'];
    $sid = (int)$r['subj_id'];
    $profSubjSecCount[$pid][$sid] = (int)$r['c'];
  }

  // ------------------ Subject → professors who can teach ------------------
  $canTeach = [];
  foreach ($qAll("SELECT prof_id, prof_subject_ids FROM professors") as $r) {
    $pid = (int)$r['prof_id'];
    $dec = [];
    if (!empty($r['prof_subject_ids'])) {
      $tmp = json_decode($r['prof_subject_ids'], true);
      if (is_array($tmp)) $dec = $tmp;
    }
    foreach ($dec as $sj) {
      $sj = (int)$sj; if ($sj<=0) continue;
      $canTeach[$sj][] = $pid;
    }
  }

  // ------------------ Build assignments ------------------
  $triplesBySection = [];
  $noEligible = [];

  // helper to check per-subject cap for a candidate prof
  $canTakeAnotherSectionForSubject = function(int $pid, int $subjId) use (&$profSubjSecCount, $profPerSubjectSectionMax): bool {
    $curr = $profSubjSecCount[$pid][$subjId] ?? 0;
    return $curr < $profPerSubjectSectionMax;
  };

  // Whenever we assign a prof to a NEW section for this subject, update the counter immediately
  $bumpProfSubjectSectionCount = function(int $pid, int $subjId) use (&$profSubjSecCount): void {
    if (!isset($profSubjSecCount[$pid])) $profSubjSecCount[$pid] = [];
    $profSubjSecCount[$pid][$subjId] = ($profSubjSecCount[$pid][$subjId] ?? 0) + 1;
  };

  if ($seedFrom === 'section_subjects') {
    foreach ($qAll("
      SELECT ss.section_id, ss.subj_id, ss.prof_id,
             ss.weekly_hours_needed AS wh
      FROM section_subjects ss
      WHERE ss.school_year='{$sy}' AND ss.semester='{$sem}'
    ") as $r) {
      $secId = (int)$r['section_id'];
      $subjId = (int)$r['subj_id'];
      $givenProf = $r['prof_id'] !== null ? (int)$r['prof_id'] : null;

      if (!isset($sectionInfo[$secId])) continue;
      if (!isset($subjectMeta[$subjId])) continue;

      $secGL = (string)$sectionInfo[$secId]['grade_level'];
      $secStr= strtolower((string)$sectionInfo[$secId]['strand']);
      $subGL = (string)$subjectMeta[$subjId]['grade_level'];
      $subStr= strtolower((string)$subjectMeta[$subjId]['strand']);

      if ($secGL !== $subGL || $secStr !== $subStr) continue;

      $hrs = isset($r['wh']) && $r['wh'] !== null && $r['wh'] !== '' ? (float)$r['wh'] : 4.0;
      $profId = $givenProf;

      if ($profId === null) {
        $cands = $canTeach[$subjId] ?? [];

        // Filter out professors already at or above the weekly row cap
        $cands = array_values(array_filter($cands, function($pid) use ($profWeeklyCount, $profMaxWeeklySchedules) {
          return ($profWeeklyCount[$pid] ?? 0) < $profMaxWeeklySchedules;
        }));

        // NEW: filter out professors who already reached per-subject section cap
        $cands = array_values(array_filter($cands, function($pid) use ($subjId, $canTakeAnotherSectionForSubject) {
          return $canTakeAnotherSectionForSubject($pid, $subjId);
        }));

        if (!$cands) { $noEligible[] = ["section_id"=>$secId,"subj_id"=>$subjId,"reason"=>"prof_subject_section_cap_reached"]; continue; }

        // Pick the candidate with the lowest current minutes load
        $best=null; $bestLoad=PHP_INT_MAX;
        foreach ($cands as $pid) {
          $l = $profLoad[$pid] ?? 0;
          if ($l < $bestLoad) { $best=$pid; $bestLoad=$l; }
        }
        if ($best===null) { $noEligible[] = ["section_id"=>$secId,"subj_id"=>$subjId,"reason"=>"no_candidate_after_filters"]; continue; }
        $profId = (int)$best;
      } else {
        // If a fixed professor is specified AND already at cap, skip this assignment entirely
        $overRowCap = (($profWeeklyCount[$profId] ?? 0) >= $profMaxWeeklySchedules);
        $overPerSubjCap = !$canTakeAnotherSectionForSubject($profId, $subjId);
        if ($overRowCap || $overPerSubjCap) {
          $noEligible[] = [
            "section_id"=>$secId,"subj_id"=>$subjId,"prof_id"=>$profId,
            "reason"=>$overRowCap ? "prof_weekly_cap_reached_fixed" : "prof_subject_section_cap_reached_fixed"
          ];
          continue;
        }
      }

      // NEW: increment per-subject section count now that we decided the prof for this section
      $bumpProfSubjectSectionCount($profId, $subjId);

      if (!isset($triplesBySection[$secId])) $triplesBySection[$secId]=[];
      $triplesBySection[$secId][] = [
        "subj_id" => $subjId,
        "prof_id" => $profId,
        "weekly_minutes" => max(0, (int)round($hrs * 60)),
      ];
    }
  } else {
    $sectionSubs = [];
    foreach ($qAll("SELECT section_id, subject_ids, grade_level, strand FROM sections") as $r) {
      $lst = [];
      if (!empty($r['subject_ids'])) {
        $dec = json_decode($r['subject_ids'], true);
        if (is_array($dec)) foreach ($dec as $v) { $n=(int)$v; if ($n>0) $lst[]=$n; }
      }
      $secId = (int)$r['section_id'];
      $secGL = (string)$r['grade_level'];
      $secStr= strtolower((string)$r['strand']);

      $lst = array_values(array_filter($lst, function($sid) use ($subjectMeta, $secGL, $secStr) {
        if (!isset($subjectMeta[$sid])) return false;
        $m = $subjectMeta[$sid];
        return ((string)$m['grade_level'] === $secGL) && (strtolower((string)$m['strand']) === $secStr);
      }));

      $sectionSubs[$secId] = $lst;
    }

    foreach ($sectionSubs as $secId => $subs) {
      if (!isset($triplesBySection[$secId])) $triplesBySection[$secId]=[];
      foreach ($subs as $subjId) {
        $cands = $canTeach[$subjId] ?? [];
        // Filter out professors at or above row cap
        $cands = array_values(array_filter($cands, function($pid) use ($profWeeklyCount, $profMaxWeeklySchedules) {
          return ($profWeeklyCount[$pid] ?? 0) < $profMaxWeeklySchedules;
        }));
        // Filter out professors at per-subject section cap
        $cands = array_values(array_filter($cands, function($pid) use ($subjId, $canTakeAnotherSectionForSubject) {
          return $canTakeAnotherSectionForSubject($pid, $subjId);
        }));
        if (!$cands) { $noEligible[] = ["section_id"=>$secId,"subj_id"=>$subjId,"reason"=>"prof_subject_section_cap_reached"]; continue; }
        $best=null; $bestLoad=PHP_INT_MAX;
        foreach ($cands as $pid) {
          $l = $profLoad[$pid] ?? 0;
          if ($l < $bestLoad) { $best=$pid; $bestLoad=$l; }
        }
        if ($best===null) { $noEligible[] = ["section_id"=>$secId,"subj_id"=>$subjId,"reason"=>"no_candidate_after_filters"]; continue; }

        $profId = (int)$best;
        // NEW: increment per-subject section count now that we decided the prof for this section
        $bumpProfSubjectSectionCount($profId, $subjId);

        $hrs = 4;
        $triplesBySection[$secId][] = [
          "subj_id" => (int)$subjId,
          "prof_id" => (int)$best,
          "weekly_minutes" => max(0, $hrs*60),
        ];
      }
    }
  }

  $any=false; foreach ($triplesBySection as $list) if (!empty($list)) { $any=true; break; }
  if (!$any) {
    echo json_encode(["success"=>true,"inserted"=>0,"skipped"=>0,"details"=>["reason"=>"no assignments found","noEligible"=>$noEligible]]);
    exit();
  }

  // ------------------ Helpers for loads & conflicts ------------------
  $secDayLoad = function(int $sec, string $day) use ($qOne,$sy,$sem) {
    return (int)$qOne("SELECT COUNT(*) FROM schedules
                       WHERE school_year='{$sy}' AND semester='{$sem}' AND section_id={$sec}
                         AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))",0);
  };
  $profDayLoad = function(int $pid, string $day) use ($qOne,$sy,$sem) {
    return (int)$qOne("SELECT COUNT(*) FROM schedules
                       WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id={$pid}
                         AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))",0);
  };
  $touchSameSubject = function(int $sec,int $subj,string $day,string $s,string $e) use ($qOne,$sy,$sem) {
    return (int)$qOne("SELECT COUNT(*) FROM schedules
                       WHERE school_year='{$sy}' AND semester='{$sem}'
                         AND section_id={$sec} AND subj_id={$subj}
                         AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                         AND (end_time='{$s}' OR start_time='{$e}')",0) > 0;
  };

  // Same subject at exactly same time on other days?
  $sameTimeOtherDay = function(int $sec,int $subj,string $day,string $s,string $e) use ($qOne,$sy,$sem) {
    return (int)$qOne("SELECT COUNT(*) FROM schedules
                       WHERE school_year='{$sy}' AND semester='{$sem}'
                         AND section_id={$sec} AND subj_id={$subj}
                         AND start_time='{$s}' AND end_time='{$e}'
                         AND NOT JSON_CONTAINS(days, JSON_QUOTE('{$day}'))",0) > 0;
  };

  // Penalize touching / tiny gaps for professors
  $profAdjScore = function(int $pid,string $day,string $s,string $e,int $slotMin) use ($qOne,$sy,$sem) {
    $touch = (int)$qOne("SELECT COUNT(*) FROM schedules
                         WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id={$pid}
                           AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                           AND (end_time='{$s}' OR start_time='{$e}')",0);
    $gapNeighbor = 0;
    $gapNeighbor += (int)$qOne("SELECT COUNT(*) FROM schedules
                        WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id={$pid}
                          AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                          AND TIMESTAMPDIFF(MINUTE, end_time, '{$s}') = {$slotMin}",0);
    $gapNeighbor += (int)$qOne("SELECT COUNT(*) FROM schedules
                        WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id={$pid}
                          AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                          AND TIMESTAMPDIFF(MINUTE, '{$e}', start_time) = {$slotMin}",0);

    $score = 0;
    if ($touch>0) $score -= 100;
    if ($gapNeighbor>0) $score -= 25*$gapNeighbor;
    return $score;
  };

  // ------------------ Insert fixed blocks ------------------
  $ensureFixed = function(int $sectionId, ?int $roomId, string $day, string $label, string $s, string $e, string $status='fixed') use ($conn, $esc, $sy, $sem) {
    $sE    = $esc($s);
    $eE    = $esc($e);
    $dayE  = $esc($day);
    $labE  = $esc($label);
    $statusE = $esc($status);
    $roomSql = $roomId === null ? "NULL" : (string)((int)$roomId);

    $existsSql = "
      SELECT 1 FROM schedules
      WHERE school_year='{$sy}' AND semester='{$sem}'
        AND section_id={$sectionId}
        AND subj_id IS NULL AND prof_id IS NULL
        AND schedule_type='{$labE}'
        AND start_time='{$sE}' AND end_time='{$eE}'
        AND JSON_LENGTH(days)=1
        AND JSON_CONTAINS(days, JSON_QUOTE('{$dayE}'))
      LIMIT 1";
    $exists = $conn->query($existsSql);
    if ($exists && $exists->fetch_row()) return false;

    $insSql = "
      INSERT INTO schedules
        (school_year, semester, subj_id, prof_id, schedule_type, start_time, end_time, room_id, section_id, days, status, origin)
      VALUES
        ('{$sy}','{$sem}', NULL, NULL, '{$labE}', '{$sE}', '{$eE}', {$roomSql}, {$sectionId}, JSON_ARRAY('{$dayE}'), '{$statusE}', 'auto-default')";
    $ok = $conn->query($insSql);
    if ($ok === false) throw new Exception('Insert fixed block failed: '.$conn->error);
    return true;
  };

  $conn->begin_transaction();

  $fixedInserted = 0;
  if ($addFixedBlocks) {
    $sectionsNeedingBlocks = array_keys($triplesBySection);
    foreach ($sectionsNeedingBlocks as $secId) {
      $roomId = $sectionRoom[$secId] ?? null;
      foreach ($DAYS as $day) {
        foreach ($fixedBlocks as $fb) {
          if ($fb['start'] < $endTime && $fb['end'] > $startTime) {
            if ($ensureFixed((int)$secId, $roomId, (string)$day, (string)$fb['label'], (string)$fb['start'], (string)$fb['end'], (string)$fb['status'])) {
              $fixedInserted++;
            }
          }
        }
      }
    }
  }

  // ------------------ Generate schedules ------------------
  $inserted=0; $skipped=0; $conflicts=[]; $metrics=[];
  foreach ($triplesBySection as $secId => $rows) {
    $hasRoom = array_key_exists($secId, $sectionRoom);
    $roomId  = $hasRoom ? (int)$sectionRoom[$secId] : null;

    foreach ($rows as $t) {
      $subjId = (int)$t['subj_id'];
      $profId = (int)$t['prof_id'];

      // Weekly cap (row-level)
      if (($profWeeklyCount[$profId] ?? 0) >= $profMaxWeeklySchedules) {
        $skipped++;
        $conflicts[] = ["type"=>"prof_weekly_cap", "prof_id"=>$profId, "section_id"=>$secId, "subj_id"=>$subjId];
        continue;
      }

      // Redundant guard: ensure we still respect per-subject section cap at insertion time
      $currSecForSubj = $profSubjSecCount[$profId][$subjId] ?? 0;
      if ($currSecForSubj > $profPerSubjectSectionMax) {
        // This shouldn't happen since we bumped on assignment; skip to be safe
        $skipped++;
        $conflicts[] = ["type"=>"prof_subject_section_cap_insert_guard", "prof_id"=>$profId, "section_id"=>$secId, "subj_id"=>$subjId];
        continue;
      }

      $target = min((int)$t['weekly_minutes'], $subCapHrs*60);
      if ($target <= 0) continue;

      $baseMinsSQL = "SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(end_time,start_time))/60),0)
                      FROM schedules
                      WHERE school_year='{$sy}' AND semester='{$sem}'
                        AND section_id={$secId} AND subj_id={$subjId}";
      $done = $ignoreExisting ? 0 : (int)$qOne($baseMinsSQL,0);
      $remain = max(0, $target - $done);
      $genForPair = 0;

      while ($remain > 0) {
        // Re-check weekly cap before each row
        if (($profWeeklyCount[$profId] ?? 0) >= $profMaxWeeklySchedules) {
          $conflicts[] = ["type"=>"prof_weekly_cap_reached", "prof_id"=>$profId, "section_id"=>$secId, "subj_id"=>$subjId];
          break;
        }

        $best = null;
        foreach ($DAYS as $day) {
          if ($secMaxDaily !== null && $secDayLoad($secId,$day) >= $secMaxDaily) continue;
          if ($profMaxDaily !== null && $profDayLoad($profId,$day) >= $profMaxDaily) continue;

          foreach ($SLOTS as [$s,$e,$len]) {
            if ($remain <= 0) break;
            if ($overlapsLunch($s,$e)) continue;

            $sE = $esc($s); $eE = $esc($e);

            // Section-time overlap
            if ($enfSec) {
              $c = (int)$qOne("SELECT COUNT(*) FROM schedules
                               WHERE school_year='{$sy}' AND semester='{$sem}' AND section_id={$secId}
                                 AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                                 AND NOT (end_time<='{$sE}' OR start_time>='{$eE}')",0);
              if ($c>0) { $skipped++; $conflicts[]=["type"=>"section","section_id"=>$secId,"day"=>$day,"s"=>$s,"e"=>$e]; continue; }
            }

            // Professor overlap
            if ($enfProf) {
              $c = (int)$qOne("SELECT COUNT(*) FROM schedules
                               WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id={$profId}
                                 AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                                 AND NOT (end_time<='{$sE}' OR start_time>='{$eE}')",0);
              if ($c>0) { $skipped++; $conflicts[]=["type"=>"prof","prof_id"=>$profId,"day"=>$day,"s"=>$s,"e"=>$e]; continue; }

              // Professor minimum gap
              if ($profMinGapMinutes > 0) {
                $g = (int)$qOne("
                  SELECT COUNT(*) FROM schedules
                    WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id={$profId}
                      AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                      AND NOT (
                        end_time <= SUBTIME('{$sE}', SEC_TO_TIME({$profMinGapMinutes}*60))
                        OR start_time >= ADDTIME('{$eE}', SEC_TO_TIME({$profMinGapMinutes}*60))
                      )
                ",0);
                if ($g>0) {
                  $skipped++;
                  $conflicts[]=["type"=>"prof_gap","prof_id"=>$profId,"day"=>$day,"s"=>$s,"e"=>$e,"minGap"=>$profMinGapMinutes];
                  continue;
                }
              }
            }

            // Avoid same subject at the same time across different days
            if ($avoidSameTimeAcrossDays && $sameTimeOtherDay($secId, $subjId, $day, $s, $e)) {
              $skipped++;
              $conflicts[] = ["type"=>"subject_same_time_pattern","section_id"=>$secId,"subj_id"=>$subjId,"day"=>$day,"s"=>$s,"e"=>$e];
              continue;
            }

            // Subject weekly target within this section
            $curr = $ignoreExisting ? ($target - $remain) : (int)$qOne($baseMinsSQL,0);
            if ($curr + $len > $target) continue;

            // Avoid duplicates for this section+subject+exact slot (same day)
            $dup = (int)$qOne("SELECT 1 FROM schedules
                               WHERE school_year='{$sy}' AND semester='{$sem}'
                                 AND section_id={$secId} AND subj_id={$subjId}
                                 AND start_time='{$sE}' AND end_time='{$eE}'
                                 AND JSON_LENGTH(days)=1
                                 AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                               LIMIT 1",0);
            if ($dup>0) { $skipped++; continue; }

            // Score (load balance + spacing preference)
            $score=0;
            $secLoad = $secDayLoad($secId,$day);
            $minLoad = PHP_INT_MAX;
            foreach ($DAYS as $d) { $minLoad = min($minLoad, $secDayLoad($secId,$d)); }
            if ($secLoad === $minLoad) $score += 40;

            $sM = (int)substr($s,0,2)*60 + (int)substr($s,3,2);
            $early = 1.0 - (($sM - $fromM)/$spanM);
            $score += (int)round(10*$early);

            if ($touchSameSubject($secId,$subjId,$day,$s,$e)) $score -= 60;
            $score += $profAdjScore($profId,$day,$s,$e,$slotMin);
            $score += -8 * $secLoad;
            $score += -5 * $profDayLoad($profId,$day);

            if ($best===null || $score > $best['score'])
              $best = ['day'=>$day,'s'=>$s,'e'=>$e,'len'=>$len,'score'=>$score];
          }
        }

        if ($best === null) break;

        // Online if section has no room, else Onsite
        $scheduleType = $hasRoom ? 'Onsite' : 'Online';
        $status='pending'; $origin='auto';
        $sE=$esc($best['s']); $eE=$esc($best['e']);
        $schTypeE = $esc($scheduleType);
        $statusE  = $esc($status);
        $originE  = $esc($origin);
        $roomSql  = $roomId === null ? "NULL" : (string)((int)$roomId);

        $ok = $conn->query("
          INSERT INTO schedules
            (school_year, semester, subj_id, prof_id, schedule_type, start_time, end_time, room_id, section_id, days, status, origin)
          VALUES
            ('{$sy}','{$sem}', {$subjId}, {$profId}, '{$schTypeE}', '{$sE}', '{$eE}', {$roomSql}, {$secId},
             JSON_ARRAY('{$best['day']}'), '{$statusE}', '{$originE}')
        ");
        if ($ok === false) throw new Exception("Insert failed: ".$conn->error);

        $inserted++;
        $genForPair += $best['len'];
        $remain     -= $best['len'];

        // Increment in-memory weekly count for the professor
        $profWeeklyCount[$profId] = ($profWeeklyCount[$profId] ?? 0) + 1;

        if (!$ignoreExisting) {
          $baseMinsSQL = "SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(end_time,start_time))/60),0)
                          FROM schedules
                          WHERE school_year='{$sy}' AND semester='{$sem}'
                            AND section_id={$secId} AND subj_id={$subjId}";
        }
      }

      $metrics[] = [
        "section_id"=>$secId,"subj_id"=>$subjId,"prof_id"=>$profId,
        "target_mins"=>$target,"generated_mins"=>$genForPair,
        "remaining_after"=>max(0, $target - ($ignoreExisting ? $genForPair : ((int)$qOne($baseMinsSQL,0))))
      ];
    }
  }

  $conn->commit();

  // ------------------ Post summary: under-minimum pairs ------------------
  // Recalculate final per-prof-per-subject distinct section counts after inserts
  $finalProfSubjCounts = [];
  foreach ($qAll("
    SELECT prof_id, subj_id, COUNT(DISTINCT section_id) AS c
    FROM schedules
    WHERE school_year='{$sy}' AND semester='{$sem}'
      AND subj_id IS NOT NULL AND prof_id IS NOT NULL AND section_id IS NOT NULL
    GROUP BY prof_id, subj_id
  ") as $r) {
    $pid = (int)$r['prof_id']; $sid = (int)$r['subj_id']; $c = (int)$r['c'];
    $finalProfSubjCounts[] = ["prof_id"=>$pid, "subj_id"=>$sid, "sections"=>$c];
  }
  $underMinPairs = array_values(array_filter($finalProfSubjCounts, function($row) use ($profPerSubjectSectionMin) {
    return ($row['sections'] ?? 0) > 0 && $row['sections'] < $profPerSubjectSectionMin;
  }));

  echo json_encode([
    "success"=>true,
    "inserted"=>$inserted,
    "skipped"=>$skipped,
    "details"=>[
      "conflicts"=>$conflicts,
      "noEligible"=>$noEligible,
      "fixedInserted"=>$fixedInserted,
      "params"=>[
        "school_year"=>$schoolYear,
        "semester"=>$semester,
        "source"=>[
          "forceSystemSettings"=>$forceSystemSettings,
          "system_settings"=>["school_year"=>$sysSY, "semester"=>$sysSem]
        ],
        "days"=>$DAYS,
        "startTime"=>$startTime,
        "endTime"=>$endTime,
        "slotMinutes"=>$slotMin,
        "maxDailyLoad"=>$secMaxDaily,
        "profMaxDailyLoad"=>$profMaxDaily,
        "subjectWeeklyHourCap"=>$subCapHrs,
        "ignoreExistingMinutes"=>$ignoreExisting,
        "seedFrom"=>$seedFrom,
        "lunchStart"=>$lunchStart,
        "lunchEnd"=>$lunchEnd,
        "addFixedBlocks"=>$addFixedBlocks,
        "profMaxWeeklySchedules"=>$profMaxWeeklySchedules,
        "profMinGapMinutes"=>$profMinGapMinutes,
        "avoidSameTimeAcrossDays"=>$avoidSameTimeAcrossDays,
        "profPerSubjectSectionMax"=>$profPerSubjectSectionMax,
        "profPerSubjectSectionMin"=>$profPerSubjectSectionMin
      ],
      "metrics"=>$metrics,
      "perSubjectSectionCounts"=>$finalProfSubjCounts,
      "underMinPairs"=>$underMinPairs
    ]
  ]);

} catch (Throwable $e) {
  try { $conn->rollback(); } catch (Throwable $ignored) {}
  http_response_code(500);
  echo json_encode(["success"=>false,"message"=>$e->getMessage()]);
}
