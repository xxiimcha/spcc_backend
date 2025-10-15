<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

// CORS
$allowed_origins = [
  'http://localhost:5174',
  'http://127.0.0.1:5500',
  'http://127.0.0.1:5501',
  'http://localhost:3000',
  'http://localhost:5173',
  'https://spcc-web.vercel.app'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin,$allowed_origins) ? $origin : '*'));
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['status'=>'error','message'=>'Method not allowed']); exit();
}

// Input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['username']) || !isset($input['password'])) {
  echo json_encode(['status'=>'error','message'=>'Username and password are required']); exit();
}
$identifier = trim((string)$input['username']);   // username OR email
$password   = (string)$input['password'];        // do not trim passwords silently

if ($identifier === '' || $password === '') {
  echo json_encode(['status'=>'error','message'=>'Username and password cannot be empty']); exit();
}

require_once __DIR__ . '/connect.php'; // must create $conn without echo/exit on error
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    throw new Exception('Database connection not available: '.($conn->connect_error ?? 'no mysqli'));
  }
  $conn->set_charset('utf8mb4');

  // Case-insensitive lookup for username OR email
  $sql = "SELECT sh_id, sh_name, sh_username, sh_email, sh_password
          FROM school_heads
          WHERE LOWER(sh_username) = LOWER(?) OR LOWER(sh_email) = LOWER(?)
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ss', $identifier, $identifier);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($res->num_rows === 0) {
    echo json_encode(['status'=>'error','message'=>'Invalid username or password']); exit();
  }

  $user = $res->fetch_assoc();

  // Trim possible trailing spaces coming from DB
  $dbPass = rtrim((string)$user['sh_password']); // rtrim to remove right-side spaces/newlines

  // Exact (case-sensitive) compare
  $valid = ($password === $dbPass);

  // OPTIONAL legacy fallback: case-insensitive password compare for old accounts
  // Uncomment this block if you want to accept CCATOR1 == ccator1:
  // if (!$valid && strcasecmp($password, $dbPass) === 0) {
  //   $valid = true;
  // }

  if (!$valid) {
    echo json_encode(['status'=>'error','message'=>'Invalid username or password']); exit();
  }

  unset($user['sh_password']);
  $userData = [
    'id'       => (int)$user['sh_id'],
    'name'     => $user['sh_name'],
    'username' => $user['sh_username'],
    'email'    => $user['sh_email'],
    'role'     => 'school_head'
  ];

  echo json_encode(['status'=>'success','message'=>'Login successful','user'=>$userData]);

  $stmt->close();
  $conn->close();

} catch (Throwable $e) {
  error_log('auth_school_head error: '.$e->getMessage());
  echo json_encode(['status'=>'error','message'=>'An error occurred. Please try again later.']);
}
