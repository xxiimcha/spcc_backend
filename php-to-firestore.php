<?php
/**
 * PHP to Firebase Firestore Data Synchronization Script
 * 
 * This script transfers data from your PHP database to Firebase Firestore
 * Run this script periodically to keep Firebase in sync with your PHP database
 * 
 * Requirements:
 * - firebase_firestore_config.php file with Firebase configuration
 * - Composer with kreait/firebase-php package
 * - Firebase service account credentials
 * 
 * Installation:
 * composer require kreait/firebase-php
 * 
 * Usage:
 * php php-to-firestore.php
 */

// Include Firebase Firestore configuration
require_once 'firebase_firestore_config.php';

// Database configuration (update with your PHP database details)
$dbConfig = [
    'host' => 'localhost',
    'dbname' => 'spcc_scheduling_system',
    'username' => 'root',
    'password' => ''
];

try {
    // Initialize Firebase Firestore
    $firestore = $firebaseFirestoreConfig->getFirestore();

    // Initialize PHP database connection
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Starting data synchronization to Firestore...\n";

    // Sync Professors
    syncProfessorsToFirestore($pdo, $firebaseFirestoreConfig);
    
    // Sync Rooms
    syncRoomsToFirestore($pdo, $firebaseFirestoreConfig);
    
    // Sync Sections
    syncSectionsToFirestore($pdo, $firebaseFirestoreConfig);
    
    // Sync Subjects
    syncSubjectsToFirestore($pdo, $firebaseFirestoreConfig);
    
    // Sync Schedules
    syncSchedulesToFirestore($pdo, $firebaseFirestoreConfig);

    echo "Data synchronization to Firestore completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Sync professors from PHP database to Firestore
 */
function syncProfessorsToFirestore($pdo, $firebase) {
    echo "Syncing professors to Firestore...\n";
    
    $stmt = $pdo->query("
        SELECT 
            prof_id,
            prof_name,
            prof_email,
            prof_phone,
            prof_qualifications,
            prof_username,
            prof_password,
            subj_count
        FROM professors
    ");
    
    $professors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $successCount = 0;
    
    foreach ($professors as $professor) {
        $professorData = [
            'id' => (int)$professor['prof_id'],
            'name' => $professor['prof_name'],
            'email' => $professor['prof_email'] ?? '',
            'phone' => $professor['prof_phone'] ?? '',
            'qualifications' => json_decode($professor['prof_qualifications'], true) ?: [],
            'username' => $professor['prof_username'] ?? '',
            'password' => $professor['prof_password'] ?? '',
            'subject_count' => (int)$professor['subj_count'],
            'created_at' => new DateTime(),
            'updated_at' => new DateTime()
        ];
        
        try {
            $firebase->addDocument('professors', $professorData, $professor['prof_id']);
            $successCount++;
        } catch (Exception $e) {
            echo "Error syncing professor {$professor['prof_id']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Synced {$successCount}/" . count($professors) . " professors to Firestore\n";
}

/**
 * Sync rooms from PHP database to Firestore
 */
function syncRoomsToFirestore($pdo, $firebase) {
    echo "Syncing rooms to Firestore...\n";
    
    $stmt = $pdo->query("
        SELECT 
            room_id,
            room_number,
            room_type,
            room_capacity
        FROM rooms
    ");
    
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $successCount = 0;
    
    foreach ($rooms as $room) {
        $roomData = [
            'id' => (int)$room['room_id'],
            'number' => (int)$room['room_number'],
            'type' => $room['room_type'],
            'capacity' => (int)$room['room_capacity'],
            'created_at' => new DateTime(),
            'updated_at' => new DateTime()
        ];
        
        try {
            $firebase->addDocument('rooms', $roomData, $room['room_id']);
            $successCount++;
        } catch (Exception $e) {
            echo "Error syncing room {$room['room_id']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Synced {$successCount}/" . count($rooms) . " rooms to Firestore\n";
}

/**
 * Sync sections from PHP database to Firestore
 */
function syncSectionsToFirestore($pdo, $firebase) {
    echo "Syncing sections to Firestore...\n";
    
    $stmt = $pdo->query("
        SELECT 
            s.section_id,
            s.section_name,
            s.grade_level,
            s.strand,
            s.max_students,
            COUNT(DISTINCT sch.schedule_id) as schedule_count,
            GROUP_CONCAT(DISTINCT r.room_id, ':', r.room_number, ':', r.room_type, ':', r.room_capacity SEPARATOR '|') as rooms
        FROM sections s
        LEFT JOIN section_room_assignments sra ON s.section_id = sra.section_id
        LEFT JOIN rooms r ON sra.room_id = r.room_id
        LEFT JOIN schedules sch ON s.section_id = sch.section_id
        GROUP BY s.section_id
        ORDER BY s.grade_level, s.section_name
    ");
    
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $successCount = 0;
    
    foreach ($sections as $section) {
        // Parse rooms data
        $rooms = [];
        if (!empty($section['rooms'])) {
            $roomData = explode('|', $section['rooms']);
            foreach ($roomData as $room) {
                if (!empty($room)) {
                    $roomParts = explode(':', $room);
                    if (count($roomParts) >= 4) {
                        $rooms[] = [
                            'id' => (int)$roomParts[0],
                            'number' => (int)$roomParts[1],
                            'type' => $roomParts[2],
                            'capacity' => (int)$roomParts[3]
                        ];
                    }
                }
            }
        }
        
        $sectionData = [
            'id' => (int)$section['section_id'],
            'name' => $section['section_name'],
            'grade_level' => $section['grade_level'],
            'strand' => $section['strand'],
            'max_students' => (int)$section['max_students'],
            'schedule_count' => (int)$section['schedule_count'],
            'rooms' => $rooms,
            'created_at' => new DateTime(),
            'updated_at' => new DateTime()
        ];
        
        try {
            $firebase->addDocument('sections', $sectionData, $section['section_id']);
            $successCount++;
        } catch (Exception $e) {
            echo "Error syncing section {$section['section_id']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Synced {$successCount}/" . count($sections) . " sections to Firestore\n";
}

/**
 * Sync subjects from PHP database to Firestore
 */
function syncSubjectsToFirestore($pdo, $firebase) {
    echo "Syncing subjects to Firestore...\n";
    
    $stmt = $pdo->query("
        SELECT 
            subj_id,
            subj_code,
            subj_name,
            subj_description,
            subj_units,
            subj_prerequisites
        FROM subjects
        ORDER BY subj_code
    ");
    
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $successCount = 0;
    
    foreach ($subjects as $subject) {
        $subjectData = [
            'id' => (int)$subject['subj_id'],
            'code' => $subject['subj_code'],
            'name' => $subject['subj_name'],
            'description' => $subject['subj_description'] ?? '',
            'units' => (int)$subject['subj_units'],
            'prerequisites' => $subject['subj_prerequisites'] ?? '',
            'created_at' => new DateTime(),
            'updated_at' => new DateTime()
        ];
        
        try {
            $firebase->addDocument('subjects', $subjectData, $subject['subj_id']);
            $successCount++;
        } catch (Exception $e) {
            echo "Error syncing subject {$subject['subj_id']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Synced {$successCount}/" . count($subjects) . " subjects to Firestore\n";
}

/**
 * Sync schedules from PHP database to Firestore
 */
function syncSchedulesToFirestore($pdo, $firebase) {
    echo "Syncing schedules to Firestore...\n";
    
    $stmt = $pdo->query("
        SELECT 
            s.schedule_id,
            s.school_year,
            s.semester,
            s.schedule_type,
            s.start_time,
            s.end_time,
            s.subj_id,
            s.prof_id,
            s.room_id,
            s.section_id,
            subj.subj_code,
            subj.subj_name,
            p.prof_name as professor_name,
            r.room_number,
            r.room_type,
            r.room_capacity,
            sec.section_name,
            sec.grade_level,
            sec.strand,
            s.days
        FROM schedules s
        JOIN subjects subj ON s.subj_id = subj.subj_id
        JOIN professors p ON s.prof_id = p.prof_id
        JOIN sections sec ON s.section_id = sec.section_id
        LEFT JOIN rooms r ON s.room_id = r.room_id
        -- Days are now stored as JSON in schedules table
        -- No need for JOIN with schedule_days and days tables
        ORDER BY s.school_year, s.semester, s.start_time
    ");
    
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $successCount = 0;
    
    foreach ($schedules as $schedule) {
        $scheduleData = [
            'id' => (int)$schedule['schedule_id'],
            'school_year' => $schedule['school_year'],
            'semester' => $schedule['semester'],
            'schedule_type' => $schedule['schedule_type'],
            'start_time' => $schedule['start_time'],
            'end_time' => $schedule['end_time'],
            'subject' => [
                'id' => (int)$schedule['subj_id'],
                'code' => $schedule['subj_code'],
                'name' => $schedule['subj_name']
            ],
            'professor' => [
                'id' => (int)$schedule['prof_id'],
                'name' => $schedule['professor_name']
            ],
            'section' => [
                'id' => (int)$schedule['section_id'],
                'name' => $schedule['section_name'],
                'grade_level' => $schedule['grade_level'],
                'strand' => $schedule['strand']
            ],
            'room' => $schedule['room_id'] ? [
                'id' => (int)$schedule['room_id'],
                'number' => (int)$schedule['room_number'],
                'type' => $schedule['room_type'],
                'capacity' => (int)$schedule['room_capacity']
            ] : null,
            'days' => explode(',', $schedule['days']),
            'created_at' => new DateTime(),
            'updated_at' => new DateTime()
        ];
        
        try {
            $firebase->addDocument('schedules', $scheduleData, $schedule['schedule_id']);
            $successCount++;
        } catch (Exception $e) {
            echo "Error syncing schedule {$schedule['schedule_id']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Synced {$successCount}/" . count($schedules) . " schedules to Firestore\n";
}

/**
 * Create Firestore indexes for better performance
 * Run this once to create the required indexes
 */
function createFirestoreIndexes() {
    echo "Creating Firestore indexes...\n";
    
    // You'll need to create these indexes in the Firebase Console
    // or use the Firebase CLI
    
    $indexes = [
        // Professors collection indexes
        'professors: email (Ascending)',
        'professors: subject_count (Ascending)',
        
        // Schedules collection indexes
        'schedules: school_year (Ascending), semester (Ascending)',
        'schedules: professor.id (Ascending), school_year (Ascending)',
        'schedules: section.id (Ascending), school_year (Ascending)',
        'schedules: room.id (Ascending), school_year (Ascending)',
        'schedules: start_time (Ascending), end_time (Ascending)',
        
        // Rooms collection indexes
        'rooms: type (Ascending)',
        'rooms: capacity (Ascending)',
        
        // Sections collection indexes
        'sections: grade_level (Ascending), strand (Ascending)',
        'sections: schedule_count (Ascending)',
        
        // Subjects collection indexes
        'subjects: code (Ascending)',
        'subjects: units (Ascending)',
    ];
    
    foreach ($indexes as $index) {
        echo "Index needed: {$index}\n";
    }
    
    echo "Please create these indexes in the Firebase Console under Firestore > Indexes\n";
}

// Uncomment the line below to create indexes (run once)
// createFirestoreIndexes();
?>
