<?php
// firebase_auth_sync.php
// Import/Update professors into Firebase Authentication using Kreait Admin SDK.
// Requires: connect.php and firebase_admin_config.php (your Kreait wrapper)

declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=utf-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

require_once __DIR__.'/connect.php';               // $host, $user, $password, $database
require_once __DIR__.'/firebase_admin_config.php'; // class FirebaseAdminConfig (Kreait)

use Kreait\Firebase\Exception\Auth\UserNotFound;

// --------- config you can tweak ----------
const SYNTHETIC_EMAIL_DOMAIN = 'spcc.local'; // username@spcc.local
const MIN_PASSWORD_LEN = 6;                  // Firebase requirement
// -----------------------------------------

function http_error(int $code, string $msg){
  http_response_code($code);
  echo json_encode(["success"=>false,"message"=>$msg], JSON_UNESCAPED_UNICODE);
  exit();
}

function db(): mysqli {
  global $host,$user,$password,$database;
  $c = @new mysqli($host,$user,$password,$database);
  if ($c->connect_error) http_error(500,"DB connection failed: ".$c->connect_error);
  $c->set_charset('utf8mb4');
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  return $c;
}

function synth_email(string $username): string {
  $u = strtolower(trim($username));
  // Basic sanitize for email local-part
  $u = preg_replace('/[^a-z0-9._-]/i', '.', $u);
  $u = trim($u, '.');
  return $u.'@'.SYNTHETIC_EMAIL_DOMAIN;
}

$started = microtime(true);
$conn = db();

// Pull professors (your columns)
$res = $conn->query("
  SELECT prof_id, prof_name, prof_username, prof_password
  FROM professors
");

$admin = new FirebaseAdminConfig();
$auth  = $admin->getAuth();

$summary = [
  "success" => true,
  "created" => 0,
  "updated" => 0,
  "skipped" => 0,
  "errors"  => 0,
  "details" => [],
];

while ($row = $res->fetch_assoc()) {
  $profId    = (string)$row['prof_id'];        // use as Firebase UID
  $name      = trim((string)$row['prof_name']);
  $username  = trim((string)$row['prof_username']);
  $password  = (string)$row['prof_password'];
  $email     = synth_email($username);

  // Validate inputs
  if ($username === '' || $password === '') {
    $summary['skipped']++;
    $summary['details'][] = ["prof_id"=>$profId, "username"=>$username, "action"=>"skipped", "reason"=>"missing username or password"];
    continue;
  }
  if (strlen($password) < MIN_PASSWORD_LEN) {
    $summary['skipped']++;
    $summary['details'][] = ["prof_id"=>$profId, "username"=>$username, "action"=>"skipped", "reason"=>"password too short (<6)"];
    continue;
  }

  try {
    // Try find by UID first (stable source of truth)
    $userRecord = null;
    try {
      $userRecord = $auth->getUser($profId);
    } catch (UserNotFound $e) {
      // Not by UID, try by email (in case a previous run created different UID)
      try {
        $userRecord = $auth->getUserByEmail($email);
      } catch (UserNotFound $e2) {
        $userRecord = null;
      }
    }

    if ($userRecord) {
      // Update user (ensure UID matches prof_id; if different, we just update that account)
      $auth->updateUser($userRecord->uid, [
        'email'       => $email,     // keep our synthetic email
        'password'    => $password,  // sync DB password
        'displayName' => $name ?: $username,
        'emailVerified' => true,
        'disabled'    => false,
      ]);
      $summary['updated']++;
      $summary['details'][] = ["prof_id"=>$profId, "username"=>$username, "uid"=>$userRecord->uid, "action"=>"updated"];
    } else {
      // Create new with UID = prof_id
      $newUser = $auth->createUser([
        'uid'          => $profId,
        'email'        => $email,
        'password'     => $password,
        'displayName'  => $name ?: $username,
        'emailVerified'=> true,
        'disabled'     => false,
      ]);
      $summary['created']++;
      $summary['details'][] = ["prof_id"=>$profId, "username"=>$username, "uid"=>$newUser->uid, "action"=>"created"];
    }

  } catch (Throwable $e) {
    $summary['errors']++;
    $summary['details'][] = ["prof_id"=>$profId, "username"=>$username, "action"=>"error", "error"=>$e->getMessage()];
  }
}

$summary['elapsedMs'] = (int)round((microtime(true) - $started)*1000);
echo json_encode($summary, JSON_UNESCAPED_UNICODE);
