<?php
require_once __DIR__ . '/cors_helper.php';
handleCORS();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/system_settings_helper.php';
require_once __DIR__ . '/activity_logger.php';

require_once __DIR__ . '/firebase_config.php';
require_once __DIR__ . '/firebase_sync_lib.php';
function firebaseSync(mysqli $db): FirebaseSync {
    global $firebaseConfig;
    return new FirebaseSync($firebaseConfig, $db);
}

$mysqli = new mysqli($host, $user, $password, $database);
if ($mysqli->connect_error) {
    @log_activity($mysqli, 'subjects_import', 'error', 'DB connection failed: '.$mysqli->connect_error, null, null);
    http_response_code(500);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Database connection failed: " . $mysqli->connect_error]);
    exit;
}
$mysqli->set_charset("utf8mb4");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    @log_activity($mysqli, 'subjects_import', 'error', 'Method not allowed (expected POST)', null, null);
    http_response_code(405);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Method not allowed. Use POST."]);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    @log_activity($mysqli, 'subjects_import', 'error', 'No file or upload failed (code '.((int)($_FILES['file']['error'] ?? -1)).')', null, null);
    http_response_code(400);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"No file uploaded or upload failed."]);
    exit;
}

$tmpPath  = $_FILES['file']['tmp_name'];
$fileName = $_FILES['file']['name'];
$ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$allowed  = ['xlsx', 'xls', 'csv'];
if (!in_array($ext, $allowed, true)) {
    @log_activity($mysqli, 'subjects_import', 'error', 'Invalid file type: '.$ext, null, null);
    http_response_code(400);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Invalid file type. Allowed: .xlsx, .xls, .csv"]);
    exit;
}

function norm_str($s) { return strtolower(trim(preg_replace('/[\s\-_]+/', ' ', (string)$s))); }
function normalize_header($h) {
    $h = norm_str($h);
    if (in_array($h, ['subj code','subject code','code'], true)) return 'code';
    if (in_array($h, ['subj name','subject name','name','title'], true)) return 'name';
    if (in_array($h, ['subj description','subject description','description','desc'], true)) return 'description';
    if (in_array($h, ['subject type','type','subj type'], true)) return 'subject type';
    if (in_array($h, ['grade level','grade_level','year level','year_level','grade'], true)) return 'grade level';
    if (in_array($h, ['strand','track/strand','track strand'], true)) return 'strand';
    if (in_array($h, ['semester','sem'], true)) return 'semester';
    return $h;
}
function strip_bom($s) { return (substr($s, 0, 3) === "\xEF\xBB\xBF") ? substr($s, 3) : $s; }
function detect_delimiter($path) {
    $candidates = [",", ";", "\t"];
    $h = fopen($path, 'r'); if (!$h) return ",";
    $line = fgets($h); fclose($h); if ($line === false) return ",";
    $line = strip_bom($line);
    $best = ","; $bestCount = 0;
    foreach ($candidates as $d) { $parts = str_getcsv($line, $d); if (count($parts) > $bestCount) { $bestCount = count($parts); $best = $d; } }
    return $best;
}
function normalize_semester($v) {
    $n = norm_str($v); if ($n === '') return '';
    if (preg_match('/^1(st)?$|^first$/', $n))  return 'First Semester';
    if (preg_match('/^2(nd)?$|^second$/', $n)) return 'Second Semester';
    if (strpos($n, 'summer') !== false) return 'Summer';
    if (strpos($n, 'mid') !== false) return 'Midyear';
    return ucfirst($n);
}
function normalize_grade_level($v) { return trim((string)$v); }
function normalize_strand($v) { return strtoupper(trim((string)$v)); }
function sanitize($v) { return trim((string)$v); }
function esc($db, $v) { return mysqli_real_escape_string($db, (string)$v); }
function q($db, $v) { return ($v === null) ? "NULL" : "'" . esc($db, $v) . "'"; }

$rows = [];
$sheetName = null;

try {
    if ($ext === 'csv') {
        $delim = detect_delimiter($tmpPath);
        $fh = fopen($tmpPath, 'r');
        if ($fh === false) throw new Exception("Unable to open uploaded CSV.");
        $first = fgets($fh);
        if ($first === false) { fclose($fh); throw new Exception("CSV is empty."); }
        $first = strip_bom($first);
        $rawHeaders = str_getcsv($first, $delim);
        $headers    = array_map('normalize_header', $rawHeaders);
        while (($data = fgetcsv($fh, 0, $delim)) !== false) {
            if (!$data || count($data) === 0) continue;
            $rowAssoc = [];
            foreach ($headers as $i => $key) {
                if ($key === '') continue;
                $rowAssoc[$key] = isset($data[$i]) ? sanitize($data[$i]) : '';
            }
            $rows[] = $rowAssoc;
        }
        fclose($fh);
        $sheetName = 'CSV';
    } else {
        require_once __DIR__ . '/vendor/autoload.php';
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getSheet(0);
        $sheetName = $sheet->getTitle();
        $arr = $sheet->toArray(null, true, true, true);
        if (empty($arr)) throw new Exception("The Excel file is empty.");
        $first = array_shift($arr);
        $headers = [];
        foreach ($first as $col => $val) { $headers[] = normalize_header((string)$val); }
        foreach ($arr as $r) {
            $rowAssoc = [];
            $i = 0;
            foreach ($r as $col => $val) {
                $key = $headers[$i] ?? '';
                if ($key !== '') $rowAssoc[$key] = sanitize($val);
                $i++;
            }
            $rows[] = $rowAssoc;
        }
    }
} catch (Exception $e) {
    @log_activity($mysqli, 'subjects_import', 'error', 'Failed to read file: '.$e->getMessage(), null, null);
    http_response_code(400);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Failed to read file: " . $e->getMessage()]);
    exit;
}

$sampleKeys = !empty($rows) ? array_map('strtolower', array_keys($rows[0])) : [];
$hasCode = in_array('code', $sampleKeys, true);
$hasName = in_array('name', $sampleKeys, true);
if (!$hasCode || !$hasName) {
    @log_activity($mysqli, 'subjects_import', 'error', 'Missing required headers (need: code, name)', null, null);
    http_response_code(400);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Missing required headers. Expected at least: code, name. Optional: description, subject type, grade level, strand, semester."]);
    exit;
}
$hasType   = in_array('subject type', $sampleKeys, true);
$hasGL     = in_array('grade level',   $sampleKeys, true);
$hasStrand = in_array('strand',        $sampleKeys, true);
$hasSem    = in_array('semester',      $sampleKeys, true);

$currentSYRaw = ss_get_current_school_year($mysqli);
$currentSY    = $currentSYRaw !== null ? trim($currentSYRaw) : '';
$schoolYearForInsert = $currentSY !== '' ? $currentSY : null;

$inserted = 0; $updated = 0; $skipped = 0; $errors = []; $preview = [];
$syncSynced = 0; $syncFailed = 0;

$mysqli->begin_transaction();

try {
    foreach ($rows as $idx => $row) {
        $code = sanitize($row['code'] ?? '');
        $name = sanitize($row['name'] ?? '');
        $desc = sanitize($row['description'] ?? '');

        $type = $hasType   ? sanitize($row['subject type'] ?? '') : '';
        $gl   = $hasGL     ? normalize_grade_level($row['grade level'] ?? '') : '';
        $str  = $hasStrand ? normalize_strand($row['strand'] ?? '') : '';
        $sem  = $hasSem    ? normalize_semester($row['semester'] ?? '') : '';

        $rowNo = $idx + 2;
        if ($code === '' && $name === '') { $skipped++; continue; }
        if ($code === '' || $name === '') { $skipped++; $errors[] = "Row {$rowNo}: Missing required 'code' or 'name'."; continue; }

        $sy = $schoolYearForInsert;

        $codeEsc = esc($mysqli, $code);
        $nameEsc = esc($mysqli, $name);
        $descEsc = esc($mysqli, $desc);
        $typeEsc = esc($mysqli, $type);
        $glEsc   = esc($mysqli, $gl);
        $semEsc  = esc($mysqli, $sem);
        $strEsc  = esc($mysqli, $str);

        $where = "
            subj_code = '{$codeEsc}'
            AND subj_name = '{$nameEsc}'
            AND COALESCE(subj_description,'') = COALESCE('{$descEsc}','')
            AND subj_type = '{$typeEsc}'
            AND COALESCE(grade_level,'') = COALESCE('{$glEsc}','')
            AND COALESCE(strand,'') = COALESCE('{$strEsc}','')
            AND COALESCE(semester,'') = COALESCE('{$semEsc}','')
            AND school_year <=> " . q($mysqli, $sy) . "
        ";

        $checkSql = "SELECT subj_id FROM subjects WHERE {$where} LIMIT 1";
        $dupRes = $mysqli->query($checkSql);
        if ($dupRes === false) {
            $skipped++;
            $errors[] = "Row {$rowNo}: DB check error - " . $mysqli->error;
            continue;
        }
        if ($dupRes->num_rows > 0) {
            $skipped++;
            continue;
        }

        $glVal  = ($gl === '') ? "NULL" : "'{$glEsc}'";
        $strVal = ($str === '') ? "NULL" : "'{$strEsc}'";
        $semVal = ($sem === '') ? "NULL" : "'{$semEsc}'";
        $syVal  = q($mysqli, $sy);

        $insSql = "
            INSERT INTO subjects
                (subj_code, subj_name, subj_description, subj_type, grade_level, strand, semester, school_year)
            VALUES
                ('{$codeEsc}', '{$nameEsc}', '{$descEsc}', '{$typeEsc}', {$glVal}, {$strVal}, {$semVal}, {$syVal})
        ";

        if (!$mysqli->query($insSql)) {
            $skipped++;
            $errors[] = "Row {$rowNo}: Insert blocked/failed: " . $mysqli->error;
            continue;
        }

        $inserted++;
        $newId = (int)$mysqli->insert_id;

        try {
            $syncResult = firebaseSync($mysqli)->syncSingleSubject($newId);
            if (is_array($syncResult) && array_key_exists('success', $syncResult)) {
                if ($syncResult['success']) { $syncSynced++; }
                else { $syncFailed++; $errors[] = "Row {$rowNo}: Firebase sync reported failure."; }
            } else {
                $syncSynced++;
            }
        } catch (Throwable $e) {
            $syncFailed++;
            $errors[] = "Row {$rowNo}: Firebase sync error - " . $e->getMessage();
        }

        if (count($preview) < 10) {
            $preview[] = [
                'code' => $code,
                'name' => $name,
                'description' => $desc,
                'subject_type' => $type,
                'grade_level' => $gl,
                'strand' => $str,
                'semester' => $sem,
                'school_year' => $sy
            ];
        }
    }

    $mysqli->commit();

    @log_activity(
        $mysqli,
        'subjects_import',
        'create',
        'Import complete: processed '.count($rows).', inserted '.$inserted.', updated '.$updated.', skipped '.$skipped.' | sheet='.$sheetName.' | SY='.( $currentSY ?: 'NULL' ).' | sync ok='.$syncSynced.', sync fail='.$syncFailed,
        null,
        null
    );

    echo json_encode([
        "success"  => true,
        "status"   => "success",
        "message"  => "Processed " . count($rows) . " row(s). Inserted: $inserted, Updated: $updated, Skipped: $skipped.",
        "data"     => [
            "sheet"     => $sheetName,
            "processed" => count($rows),
            "inserted"  => $inserted,
            "updated"   => $updated,
            "skipped"   => $skipped,
            "sample"    => $preview,
            "errors"    => $errors
        ],
        "firebase_sync" => [
            "synced" => $syncSynced,
            "failed" => $syncFailed
        ],
        "current_school_year" => $currentSY
    ]);
} catch (Exception $e) {
    $mysqli->rollback();
    @log_activity($mysqli, 'subjects_import', 'error', 'Transaction failed: '.$e->getMessage(), null, null);
    http_response_code(500);
    echo json_encode(["success"=>false,"status"=>"error","message"=>"Transaction failed: " . $e->getMessage()]);
} finally {
    $mysqli->close();
}
