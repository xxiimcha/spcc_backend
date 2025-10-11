<?php
// school_years.php (no prepared statements)
// Returns a JSON array of school years, e.g. ["2023-2024","2024-2025"]
// Optional query params:
//   - order=asc|desc  (default: desc)
//   - format=select   (returns [{value,label}, ...])

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once __DIR__ . '/connect.php';

// Ensure connection exists
if (!isset($conn) || !($conn instanceof mysqli)) {
  if (!isset($host, $user, $password, $database)) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database credentials not available"]);
    exit();
  }
  $conn = @new mysqli($host, $user, $password, $database);
}

if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "DB connection failed: " . $conn->connect_error]);
  exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------- helpers (no prepared statements) ----------
$sanitizeIdent = function(string $s): string {
  // allow only letters, numbers, and underscore for identifiers
  return preg_match('/^[A-Za-z0-9_]+$/', $s) ? $s : '';
};

$hasTable = function(string $table) use ($conn, $sanitizeIdent): bool {
  $t = $sanitizeIdent($table);
  if ($t === '') return false;
  $sql = "SELECT 1 FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = '{$t}' LIMIT 1";
  $res = $conn->query($sql);
  $ok = ($res && $res->num_rows > 0);
  if ($res) $res->free();
  return $ok;
};

$hasColumn = function(string $table, string $col) use ($conn, $sanitizeIdent): bool {
  $t = $sanitizeIdent($table);
  $c = $sanitizeIdent($col);
  if ($t === '' || $c === '') return false;
  $sql = "SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = '{$t}' AND column_name = '{$c}' LIMIT 1";
  $res = $conn->query($sql);
  $ok = ($res && $res->num_rows > 0);
  if ($res) $res->free();
  return $ok;
};

$getDistinctCol = function(string $table, string $col) use ($conn, $sanitizeIdent): array {
  $t = $sanitizeIdent($table);
  $c = $sanitizeIdent($col);
  if ($t === '' || $c === '') return [];
  // identifiers are sanitized; wrap with backticks
  $sql = "SELECT DISTINCT `$c` AS sy FROM `$t` WHERE `$c` IS NOT NULL AND `$c` <> ''";
  $res = $conn->query($sql);
  $out = [];
  if ($res) {
    while ($row = $res->fetch_assoc()) {
      $out[] = trim((string)$row['sy']);
    }
    $res->free();
  }
  return $out;
};

$normalizeSY = function(array $list): array {
  $out = [];
  foreach ($list as $v) {
    $v = trim((string)$v);
    if (preg_match('/^\d{4}\-\d{4}$/', $v)) {
      $out[] = $v;
    }
  }
  return array_values(array_unique($out));
};

$sortSY = function(array $list, string $order): array {
  usort($list, function($a, $b) use ($order) {
    $ay = (int)substr($a, 0, 4);
    $by = (int)substr($b, 0, 4);
    if ($ay === $by) return 0;
    return ($order === 'asc') ? ($ay <=> $by) : ($by <=> $ay);
  });
  return $list;
};

$generateFallback = function(int $count = 3): array {
  $y = (int)date('Y');
  $baseStart = $y - 1;
  $out = [];
  for ($i = 0; $i < $count; $i++) {
    $start = $baseStart + $i;
    $out[] = $start . '-' . ($start + 1);
  }
  return $out;
};

// ---------- main ----------
try {
  $order = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'asc' : 'desc';
  $formatSelect = (isset($_GET['format']) && strtolower($_GET['format']) === 'select');

  $candidates = [];

  // 1) Prefer dedicated school_years table if present
  if ($hasTable('school_years')) {
    if ($hasColumn('school_years', 'school_year')) {
      $candidates = array_merge($candidates, $getDistinctCol('school_years', 'school_year'));
    } elseif ($hasColumn('school_years', 'name')) {
      $candidates = array_merge($candidates, $getDistinctCol('school_years', 'name'));
    } elseif ($hasColumn('school_years', 'label')) {
      $candidates = array_merge($candidates, $getDistinctCol('school_years', 'label'));
    }
  }

  // 2) schedules table
  if ($hasTable('schedules') && $hasColumn('schedules', 'school_year')) {
    $candidates = array_merge($candidates, $getDistinctCol('schedules', 'school_year'));
  }

  // 3) sections table
  if ($hasTable('sections') && $hasColumn('sections', 'school_year')) {
    $candidates = array_merge($candidates, $getDistinctCol('sections', 'school_year'));
  }

  $years = $normalizeSY($candidates);

  if (count($years) === 0) {
    $years = $generateFallback(4);
  }

  $years = $sortSY($years, $order);

  if ($formatSelect) {
    $items = array_map(fn($sy) => ["value" => $sy, "label" => $sy], $years);
    echo json_encode($items, JSON_UNESCAPED_UNICODE);
  } else {
    echo json_encode($years, JSON_UNESCAPED_UNICODE);
  }
  exit();
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "Error: " . $e->getMessage()
  ]);
  exit();
}
