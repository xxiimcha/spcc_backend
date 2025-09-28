<?php
include 'cors_helper.php';
handleCORS();

include 'connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

function fetchSubjectsByIds($conn, $ids) {
    if (!is_array($ids) || empty($ids)) return [];

    // sanitize to integers and remove empties/dupes
    $safe = array_values(array_unique(array_filter(array_map(function($v){ return (int)$v; }, $ids), function($v){ return $v > 0; })));
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

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        createProfessor($conn, $data);
        break;
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        updateProfessor($conn, (int)($_GET['id'] ?? 0), $data);
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

function createProfessor($conn, $data) {
    $name  = mysqli_real_escape_string($conn, (string)($data['name'] ?? ''));
    $username = mysqli_real_escape_string($conn, (string)($data['username'] ?? ''));
    $password = mysqli_real_escape_string($conn, (string)($data['password'] ?? ''));
    $email = mysqli_real_escape_string($conn, (string)($data['email'] ?? ''));
    $phone = mysqli_real_escape_string($conn, (string)($data['phone'] ?? ''));
    $quals = json_encode($data['qualifications'] ?? []);
    $ids   = $data['subject_ids'] ?? [];
    $subjects_json = json_encode($ids);
    $subj_count = is_array($ids) ? count($ids) : 0;

    $sql = "INSERT INTO professors 
        (prof_name, prof_username, prof_password, prof_email, prof_phone, prof_qualifications, prof_subject_ids, subj_count)
        VALUES ('$name', '$username', '$password', '$email', '$phone', '$quals', '$subjects_json', $subj_count)";

    if ($conn->query($sql) === TRUE) {
        $id = $conn->insert_id;

        // Fetch subject rows for email (code + name)
        $subjectRows = fetchSubjectsByIds($conn, $ids);

        $emailResult = !empty($email)
            ? sendWelcomeProfessorEmail(
                $email,
                $name,
                $username,
                $password,
                'Systems Plus Computer College Caloocan',
                'https://your-portal.example.com/login',
                $subjectRows
              )
            : ['sent'=>false,'message'=>'No email'];

        echo json_encode(["status"=>"success","message"=>"Professor added","id"=>$id,"email"=>$emailResult]);
    } else {
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$conn->error]);
    }
}

function updateProfessor($conn, $id, $data) {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }
    $name  = mysqli_real_escape_string($conn, (string)($data['name'] ?? ''));
    $email = mysqli_real_escape_string($conn, (string)($data['email'] ?? ''));
    $phone = mysqli_real_escape_string($conn, (string)($data['phone'] ?? ''));
    $quals = json_encode($data['qualifications'] ?? []);
    $ids   = $data['subject_ids'] ?? [];
    $subjects_json = json_encode($ids);
    $subj_count = is_array($ids) ? count($ids) : 0;

    $sql = "UPDATE professors SET 
        prof_name='$name', 
        prof_email='$email', 
        prof_phone='$phone', 
        prof_qualifications='$quals',
        prof_subject_ids='$subjects_json',
        subj_count=$subj_count
        WHERE prof_id=$id";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status"=>"success","message"=>"Professor updated"]);
    } else {
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$conn->error]);
    }
}

function deleteProfessor($conn, $id) {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }
    $sql = "DELETE FROM professors WHERE prof_id=$id";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status"=>"success","message"=>"Professor deleted"]);
    } else {
        http_response_code(500);
        echo json_encode(["status"=>"error","message"=>$conn->error]);
    }
}

function getAllProfessors($conn) {
    $sql = "SELECT * FROM professors ORDER BY prof_id ASC";
    $result = $conn->query($sql);
    $professors = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $professors[] = [
                'prof_id'         => (int)$row['prof_id'],
                'prof_name'       => $row['prof_name'],
                'prof_email'      => $row['prof_email'],
                'prof_phone'      => $row['prof_phone'],
                'prof_username'   => $row['prof_username'],
                'prof_password'   => $row['prof_password'],
                'qualifications'  => json_decode($row['prof_qualifications'] ?? '[]', true) ?: [],
                'subjects_ids'    => json_decode($row['prof_subject_ids'] ?? '[]', true) ?: [],
                'subj_count'      => (int)$row['subj_count'],
            ];
        }
    }
    echo json_encode(["success"=>true,"data"=>$professors]);
}

function getProfessor($conn, $id) {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"Professor ID is required"]);
        return;
    }
    $sql = "SELECT * FROM professors WHERE prof_id=$id LIMIT 1";
    $res = $conn->query($sql);
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
        'subj_count'      => (int)$row['subj_count'],
    ];

    echo json_encode(["status"=>"success","data"=>$prof]);
}

$conn->close();
