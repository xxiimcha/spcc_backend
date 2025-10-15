<?php
ini_set('display_errors', 1); error_reporting(E_ALL);

// CORS
$allowed = ['http://localhost:5174','http://127.0.0.1:5500','http://127.0.0.1:5501','http://localhost:3000','http://localhost:5173','https://spcc-web.vercel.app'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . (in_array($origin,$allowed) ? $origin : '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$identifier = trim((string)($input['username'] ?? ''));
$password   = (string)($input['password'] ?? '');
if ($identifier === '' || $password === '') { echo json_encode(['success'=>false,'message'=>'Username and password are required']); exit; }

require_once __DIR__.'/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
  error_log('DB: '.($conn->connect_error ?? 'no mysqli')); echo json_encode(['success'=>false,'message'=>'Database connection error']); exit;
}
$conn->set_charset('utf8mb4');

$sql = "SELECT sh_id, sh_name, sh_username, sh_email, sh_password
        FROM school_heads
        WHERE LOWER(sh_username) = LOWER(?) OR LOWER(sh_email) = LOWER(?)
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $identifier, $identifier);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$user) { echo json_encode(['success'=>false,'message'=>'Invalid username or password']); exit; }

// normalize possible padding/newlines in DB value
$dbPass = (string)$user['sh_password'];
$dbPass = preg_replace("/(\r\n|\n|\r)+$/", "", $dbPass);
$dbPass = trim($dbPass);

if ($password !== $dbPass && trim($password) !== $dbPass) {
  echo json_encode(['success'=>false,'message'=>'Invalid username or password']); exit;
}

unset($user['sh_password']);
echo json_encode([
  'success'=>true,
  'message'=>'Login successful',
  'user'=>[
    'id'=>(int)$user['sh_id'],
    'name'=>$user['sh_name'],
    'username'=>$user['sh_username'],
    'email'=>$user['sh_email'],
    'role'=>'school_head'
  ]
]);
