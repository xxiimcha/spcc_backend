<?php
include 'cors_helper.php';
handleCORS();

include 'connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

function sendWelcomeProfessorEmail($toEmail, $toName, $username, $plainPassword, $schoolName, $portalUrl) {
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

        $alt = "Welcome to $schoolName\n\nHi ".($toName ?: 'Professor').",\n\nYour professor account has been created.\n\nUsername: $username\nTemporary Password: $plainPassword\nLogin: $portalUrl\n\nPlease change your password after logging in.";

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
    case 'GET':
        if (isset($_GET['id'])) {
            getProfessor($conn, (int)$_GET['id']);
        } else {
            getAllProfessors($conn);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        createProfessor($conn, $data);
        break;

    case 'PUT':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Professor ID is required"]);
            exit();
        }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        updateProfessor($conn, (int)$_GET['id'], $data);
        break;

    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Professor ID is required"]);
            exit();
        }
        deleteProfessor($conn, (int)$_GET['id']);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        break;
}

function getAllProfessors($conn) {
    $sql = "
        SELECT 
            p.*,
            COALESCE(s.cnt, 0) AS subject_count
        FROM professors p
        LEFT JOIN (
            SELECT prof_id, COUNT(*) AS cnt
            FROM schedules
            GROUP BY prof_id
        ) s ON s.prof_id = p.prof_id
        ORDER BY p.prof_id ASC
    ";

    $result = $conn->query($sql);
    if (!$result) {
        http_response_code(500);
        echo json_encode(["success" => false, "status" => "error", "message" => "Failed to fetch professors"]);
        return;
    }

    $professors = [];
    while ($row = $result->fetch_assoc()) {
        $quals = [];
        if (isset($row['prof_qualifications']) && $row['prof_qualifications'] !== null && $row['prof_qualifications'] !== '') {
            $decoded = json_decode($row['prof_qualifications'], true);
            $quals = is_array($decoded) ? $decoded : [];
        }

        $professors[] = [
            'prof_id'        => (int)$row['prof_id'],
            'prof_name'      => $row['prof_name'],
            'prof_email'     => $row['prof_email'],
            'prof_phone'     => $row['prof_phone'],
            'qualifications' => $quals,
            'prof_username'  => $row['prof_username'],
            'prof_password'  => $row['prof_password'],
            'subject_count'  => (int)$row['subject_count'],
            'subjectCount'   => (int)$row['subject_count'],
        ];
    }

    echo json_encode(["success" => true, "status" => "success", "data" => $professors]);
}

function getProfessor($conn, $id) {
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            (
                SELECT COUNT(*) 
                FROM schedules s 
                WHERE s.prof_id = p.prof_id
            ) AS subject_count
        FROM professors p
        WHERE p.prof_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!$res || $res->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Professor not found"]);
        $stmt->close();
        return;
    }

    $row = $res->fetch_assoc();
    $stmt->close();

    $quals = [];
    if (isset($row['prof_qualifications']) && $row['prof_qualifications'] !== null && $row['prof_qualifications'] !== '') {
        $decoded = json_decode($row['prof_qualifications'], true);
        $quals = is_array($decoded) ? $decoded : [];
    }

    $professor = [
        'id'             => (int)$row['prof_id'],
        'name'           => $row['prof_name'],
        'email'          => $row['prof_email'],
        'phone'          => $row['prof_phone'],
        'qualifications' => $quals,
        'username'       => $row['prof_username'],
        'password'       => $row['prof_password'],
        'subject_count'  => (int)$row['subject_count'],
        'subjectCount'   => (int)$row['subject_count'],
    ];

    echo json_encode(["status" => "success", "data" => $professor]);
}

function createProfessor($conn, $data) {
    $requiredFields = ['name', 'qualifications'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
            exit();
        }
    }

    $name = $data['name'];
    $username = isset($data['username']) ? $data['username'] : null;
    $password = isset($data['password']) ? $data['password'] : null;
    $email = isset($data['email']) ? $data['email'] : null;
    $phone = isset($data['phone']) ? $data['phone'] : null;
    $qualifications = json_encode($data['qualifications']);

    $sql = "INSERT INTO professors (prof_name, prof_username, prof_password, prof_email, prof_phone, prof_qualifications, subj_count)
        VALUES (?, ?, ?, ?, ?, ?, 0)";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to prepare SQL statement"]);
        exit();
    }

    $stmt->bind_param("ssssss", $name, $username, $password, $email, $phone, $qualifications);

    if ($stmt->execute()) {
        $id = $conn->insert_id;
        $professor = [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'qualifications' => $data['qualifications'],
            'username' => $username,
            'password' => $password,
            'subjectCount' => 0,
            'subject_count' => 0
        ];

        try {
            require_once 'realtime_firebase_sync.php';
            $sync = new RealtimeFirebaseSync();
            $sync->syncProfessors();
        } catch (Exception $e) {
            error_log("Firebase sync failed for professor $id: " . $e->getMessage());
        }

        $emailResult = ['sent' => false, 'message' => 'Skipped (no email)'];
        if (!empty($email)) {
            $emailResult = sendWelcomeProfessorEmail(
                $email,
                $name,
                $username,
                $password,
                'Systems Plus Computer College Caloocan',
                'https://your-portal.example.com/login'
            );
        }

        http_response_code(201);
        echo json_encode([
            "status" => "success",
            "message" => "Professor added successfully",
            "id" => $id,
            "data" => $professor,
            "email" => $emailResult
        ]);
        exit();
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
        exit();
    }

    $stmt->close();
}

function updateProfessor($conn, $id, $data) {
    $checkStmt = $conn->prepare("SELECT prof_id FROM professors WHERE prof_id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Professor not found"]);
        $checkStmt->close();
        exit();
    }
    $checkStmt->close();

    $name = $data['name'];
    $email = isset($data['email']) ? $data['email'] : null;
    $phone = isset($data['phone']) ? $data['phone'] : null;
    $qualifications = json_encode($data['qualifications']);

    $sql = "UPDATE professors SET 
            prof_name = ?, 
            prof_email = ?, 
            prof_phone = ?, 
            prof_qualifications = ? 
            WHERE prof_id = ?";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to prepare SQL statement"]);
        exit();
    }

    $stmt->bind_param("ssssi", $name, $email, $phone, $qualifications, $id);

    if ($stmt->execute()) {
        $updatedProfessor = [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'qualifications' => $data['qualifications']
        ];

        echo json_encode([
            "status" => "success", 
            "message" => "Professor updated successfully",
            "data" => $updatedProfessor
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
    }

    $stmt->close();
}

function deleteProfessor($conn, $id) {
    $checkStmt = $conn->prepare("SELECT prof_id FROM professors WHERE prof_id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Professor not found"]);
        $checkStmt->close();
        exit();
    }
    $checkStmt->close();

    $sql = "DELETE FROM professors WHERE prof_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success", 
            "message" => "Professor deleted successfully",
            "data" => ["id" => $id]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
    }

    $stmt->close();
}

$conn->close();
