<?php
// change_password.php (NO HASHING, NO PREPARED STATEMENTS, with logs)
declare(strict_types=1);

header('Access-Control-Allow-Origin: *'); // tighten in prod
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit();
}

// ---- Logging (writes to /logs) ----
$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) { @mkdir($LOG_DIR, 0775, true); }
function log_line(string $msg, array $ctx = []): void {
  global $LOG_DIR;
  $line = '['.date('c').'] '.$msg.(empty($ctx) ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES));
  @file_put_contents($LOG_DIR.'/change_password.log', $line.PHP_EOL, FILE_APPEND);
}

require_once __DIR__ . '/../connect.php'; // adjust path if needed

if (!isset($conn) || !($conn instanceof mysqli)) {
  log_line('DB not available');
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "Database connection not available"]);
  exit();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function json_fail(int $code, string $message, array $ctx = []): void {
  log_line("FAIL $code: $message", $ctx);
  http_response_code($code);
  echo json_encode(["success" => false, "message" => $message], JSON_UNESCAPED_SLASHES);
  exit();
}
function json_ok(array $extra = []): void {
  log_line("OK", $extra);
  http_response_code(200);
  echo json_encode(array_merge(["success" => true], $extra), JSON_UNESCAPED_SLASHES);
  exit();
}
function qstr(mysqli $c, string $s): string {
  return "'" . $c->real_escape_string($s) . "'";
}

// ---- Read JSON body ----
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
if (!is_array($body)) {
  json_fail(400, "Invalid JSON body.", ['raw'=>$raw]);
}

$user_id          = isset($body['user_id']) ? trim((string)$body['user_id']) : '';
$current_password = isset($body['current_password']) ? (string)$body['current_password'] : '';
$new_password     = isset($body['new_password']) ? (string)$body['new_password'] : '';

if ($user_id === '' || $current_password === '' || $new_password === '') {
  json_fail(400, "Missing required fields: user_id, current_password, new_password.");
}
if (strlen($new_password) < 8) {
  json_fail(400, "New password must be at least 8 characters.");
}
if (hash_equals($current_password, $new_password)) {
  json_fail(400, "New password must be different from current password.");
}

// ---- CONFIG ----
$table          = 'professors';
$col_id         = 'id';                    // INT or VARCHAR
$col_plain      = 'password';              // plaintext col (your current system)
$col_hash       = 'password_hash';         // legacy hash col (we will NULL it)
$col_updated_at = 'password_updated_at';   // DATETIME/TIMESTAMP (optional)

// Build WHERE for id (numeric vs string)
$is_numeric_id = ctype_digit($user_id);
$where_id = $is_numeric_id ? ($col_id . " = " . (int)$user_id)
                           : ($col_id . " = " . qstr($conn, $user_id));

try {
  // 1) Fetch row (no prepared statements)
  $sql = "SELECT $col_id, $col_plain, $col_hash FROM $table WHERE $where_id LIMIT 1";
  $res = $conn->query($sql);
  if (!$res || $res->num_rows === 0) {
    json_fail(404, "Account not found.", ['user_id'=>$user_id]);
  }
  $row = $res->fetch_assoc();
  $res->free();

  $stored_plain = $row[$col_plain] ?? null;
  $stored_hash  = $row[$col_hash] ?? null;

  // 2) Verify current password (prefer plaintext; fallback to legacy hash)
  $verified = false;
  if ($stored_plain !== null && $stored_plain !== '') {
    $verified = hash_equals((string)$stored_plain, $current_password);
  } elseif (!empty($stored_hash)) {
    $verified = password_verify($current_password, $stored_hash);
  }
  if (!$verified) {
    json_fail(401, "Current password is incorrect.");
  }

  // 3) Update plaintext password; null out hash; set updated_at
  $now = date('Y-m-d H:i:s');
  $set_updated_at = $col_updated_at ? ", $col_updated_at = " . qstr($conn, $now) : "";
  $sqlUp = "UPDATE $table 
            SET $col_plain = " . qstr($conn, $new_password) . ", 
                $col_hash = NULL
                $set_updated_at
            WHERE $where_id
            LIMIT 1";

  $ok = $conn->query($sqlUp);
  if (!$ok) {
    json_fail(500, "Failed to update password.");
  }

  json_ok(["message" => "Password updated."]);
} catch (mysqli_sql_exception $e) {
  json_fail(500, "Database error.", ['err'=>$e->getMessage()]);
} catch (Throwable $e) {
  json_fail(500, "Server error.", ['err'=>$e->getMessage()]);
}
