<?php
include 'cors_helper.php';
handleCORS();

include 'connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

/* -------------------------- helpers -------------------------- */

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function normalize_array($v): array {
    if (is_array($v)) return $v;
    // accept comma-separated string as a fallback
    if (is_string($v) && trim($v) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
    }
    return [];
}

function emailExists(mysqli $conn, string $email, int $excludeId = 0): bool {
    if ($email === '') return false;
    if ($excludeId > 0) {
        $stmt = $conn->prepare("SELECT 1 FROM professors WHERE prof_email=? AND prof_id<>?");
        $stmt->bind_param('si', $email, $excludeId);
    } else {
        $stmt = $conn->prepare("SELECT 1 FROM professors WHERE prof_email=?");
        $stmt->bind_param('s', $email);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function fetchSubjectsByIds($conn, $ids) {
    if (!is_array($ids) || empty($ids)) return [];
    $safe = array_values(array_unique(array_filter(array_map(fn($v)=>(int)$v, $ids), fn($v)=>$v>0)));
    if (empty($safe)) return [];
    $in = implode(',', $safe);
    $sql = "SELECT subj_id, subj_code, subj_name FROM subjects WHERE subj_id IN ($in) ORDER BY subj_name ASC";
    $res = $conn->query($sql);
    $out = [];
    if ($res) {
        while($row = $res->fetch_assoc()) {
            $out[] = [
                'subj_id'   => (int)$row['subj_id'],
                'subj_code' => $row['subj_code'],
                'subj_name' => $row['subj_name'],
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

/* ------------------------- bootstrap ------------------------- */

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$payload = read_json_body();

// method override support: POST + {"_method":"PUT"} or form-encoded _method
$methodOverride = null;
if (isset($payload['_method'])) $methodOverride = strtoupper(trim((string)$payload['_method']));
if ($method === 'POST' && isset($_POST['_method']) && !$methodOverride) {
    $methodOverride = strtoupper(trim((string)$_POST['_method']));
}
$effectiveMethod = $methodOverride ?: $method;

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

/* -------------------------- handlers ------------------------- */

function createProfessor(mysqli $conn, array $data) {
    $name      = (string)($data['name'] ?? '');
    $username  = (string)($data['username'] ?? '');
    $password  = (string)($data['password'] ?? '');
    $email     = trim((string)($data['email'] ?? ''));
    $phone     = (string)($data['phone'] ?? '');
    $qualsArr  = normalize_array($data['qualifications'] ?? []);
    $subjIds   = normalize_array($data['subject_ids'] ?? []);

    if ($email !== '' && emailExists($conn, $email)) {
        http_response_code(409);
        echo json_encode(["status"=>"error","message"=>"Email already exists."]);
        return;
    }

    $qualStr = json_encode(array_values($qualsArr), JSON_UNESCAPED_UNICODE);
    $subjects_json = json_encode(array_map('intval', $subjIds));
    $subj_count = is_array($subjIds) ? count($subjIds) : 0;

    $stmt = $conn->prepare("
        INSERT INTO professors 
        (prof_name, prof_username, prof_password, prof_email, prof_phone, prof_qualifications, prof_subject_ids, subj_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('sssssssi', $name, $username, $password, $email, $phone, $qualStr, $subjects_json, $subj_count);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$conn->error]);
        return;
    }

    $id = $conn->insert_id;

    $subjectRows = fetchSubjectsByIds($conn, $subjIds);
    $emailResult = $email !== ''
        ? sendWelcomeProfessorEmail(
            $email, $name, $username, $password,
            'Systems Plus Computer College Caloocan',
            'https://your-portal.example.com/login',
            $subjectRows
          )
        : ['sent'=>false,'message'=>'No email'];

    echo json_encode(["status"=>"success","message"=>"Professor added","id"=>$id,"email"=>$emailResult]);
}

function updateProfessor(mysqli $conn, int $id, array $data) {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }

    $name      = (string)($data['name'] ?? '');
    $email     = trim((string)($data['email'] ?? ''));
    $phone     = (string)($data['phone'] ?? '');
    $qualsArr  = normalize_array($data['qualifications'] ?? []);
    $subjIds   = normalize_array($data['subject_ids'] ?? []);

    if ($email !== '' && emailExists($conn, $email, $id)) {
        http_response_code(409);
        echo json_encode(["status"=>"error","message"=>"Email already taken by another professor."]);
        return;
    }

    $qualStr = json_encode(array_values($qualsArr), JSON_UNESCAPED_UNICODE);
    $subjects_json = json_encode(array_map('intval', $subjIds));
    $subj_count = is_array($subjIds) ? count($subjIds) : 0;

    $stmt = $conn->prepare("
        UPDATE professors SET 
            prof_name=?,
            prof_email=?,
            prof_phone=?,
            prof_qualifications=?,
            prof_subject_ids=?,
            subj_count=?
        WHERE prof_id=?
    ");
    $stmt->bind_param('ssssssi', $name, $email, $phone, $qualStr, $subjects_json, $subj_count, $id);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$conn->error]);
        return;
    }

    echo json_encode(["status"=>"success","message"=>"Professor updated","id"=>$id]);
}

function deleteProfessor(mysqli $conn, int $id) {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }
    $stmt = $conn->prepare("DELETE FROM professors WHERE prof_id=?");
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$conn->error]);
        return;
    }
    echo json_encode(["status"=>"success","message"=>"Professor deleted"]);
}

function getAllProfessors(mysqli $conn) {
    $sql = "SELECT * FROM professors ORDER BY prof_id ASC";
    $result = $conn->query($sql);
    $professors = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $professors[] = [
                'prof_id'        => (int)$row['prof_id'],
                'prof_name'      => $row['prof_name'],
                'prof_email'     => $row['prof_email'],
                'prof_phone'     => $row['prof_phone'],
                'prof_username'  => $row['prof_username'],
                'prof_password'  => $row['prof_password'],
                'qualifications' => json_decode($row['prof_qualifications'] ?? '[]', true) ?: [],
                // ✅ normalize key name expected by your frontend
                'subject_ids'    => json_decode($row['prof_subject_ids'] ?? '[]', true) ?: [],
                'subj_count'     => (int)$row['subj_count'],
            ];
        }
    }
    echo json_encode(["success"=>true,"data"=>$professors]);
}

function getProfessor(mysqli $conn, int $id) {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }
    $stmt = $conn->prepare("SELECT * FROM professors WHERE prof_id=? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) {
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
    ];

    echo json_encode(["status"=>"success","data"=>$prof]);
}

$conn->close();
