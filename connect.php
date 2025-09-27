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
$user = "root"; // Default XAMPP user
$password = ""; // Default XAMPP password (empty)
$database = "spcc_scheduling_system";

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