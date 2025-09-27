<?php
// subjects_bulk_upload.php
// Upload Excel/CSV and insert/update the `subjects` table

require_once __DIR__ . '/cors_helper.php';
handleCORS();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Always return JSON
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

// --- Utilities ---
function normalize_header($h) {
    $h = trim($h);
    $h = strtolower($h);
    if (in_array($h, ['subj_code', 'subject_code', 'code'], true)) return 'code';
    if (in_array($h, ['subj_name', 'subject_name', 'name', 'title'], true)) return 'name';
    if (in_array($h, ['subj_description', 'subject_description', 'description', 'desc'], true)) return 'description';
    return $h;
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
    $best = ",";
    $bestCount = 0;
    foreach ($candidates as $d) {
        $parts = str_getcsv($line, $d);
        if (count($parts) > $bestCount) {
            $bestCount = count($parts);
            $best = $d;
        }
    }
    return $best;
}

// --- Read rows ---
$rows = [];
$sheetName = null;

try {
    if ($ext === 'csv') {
        // CSV (pure PHP)
        $delim = detect_delimiter($tmpPath);
        $fh = fopen($tmpPath, 'r');
        if ($fh === false) {
            throw new Exception("Unable to open uploaded CSV.");
        }

        // header line
        $first = fgets($fh);
        if ($first === false) {
            fclose($fh);
            throw new Exception("CSV is empty.");
        }
        $first = strip_bom($first);
        $rawHeaders = str_getcsv($first, $delim);
        $headers    = array_map('normalize_header', $rawHeaders);

        // data lines
        while (($data = fgetcsv($fh, 0, $delim)) !== false) {
            if (!$data || count($data) === 0) continue;
            $rowAssoc = [];
            foreach ($headers as $i => $key) {
                if ($key === '') continue;
                $rowAssoc[$key] = isset($data[$i]) ? trim((string)$data[$i]) : '';
            }
            $rows[] = $rowAssoc;
        }
        fclose($fh);
        $sheetName = 'CSV';
    } else {
        // Excel via PhpSpreadsheet
        // Composer autoloader (vendor is inside this project)
        require_once __DIR__ . '/vendor/autoload.php';

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getSheet(0);
        $sheetName = $sheet->getTitle();
        $arr = $sheet->toArray(null, true, true, true);
        if (empty($arr)) {
            throw new Exception("The Excel file is empty.");
        }
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
                if ($key !== '') {
                    $rowAssoc[$key] = trim((string)$val);
                }
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

// --- Validate required headers: code + name (description optional) ---
$hasCode = false; $hasName = false;
if (!empty($rows)) {
    $sampleKeys = array_map('strtolower', array_keys($rows[0]));
    $hasCode = in_array('code', $sampleKeys, true);
    $hasName = in_array('name', $sampleKeys, true);
}
if (!$hasCode || !$hasName) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "status"  => "error",
        "message" => "Missing required headers. Expected at least: code, name. (description is optional)"
    ]);
    exit;
}

// --- Prepare UPSERT ---
// IMPORTANT: subj_code must be UNIQUE for ON DUPLICATE KEY to work.
// ALTER TABLE subjects ADD UNIQUE KEY uq_subject_code (subj_code);
$stmt = $mysqli->prepare(
    "INSERT INTO subjects (subj_code, subj_name, subj_description)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE
       subj_name = VALUES(subj_name),
       subj_description = VALUES(subj_description)"
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

$inserted = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];
$preview  = [];

$mysqli->begin_transaction();

try {
    foreach ($rows as $idx => $row) {
        $code = trim((string)($row['code'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $desc = trim((string)($row['description'] ?? ''));

        // row number in the original file (1-based; +2 accounts for header)
        $rowNo = $idx + 2;

        if ($code === '' && $name === '') { $skipped++; continue; }
        if ($code === '' || $name === '') {
            $skipped++;
            $errors[] = "Row {$rowNo}: Missing required 'code' or 'name'.";
            continue;
        }

        $stmt->bind_param('sss', $code, $name, $desc);
        if (!$stmt->execute()) {
            $skipped++;
            $errors[] = "Row {$rowNo}: DB error - " . $stmt->error;
            continue;
        }

        // affected_rows: 1=insert, 2=update
        if ($stmt->affected_rows === 1) $inserted++;
        elseif ($stmt->affected_rows === 2) $updated++;
        else $skipped++; // no change

        if (count($preview) < 10) {
            $preview[] = ['code' => $code, 'name' => $name, 'description' => $desc];
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
