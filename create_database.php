<?php
header('Content-Type: text/html; charset=utf-8');

echo "<h2>SPCC Database Setup</h2>";

try {
    // First, connect to MySQL without specifying a database
    $host = 'localhost';
    $dbuser = 'root';
    $dbpass = '';
    
    $pdo = new PDO("mysql:host=$host;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p style='color: green;'>✅ Connected to MySQL server!</p>";
    
    // Create the database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS spcc_scheduling_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p style='color: green;'>✅ Database 'spcc_scheduling_system' created!</p>";
    
    // Now connect to the specific database
    $pdo = new PDO("mysql:host=$host;dbname=spcc_scheduling_system;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p style='color: green;'>✅ Connected to spcc_scheduling_system!</p>";
    
    // Create tables
    $tables = [
        'schedules' => "
        CREATE TABLE IF NOT EXISTS schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            school_year VARCHAR(10) NOT NULL,
            semester VARCHAR(50) NOT NULL,
            subj_id INT NOT NULL,
            prof_id INT NOT NULL,
            section_id INT NOT NULL,
            room_id INT NULL,
            schedule_type ENUM('Onsite', 'Online') DEFAULT 'Onsite',
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            days JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_school_year_semester (school_year, semester),
            INDEX idx_prof_id (prof_id),
            INDEX idx_room_id (room_id),
            INDEX idx_section_id (section_id),
            INDEX idx_time_range (start_time, end_time)
        )",
        
        'subjects' => "
        CREATE TABLE IF NOT EXISTS subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject_name VARCHAR(255) NOT NULL,
            subject_code VARCHAR(20) NOT NULL UNIQUE,
            subject_units INT DEFAULT 3,
            subject_type VARCHAR(50) DEFAULT 'Core',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        'professors' => "
        CREATE TABLE IF NOT EXISTS professors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prof_name VARCHAR(255) NOT NULL,
            prof_email VARCHAR(255) UNIQUE,
            prof_contact VARCHAR(20),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        'rooms' => "
        CREATE TABLE IF NOT EXISTS rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_number VARCHAR(20) NOT NULL UNIQUE,
            room_type ENUM('Lecture', 'Laboratory') DEFAULT 'Lecture',
            room_capacity INT DEFAULT 40,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        'sections' => "
        CREATE TABLE IF NOT EXISTS sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section_name VARCHAR(100) NOT NULL,
            grade_level ENUM('11', '12') NOT NULL,
            strand VARCHAR(50) NOT NULL,
            number_of_students INT DEFAULT 0,
            room_ids TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    ];
    
    foreach ($tables as $tableName => $sql) {
        $pdo->exec($sql);
        echo "<p style='color: green;'>✅ Table '$tableName' created!</p>";
    }
    
    // Insert sample data
    echo "<h3>Inserting Sample Data...</h3>";
    
    // Sample subjects
    $pdo->exec("INSERT IGNORE INTO subjects (id, subject_name, subject_code) VALUES
        (1, 'Mathematics', 'MATH101'),
        (2, 'English', 'ENG101'),
        (3, 'Science', 'SCI101'),
        (4, 'History', 'HIST101'),
        (5, 'Physical Education', 'PE101'),
        (6, 'Computer Science', 'CS101'),
        (7, 'Filipino', 'FIL101'),
        (8, 'Research', 'RES101')");
    echo "<p style='color: blue;'>📚 Sample subjects inserted!</p>";
    
    // Sample professors
    $pdo->exec("INSERT IGNORE INTO professors (id, prof_name, prof_email) VALUES
        (1, 'Jasper Jean Mariano', 'jasper.mariano@spcc.edu.ph'),
        (2, 'Korina Sanchez', 'korina.sanchez@spcc.edu.ph'),
        (3, 'Maria Santos', 'maria.santos@spcc.edu.ph'),
        (4, 'John Dela Cruz', 'john.delacruz@spcc.edu.ph'),
        (5, 'Ana Rodriguez', 'ana.rodriguez@spcc.edu.ph')");
    echo "<p style='color: blue;'>👨‍🏫 Sample professors inserted!</p>";
    
    // Sample rooms
    $pdo->exec("INSERT IGNORE INTO rooms (id, room_number, room_type, room_capacity) VALUES
        (1, '108', 'Lecture', 50),
        (2, '109', 'Laboratory', 30),
        (3, '110', 'Lecture', 40),
        (4, '201', 'Lecture', 45),
        (5, '202', 'Laboratory', 25)");
    echo "<p style='color: blue;'>🏫 Sample rooms inserted!</p>";
    
    // Sample sections
    $pdo->exec("INSERT IGNORE INTO sections (id, section_name, grade_level, strand, number_of_students, room_ids) VALUES
        (1, 'Grade 11-A', '11', 'STEM', 35, '1'),
        (2, 'Grade 11-B', '11', 'ABM', 32, '2'),
        (3, 'Grade 12-A', '12', 'HUMSS', 30, '3'),
        (4, 'Grade 11-C', '11', 'GAS', 28, '4'),
        (5, 'Grade 12-B', '12', 'STEM', 33, '5')");
    echo "<p style='color: blue;'>🎓 Sample sections inserted!</p>";
    
    // Show final status
    echo "<h3>Database Status:</h3>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM subjects");
    $result = $stmt->fetch();
    echo "<p>Subjects: {$result['count']}</p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM professors");
    $result = $stmt->fetch();
    echo "<p>Professors: {$result['count']}</p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM rooms");
    $result = $stmt->fetch();
    echo "<p>Rooms: {$result['count']}</p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM sections");
    $result = $stmt->fetch();
    echo "<p>Sections: {$result['count']}</p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM schedules");
    $result = $stmt->fetch();
    echo "<p>Schedules: {$result['count']}</p>";
    
    echo "<h2 style='color: green;'>🎉 Database setup completed successfully!</h2>";
    echo "<p>You can now go back to your scheduling application and try creating a schedule.</p>";
    echo "<p><a href='test_database.php'>Test Database Connection</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
    echo "<p>Please check:</p>";
    echo "<ul>";
    echo "<li>XAMPP MySQL service is running</li>";
    echo "<li>MySQL credentials are correct (username: root, password: nchsrgs2803)</li>";
    echo "<li>MySQL server is accessible on localhost</li>";
    echo "</ul>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
h2, h3 { color: #333; }
p { margin: 10px 0; }
ul { margin: 10px 0; padding-left: 20px; }
a { color: #007bff; text-decoration: none; }
a:hover { text-decoration: underline; }
</style>
