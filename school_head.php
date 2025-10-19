<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { exit(0); }

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
            getSchoolHead($conn, $_GET['id']);
        } else {
            getAllSchoolHeads($conn);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        if (isset($data['action']) && $data['action'] === 'login') {
            loginSchoolHead($conn, $data);
        } else {
            createSchoolHead($conn, $data); // <-- implements users insert (no stmt)
        }
        break;

    case 'PUT':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "School Head ID is required"]);
            exit();
        }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        updateSchoolHead($conn, $_GET['id'], $data);
        break;

    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "School Head ID is required"]);
            exit();
        }
        deleteSchoolHead($conn, $_GET['id']);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        break;
}

function getAllSchoolHeads($conn) {
    $sql = "SELECT * FROM school_heads";
    $result = $conn->query($sql);

    if ($result) {
        $schoolHeads = [];
        while ($row = $result->fetch_assoc()) {
            $schoolHeads[] = [
                'id'       => (int)$row['sh_id'],
                'user_id'  => isset($row['user_id']) ? (int)$row['user_id'] : null,
                'name'     => $row['sh_name'],
                'email'    => $row['sh_email'],
                'username' => $row['sh_username'],
                'password' => $row['sh_password'],
            ];
        }
        echo json_encode(["status" => "success", "data" => $schoolHeads]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to fetch school heads"]);
    }
}

function getSchoolHead($conn, $idRaw) {
    $id = (int)$idRaw;
    $sql = "SELECT * FROM school_heads WHERE sh_id={$id} LIMIT 1";
    $res = $conn->query($sql);

    if ($res && $row = $res->fetch_assoc()) {
        echo json_encode([
            "status" => "success",
            "data" => [
                'id'       => (int)$row['sh_id'],
                'user_id'  => isset($row['user_id']) ? (int)$row['user_id'] : null,
                'name'     => $row['sh_name'],
                'email'    => $row['sh_email'],
                'username' => $row['sh_username'],
                'password' => $row['sh_password'],
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "School Head not found"]);
    }
}

function loginSchoolHead($conn, $data) {
    foreach (['username','password'] as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
            exit();
        }
    }
    $u = $conn->real_escape_string((string)$data['username']);
    $p = $conn->real_escape_string((string)$data['password']);

    $sql = "SELECT * FROM school_heads WHERE sh_username='{$u}' AND sh_password='{$p}' LIMIT 1";
    $res = $conn->query($sql);

    if ($res && $row = $res->fetch_assoc()) {
        echo json_encode([
            "status"  => "success",
            "message" => "Login successful",
            "data"    => [
                'id'       => (int)$row['sh_id'],
                'user_id'  => isset($row['user_id']) ? (int)$row['user_id'] : null,
                'name'     => $row['sh_name'],
                'email'    => $row['sh_email'],
                'username' => $row['sh_username'],
                'password' => $row['sh_password'],
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid username or password"]);
    }
}

/**
 * CREATE: also inserts into `users` (role='school_head'), no prepared statements.
 * Requires a `user_id` column in `school_heads`.
 */
function createSchoolHead($conn, $data) {
    foreach (['name','username','password'] as $f) {
        if (!isset($data[$f]) || trim((string)$data[$f]) === '') {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing required field: $f"]);
            exit();
        }
    }

    $name     = trim((string)$data['name']);
    $username = trim((string)$data['username']);
    $password = trim((string)$data['password']);
    $email    = isset($data['email']) && trim((string)$data['email']) !== '' ? trim((string)$data['email']) : '';

    $nameEsc     = $conn->real_escape_string($name);
    $usernameEsc = $conn->real_escape_string($username);
    $passwordEsc = $conn->real_escape_string($password);
    $emailEsc    = $conn->real_escape_string($email);

    $dup1 = $conn->query("SELECT sh_id FROM school_heads WHERE sh_username='{$usernameEsc}' LIMIT 1");
    if ($dup1 && $dup1->num_rows > 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "School Head with this username already exists"]);
        return;
    }
    $dup2 = $conn->query("SELECT sh_id FROM school_heads WHERE sh_name='{$nameEsc}' LIMIT 1");
    if ($dup2 && $dup2->num_rows > 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "School Head with this name already exists"]);
        return;
    }

    $uDup1 = $conn->query("SELECT user_id FROM users WHERE username='{$usernameEsc}' LIMIT 1");
    if ($uDup1 && $uDup1->num_rows > 0) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "Username already exists in users"]);
        return;
    }
    if ($email !== '') {
        $uDup2 = $conn->query("SELECT user_id FROM users WHERE email='{$emailEsc}' LIMIT 1");
        if ($uDup2 && $uDup2->num_rows > 0) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "Email already exists in users"]);
            return;
        }
    }

    $now = date('Y-m-d H:i:s');
    if ($email === '') {
        $sqlUser = "INSERT INTO users (username, password, role, status, created_at, updated_at)
                    VALUES ('{$usernameEsc}', '{$passwordEsc}', 'school_head', 'active', '{$now}', '{$now}')";
    } else {
        $sqlUser = "INSERT INTO users (username, password, email, role, status, created_at, updated_at)
                    VALUES ('{$usernameEsc}', '{$passwordEsc}', '{$emailEsc}', 'school_head', 'active', '{$now}', '{$now}')";
    }
    if (!$conn->query($sqlUser)) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed creating linked user: " . $conn->error]);
        return;
    }
    $userId = (int)$conn->insert_id;

    $emailSQL = ($email === '') ? "NULL" : "'{$emailEsc}'";
    $sqlSH = "INSERT INTO school_heads (user_id, sh_name, sh_username, sh_password, sh_email)
              VALUES ({$userId}, '{$nameEsc}', '{$usernameEsc}', '{$passwordEsc}', {$emailSQL})";
    if (!$conn->query($sqlSH)) {
        $conn->query("DELETE FROM users WHERE user_id={$userId} LIMIT 1");
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $conn->error]);
        return;
    }

    $id = (int)$conn->insert_id;
    echo json_encode([
        "status"  => "success",
        "message" => "School Head added successfully",
        "id"      => $id,
        "data"    => [
            "id"       => $id,
            "user_id"  => $userId,
            "name"     => $name,
            "email"    => ($email === '' ? null : $email),
            "username" => $username,
            "password" => $password
        ]
    ]);
}

function updateSchoolHead($conn, $idRaw, $data) {
    $id = (int)$idRaw;
    $exists = $conn->query("SELECT sh_id FROM school_heads WHERE sh_id={$id} LIMIT 1");
    if (!$exists || $exists->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "School Head not found"]);
        return;
    }

    $name     = isset($data['name']) && trim((string)$data['name']) !== '' ? $conn->real_escape_string(trim((string)$data['name'])) : null;
    $username = isset($data['username']) && trim((string)$data['username']) !== '' ? $conn->real_escape_string(trim((string)$data['username'])) : null;
    $email    = isset($data['email']) && trim((string)$data['email']) !== '' ? $conn->real_escape_string(trim((string)$data['email'])) : null;
    $password = isset($data['password']) && trim((string)$data['password']) !== '' ? $conn->real_escape_string(trim((string)$data['password'])) : null;

    if ($username !== null) {
        $dup = $conn->query("SELECT sh_id FROM school_heads WHERE sh_username='{$username}' AND sh_id<>$id LIMIT 1");
        if ($dup && $dup->num_rows > 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "School Head with this username already exists"]);
            return;
        }
    }
    if ($name !== null) {
        $dup = $conn->query("SELECT sh_id FROM school_heads WHERE sh_name='{$name}' AND sh_id<>$id LIMIT 1");
        if ($dup && $dup->num_rows > 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "School Head with this name already exists"]);
            return;
        }
    }

    $set = [];
    if (!is_null($name))     $set[] = "sh_name='{$name}'";
    if (!is_null($username)) $set[] = "sh_username='{$username}'";
    if (!is_null($email))    $set[] = "sh_email='{$email}'";
    if (!is_null($password)) $set[] = "sh_password='{$password}'";

    if (empty($set)) {
        echo json_encode(["status" => "success", "message" => "No changes"]);
        return;
    }

    $sql = "UPDATE school_heads SET " . implode(',', $set) . " WHERE sh_id={$id}";
    if ($conn->query($sql)) {
        echo json_encode([
            "status"  => "success",
            "message" => "School Head updated successfully",
            "data"    => [
                "id"       => $id,
                "name"     => $name ?? '',
                "username" => $username ?? '',
                "email"    => $email ?? '',
                "password" => $password ?? ''
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $conn->error]);
    }
}

function deleteSchoolHead($conn, $idRaw) {
    $id = (int)$idRaw;

    $res = $conn->query("SELECT user_id FROM school_heads WHERE sh_id={$id} LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "School Head not found"]);
        return;
    }
    $row = $res->fetch_assoc();
    $userId = (int)($row['user_id'] ?? 0);

    $del = $conn->query("DELETE FROM school_heads WHERE sh_id={$id} LIMIT 1");
    if ($del) {
        if ($userId > 0) { $conn->query("DELETE FROM users WHERE user_id={$userId} LIMIT 1"); }
        echo json_encode([
            "status"  => "success",
            "message" => "School Head deleted successfully",
            "data"    => ["id" => $id]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $conn->error]);
    }
}

$conn->close();
