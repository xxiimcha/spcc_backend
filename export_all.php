<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
ini_set('display_errors', $DEBUG ? '1' : '0');
ini_set('log_errors', '1');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

/* ---------------- Logging ---------------- */
$LOG_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
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
  echo json_encode(['ok'=>false,'error'=>$msg] + $extra);
  exit();
}

set_error_handler(function($severity, $message, $file, $line){
  if (!(error_reporting() & $severity)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    log_line('FATAL', $e);
  }
});

log_line('REQUEST_BEGIN', [
  'uri'  => $_SERVER['REQUEST_URI'] ?? '',
  'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
  'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
  'php'  => PHP_VERSION,
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
  $conn->set_charset('utf8mb4');
  log_line('DB_CONNECTED', ['elapsed_ms'=>round((microtime(true)-$t0)*1000,2)]);

  /* ---------- Inputs ---------- */
  $sy  = isset($_GET['school_year']) ? trim($_GET['school_year']) : '';
  $sem = isset($_GET['semester']) ? trim($_GET['semester']) : '';
  if (!preg_match('/^\d{4}\-\d{4}$/', $sy)) throw new InvalidArgumentException('Missing or invalid school_year. Example: 2024-2025');
  $esc = fn($v) => $conn->real_escape_string($v);
  log_line('INPUTS', ['school_year'=>$sy, 'semester'=>$sem]);

  /* ---------- Helpers ---------- */
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
  $firstExisting = function(mysqli $conn, string $table, array $candidates) use ($colExists) {
    foreach ($candidates as $c) { if ($colExists($conn, $table, $c)) return $c; }
    return null;
  };
  $qAll = function(mysqli $conn, string $sql, string $label) {
    $start = microtime(true);
    try {
      $res = $conn->query($sql);
      $rows = [];
      if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; $res->free(); }
      log_line('SQL_OK', ['label'=>$label, 'elapsed_ms'=>round((microtime(true)-$start)*1000,2), 'rows'=>count($rows), 'sql'=>$sql]);
      return $rows;
    } catch (Throwable $e) {
      log_line('SQL_ERR', ['label'=>$label, 'elapsed_ms'=>round((microtime(true)-$start)*1000,2), 'sql'=>$sql, 'error'=>$e->getMessage()]);
      throw $e;
    }
  };

  /* ---------- Sheets (filter by school_year if present) ---------- */
  $whereSy    = " WHERE school_year = '".$esc($sy)."' ";
  $sections   = $qAll($conn, "SELECT * FROM sections"   . ($colExists($conn,'sections','school_year')   ? $whereSy : ""), 'sections');
  $subjects   = $qAll($conn, "SELECT * FROM subjects"   . ($colExists($conn,'subjects','school_year')   ? $whereSy : ""), 'subjects');
  $professors = $qAll($conn, "SELECT * FROM professors" . ($colExists($conn,'professors','school_year') ? $whereSy : ""), 'professors');
  // no rooms sheet

  /* ---------- Schedules (schema-adaptive) ---------- */
  $schedIdCol   = $firstExisting($conn, 'schedules', ['schedule_id','id']);
  $secIdCol     = $firstExisting($conn, 'schedules', ['section_id']);
  $subjIdCol    = $firstExisting($conn, 'schedules', ['subj_id','subject_id']);
  $profIdCol    = $firstExisting($conn, 'schedules', ['prof_id','professor_id']);
  $roomIdCol    = $firstExisting($conn, 'schedules', ['room_id','room']);
  $startCol     = $firstExisting($conn, 'schedules', ['start_time','time_start']);
  $endCol       = $firstExisting($conn, 'schedules', ['end_time','time_end']);
  $dayCol       = $firstExisting($conn, 'schedules', ['days','day']);
  $semCol       = $firstExisting($conn, 'schedules', ['semester']);
  $syCol        = $firstExisting($conn, 'schedules', ['school_year']);

  $secPK        = $firstExisting($conn, 'sections', ['section_id','id']);
  $secName      = $firstExisting($conn, 'sections', ['section_name','name']);
  $subjPK       = $firstExisting($conn, 'subjects', ['subj_id','id','subject_id']);
  $subjCode     = $firstExisting($conn, 'subjects', ['subj_code','code']);
  $subjName     = $firstExisting($conn, 'subjects', ['subj_name','name']);
  $profPK       = $firstExisting($conn, 'professors', ['prof_id','id','professor_id']);
  $profName     = $firstExisting($conn, 'professors', ['prof_name','name']);
  $roomPK       = $firstExisting($conn, 'rooms', ['room_id','id']);
  $roomName     = $firstExisting($conn, 'rooms', ['room_name','name']);

  $parts = [];
  if ($syCol)  $parts[] = "s.`$syCol` = '".$esc($sy)."'";
  if ($sem !== '' && $semCol) $parts[] = "s.`$semCol` = '".$esc($sem)."'";
  $schedWhere = count($parts) ? (' WHERE '.implode(' AND ', $parts)) : '';

  $sel = [];
  if ($dayCol)     $sel[] = "s.`$dayCol`     AS days";
  if ($startCol)   $sel[] = "s.`$startCol`   AS start_time";
  if ($endCol)     $sel[] = "s.`$endCol`     AS end_time";
  if ($roomIdCol)  $sel[] = "s.`$roomIdCol`  AS room_id";
  if ($semCol)     $sel[] = "s.`$semCol`     AS semester";
  if ($syCol)      $sel[] = "s.`$syCol`      AS school_year";

  if ($secName)   $sel[] = "sec.`$secName`   AS section_name";
  if ($subjCode)  $sel[] = "sub.`$subjCode`  AS subject_code";
  if ($subjName)  $sel[] = "sub.`$subjName`  AS subject_name";
  if ($profName)  $sel[] = "prof.`$profName` AS professor_name";
  if ($roomName)  $sel[] = "r.`$roomName`    AS room_name";

  if (empty($sel)) { $sel[] = "s.*"; }
  $selectList = implode(",\n      ", $sel);

  $joins = [];
  if ($secIdCol && $secPK)   $joins[] = "LEFT JOIN sections   sec  ON sec.`$secPK`   = s.`$secIdCol`";
  if ($subjIdCol && $subjPK) $joins[] = "LEFT JOIN subjects   sub  ON sub.`$subjPK`   = s.`$subjIdCol`";
  if ($profIdCol && $profPK) $joins[] = "LEFT JOIN professors prof ON prof.`$profPK`  = s.`$profIdCol`";
  if ($roomIdCol && $roomPK) $joins[] = "LEFT JOIN rooms      r    ON r.`$roomPK`     = s.`$roomIdCol`";
  $joinSql = implode("\n    ", $joins);

  $order = [];
  if ($secName)   $order[] = "sec.`$secName`";
  if ($subjCode)  $order[] = "sub.`$subjCode`";
  if ($startCol)  $order[] = "s.`$startCol`";
  $orderBy = count($order) ? (' ORDER BY '.implode(', ', $order)) : '';

  $schedulesSql = "
    SELECT
      $selectList
    FROM schedules s
    $joinSql
    $schedWhere
    $orderBy
  ";
  $schedules = $qAll($conn, $schedulesSql, 'schedules_join');

  /* ---------- Consolidate days & normalize to plain text ---------- */
  // parse "days" (can be JSON like ["friday"] or string), normalize to full names
  $parseDays = function($val): array {
    $val = trim((string)$val);
    if ($val === '') return [];
    $decoded = json_decode($val, true);
    if (is_array($decoded)) {
      $list = $decoded;
    } else {
      // fallback: strip brackets/quotes and split by comma
      $s = trim($val, "[]");
      $s = str_replace(['"', "'"], '', $s);
      $list = array_filter(array_map('trim', explode(',', $s)));
    }
    $map = [
      'mon' => 'monday', 'monday' => 'monday',
      'tue' => 'tuesday', 'tues' => 'tuesday', 'tuesday' => 'tuesday',
      'wed' => 'wednesday', 'wednesday' => 'wednesday',
      'thu' => 'thursday', 'thur' => 'thursday', 'thurs' => 'thursday', 'thursday' => 'thursday',
      'fri' => 'friday', 'friday' => 'friday',
      'sat' => 'saturday', 'saturday' => 'saturday',
      'sun' => 'sunday', 'sunday' => 'sunday',
    ];
    $out = [];
    foreach ($list as $d) {
      $k = strtolower(preg_replace('/[^a-z]/i', '', (string)$d));
      if (isset($map[$k])) $out[] = $map[$k];
    }
    // unique
    $out = array_values(array_unique($out));
    return $out;
  };

  $weekdayOrder = ['monday'=>1,'tuesday'=>2,'wednesday'=>3,'thursday'=>4,'friday'=>5,'saturday'=>6,'sunday'=>7];

  // Group by Section + Subject + Start/End time (so same class at same time consolidates days)
  $grouped = [];
  foreach ($schedules as $row) {
    $days = $parseDays($row['days'] ?? '');
    // build key
    $sec  = $row['section_name'] ?? ($row['section_id'] ?? '');
    $subj = $row['subject_code'] ?? ($row['subject_name'] ?? ($row['subject_id'] ?? ''));
    $start = $row['start_time'] ?? '';
    $end   = $row['end_time'] ?? '';
    $key = implode('|', [$sec, $subj, $start, $end]);

    if (!isset($grouped[$key])) {
      $row['__days_set'] = [];
      $grouped[$key] = $row;
    }
    // merge days
    $existing = $grouped[$key]['__days_set'];
    foreach ($days as $d) { $existing[$d] = true; }
    $grouped[$key]['__days_set'] = $existing;
  }

  // Flatten groups, sort days by weekday, and turn into "Monday, Wednesday" text
  $consolidated = [];
  foreach ($grouped as $row) {
    $dset = array_keys($row['__days_set']);
    usort($dset, function($a,$b) use ($weekdayOrder){
      return ($weekdayOrder[$a] ?? 99) <=> ($weekdayOrder[$b] ?? 99);
    });
    // Capitalize
    $pretty = implode(', ', array_map(fn($x)=>ucfirst($x), $dset));
    $row['days'] = $pretty;                 // replace with plain text
    unset($row['__days_set']);              // cleanup
    $consolidated[] = $row;
  }
  $schedules = $consolidated;

  /* ---------- PhpSpreadsheet ---------- */
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (!file_exists($autoload)) throw new RuntimeException('vendor/autoload.php not found. Run: composer require phpoffice/phpspreadsheet');
  if (!extension_loaded('zip')) throw new RuntimeException('PHP extension "zip" is required. Enable it in php.ini and restart Apache.');
  require_once $autoload;

  // Universal addSheet helper (Coordinate class used fully-qualified)
  $addSheet = function(\PhpOffice\PhpSpreadsheet\Spreadsheet $wb, string $title, array $rows): void {
    $sheet = $wb->createSheet();
    $sheet->setTitle(substr(preg_replace('/[\\\\\\/*?:\\[\\]]/', '_', $title), 0, 31));
    if (empty($rows)) { $sheet->setCellValue('A1', 'No data'); return; }

    $headers = array_keys($rows[0]);

    // Header row
    foreach ($headers as $i => $h) {
      $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
      $sheet->setCellValue("{$col}1", $h);
    }

    // Data rows
    $r = 2;
    foreach ($rows as $row) {
      foreach ($headers as $i => $h) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue("{$col}{$r}", $row[$h] ?? null);
      }
      $r++;
    }

    // Style
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
    for ($i = 1; $i <= count($headers); $i++) {
      $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
  };

  log_line('PHPSPREADSHEET_READY');

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

  // Only the sheets you want:
  $addSheet($wb, 'Sections',   $sections);
  $addSheet($wb, 'Subjects',   $subjects);
  $addSheet($wb, 'Professors', $professors);
  $addSheet($wb, 'Schedules',  $schedules);

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
    'path'=>$tmp,
    'elapsed_ms'=>round((microtime(true)-$tSave)*1000,2),
    'size'=>filesize($tmp),
    'memory_peak'=>memory_get_peak_usage(true),
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
  log_line('RESPONSE_STREAMED', ['elapsed_ms'=>round((microtime(true)-$tOut)*1000,2)]);
  exit;

} catch (Throwable $e) {
  log_line('EXCEPTION', [
    'class'=>get_class($e),
    'message'=>$e->getMessage(),
    'file'=>$e->getFile(),
    'line'=>$e->getLine(),
    'trace'=>$e->getTraceAsString(),
  ]);

  if ($DEBUG) {
    fail_json(500, $e->getMessage(), [
      'class'=>get_class($e),
      'file'=>$e->getFile(),
      'line'=>$e->getLine(),
      'trace'=>$e->getTraceAsString(),
    ]);
  } else {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Export failed. See server logs for details.']);
    exit();
  }
}
