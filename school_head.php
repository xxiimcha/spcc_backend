<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { echo json_encode(["status"=>"ok"]); exit(0); }

include 'connect.php';

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status"=>"error","message"=>"Database connection failed: ".$conn->connect_error]);
    exit();
}

/* ---------- helpers ---------- */
function json_fail(int $code, string $msg) {
    http_response_code($code);
    echo json_encode(["status"=>"error","message"=>$msg]);
    exit();
}
function column_exists(mysqli $c, string $table, string $col): bool {
    $t = $c->real_escape_string($table);
    $q = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'");
    return $q && $q->num_rows > 0;
}
/* ----------------------------- */

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) { getSchoolHead($conn, $_GET['id']); }
        else { getAllSchoolHeads($conn); }
        break;

    case 'POST':
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) json_fail(400, "Invalid JSON data");
        if (isset($data['action']) && $data['action'] === 'login') loginSchoolHead($conn, $data);
        else createSchoolHead($conn, $data);
        break;

    case 'PUT':
        if (!isset($_GET['id'])) json_fail(400, "School Head ID is required");
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) json_fail(400, "Invalid JSON data");
        updateSchoolHead($conn, $_GET['id'], $data);
        break;

    case 'DELETE':
        if (!isset($_GET['id'])) json_fail(400, "School Head ID is required");
        deleteSchoolHead($conn, $_GET['id']);
        break;

    default:
        json_fail(405, "Method not allowed");
}

/* ---------- handlers ---------- */

function getAllSchoolHeads(mysqli $conn) {
    $res = $conn->query("SELECT * FROM school_heads");
    if (!$res) json_fail(500, "Failed to fetch school heads: ".$conn->error);

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id'       => (int)$r['sh_id'],
            'user_id'  => isset($r['user_id']) ? (int)$r['user_id'] : null,
            'name'     => $r['sh_name'],
            'email'    => $r['sh_email'],
            'username' => $r['sh_username'],
            'password' => $r['sh_password'],
        ];
    }
    echo json_encode(["status"=>"success","data"=>$rows]);
}

function getSchoolHead(mysqli $conn, $idRaw) {
    $id = (int)$idRaw;
    $res = $conn->query("SELECT * FROM school_heads WHERE sh_id={$id} LIMIT 1");
    if (!$res) json_fail(500, "Query failed: ".$conn->error);
    if (!$res->num_rows) json_fail(404, "School Head not found");

    $row = $res->fetch_assoc();
    echo json_encode([
        "status"=>"success",
        "data"=>[
            'id'       => (int)$row['sh_id'],
            'user_id'  => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'name'     => $row['sh_name'],
            'email'    => $row['sh_email'],
            'username' => $row['sh_username'],
            'password' => $row['sh_password'],
        ]
    ]);
}

function loginSchoolHead(mysqli $conn, array $data) {
    if (!isset($data['username']) || !isset($data['password'])) json_fail(400, "Missing required fields: username, password");
    $u = $conn->real_escape_string((string)$data['username']);
    $p = $conn->real_escape_string((string)$data['password']);

    $res = $conn->query("SELECT * FROM school_heads WHERE sh_username='{$u}' AND sh_password='{$p}' LIMIT 1");
    if (!$res) json_fail(500, "Login query failed: ".$conn->error);
    if (!$res->num_rows) json_fail(401, "Invalid username or password");

    $row = $res->fetch_assoc();
    echo json_encode([
        "status"=>"success",
        "message"=>"Login successful",
        "data"=>[
            'id'       => (int)$row['sh_id'],
            'user_id'  => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'name'     => $row['sh_name'],
            'email'    => $row['sh_email'],
            'username' => $row['sh_username'],
            'password' => $row['sh_password'],
        ]
    ]);
}

function createSchoolHead(mysqli $conn, array $data) {
    foreach (['name','username','password'] as $f) {
        if (!isset($data[$f]) || trim((string)$data[$f])==='') json_fail(400, "Missing required field: {$f}");
    }

    $name     = trim((string)$data['name']);
    $username = trim((string)$data['username']);
    $password = trim((string)$data['password']);
    $email    = isset($data['email']) ? trim((string)$data['email']) : '';

    $nameEsc     = $conn->real_escape_string($name);
    $usernameEsc = $conn->real_escape_string($username);
    $passwordEsc = $conn->real_escape_string($password);
    $emailEsc    = $conn->real_escape_string($email);

    $d1 = $conn->query("SELECT sh_id FROM school_heads WHERE sh_username='{$usernameEsc}' LIMIT 1");
    if ($d1 && $d1->num_rows) json_fail(400, "School Head with this username already exists");
    $d2 = $conn->query("SELECT sh_id FROM school_heads WHERE sh_name='{$nameEsc}' LIMIT 1");
    if ($d2 && $d2->num_rows) json_fail(400, "School Head with this name already exists");

    $u1 = $conn->query("SELECT user_id FROM users WHERE username='{$usernameEsc}' LIMIT 1");
    if ($u1 && $u1->num_rows) json_fail(409, "Username already exists in users");
    if ($email !== '') {
        $u2 = $conn->query("SELECT user_id FROM users WHERE email='{$emailEsc}' LIMIT 1");
        if ($u2 && $u2->num_rows) json_fail(409, "Email already exists in users");
    }

    $now = date('Y-m-d H:i:s');
    if ($email === '') {
        $sqlUser = "INSERT INTO users (username,password,role,status,created_at,updated_at)
                    VALUES ('{$usernameEsc}','{$passwordEsc}','school_head','active','{$now}','{$now}')";
    } else {
        $sqlUser = "INSERT INTO users (username,password,email,role,status,created_at,updated_at)
                    VALUES ('{$usernameEsc}','{$passwordEsc}','{$emailEsc}','school_head','active','{$now}','{$now}')";
    }
    if (!$conn->query($sqlUser)) json_fail(500, "Failed creating linked user: ".$conn->error);
    $userId = (int)$conn->insert_id;

    $hasUserIdCol = column_exists($conn, 'school_heads', 'user_id');
    if ($hasUserIdCol) {
        $emailSQL = ($email === '') ? "NULL" : "'{$emailEsc}'";
        $sqlSH = "INSERT INTO school_heads (user_id, sh_name, sh_username, sh_password, sh_email)
                  VALUES ({$userId},'{$nameEsc}','{$usernameEsc}','{$passwordEsc}',{$emailSQL})";
    } else {
        // Fall back: still insert the school head (unlinked) so you can proceed
        $emailSQL = ($email === '') ? "NULL" : "'{$emailEsc}'";
        $sqlSH = "INSERT INTO school_heads (sh_name, sh_username, sh_password, sh_email)
                  VALUES ('{$nameEsc}','{$usernameEsc}','{$passwordEsc}',{$emailSQL})";
    }

    if (!$conn->query($sqlSH)) {
        // Roll back the just-created user to avoid orphaned account
        $conn->query("DELETE FROM users WHERE user_id={$userId} LIMIT 1");
        json_fail(500, "Failed creating school head: ".$conn->error);
    }

    $id = (int)$conn->insert_id;
    echo json_encode([
        "status"=>"success",
        "message"=>"School Head added successfully",
        "id"=>$id,
        "data"=>[
            "id"=>$id,
            "user_id"=>$hasUserIdCol ? $userId : null,
            "name"=>$name,
            "email"=>($email === '' ? null : $email),
            "username"=>$username,
            "password"=>$password
        ],
        "note"=>$hasUserIdCol ? null : "Note: school_heads.user_id column not found; user was created but not linked."
    ]);
}

function updateSchoolHead(mysqli $conn, $idRaw, array $data) {
    $id = (int)$idRaw;
    $exists = $conn->query("SELECT sh_id FROM school_heads WHERE sh_id={$id} LIMIT 1");
    if (!$exists || !$exists->num_rows) json_fail(404, "School Head not found");

    $name     = isset($data['name']) && trim((string)$data['name'])!=='' ? $conn->real_escape_string(trim((string)$data['name'])) : null;
    $username = isset($data['username']) && trim((string)$data['username'])!=='' ? $conn->real_escape_string(trim((string)$data['username'])) : null;
    $email    = isset($data['email']) && trim((string)$data['email'])!=='' ? $conn->real_escape_string(trim((string)$data['email'])) : null;
    $password = isset($data['password']) && trim((string)$data['password'])!=='' ? $conn->real_escape_string(trim((string)$data['password'])) : null;

    if ($username !== null) {
        $dup = $conn->query("SELECT sh_id FROM school_heads WHERE sh_username='{$username}' AND sh_id<>$id LIMIT 1");
        if ($dup && $dup->num_rows) json_fail(400, "School Head with this username already exists");
    }
    if ($name !== null) {
        $dup = $conn->query("SELECT sh_id FROM school_heads WHERE sh_name='{$name}' AND sh_id<>$id LIMIT 1");
        if ($dup && $dup->num_rows) json_fail(400, "School Head with this name already exists");
    }

    $set = [];
    if (!is_null($name))     $set[] = "sh_name='{$name}'";
    if (!is_null($username)) $set[] = "sh_username='{$username}'";
    if (!is_null($email))    $set[] = "sh_email='{$email}'";
    if (!is_null($password)) $set[] = "sh_password='{$password}'";
    if (!$set) { echo json_encode(["status"=>"success","message"=>"No changes"]); return; }

    $sql = "UPDATE school_heads SET ".implode(',', $set)." WHERE sh_id={$id}";
    if (!$conn->query($sql)) json_fail(500, "Update failed: ".$conn->error);

    echo json_encode([
        "status"=>"success",
        "message"=>"School Head updated successfully",
        "data"=>[
            "id"=>$id,
            "name"=>$name ?? '',
            "username"=>$username ?? '',
            "email"=>$email ?? '',
            "password"=>$password ?? ''
        ]
    ]);
}

function deleteSchoolHead(mysqli $conn, $idRaw) {
    $id = (int)$idRaw;
    $res = $conn->query("SELECT user_id FROM school_heads WHERE sh_id={$id} LIMIT 1");
    if (!$res || !$res->num_rows) json_fail(404, "School Head not found");
    $row = $res->fetch_assoc();
    $userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;

    if (!$conn->query("DELETE FROM school_heads WHERE sh_id={$id} LIMIT 1"))
        json_fail(500, "Delete failed: ".$conn->error);

    if ($userId > 0) $conn->query("DELETE FROM users WHERE user_id={$userId} LIMIT 1");

    echo json_encode(["status"=>"success","message"=>"School Head deleted successfully","data"=>["id"=>$id]]);
}

$conn->close();
