<?php
include 'connect.php';
include 'system_settings_helper.php';

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die("DB error: " . $conn->connect_error);
}

$value = ss_get_setting($conn, 'current_school_year');
echo "Current School Year: " . var_export($value, true) . "<br>";

$multiple = ss_get_settings($conn, ['current_school_year', 'school_name', 'portal_url']);
echo "<pre>";
print_r($multiple);
echo "</pre>";

$sy = ss_get_current_school_year($conn);
echo "Helper function school year: " . ($sy ?? 'NULL') . "<br>";

$conn->close();
