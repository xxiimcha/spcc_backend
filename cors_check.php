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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Return origin information
echo json_encode([
    'success' => true,
    'message' => 'CORS check successful',
    'request_origin' => $origin,
    'allowed_origins' => $allowed_origins,
    'is_allowed' => in_array($origin, $allowed_origins),
    'server_info' => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]
]);
?>
