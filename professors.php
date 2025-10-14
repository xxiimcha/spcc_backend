<?php
include 'cors_helper.php';
handleCORS();

include 'connect.php';
include 'activity_logger.php';

require_once __DIR__ . '/system_settings_helper.php';
require_once __DIR__ . '/firebase_config.php';
require_once __DIR__ . '/firebase_sync_lib.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

function firebaseSync(mysqli $conn): FirebaseSync {
    global $firebaseConfig; // from firebase_config.php
    return new FirebaseSync($firebaseConfig, $conn);
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function normalize_array($v): array {
    if (is_array($v)) return $v;
    if (is_string($v) && trim($v) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
    }
    return [];
}

function emailExists(mysqli $conn, string $email, int $excludeId = 0): bool {
    if ($email === '') return false;
    $safeEmail = mysqli_real_escape_string($conn, $email);
    if ($excludeId > 0) {
        $id = (int)$excludeId;
        $sql = "SELECT 1 FROM professors WHERE prof_email='$safeEmail' AND prof_id<>$id LIMIT 1";
    } else {
        $sql = "SELECT 1 FROM professors WHERE prof_email='$safeEmail' LIMIT 1";
    }
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function fetchSubjectsByIds($conn, $ids) {
    if (!is_array($ids) || empty($ids)) return [];
    $safe = array_values(array_unique(array_filter(array_map(fn($v)=>(int)$v, $ids), fn($v)=>$v>0)));
    if (empty($safe)) return [];
    $in = implode(',', $safe);
    $sql = "
        SELECT subj_id, subj_code, subj_name, grade_level, strand
        FROM subjects
        WHERE subj_id IN ($in)
        ORDER BY 
          CASE WHEN grade_level REGEXP '^[0-9]+$' THEN CAST(grade_level AS UNSIGNED) ELSE 999 END ASC,
          strand ASC, subj_name ASC
    ";
    $res = $conn->query($sql);
    $out = [];
    if ($res) {
        while($row = $res->fetch_assoc()) {
            $out[] = [
                'subj_id'     => (int)$row['subj_id'],
                'subj_code'   => $row['subj_code'],
                'subj_name'   => $row['subj_name'],
                'grade_level' => $row['grade_level'] ?? null,
                'strand'      => $row['strand'] ?? null,
            ];
        }
    }
    return $out;
}

function sendWelcomeProfessorEmail($toEmail, $toName, $username, $plainPassword, $schoolName, $portalUrl, $subjectRows) {
    if (!$toEmail) return ['sent' => false, 'message' => 'No recipient email'];
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ssassist028@gmail.com';
        $mail->Password   = 'qans jgft ggrl nplb';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        $mail->setFrom('ssassist028@gmail.com', 'SPCC Scheduler');
        $mail->addAddress($toEmail, $toName ?: 'Professor');

        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = "$schoolName — Your Professor Account";

        $subjectList = "";
        if (!empty($subjectRows)) {
            $subjectList .= "<ul style='margin:10px 0; padding-left:20px;'>";
            foreach ($subjectRows as $s) {
                $label = trim(($s['subj_code'] ?? '') . ' — ' . ($s['subj_name'] ?? ''));
                $subjectList .= "<li>".htmlspecialchars($label)."</li>";
            }
            $subjectList .= "</ul>";
        } else {
            $subjectList = "<p><em>No subjects assigned yet.</em></p>";
        }

        $body = "
        <div style='font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif; background:#f9fafb; padding:20px;'>
          <div style='max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 6px rgba(0,0,0,0.1);'>
            <div style='background:#4f46e5; color:#ffffff; padding:16px 24px;'>
              <h2 style='margin:0; font-size:20px;'>Welcome to $schoolName</h2>
            </div>
            <div style='padding:24px; color:#111827; font-size:15px;'>
              <p>Hi <strong>".htmlspecialchars($toName ?: 'Professor')."</strong>,</p>
              <p>Your professor account has been created. Here are your credentials:</p>
              <table style='border-collapse:collapse; margin:16px 0; width:100%;'>
                <tr>
                  <td style='padding:10px; border:1px solid #e5e7eb; background:#f3f4f6; font-weight:bold;'>Username</td>
                  <td style='padding:10px; border:1px solid #e5e7eb;'><code>".htmlspecialchars($username)."</code></td>
                </tr>
                <tr>
                  <td style='padding:10px; border:1px solid #e5e7eb; background:#f3f4f6; font-weight:bold;'>Temporary Password</td>
                  <td style='padding:10px; border:1px solid #e5e7eb;'><code>".htmlspecialchars($plainPassword)."</code></td>
                </tr>
              </table>
              <p><strong>Assigned Subjects:</strong></p>
              $subjectList
              <p style='margin:20px 0;'>Login here:
                <a href='".htmlspecialchars($portalUrl)."' style='color:#4f46e5; text-decoration:none; font-weight:500;'>".htmlspecialchars($portalUrl)."</a>
              </p>
              <p style='font-style:italic; color:#6b7280;'>For security, please change your password after logging in.</p>
            </div>
            <div style='background:#f9fafb; padding:12px 24px; font-size:12px; color:#6b7280; text-align:center;'>
              This is an automated email. If you didn’t expect this, you can ignore it.
            </div>
          </div>
        </div>";

        $alts = array_map(function($s){
          $code = $s['subj_code'] ?? '';
          $name = $s['subj_name'] ?? '';
          return trim("$code — $name");
        }, $subjectRows);
        $altSubjects = empty($alts) ? "No subjects assigned yet." : implode(", ", $alts);

        $alt = "Welcome to $schoolName\n\nHi ".($toName ?: 'Professor').",\n\nYour professor account has been created.\n\nUsername: $username\nTemporary Password: $plainPassword\n\nSubjects: $altSubjects\n\nLogin: $portalUrl\n\nPlease change your password after logging in.";

        $mail->Body    = $body;
        $mail->AltBody = $alt;

        $mail->send();
        return ['sent' => true, 'message' => 'Email sent'];
    } catch (Exception $e) {
        return ['sent' => false, 'message' => $mail->ErrorInfo];
    }
}

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    // NEW: log connection failure
    log_activity($conn, 'professors', 'error', 'DB connection failed: '.$conn->connect_error, null, null);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$payload = read_json_body();
$methodOverride = null;
if (isset($payload['_method'])) $methodOverride = strtoupper(trim((string)$payload['_method']));
if ($method === 'POST' && isset($_POST['_method']) && !$methodOverride) {
    $methodOverride = strtoupper(trim((string)$_POST['_method']));
}
$effectiveMethod = $methodOverride ?: $method;

if ($effectiveMethod === 'GET' && isset($_GET['setting_key'])) {
    $k = trim((string)$_GET['setting_key']);
    $val = $k !== '' ? ss_get_setting($conn, $k) : null;
    echo json_encode(["success"=>true,"setting_key"=>$k,"setting_value"=>$val]);
    $conn->close();
    exit();
}

switch ($effectiveMethod) {
    case 'POST':
        createProfessor($conn, $payload);
        break;
    case 'PUT':
        updateProfessor($conn, (int)($_GET['id'] ?? 0), $payload);
        break;
    case 'DELETE':
        deleteProfessor($conn, (int)($_GET['id'] ?? 0));
        break;
    default:
        if (isset($_GET['id'])) {
            getProfessor($conn, (int)$_GET['id']);
        } else {
            getAllProfessors($conn);
        }
        break;
}

function createProfessor(mysqli $conn, array $data) {
    $name      = mysqli_real_escape_string($conn, (string)($data['name'] ?? ''));
    $username  = mysqli_real_escape_string($conn, (string)($data['username'] ?? ''));
    $password  = mysqli_real_escape_string($conn, (string)($data['password'] ?? ''));
    $email     = trim((string)($data['email'] ?? ''));
    $phone     = mysqli_real_escape_string($conn, (string)($data['phone'] ?? ''));
    $qualsArr  = normalize_array($data['qualifications'] ?? []);
    $subjIds   = normalize_array($data['subject_ids'] ?? []);

    if ($email !== '' && emailExists($conn, $email)) {
        log_activity($conn, 'professors', 'create', "FAILED create: email exists for {$email}", null, null); // NEW
        http_response_code(409);
        echo json_encode(["status"=>"error","message"=>"Email already exists."]);
        return;
    }

    $qualStr       = mysqli_real_escape_string($conn, json_encode(array_values($qualsArr), JSON_UNESCAPED_UNICODE));
    $subjects_json = mysqli_real_escape_string($conn, json_encode(array_map('intval', $subjIds)));
    $subj_count    = is_array($subjIds) ? count($subjIds) : 0;

    $schoolYear    = ss_get_current_school_year($conn);
    $schoolYearSQL = $schoolYear !== null ? "'" . mysqli_real_escape_string($conn, $schoolYear) . "'" : "NULL";

    $sql = "
        INSERT INTO professors 
        (prof_name, prof_username, prof_password, prof_email, prof_phone, prof_qualifications, prof_subject_ids, subj_count, school_year)
        VALUES ('$name', '$username', '$password', '".mysqli_real_escape_string($conn, $email)."', '$phone', '$qualStr', '$subjects_json', $subj_count, $schoolYearSQL)
    ";
    if (!$conn->query($sql)) {
        log_activity($conn, 'professors', 'create', "FAILED create professor {$name}: ".$conn->error, null, null); // NEW
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$conn->error]);
        return;
    }

    $id = $conn->insert_id;

    $syncResult = firebaseSync($conn)->syncSingleProfessor($id);

    $subjectRows = fetchSubjectsByIds($conn, $subjIds);
    $emailResult = $email !== ''
        ? sendWelcomeProfessorEmail(
            $email, $name, $username, (string)($data['password'] ?? ''),
            'Systems Plus Computer College Caloocan',
            'https://your-portal.example.com/login',
            $subjectRows
          )
        : ['sent'=>false,'message'=>'No email'];

    // NEW: log success
    $desc = "Created professor: {$name} (ID: {$id}) | SY: " . ($schoolYear ?? 'N/A') . " | Subjects: {$subj_count}";
    log_activity($conn, 'professors', 'create', $desc, $id, null);

    echo json_encode([
        "status"=>"success",
        "message"=>"Professor added",
        "id"=>$id,
        "email"=>$emailResult,
        "firebase_sync"=>$syncResult
    ]);
}

function updateProfessor(mysqli $conn, int $id, array $data) {
    if ($id <= 0) {
        log_activity($conn, 'professors', 'update', "FAILED update: missing ID", null, null); // NEW
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }

    $name      = mysqli_real_escape_string($conn, (string)($data['name'] ?? ''));
    $emailRaw  = trim((string)($data['email'] ?? ''));
    $emailEsc  = mysqli_real_escape_string($conn, $emailRaw);
    $phone     = mysqli_real_escape_string($conn, (string)($data['phone'] ?? ''));
    $qualsArr  = normalize_array($data['qualifications'] ?? []);
    $subjIds   = normalize_array($data['subject_ids'] ?? []);

    if ($emailRaw !== '' && emailExists($conn, $emailRaw, $id)) {
        log_activity($conn, 'professors', 'update', "FAILED update ID {$id}: email taken ({$emailRaw})", $id, null); // NEW
        http_response_code(409);
        echo json_encode(["status"=>"error","message"=>"Email already taken by another professor."]);
        return;
    }

    $qualStr       = mysqli_real_escape_string($conn, json_encode(array_values($qualsArr), JSON_UNESCAPED_UNICODE));
    $subjects_json = mysqli_real_escape_string($conn, json_encode(array_map('intval', $subjIds)));
    $subj_count    = is_array($subjIds) ? count($subjIds) : 0;

    $set = [];
    $set[] = "prof_name='$name'";
    $set[] = "prof_email='$emailEsc'";
    $set[] = "prof_phone='$phone'";
    $set[] = "prof_qualifications='$qualStr'";
    $set[] = "prof_subject_ids='$subjects_json'";
    $set[] = "subj_count=$subj_count";

    if (array_key_exists('school_year', $data)) {
        $sy = trim((string)$data['school_year']);
        if ($sy === '') {
            $set[] = "school_year=NULL";
        } else {
            $set[] = "school_year='" . mysqli_real_escape_string($conn, $sy) . "'";
        }
    }

    $sql = "UPDATE professors SET " . implode(',', $set) . " WHERE prof_id=".(int)$id;
    if (!$conn->query($sql)) {
        log_activity($conn, 'professors', 'update', "FAILED update ID {$id}: ".$conn->error, $id, null); // NEW
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$conn->error]);
        return;
    }

    $syncResult = firebaseSync($conn)->syncSingleProfessor($id);

    // NEW: log success
    $desc = "Updated professor: {$name} (ID: {$id}) | Subjects: {$subj_count}";
    log_activity($conn, 'professors', 'update', $desc, $id, null);

    echo json_encode([
        "status"=>"success",
        "message"=>"Professor updated",
        "id"=>$id,
        "firebase_sync"=>$syncResult
    ]);
}

function deleteProfessor(mysqli $conn, int $id) {
    if ($id <= 0) {
        log_activity($conn, 'professors', 'delete', "FAILED delete: missing ID", null, null); // NEW
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }
    $sql = "DELETE FROM professors WHERE prof_id=".(int)$id;
    if (!$conn->query($sql)) {
        log_activity($conn, 'professors', 'delete', "FAILED delete ID {$id}: ".$conn->error, $id, null); // NEW
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$conn->error]);
        return;
    }

    $syncResult = firebaseSync($conn)->deleteProfessorInFirebase($id);

    // NEW: log success
    $desc = "Deleted professor ID: {$id}";
    log_activity($conn, 'professors', 'delete', $desc, $id, null);

    echo json_encode([
        "status"=>"success",
        "message"=>"Professor deleted",
        "firebase_sync"=>$syncResult
    ]);
}

function getAllProfessors(mysqli $conn) {
    $requestedSY = isset($_GET['school_year']) ? trim((string)$_GET['school_year']) : '';
    $currentSY   = ss_get_current_school_year($conn);

    if ($requestedSY === '' || strtolower($requestedSY) === 'current') {
        $filterSY = $currentSY;
    } elseif (strtolower($requestedSY) === 'all') {
        $filterSY = null;
    } else {
        $filterSY = $requestedSY;
    }

    $sql = "SELECT * FROM professors";
    if ($filterSY !== null) {
        $sql .= " WHERE school_year " . ($filterSY === '' ? "IS NULL" : "= '" . mysqli_real_escape_string($conn, $filterSY) . "'") ;
    }
    $sql .= " ORDER BY prof_id ASC";

    $result = $conn->query($sql);
    $professors = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ids = json_decode($row['prof_subject_ids'] ?? '[]', true);
            if (!is_array($ids)) { $ids = []; }
            $derivedCount = count(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
            $professors[] = [
                'prof_id'        => (int)$row['prof_id'],
                'prof_name'      => $row['prof_name'],
                'prof_email'     => $row['prof_email'],
                'prof_phone'     => $row['prof_phone'],
                'prof_username'  => $row['prof_username'],
                'prof_password'  => $row['prof_password'],
                'qualifications' => json_decode($row['prof_qualifications'] ?? '[]', true) ?: [],
                'subject_ids'    => $ids,
                'subj_count'     => $derivedCount > 0 ? $derivedCount : (int)($row['subj_count'] ?? 0),
                'school_year'    => $row['school_year'] ?? null,
            ];
        }
    }
    echo json_encode([
        "success"=>true,
        "data"=>$professors,
        "current_school_year"=>$currentSY,
        "applied_school_year"=>$filterSY === null ? "all" : ($filterSY ?? "")
    ]);
}

function getProfessor(mysqli $conn, int $id) {
    if ($id <= 0) {
        log_activity($conn, 'professors', 'read', "FAILED view: missing ID", null, null); // NEW (optional)
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }
    $res = $conn->query("SELECT * FROM professors WHERE prof_id=".(int)$id." LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        log_activity($conn, 'professors', 'read', "FAILED view: professor not found (ID {$id})", $id, null); // NEW (optional)
        http_response_code(404);
        echo json_encode(["status"=>"error","message"=>"Professor not found"]);
        return;
    }
    $row = $res->fetch_assoc();

    $ids = json_decode($row['prof_subject_ids'] ?? '[]', true) ?: [];
    $subjects = fetchSubjectsByIds($conn, $ids);

    $prof = [
        'id'              => (int)$row['prof_id'],
        'name'            => $row['prof_name'],
        'email'           => $row['prof_email'],
        'phone'           => $row['prof_phone'],
        'username'        => $row['prof_username'],
        'password'        => $row['prof_password'],
        'qualifications'  => json_decode($row['prof_qualifications'] ?? '[]', true) ?: [],
        'subjects'        => $subjects,
        'subject_ids'     => $ids,
        'subj_count'      => (int)$row['subj_count'],
        'school_year'     => $row['school_year'] ?? null,
    ];

    // NEW (optional): log successful view
    log_activity($conn, 'professors', 'read', "Viewed professor ID: {$id}", $id, null);

    $currentSY = ss_get_current_school_year($conn);
    echo json_encode(["status"=>"success","data"=>$prof,"current_school_year"=>$currentSY]);
}

$conn->close();
