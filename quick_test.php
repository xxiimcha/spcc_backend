<?php
// Quick test to verify Firebase sync
header("Content-Type: text/html; charset=UTF-8");

require_once 'connect.php';
require_once 'firebase_config.php';

echo "<h1>⚡ Quick Firebase Test</h1>";

try {
    // Test 1: Simple write to Firebase
    echo "<h2>Test 1: Writing to Firebase</h2>";
    $testData = [
        'message' => 'Hello from SPCC Database!',
        'timestamp' => date('Y-m-d H:i:s'),
        'test' => true
    ];
    
    $response = $firebaseConfig->setData('test', $testData);
    echo "<p>Status: " . $response['status'] . "</p>";
    echo "<p>Response: " . json_encode($response['data']) . "</p>";
    
    if ($response['status'] === 200) {
        echo "<p style='color: green;'>✅ Successfully wrote to Firebase!</p>";
    } else {
        echo "<p style='color: red;'>❌ Failed to write to Firebase</p>";
    }
    
    // Test 2: Read from Firebase
    echo "<h2>Test 2: Reading from Firebase</h2>";
    $readResponse = $firebaseConfig->getData('test');
    echo "<p>Status: " . $readResponse['status'] . "</p>";
    echo "<p>Data: " . json_encode($readResponse['data']) . "</p>";
    
    if ($readResponse['status'] === 200) {
        echo "<p style='color: green;'>✅ Successfully read from Firebase!</p>";
    } else {
        echo "<p style='color: red;'>❌ Failed to read from Firebase</p>";
    }
    
    // Test 3: Check database data
    echo "<h2>Test 3: Database Data Check</h2>";
    $profCount = $conn->query("SELECT COUNT(*) as count FROM professors")->fetch_assoc()['count'];
    $secCount = $conn->query("SELECT COUNT(*) as count FROM sections")->fetch_assoc()['count'];
    $schCount = $conn->query("SELECT COUNT(*) as count FROM schedules")->fetch_assoc()['count'];
    
    echo "<p>Professors: {$profCount}</p>";
    echo "<p>Sections: {$secCount}</p>";
    echo "<p>Schedules: {$schCount}</p>";
    
    if ($profCount > 0 || $secCount > 0 || $schCount > 0) {
        echo "<p style='color: green;'>✅ Database has data to sync</p>";
        
        // Test 4: Try syncing one professor
        if ($profCount > 0) {
            echo "<h2>Test 4: Syncing One Professor</h2>";
            $profQuery = "SELECT prof_id, prof_name, prof_email, subj_count FROM professors LIMIT 1";
            $profResult = $conn->query($profQuery);
            $professor = $profResult->fetch_assoc();
            
            $profData = [
                'id' => $professor['prof_id'],
                'name' => $professor['prof_name'],
                'email' => $professor['prof_email'],
                'subject_count' => $professor['subj_count']
            ];
            
            $profResponse = $firebaseConfig->setData('test_professor', $profData);
            echo "<p>Status: " . $profResponse['status'] . "</p>";
            
            if ($profResponse['status'] === 200) {
                echo "<p style='color: green;'>✅ Successfully synced professor to Firebase!</p>";
            } else {
                echo "<p style='color: red;'>❌ Failed to sync professor</p>";
            }
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Database is empty - add some data first</p>";
    }
    
    echo "<h2>🎯 Next Steps</h2>";
    if ($response['status'] === 200) {
        echo "<p style='color: green;'>✅ Firebase is working! Now try the full sync:</p>";
        echo "<p><a href='auto_sync.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🚀 Run Full Sync</a></p>";
    } else {
        echo "<p style='color: red;'>❌ Firebase connection failed. Check your rules and try again.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?> 