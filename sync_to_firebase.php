<?php
// Simple Firebase sync script
header("Content-Type: text/html; charset=UTF-8");

require_once 'connect.php';
require_once 'firebase_config.php';
require_once 'firebase_sync.php';

echo "<h1>🔄 Sync MySQL Tables to Firebase</h1>";

try {
    $sync = new FirebaseSync($firebaseConfig, $conn);
    
    // Check database data
    echo "<h2>📊 Database Status</h2>";
    $profCount = $conn->query("SELECT COUNT(*) as count FROM professors")->fetch_assoc()['count'];
    $secCount = $conn->query("SELECT COUNT(*) as count FROM sections")->fetch_assoc()['count'];
    $schCount = $conn->query("SELECT COUNT(*) as count FROM schedules")->fetch_assoc()['count'];
    $roomCount = $conn->query("SELECT COUNT(*) as count FROM rooms")->fetch_assoc()['count'];
    
    echo "<p>Professors: <strong>{$profCount}</strong> records</p>";
    echo "<p>Sections: <strong>{$secCount}</strong> records</p>";
    echo "<p>Schedules: <strong>{$schCount}</strong> records</p>";
    echo "<p>Rooms: <strong>{$roomCount}</strong> records</p>";
    
    if ($profCount == 0 && $secCount == 0 && $schCount == 0 && $roomCount == 0) {
        echo "<p style='color: orange;'>⚠️ No data to sync. Add some data first!</p>";
        exit;
    }
    
    echo "<h2>🔄 Starting Sync...</h2>";
    
    // Sync Professors
    echo "<h3>👨‍🏫 Syncing Professors...</h3>";
    $profResult = $sync->syncProfessors();
    if ($profResult['success']) {
        echo "<p style='color: green;'>✅ Professors synced successfully! ({$profCount} records)</p>";
    } else {
        echo "<p style='color: red;'>❌ Professor sync failed: " . $profResult['message'] . "</p>";
    }
    
    // Sync Sections
    echo "<h3>📚 Syncing Sections...</h3>";
    $secResult = $sync->syncSections();
    if ($secResult['success']) {
        echo "<p style='color: green;'>✅ Sections synced successfully! ({$secCount} records)</p>";
    } else {
        echo "<p style='color: red;'>❌ Section sync failed: " . $secResult['message'] . "</p>";
    }
    
    // Sync Schedules
    echo "<h3>📅 Syncing Schedules...</h3>";
    $schResult = $sync->syncSchedules();
    if ($schResult['success']) {
        echo "<p style='color: green;'>✅ Schedules synced successfully! ({$schCount} records)</p>";
    } else {
        echo "<p style='color: red;'>❌ Schedule sync failed: " . $schResult['message'] . "</p>";
    }
    
    // Sync Rooms
    echo "<h3>🏢 Syncing Rooms...</h3>";
    $roomResult = $sync->syncRooms();
    if ($roomResult['success']) {
        echo "<p style='color: green;'>✅ Rooms synced successfully! ({$roomCount} records)</p>";
    } else {
        echo "<p style='color: red;'>❌ Room sync failed: " . $roomResult['message'] . "</p>";
    }
    
    // Summary
    $successCount = 0;
    if ($profResult['success']) $successCount++;
    if ($secResult['success']) $successCount++;
    if ($schResult['success']) $successCount++;
    if ($roomResult['success']) $successCount++;
    
    echo "<h2>🎯 Sync Summary</h2>";
    echo "<p>Successfully synced: <strong>{$successCount}/4</strong> tables</p>";
    
    if ($successCount == 4) {
        echo "<p style='color: green; font-size: 18px;'>🎉 All tables synced successfully!</p>";
        echo "<p><a href='https://console.firebase.google.com/project/spcc-database/realtime-database' target='_blank' style='background: #ff6b35; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔍 View in Firebase Console</a></p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Some tables failed to sync. Check the errors above.</p>";
    }
    
    echo "<h2>🔄 Sync Again</h2>";
    echo "<p><a href='sync_to_firebase.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔄 Run Sync Again</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<p><a href='firebase_rules_fix.php' style='background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔧 Fix Firebase Rules</a></p>";
}
?> 