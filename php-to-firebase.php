<?php
/**
 * PHP to Firebase Data Synchronization Script (Admin SDK)
 * 
 * This script transfers data from your PHP database to Firebase Realtime Database
 * using the Firebase Admin SDK for PHP
 * 
 * Requirements:
 * - firebase_admin_config.php file with Firebase Admin SDK configuration
 * - Composer with kreait/firebase-php package
 * - Firebase service account credentials
 * 
 * Installation:
 * composer require kreait/firebase-php
 * 
 * Usage:
 * php php-to-firebase.php
 */

// Include Firebase Admin SDK configuration
require_once 'firebase_admin_config.php';

// Database configuration (update with your PHP database details)
$dbConfig = [
    'host' => 'localhost',
    'dbname' => 'spcc_scheduling_system',
    'username' => 'root',
    'password' => ''
];

try {
    // Initialize Firebase Admin SDK
    $firebaseAdmin = new FirebaseAdminConfig();
    $firebase = $firebaseAdmin->getDatabase();
    $auth = $firebaseAdmin->getAuth();

    // Initialize PHP database connection
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Starting data synchronization...\n";

    // Sync Professors
    syncProfessors($pdo, $firebase);
    // Ensure professors exist in Firebase Authentication and link UIDs
    syncProfessorsToAuth($pdo, $auth, $firebase);
    
    // Sync Rooms
    syncRooms($pdo, $firebase);
    
    // Sync Sections
    syncSections($pdo, $firebase);
    
    // Sync Subjects
    syncSubjects($pdo, $firebase);
    
    // Sync Schedules
    syncSchedules($pdo, $firebase);

    echo "Data synchronization completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Sync professors from PHP database to Firebase
 */
function syncProfessors($pdo, $firebase) {
    echo "Syncing professors...\n";
    
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
    
    $professorsData = [];
    foreach ($professors as $professor) {
        $professorsData[$professor['prof_id']] = [
            'id' => (int)$professor['prof_id'],
            'name' => $professor['prof_name'],
            'email' => $professor['prof_email'] ?? '',
            'phone' => $professor['prof_phone'] ?? '',
            'qualifications' => json_decode($professor['prof_qualifications'], true) ?: [],
            'username' => $professor['prof_username'] ?? '',
            'password' => $professor['prof_password'] ?? '',
            'subject_count' => (int)$professor['subj_count']
        ];
    }
    
    // Push all professors to Firebase using Admin SDK
    $reference = $firebase->getReference('professors');
    $reference->set($professorsData);
    
    echo "Synced " . count($professors) . " professors successfully\n";
}

/**
 * Create/Update Firebase Auth users for each professor and store UID in Realtime DB
 */
function syncProfessorsToAuth($pdo, $auth, $firebase) {
    echo "Linking professors to Firebase Auth...\n";

    // Fetch professors needed for auth
    $stmt = $pdo->query("\n        SELECT \n            prof_id,\n            prof_name,\n            prof_email,\n            prof_username,\n            prof_password\n        FROM professors\n    ");

    $professors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $createdCount = 0;
    $updatedCount = 0;
    foreach ($professors as $professor) {
        $profId = (int)$professor['prof_id'];
        $displayName = $professor['prof_name'] ?: ('Professor #' . $profId);
        $username = $professor['prof_username'] ?: ('prof_' . $profId);

        // Deterministic UID and fallback email to avoid collisions
        $uid = 'prof_' . $profId;
        $email = $professor['prof_email'];
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $uid . '@spcc.local';
        }

        // Ensure a password exists with minimum length
        $password = (string)($professor['prof_password'] ?? '');
        if (strlen($password) < 6) {
            $password = str_pad($password, 6, '0');
        }

        try {
            // Try get by UID first (deterministic)
            $auth->getUser($uid);
            // Update user
            $auth->updateUser($uid, [
                'email' => $email,
                'displayName' => $displayName,
                'password' => $password,
            ]);
            $updatedCount++;
        } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
            // Create with specified UID; handle existing email
            try {
                $auth->createUser([
                    'uid' => $uid,
                    'email' => $email,
                    'password' => $password,
                    'displayName' => $displayName,
                ]);
                $createdCount++;
            } catch (\Kreait\Firebase\Exception\Auth\EmailExists $ex) {
                // If email exists, update that user and adopt its UID
                $existing = $auth->getUserByEmail($email);
                $uid = $existing->uid;
                $auth->updateUser($uid, [
                    'displayName' => $displayName,
                    'password' => $password,
                ]);
                $updatedCount++;
            }
        } catch (\Throwable $e) {
            echo "Failed auth sync for professor {$profId} ({$email}): " . $e->getMessage() . "\n";
            continue;
        }

        // Store UID and auth email back into Realtime Database under the professor entry
        try {
            $firebase->getReference('professors/' . $profId)->update([
                'auth_uid' => $uid,
                'auth_email' => $email,
                'last_auth_sync' => date('c'),
            ]);
        } catch (\Throwable $e) {
            echo "Failed to write auth linkage for professor {$profId}: " . $e->getMessage() . "\n";
        }
    }

    echo "Professors Auth sync complete. Created: {$createdCount}, Updated: {$updatedCount}\n";
}

/**
 * Sync rooms from PHP database to Firebase
 */
function syncRooms($pdo, $firebase) {
    echo "Syncing rooms...\n";
    
    $stmt = $pdo->query("
        SELECT 
            room_id,
            room_number,
            room_type,
            room_capacity
        FROM rooms 
    ");
    
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $roomsData = [];
    foreach ($rooms as $room) {
        $roomsData[$room['room_id']] = [
            'id' => (int)$room['room_id'],
            'number' => (int)$room['room_number'],
            'type' => $room['room_type'],
            'capacity' => (int)$room['room_capacity']
        ];
    }
    
    // Push all rooms to Firebase using Admin SDK
    $reference = $firebase->getReference('rooms');
    $reference->set($roomsData);
    
    echo "Synced " . count($rooms) . " rooms successfully\n";
}

/**
 * Sync sections from PHP database to Firebase
 */
function syncSections($pdo, $firebase) {
    echo "Syncing sections...\n";
    
    $stmt = $pdo->query("
        SELECT 
            s.section_id,
            s.section_name,
            s.grade_level,
            s.strand,
            s.number_of_students,
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
    
    $sectionsData = [];
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
        
        $sectionsData[$section['section_id']] = [
            'id' => (int)$section['section_id'],
            'name' => $section['section_name'],
            'grade_level' => $section['grade_level'],
            'strand' => $section['strand'],
            'max_students' => (int)$section['number_of_students'],
            'schedule_count' => (int)$section['schedule_count'],
            'rooms' => $rooms
        ];
    }
    
    // Push all sections to Firebase using Admin SDK
    $reference = $firebase->getReference('sections');
    $reference->set($sectionsData);
    
    echo "Synced " . count($sections) . " sections successfully\n";
}

/**
 * Sync subjects from PHP database to Firebase
 */
function syncSubjects($pdo, $firebase) {
    echo "Syncing subjects...\n";
    
    $stmt = $pdo->query("
        SELECT 
            subj_id,
            subj_code,
            subj_name,
            subj_description
        FROM subjects
        ORDER BY subj_code
    ");
    
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $subjectsData = [];
    foreach ($subjects as $subject) {
        $subjectsData[$subject['subj_id']] = [
            'id' => (int)$subject['subj_id'],
            'code' => $subject['subj_code'],
            'name' => $subject['subj_name'],
            'description' => $subject['subj_description'] ?? ''
        ];
    }
    
    // Push all subjects to Firebase using Admin SDK
    $reference = $firebase->getReference('subjects');
    $reference->set($subjectsData);
    
    echo "Synced " . count($subjects) . " subjects successfully\n";
}

/**
 * Sync schedules from PHP database to Firebase
 */
function syncSchedules($pdo, $firebase) {
    echo "Syncing schedules...\n";
    
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
            sec.number_of_students,
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
    
    $schedulesData = [];
    foreach ($schedules as $schedule) {
        $payload = [
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
                'strand' => $schedule['strand'],
                'max_students' => isset($schedule['number_of_students']) ? (int)$schedule['number_of_students'] : null
            ],
            'room' => $schedule['room_id'] ? [
                'id' => (int)$schedule['room_id'],
                'number' => (int)$schedule['room_number'],
                'type' => $schedule['room_type'],
                'capacity' => (int)$schedule['room_capacity']
            ] : null,
            'days' => explode(',', $schedule['days'])
        ];

        $schedulesData[$schedule['schedule_id']] = $payload;

        // Also write per-professor schedule copy under prof_schedules/{authUid}
        try {
            $profId = (int)$schedule['prof_id'];
            $authUid = $firebase->getReference('professors/' . $profId . '/auth_uid')->getValue();
            if (!empty($authUid)) {
                $firebase
                    ->getReference('prof_schedules/' . $authUid . '/' . $schedule['schedule_id'])
                    ->set($payload);
            }
        } catch (\Throwable $e) {
            echo "Warning: failed writing prof_schedules for schedule {$schedule['schedule_id']}: " . $e->getMessage() . "\n";
        }
    }
    
    // Push all schedules to Firebase using Admin SDK
    $reference = $firebase->getReference('schedules');
    $reference->set($schedulesData);
    
    echo "Synced " . count($schedules) . " schedules successfully\n";
}

/**
 * Create Firebase indexes for better performance
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
