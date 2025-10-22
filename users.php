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

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function random_password(int $len = 10): string {
  try {
    return substr(str_replace(['/', '+', '='], '', base64_encode(random_bytes(16))), 0, $len);
  } catch (Throwable $e) {
    return 'Temp' . mt_rand(100000, 999999);
  }
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
  $sql = "SELECT user_id FROM users WHERE email = ?" . ($excludeId ? " AND user_id <> ?" : "") . " LIMIT 1";
  $stmt = $conn->prepare($sql);
  if ($excludeId) $stmt->bind_param('si', $email, $excludeId); else $stmt->bind_param('s', $email);
  $stmt->execute(); $stmt->store_result();
  $exists = $stmt->num_rows > 0;
  $stmt->close();
  return $exists;
}

function usernameExists(mysqli $conn, string $username, ?int $excludeId = null): bool {
  $sql = "SELECT user_id FROM users WHERE username = ?" . ($excludeId ? " AND user_id <> ?" : "") . " LIMIT 1";
  $stmt = $conn->prepare($sql);
  if ($excludeId) $stmt->bind_param('si', $username, $excludeId); else $stmt->bind_param('s', $username);
  $stmt->execute(); $stmt->store_result();
  $exists = $stmt->num_rows > 0;
  $stmt->close();
  return $exists;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
  case 'GET': {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id) {
      $stmt = $conn->prepare(
        "SELECT user_id AS id, username, email, NULL AS name, role, status, NULL AS last_login
         FROM users WHERE user_id = ? LIMIT 1"
      );
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res->fetch_assoc();
      $stmt->close();

      if ($row) {
        echo json_encode($row);
      } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "User not found"]);
      }
      exit();
    }

    $rows = [];
    $res = $conn->query(
      "SELECT user_id AS id, username, email, NULL AS name, role, status, NULL AS last_login
       FROM users ORDER BY user_id DESC"
    );
    if (!$res) {
      http_response_code(500);
      echo json_encode(["status" => "error", "message" => "Query failed", "error" => $conn->error]);
      exit();
    }
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode($rows);
    exit();
  }

  case 'POST': {
    $reqRole = $_SERVER['HTTP_X_USER_ROLE'] ?? $_SERVER['HTTP_X_ROLE'] ?? '';

    if (!in_array($reqRole, ['super_admin', 'admin'], true)) {
      http_response_code(403);
      echo json_encode(["status" => "error", "message" => "Only super_admin/admin can create users"]);
      exit();
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
      http_response_code(400);
      echo json_encode(["status" => "error", "message" => "username, email, and role are required"]);
      exit();
    }

    if (!in_array($role, ['admin', 'acad_head'], true)) {
      http_response_code(400);
      echo json_encode(["status" => "error", "message" => "Role must be admin or acad_head"]);
      exit();
    }

    // Admins can only create acad_head
    if ($reqRole === 'admin' && $role !== 'acad_head') {
      http_response_code(403);
      echo json_encode(["status" => "error", "message" => "Admin can only create Academic Head"]);
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

    // Ensure a password value (in case users.password is NOT NULL)
    $finalPassword = $password !== '' ? $password : random_password(10);

    $conn->begin_transaction();

    try {
      // users insert (users table DOES have created_at/updated_at)
      $stmt = $conn->prepare(
        "INSERT INTO users (username, email, role, status, password, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())"
      );
      $stmt->bind_param('sssss', $username, $email, $role, $status, $finalPassword);
      $ok = $stmt->execute();
      if (!$ok) throw new Exception("users insert failed: " . $stmt->error);
      $newId = (int)$stmt->insert_id;
      $stmt->close();

      // role-specific details
      if ($role === 'acad_head') {
        // school_heads has NO created_at/updated_at in your schema
        $stmt2 = $conn->prepare(
          "INSERT INTO school_heads (user_id, sh_name, sh_email, sh_phone, department)
           VALUES (?, ?, ?, ?, ?)"
        );
        $stmt2->bind_param('issss', $newId, $name, $email, $phone, $department);
        $ok2 = $stmt2->execute();
        if (!$ok2) throw new Exception("school_heads insert failed: " . $stmt2->error);
        $stmt2->close();
      }

      if ($role === 'admin') {
        // admins has NO created_at/updated_at in your schema
        $stmt3 = $conn->prepare(
          "INSERT INTO admins (user_id, full_name, contact_number, department)
           VALUES (?, ?, ?, ?)"
        );
        $stmt3->bind_param('isss', $newId, $name, $phone, $department);
        $ok3 = $stmt3->execute();
        if (!$ok3) throw new Exception("admins insert failed: " . $stmt3->error);
        $stmt3->close();
      }

      $conn->commit();

      $mailOk = send_account_email($email, $name, $username, $finalPassword, $role);
      log_activity(
        $conn,
        'users',
        'create',
        "Created {$role} user {$username} (id={$newId})" . ($mailOk ? " + email sent" : " + email failed"),
        $newId,
        null
      );

      $stmt4 = $conn->prepare(
        "SELECT user_id AS id, username, email, NULL AS name, role, status, NULL AS last_login
         FROM users WHERE user_id = ? LIMIT 1"
      );
      $stmt4->bind_param('i', $newId);
      $stmt4->execute();
      $res4 = $stmt4->get_result();
      $row = $res4->fetch_assoc();
      $stmt4->close();

      echo json_encode([
        "success" => true,
        "status" => "success",
        "message" => "User created",
        "email_sent" => $mailOk,
        "data" => $row
      ]);
      exit();

    } catch (Exception $e) {
      $conn->rollback();
      http_response_code(500);
      echo json_encode(["success" => false, "status" => "error", "message" => $e->getMessage()]);
      exit();
    }
  }

  default:
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit();
}
