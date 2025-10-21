<?php
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

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function esc(mysqli $conn, ?string $v): string {
  return mysqli_real_escape_string($conn, (string)($v ?? ''));
}

function emailExists(mysqli $conn, string $email, ?int $excludeId = null): bool {
  $email = esc($conn, $email);
  $q = "SELECT user_id FROM users WHERE email='{$email}'"
     . ($excludeId ? " AND user_id <> {$excludeId}" : "")
     . " LIMIT 1";
  $res = $conn->query($q);
  return ($res && $res->num_rows > 0);
}

function usernameExists(mysqli $conn, string $username, ?int $excludeId = null): bool {
  $username = esc($conn, $username);
  $q = "SELECT user_id FROM users WHERE username='{$username}'"
     . ($excludeId ? " AND user_id <> {$excludeId}" : "")
     . " LIMIT 1";
  $res = $conn->query($q);
  return ($res && $res->num_rows > 0);
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
    $mailer->Subject = 'Your SPCC Scheduler account';
    $pwdLine = $password ? "<p><strong>Temporary Password:</strong> {$password}</p>" : "<p>Please set your password via the provided reset flow.</p>";
    $roleLabel = $role === 'acad_head' ? 'Academic Head' : ucfirst($role);
    $mailer->Body = "
      <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#0B2333'>
        <p>Hello " . htmlspecialchars($toName ?: $username) . ",</p>
        <p>Your {$roleLabel} account has been created.</p>
        <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
        {$pwdLine}
        <p>You can now sign in to the SPCC Scheduler.</p>
        <p>— SPCC Scheduler</p>
      </div>";
    $mailer->AltBody = "Your {$roleLabel} account has been created.\nUsername: {$username}\n"
      . ($password ? "Temporary Password: {$password}\n" : "Please set your password via the provided reset flow.\n")
      . "You can now sign in to the SPCC Scheduler.";
    return $mailer->send();
  } catch (Exception $e) {
    return false;
  }
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
  case 'GET': {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if ($id) {
      $sql = "SELECT user_id AS id, username, email, NULL AS name, role, status, NULL AS last_login
              FROM users WHERE user_id = {$id} LIMIT 1";
      $res = $conn->query($sql);
      if ($res && $row = $res->fetch_assoc()) {
        echo json_encode($row);
      } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "User not found"]);
      }
      exit();
    }
    $sql = "SELECT user_id AS id, username, email, NULL AS name, role, status, NULL AS last_login
            FROM users ORDER BY user_id DESC";
    $res = $conn->query($sql);
    if (!$res) {
      http_response_code(500);
      echo json_encode(["status" => "error", "message" => "Query failed", "error" => $conn->error]);
      exit();
    }
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    echo json_encode($rows);
    exit();
  }

  case 'POST': {
    $reqRole = $_SERVER['HTTP_X_USER_ROLE'] ?? $_SERVER['HTTP_X_ROLE'] ?? '';
    if ($reqRole !== 'super_admin') {
      http_response_code(403);
      echo json_encode(["status" => "error", "message" => "Only super_admin can create users"]);
      exit();
    }

    $data = read_json_body();
    $name = trim((string)($data['name'] ?? ''));
    $username = trim((string)($data['username'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $role = trim((string)($data['role'] ?? ''));
    $status = trim((string)($data['status'] ?? 'active'));
    $password = (string)($data['password'] ?? '');
    $department = trim((string)($data['department'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));

    if ($username === '' || $email === '' || $role === '') {
      http_response_code(400);
      echo json_encode(["status" => "error", "message" => "username, email, and role are required"]);
      exit();
    }

    if (!in_array($role, ['admin', 'acad_head'], true)) {
      http_response_code(400);
      echo json_encode(["status" => "error", "message" => "Role must be admin or acad_head"]);
      exit();
    }

    if (emailExists($conn, $email)) {
      http_response_code(409);
      echo json_encode(["status" => "error", "message" => "Email already exists"]);
      exit();
    }
    if (usernameExists($conn, $username)) {
      http_response_code(409);
      echo json_encode(["status" => "error", "message" => "Username already exists"]);
      exit();
    }

    $usernameEsc = esc($conn, $username);
    $emailEsc = esc($conn, $email);
    $roleEsc = esc($conn, $role);
    $statusEsc = esc($conn, $status);
    $pwdVal = $password !== '' ? esc($conn, $password) : null;

    $sql = "INSERT INTO users (username, email, role, status, password, created_at, updated_at)
            VALUES ('{$usernameEsc}', '{$emailEsc}', '{$roleEsc}', '{$statusEsc}', "
            . ($pwdVal !== null ? "'{$pwdVal}'" : "NULL")
            . ", NOW(), NOW())";

    $ok = $conn->query($sql);
    if (!$ok) {
      http_response_code(500);
      echo json_encode(["status" => "error", "message" => "Insert failed", "error" => $conn->error]);
      exit();
    }

    $newId = (int)$conn->insert_id;

    if ($role === 'acad_head') {
      $q = "INSERT INTO school_heads (user_id, sh_name, sh_email, sh_phone, department)
            VALUES ({$newId}, '{$name}', '{$emailEsc}', '{$phone}', '{$department}')";
      $conn->query($q);
    }

    if ($role === 'admin') {
      $q = "INSERT INTO admins (user_id, full_name, contact_number, department)
            VALUES ({$newId}, '{$name}', '{$phone}', '{$department}')";
      $conn->query($q);
    }

    $mailOk = send_account_email($email, $name, $username, $password !== '' ? $password : null, $role);
    log_activity($conn, 'users', 'create', "Created {$role} user {$username} (id={$newId})" . ($mailOk ? " + email sent" : " + email failed"), $newId, null);

    $sel = $conn->query(
      "SELECT user_id AS id, username, email, NULL AS name, role, status, NULL AS last_login
       FROM users WHERE user_id = {$newId} LIMIT 1"
    );
    $row = $sel ? $sel->fetch_assoc() : null;

    echo json_encode([
      "status" => "ok",
      "message" => "User created",
      "email_sent" => $mailOk,
      "user" => $row
    ]);
    exit();
  }

  default:
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit();
}
