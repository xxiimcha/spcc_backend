<?php
header('Content-Type: application/json; charset=utf-8');
$result = [
  'php_version' => PHP_VERSION,
  'extensions' => [
    'zip' => extension_loaded('zip'),
    'gd'  => extension_loaded('gd'),
    'mbstring' => extension_loaded('mbstring'),
  ],
  'paths' => [
    'cwd' => getcwd(),
    'vendor_autoload' => __DIR__ . '/vendor/autoload.php',
    'vendor_autoload_exists' => file_exists(__DIR__ . '/vendor/autoload.php'),
  ],
  'db' => null,
  'tables' => [],
  'counts' => [],
  'errors' => [],
];

require_once __DIR__ . '/connect.php';
try {
  if (!isset($conn) || !($conn instanceof mysqli)) {
    if (!isset($host, $user, $password, $database)) {
      throw new Exception('No mysqli $conn and no creds from connect.php');
    }
    $conn = @new mysqli($host, $user, $password, $database);
  }
  if ($conn->connect_error) throw new Exception($conn->connect_error);
  $result['db'] = 'ok';

  // helper
  $colExists = function(mysqli $conn, string $table, string $col): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($col);
    $sql = "SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = '{$t}'
              AND column_name = '{$c}'
            LIMIT 1";
    $res = $conn->query($sql);
    $ok = ($res && $res->num_rows > 0);
    if ($res) $res->free();
    return $ok;
  };

  // list of tables we touch
  $tables = [
    'sections','subjects','professors','rooms',
    'section_room_assignments','schedules'
  ];
  foreach ($tables as $t) {
    $exists = false;
    $res = $conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($t)."'");
    if ($res) { $exists = $res->num_rows > 0; $res->free(); }
    $result['tables'][$t] = [
      'exists' => $exists,
      'has_school_year_col' => $exists ? $colExists($conn,$t,'school_year') : false
    ];
  }

  // count rows for the selected school_year (change default if you want)
  $sy = isset($_GET['school_year']) ? trim($_GET['school_year']) : '2024-2025';
  $esc = fn($v) => $conn->real_escape_string($v);

  foreach ($tables as $t) {
    if (!$result['tables'][$t]['exists']) { $result['counts'][$t] = 'table_missing'; continue; }
    if ($result['tables'][$t]['has_school_year_col']) {
      $sql = "SELECT COUNT(*) c FROM `$t` WHERE school_year='".$esc($sy)."'";
    } else {
      $sql = "SELECT COUNT(*) c FROM `$t`";
    }
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : ['c' => null];
    if ($res) $res->free();
    $result['counts'][$t] = $row['c'];
  }

} catch (Throwable $e) {
  $result['errors'][] = $e->getMessage();
}
echo json_encode($result, JSON_PRETTY_PRINT);
