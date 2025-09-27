<?php
// Handle CORS for multiple origins
$allowed_origins = [
    'http://localhost:5174',
    'http://127.0.0.1:5500',
    'http://127.0.0.1:5501',
    'http://localhost:3000',
    'http://localhost:5173'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
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

try {
    // Database connection - update these values according to your database configuration
    $host = 'localhost';
    $dbname = 'spcc_scheduling_system'; // Update this to your actual database name
    $dbuser = 'root'; // Update this to your database username
    $dbpass = ''; // Update this to your database password
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Prepare and execute query to find the school head
    $stmt = $pdo->prepare("SELECT sh_id, sh_name, sh_username, sh_email, sh_password FROM school_heads WHERE sh_username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid username or password']);
        exit();
    }
    
    // Verify password - assuming passwords are stored as plain text for now
    // In production, you should use password_hash() and password_verify()
    if ($password === $user['sh_password']) {
        // Password is correct, return user data (without password)
        unset($user['sh_password']);
        
        // Map the database columns to frontend-friendly names
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
    
} catch (PDOException $e) {
    // Log the error (in production, log to a file, not output)
    error_log("Database error: " . $e->getMessage());
    
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database connection error. Please try again later.'
    ]);
} catch (Exception $e) {
    // Log the error
    error_log("General error: " . $e->getMessage());
    
    echo json_encode([
        'status' => 'error', 
        'message' => 'An error occurred. Please try again later.'
    ]);
}
?>
