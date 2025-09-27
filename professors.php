<?php
include 'cors_helper.php';
handleCORS();

include 'connect.php';

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
            // expose both snake_case and camelCase for compatibility
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
    // Validate required fields
    $requiredFields = ['name', 'qualifications']; // Removed department from required fields
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
            exit();
        }
    }
    
    // Prepare values
    $name = $data['name'];
    $username = isset($data['username']) ? $data['username'] : null;
    $password = isset($data['password']) ? $data['password'] : null;  // Removed password hashing
    $email = isset($data['email']) ? $data['email'] : null;
    $phone = isset($data['phone']) ? $data['phone'] : null;
    $qualifications = json_encode($data['qualifications']);
    
    // Prepare the SQL statement - removed department field
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

        // Auto-sync to Firebase
        try {
            require_once 'realtime_firebase_sync.php';
            $sync = new RealtimeFirebaseSync();
            $sync->syncProfessors(); // Sync all professors
        } catch (Exception $e) {
            error_log("Firebase sync failed for professor $id: " . $e->getMessage());
        }
        
        // Return success immediately and exit
        http_response_code(201);
        echo json_encode([
            "status" => "success",
            "message" => "Professor added successfully",
            "id" => $id,
            "data" => $professor
        ]);
        exit(); // Prevent any additional output
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
        exit(); // Prevent any additional output
    }
    
    $stmt->close();
}

// Function to update a professor
function updateProfessor($conn, $id, $data) {
    // Check if professor exists
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
    
    // Prepare values
    $name = $data['name'];
    $email = isset($data['email']) ? $data['email'] : null;
    $phone = isset($data['phone']) ? $data['phone'] : null;
    $qualifications = json_encode($data['qualifications']);
    
    // Prepare the SQL statement - removed department field
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
        // Get the updated professor data
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

// Function to delete a professor
function deleteProfessor($conn, $id) {
    // Check if professor exists
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
    
    // Prepare the SQL statement
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

// Close the database connection
$conn->close();
?>