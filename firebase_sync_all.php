<?php
// firebase_sync_all.php - Sync to Realtime DB + Import/Update Firebase Auth users (professors)
// Keeps your existing firebase_config.php unchanged and uses firebase_admin_config.php (Kreait) for Auth.

declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=utf-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

require_once __DIR__.'/connect.php';                  // $host, $user, $password, $database
require_once __DIR__.'/firebase_config.php';          // $firebaseConfig -> FirebaseConfig instance (Realtime DB via REST)
require_once __DIR__.'/firebase_admin_config.php';    // class FirebaseAdminConfig (Kreait Admin SDK for Auth)

use Kreait\Firebase\Exception\Auth\UserNotFound;

$startedAt = microtime(true);

function http_error(int $code, string $msg){
  http_response_code($code);
  echo json_encode(["success"=>false,"status"=>"error","message"=>$msg], JSON_UNESCAPED_UNICODE);
  exit();
}

function db(): mysqli {
  global $host, $user, $password, $database;
  $c = @new mysqli($host, $user, $password, $database);
  if ($c->connect_error) http_error(500, "DB connection failed: ".$c->connect_error);
  $c->set_charset('utf8mb4');
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  return $c;
}

// ------------ Realtime DB helpers (use your FirebaseConfig) ------------------
/** @return array [ok(bool), body(mixed), raw(array)] */
function fb_call(string $method, string $path, $data = null): array {
  global $firebaseConfig; // instance of FirebaseConfig
  switch (strtoupper($method)) {
    case 'PUT':   $res = $firebaseConfig->setData($path, $data); break;
    case 'PATCH': $res = $firebaseConfig->updateData($path, $data); break;
    case 'POST':  $res = $firebaseConfig->pushData($path, $data); break;
    case 'DELETE':$res = $firebaseConfig->deleteData($path); break;
    default:      $res = $firebaseConfig->getData($path); break;
  }
  $ok = ($res['status'] >= 200 && $res['status'] < 300);
  $body = $res['response'] !== null ? json_decode($res['response'], true) : null;
  return [$ok, $body, $res];
}
function fb_put($path, $data){ [$ok,$b,$r]=fb_call('PUT',$path,$data); if(!$ok) http_error($r['status']?:500,"Firebase PUT failed: ".$r['response']); return $b; }
function fb_patch($path,$data){ [$ok,$b,$r]=fb_call('PATCH',$path,$data); if(!$ok) http_error($r['status']?:500,"Firebase PATCH failed: ".$r['response']); return $b; }
function fb_delete($path){ [$ok,$b,$r]=fb_call('DELETE',$path,null); if(!$ok) http_error($r['status']?:500,"Firebase DELETE failed: ".$r['response']); return $b; }

// ---------------------------- Utilities --------------------------------------
function maybe_json($s){
  if ($s === null) return null;
  if (is_array($s) || is_object($s)) return $s;
  $t = trim((string)$s);
  if ($t === '') return null;
  if (($t[0]=='[' && substr($t,-1)==']') || ($t[0]=='{' && substr($t,-1)=='}')) {
    $d = json_decode($t, true);
    if (json_last_error() === JSON_ERROR_NONE) return $d;
  }
  return $s; // leave as-is if not valid JSON
}

function count_table(mysqli $c, string $table): int {
  $r = $c->query("SELECT COUNT(*) AS n FROM `{$table}`")->fetch_assoc();
  return (int)$r['n'];
}

// ----------------------- Fetchers (your columns) -----------------------------
function fetch_professors(mysqli $c): array {
  // IMPORTANT: DO NOT include prof_password in Firebase DB.
  $rows = [];
  $res = $c->query("
    SELECT prof_id, prof_name, prof_username, prof_email, prof_phone,
           prof_qualifications, subj_count, prof_subject_ids, school_year
    FROM professors
  ");
  while ($r = $res->fetch_assoc()){
    $id = (string)$r['prof_id'];
    $rows[$id] = [
      "id"              => (int)$r['prof_id'],
      "name"            => $r['prof_name'],
      "username"        => $r['prof_username'],
      "email"           => $r['prof_email'],
      "phone"           => $r['prof_phone'],
      "qualifications"  => maybe_json($r['prof_qualifications']),
      "subjectCount"    => isset($r['subj_count']) ? (int)$r['subj_count'] : null,
      "subjectIds"      => maybe_json($r['prof_subject_ids']),
      "schoolYear"      => $r['school_year'] ?? null,
    ];
  }
  return $rows;
}

function fetch_rooms(mysqli $c): array {
  $rows = [];
  $res = $c->query("SELECT room_id, room_number, room_type, room_capacity, school_year, semester FROM rooms");
  while ($r = $res->fetch_assoc()){
    $id = (string)$r['room_id'];
    $rows[$id] = [
      "id"        => (int)$r['room_id'],
      "number"    => $r['room_number'],
      "type"      => $r['room_type'],
      "capacity"  => isset($r['room_capacity']) ? (int)$r['room_capacity'] : null,
      "schoolYear"=> $r['school_year'] ?? null,
      "semester"  => $r['semester'] ?? null,
    ];
  }
  return $rows;
}

function fetch_sections(mysqli $c): array {
  $rows = [];
  $res = $c->query("SELECT section_id, section_name, grade_level, strand, number_of_students, subject_ids, school_year, semester FROM sections");
  while ($r = $res->fetch_assoc()){
    $id = (string)$r['section_id'];
    $rows[$id] = [
      "id"               => (int)$r['section_id'],
      "name"             => $r['section_name'],
      "gradeLevel"       => $r['grade_level'],
      "strand"           => $r['strand'],
      "numberOfStudents" => isset($r['number_of_students']) ? (int)$r['number_of_students'] : null,
      "subjectIds"       => maybe_json($r['subject_ids']),
      "schoolYear"       => $r['school_year'] ?? null,
      "semester"         => $r['semester'] ?? null,
    ];
  }
  return $rows;
}

function stream_schedules(mysqli $c, int $chunkSize, array &$summary): void {
  $total = count_table($c, "schedules");
  $summary['schedules']['totalRows'] = $total;
  $pushed = 0;
  $offset = 0;

  while ($offset < $total) {
    $res = $c->query("
      SELECT schedule_id, school_year, semester, subj_id, prof_id, start_time, end_time, created_at, room_id, section_id, days, status, origin, schedule_type, online_mode
      FROM schedules
      ORDER BY schedule_id
      LIMIT {$chunkSize} OFFSET {$offset}
    ");
    $batch = [];
    while ($r = $res->fetch_assoc()){
      $id = (string)$r['schedule_id'];
      $batch[$id] = [
        "id"          => (int)$r['schedule_id'],
        "schoolYear"  => $r['school_year'] ?? null,
        "semester"    => $r['semester'] ?? null,
        "subjectId"   => isset($r['subj_id']) ? (int)$r['subj_id'] : null,
        "professorId" => isset($r['prof_id']) ? (int)$r['prof_id'] : null,
        "roomId"      => isset($r['room_id']) ? (int)$r['room_id'] : null,
        "sectionId"   => isset($r['section_id']) ? (int)$r['section_id'] : null,
        "days"        => maybe_json($r['days']),          // e.g. ["wednesday"]
        "startTime"   => $r['start_time'],
        "endTime"     => $r['end_time'],
        "status"      => $r['status'] ?? null,
        "origin"      => $r['origin'] ?? null,
        "scheduleType"=> $r['schedule_type'] ?? null,
        "onlineMode"  => $r['online_mode'] ?? null,
        "createdAt"   => $r['created_at'] ?? null,
      ];
    }
    if (!empty($batch)) { fb_patch("/schedules", $batch); $pushed += count($batch); }
    $offset += $chunkSize;
  }
  $summary['schedules']['pushed'] = $pushed;
}

// -------------------- Firebase Auth sync (Kreait) ----------------------------
const SYNTHETIC_EMAIL_DOMAIN = 'spcc.local'; // username@spcc.local
const MIN_PASSWORD_LEN       = 6;

function synth_email(string $username): string {
  $u = strtolower(trim($username));
  $u = preg_replace('/[^a-z0-9._-]/i', '.', $u);
  $u = trim($u, '.');
  return $u.'@'.SYNTHETIC_EMAIL_DOMAIN;
}

/**
 * Creates/updates Firebase Auth users for all professors.
 * UID = prof_id, email = prof_username@spcc.local, password = prof_password, displayName = prof_name
 */
function sync_auth_professors(mysqli $c, FirebaseAdminConfig $admin, array &$summary): void {
  $auth = $admin->getAuth();

  $res = $c->query("SELECT prof_id, prof_name, prof_username, prof_password FROM professors");

  $created = 0; $updated = 0; $skipped = 0; $errors = 0; $details = [];
  while ($row = $res->fetch_assoc()) {
    $profId   = (string)$row['prof_id'];         // use as UID
    $name     = trim((string)$row['prof_name']);
    $username = trim((string)$row['prof_username']);
    $password = (string)$row['prof_password'];
    $email    = synth_email($username);

    if ($username === '' || $password === '') {
      $skipped++; $details[] = ["prof_id"=>$profId,"username"=>$username,"action"=>"skipped","reason"=>"missing username or password"]; continue;
    }
    if (strlen($password) < MIN_PASSWORD_LEN) {
      $skipped++; $details[] = ["prof_id"=>$profId,"username"=>$username,"action"=>"skipped","reason"=>"password too short (<6)"]; continue;
    }

    try {
      // Prefer UID lookup
      $userRecord = null;
      try {
        $userRecord = $auth->getUser($profId);
      } catch (UserNotFound $e) {
        try { $userRecord = $auth->getUserByEmail($email); } catch (UserNotFound $e2) { $userRecord = null; }
      }

      if ($userRecord) {
        $auth->updateUser($userRecord->uid, [
          'email'         => $email,
          'password'      => $password,
          'displayName'   => $name ?: $username,
          'emailVerified' => true,
          'disabled'      => false,
        ]);
        $updated++; $details[] = ["prof_id"=>$profId,"username"=>$username,"uid"=>$userRecord->uid,"action"=>"updated"];
      } else {
        $newUser = $auth->createUser([
          'uid'           => $profId,
          'email'         => $email,
          'password'      => $password,
          'displayName'   => $name ?: $username,
          'emailVerified' => true,
          'disabled'      => false,
        ]);
        $created++; $details[] = ["prof_id"=>$profId,"username"=>$username,"uid"=>$newUser->uid,"action"=>"created"];
      }

    } catch (Throwable $e) {
      $errors++; $details[] = ["prof_id"=>$profId,"username"=>$username,"action"=>"error","error"=>$e->getMessage()];
    }
  }

  $summary['auth'] = [
    "created" => $created,
    "updated" => $updated,
    "skipped" => $skipped,
    "errors"  => $errors,
    "details" => $details,
  ];
}

// ----------------------------- main ------------------------------------------
$wipe    = isset($_GET['wipe']) && $_GET['wipe'] === '1';
$chunkSz = isset($_GET['chunk']) ? max(100, (int)$_GET['chunk']) : 500;

$conn = db();

$summary = [
  "success"   => true,
  "status"    => "ok",
  "wiped"     => $wipe,
  "counts"    => [],
  "schedules" => ["chunkSize"=>$chunkSz, "totalRows"=>0, "pushed"=>0],
  "auth"      => ["created"=>0,"updated"=>0,"skipped"=>0,"errors"=>0,"details"=>[]],
  "syncedAt"  => null,
  "elapsedMs" => 0,
];

try {
  // ---- Realtime DB sync ----
  $professors = fetch_professors($conn);
  $rooms      = fetch_rooms($conn);
  $sections   = fetch_sections($conn);

  $summary['counts'] = [
    "professors" => count($professors),
    "rooms"      => count($rooms),
    "sections"   => count($sections),
  ];

  if ($wipe) {
    fb_delete("/professors");
    fb_delete("/rooms");
    fb_delete("/sections");
    fb_delete("/schedules");
  }

  fb_put("/professors", $professors ?: new stdClass());
  fb_put("/rooms",      $rooms      ?: new stdClass());
  fb_put("/sections",   $sections   ?: new stdClass());

  if (!$wipe) fb_put("/schedules", new stdClass());
  stream_schedules($conn, $chunkSz, $summary);

  // ---- Firebase Auth users sync (professors) ----
  try {
    $admin = new FirebaseAdminConfig(); // uses your Kreait setup
    sync_auth_professors($conn, $admin, $summary);
  } catch (Throwable $e) {
    // If Admin SDK init fails, report but keep DB sync successful
    $summary['auth']['errors']++;
    $summary['auth']['details'][] = ["action"=>"init_error","error"=>$e->getMessage()];
  }

  $summary['syncedAt']  = gmdate('c');
  $summary['elapsedMs'] = (int)round((microtime(true) - $startedAt)*1000);
  echo json_encode($summary, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_error(500, "Sync failed: ".$e->getMessage());
}
