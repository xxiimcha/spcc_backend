<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/connect.php'; // must define $conn = new mysqli(...)

if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "Database connection not available"]);
  exit();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------- tiny query helpers ----------
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
  // ---------- read input ----------
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw, true);
  if (!is_array($in)) throw new Exception('Invalid JSON body');

  $schoolYear = trim((string)($in['school_year'] ?? ''));
  $semester   = trim((string)($in['semester'] ?? ''));
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
  $seedFrom   = strtolower((string)($in['seedFrom'] ?? 'assignments')); // "assignments" | "section_subjects"
  $lunchStart = trim((string)($in['lunchStart'] ?? '12:00'));
  $lunchEnd   = trim((string)($in['lunchEnd']   ?? '13:00'));
  $enforceLunch = ($lunchStart !== '' && $lunchEnd !== '');

  if ($schoolYear === '' || $semester === '') throw new Exception('Missing school_year or semester');
  if (!is_array($daysIn) || count($daysIn) === 0) throw new Exception('At least one active day is required');
  if ($slotMin <= 0) throw new Exception('slotMinutes must be positive');

  $sy  = $esc($schoolYear);
  $sem = $esc($semester);

  // ---------- time/slots ----------
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
    $SLOTS[] = [$sH.':'.$sN, $eH.':'.$eN, $slotMin]; // [start, end, minutes]
  }
  $DAYS  = array_map(fn($d)=>strtolower((string)$d), $daysIn);
  $spanM = max(1, $toM - $fromM);

  $overlapsLunch = function(string $s, string $e) use ($enforceLunch, $lunchStart, $lunchEnd) {
    return $enforceLunch ? ($s < $lunchEnd && $e > $lunchStart) : false;
  };

  // ---------- sections → latest room mapping ----------
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
  if (!$sectionRoom) {
    echo json_encode(["success"=>true,"inserted"=>0,"skipped"=>0,"details"=>["reason"=>"no sections found with room"]]);
    exit();
  }

  // ---------- subjects hours cache ----------
  $subHours = [];
  foreach ($qAll("SELECT subj_id, COALESCE(subj_hours_per_week,3) AS h FROM subjects") as $r) {
    $subHours[(int)$r['subj_id']] = (int)$r['h'];
  }

  // ---------- professor load this term (minutes) ----------
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

  // ---------- seed (section,subject,professor) triples ----------
  $triplesBySection = []; // section_id => [ {subj_id, prof_id, weekly_minutes}, ... ]
  $noEligible = [];

  if ($seedFrom === 'section_subjects') {
    foreach ($qAll("
      SELECT ss.section_id, ss.subj_id, ss.prof_id,
             COALESCE(ss.weekly_hours_needed, COALESCE(s.subj_hours_per_week,3)) AS wh
      FROM section_subjects ss
      LEFT JOIN subjects s ON s.subj_id = ss.subj_id
      WHERE ss.school_year='{$sy}' AND ss.semester='{$sem}'
    ") as $r) {
      $sec = (int)$r['section_id'];
      if (!isset($triplesBySection[$sec])) $triplesBySection[$sec]=[];
      $triplesBySection[$sec][] = [
        "subj_id" => (int)$r['subj_id'],
        "prof_id" => (int)$r['prof_id'],
        "weekly_minutes" => max(0, (int)$r['wh'] * 60),
      ];
    }
  } else { // assignments (default)
    // sections.subject_ids (JSON)
    $sectionSubs = []; // section_id => [subj_id...]
    foreach ($qAll("SELECT section_id, subject_ids FROM sections") as $r) {
      $lst = [];
      if (!empty($r['subject_ids'])) {
        $dec = json_decode($r['subject_ids'], true);
        if (is_array($dec)) foreach ($dec as $v) { $n=(int)$v; if ($n>0) $lst[]=$n; }
      }
      $sectionSubs[(int)$r['section_id']] = array_values(array_unique($lst));
    }
    // professors.prof_subject_ids (JSON) → subject => [prof_id...]
    $canTeach = []; // subj_id => [prof_id...]
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
    // choose least-loaded prof per (section, subj)
    foreach ($sectionSubs as $secId => $subs) {
      if (!isset($triplesBySection[$secId])) $triplesBySection[$secId]=[];
      foreach ($subs as $subj) {
        $cands = $canTeach[$subj] ?? [];
        if (!$cands) { $noEligible[] = ["section_id"=>$secId,"subj_id"=>$subj]; continue; }
        $best=null; $bestLoad=PHP_INT_MAX;
        foreach ($cands as $pid) {
          $l = $profLoad[$pid] ?? 0;
          if ($l < $bestLoad) { $best=$pid; $bestLoad=$l; }
        }
        if ($best===null) { $noEligible[] = ["section_id"=>$secId,"subj_id"=>$subj]; continue; }
        $hrs = $subHours[$subj] ?? 3;
        $triplesBySection[$secId][] = [
          "subj_id" => $subj,
          "prof_id" => (int)$best,
          "weekly_minutes" => max(0, $hrs*60),
        ];
        // optimistic update for balancing
        $profLoad[$best] = ($profLoad[$best] ?? 0) + $hrs*60;
      }
    }
  }

  // guard
  $any=false; foreach ($triplesBySection as $list) if (!empty($list)) { $any=true; break; }
  if (!$any) {
    echo json_encode(["success"=>true,"inserted"=>0,"skipped"=>0,"details"=>["reason"=>"no assignments found","noEligible"=>$noEligible]]);
    exit();
  }

  // ---------- small helpers that query current term ----------
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
  $profAdjScore = function(int $pid,string $day,string $s,string $e,int $slotMin) use ($qOne,$sy,$sem) {
    $touch = (int)$qOne("SELECT COUNT(*) FROM schedules
                         WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id={$pid}
                           AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                           AND (end_time='{$s}' OR start_time='{$e}')",0);
    $gap = 0;
    $gap += (int)$qOne("SELECT COUNT(*) FROM schedules
                        WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id={$pid}
                          AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                          AND TIMESTAMPDIFF(MINUTE, end_time, '{$s}') = {$slotMin}",0);
    $gap += (int)$qOne("SELECT COUNT(*) FROM schedules
                        WHERE school_year='{$sy}' AND semester='{$sem}' AND prof_id={$pid}
                          AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                          AND TIMESTAMPDIFF(MINUTE, '{$e}', start_time) = {$slotMin}",0);
    $score = 0;
    if ($touch>0) $score += 15;
    if ($gap>0)   $score -= 25*$gap;
    return $score;
  };

  // ---------- generation ----------
  $conn->begin_transaction();

  $inserted=0; $skipped=0; $conflicts=[]; $metrics=[];
  foreach ($triplesBySection as $secId => $rows) {
    if (!isset($sectionRoom[$secId])) continue; // needs room mapping
    $roomId = (int)$sectionRoom[$secId];

    foreach ($rows as $t) {
      $subjId = (int)$t['subj_id'];
      $profId = (int)$t['prof_id'];
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
        $best = null; // ['day','s','e','len','score']
        foreach ($DAYS as $day) {
          if ($secMaxDaily !== null && $secDayLoad($secId,$day) >= $secMaxDaily) continue;
          if ($profMaxDaily !== null && $profDayLoad($profId,$day) >= $profMaxDaily) continue;

          foreach ($SLOTS as [$s,$e,$len]) {
            if ($remain <= 0) break;
            if ($overlapsLunch($s,$e)) continue;

            $sE = $esc($s); $eE = $esc($e);

            // conflicts
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
            }

            // weekly cap fit
            $curr = $ignoreExisting ? ($target - $remain) : (int)$qOne($baseMinsSQL,0);
            if ($curr + $len > $target) continue;

            // idempotency
            $dup = (int)$qOne("SELECT 1 FROM schedules
                               WHERE school_year='{$sy}' AND semester='{$sem}'
                                 AND section_id={$secId} AND subj_id={$subjId}
                                 AND start_time='{$sE}' AND end_time='{$eE}'
                                 AND JSON_LENGTH(days)=1
                                 AND JSON_CONTAINS(days, JSON_QUOTE('{$day}'))
                               LIMIT 1",0);
            if ($dup>0) { $skipped++; continue; }

            // -------- scoring (simple but effective) --------
            $score=0;

            // balance section day load: prefer lowest day
            $secLoad = $secDayLoad($secId,$day);
            $minLoad = PHP_INT_MAX;
            foreach ($DAYS as $d) { $minLoad = min($minLoad, $secDayLoad($secId,$d)); }
            if ($secLoad === $minLoad) $score += 40;     // bonus if we're filling the least-loaded day

            // earlier time preferred (normalize 0..1)
            $sM = (int)substr($s,0,2)*60 + (int)substr($s,3,2);
            $early = 1.0 - (($sM - $fromM)/$spanM);
            $score += (int)round(10*$early);

            // avoid back-to-back same subject for section
            if ($touchSameSubject($secId,$subjId,$day,$s,$e)) $score -= 60;

            // professor adjacency / gap handling
            $score += $profAdjScore($profId,$day,$s,$e,$slotMin);

            // mild penalties by loads
            $score += -8 * $secLoad;
            $score += -5 * $profDayLoad($profId,$day);

            if ($best===null || $score > $best['score'])
              $best = ['day'=>$day,'s'=>$s,'e'=>$e,'len'=>$len,'score'=>$score];
          }
        }

        if ($best === null) break; // nothing fits now

        // place it
        $scheduleType='Onsite'; $status='pending'; $origin='auto';
        $sE=$esc($best['s']); $eE=$esc($best['e']);
        $ok = $conn->query("
          INSERT INTO schedules
            (school_year, semester, subj_id, prof_id, schedule_type, start_time, end_time, room_id, section_id, days, status, origin)
          VALUES
            ('{$sy}','{$sem}', {$subjId}, {$profId}, '{$esc($scheduleType)}', '{$sE}', '{$eE}', {$roomId}, {$secId},
             JSON_ARRAY('{$best['day']}'), '{$esc($status)}', '{$esc($origin)}')
        ");
        if ($ok === false) throw new Exception("Insert failed: ".$conn->error);

        $inserted++;
        $genForPair += $best['len'];
        $remain     -= $best['len'];

        if (!$ignoreExisting) {
          $baseMinsSQL = "SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(end_time,start_time))/60),0)
                          FROM schedules
                          WHERE school_year='{$sy}' AND semester='{$sem}'
                            AND section_id={$secId} AND subj_id={$subjId}";
        }
      } // while remain

      $metrics[] = [
        "section_id"=>$secId,"subj_id"=>$subjId,"prof_id"=>$profId,
        "target_mins"=>$target,"generated_mins"=>$genForPair,
        "remaining_after"=>max(0, $target - ($ignoreExisting ? $genForPair : ((int)$qOne($baseMinsSQL,0))))
      ];
    } // each triple
  } // each section

  $conn->commit();

  echo json_encode([
    "success"=>true,
    "inserted"=>$inserted,
    "skipped"=>$skipped,
    "details"=>[
      "conflicts"=>$conflicts,
      "noEligible"=>$noEligible,
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
        "lunchEnd"=>$lunchEnd
      ],
      "metrics"=>$metrics
    ]
  ]);

} catch (Throwable $e) {
  try { $conn->rollback(); } catch (Throwable $ignored) {}
  http_response_code(500);
  echo json_encode(["success"=>false,"message"=>$e->getMessage()]);
}
