<?php
// Handle CORS for multiple origins
$allowed_origins = [
    'http://localhost:5174',
    'http://127.0.0.1:5500',
    'http://127.0.0.1:5501',
    'http://localhost:3000',
    'http://localhost:5173'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Database connection
    $host = "localhost";
    $user = "root";
    $password = "";
    $database = "spcc_scheduling_system";

    $conn = new mysqli($host, $user, $password, $database);

    if ($conn->connect_error) {
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed',
            'error' => $conn->connect_error
        ]);
        exit;
    }

    $tables = ['schedules', 'rooms', 'subjects', 'professors', 'sections'];
    $table_structures = [];

    foreach ($tables as $table) {
        $result = $conn->query("DESCRIBE $table");
        if ($result) {
            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[] = [
                    'Field' => $row['Field'],
                    'Type' => $row['Type'],
                    'Null' => $row['Null'],
                    'Key' => $row['Key'],
                    'Default' => $row['Default'],
                    'Extra' => $row['Extra']
                ];
            }
            $table_structures[$table] = $columns;
        } else {
            $table_structures[$table] = 'Table does not exist';
        }
    }

    // Also check what tables exist
    $existing_tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $existing_tables[] = $row[0];
    }

    echo json_encode([
        'success' => true,
        'message' => 'Table structures retrieved',
        'database' => $database,
        'existing_tables' => $existing_tables,
        'table_structures' => $table_structures
    ]);

    $conn->close();

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Exception occurred',
        'error' => $e->getMessage()
    ]);
}
?>
