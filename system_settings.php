<?php
// system_settings.php — returns current settings as JSON (CORS + no prepared stmts)

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

include 'connect.php';
require_once __DIR__ . '/system_settings_helper.php';

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"DB connection failed: ".$conn->connect_error]);
  exit();
}

// read current values via helper
$sy = ss_get_setting($conn, 'current_school_year');
$sem = ss_get_setting($conn, 'current_semester'); // add this key in your table if missing

// allow optional ?keys=current_school_year,current_semester to fetch arbitrary keys
if (isset($_GET['keys'])) {
  $keys = array_values(array_filter(array_map('trim', explode(',', $_GET['keys'])), fn($k)=>$k!==''));
  $data = ss_get_settings($conn, $keys);
  echo json_encode(["status"=>"success","data"=>$data]);
  $conn->close();
  exit();
}

echo json_encode([
  "status" => "success",
  "data" => [
    "current_school_year" => $sy,
    "current_semester"    => $sem
  ]
]);

$conn->close();
