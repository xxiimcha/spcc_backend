<?phpss
include 'cors_helper.php';

require_once __DIR__ . '/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function jout(array $data): void { echo json_encode($data, JSON_UNESCAPED_UNICODE); }

function body_json(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw ?: '[]', true);
  return is_array($j) ? $j : [];
}

function looks_hashed(string $s): bool {
  return strlen($s) >= 30
      || str_starts_with($s, '$2y$')
      || str_starts_with($s, '$argon2');
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

  $stored = (string)$user['password'];
  $valid = looks_hashed($stored) ? password_verify($password, $stored) : hash_equals($stored, $password);

  if (!$valid) {
    jout(['success' => false, 'error' => 'Invalid credentials.']);
    exit();
  }

  $status = strtolower(trim((string)($user['status'] ?? '')));
  if ($status !== 'active') {
    http_response_code(403);
    $msg = match ($status) {
      'pending', 'pending_verification' => 'Account pending verification.',
      'suspended' => 'Account is suspended.',
      'disabled' => 'Account is disabled.',
      'blocked', 'banned' => 'Account is blocked.',
      'inactive' => 'Account is inactive.',
      default => 'Account not allowed to sign in.',
    };
    jout([
      'success' => false,
      'error' => $msg,
      'status' => $status ?: null,
      'code' => 'account_status_denied'
    ]);
    exit();
  }

  $userId = (int)$user['user_id'];
  $role   = (string)$user['role'];
  $profile = null;
  $bestName = null;

  if ($role === 'admin' || $role === 'super_admin') {
    $s = $conn->prepare("SELECT admin_id, full_name, contact_number, department FROM admins WHERE user_id = ? LIMIT 1");
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
    $s = $conn->prepare("SELECT sh_id, sh_name, sh_email FROM school_heads WHERE user_id = ? LIMIT 1");
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
        'extra'        => new stdClass(),
      ];
      $bestName = $row['sh_name'] ?? null;
    }
  } elseif ($role === 'professor') {
    $s = $conn->prepare("SELECT prof_id, prof_name, prof_email, subj_count, school_year FROM professors WHERE user_id = ? LIMIT 1");
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

  $token = null;

  $payload = [
    'success' => true,
    'user' => [
      'id'       => $userId,
      'username' => $user['username'],
      'role'     => $role,
      'email'    => $user['email'],
      'name'     => $bestName,
      'status'   => $status,
      'token'    => $token,
      'profile'  => $profile,
    ],
  ];

  jout($payload);
} catch (Throwable $e) {
  http_response_code(500);
  jout(['success' => false, 'error' => 'Auth error: '.$e->getMessage()]);
}
