<?php
// subjects_bulk_upload.php
// Upload Excel/CSV and insert/update the `subjects` table, now supporting:
// code, name, description, subject type, grade level, strand, semester

require_once __DIR__ . '/cors_helper.php';
handleCORS();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// DB connect
require_once __DIR__ . '/connect.php';
$mysqli = new mysqli($host, $user, $password, $database);
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Database connection failed: " . $mysqli->connect_error
    ]);
    exit;
}
$mysqli->set_charset("utf8mb4");

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Method not allowed. Use POST."
    ]);
    exit;
}

// Validate upload
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "No file uploaded or upload failed."
    ]);
    exit;
}

$tmpPath  = $_FILES['file']['tmp_name'];
$fileName = $_FILES['file']['name'];
$ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$allowed  = ['xlsx', 'xls', 'csv'];
if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Invalid file type. Allowed: .xlsx, .xls, .csv"
    ]);
    exit;
}

// ---------- Utilities ----------
function norm_str($s) {
    return strtolower(trim(preg_replace('/[\s\-_]+/', ' ', (string)$s)));
}

function normalize_header($h) {
    $h = norm_str($h);
    // base
    if (in_array($h, ['subj code','subject code','code'], true)) return 'code';
    if (in_array($h, ['subj name','subject name','name','title'], true)) return 'name';
    if (in_array($h, ['subj description','subject description','description','desc'], true)) return 'description';

    // new fields
    if (in_array($h, ['subject type','type','subj type'], true)) return 'subject type';
    if (in_array($h, ['grade level','grade_level','year level','year_level','grade'], true)) return 'grade level';
    if (in_array($h, ['strand','track/strand','track strand'], true)) return 'strand';
    if (in_array($h, ['semester','sem'], true)) return 'semester';

    return $h; // fallback
}

function strip_bom($s) {
    return (substr($s, 0, 3) === "\xEF\xBB\xBF") ? substr($s, 3) : $s;
}

function detect_delimiter($path) {
    $candidates = [",", ";", "\t"];
    $h = fopen($path, 'r');
    if (!$h) return ",";
    $line = fgets($h);
    fclose($h);
    if ($line === false) return ",";
    $line = strip_bom($line);
    $best = ","; $bestCount = 0;
    foreach ($candidates as $d) {
        $parts = str_getcsv($line, $d);
        if (count($parts) > $bestCount) {
            $bestCount = count($parts);
            $best = $d;
        }
    }
    return $best;
}

// Value normalizers (lightweight; adjust if you have strict enums)
function normalize_semester($v) {
    $n = norm_str($v);
    if ($n === '' ) return '';
    if (preg_match('/^1(st)?$|^first$/', $n))  return 'First Semester';
    if (preg_match('/^2(nd)?$|^second$/', $n)) return 'Second Semester';
    // accept "summer", "midyear", etc
    if (strpos($n, 'summer') !== false) return 'Summer';
    if (strpos($n, 'mid') !== false) return 'Midyear';
    return ucfirst($n); // fallback keep readable
}
function normalize_grade_level($v) {
    $n = trim((string)$v);
    // keep raw but trim; if numeric like "11", keep as "11"
    return $n;
}
function normalize_strand($v) {
    // common SHS strands: STEM, ABM, HUMSS, GAS, ICT, HE, IA
    $n = strtoupper(trim((string)$v));
    return $n;
}
function sanitize($v) {
    return trim((string)$v);
}

// ---------- Read rows ----------
$rows = [];
$sheetName = null;

try {
    if ($ext === 'csv') {
        $delim = detect_delimiter($tmpPath);
        $fh = fopen($tmpPath, 'r');
        if ($fh === false) throw new Exception("Unable to open uploaded CSV.");

        // header line
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
        // Excel via PhpSpreadsheet
        require_once __DIR__ . '/vendor/autoload.php';
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getSheet(0);
        $sheetName = $sheet->getTitle();
        $arr = $sheet->toArray(null, true, true, true);
        if (empty($arr)) throw new Exception("The Excel file is empty.");

        // headers
        $first = array_shift($arr);
        $headers = [];
        foreach ($first as $col => $val) {
            $headers[] = normalize_header((string)$val);
        }
        // data
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
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Failed to read file: " . $e->getMessage()
    ]);
    exit;
}

// ---------- Validate headers ----------
// Required minimum (to remain backward compatible): code + name
// Recommended new headers: subject type, grade level, strand, semester
$hasCode = false; $hasName = false;
$sampleKeys = !empty($rows) ? array_map('strtolower', array_keys($rows[0])) : [];
$hasCode = in_array('code', $sampleKeys, true);
$hasName = in_array('name', $sampleKeys, true);

if (!$hasCode || !$hasName) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Missing required headers. Expected at least: code, name. Optional: description, subject type, grade level, strand, semester."
    ]);
    exit;
}

// Detect presence of new fields in this file
$hasType   = in_array('subject type', $sampleKeys, true);
$hasGL     = in_array('grade level',   $sampleKeys, true);
$hasStrand = in_array('strand',        $sampleKeys, true);
$hasSem    = in_array('semester',      $sampleKeys, true);

// ---------- Prepare UPSERT ----------
// Adjust these column names if your schema differs.
$col_list = "subj_code, subj_name, subj_description, subj_type, grade_level, strand, semester";
$placeholders = "?, ?, ?, ?, ?, ?, ?";
$update_list =
  "subj_name = VALUES(subj_name),
   subj_description = VALUES(subj_description),
   subj_type = VALUES(subj_type),
   grade_level = VALUES(grade_level),
   strand = VALUES(strand),
   semester = VALUES(semester)";

$stmt = $mysqli->prepare(
    "INSERT INTO subjects ($col_list)
     VALUES ($placeholders)
     ON DUPLICATE KEY UPDATE $update_list"
);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Failed to prepare statement: " . $mysqli->error
    ]);
    exit;
}

$inserted = 0; $updated = 0; $skipped = 0; $errors = []; $preview = [];

$mysqli->begin_transaction();

try {
    foreach ($rows as $idx => $row) {
        $code = sanitize($row['code'] ?? '');
        $name = sanitize($row['name'] ?? '');
        $desc = sanitize($row['description'] ?? '');

        $type = $hasType ? sanitize($row['subject type'] ?? '') : '';
        $gl   = $hasGL   ? normalize_grade_level($row['grade level'] ?? '') : '';
        $str  = $hasStrand ? normalize_strand($row['strand'] ?? '') : '';
        $sem  = $hasSem ? normalize_semester($row['semester'] ?? '') : '';

        $rowNo = $idx + 2; // header at 1

        if ($code === '' && $name === '') { $skipped++; continue; }
        if ($code === '' || $name === '') {
            $skipped++;
            $errors[] = "Row {$rowNo}: Missing required 'code' or 'name'.";
            continue;
        }

        // Bind and execute (7 columns)
        $stmt->bind_param('sssssss', $code, $name, $desc, $type, $gl, $str, $sem);
        if (!$stmt->execute()) {
            $skipped++;
            $errors[] = "Row {$rowNo}: DB error - " . $stmt->error;
            continue;
        }

        // affected_rows: 1 insert, 2 update
        if ($stmt->affected_rows === 1) $inserted++;
        elseif ($stmt->affected_rows === 2) $updated++;
        else $skipped++;

        if (count($preview) < 10) {
            $preview[] = [
                'code' => $code,
                'name' => $name,
                'description' => $desc,
                'subject_type' => $type,
                'grade_level' => $gl,
                'strand' => $str,
                'semester' => $sem
            ];
        }
    }

    $mysqli->commit();

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
        ]
    ]);
} catch (Exception $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Transaction failed: " . $e->getMessage()
    ]);
} finally {
    $stmt->close();
    $mysqli->close();
}
