<?php
// Execute database schema fixes
include 'connect.php';

// Read the SQL file
$sql_content = file_get_contents('fix_database_schema.sql');

if ($sql_content === false) {
    die("Error: Could not read fix_database_schema.sql file\n");
}

// Split SQL into individual statements
$statements = array_filter(
    array_map('trim', explode(';', $sql_content)), 
    function($stmt) {
        return !empty($stmt) && 
               !preg_match('/^\s*--/', $stmt) && 
               !preg_match('/^\s*\/\*/', $stmt) &&
               !preg_match('/^\s*USE\s+/i', $stmt);
    }
);

$success_count = 0;
$error_count = 0;

echo "Starting database schema fixes...\n\n";

foreach ($statements as $index => $statement) {
    $statement = trim($statement);
    if (empty($statement)) continue;
    
    echo "Executing statement " . ($index + 1) . "...\n";
    echo substr($statement, 0, 100) . (strlen($statement) > 100 ? '...' : '') . "\n";
    
    if ($conn->query($statement) === TRUE) {
        echo "✅ SUCCESS\n\n";
        $success_count++;
    } else {
        echo "❌ ERROR: " . $conn->error . "\n\n";
        $error_count++;
        
        // Continue with other statements even if one fails
    }
}

echo "=== SUMMARY ===\n";
echo "✅ Successful statements: $success_count\n";
echo "❌ Failed statements: $error_count\n";
echo "Total statements processed: " . ($success_count + $error_count) . "\n\n";

if ($error_count === 0) {
    echo "🎉 All database schema fixes applied successfully!\n";
} else {
    echo "⚠️  Some statements failed, but the database should still be functional.\n";
}

// Test the fixes by checking if new columns exist
echo "\n=== VERIFICATION ===\n";
$test_queries = [
    "SHOW COLUMNS FROM subjects LIKE 'subj_units'",
    "SHOW COLUMNS FROM subjects LIKE 'subj_type'", 
    "SHOW COLUMNS FROM subjects LIKE 'subj_hours_per_week'",
    "SHOW TABLES LIKE 'subject_professor_assignments'",
    "SHOW TABLES LIKE 'system_settings'"
];

foreach ($test_queries as $query) {
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        echo "✅ " . $query . "\n";
    } else {
        echo "❌ " . $query . "\n";
    }
}

$conn->close();
echo "\nDatabase schema fix complete!\n";
?>
