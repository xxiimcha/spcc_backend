<?php
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

session_start(); // Start PHP session

include "connect.php"; // Your DB connection file

// Get input data
$data = json_decode(file_get_contents("php://input"), true);

// Check if username and password are set
if (!isset($data["username"]) || !isset($data["password"])) {
    echo json_encode(["error" => "Missing username or password"]);
    exit;
}

$username = $data["username"];
$password = $data["password"];

// Prepare and execute query to check if user exists
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 1) { // Only one user should match the username
    $user = $result->fetch_assoc();

    // Compare the input password with the stored plain text password
    if ($password === $user["password"]) {
        // Password is correct, start a session and set session variables
        $_SESSION["user_id"] = $user["id"];
        $_SESSION["username"] = $user["username"];
        $_SESSION["position"] = $user["position"];
        $_SESSION["firstName"] = $user["firstName"];
        $_SESSION["lastName"] = $user["lastName"];

        // Send a successful response with 'authenticated' status
        echo json_encode([
            "authenticated" => true,
            "user" => [
                "id" => $_SESSION["user_id"],
                "username" => $_SESSION["username"],
                "position" => $_SESSION["position"],
                "firstName" => $_SESSION["firstName"],
                "lastName" => $_SESSION["lastName"],
            ]
        ]);
    } else {
        // If password doesn't match
        echo json_encode(["authenticated" => false, "error" => "Invalid password"]);
    }
} else {
    // If no user found with the given username
    echo json_encode(["authenticated" => false, "error" => "User not found"]);
}

$stmt->close();
$conn->close();
?>