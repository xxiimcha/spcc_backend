<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "../connect.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) { $input = $_POST; }

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if ($username === '' || $password === '') {
    echo json_encode(["success" => false, "message" => "Username and password required"]);
    exit();
}

$usernameEsc = $conn->real_escape_string($username);

$sql = "SELECT id, username, email, name, password_hash, is_active 
        FROM admins 
        WHERE username = '$usernameEsc' OR email = '$usernameEsc'
        LIMIT 1";

$result = $conn->query($sql);

if (!$result || $result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "Invalid username or password"]);
    exit();
}

$admin = $result->fetch_assoc();

if ((int)$admin['is_active'] !== 1) {
    echo json_encode(["success" => false, "message" => "Account is inactive"]);
    exit();
}

if (!password_verify($password, $admin['password_hash'])) {
    echo json_encode(["success" => false, "message" => "Invalid username or password"]);
    exit();
}

$user = [
    "id"       => (string)$admin['id'],
    "username" => $admin['username'],
    "email"    => $admin['email'],
    "name"     => $admin['name'],
    "role"     => "admin"
];

echo json_encode([
    "success" => true,
    "data"    => ["user" => $user]
]);
