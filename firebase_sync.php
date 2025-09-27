<?php
// Firebase sync service for schedules, sections, and professors
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once 'connect.php';
require_once 'firebase_config.php';

class FirebaseSync {
    private $firebaseConfig;
    private $conn;
    
    public function __construct($firebaseConfig, $conn) {
        $this->firebaseConfig = $firebaseConfig;
        $this->conn = $conn;
    }
    
    // Sync all schedules to Firebase
    public function syncSchedules() {
        $sql = "SELECT 
                    s.schedule_id,
                    s.school_year,
                    s.semester,
                    s.schedule_type,
                    s.start_time,
                    s.end_time,
                    subj.subj_code,
                    subj.subj_name,
                    subj.subj_description,
                    p.prof_id,
                    p.prof_name,
                    p.prof_email,
                    sec.section_id,
                    sec.section_name,
                    sec.grade_level,
                    sec.strand,
                    r.room_id,
                    r.room_number,
                    r.room_type,
                    r.room_capacity,
                    s.days
                FROM schedules s
                JOIN subjects subj ON s.subj_id = subj.subj_id
                JOIN professors p ON s.prof_id = p.prof_id
                JOIN sections sec ON s.section_id = sec.section_id
                LEFT JOIN rooms r ON s.room_id = r.room_id
                        -- Days are now stored as JSON in schedules table
        -- No need for JOIN with schedule_days and days tables
                ORDER BY s.school_year, s.semester, s.start_time";
        
        $result = $this->conn->query($sql);
        
        if (!$result) {
            return [
                'success' => false,
                'message' => 'Failed to fetch schedules: ' . $this->conn->error
            ];
        }
        
        $schedules = [];
        while ($row = $result->fetch_assoc()) {
            $schedule = [
                'id' => $row['schedule_id'],
                'school_year' => $row['school_year'],
                'semester' => $row['semester'],
                'schedule_type' => $row['schedule_type'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'subject' => [
                    'code' => $row['subj_code'],
                    'name' => $row['subj_name'],
                    'description' => $row['subj_description']
                ],
                'professor' => [
                    'id' => $row['prof_id'],
                    'name' => $row['prof_name'],
                    'email' => $row['prof_email']
                ],
                'section' => [
                    'id' => $row['section_id'],
                    'name' => $row['section_name'],
                    'grade_level' => $row['grade_level'],
                    'strand' => $row['strand']
                ],
                'room' => $row['room_id'] ? [
                    'id' => $row['room_id'],
                    'number' => $row['room_number'],
                    'type' => $row['room_type'],
                    'capacity' => $row['room_capacity']
                ] : null,
                'days' => explode(',', $row['days'])
            ];
            
            $schedules[] = $schedule;
        }
        
        // Push to Firebase
        $response = $this->firebaseConfig->setData('schedules', $schedules);
        
        return [
            'success' => $response['status'] === 200,
            'message' => $response['status'] === 200 ? 'Schedules synced successfully' : 'Failed to sync schedules',
            'count' => count($schedules),
            'firebase_response' => $response
        ];
    }
    
    // Sync all sections to Firebase
    public function syncSections() {
        $sql = "SELECT 
                    s.section_id,
                    s.section_name,
                    s.grade_level,
                    s.strand,
                    s.number_of_students,
                    GROUP_CONCAT(DISTINCT r.room_id, ':', r.room_number, ':', r.room_type, ':', r.room_capacity SEPARATOR '|') as rooms,
                    COUNT(DISTINCT sch.schedule_id) as schedule_count
                FROM sections s
                LEFT JOIN section_room_assignments sra ON s.section_id = sra.section_id
                LEFT JOIN rooms r ON sra.room_id = r.room_id
                LEFT JOIN schedules sch ON s.section_id = sch.section_id
                GROUP BY s.section_id
                ORDER BY s.grade_level, s.strand, s.section_name";
        
        $result = $this->conn->query($sql);
        
        if (!$result) {
            return [
                'success' => false,
                'message' => 'Failed to fetch sections: ' . $this->conn->error
            ];
        }
        
        $sections = [];
        while ($row = $result->fetch_assoc()) {
            $rooms = [];
            if ($row['rooms']) {
                $roomData = explode('|', $row['rooms']);
                foreach ($roomData as $room) {
                    $roomParts = explode(':', $room);
                    if (count($roomParts) >= 4) {
                        $rooms[] = [
                            'id' => $roomParts[0],
                            'number' => $roomParts[1],
                            'type' => $roomParts[2],
                            'capacity' => $roomParts[3]
                        ];
                    }
                }
            }
            
            $section = [
                'id' => $row['section_id'],
                'name' => $row['section_name'],
                'grade_level' => $row['grade_level'],
                'strand' => $row['strand'],
                'number_of_students' => $row['number_of_students'],
                'rooms' => $rooms,
                'schedule_count' => $row['schedule_count']
            ];
            
            $sections[] = $section;
        }
        
        // Push to Firebase
        $response = $this->firebaseConfig->setData('sections', $sections);
        
        return [
            'success' => $response['status'] === 200,
            'message' => $response['status'] === 200 ? 'Sections synced successfully' : 'Failed to sync sections',
            'count' => count($sections),
            'firebase_response' => $response
        ];
    }
    
    // Sync all professors to Firebase
    public function syncProfessors() {
        $sql = "SELECT 
                    prof_id,
                    prof_name,
                    prof_email,
                    prof_phone,
                    prof_qualifications,
                    subj_count
                FROM professors
                ORDER BY prof_name";
        
        $result = $this->conn->query($sql);
        
        if (!$result) {
            return [
                'success' => false,
                'message' => 'Failed to fetch professors: ' . $this->conn->error
            ];
        }
        
        $professors = [];
        while ($row = $result->fetch_assoc()) {
            $professor = [
                'id' => $row['prof_id'],
                'name' => $row['prof_name'],
                'email' => $row['prof_email'],
                'phone' => $row['prof_phone'],
                'qualifications' => json_decode($row['prof_qualifications'], true) ?: [],
                'subject_count' => $row['subj_count']
            ];
            
            $professors[] = $professor;
        }
        
        // Push to Firebase
        $response = $this->firebaseConfig->setData('professors', $professors);
        
        return [
            'success' => $response['status'] === 200,
            'message' => $response['status'] === 200 ? 'Professors synced successfully' : 'Failed to sync professors',
            'count' => count($professors),
            'firebase_response' => $response
        ];
    }
    
    // Sync a single schedule to Firebase
    public function syncSingleSchedule($scheduleId) {
        $sql = "SELECT 
                    s.schedule_id,
                    s.school_year,
                    s.semester,
                    s.schedule_type,
                    s.start_time,
                    s.end_time,
                    subj.subj_code,
                    subj.subj_name,
                    subj.subj_description,
                    p.prof_id,
                    p.prof_name,
                    p.prof_email,
                    sec.section_id,
                    sec.section_name,
                    sec.grade_level,
                    sec.strand,
                    r.room_id,
                    r.room_number,
                    r.room_type,
                    r.room_capacity,
                    s.days
                FROM schedules s
                JOIN subjects subj ON s.subj_id = subj.subj_id
                JOIN professors p ON s.prof_id = p.prof_id
                JOIN sections sec ON s.section_id = sec.section_id
                LEFT JOIN rooms r ON s.room_id = r.room_id
                JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
                JOIN days d ON sd.day_id = d.day_id
                WHERE s.schedule_id = ?
                GROUP BY s.schedule_id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $scheduleId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            return [
                'success' => false,
                'message' => 'Schedule not found'
            ];
        }
        
        $row = $result->fetch_assoc();
        $schedule = [
            'id' => $row['schedule_id'],
            'school_year' => $row['school_year'],
            'semester' => $row['semester'],
            'schedule_type' => $row['schedule_type'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'subject' => [
                'code' => $row['subj_code'],
                'name' => $row['subj_name'],
                'description' => $row['subj_description']
            ],
            'professor' => [
                'id' => $row['prof_id'],
                'name' => $row['prof_name'],
                'email' => $row['prof_email']
            ],
            'section' => [
                'id' => $row['section_id'],
                'name' => $row['section_name'],
                'grade_level' => $row['grade_level'],
                'strand' => $row['strand']
            ],
            'room' => $row['room_id'] ? [
                'id' => $row['room_id'],
                'number' => $row['room_number'],
                'type' => $row['room_type'],
                'capacity' => $row['room_capacity']
            ] : null,
            'days' => explode(',', $row['days'])
        ];
        
        // Push to Firebase
        $response = $this->firebaseConfig->setData("schedules/{$scheduleId}", $schedule);
        
        return [
            'success' => $response['status'] === 200,
            'message' => $response['status'] === 200 ? 'Schedule synced successfully' : 'Failed to sync schedule',
            'firebase_response' => $response
        ];
    }
    
    // Sync all rooms to Firebase
    public function syncRooms() {
        $sql = "SELECT 
                    r.room_id,
                    r.room_number,
                    r.room_type,
                    r.room_capacity,
                    COUNT(DISTINCT s.schedule_id) as schedule_count,
                    GROUP_CONCAT(DISTINCT sec.section_id, ':', sec.section_name, ':', sec.grade_level, ':', sec.strand SEPARATOR '|') as sections
                FROM rooms r
                LEFT JOIN schedules s ON r.room_id = s.room_id
                LEFT JOIN section_room_assignments sra ON r.room_id = sra.room_id
                LEFT JOIN sections sec ON sra.section_id = sec.section_id
                GROUP BY r.room_id
                ORDER BY r.room_number";
        
        $result = $this->conn->query($sql);
        
        if (!$result) {
            return [
                'success' => false,
                'message' => 'Failed to fetch rooms: ' . $this->conn->error
            ];
        }
        
        $rooms = [];
        while ($row = $result->fetch_assoc()) {
            $sections = [];
            if ($row['sections']) {
                $sectionData = explode('|', $row['sections']);
                foreach ($sectionData as $section) {
                    $sectionParts = explode(':', $section);
                    if (count($sectionParts) >= 4) {
                        $sections[] = [
                            'id' => $sectionParts[0],
                            'name' => $sectionParts[1],
                            'grade_level' => $sectionParts[2],
                            'strand' => $sectionParts[3]
                        ];
                    }
                }
            }
            
            $room = [
                'id' => $row['room_id'],
                'number' => $row['room_number'],
                'type' => $row['room_type'],
                'capacity' => $row['room_capacity'],
                'schedule_count' => $row['schedule_count'],
                'sections' => $sections
            ];
            
            $rooms[] = $room;
        }
        
        // Push to Firebase
        $response = $this->firebaseConfig->setData('rooms', $rooms);
        
        return [
            'success' => $response['status'] === 200,
            'message' => $response['status'] === 200 ? 'Rooms synced successfully' : 'Failed to sync rooms',
            'count' => count($rooms),
            'firebase_response' => $response
        ];
    }
    
    // Sync all data to Firebase
    public function syncAll() {
        $results = [
            'schedules' => $this->syncSchedules(),
            'sections' => $this->syncSections(),
            'professors' => $this->syncProfessors(),
            'rooms' => $this->syncRooms()
        ];
        
        $allSuccess = true;
        $totalCount = 0;
        
        foreach ($results as $type => $result) {
            if (!$result['success']) {
                $allSuccess = false;
            }
            if (isset($result['count'])) {
                $totalCount += $result['count'];
            }
        }
        
        return [
            'success' => $allSuccess,
            'message' => $allSuccess ? 'All data synced successfully' : 'Some data failed to sync',
            'total_count' => $totalCount,
            'details' => $results
        ];
    }
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $sync = new FirebaseSync($firebaseConfig, $conn);
    
    $action = $data['action'] ?? 'sync_all';
    
    switch ($action) {
        case 'sync_schedules':
            $result = $sync->syncSchedules();
            break;
        case 'sync_sections':
            $result = $sync->syncSections();
            break;
        case 'sync_professors':
            $result = $sync->syncProfessors();
            break;
        case 'sync_rooms':
            $result = $sync->syncRooms();
            break;
        case 'sync_single_schedule':
            $scheduleId = $data['schedule_id'] ?? null;
            if (!$scheduleId) {
                $result = ['success' => false, 'message' => 'Schedule ID is required'];
            } else {
                $result = $sync->syncSingleSchedule($scheduleId);
            }
            break;
        case 'sync_all':
        default:
            $result = $sync->syncAll();
            break;
    }
    
    echo json_encode($result);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. Use POST.'
    ]);
}
?> 