<?php
// Add CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = "localhost";
$user = "u341538466_spcc";
$password = "9g1k~M|;D";
$database = "u341538466_spcc";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    // Only output JSON if this file is accessed directly, not when included
    if (basename($_SERVER['PHP_SELF']) === 'connect.php') {
        echo json_encode(["error" => "Database connection failed: " . $conn->connect_error]);
    }
    exit("Database connection failed: " . $conn->connect_error);
} else {
    // Only output JSON if this file is accessed directly
    if (basename($_SERVER['PHP_SELF']) === 'connect.php') {
        echo json_encode(["success" => "Database connection successful"]);
    }
}
?>