<?php
// Detailed debug script to identify Firebase sync issues
header("Content-Type: text/html; charset=UTF-8");

require_once 'connect.php';
require_once 'firebase_config.php';

echo "<h1>🔍 Detailed Firebase Debug</h1>";

try {
    // Test 1: Check Firebase configuration
    echo "<h2>Test 1: Firebase Configuration</h2>";
    echo "<p>Database URL: " . $firebaseConfig->getDatabaseUrl() . "</p>";
    echo "<p>API Key: " . substr($firebaseConfig->getApiKey(), 0, 10) . "...</p>";
    
    // Test 2: Test basic HTTP connection
    echo "<h2>Test 2: HTTP Connection Test</h2>";
    $testUrl = $firebaseConfig->getDatabaseUrl() . '/test.json';
    echo "<p>Testing URL: " . $testUrl . "</p>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $testUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    echo "<p>HTTP Status Code: " . $httpCode . "</p>";
    echo "<p>cURL Error: " . ($curlError ? $curlError : 'None') . "</p>";
    echo "<p>Response: " . substr($response, 0, 200) . "...</p>";
    
    if ($httpCode === 200) {
        echo "<p style='color: green;'>✅ HTTP connection successful</p>";
    } else {
        echo "<p style='color: red;'>❌ HTTP connection failed</p>";
    }
    
    // Test 3: Test writing with detailed error info
    echo "<h2>Test 3: Detailed Write Test</h2>";
    $testData = [
        'test' => 'Hello Firebase',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    $writeUrl = $firebaseConfig->getDatabaseUrl() . '/debug_test.json';
    echo "<p>Write URL: " . $writeUrl . "</p>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $writeUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen(json_encode($testData))
    ]);
    
    $writeResponse = curl_exec($ch);
    $writeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $writeCurlError = curl_error($ch);
    curl_close($ch);
    
    echo "<p>Write HTTP Status: " . $writeHttpCode . "</p>";
    echo "<p>Write cURL Error: " . ($writeCurlError ? $writeCurlError : 'None') . "</p>";
    echo "<p>Write Response: " . $writeResponse . "</p>";
    
    if ($writeHttpCode === 200) {
        echo "<p style='color: green;'>✅ Write test successful</p>";
    } else {
        echo "<p style='color: red;'>❌ Write test failed</p>";
        
        // Check if it's a rules issue
        if ($writeHttpCode === 403) {
            echo "<p style='color: red;'>🔒 This looks like a Firebase rules issue!</p>";
            echo "<p>Make sure your Firebase rules allow write access:</p>";
            echo "<pre>{
  \"rules\": {
    \".read\": true,
    \".write\": true
  }
}</pre>";
        }
    }
    
    // Test 4: Check database connection
    echo "<h2>Test 4: Database Connection</h2>";
    if ($conn->ping()) {
        echo "<p style='color: green;'>✅ Database connection successful</p>";
    } else {
        echo "<p style='color: red;'>❌ Database connection failed</p>";
    }
    
    // Test 5: Check if we have data
    echo "<h2>Test 5: Data Availability</h2>";
    $profCount = $conn->query("SELECT COUNT(*) as count FROM professors")->fetch_assoc()['count'];
    $secCount = $conn->query("SELECT COUNT(*) as count FROM sections")->fetch_assoc()['count'];
    $schCount = $conn->query("SELECT COUNT(*) as count FROM schedules")->fetch_assoc()['count'];
    
    echo "<p>Professors: {$profCount}</p>";
    echo "<p>Sections: {$secCount}</p>";
    echo "<p>Schedules: {$schCount}</p>";
    
    if ($profCount > 0 || $secCount > 0 || $schCount > 0) {
        echo "<p style='color: green;'>✅ Database has data to sync</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Database is empty</p>";
    }
    
    // Test 6: Test the actual sync function
    echo "<h2>Test 6: Sync Function Test</h2>";
    if ($profCount > 0) {
        require_once 'firebase_sync.php';
        $sync = new FirebaseSync($firebaseConfig, $conn);
        
        echo "<p>Testing professor sync...</p>";
        $profResult = $sync->syncProfessors();
        echo "<p>Professor Sync Result: " . json_encode($profResult) . "</p>";
        
        if ($profResult['success']) {
            echo "<p style='color: green;'>✅ Professor sync successful!</p>";
        } else {
            echo "<p style='color: red;'>❌ Professor sync failed: " . $profResult['message'] . "</p>";
        }
    }
    
    echo "<h2>🔧 Troubleshooting Steps</h2>";
    echo "<ol>";
    echo "<li>If HTTP Status is not 200: Check your Firebase URL</li>";
    echo "<li>If Write Status is 403: Update your Firebase rules</li>";
    echo "<li>If Database is empty: Add some data first</li>";
    echo "<li>If cURL errors: Check your internet connection</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<p>Stack trace: " . $e->getTraceAsString() . "</p>";
}
?> 