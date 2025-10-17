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

  $forceSystemSettings = array_key_exists('forceSystemSettings', $in) ? (bool)$in['forceSystemSettings'] : true;

  $computeCurrentSY = function(): string {
    $y = (int)date('Y');
    $m = (int)date('n');
    return ($m >= 6) ? sprintf('%d-%d', $y, $y + 1) : sprintf('%d-%d', $y - 1, $y);
  };

  $sys = ss_get_current_school_info($conn);
  $sysSY  = $sys['school_year'] ?? null;
  $sysSem = $sys['semester'] ?? null;

  $inSY   = trim((string)($in['school_year'] ?? ''));
  $inSem  = trim((string)($in['semester'] ?? ''));

  if ($forceSystemSettings) {
    $schoolYear = $sysSY ?: $computeCurrentSY();
    $semester   = $sysSem ?: $inSem;
  } else {
    $schoolYear = $inSY !== '' ? $inSY : ($sysSY ?: $computeCurrentSY());
    $semester   = $inSem !== '' ? $inSem : ($sysSem ?? '');
  }

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

  $profMaxWeeklySchedules = isset($in['profMaxWeeklySchedules']) ? (int)$in['profMaxWeeklySchedules'] : 8;
  if ($profMaxWeeklySchedules <= 0) { $profMaxWeeklySchedules = 8; }

  $profMinGapMinutes = isset($in['profMinGapMinutes']) ? (int)$in['profMinGapMinutes'] : 10;
  if ($profMinGapMinutes < 0) $profMinGapMinutes = 0;

  $avoidSameTimeAcrossDays = (bool)($in['avoidSameTimeAcrossDays'] ?? true);

  $profPerSubjectSectionMax = isset($in['profPerSubjectSectionMax']) ? (int)$in['profPerSubjectSectionMax'] : 8;
  $profPerSubjectSectionMin = isset($in['profPerSubjectSectionMin']) ? (int)$in['profPerSubjectSectionMin'] : 6;

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

  // Remove 13:30–14:30, force 13:00–14:00
  $SLOTS = array_values(array_filter($SLOTS, function($slt) {
    return !($slt[0] === '13:30' && $slt[1] === '14:30');
  }));
  $has1300 = false;
  foreach ($SLOTS as $slt) if ($slt[0]==='13:00' && $slt[1]==='14:00') { $has1300=true; break; }
  if (!$has1300) {
    $slot13s = 13*60; $slot13e = 14*60;
    if ($slot13s >= $fromM && $slot13e <= $toM && !$overlapsLunch('13:00','14:00')) {
      $SLOTS[] = ['13:00','14:00',60];
    }
  }
  usort($SLOTS, fn($a,$b)=>strcmp($a[0],$b[0]));

  // Section → latest room
  $sectionRoom = [];
  foreach ($qAll("
    SELECT s.section_id, sra.room_id
    FROM sections s
    JOIN (
      SELECT section_id, MAX(assignment_id) AS latest_assignment_id
      FROM section_room_assignments GROUP BY section_id
    ) t ON t.section_id = s.section_id
    JOIN section_room_assignments sra ON sra.assignment_id = t.latest_assignment_id
  ") as $r) $sectionRoom[(int)$r['section_id']] = (int)$r['room_id'];

  $sectionInfo = [];
  foreach ($qAll("SELECT section_id, grade_level, strand FROM sections") as $r) {
    $sectionInfo[(int)$r['section_id']] = [
      'grade_level'=>(string)$r['grade_level'],
      'strand'=>strtolower((string)$r['strand'])
    ];
  }

  // Subject meta (with code for PEH)
  $subjectMeta = [];
  foreach ($qAll("SELECT subj_id, subj_code, grade_level, strand FROM subjects") as $r) {
    $subjectMeta[(int)$r['subj_id']] = [
      'code'=>(string)$r['subj_code'],
      'grade_level'=>(string)$r['grade_level'],
      'strand'=>strtolower((string)$r['strand']),
      'hours'=>4
    ];
  }
  $subjectIsPEH = function(int $sid) use ($subjectMeta): bool {
    $code = $subjectMeta[$sid]['code'] ?? '';
    return strncasecmp($code, 'PEH', 3) === 0;
  };

  // Existing load minutes / weekly row counts
  $profLoad = [];
  foreach ($qAll("
    SELECT prof_id, COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(end_time,start_time))/60),0) AS m
    FROM schedules WHERE school_year='{$sy}' AND semester='{$sem}'
    GROUP BY prof_id
  ") as $r) $profLoad[(int)$r['prof_id']] = (int)$r['m'];
  foreach ($qAll("SELECT prof_id FROM professors") as $r) { $pid=(int)$r['prof_id']; if(!isset($profLoad[$pid])) $profLoad[$pid]=0; }

  $profWeeklyCount = [];
  foreach ($qAll("
    SELECT prof_id, COUNT(*) AS c FROM schedules
    WHERE school_year='{$sy}' AND semester='{$sem}'
    GROUP BY prof_id
  ") as $r) $profWeeklyCount[(int)$r['prof_id']] = (int)$r['c'];
  foreach ($qAll("SELECT prof_id FROM professors") as $r) { $pid=(int)$r['prof_id']; if(!isset($profWeeklyCount[$pid])) $profWeeklyCount[$pid]=0; }

  // Per-prof-per-subject distinct section counts
  $profSubjSecCount = [];
  foreach ($qAll("
    SELECT prof_id, subj_id, COUNT(DISTINCT section_id) AS c
    FROM schedules
    WHERE school_year='{$sy}' AND semester='{$sem}'
      AND subj_id IS NOT NULL AND prof_id IS NOT NULL AND section_id IS NOT NULL
    GROUP BY prof_id, subj_id
  ") as $r) { $profSubjSecCount[(int)$r['prof_id']][(int)$r['subj_id']] = (int)$r['c']; }

  // Subject → list of profs who can teach (from professors.prof_subject_ids)
  $canTeach = [];
  foreach ($qAll("SELECT prof_id, prof_subject_ids FROM professors") as $r) {
    $pid = (int)$r['prof_id'];
    $ids = [];
    if (!empty($r['prof_subject_ids'])) {
      $tmp = json_decode($r['prof_subject_ids'], true);
      if (is_array($tmp)) $ids = array_map('intval', $tmp);
    }
    foreach ($ids as $sj) { if ($sj>0) $canTeach[$sj][] = $pid; }
  }

  // -------- NEW: Lock one professor per (section, subject) --------
  // Priority 1: section_subjects.prof_id (current SY/sem)
  $fixedProfForPair = []; // [section_id][subj_id] => prof_id
  foreach ($qAll("
    SELECT section_id, subj_id, prof_id
    FROM section_subjects
    WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id IS NOT NULL
  ") as $r) {
    $fixedProfForPair[(int)$r['section_id']][(int)$r['subj_id']] = (int)$r['prof_id'];
  }
  // Priority 2: existing schedules for the same SY/sem (most rows wins)
  $existCounts = []; // [sec][subj] => ['prof_id'=>..., 'c'=>...]
  foreach ($qAll("
    SELECT section_id, subj_id, prof_id, COUNT(*) AS c
    FROM schedules
    WHERE school_year='{$sy}' AND semester='{$sem}'
      AND section_id IS NOT NULL AND subj_id IS NOT NULL AND prof_id IS NOT NULL
    GROUP BY section_id, subj_id, prof_id
  ") as $r) {
    $sec=(int)$r['section_id']; $sub=(int)$r['subj_id']; $pid=(int)$r['prof_id']; $c=(int)$r['c'];
    if (isset($fixedProfForPair[$sec][$sub])) continue; // section_subjects takes priority
    $curr = $existCounts[$sec][$sub]['c'] ?? -1;
    if ($c > $curr) {
      $existCounts[$sec][$sub] = ['prof_id'=>$pid,'c'=>$c];
      $fixedProfForPair[$sec][$sub] = $pid;
    }
  }

  // Helpers
  $canTakeAnotherSectionForSubject = function(int $pid, int $subjId) use (&$profSubjSecCount, $profPerSubjectSectionMax): bool {
    $curr = $profSubjSecCount[$pid][$subjId] ?? 0;
    return $curr < $profPerSubjectSectionMax;
  };
  $bumpProfSubjectSectionCount = function(int $pid, int $subjId) use (&$profSubjSecCount): void {
    if (!isset($profSubjSecCount[$pid])) $profSubjSecCount[$pid] = [];
    $profSubjSecCount[$pid][$subjId] = ($profSubjSecCount[$pid][$subjId] ?? 0) + 1;
  };

  // -------- Build assignments (triples) --------
  $triplesBySection = [];
  $noEligible = [];

  if ($seedFrom === 'section_subjects') {
    foreach ($qAll("
      SELECT ss.section_id, ss.subj_id, ss.prof_id, ss.weekly_hours_needed AS wh
      FROM section_subjects ss
      WHERE ss.school_year='{$sy}' AND ss.semester='{$sem}'
    ") as $r) {
      $secId = (int)$r['section_id'];
      $subjId = (int)$r['subj_id'];
      if (!isset($sectionInfo[$secId]) || !isset($subjectMeta[$subjId])) continue;

      $secGL = $sectionInfo[$secId]['grade_level']; $secStr = $sectionInfo[$secId]['strand'];
      $subGL = $subjectMeta[$subjId]['grade_level']; $subStr = $subjectMeta[$subjId]['strand'];
      if ($secGL !== $subGL || $secStr !== $subStr) continue;

      $hrs = isset($r['wh']) && $r['wh'] !== null && $r['wh'] !== '' ? (float)$r['wh'] : 4.0;
      if ($subjectIsPEH($subjId)) $hrs = 1.0;

      // Choose prof: lock order → explicit section_subjects → existing schedules → otherwise assign
      $profId = null;
      if (!empty($r['prof_id'])) {
        $profId = (int)$r['prof_id']; // explicit lock
        $lockProf = true;
      } elseif (isset($fixedProfForPair[$secId][$subjId])) {
        $profId = (int)$fixedProfForPair[$secId][$subjId];
        $lockProf = true;
      } else {
        $lockProf = false;
        $cands = $canTeach[$subjId] ?? [];
        $cands = array_values(array_filter($cands, function($pid) use ($profWeeklyCount, $profMaxWeeklySchedules) {
          return ($profWeeklyCount[$pid] ?? 0) < $profMaxWeeklySchedules;
        }));
        $cands = array_values(array_filter($cands, function($pid) use ($subjId, $canTakeAnotherSectionForSubject) {
          return $canTakeAnotherSectionForSubject($pid, $subjId);
        }));
        if (!$cands) { $noEligible[] = ["section_id"=>$secId,"subj_id"=>$subjId,"reason"=>"no_candidates"]; continue; }
        $best=null; $bestLoad=PHP_INT_MAX;
        foreach ($cands as $pid) { $l=$profLoad[$pid] ?? 0; if ($l<$bestLoad){$best=$pid;$bestLoad=$l;} }
        $profId = (int)$best;
      }

      // If we just decided a NEW professor (not locked from existing), bump per-subject section count
      if (!$lockProf) $bumpProfSubjectSectionCount($profId, $subjId);

      if (!isset($triplesBySection[$secId])) $triplesBySection[$secId]=[];
      $triplesBySection[$secId][] = [
        "subj_id"=>$subjId,
        "prof_id"=>$profId,
        "weekly_minutes"=>$subjectIsPEH($subjId)?60:max(0,(int)round($hrs*60)),
        "lock_prof"=>$lockProf ? 1 : 0
      ];
    }
  } else {
    // From sections.subject_ids
    $sectionSubs = [];
    foreach ($qAll("SELECT section_id, subject_ids, grade_level, strand FROM sections") as $r) {
      $secId=(int)$r['section_id'];
      $secGL=(string)$r['grade_level']; $secStr=strtolower((string)$r['strand']);
      $lst=[];
      if (!empty($r['subject_ids'])) {
        $dec=json_decode($r['subject_ids'],true);
        if (is_array($dec)) foreach ($dec as $v){ $n=(int)$v; if($n>0)$lst[]=$n; }
      }
      $lst = array_values(array_filter($lst, function($sid) use ($subjectMeta,$secGL,$secStr){
        if (!isset($subjectMeta[$sid])) return false;
        $m=$subjectMeta[$sid];
        return ((string)$m['grade_level']===$secGL) && ((string)$m['strand']===$secStr);
      }));
      $sectionSubs[$secId]=$lst;
    }

    foreach ($sectionSubs as $secId=>$subs) {
      if (!isset($triplesBySection[$secId])) $triplesBySection[$secId]=[];
      foreach ($subs as $subjId) {
        $hrs = $subjectIsPEH($subjId) ? 1 : 4;

        // Try lock: section_subjects / existing schedules
        if (isset($fixedProfForPair[$secId][$subjId])) {
          $profId = (int)$fixedProfForPair[$secId][$subjId];
          $lockProf = 1;
        } else {
          $lockProf = 0;
          $cands = $canTeach[$subjId] ?? [];
          $cands = array_values(array_filter($cands, function($pid) use ($profWeeklyCount, $profMaxWeeklySchedules) {
            return ($profWeeklyCount[$pid] ?? 0) < $profMaxWeeklySchedules;
          }));
          $cands = array_values(array_filter($cands, function($pid) use ($subjId, $canTakeAnotherSectionForSubject) {
            return $canTakeAnotherSectionForSubject($pid, $subjId);
          }));
          if (!$cands) { $noEligible[]=["section_id"=>$secId,"subj_id"=>$subjId,"reason"=>"no_candidates"]; continue; }
          $best=null; $bestLoad=PHP_INT_MAX;
          foreach ($cands as $pid){ $l=$profLoad[$pid] ?? 0; if($l<$bestLoad){$best=$pid;$bestLoad=$l;} }
          $profId=(int)$best;
          $bumpProfSubjectSectionCount($profId, $subjId);
        }

        $triplesBySection[$secId][] = [
          "subj_id"=>$subjId,
          "prof_id"=>$profId,
          "weekly_minutes"=>$subjectIsPEH($subjId)?60:max(0,$hrs*60),
          "lock_prof"=>$lockProf
        ];
      }
    }
  }

  $any=false; foreach ($triplesBySection as $list) if (!empty($list)) { $any=true; break; }
  if (!$any) {
    echo json_encode(["success"=>true,"inserted"=>0,"skipped"=>0,"details"=>["reason"=>"no assignments found","noEligible"=>$noEligible]]);
    exit();
  }

  // Helpers for loads & conflicts
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
  $sameTimeOtherDay = function(int $sec,int $subj,string $day,string $s,string $e) use ($qOne,$sy,$sem) {
    return (int)$qOne("SELECT COUNT(*) FROM schedules
                       WHERE school_year='{$sy}' AND semester='{$sem}'
                         AND section_id={$sec} AND subj_id={$subj}
                         AND start_time='{$s}' AND end_time='{$e}'
                         AND NOT JSON_CONTAINS(days, JSON_QUOTE('{$day}'))",0) > 0;
  };
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

  // Fixed blocks (for ALL sections)
  $ensureFixed = function(int $sectionId, ?int $roomId, string $day, string $label, string $s, string $e, string $status='fixed') use ($conn, $esc, $sy, $sem) {
    $sE=$esc($s); $eE=$esc($e); $dayE=$esc($day); $labE=$esc($label); $statusE=$esc($status);
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
    $sectionsNeedingBlocks = array_keys($sectionInfo);
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

  // Generate schedules
  $inserted=0; $skipped=0; $conflicts=[]; $metrics=[];
  foreach ($triplesBySection as $secId => $rows) {
    $hasRoom = array_key_exists($secId, $sectionRoom);
    $roomId  = $hasRoom ? (int)$sectionRoom[$secId] : null;

    foreach ($rows as $t) {
      $subjId = (int)$t['subj_id'];
      $profId = (int)$t['prof_id'];
      $lockProf = !empty($t['lock_prof']);

      // If prof is locked for this (section, subject), ignore weekly cap at selection stage
      if (!$lockProf && (($profWeeklyCount[$profId] ?? 0) >= $profMaxWeeklySchedules)) {
        $skipped++; $conflicts[]=["type"=>"prof_weekly_cap","prof_id"=>$profId,"section_id"=>$secId,"subj_id"=>$subjId]; continue;
      }

      // Redundant guard for per-subject-section cap — skip ONLY when not locked
      $currSecForSubj = $profSubjSecCount[$profId][$subjId] ?? 0;
      if (!$lockProf && $currSecForSubj > $profPerSubjectSectionMax) {
        $skipped++; $conflicts[]=["type"=>"prof_subject_section_cap_insert_guard","prof_id"=>$profId,"section_id"=>$secId,"subj_id"=>$subjId]; continue;
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
        // Weekly cap check only if NOT locked
        if (!$lockProf && (($profWeeklyCount[$profId] ?? 0) >= $profMaxWeeklySchedules)) {
          $conflicts[] = ["type"=>"prof_weekly_cap_reached","prof_id"=>$profId,"section_id"=>$secId,"subj_id"=>$subjId];
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

            if ($enfSec) {
              $c = (int)$qOne("SELECT COUNT(*) FROM schedules
                               WHERE school_year='{$sy}' AND semester='{$sem}' AND section_id={$secId}
                                 AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                                 AND NOT (end_time<='{$sE}' OR start_time>='{$eE}')",0);
              if ($c>0) { $skipped++; $conflicts[]=["type"=>"section","section_id"=>$secId,"day"=>$day,"s"=>$s,"e"=>$e]; continue; }
            }

            if ($enfProf) {
              $c = (int)$qOne("SELECT COUNT(*) FROM schedules
                               WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id={$profId}
                                 AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                                 AND NOT (end_time<='{$sE}' OR start_time>='{$eE}')",0);
              if ($c>0) { $skipped++; $conflicts[]=["type"=>"prof","prof_id"=>$profId,"day"=>$day,"s"=>$s,"e"=>$e]; continue; }

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
                if ($g>0) { $skipped++; $conflicts[]=["type"=>"prof_gap","prof_id"=>$profId,"day"=>$day,"s"=>$s,"e"=>$e,"minGap"=>$profMinGapMinutes]; continue; }
              }
            }

            if ($avoidSameTimeAcrossDays && $sameTimeOtherDay($secId, $subjId, $day, $s, $e)) {
              $skipped++; $conflicts[] = ["type"=>"subject_same_time_pattern","section_id"=>$secId,"subj_id"=>$subjId,"day"=>$day,"s"=>$s,"e"=>$e];
              continue;
            }

            $curr = $ignoreExisting ? ($target - $remain) : (int)$qOne($baseMinsSQL,0);
            if ($curr + $len > $target) continue;

            $dup = (int)$qOne("SELECT 1 FROM schedules
                               WHERE school_year='{$sy}' AND semester='{$sem}'
                                 AND section_id={$secId} AND subj_id={$subjId}
                                 AND start_time='{$sE}' AND end_time='{$eE}'
                                 AND JSON_LENGTH(days)=1
                                 AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                               LIMIT 1",0);
            if ($dup>0) { $skipped++; continue; }

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

        $scheduleType = $hasRoom ? 'Onsite' : 'Online';
        $status='pending'; $origin='auto';
        $sE=$esc($best['s']); $eE=$esc($best['e']);
        $schTypeE=$esc($scheduleType); $statusE=$esc($status); $originE=$esc($origin);
        $roomSql = $roomId === null ? "NULL" : (string)((int)$roomId);

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

  // Summary: distinct-section counts again
  $finalProfSubjCounts = [];
  foreach ($qAll("
    SELECT prof_id, subj_id, COUNT(DISTINCT section_id) AS c
    FROM schedules
    WHERE school_year='{$sy}' AND semester='{$sem}'
      AND subj_id IS NOT NULL AND prof_id IS NOT NULL AND section_id IS NOT NULL
    GROUP BY prof_id, subj_id
  ") as $r) $finalProfSubjCounts[]=["prof_id"=>(int)$r['prof_id'],"subj_id"=>(int)$r['subj_id'],"sections"=>(int)$r['c']];

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
