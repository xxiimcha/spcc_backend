<?php
include 'cors_helper.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/activity_logger.php';

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
  }

  $data = read_json_body();
  if (empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty request body']);
    exit();
  }

  $prof_id = (int)($data['prof_id'] ?? 0);
  if ($prof_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing prof_id']);
    exit();
  }

  // Find linked user_id
  $res = mysqli_query($conn, "SELECT user_id FROM professors WHERE prof_id = $prof_id LIMIT 1");
  if (!$res || mysqli_num_rows($res) === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Professor not found']);
    exit();
  }
  $user_id = (int)mysqli_fetch_assoc($res)['user_id'];

  // Sanitize inputs
  $name     = mysqli_real_escape_string($conn, trim((string)($data['name'] ?? '')));
  $email    = mysqli_real_escape_string($conn, trim((string)($data['email'] ?? '')));
  $phone    = mysqli_real_escape_string($conn, trim((string)($data['phone'] ?? '')));
  $username = mysqli_real_escape_string($conn, trim((string)($data['username'] ?? '')));
  $password = mysqli_real_escape_string($conn, trim((string)($data['password'] ?? ''))); // plain text as requested

  // Build update sets
  $prof_sets = [];
  if ($name     !== '') $prof_sets[] = "prof_name = '$name'";
  if ($email    !== '') $prof_sets[] = "prof_email = '$email'";
  if ($phone    !== '') $prof_sets[] = "prof_phone = '$phone'";
  if ($username !== '') $prof_sets[] = "prof_username = '$username'";

  $user_sets = [];
  if ($name     !== '') $user_sets[] = "name = '$name'";
  if ($email    !== '') $user_sets[] = "email = '$email'";
  if ($username !== '') $user_sets[] = "username = '$username'";
  if ($password !== '') $user_sets[] = "password = '$password'";
  // keep roles/status untouched

  if (empty($prof_sets) && empty($user_sets)) {
    echo json_encode(['success' => false, 'message' => 'No changes detected']);
    exit();
  }

  mysqli_begin_transaction($conn);

  if (!empty($prof_sets)) {
    $sql_prof = "UPDATE professors SET " . implode(', ', $prof_sets) . " WHERE user_id = $prof_id";
    if (!mysqli_query($conn, $sql_prof)) {
      mysqli_rollback($conn);
      http_response_code(500);
      echo json_encode(['success' => false, 'message' => 'Update (professors) failed', 'error' => mysqli_error($conn)]);
      exit();
    }
  }

  if (!empty($user_sets)) {
    $sql_user = "UPDATE users SET " . implode(', ', $user_sets) . ", updated_at = NOW() WHERE user_id = $user_id";
    if (!mysqli_query($conn, $sql_user)) {
      mysqli_rollback($conn);
      http_response_code(500);
      echo json_encode(['success' => false, 'message' => 'Update (users) failed', 'error' => mysqli_error($conn)]);
      exit();
    }
  }

  mysqli_commit($conn);

  log_activity($conn, 'professors', 'update', "Updated profile for prof_id=$prof_id (user_id=$user_id)");

  echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
} catch (Throwable $e) {
  if ($conn && mysqli_errno($conn)) { @mysqli_rollback($conn); }
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
