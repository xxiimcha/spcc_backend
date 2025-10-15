<?php
// school_years.php — Returns ["2024-2025","2023-2024", ...] without requiring a school_years table
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__.'/connect.php';
@require_once __DIR__.'/activity_logger.php'; // optional

function respond(int $code, $payload) {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit();
}
function log_safe($conn, $status, $msg) {
  if (function_exists('log_activity')) {
    @log_activity($conn, 'school_years', $status, $msg, null, null);
  }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn = new mysqli($host, $user, $password, $database);
  $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
  respond(500, ["success"=>false,"status"=>"error","message"=>"Database connection failed"]);
}

function table_exists(mysqli $conn, string $table): bool {
  try {
    $t = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$t'");
    return $res && $res->num_rows > 0;
  } catch (Throwable $e) {
    return false;
  }
}
function column_exists(mysqli $conn, string $table, string $column): bool {
  try {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $res && $res->num_rows > 0;
  } catch (Throwable $e) {
    return false;
  }
}
function fetch_col(mysqli $conn, string $sql): array {
  $out = [];
  $res = $conn->query($sql);
  while ($row = $res->fetch_row()) {
    if (isset($row[0])) {
      $val = trim((string)$row[0]);
      if ($val !== '') $out[] = $val;
    }
  }
  return $out;
}

$years = [];

/** 1) system_settings.school_year_list (JSON array) */
if (table_exists($conn, 'system_settings') && column_exists($conn, 'system_settings', 'key')) {
  try {
    $res = $conn->query("SELECT `value` FROM `system_settings` WHERE `key`='school_year_list' LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
      $decoded = json_decode((string)$row['value'], true);
      if (is_array($decoded)) {
        foreach ($decoded as $v) {
          if (is_string($v) && ($v = trim($v)) !== '') $years[] = $v;
        }
      }
    }
  } catch (Throwable $e) {
    // ignore, fallback next
  }
}

/** 2) Fallback: union distinct school_year from any tables having that column */
if (empty($years)) {
  try {
    $dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
    $dbName = $conn->real_escape_string($dbName);

    $colQry = "
      SELECT TABLE_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = '{$dbName}' AND COLUMN_NAME = 'school_year'
    ";
    $tblRes = $conn->query($colQry);
    $tables = [];
    while ($r = $tblRes->fetch_assoc()) $tables[] = $r['TABLE_NAME'];

    $distinct = [];
    foreach ($tables as $t) {
      $tSafe = "`".str_replace("`","``",$t)."`";
      $vals = fetch_col($conn, "SELECT DISTINCT `school_year` FROM $tSafe WHERE `school_year` IS NOT NULL AND `school_year`<>''");
      foreach ($vals as $v) $distinct[$v] = true;
    }
    $years = array_keys($distinct);
  } catch (Throwable $e) {
    // ignore, fallback next
  }
}

/** 3) Final safety net: synthesize */
if (empty($years)) {
  $y = (int)date('Y');
  $years = [ "{$y}-".($y+1), ($y-1)."-{$y}" ];
}

/** Clean, dedupe, sort (latest first) */
$years = array_values(array_unique(array_filter($years, fn($s) => is_string($s) && trim($s) !== '')));
rsort($years, SORT_STRING);

log_safe($conn, 'success', 'Returned '.count($years).' school years');
respond(200, $years);
