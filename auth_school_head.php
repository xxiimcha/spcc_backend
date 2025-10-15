<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

// Handle CORS for multiple origins
$allowed_origins = [
    'http://localhost:5174',
    'http://127.0.0.1:5500',
    'http://127.0.0.1:5501',
    'http://localhost:3000',
    'http://localhost:5173',
    'https://spcc-web.vercel.app'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['username']) || !isset($input['password'])) {
    echo json_encode(['status' => 'error', 'message' => 'Username and password are required']);
    exit();
}

$username = trim($input['username']);
$password = trim($input['password']);

// Validate input
if (empty($username) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Username and password cannot be empty']);
    exit();
}

require_once __DIR__ . '/connect.php'; // 
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection not available');
    }

    $conn->set_charset('utf8mb4');

    // Use prepared statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT sh_id, sh_name, sh_username, sh_email, sh_password FROM school_heads WHERE sh_username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid username or password']);
        exit();
    }

    $user = $result->fetch_assoc();

    if ($password === $user['sh_password']) {
        unset($user['sh_password']);

        $userData = [
            'id' => $user['sh_id'],
            'name' => $user['sh_name'],
            'username' => $user['sh_username'],
            'email' => $user['sh_email']
        ];

        echo json_encode([
            'status' => 'success',
            'message' => 'Login successful',
            'user' => $userData
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid username or password']);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred. Please try again later.'
    ]);
}
?>
