<?php
// Auto sync script - syncs all data to Firebase automatically
header("Content-Type: text/html; charset=UTF-8");

require_once 'connect.php';
require_once 'firebase_config.php';
require_once 'firebase_sync.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto Sync to Firebase</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .result {
            margin: 10px 0;
            padding: 15px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
        }
        .success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .info {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .summary {
            background-color: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
        }
        .firebase-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #ff6b35;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }
        .firebase-link:hover {
            background-color: #e55a2b;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Auto Sync to Firebase</h1>
        
        <?php
        try {
            $sync = new FirebaseSync($firebaseConfig, $conn);
            $results = [];
            
            echo "<h2>📊 Syncing Data...</h2>";
            
            // Sync Professors
            echo "<h3>👨‍🏫 Syncing Professors...</h3>";
            $profResult = $sync->syncProfessors();
            $results['professors'] = $profResult;
            
            if ($profResult['success']) {
                echo "<div class='result success'>✅ Professors synced successfully! Count: {$profResult['count']}</div>";
            } else {
                echo "<div class='result error'>❌ Failed to sync professors: {$profResult['message']}</div>";
            }
            
            // Sync Sections
            echo "<h3>🏫 Syncing Sections...</h3>";
            $secResult = $sync->syncSections();
            $results['sections'] = $secResult;
            
            if ($secResult['success']) {
                echo "<div class='result success'>✅ Sections synced successfully! Count: {$secResult['count']}</div>";
            } else {
                echo "<div class='result error'>❌ Failed to sync sections: {$secResult['message']}</div>";
            }
            
            // Sync Schedules
            echo "<h3>📅 Syncing Schedules...</h3>";
            $schResult = $sync->syncSchedules();
            $results['schedules'] = $schResult;
            
            if ($schResult['success']) {
                echo "<div class='result success'>✅ Schedules synced successfully! Count: {$schResult['count']}</div>";
            } else {
                echo "<div class='result error'>❌ Failed to sync schedules: {$schResult['message']}</div>";
            }
            
            // Summary
            $totalSuccess = $profResult['success'] && $secResult['success'] && $schResult['success'];
            $totalCount = ($profResult['count'] ?? 0) + ($secResult['count'] ?? 0) + ($schResult['count'] ?? 0);
            
            echo "<div class='summary'>";
            echo "<h3>📋 Sync Summary</h3>";
            echo "<p><strong>Status:</strong> " . ($totalSuccess ? "✅ All data synced successfully!" : "⚠️ Some data failed to sync") . "</p>";
            echo "<p><strong>Total Records:</strong> {$totalCount}</p>";
            echo "<p><strong>Professors:</strong> " . ($profResult['count'] ?? 0) . "</p>";
            echo "<p><strong>Sections:</strong> " . ($secResult['count'] ?? 0) . "</p>";
            echo "<p><strong>Schedules:</strong> " . ($schResult['count'] ?? 0) . "</p>";
            echo "</div>";
            
            if ($totalSuccess) {
                echo "<a href='https://console.firebase.google.com/project/spcc-database/realtime-database' target='_blank' class='firebase-link'>🔥 View Data in Firebase Console</a>";
            }
            
        } catch (Exception $e) {
            echo "<div class='result error'>❌ Error: " . $e->getMessage() . "</div>";
        }
        ?>
        
        <div style="margin-top: 30px; padding: 15px; background-color: #e9ecef; border-radius: 5px;">
            <h3>📋 What was synced?</h3>
            <ul>
                <li><strong>Professors:</strong> All professor data including name, email, phone, qualifications, and subject count</li>
                <li><strong>Sections:</strong> All section data including name, grade level, strand, room assignments, and schedule count</li>
                <li><strong>Schedules:</strong> All schedule data including subject, professor, section, room, time slots, and days</li>
            </ul>
        </div>
    </div>
</body>
</html> 