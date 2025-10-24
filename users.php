<?php
declare(strict_types=1);

/* =========================
   CORS (must be FIRST)
   ========================= */
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [
  'http://localhost:5173',
  'http://127.0.0.1:5173',
  'https://spcc-smartsched.vercel.app',
  'https://spcc-scheduler.site',
  'https://www.spcc-scheduler.site',
];
if ($origin && in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: {$origin}");
  header("Vary: Origin");
  header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Role, X-Role");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=utf-8");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit();
}

/* =========================
   Includes / Setup
   ========================= */
ini_set('display_errors', '0'); // prod safe
ini_set('log_errors', '1');

require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/activity_logger.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/vendor/autoload.php';

$DEBUG   = isset($_GET['debug'])   && $_GET['debug']   === '1';
$NOEMAIL = isset($_GET['noemail']) && $_GET['noemail'] === '1';
$UPSERT  = isset($_GET['upsert'])  && $_GET['upsert']  === '1';

/* =========================
   Helpers
   ========================= */
function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function json_fail(int $code, string $msg, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['success' => false, 'status' => 'error', 'message' => $msg] + $extra);
  exit();
}

function json_ok(array $payload = [], int $code = 200): void {
  http_response_code($code);
  echo json_encode(['success' => true, 'status' => 'success'] + $payload);
  exit();
}

function random_password(int $len = 10): string {
  try {
    return substr(str_replace(['/', '+', '='], '', base64_encode(random_bytes(16))), 0, $len);
  } catch (Throwable $e) {
    return 'Temp' . mt_rand(100000, 999999);
  }
}

/** Throws with clear message if prepare() fails */
function safe_prepare(mysqli $conn, string $sql): mysqli_stmt {
  $stmt = $conn->prepare($sql);
  if ($stmt === false) {
    throw new Exception("SQL prepare failed: " . $conn->error . " | SQL: " . $sql);
  }
  return $stmt;
}

/** SELECT list with name (admin/full_name or school_heads/sh_name) */
function base_user_select(): string {
  return "
    SELECT
      u.user_id AS id,
      u.username,
      u.email,
      COALESCE(a.full_name, sh.sh_name) AS name,
      u.role,
      u.status,
      NULL AS last_login
    FROM users u
    LEFT JOIN admins a ON a.user_id = u.user_id
    LEFT JOIN school_heads sh ON sh.user_id = u.user_id
  ";
}

/** Case-insensitive email lookup + name */
function findUserByEmail(mysqli $conn, string $email): ?array {
  $email = trim($email);
  $sql = base_user_select() . " WHERE LOWER(u.email) = LOWER(?) LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('s', $email);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc() ?: null;
  $stmt->close();
  return $row;
}

/** Case-insensitive username lookup + name (global uniqueness) */
function findUserByUsername(mysqli $conn, string $username): ?array {
  $u = trim($username);
  $sql = base_user_select() . " WHERE LOWER(u.username) = LOWER(?) LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('s', $u);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc() ?: null;
  $stmt->close();
  return $row;
}

/** Fetch by id + name */
function findUserById(mysqli $conn, int $id): ?array {
  $sql = base_user_select() . " WHERE u.user_id = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc() ?: null;
  $stmt->close();
  return $row;
}

function schoolHeadRowExists(mysqli $conn, int $userId): bool {
  $stmt = $conn->prepare("SELECT 1 FROM school_heads WHERE user_id = ? LIMIT 1");
  $stmt->bind_param('i', $userId);
  $stmt->execute(); $stmt->store_result();
  $ok = $stmt->num_rows > 0;
  $stmt->close();
  return $ok;
}

function adminRowExists(mysqli $conn, int $userId): bool {
  $stmt = $conn->prepare("SELECT 1 FROM admins WHERE user_id = ? LIMIT 1");
  $stmt->bind_param('i', $userId);
  $stmt->execute(); $stmt->store_result();
  $ok = $stmt->num_rows > 0;
  $stmt->close();
  return $ok;
}

/** Email sender — replace creds with environment variables! */
function send_account_email(string $toEmail, string $toName, string $username, ?string $password, string $role): bool {
  $mailer = new PHPMailer(true);
  try {
    // TODO: move to environment/Hostinger secrets
    $smtpHost = 'smtp.gmail.com';
    $smtpUser = 'YOUR_GMAIL_APP_USER@example.com';
    $smtpPass = 'YOUR_GMAIL_APP_PASSWORD';
    $smtpPort = '465';
    $fromEmail = 'no-reply@spcc.edu.ph';
    $fromName  = 'SPCC Scheduler';

    $mailer->isSMTP();
    $mailer->Host       = $smtpHost;
    $mailer->SMTPAuth   = true;
    $mailer->Username   = $smtpUser;
    $mailer->Password   = $smtpPass;
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mailer->Port       = (int)$smtpPort;

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
        <p>Your {$roleLabel} account has been created/updated.</p>
        <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
        {$pwdLine}
        <p>You can now sign in to the SPCC Scheduler.</p>
        <p>— SPCC Scheduler</p>
      </div>";
    $mailer->AltBody =
      "Your {$roleLabel} account has been created/updated.\n" .
      "Username: {$username}\n" .
      ($password ? "Temporary Password: {$password}\n" : "Please set your password via the provided reset flow.\n") .
      "You can now sign in to the SPCC Scheduler.";

    return $mailer->send();
  } catch (Exception $e) {
    return false;
  }
}

/* =========================
   Router
   ========================= */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {

  /* ---------- GET /users.php or /users.php?id=XX ---------- */
  if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id) {
      $stmt = safe_prepare($conn, base_user_select() . " WHERE u.user_id = ? LIMIT 1");
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res->fetch_assoc();
      $stmt->close();

      if ($row) {
        echo json_encode($row);
      } else {
        json_fail(404, "User not found");
      }
      exit();
    }

    $rows = [];
    $sql = base_user_select() . " ORDER BY u.user_id DESC";
    $res = $conn->query($sql);
    if (!$res) json_fail(500, "Query failed", ["error" => $conn->error]);
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode($rows);
    exit();
  }

  /* ---------- POST /users.php[?upsert=1] ---------- */
  if ($method === 'POST') {
    // Authorization via custom header (preflight allowed by CORS above)
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

    // Duplicate checks (case-insensitive)
    $existingByEmail    = findUserByEmail($conn, $email);
    $existingByUsername = findUserByUsername($conn, $username);

    // Username must be globally unique across all roles
    if ($existingByUsername) {
      json_fail(409, "Username already exists", ["existing_user" => $existingByUsername]);
    }

    $finalPassword = $password !== '' ? $password : random_password(10);

    $conn->begin_transaction();

    try {
      if ($existingByEmail && $UPSERT) {
        // --- UPSERT path (email exists): update role/status and ensure detail rows
        $existingId = (int)$existingByEmail['id'];

        $stmtU = safe_prepare($conn,
          "UPDATE users SET role = ?, status = ?, updated_at = NOW() WHERE user_id = ?"
        );
        $stmtU->bind_param('ssi', $role, $status, $existingId);
        $stmtU->execute();
        $stmtU->close();

        // Ensure detail row exists AND update provided fields if present
        if ($role === 'acad_head') {
          if (!schoolHeadRowExists($conn, $existingId)) {
            $stmt2 = safe_prepare($conn,
              "INSERT INTO school_heads (user_id, sh_name, sh_email, sh_phone, department)
               VALUES (?,?,?,?,?)"
            );
            $stmt2->bind_param('issss', $existingId, $name, $email, $phone, $department);
            $stmt2->execute();
            $stmt2->close();
          } else {
            $updates = [];
            $typesU  = '';
            $valsU   = [];
            if ($name !== '')       { $updates[] = "sh_name = ?";   $typesU .= 's'; $valsU[] = $name; }
            if ($email !== '')      { $updates[] = "sh_email = ?";  $typesU .= 's'; $valsU[] = $email; }
            if ($phone !== '')      { $updates[] = "sh_phone = ?";  $typesU .= 's'; $valsU[] = $phone; }
            if ($department !== '') { $updates[] = "department = ?";$typesU .= 's'; $valsU[] = $department; }

            if (!empty($updates)) {
              $sqlU = "UPDATE school_heads SET " . implode(', ', $updates) . " WHERE user_id = ?";
              $stmtU = safe_prepare($conn, $sqlU);
              $typesU .= 'i';
              $valsU[] = $existingId;
              $stmtU->bind_param($typesU, ...$valsU);
              $stmtU->execute();
              $stmtU->close();
            }
          }
        }

        if ($role === 'admin') {
          if (!adminRowExists($conn, $existingId)) {
            $stmt3 = safe_prepare($conn,
              "INSERT INTO admins (user_id, full_name, contact_number, department)
               VALUES (?,?,?,?)"
            );
            $stmt3->bind_param('isss', $existingId, $name, $phone, $department);
            $stmt3->execute();
            $stmt3->close();
          } else {
            $updates = [];
            $typesU  = '';
            $valsU   = [];
            if ($name !== '')       { $updates[] = "full_name = ?";      $typesU .= 's'; $valsU[] = $name; }
            if ($phone !== '')      { $updates[] = "contact_number = ?"; $typesU .= 's'; $valsU[] = $phone; }
            if ($department !== '') { $updates[] = "department = ?";     $typesU .= 's'; $valsU[] = $department; }

            if (!empty($updates)) {
              $sqlU = "UPDATE admins SET " . implode(', ', $updates) . " WHERE user_id = ?";
              $stmtU = safe_prepare($conn, $sqlU);
              $typesU .= 'i';
              $valsU[] = $existingId;
              $stmtU->bind_param($typesU, ...$valsU);
              $stmtU->execute();
              $stmtU->close();
            }
          }
        }

        $conn->commit();

        // Return the (possibly updated) user
        $row = findUserById($conn, $existingId);

        echo json_encode([
          "success" => true,
          "status"  => "success",
          "message" => "User upserted (existing email)",
          "data"    => $row
        ]);
        exit();
      }

      // Strict create mode and email already exists -> 409 with details
      if ($existingByEmail && !$UPSERT) {
        $conn->rollback();
        json_fail(409, "Email already exists", ["existing_user" => $existingByEmail]);
      }

      // Create new user
      $stmt = safe_prepare($conn,
        "INSERT INTO users (username, email, role, status, password, created_at, updated_at)
         VALUES (?,?,?,?,?,NOW(),NOW())"
      );
      $stmt->bind_param('sssss', $username, $email, $role, $status, $finalPassword);
      $stmt->execute();
      $newId = (int)$stmt->insert_id;
      $stmt->close();

      if ($role === 'acad_head') {
        $stmt2 = safe_prepare($conn,
          "INSERT INTO school_heads (user_id, sh_name, sh_email, sh_phone, department)
           VALUES (?,?,?,?,?)"
        );
        $stmt2->bind_param('issss', $newId, $name, $email, $phone, $department);
        $stmt2->execute();
        $stmt2->close();
      }
      if ($role === 'admin') {
        $stmt3 = safe_prepare($conn,
          "INSERT INTO admins (user_id, full_name, contact_number, department)
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

      $row = findUserById($conn, $newId);

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
      if ($GLOBALS['DEBUG']) json_fail(500, $e->getMessage(), ["trace" => $e->getTraceAsString()]);
      json_fail(500, "Create failed");
    }
  }

  /* ---------- PUT /users.php?id=XX ---------- */
  if ($method === 'PUT' || $method === 'PATCH') {
    $reqRole = $_SERVER['HTTP_X_USER_ROLE'] ?? $_SERVER['HTTP_X_ROLE'] ?? '';
    if (!in_array($reqRole, ['super_admin', 'admin'], true)) {
      json_fail(403, "Only super_admin/admin can update users");
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) json_fail(400, "Missing or invalid id");

    $target = findUserById($conn, $id);
    if (!$target) json_fail(404, "User not found");

    // Admin CANNOT modify admin/super_admin; may only modify acad_head
    if ($reqRole === 'admin' && strtolower($target['role']) !== 'acad_head') {
      json_fail(403, "Admins can only modify Academic Head accounts");
    }

    $data = read_json_body();
    $newStatus       = isset($data['status']) ? trim((string)$data['status']) : null;
    $newRole         = isset($data['role']) ? trim((string)$data['role']) : null;
    $newEmail        = isset($data['email']) ? trim((string)$data['email']) : null;
    $newUsername     = isset($data['username']) ? trim((string)$data['username']) : null; // optional
    $resetPassword   = isset($data['reset_password']) ? (bool)$data['reset_password'] : false;

    // NEW: detail table fields that may be updated
    $newName        = isset($data['name']) ? trim((string)$data['name']) : null;
    $newDepartment  = isset($data['department']) ? trim((string)$data['department']) : null;
    $newPhone       = isset($data['phone']) ? trim((string)$data['phone']) : null;

    // Validate inputs
    if ($newStatus !== null && !in_array($newStatus, ['active', 'inactive'], true)) {
      json_fail(400, "Invalid status");
    }
    if ($newRole !== null && !in_array($newRole, ['admin', 'acad_head'], true)) {
      json_fail(400, "Invalid role");
    }
    if ($reqRole === 'admin' && $newRole !== null && $newRole !== 'acad_head') {
      json_fail(403, "Admins cannot change role to admin");
    }

    // Uniqueness checks if username/email are being changed
    if ($newUsername !== null && strtolower($newUsername) !== strtolower($target['username'])) {
      $du = findUserByUsername($conn, $newUsername);
      if ($du && (int)$du['id'] !== $id) {
        json_fail(409, "Username already exists", ["existing_user" => $du]);
      }
    }
    if ($newEmail !== null && strtolower($newEmail) !== strtolower($target['email'])) {
      $de = findUserByEmail($conn, $newEmail);
      if ($de && (int)$de['id'] !== $id) {
        json_fail(409, "Email already exists", ["existing_user" => $de]);
      }
    }

    $conn->begin_transaction();
    try {
      // Build dynamic update for users table
      $sets = [];
      $params = [];
      $types = '';

      if ($newStatus !== null)     { $sets[] = "status = ?";   $params[] = $newStatus;     $types .= 's'; }
      if ($newRole   !== null)     { $sets[] = "role = ?";     $params[] = $newRole;       $types .= 's'; }
      if ($newEmail  !== null)     { $sets[] = "email = ?";    $params[] = $newEmail;      $types .= 's'; }
      if ($newUsername !== null)   { $sets[] = "username = ?"; $params[] = $newUsername;   $types .= 's'; }

      $tempPassword = null;
      if ($resetPassword) {
        $tempPassword = random_password(10);
        $sets[] = "password = ?";
        $params[] = $tempPassword;
        $types .= 's';
      }

      if (!empty($sets)) {
        $sql = "UPDATE users SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE user_id = ?";
        $stmt = safe_prepare($conn, $sql);
        $types .= 'i';
        $params[] = $id;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
      }

      // If role changed or not, ensure detail row exists AND update provided fields
      $effectiveRole = $newRole !== null ? $newRole : $target['role'];

      if ($effectiveRole === 'acad_head') {
        // Ensure row exists
        if (!schoolHeadRowExists($conn, $id)) {
          $stmt2 = safe_prepare($conn,
            "INSERT INTO school_heads (user_id, sh_name, sh_email, sh_phone, department)
             VALUES (?,?,?,?,?)"
          );
          $nameForRow   = $newName   !== null ? $newName   : '';
          $emailForRow  = $newEmail  !== null ? $newEmail  : ($target['email'] ?? '');
          $phoneForRow  = $newPhone  !== null ? $newPhone  : '';
          $deptForRow   = $newDepartment !== null ? $newDepartment : '';
          $stmt2->bind_param('issss', $id, $nameForRow, $emailForRow, $phoneForRow, $deptForRow);
          $stmt2->execute();
          $stmt2->close();
        } else {
          $updates = [];
          $typesU  = '';
          $valsU   = [];

          if ($newName !== null)       { $updates[] = "sh_name = ?";   $typesU .= 's'; $valsU[] = $newName; }
          if ($newEmail !== null)      { $updates[] = "sh_email = ?";  $typesU .= 's'; $valsU[] = $newEmail; }
          if ($newPhone !== null)      { $updates[] = "sh_phone = ?";  $typesU .= 's'; $valsU[] = $newPhone; }
          if ($newDepartment !== null) { $updates[] = "department = ?";$typesU .= 's'; $valsU[] = $newDepartment; }

          if (!empty($updates)) {
            $sqlU = "UPDATE school_heads SET " . implode(', ', $updates) . " WHERE user_id = ?";
            $stmtU = safe_prepare($conn, $sqlU);
            $typesU .= 'i';
            $valsU[] = $id;
            $stmtU->bind_param($typesU, ...$valsU);
            $stmtU->execute();
            $stmtU->close();
          }
        }
      }

      if ($effectiveRole === 'admin') {
        // Ensure row exists
        if (!adminRowExists($conn, $id)) {
          $stmt3 = safe_prepare($conn,
            "INSERT INTO admins (user_id, full_name, contact_number, department)
             VALUES (?,?,?,?)"
          );
          $nameForRow  = $newName   !== null ? $newName   : '';
          $phoneForRow = $newPhone  !== null ? $newPhone  : '';
          $deptForRow  = $newDepartment !== null ? $newDepartment : '';
          $stmt3->bind_param('isss', $id, $nameForRow, $phoneForRow, $deptForRow);
          $stmt3->execute();
          $stmt3->close();
        } else {
          $updates = [];
          $typesU  = '';
          $valsU   = [];

          if ($newName !== null)       { $updates[] = "full_name = ?";      $typesU .= 's'; $valsU[] = $newName; }
          if ($newPhone !== null)      { $updates[] = "contact_number = ?"; $typesU .= 's'; $valsU[] = $newPhone; }
          if ($newDepartment !== null) { $updates[] = "department = ?";     $typesU .= 's'; $valsU[] = $newDepartment; }

          if (!empty($updates)) {
            $sqlU = "UPDATE admins SET " . implode(', ', $updates) . " WHERE user_id = ?";
            $stmtU = safe_prepare($conn, $sqlU);
            $typesU .= 'i';
            $valsU[] = $id;
            $stmtU->bind_param($typesU, ...$valsU);
            $stmtU->execute();
            $stmtU->close();
          }
        }
      }

      $conn->commit();

      // Reload with name
      $updated = findUserById($conn, $id);

      // Activity log
      $action = $resetPassword ? 'reset_password' : 'update';
      log_activity(
        $conn,
        'users',
        $action,
        ($resetPassword ? "Password reset for user id={$id}" : "Updated user id={$id}"),
        $id,
        null
      );

      $payload = [
        "message" => $resetPassword ? "Password reset" : "User updated",
        "data"    => [
          "id"         => (int)$updated['id'],
          "username"   => $updated['username'],
          "email"      => $updated['email'],
          "name"       => $updated['name'],
          "role"       => $updated['role'],
          "status"     => $updated['status'],
          "last_login" => null,
        ],
      ];

      // Optionally email the new password — now with the resolved name
      if ($resetPassword && !$NOEMAIL) {
        @send_account_email(
          $updated['email'],
          $updated['name'] ?: $updated['username'],
          $updated['username'],
          $tempPassword,
          $updated['role']
        );
        $payload["email_sent"] = true;
      }

      json_ok($payload);
    } catch (Exception $e) {
      $conn->rollback();
      if ($GLOBALS['DEBUG']) json_fail(500, $e->getMessage(), ["trace" => $e->getTraceAsString()]);
      json_fail(500, "Update failed");
    }
  }

  json_fail(405, "Method not allowed");

} catch (Throwable $e) {
  if ($DEBUG) json_fail(500, $e->getMessage(), ["trace" => $e->getTraceAsString()]);
  json_fail(500, "Server error");
}
