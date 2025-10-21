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
    global $firebaseConfig;
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

function userUsernameExists(mysqli $conn, string $username, int $excludeUserId = 0): bool {
    if ($username === '') return false;
    $u = mysqli_real_escape_string($conn, $username);
    if ($excludeUserId > 0) {
        $id = (int)$excludeUserId;
        $sql = "SELECT 1 FROM users WHERE username='$u' AND user_id<>$id LIMIT 1";
    } else {
        $sql = "SELECT 1 FROM users WHERE username='$u' LIMIT 1";
    }
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function userEmailExists(mysqli $conn, string $email, int $excludeUserId = 0): bool {
    if ($email === '') return false;
    $e = mysqli_real_escape_string($conn, $email);
    if ($excludeUserId > 0) {
        $id = (int)$excludeUserId;
        $sql = "SELECT 1 FROM users WHERE email='$e' AND user_id<>$id LIMIT 1";
    } else {
        $sql = "SELECT 1 FROM users WHERE email='$e' LIMIT 1";
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

    $subjects_json = mysqli_real_escape_string($conn, json_encode([]));
    $subj_count    = 0;

    if ($email !== '' && emailExists($conn, $email)) {
        log_activity($conn, 'professors', 'create', "FAILED create: email exists for {$email}", null, null);
        http_response_code(409);
        echo json_encode(["status"=>"error","message"=>"Email already exists."]);
        return;
    }
    if ($username === '') {
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Username is required"]);
        return;
    }
    if (userUsernameExists($conn, $username)) {
        http_response_code(409);
        echo json_encode(["status"=>"error","message"=>"Username already exists in users."]);
        return;
    }
    if ($email !== '' && userEmailExists($conn, $email)) {
        http_response_code(409);
        echo json_encode(["status"=>"error","message"=>"Email already exists in users."]);
        return;
    }

    $qualStr       = mysqli_real_escape_string($conn, json_encode(array_values($qualsArr), JSON_UNESCAPED_UNICODE));
    $schoolYear    = ss_get_current_school_year($conn);
    $schoolYearSQL = $schoolYear !== null ? "'" . mysqli_real_escape_string($conn, $schoolYear) . "'" : "NULL";
    $emailSQL      = $email === '' ? "NULL" : "'" . mysqli_real_escape_string($conn, $email) . "'";
    $now           = date('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
        // 1) create USER first
        $sqlUser = "
            INSERT INTO users (username, password, email, role, status, created_at, updated_at)
            VALUES ('{$username}', '{$password}', {$emailSQL}, 'professor', 'active', '{$now}', '{$now}')
        ";
        if (!$conn->query($sqlUser)) {
            throw new Exception("Failed creating linked user: " . $conn->error);
        }
        $userId = (int)$conn->insert_id;

        // 2) then insert PROFESSOR with that user_id
        $sqlProf = "
            INSERT INTO professors
                (user_id, prof_name, prof_username, prof_email, prof_phone,
                 prof_qualifications, prof_subject_ids, subj_count, school_year)
            VALUES
                ({$userId}, '$name', '$username', $emailSQL, '$phone',
                 '$qualStr', '$subjects_json', $subj_count, $schoolYearSQL)
        ";
        if (!$conn->query($sqlProf)) {
            throw new Exception("Failed inserting professor: " . $conn->error);
        }
        $profId = (int)$conn->insert_id;

        $conn->commit();

        $syncResult = firebaseSync($conn)->syncSingleProfessor($profId);

        $emailResult = $email !== ''
            ? sendWelcomeProfessorEmail(
                $email, $name, $username, (string)($data['password'] ?? ''),
                'Systems Plus Computer College Caloocan',
                'https://your-portal.example.com/login',
                []
              )
            : ['sent'=>false,'message'=>'No email'];

        $desc = "Created professor: {$name} (ID: {$profId}) | SY: " . ($schoolYear ?? 'N/A');
        log_activity($conn, 'professors', 'create', $desc, $profId, $userId);

        echo json_encode([
            "status"         => "success",
            "message"        => "Professor added",
            "id"             => $profId,
            "user_id"        => $userId,
            "email"          => $emailResult,
            "firebase_sync"  => $syncResult
        ]);

    } catch (Throwable $e) {
        $conn->rollback();
        log_activity($conn, 'professors', 'create', "FAILED create: " . $e->getMessage(), null, null);
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
        return;
    }
}

function updateProfessor(mysqli $conn, int $id, array $data) {
    if ($id <= 0) {
        log_activity($conn, 'professors', 'update', "FAILED update: missing ID", null, null);
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }

    $curRes = $conn->query("SELECT prof_name, prof_email, prof_username, prof_subject_ids, user_id FROM professors WHERE prof_id=".(int)$id." LIMIT 1");
    if (!$curRes || $curRes->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status"=>"error","message"=>"Professor not found"]);
        return;
    }
    $cur = $curRes->fetch_assoc();
    $currentName     = $cur['prof_name'] ?? '';
    $currentEmail    = $cur['prof_email'] ?? '';
    $currentUsername = $cur['prof_username'] ?? '';
    $currentSubjIds  = json_decode($cur['prof_subject_ids'] ?? '[]', true) ?: [];
    $userId          = (int)($cur['user_id'] ?? 0);

    $set = [];
    $userSet = [];

    if (array_key_exists('name', $data)) {
        $name = mysqli_real_escape_string($conn, (string)$data['name']);
        $set[] = "prof_name='$name'";
    }

    if (array_key_exists('email', $data)) {
        $emailRaw = trim((string)$data['email']);
        if ($emailRaw !== '' && emailExists($conn, $emailRaw, $id)) {
            log_activity($conn, 'professors', 'update', "FAILED update ID {$id}: email taken ({$emailRaw})", $id, $userId);
            http_response_code(409);
            echo json_encode(["status"=>"error","message"=>"Email already taken by another professor."]);
            return;
        }
        $emailEsc = mysqli_real_escape_string($conn, $emailRaw);
        $set[] = "prof_email='$emailEsc'";
        if ($userId > 0) {
            if ($emailRaw !== '' && userEmailExists($conn, $emailRaw, $userId)) {
                http_response_code(409);
                echo json_encode(["status"=>"error","message"=>"Email already used by another user."]);
                return;
            }
            $userSet[] = "email='$emailEsc'";
        }
    }

    if (array_key_exists('phone', $data)) {
        $phone = mysqli_real_escape_string($conn, (string)$data['phone']);
        $set[] = "prof_phone='$phone'";
    }

    if (array_key_exists('qualifications', $data)) {
        $qualsArr = normalize_array($data['qualifications']);
        $qualStr  = mysqli_real_escape_string($conn, json_encode(array_values($qualsArr), JSON_UNESCAPED_UNICODE));
        $set[] = "prof_qualifications='$qualStr'";
    }

    if (array_key_exists('subject_ids', $data)) {
        $subjIds         = normalize_array($data['subject_ids']);
        $subjects_json   = mysqli_real_escape_string($conn, json_encode(array_map('intval', $subjIds)));
        $subj_count      = is_array($subjIds) ? count($subjIds) : 0;
        $set[] = "prof_subject_ids='$subjects_json'";
        $set[] = "subj_count=$subj_count";
    }

    if (array_key_exists('school_year', $data)) {
        $sy = trim((string)$data['school_year']);
        if ($sy === '') {
            $set[] = "school_year=NULL";
        } else {
            $set[] = "school_year='" . mysqli_real_escape_string($conn, $sy) . "'";
        }
    }

    $passwordChanged = false;
    $passwordRaw = '';
    if (array_key_exists('password', $data)) {
        $passwordRaw = (string)$data['password'];
        if (trim($passwordRaw) !== '' && $userId > 0) {
            $passwordEsc = mysqli_real_escape_string($conn, $passwordRaw);
            $userSet[] = "password='$passwordEsc'";
            $passwordChanged = true;
        }
    }

    if (array_key_exists('username', $data)) {
        $uRaw = (string)$data['username'];
        if ($uRaw !== '') {
            if ($userId > 0 && userUsernameExists($conn, $uRaw, $userId)) {
                http_response_code(409);
                echo json_encode(["status"=>"error","message"=>"Username already used by another user."]);
                return;
            }
            $uEsc = mysqli_real_escape_string($conn, $uRaw);
            $set[] = "prof_username='$uEsc'";
            if ($userId > 0) $userSet[] = "username='$uEsc'";
        }
    }

    if (empty($set) && empty($userSet)) {
        echo json_encode([
            "status"=>"success",
            "message"=>"No changes",
            "id"=>$id,
            "password_changed"=>false
        ]);
        return;
    }

    if (!empty($set)) {
        $sql = "UPDATE professors SET " . implode(',', $set) . " WHERE prof_id=".(int)$id;
        if (!$conn->query($sql)) {
            log_activity($conn, 'professors', 'update', "FAILED update ID {$id}: ".$conn->error, $id, $userId);
            http_response_code(500);
            echo json_encode(["status"=>"error","message"=>$conn->error]);
            return;
        }
    }

    if (!empty($userSet) && $userId > 0) {
        $userSet[] = "updated_at='".date('Y-m-d H:i:s')."'";
        $sqlU = "UPDATE users SET ".implode(',', $userSet)." WHERE user_id=".$userId;
        if (!$conn->query($sqlU)) {
            log_activity($conn, 'users', 'update', "FAILED linked user update for prof {$id}: ".$conn->error, $id, $userId);
            http_response_code(500);
            echo json_encode(["status"=>"error","message"=>"Failed updating linked user: ".$conn->error]);
            return;
        }
    }

    $syncResult = firebaseSync($conn)->syncSingleProfessor($id);

    $emailResult = null;
    if ($passwordChanged) {
        $sendToEmail = array_key_exists('email', $data) ? trim((string)$data['email']) : $currentEmail;
        $sendToName  = array_key_exists('name', $data)  ? (string)$data['name'] : $currentName;
        $username    = array_key_exists('username', $data) ? (string)$data['username'] : $currentUsername;
        $subjIdsForEmail = array_key_exists('subject_ids', $data) ? normalize_array($data['subject_ids']) : $currentSubjIds;
        $subjectRows = fetchSubjectsByIds($conn, $subjIdsForEmail);

        if ($sendToEmail !== '') {
            $emailResult = sendWelcomeProfessorEmail(
                $sendToEmail,
                $sendToName,
                $username,
                $passwordRaw,
                'Systems Plus Computer College Caloocan',
                'https://your-portal.example.com/login',
                $subjectRows
            );
            log_activity($conn, 'professors', 'update', "Password reset email attempted for {$sendToEmail}", $id, $userId);
        }
    }

    $desc = "Updated professor ID: {$id}" . ($passwordChanged ? " | Password Reset" : "");
    log_activity($conn, 'professors', 'update', $desc, $id, $userId);

    echo json_encode([
        "status"=>"success",
        "message"=>"Professor updated",
        "id"=>$id,
        "user_id"=>$userId,
        "firebase_sync"=>$syncResult,
        "password_changed"=>$passwordChanged,
        "email"=>$emailResult
    ]);
}

function deleteProfessor(mysqli $conn, int $id) {
    if ($id <= 0) {
        log_activity($conn, 'professors', 'delete', "FAILED delete: missing ID", null, null);
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }

    $res = $conn->query("SELECT user_id FROM professors WHERE prof_id=".(int)$id." LIMIT 1");
    $userId = 0;
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $userId = (int)($row['user_id'] ?? 0);
    }

    $sql = "DELETE FROM professors WHERE prof_id=".(int)$id;
    if (!$conn->query($sql)) {
        log_activity($conn, 'professors', 'delete', "FAILED delete ID {$id}: ".$conn->error, $id, $userId);
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$conn->error]);
        return;
    }

    if ($userId > 0) {
        $conn->query("DELETE FROM users WHERE user_id=".$userId." LIMIT 1");
    }

    $syncResult = firebaseSync($conn)->deleteProfessorInFirebase($id);

    $desc = "Deleted professor ID: {$id}";
    log_activity($conn, 'professors', 'delete', $desc, $id, $userId);

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
                'user_id'        => isset($row['user_id']) ? (int)$row['user_id'] : null,
                'prof_name'      => $row['prof_name'],
                'prof_email'     => $row['prof_email'],
                'prof_phone'     => $row['prof_phone'],
                'prof_username'  => $row['prof_username'] ?? null,
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
        log_activity($conn, 'professors', 'read', "FAILED view: missing ID", null, null);
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }
    $res = $conn->query("SELECT * FROM professors WHERE prof_id=".(int)$id." LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        log_activity($conn, 'professors', 'read', "FAILED view: professor not found (ID {$id})", $id, null);
        http_response_code(404);
        echo json_encode(["status"=>"error","message"=>"Professor not found"]);
        return;
    }
    $row = $res->fetch_assoc();

    $ids = json_decode($row['prof_subject_ids'] ?? '[]', true) ?: [];
    $subjects = fetchSubjectsByIds($conn, $ids);

    $prof = [
        'id'              => (int)$row['prof_id'],
        'user_id'         => isset($row['user_id']) ? (int)$row['user_id'] : null,
        'name'            => $row['prof_name'],
        'email'           => $row['prof_email'],
        'phone'           => $row['prof_phone'],
        'username'        => $row['prof_username'] ?? null,
        'qualifications'  => json_decode($row['prof_qualifications'] ?? '[]', true) ?: [],
        'subjects'        => $subjects,
        'subject_ids'     => $ids,
        'subj_count'      => (int)$row['subj_count'],
        'school_year'     => $row['school_year'] ?? null,
    ];

    log_activity($conn, 'professors', 'read', "Viewed professor ID: {$id}", $id, $prof['user_id']);

    $currentSY = ss_get_current_school_year($conn);
    echo json_encode(["status"=>"success","data"=>$prof,"current_school_year"=>$currentSY]);
}

$conn->close();
