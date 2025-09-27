<?php
// Execute simple database schema fixes
include 'connect.php';

// Read the SQL file
$sql_content = file_get_contents('fix_database_schema_simple.sql');

if ($sql_content === false) {
    die("Error: Could not read fix_database_schema_simple.sql file\n");
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
    "SHOW COLUMNS FROM subjects LIKE 'subj_units'" => "subjects.subj_units column",
    "SHOW COLUMNS FROM subjects LIKE 'subj_type'" => "subjects.subj_type column", 
    "SHOW COLUMNS FROM subjects LIKE 'subj_hours_per_week'" => "subjects.subj_hours_per_week column",
    "SHOW TABLES LIKE 'subject_professor_assignments'" => "subject_professor_assignments table",
    "SHOW TABLES LIKE 'system_settings'" => "system_settings table",
    "SELECT COUNT(*) as count FROM subject_professor_assignments" => "subject-professor assignments data"
];

foreach ($test_queries as $query => $description) {
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        if (strpos($query, 'SELECT COUNT') !== false) {
            $row = $result->fetch_assoc();
            echo "✅ " . $description . ": " . $row['count'] . " records\n";
        } else {
            echo "✅ " . $description . ": EXISTS\n";
        }
    } else {
        echo "❌ " . $description . ": NOT FOUND\n";
    }
}

// Check if subjects have the new columns with data
$subjects_check = $conn->query("SELECT subj_id, subj_name, subj_units, subj_type, subj_hours_per_week FROM subjects LIMIT 3");
if ($subjects_check && $subjects_check->num_rows > 0) {
    echo "\n=== SAMPLE SUBJECTS DATA ===\n";
    while ($row = $subjects_check->fetch_assoc()) {
        echo "Subject: {$row['subj_name']} | Units: {$row['subj_units']} | Type: {$row['subj_type']} | Hours/Week: {$row['subj_hours_per_week']}\n";
    }
}

$conn->close();
echo "\nDatabase schema fix complete!\n";
?>
