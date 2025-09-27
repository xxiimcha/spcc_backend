<?php
include 'connect.php';

$result = $conn->query("SELECT * FROM users");
$users = [];

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

echo json_encode($users);
?>