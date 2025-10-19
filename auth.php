<?php
// auth.php — Unified authentication endpoint
// Uses `users` for login and attaches profile details from role tables.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // tighten in production
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit();
}

require_once __DIR__ . '/connect.php'; // must define $conn (mysqli)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function jout(array $data): void {
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function body_json(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw ?: '[]', true);
  return is_array($j) ? $j : [];
}

function looks_hashed(string $s): bool {
  return strlen($s) >= 30
      || str_starts_with($s, '$2y$')      // bcrypt
      || str_starts_with($s, '$argon2');  // argon2
}

try {
  if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new Exception('Database connection not available.');
  }

  $in = body_json();
  $username = trim((string)($in['username'] ?? ''));
  $password = (string)($in['password'] ?? '');

  if ($username === '' || $password === '') {
    http_response_code(400);
    jout(['success' => false, 'error' => 'Username and password are required.']);
    exit();
  }

  // 1) Look up in users by username OR email
  $sql = "SELECT user_id, username, email, password, role, status
          FROM users
          WHERE username = ? OR email = ?
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ss', $username, $username);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user) {
    jout(['success' => false, 'error' => 'Invalid credentials.']);
    exit();
  }

  if (isset($user['status']) && $user['status'] !== 'active') {
    jout(['success' => false, 'error' => 'Account is inactive.']);
    exit();
  }

  // 2) Password verify (hashed or legacy plain)
  $stored = (string)$user['password'];
  $valid = looks_hashed($stored) ? password_verify($password, $stored) : hash_equals($stored, $password);

  if (!$valid) {
    jout(['success' => false, 'error' => 'Invalid credentials.']);
    exit();
  }

  $userId = (int)$user['user_id'];
  $role   = (string)$user['role'];

  // 3) Fetch role-specific profile using user_id
  $profile = null;
  $bestName = null;

  if ($role === 'admin' || $role === 'super_admin') {
    // admins table: admin_id, user_id, full_name, contact_number, department, ...
    $s = $conn->prepare("SELECT admin_id, full_name, contact_number, department
                         FROM admins
                         WHERE user_id = ?
                         LIMIT 1");
    $s->bind_param('i', $userId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();

    if ($row) {
      $profile = [
        'profile_type' => 'admin',
        'profile_id'   => (string)$row['admin_id'],
        'name'         => $row['full_name'] ?? null,
        'email'        => $user['email'] ?? null,
        'extra'        => [
          'contact_number' => $row['contact_number'] ?? null,
          'department'     => $row['department'] ?? null,
        ],
      ];
      $bestName = $row['full_name'] ?? null;
    }
  } elseif ($role === 'acad_head') {
    // school_heads table: sh_id, user_id, sh_name, sh_email, ...
    $s = $conn->prepare("SELECT sh_id, sh_name, sh_email
                         FROM school_heads
                         WHERE user_id = ?
                         LIMIT 1");
    $s->bind_param('i', $userId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();

    if ($row) {
      $profile = [
        'profile_type' => 'acad_head',
        'profile_id'   => (string)$row['sh_id'],
        'name'         => $row['sh_name'] ?? null,
        'email'        => $row['sh_email'] ?? $user['email'] ?? null,
        'extra'        => new stdClass(), // placeholder
      ];
      $bestName = $row['sh_name'] ?? null;
    }
  } elseif ($role === 'professor') {
    // professors table: prof_id, user_id, prof_name, prof_email, subj_count, school_year, ...
    $s = $conn->prepare("SELECT prof_id, prof_name, prof_email, subj_count, school_year
                         FROM professors
                         WHERE user_id = ?
                         LIMIT 1");
    $s->bind_param('i', $userId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();

    if ($row) {
      $profile = [
        'profile_type' => 'professor',
        'profile_id'   => (string)$row['prof_id'],
        'name'         => $row['prof_name'] ?? null,
        'email'        => $row['prof_email'] ?? $user['email'] ?? null,
        'extra'        => [
          'school_year' => $row['school_year'] ?? null,
          'subj_count'  => isset($row['subj_count']) ? (int)$row['subj_count'] : null,
        ],
      ];
      $bestName = $row['prof_name'] ?? null;
    }
  }

  // Optional: issue a simple token (replace with JWT if you want)
  $token = null; // e.g., bin2hex(random_bytes(16));

  // 4) Respond
  $payload = [
    'success' => true,
    'user' => [
      'id'       => $userId,
      'username' => $user['username'],
      'role'     => $role,
      'email'    => $user['email'],
      'name'     => $bestName, // prefer profile name if present
      'token'    => $token,
      'profile'  => $profile,
    ],
  ];

  jout($payload);
} catch (Throwable $e) {
  http_response_code(500);
  jout(['success' => false, 'error' => 'Auth error: '.$e->getMessage()]);
}
