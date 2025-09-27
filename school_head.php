<?php
// school_head.php - API endpoint for school head management and login

// Enable CORS
header("Access-Control-Allow-Origin: *"); // Adjust this URL if needed
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS"); // Add PUT and DELETE
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

include 'connect.php';

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit();
}

// Handle different HTTP methods
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get all school heads or a specific school head
        if (isset($_GET['id'])) {
            getSchoolHead($conn, $_GET['id']);
        } else {
            getAllSchoolHeads($conn);
        }
        break;
    
    case 'POST':
        // Check if this is a login request or create request
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        
        // If action is login, handle login; otherwise create new school head
        if (isset($data['action']) && $data['action'] === 'login') {
            loginSchoolHead($conn, $data);
        } else {
            createSchoolHead($conn, $data);
        }
        break;
    
    case 'PUT':
        // Update an existing school head
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "School Head ID is required"]);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
            exit();
        }
        
        updateSchoolHead($conn, $_GET['id'], $data);
        break;
    
    case 'DELETE':
        // Delete a school head
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "School Head ID is required"]);
            exit();
        }
        
        deleteSchoolHead($conn, $_GET['id']);
        break;
    
    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        break;
}

// Function to get all school heads
function getAllSchoolHeads($conn) {
    $sql = "SELECT * FROM school_heads";
    $result = $conn->query($sql);
    
    if ($result) {
        $schoolHeads = [];
        while ($row = $result->fetch_assoc()) {
            $schoolHead = [
                'id' => $row['sh_id'],
                'name' => $row['sh_name'],
                'email' => $row['sh_email'],
                'username' => $row['sh_username'],
                'password' => $row['sh_password']
            ];
            
            $schoolHeads[] = $schoolHead;
        }
        
        // Wrap the response in a data property to match frontend expectations
        echo json_encode(["status" => "success", "data" => $schoolHeads]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to fetch school heads"]);
    }
}

// Function to get a specific school head
function getSchoolHead($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM school_heads WHERE sh_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $row = $result->fetch_assoc()) {
        $schoolHead = [
            'id' => $row['sh_id'],
            'name' => $row['sh_name'],
            'email' => $row['sh_email'],
            'username' => $row['sh_username'],
            'password' => $row['sh_password']
        ];
        
        // Wrap the response in a data property to match frontend expectations
        echo json_encode(["status" => "success", "data" => $schoolHead]);
    } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "School Head not found"]);
    }
    
    $stmt->close();
}

// Function to handle school head login
function loginSchoolHead($conn, $data) {
    // Validate required fields for login
    $requiredFields = ['username', 'password'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
            exit();
        }
    }
    
    $username = $data['username'];
    $password = $data['password'];
    
    // Check if school head exists with given credentials
    $stmt = $conn->prepare("SELECT * FROM school_heads WHERE sh_username = ? AND sh_password = ?");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $row = $result->fetch_assoc()) {
        // Login successful
        $schoolHead = [
            'id' => $row['sh_id'],
            'name' => $row['sh_name'],
            'email' => $row['sh_email'],
            'username' => $row['sh_username'],
            'password' => $row['sh_password']
        ];
        
        echo json_encode([
            "status" => "success", 
            "message" => "Login successful",
            "data" => $schoolHead
        ]);
    } else {
        // Login failed
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid username or password"]);
    }
    
    $stmt->close();
}

// Function to create a new school head
function createSchoolHead($conn, $data) {
    // Validate required fields
    $requiredFields = ['name', 'username', 'password'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
            exit();
        }
    }
    
    // Prepare values
    $name = trim($data['name']);
    $username = trim($data['username']);
    $password = trim($data['password']);
    $email = isset($data['email']) && !empty(trim($data['email'])) ? trim($data['email']) : null;
    
    // Check if school head with same username already exists
    $checkStmt = $conn->prepare("SELECT sh_id FROM school_heads WHERE sh_username = ?");
    $checkStmt->bind_param("s", $username);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "School Head with this username already exists"]);
        $checkStmt->close();
        exit();
    }
    $checkStmt->close();
    
    // Check if school head with same name already exists
    $checkNameStmt = $conn->prepare("SELECT sh_id FROM school_heads WHERE sh_name = ?");
    $checkNameStmt->bind_param("s", $name);
    $checkNameStmt->execute();
    $checkNameResult = $checkNameStmt->get_result();
    
    if ($checkNameResult->num_rows > 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "School Head with this name already exists"]);
        $checkNameStmt->close();
        exit();
    }
    $checkNameStmt->close();
    
    // Prepare the SQL statement
    $sql = "INSERT INTO school_heads (sh_name, sh_username, sh_password, sh_email) VALUES (?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to prepare SQL statement"]);
        exit();
    }
    
    $stmt->bind_param("ssss", $name, $username, $password, $email);
    
    if ($stmt->execute()) {
        $id = $conn->insert_id;
        $schoolHead = [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'password' => $password
        ];
        
        // Simple success response without Firebase sync
        http_response_code(201);
        echo json_encode([
            "status" => "success", 
            "message" => "School Head added successfully", 
            "id" => $id,
            "data" => $schoolHead
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
    }
    
    $stmt->close();
}

// Function to update a school head
function updateSchoolHead($conn, $id, $data) {
    // Check if school head exists
    $checkStmt = $conn->prepare("SELECT sh_id FROM school_heads WHERE sh_id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "School Head not found"]);
        $checkStmt->close();
        exit();
    }
    $checkStmt->close();
    
    // Prepare values
    $name = isset($data['name']) && !empty(trim($data['name'])) ? trim($data['name']) : null;
    $username = isset($data['username']) && !empty(trim($data['username'])) ? trim($data['username']) : null;
    $email = isset($data['email']) && !empty(trim($data['email'])) ? trim($data['email']) : null;
    $password = isset($data['password']) && !empty(trim($data['password'])) ? trim($data['password']) : null;
    
    // Check for duplicate username if username is being updated
    if ($username !== null) {
        $checkUsernameStmt = $conn->prepare("SELECT sh_id FROM school_heads WHERE sh_username = ? AND sh_id != ?");
        $checkUsernameStmt->bind_param("si", $username, $id);
        $checkUsernameStmt->execute();
        $checkUsernameResult = $checkUsernameStmt->get_result();
        
        if ($checkUsernameResult->num_rows > 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "School Head with this username already exists"]);
            $checkUsernameStmt->close();
            exit();
        }
        $checkUsernameStmt->close();
    }
    
    // Check for duplicate name if name is being updated
    if ($name !== null) {
        $checkNameStmt = $conn->prepare("SELECT sh_id FROM school_heads WHERE sh_name = ? AND sh_id != ?");
        $checkNameStmt->bind_param("si", $name, $id);
        $checkNameStmt->execute();
        $checkNameResult = $checkNameStmt->get_result();
        
        if ($checkNameResult->num_rows > 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "School Head with this name already exists"]);
            $checkNameStmt->close();
            exit();
        }
        $checkNameStmt->close();
    }
    
    // Build dynamic SQL based on provided fields
    $sql = "UPDATE school_heads SET ";
    $params = [];
    $types = "";
    
    if ($name !== null) {
        $sql .= "sh_name = ?, ";
        $params[] = $name;
        $types .= "s";
    }
    
    if ($username !== null) {
        $sql .= "sh_username = ?, ";
        $params[] = $username;
        $types .= "s";
    }
    
    if ($email !== null) {
        $sql .= "sh_email = ?, ";
        $params[] = $email;
        $types .= "s";
    }
    
    if ($password !== null) {
        $sql .= "sh_password = ?, ";
        $params[] = $password;
        $types .= "s";
    }
    
    // Remove trailing comma and space
    $sql = rtrim($sql, ", ");
    $sql .= " WHERE sh_id = ?";
    
    // Add ID parameter
    $params[] = $id;
    $types .= "i";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to prepare SQL statement"]);
        exit();
    }
    
    // Bind parameters dynamically
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        // Get the updated school head data
        $updatedSchoolHead = [
            'id' => $id,
            'name' => $name !== null ? $name : '',
            'username' => $username !== null ? $username : '',
            'email' => $email !== null ? $email : '',
            'password' => $password !== null ? $password : ''
        ];
        
        echo json_encode([
            "status" => "success", 
            "message" => "School Head updated successfully",
            "data" => $updatedSchoolHead
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
    }
    
    $stmt->close();
}

// Function to delete a school head
function deleteSchoolHead($conn, $id) {
    // Check if school head exists
    $checkStmt = $conn->prepare("SELECT sh_id FROM school_heads WHERE sh_id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "School Head not found"]);
        $checkStmt->close();
        exit();
    }
    $checkStmt->close();
    
    // Prepare the SQL statement
    $sql = "DELETE FROM school_heads WHERE sh_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success", 
            "message" => "School Head deleted successfully",
            "data" => ["id" => $id]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
    }
    
    $stmt->close();
}

// Close the database connection
$conn->close();
?>
