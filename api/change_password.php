<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit();
}

include '../connect.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "Database connection not available"]);
  exit();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function json_fail(int $code, string $message): void {
  http_response_code($code);
  echo json_encode(["success" => false, "message" => $message], JSON_UNESCAPED_SLASHES);
  exit();
}
function json_ok(array $extra = []): void {
  http_response_code(200);
  echo json_encode(array_merge(["success" => true], $extra), JSON_UNESCAPED_SLASHES);
  exit();
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
if (!is_array($body)) {
  json_fail(400, "Invalid JSON body.");
}

$user_id = isset($body['user_id']) ? trim((string)$body['user_id']) : '';
$current_password = isset($body['current_password']) ? (string)$body['current_password'] : '';
$new_password = isset($body['new_password']) ? (string)$body['new_password'] : '';

if ($user_id === '' || $current_password === '' || $new_password === '') {
  json_fail(400, "Missing required fields: user_id, current_password, new_password.");
}
if (strlen($new_password) < 8) {
  json_fail(400, "New password must be at least 8 characters.");
}
if (hash_equals($current_password, $new_password)) {
  json_fail(400, "New password must be different from current password.");
}

// ---- CONFIG: table & columns ----
$table = 'professors';
$col_id = 'id';
$col_plain = 'password';             // plaintext column to use
$col_hash = 'password_hash';         // legacy hash column (will be nulled)
$col_updated_at = 'password_updated_at'; // optional DATETIME/TIMESTAMP

try {
  // 1) Fetch user row
  $sql = "SELECT $col_id, $col_plain, $col_hash FROM $table WHERE $col_id = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('s', $user_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res->num_rows === 0) {
    json_fail(404, "Account not found.");
  }
  $row = $res->fetch_assoc();
  $stmt->close();

  $stored_plain = $row[$col_plain] ?? null;
  $stored_hash  = $row[$col_hash] ?? null;

  // 2) Verify current password
  // Prefer plaintext column if present; else accept legacy hash
  $verified = false;
  if ($stored_plain !== null && $stored_plain !== '') {
    $verified = hash_equals((string)$stored_plain, $current_password);
  } elseif (!empty($stored_hash)) {
    // Allow change if the user previously had a hashed password
    $verified = password_verify($current_password, $stored_hash);
  }

  if (!$verified) {
    json_fail(401, "Current password is incorrect.");
  }

  // 3) Update to NEW PLAINTEXT password; clear legacy hash; set updated_at
  $now = date('Y-m-d H:i:s');
  if ($col_updated_at) {
    $sqlUp = "UPDATE $table SET $col_plain = ?, $col_hash = NULL, $col_updated_at = ? WHERE $col_id = ?";
    $stmtUp = $conn->prepare($sqlUp);
    $stmtUp->bind_param('sss', $new_password, $now, $user_id);
  } else {
    $sqlUp = "UPDATE $table SET $col_plain = ?, $col_hash = NULL WHERE $col_id = ?";
    $stmtUp = $conn->prepare($sqlUp);
    $stmtUp->bind_param('ss', $new_password, $user_id);
  }

  $stmtUp->execute();
  $affected = $stmtUp->affected_rows;
  $stmtUp->close();

  if ($affected < 0) {
    json_fail(500, "Failed to update password.");
  }

  json_ok(["message" => "Password updated."]);
} catch (mysqli_sql_exception $e) {
  json_fail(500, "Database error: " . $e->getMessage());
} catch (Throwable $e) {
  json_fail(500, "Server error: " . $e->getMessage());
}
