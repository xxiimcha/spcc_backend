<?php
header("Access-Control-Allow-Origin: *"); // Allow React to access API
header("Access-Control-Allow-Methods: GET, POST");
header("Content-Type: application/json");

include "connect.php";

$sql = "SELECT * FROM users";
$result = $conn->query($sql);

$users = [];

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

echo json_encode($users);
?>