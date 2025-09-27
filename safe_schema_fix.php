<?php
// Safe database schema fixes - checks for existing structures first
include 'connect.php';

echo "Starting SAFE database schema fixes...\n\n";

$fixes_applied = 0;
$fixes_skipped = 0;

// Helper function to check if column exists
function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// Helper function to check if table exists
function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

// Helper function to check if index exists
function indexExists($conn, $table, $index) {
    $result = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $result && $result->num_rows > 0;
}

// 1. Add missing columns to subjects table
echo "1. Checking subjects table columns...\n";

$columns_to_add = [
    'subj_units' => "ADD COLUMN `subj_units` int DEFAULT 3 AFTER `subj_description`",
    'subj_type' => "ADD COLUMN `subj_type` varchar(50) DEFAULT 'Core' AFTER `subj_units`", 
    'subj_hours_per_week' => "ADD COLUMN `subj_hours_per_week` int DEFAULT 3 AFTER `subj_type`"
];

foreach ($columns_to_add as $column => $sql) {
    if (!columnExists($conn, 'subjects', $column)) {
        if ($conn->query("ALTER TABLE `subjects` $sql")) {
            echo "✅ Added column: subjects.$column\n";
            $fixes_applied++;
        } else {
            echo "❌ Failed to add column: subjects.$column - " . $conn->error . "\n";
        }
    } else {
        echo "⏭️  Column subjects.$column already exists\n";
        $fixes_skipped++;
    }
}

// 2. Create subject_professor_assignments table
echo "\n2. Checking subject_professor_assignments table...\n";

if (!tableExists($conn, 'subject_professor_assignments')) {
    $create_table_sql = "
    CREATE TABLE `subject_professor_assignments` (
      `assignment_id` int NOT NULL AUTO_INCREMENT,
      `subj_id` int NOT NULL,
      `prof_id` int NOT NULL,
      `assigned_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`assignment_id`),
      UNIQUE KEY `unique_subject_professor` (`subj_id`, `prof_id`),
      KEY `idx_subject_assignment` (`subj_id`),
      KEY `idx_professor_assignment` (`prof_id`),
      CONSTRAINT `fk_spa_subject` FOREIGN KEY (`subj_id`) REFERENCES `subjects` (`subj_id`) ON DELETE CASCADE,
      CONSTRAINT `fk_spa_professor` FOREIGN KEY (`prof_id`) REFERENCES `professors` (`prof_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
    
    if ($conn->query($create_table_sql)) {
        echo "✅ Created table: subject_professor_assignments\n";
        $fixes_applied++;
    } else {
        echo "❌ Failed to create table: subject_professor_assignments - " . $conn->error . "\n";
    }
} else {
    echo "⏭️  Table subject_professor_assignments already exists\n";
    $fixes_skipped++;
}

// 3. Create system_settings table
echo "\n3. Checking system_settings table...\n";

if (!tableExists($conn, 'system_settings')) {
    $create_settings_sql = "
    CREATE TABLE `system_settings` (
      `setting_id` int NOT NULL AUTO_INCREMENT,
      `setting_key` varchar(100) NOT NULL,
      `setting_value` text,
      `setting_description` varchar(255),
      `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`setting_id`),
      UNIQUE KEY `unique_setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
    
    if ($conn->query($create_settings_sql)) {
        echo "✅ Created table: system_settings\n";
        $fixes_applied++;
        
        // Insert default settings
        $default_settings = [
            "('current_school_year', '2025-2026', 'Current active school year')",
            "('current_semester', 'First Semester', 'Current active semester')",
            "('firebase_sync_enabled', '1', 'Enable automatic Firebase synchronization')",
            "('max_schedules_per_day', '8', 'Maximum number of schedules per day per professor')",
            "('default_class_duration', '60', 'Default class duration in minutes')"
        ];
        
        $insert_sql = "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_description`) VALUES " . implode(', ', $default_settings);
        
        if ($conn->query($insert_sql)) {
            echo "✅ Inserted default system settings\n";
            $fixes_applied++;
        } else {
            echo "❌ Failed to insert default settings - " . $conn->error . "\n";
        }
    } else {
        echo "❌ Failed to create table: system_settings - " . $conn->error . "\n";
    }
} else {
    echo "⏭️  Table system_settings already exists\n";
    $fixes_skipped++;
}

// 4. Update existing subjects with default values
echo "\n4. Updating subjects with default values...\n";

$update_sql = "UPDATE `subjects` 
SET 
  `subj_units` = COALESCE(`subj_units`, 3),
  `subj_type` = COALESCE(`subj_type`, 'Core'),
  `subj_hours_per_week` = COALESCE(`subj_hours_per_week`, 3)
WHERE (`subj_units` IS NULL OR `subj_type` IS NULL OR `subj_hours_per_week` IS NULL)";

if ($conn->query($update_sql)) {
    $affected_rows = $conn->affected_rows;
    echo "✅ Updated $affected_rows subjects with default values\n";
    if ($affected_rows > 0) $fixes_applied++;
} else {
    echo "❌ Failed to update subjects - " . $conn->error . "\n";
}

// 5. Populate subject_professor_assignments from existing schedules
echo "\n5. Populating subject-professor assignments...\n";

if (tableExists($conn, 'subject_professor_assignments')) {
    $populate_sql = "INSERT IGNORE INTO `subject_professor_assignments` (`subj_id`, `prof_id`)
    SELECT DISTINCT `subj_id`, `prof_id` 
    FROM `schedules` 
    WHERE `subj_id` IS NOT NULL AND `prof_id` IS NOT NULL";
    
    if ($conn->query($populate_sql)) {
        $affected_rows = $conn->affected_rows;
        echo "✅ Added $affected_rows subject-professor assignments\n";
        if ($affected_rows > 0) $fixes_applied++;
    } else {
        echo "❌ Failed to populate assignments - " . $conn->error . "\n";
    }
}

// 6. Create useful views
echo "\n6. Creating database views...\n";

$views = [
    'schedule_details' => "
    CREATE OR REPLACE VIEW `schedule_details` AS
    SELECT 
        s.schedule_id,
        s.school_year,
        s.semester,
        s.schedule_type,
        s.start_time,
        s.end_time,
        s.days,
        s.created_at,
        subj.subj_id,
        subj.subj_code,
        subj.subj_name,
        subj.subj_description,
        subj.subj_units,
        subj.subj_type,
        subj.subj_hours_per_week,
        p.prof_id,
        p.prof_name,
        p.prof_email,
        r.room_id,
        r.room_number,
        r.room_type,
        sec.section_id,
        sec.section_name,
        sec.grade_level,
        sec.strand
    FROM schedules s
    LEFT JOIN subjects subj ON s.subj_id = subj.subj_id
    LEFT JOIN professors p ON s.prof_id = p.prof_id
    LEFT JOIN rooms r ON s.room_id = r.room_id
    LEFT JOIN sections sec ON s.section_id = sec.section_id",
    
    'professor_workload' => "
    CREATE OR REPLACE VIEW `professor_workload` AS
    SELECT 
        p.prof_id,
        p.prof_name,
        p.prof_email,
        COUNT(DISTINCT s.schedule_id) as total_schedules,
        COUNT(DISTINCT s.subj_id) as unique_subjects,
        COALESCE(SUM(subj.subj_hours_per_week), 0) as total_hours_per_week,
        GROUP_CONCAT(DISTINCT subj.subj_name SEPARATOR ', ') as subjects_taught
    FROM professors p
    LEFT JOIN schedules s ON p.prof_id = s.prof_id
    LEFT JOIN subjects subj ON s.subj_id = subj.subj_id
    GROUP BY p.prof_id, p.prof_name, p.prof_email"
];

foreach ($views as $view_name => $view_sql) {
    if ($conn->query($view_sql)) {
        echo "✅ Created view: $view_name\n";
        $fixes_applied++;
    } else {
        echo "❌ Failed to create view: $view_name - " . $conn->error . "\n";
    }
}

// Final verification
echo "\n=== FINAL VERIFICATION ===\n";

$verifications = [
    "SELECT COUNT(*) as count FROM subjects WHERE subj_units IS NOT NULL" => "Subjects with units",
    "SELECT COUNT(*) as count FROM subject_professor_assignments" => "Subject-professor assignments",
    "SELECT COUNT(*) as count FROM system_settings" => "System settings",
    "SELECT COUNT(*) as count FROM schedule_details LIMIT 1" => "Schedule details view",
    "SELECT COUNT(*) as count FROM professor_workload LIMIT 1" => "Professor workload view"
];

foreach ($verifications as $query => $description) {
    $result = $conn->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✅ $description: " . $row['count'] . "\n";
    } else {
        echo "❌ $description: FAILED\n";
    }
}

echo "\n=== SUMMARY ===\n";
echo "✅ Fixes applied: $fixes_applied\n";
echo "⏭️  Fixes skipped (already exist): $fixes_skipped\n";
echo "🎉 Database schema fix completed successfully!\n";

$conn->close();
?>
