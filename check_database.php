<?php
// Check database contents
header("Content-Type: text/html; charset=UTF-8");

require_once 'connect.php';

echo "<h1>📊 Database Contents Check</h1>";

try {
    // Check professors
    echo "<h2>👨‍🏫 Professors</h2>";
    $profQuery = "SELECT prof_id, prof_name, prof_email, subj_count FROM professors LIMIT 5";
    $profResult = $conn->query($profQuery);
    
    if ($profResult && $profResult->num_rows > 0) {
        echo "<p>✅ Found {$profResult->num_rows} professors</p>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Subject Count</th></tr>";
        while ($row = $profResult->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['prof_id']}</td>";
            echo "<td>{$row['prof_name']}</td>";
            echo "<td>{$row['prof_email']}</td>";
            echo "<td>{$row['subj_count']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>❌ No professors found in database</p>";
    }
    
    // Check sections
    echo "<h2>🏫 Sections</h2>";
    $secQuery = "SELECT section_id, section_name, grade_level, strand FROM sections LIMIT 5";
    $secResult = $conn->query($secQuery);
    
    if ($secResult && $secResult->num_rows > 0) {
        echo "<p>✅ Found {$secResult->num_rows} sections</p>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>Name</th><th>Grade Level</th><th>Strand</th></tr>";
        while ($row = $secResult->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['section_id']}</td>";
            echo "<td>{$row['section_name']}</td>";
            echo "<td>{$row['grade_level']}</td>";
            echo "<td>{$row['strand']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>❌ No sections found in database</p>";
    }
    
    // Check schedules
    echo "<h2>📅 Schedules</h2>";
    $schQuery = "SELECT schedule_id, school_year, semester, start_time, end_time FROM schedules LIMIT 5";
    $schResult = $conn->query($schQuery);
    
    if ($schResult && $schResult->num_rows > 0) {
        echo "<p>✅ Found {$schResult->num_rows} schedules</p>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>School Year</th><th>Semester</th><th>Start Time</th><th>End Time</th></tr>";
        while ($row = $schResult->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['schedule_id']}</td>";
            echo "<td>{$row['school_year']}</td>";
            echo "<td>{$row['semester']}</td>";
            echo "<td>{$row['start_time']}</td>";
            echo "<td>{$row['end_time']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>❌ No schedules found in database</p>";
    }
    
    // Summary
    $totalProf = $conn->query("SELECT COUNT(*) as count FROM professors")->fetch_assoc()['count'];
    $totalSec = $conn->query("SELECT COUNT(*) as count FROM sections")->fetch_assoc()['count'];
    $totalSch = $conn->query("SELECT COUNT(*) as count FROM schedules")->fetch_assoc()['count'];
    
    echo "<h2>📋 Summary</h2>";
    echo "<p><strong>Total Professors:</strong> {$totalProf}</p>";
    echo "<p><strong>Total Sections:</strong> {$totalSec}</p>";
    echo "<p><strong>Total Schedules:</strong> {$totalSch}</p>";
    
    if ($totalProf > 0 || $totalSec > 0 || $totalSch > 0) {
        echo "<p>✅ Database has data to sync!</p>";
        echo "<p><a href='debug_firebase.php'>🔍 Run Firebase Debug Test</a></p>";
        echo "<p><a href='auto_sync.php'>🚀 Try Auto Sync</a></p>";
    } else {
        echo "<p>❌ Database is empty. You need to add some data first.</p>";
        echo "<p>Add some professors, sections, and schedules through your application first.</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}
?> 