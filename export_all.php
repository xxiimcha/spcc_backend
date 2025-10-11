<?php
/**
 * export_all.php  — detailed logging version
 * Usage:
 *   /export_all.php?school_year=2024-2025
 *   (optional) &semester=First%20Semester
 *   (optional) &debug=1   -> returns JSON with error details
 */

ini_set('memory_limit', '1024M');
set_time_limit(300);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
ini_set('display_errors', $DEBUG ? '1' : '0');
ini_set('log_errors', '1');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

/* ---------------- Logging setup ---------------- */
$LOG_DIR  = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($LOG_DIR)) { @mkdir($LOG_DIR, 0777, true); }
$LOG_FILE = $LOG_DIR . DIRECTORY_SEPARATOR . ('export_' . date('Ymd') . '.log');
ini_set('error_log', $LOG_DIR . DIRECTORY_SEPARATOR . 'php_errors.log');

function log_line(string $msg, array $ctx = []): void {
  global $LOG_FILE;
  $line = '[' . date('c') . '] ' . $msg;
  if (!empty($ctx)) $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES);
  $line .= PHP_EOL;
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

function fail_json($code, $msg, $extra = []) {
  log_line('FAIL_JSON', ['code'=>$code, 'msg'=>$msg, 'extra'=>$extra]);
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $msg] + $extra);
  exit();
}

// Turn warnings/notices into exceptions so we can log/catch them
set_error_handler(function($severity, $message, $file, $line) {
  if (!(error_reporting() & $severity)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
});

// Catch fatals too
register_shutdown_function(function() {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    log_line('FATAL', ['type'=>$e['type'], 'message'=>$e['message'], 'file'=>$e['file'], 'line'=>$e['line']]);
  }
});

/* -------------- Request/env breadcrumbs -------------- */
log_line('REQUEST_BEGIN', [
  'uri'  => $_SERVER['REQUEST_URI'] ?? '',
  'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
  'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
  'php'  => PHP_VERSION,
  'temp' => sys_get_temp_dir(),
  'params' => $_GET,
]);

require_once __DIR__ . '/connect.php';

try {
  /* ---------- DB ---------- */
  $t0 = microtime(true);
  if (!isset($conn) || !($conn instanceof mysqli)) {
    if (!isset($host, $user, $password, $database)) {
      throw new RuntimeException('Database credentials not available from connect.php');
    }
    $conn = @new mysqli($host, $user, $password, $database);
  }
  if ($conn->connect_error) throw new RuntimeException('DB connection failed: '.$conn->connect_error);
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  log_line('DB_CONNECTED', ['elapsed_ms' => round((microtime(true)-$t0)*1000,2)]);

  /* ---------- Inputs ---------- */
  $sy  = isset($_GET['school_year']) ? trim($_GET['school_year']) : '';
  $sem = isset($_GET['semester']) ? trim($_GET['semester']) : '';
  if (!preg_match('/^\d{4}\-\d{4}$/', $sy)) {
    throw new InvalidArgumentException('Missing or invalid school_year. Example: 2024-2025');
  }
  $esc = fn($v) => $conn->real_escape_string($v);
  log_line('INPUTS', ['school_year'=>$sy, 'semester'=>$sem]);

  /* ---------- Util ---------- */
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

  $qAll = function(mysqli $conn, string $sql, string $label) {
    $start = microtime(true);
    try {
      $res = $conn->query($sql);
      $rows = [];
      if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; $res->free(); }
      log_line('SQL_OK', [
        'label' => $label,
        'elapsed_ms' => round((microtime(true)-$start)*1000,2),
        'rows' => count($rows),
        'sql' => $sql,
      ]);
      return $rows;
    } catch (Throwable $e) {
      log_line('SQL_ERR', [
        'label' => $label,
        'elapsed_ms' => round((microtime(true)-$start)*1000,2),
        'sql' => $sql,
        'error' => $e->getMessage(),
      ]);
      throw $e;
    }
  };

  /* ---------- Queries ---------- */
  $whereSy    = " WHERE school_year = '".$esc($sy)."' ";
  $whereSySem = $whereSy . ($sem !== '' ? " AND semester = '".$esc($sem)."'" : "");

  $sections   = $qAll($conn, "SELECT * FROM sections" . ($colExists($conn,'sections','school_year') ? $whereSy : ""), 'sections');
  $subjects   = $qAll($conn, "SELECT * FROM subjects" . ($colExists($conn,'subjects','school_year') ? $whereSy : ""), 'subjects');
  $professors = $qAll($conn, "SELECT * FROM professors" . ($colExists($conn,'professors','school_year') ? $whereSy : ""), 'professors');
  $rooms      = $qAll($conn, "SELECT * FROM rooms" . ($colExists($conn,'rooms','school_year') ? $whereSy : ""), 'rooms');
  $sra        = $qAll($conn, "SELECT * FROM section_room_assignments" . ($colExists($conn,'section_room_assignments','school_year') ? $whereSy : ""), 'section_room_assignments');

  $schedulesSql = "
    SELECT
      s.id,
      s.section_id,
      sec.name AS section_name,
      s.subject_id,
      sub.code AS subject_code,
      sub.name AS subject_name,
      s.professor_id,
      prof.name AS professor_name,
      s.day,
      s.time_start,
      s.time_end,
      s.room,
      s.semester,
      s.school_year
    FROM schedules s
    LEFT JOIN sections sec ON sec.id = s.section_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    LEFT JOIN professors prof ON prof.id = s.professor_id
    $whereSySem
    ORDER BY sec.name, s.day, s.time_start
  ";
  $schedules = $qAll($conn, $schedulesSql, 'schedules_join');

  /* ---------- PhpSpreadsheet ---------- */
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (!file_exists($autoload)) throw new RuntimeException('vendor/autoload.php not found. Run: composer require phpoffice/phpspreadsheet');
  if (!extension_loaded('zip')) throw new RuntimeException('PHP extension "zip" is required. Enable it in php.ini and restart Apache.');
  require_once $autoload;
  log_line('PHPSPREADSHEET_READY');

  // sheet helper
  $addSheet = function(\PhpOffice\PhpSpreadsheet\Spreadsheet $wb, string $title, array $rows): void {
    $sheet = $wb->createSheet();
    $sheet->setTitle(substr(preg_replace('/[\\\\\\/*?:\\[\\]]/', '_', $title), 0, 31));
    if (empty($rows)) { $sheet->setCellValue('A1', 'No data'); return; }
    $headers = array_keys($rows[0]);
    foreach ($headers as $i => $h) { $sheet->setCellValueByColumnAndRow($i+1, 1, $h); }
    $r = 2;
    foreach ($rows as $row) {
      foreach ($headers as $i => $h) { $sheet->setCellValueByColumnAndRow($i+1, $r, $row[$h] ?? null); }
      $r++;
    }
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
    for ($i=1; $i<=count($headers); $i++) $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
  };

  // workbook
  $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $wb->getProperties()
     ->setCreator('SPCC Scheduling System')
     ->setLastModifiedBy('SPCC Scheduling System')
     ->setTitle("Compiled Data $sy" . ($sem ? " - $sem" : ""))
     ->setSubject('Compiled Export')
     ->setDescription('All recorded data per school year');

  $summary = $wb->getActiveSheet();
  $summary->setTitle('Summary');
  $summary->setCellValue('A1', 'SPCC Compiled Export');
  $summary->setCellValue('A2', 'School Year:');
  $summary->setCellValue('B2', $sy);
  $summary->setCellValue('A3', 'Semester:');
  $summary->setCellValue('B3', ($sem ?: 'All / Not specified'));
  $summary->getStyle('A1')->getFont()->setBold(true)->setSize(14);
  $summary->getColumnDimension('A')->setAutoSize(true);
  $summary->getColumnDimension('B')->setAutoSize(true);

  $addSheet($wb, 'Sections', $sections);
  $addSheet($wb, 'Subjects', $subjects);
  $addSheet($wb, 'Professors', $professors);
  $addSheet($wb, 'Rooms', $rooms);
  $addSheet($wb, 'Schedules', $schedules);
  $addSheet($wb, 'Section_Room_Assign', $sra);
  log_line('WORKBOOK_BUILT');

  /* ---------- Stream XLSX ---------- */
  ini_set('zlib.output_compression', 'Off');
  while (ob_get_level()) { ob_end_clean(); }

  $tmpDir = sys_get_temp_dir();
  if (!is_writable($tmpDir)) {
    $tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
    if (!is_writable($tmpDir)) throw new RuntimeException('Temp dir not writable: ' . $tmpDir);
  }
  $tmp = tempnam($tmpDir, 'spcc_export_');
  if ($tmp === false) throw new RuntimeException('tempnam() failed for dir: ' . $tmpDir);

  $tSave = microtime(true);
  $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($wb);
  $writer->save($tmp);
  log_line('FILE_SAVED', [
    'path' => $tmp,
    'elapsed_ms' => round((microtime(true)-$tSave)*1000,2),
    'size' => filesize($tmp),
    'memory_peak' => memory_get_peak_usage(true),
  ]);

  $fname = "SPCC_Compiled_{$sy}" . ($sem ? "_".preg_replace('/\s+/', '', $sem) : "") . ".xlsx";
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="'.$fname.'"');
  header('Content-Transfer-Encoding: binary');
  header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
  header('Pragma: public');
  header('Expires: 0');
  header('Content-Length: ' . filesize($tmp));

  $tOut = microtime(true);
  $fp = fopen($tmp, 'rb'); fpassthru($fp); fclose($fp);
  @unlink($tmp);
  log_line('RESPONSE_STREAMED', ['elapsed_ms' => round((microtime(true)-$tOut)*1000,2)]);
  exit;

} catch (Throwable $e) {
  // log full details always
  log_line('EXCEPTION', [
    'class' => get_class($e),
    'message' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'trace' => $e->getTraceAsString(),
  ]);

  if ($DEBUG) {
    fail_json(500, $e->getMessage(), [
      'class' => get_class($e),
      'file'  => $e->getFile(),
      'line'  => $e->getLine(),
      'trace' => $e->getTraceAsString(),
    ]);
  } else {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Export failed. See server logs for details.']);
    exit();
  }
}
