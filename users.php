<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

declare(strict_types=1);

include 'cors_helper.php';
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/activity_logger.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/vendor/autoload.php';

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
$NOEMAIL = isset($_GET['noemail']) && $_GET['noemail'] == '1';

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function json_fail(int $code, string $msg, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['success'=>false,'status'=>'error','message'=>$msg] + $extra);
  exit();
}

function random_password(int $len = 10): string {
  try { return substr(str_replace(['/', '+', '='], '', base64_encode(random_bytes(16))), 0, $len); }
  catch (Throwable $e) { return 'Temp' . mt_rand(100000, 999999); }
}

function send_account_email(string $toEmail, string $toName, string $username, ?string $password, string $role): bool {
  $mailer = new PHPMailer(true);
  try {
    $smtpHost = 'smtp.gmail.com';
    $smtpUser = 'ssassist028@gmail.com';
    $smtpPass = 'qans jgft ggrl nplb';
    $smtpPort = '465';
    $fromEmail = 'no-reply@spcc.edu.ph';
    $fromName  = 'SPCC Scheduler';

    $mailer->isSMTP();
    $mailer->Host = $smtpHost;
    $mailer->SMTPAuth = true;
    $mailer->Username = $smtpUser;
    $mailer->Password = $smtpPass;
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mailer->Port = (int)$smtpPort;

    $mailer->setFrom($fromEmail, $fromName);
    $mailer->addAddress($toEmail, $toName ?: $toEmail);
    $mailer->isHTML(true);

    $roleLabel = $role === 'acad_head' ? 'Academic Head' : ucfirst($role);
    $pwdLine = $password
      ? "<p><strong>Temporary Password:</strong> " . htmlspecialchars($password) . "</p>"
      : "<p>Please set your password via the provided reset flow.</p>";

    $mailer->Subject = 'Your SPCC Scheduler account';
    $mailer->Body = "
      <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#0B2333'>
        <p>Hello " . htmlspecialchars($toName ?: $username) . ",</p>
        <p>Your {$roleLabel} account has been created.</p>
        <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
        {$pwdLine}
        <p>You can now sign in to the SPCC Scheduler.</p>
        <p>— SPCC Scheduler</p>
      </div>";
    $mailer->AltBody =
      "Your {$roleLabel} account has been created.\n" .
      "Username: {$username}\n" .
      ($password ? "Temporary Password: {$password}\n" : "Please set your password via the provided reset flow.\n") .
      "You can now sign in to the SPCC Scheduler.";

    return $mailer->send();
  } catch (Exception $e) {
    return false;
  }
}

function emailExists(mysqli $conn, string $email, ?int $excludeId = null): bool {
  $sql = "SELECT `user_id` FROM `users` WHERE `email` = ?" . ($excludeId ? " AND `user_id` <> ?" : "") . " LIMIT 1";
  $stmt = $conn->prepare($sql);
  if ($excludeId) $stmt->bind_param('si', $email, $excludeId); else $stmt->bind_param('s', $email);
  $stmt->execute(); $stmt->store_result();
  $exists = $stmt->num_rows > 0;
  $stmt->close();
  return $exists;
}

function usernameExists(mysqli $conn, string $username, ?int $excludeId = null): bool {
  $sql = "SELECT `user_id` FROM `users` WHERE `username` = ?" . ($excludeId ? " AND `user_id` <> ?" : "") . " LIMIT 1";
  $stmt = $conn->prepare($sql);
  if ($excludeId) $stmt->bind_param('si', $username, $excludeId); else $stmt->bind_param('s', $username);
  $stmt->execute(); $stmt->store_result();
  $exists = $stmt->num_rows > 0;
  $stmt->close();
  return $exists;
}

/** Throws with clear message if prepare() fails */
function safe_prepare(mysqli $conn, string $sql): mysqli_stmt {
  $stmt = $conn->prepare($sql);
  if ($stmt === false) {
    throw new Exception("SQL prepare failed: " . $conn->error . " | SQL: " . $sql);
  }
  return $stmt;
}

$method = $_SERVER['REQUEST_METHOD'];

try {

  if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id) {
      $stmt = safe_prepare($conn,
        "SELECT `user_id` AS `id`, `username`, `email`, NULL AS `name`, `role`, `status`, NULL AS `last_login`
         FROM `users` WHERE `user_id` = ? LIMIT 1"
      );
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res->fetch_assoc();
      $stmt->close();

      if ($row) echo json_encode($row);
      else json_fail(404, "User not found");
      exit();
    }

    $rows = [];
    $res = $conn->query(
      "SELECT `user_id` AS `id`, `username`, `email`, NULL AS `name`, `role`, `status`, NULL AS `last_login`
       FROM `users` ORDER BY `user_id` DESC"
    );
    if (!$res) json_fail(500, "Query failed", ["error"=>$conn->error]);
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode($rows);
    exit();
  }

  if ($method === 'POST') {
    $reqRole = $_SERVER['HTTP_X_USER_ROLE'] ?? $_SERVER['HTTP_X_ROLE'] ?? '';

    if (!in_array($reqRole, ['super_admin', 'admin'], true)) {
      json_fail(403, "Only super_admin/admin can create users");
    }

    $data = read_json_body();

    $name       = trim((string)($data['name'] ?? ''));
    $username   = trim((string)($data['username'] ?? ''));
    $email      = trim((string)($data['email'] ?? ''));
    $role       = trim((string)($data['role'] ?? ''));
    $status     = trim((string)($data['status'] ?? 'active'));
    $password   = (string)($data['password'] ?? '');
    $department = trim((string)($data['department'] ?? ''));
    $phone      = trim((string)($data['phone'] ?? ''));

    if ($username === '' || $email === '' || $role === '') {
      json_fail(400, "username, email, and role are required");
    }

    if (!in_array($role, ['admin', 'acad_head'], true)) {
      json_fail(400, "Role must be admin or acad_head");
    }

    if ($reqRole === 'admin' && $role !== 'acad_head') {
      json_fail(403, "Admin can only create Academic Head");
    }

    if (emailExists($conn, $email))   json_fail(409, "Email already exists");
    if (usernameExists($conn, $username)) json_fail(409, "Username already exists");

    $finalPassword = $password !== '' ? $password : random_password(10);

    $conn->begin_transaction();

    try {
      $stmt = safe_prepare($conn,
        "INSERT INTO `users` (`username`,`email`,`role`,`status`,`password`,`created_at`,`updated_at`)
         VALUES (?,?,?,?,?,NOW(),NOW())"
      );
      $stmt->bind_param('sssss', $username, $email, $role, $status, $finalPassword);
      $stmt->execute();
      $newId = (int)$stmt->insert_id;
      $stmt->close();

      if ($role === 'acad_head') {
        $stmt2 = safe_prepare($conn,
          "INSERT INTO `school_heads` (`user_id`,`sh_name`,`sh_email`,`sh_phone`,`department`)
           VALUES (?,?,?,?,?)"
        );
        $stmt2->bind_param('issss', $newId, $name, $email, $phone, $department);
        $stmt2->execute();
        $stmt2->close();
      }

      if ($role === 'admin') {
        $stmt3 = safe_prepare($conn,
          "INSERT INTO `admins` (`user_id`,`full_name`,`contact_number`,`department`)
           VALUES (?,?,?,?)"
        );
        $stmt3->bind_param('isss', $newId, $name, $phone, $department);
        $stmt3->execute();
        $stmt3->close();
      }

      $conn->commit();

      $mailOk = true;
      if (!$NOEMAIL) {
        $mailOk = send_account_email($email, $name, $username, $finalPassword, $role);
      }

      log_activity(
        $conn,
        'users',
        'create',
        "Created {$role} user {$username} (id={$newId})" . ($mailOk ? " + email sent" : " + email failed"),
        $newId,
        null
      );

      $stmt4 = safe_prepare($conn,
        "SELECT `user_id` AS `id`, `username`, `email`, NULL AS `name`, `role`, `status`, NULL AS `last_login`
         FROM `users` WHERE `user_id` = ? LIMIT 1"
      );
      $stmt4->bind_param('i', $newId);
      $stmt4->execute();
      $res4 = $stmt4->get_result();
      $row = $res4->fetch_assoc();
      $stmt4->close();

      echo json_encode([
        "success"    => true,
        "status"     => "success",
        "message"    => "User created",
        "email_sent" => $mailOk,
        "data"       => $row
      ]);
      exit();

    } catch (Exception $e) {
      $conn->rollback();
      if ($GLOBALS['DEBUG']) json_fail(500, $e->getMessage(), ["trace"=>$e->getTraceAsString()]);
      json_fail(500, "Create failed");
    }
  }

  json_fail(405, "Method not allowed");

} catch (Throwable $e) {
  if ($DEBUG) json_fail(500, $e->getMessage(), ["trace"=>$e->getTraceAsString()]);
  json_fail(500, "Server error");
}
