<?php
// Simple test runner to simulate POST request
$url = "https://spcc-scheduler.site/professor_subject_preferences.php"; // adjust to your own domain/path

// sample payload
$payload = [
  "user_id" => 4, // or prof_id — replace with an actual ID in your database
  "preferences" => [
    ["subj_id" => 36, "proficiency" => "advanced", "willingness" => "willing"],
    ["subj_id" => 42, "proficiency" => "intermediate", "willingness" => "willing"]
  ]
];

$options = [
  CURLOPT_URL => $url,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($payload),
];

$ch = curl_init();
curl_setopt_array($ch, $options);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h3>HTTP $httpCode Response</h3>";
echo "<pre>";
echo htmlspecialchars($response);
echo "</pre>";
