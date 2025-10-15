<?php
ini_set('display_errors', 1); error_reporting(E_ALL);

// ---- CORS (unchanged) ----
$allowed = ['http://localhost:5174','http://127.0.0.1:5500','http://127.0.0.1:5501','http://localhost:3000','http://localhost:5173','https://spcc-web.vercel.app'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . (in_array($origin,$allowed) ? $origin : '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$identifier = trim((string)($input['username'] ?? ''));
$pass   = (string)($input['password'] ?? '');
if ($identifier === '' || $pass === '') { echo json_encode(['success'=>false,'message'=>'Username and password are required']); exit; }

// ---- DB ----
require_once __DIR__.'/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
  echo json_encode(['success'=>false,'message'=>'Database connection error','debug'=>['mysqli_error'=>$conn->connect_error ?? 'no mysqli']]); exit;
}
$conn->set_charset('utf8mb4');

// Show which DB we're actually on
$dbRow = $conn->query("SELECT DATABASE() AS db, @@hostname AS host, @@version AS ver")->fetch_assoc();

$sql = "SELECT sh_id, sh_name, sh_username, sh_email, sh_password
        FROM school_heads
        WHERE LOWER(sh_username) = LOWER(?) OR LOWER(sh_email) = LOWER(?)
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $identifier, $identifier);
$stmt->execute();
$res  = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();
$conn->close();

// No row found → probably not the same DB/table or username mismatch
if (!$user) {
  echo json_encode([
    'success'=>false,
    'message'=>'Invalid username or password',
    'debug'=>[
      'db_actually_used'=>$dbRow,
      'found_user'=>false,
      'identifier'=>$identifier
    ]
  ]);
  exit;
}

// Normalize DB password for diagnostics (don’t send plain password)
$dbPassRaw = (string)$user['sh_password'];

// helpers to remove NBSP and trailing CR/LF/spaces
$remove_nbsp = function(string $s){ return str_replace("\xC2\xA0", ' ', $s); };
$rt = function(string $s){ $s = preg_replace("/(\r\n|\n|\r)+$/", "", $s); return trim($s); };

$dbPassNorm = $rt($remove_nbsp($dbPassRaw));
$inPassNorm = $rt($remove_nbsp($pass));

$equalStrict   = hash_equals($dbPassRaw, $pass);
$equalTrimmed  = hash_equals($dbPassNorm, $inPassNorm);
$equalIcase    = (strcasecmp($dbPassNorm, $inPassNorm) === 0); // just for debug

if (!$equalStrict && !$equalTrimmed) {
  echo json_encode([
    'success'=>false,
    'message'=>'Invalid username or password',
    'debug'=>[
      'db_actually_used'=>$dbRow,
      'found_user'=>true,
      'user_row'=>[
        'id'=>$user['sh_id'],
        'username'=>$user['sh_username'],
        'email'=>$user['sh_email'],
      ],
      'compare'=>[
        'strict'=>$equalStrict,
        'trimmed'=>$equalTrimmed,
        'icase'=>$equalIcase
      ],
      'input'=>[
        'len'=>strlen($pass),
        'hex'=>bin2hex($pass)
      ],
      'db_password'=>[
        'len'=>strlen($dbPassRaw),
        'hex'=>bin2hex($dbPassRaw)
      ]
    ]
  ]);
  exit;
}

// Success
unset($user['sh_password']);
echo json_encode([
  'success'=>true,
  'message'=>'Login successful',
  'user'=>[
    'id'=>(int)$user['sh_id'],
    'name'=>$user['sh_name'],
    'username'=>$user['sh_username'],
    'email'=>$user['sh_email'],
    'role'=>'school_head'
  ],
  'debug'=>[
    'db_actually_used'=>$dbRow,
    'matched'=>[
      'strict'=>$equalStrict,
      'trimmed'=>$equalTrimmed
    ]
  ]
]);
