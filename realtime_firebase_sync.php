<?php
/**
 * Real-time Firebase Sync System
 * Syncs PHP database changes to Firebase in real-time
 */

require_once 'firebase_realtime_config.php';
require_once 'connect.php';

class RealtimeFirebaseSync {
    private $firebase;
    private $db;
    
    public function __construct() {
        $this->firebase = new FirebaseRealtimeConfig();
        
        // Initialize database connection
        global $conn;
        if ($conn) {
            $this->db = $conn;
        } else {
            // Fallback: create new connection
            $this->db = new mysqli('localhost', 'root', '', 'spcc_database');
            if ($this->db->connect_error) {
                throw new Exception("Database connection failed: " . $this->db->connect_error);
            }
        }
    }
    
    /**
     * Sync all tables to Firebase
     */
    public function syncAllTables() {
        $results = [];
        
        try {
            $results['schedules'] = $this->syncSchedules();
            $results['professors'] = $this->syncProfessors();
            $results['subjects'] = $this->syncSubjects();
            $results['sections'] = $this->syncSections();
            $results['rooms'] = $this->syncRooms();
            
            return [
                'success' => true,
                'message' => 'All tables synced successfully',
                'results' => $results
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
                'results' => $results
            ];
        }
    }
    
    /**
     * Sync schedules table
     */
    public function syncSchedules() {
        $sql = "SELECT s.*, 
                       subj.subj_name, subj.subj_code,
                       p.prof_name as professor_name,
                       sect.section_name, sect.grade_level, sect.strand,
                       r.room_number, r.room_type
                FROM schedules s
                LEFT JOIN subjects subj ON s.subj_id = subj.subj_id
                LEFT JOIN professors p ON s.prof_id = p.prof_id
                LEFT JOIN sections sect ON s.section_id = sect.section_id
                LEFT JOIN rooms r ON s.room_id = r.room_id
                ORDER BY s.schedule_id DESC";
                
        $result = $this->db->query($sql);
        $schedules = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                // Parse days JSON
                if (isset($row['days'])) {
                    $row['days'] = json_decode($row['days'], true) ?: [];
                }
                
                $schedules[$row['schedule_id']] = [
                    'id' => (string)$row['schedule_id'],
                    'schedule_id' => (int)$row['schedule_id'],
                    'school_year' => $row['school_year'],
                    'semester' => $row['semester'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'days' => $row['days'],
                    'schedule_type' => $row['schedule_type'],
                    'created_at' => $row['created_at'],
                    // Mobile app expected structure
                    'professor' => [
                        'id' => (string)$row['prof_id'],
                        'name' => $row['professor_name']
                    ],
                    'subject' => [
                        'name' => $row['subj_name'],
                        'code' => $row['subj_code']
                    ],
                    'room' => $row['room_id'] ? [
                        'id' => (string)$row['room_id'],
                        'number' => $row['room_number'],
                        'type' => strtolower($row['room_type']),
                        'capacity' => 50 // Default capacity
                    ] : null,
                    'section' => [
                        'id' => (string)$row['section_id'],
                        'name' => $row['section_name'],
                        'grade_level' => $row['grade_level'],
                        'strand' => $row['strand']
                    ],
                    'synced_at' => date('c')
                ];
            }
        }
        
        // Sync to Firebase - main schedules collection
        $response = $this->firebase->makeRequest('schedules', 'PUT', $schedules);
        
        // Also sync professor-specific schedules for mobile app
        $professorSchedules = [];
        foreach ($schedules as $schedule) {
            $profId = $schedule['professor']['id'];
            if (!isset($professorSchedules[$profId])) {
                $professorSchedules[$profId] = [];
            }
            $professorSchedules[$profId][$schedule['id']] = $schedule;
        }
        
        // Sync each professor's schedules to prof_schedules/{firebase_uid}
        // Firebase Auth UIDs follow the pattern: prof_{professor_id}
        foreach ($professorSchedules as $profId => $profScheduleList) {
            $firebaseUid = "prof_{$profId}";
            $this->firebase->makeRequest("prof_schedules/{$firebaseUid}", 'PUT', $profScheduleList);
        }
        
        return [
            'table' => 'schedules',
            'count' => count($schedules),
            'firebase_response' => $response,
            'professor_schedules_synced' => count($professorSchedules)
        ];
    }
    
    /**
     * Sync professors table
     */
    public function syncProfessors() {
        $sql = "SELECT * FROM professors ORDER BY prof_id";
        $result = $this->db->query($sql);
        $professors = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $qualifications = json_decode($row['prof_qualifications'], true) ?: [];
                
                $professors[$row['prof_id']] = [
                    'id' => (int)$row['prof_id'],
                    'prof_id' => (int)$row['prof_id'],
                    'name' => $row['prof_name'],
                    'username' => $row['prof_username'],
                    'email' => $row['prof_email'],
                    'phone' => $row['prof_phone'],
                    'qualifications' => $qualifications,
                    'subject_count' => (int)$row['subj_count'],
                    'synced_at' => date('c')
                ];
            }
        }
        
        // Sync to Firebase
        $response = $this->firebase->makeRequest('professors', 'PUT', $professors);
        
        return [
            'table' => 'professors',
            'count' => count($professors),
            'firebase_response' => $response
        ];
    }
    
    /**
     * Sync subjects table
     */
    public function syncSubjects() {
        $sql = "SELECT * FROM subjects ORDER BY subj_id";
        $result = $this->db->query($sql);
        $subjects = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $subjects[$row['subj_id']] = [
                    'id' => (int)$row['subj_id'],
                    'subj_id' => (int)$row['subj_id'],
                    'code' => $row['subj_code'],
                    'name' => $row['subj_name'],
                    'description' => $row['subj_description'],
                    'units' => isset($row['subj_units']) ? (int)$row['subj_units'] : 3,
                    'type' => isset($row['subj_type']) ? $row['subj_type'] : 'Core',
                    'hours_per_week' => isset($row['subj_hours_per_week']) ? (int)$row['subj_hours_per_week'] : 3,
                    'synced_at' => date('c')
                ];
            }
        }
        
        // Sync to Firebase
        $response = $this->firebase->makeRequest('subjects', 'PUT', $subjects);
        
        return [
            'table' => 'subjects',
            'count' => count($subjects),
            'firebase_response' => $response
        ];
    }
    
    /**
     * Sync sections table
     */
    public function syncSections() {
        $sql = "SELECT * FROM sections ORDER BY section_id";
        $result = $this->db->query($sql);
        $sections = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $sections[$row['section_id']] = [
                    'id' => (int)$row['section_id'],
                    'section_id' => (int)$row['section_id'],
                    'name' => $row['section_name'],
                    'grade_level' => $row['grade_level'],
                    'strand' => $row['strand'],
                    'number_of_students' => (int)$row['number_of_students'],
                    'synced_at' => date('c')
                ];
            }
        }
        
        // Sync to Firebase
        $response = $this->firebase->makeRequest('sections', 'PUT', $sections);
        
        return [
            'table' => 'sections',
            'count' => count($sections),
            'firebase_response' => $response
        ];
    }
    
    /**
     * Sync rooms table
     */
    public function syncRooms() {
        $sql = "SELECT * FROM rooms ORDER BY room_id";
        $result = $this->db->query($sql);
        $rooms = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rooms[$row['room_id']] = [
                    'id' => (int)$row['room_id'],
                    'room_id' => (int)$row['room_id'],
                    'number' => (int)$row['room_number'],
                    'type' => $row['room_type'],
                    'capacity' => (int)$row['room_capacity'],
                    'synced_at' => date('c')
                ];
            }
        }
        
        // Sync to Firebase
        $response = $this->firebase->makeRequest('rooms', 'PUT', $rooms);
        
        return [
            'table' => 'rooms',
            'count' => count($rooms),
            'firebase_response' => $response
        ];
    }
    
    /**
     * Sync a single schedule by ID
     */
    public function syncSingleSchedule($scheduleId) {
        $sql = "SELECT s.*, 
                       subj.subj_name, subj.subj_code,
                       p.prof_name as professor_name,
                       sect.section_name, sect.grade_level, sect.strand,
                       r.room_number, r.room_type
                FROM schedules s
                LEFT JOIN subjects subj ON s.subj_id = subj.subj_id
                LEFT JOIN professors p ON s.prof_id = p.prof_id
                LEFT JOIN sections sect ON s.section_id = sect.section_id
                LEFT JOIN rooms r ON s.room_id = r.room_id
                WHERE s.schedule_id = ?";
                
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $scheduleId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Parse days JSON
            if (isset($row['days'])) {
                $row['days'] = json_decode($row['days'], true) ?: [];
            }
            
            $schedule = [
                'id' => (string)$row['schedule_id'],
                'schedule_id' => (int)$row['schedule_id'],
                'school_year' => $row['school_year'],
                'semester' => $row['semester'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'days' => $row['days'],
                'schedule_type' => $row['schedule_type'],
                'created_at' => $row['created_at'],
                // Mobile app expected structure
                'professor' => [
                    'id' => (string)$row['prof_id'],
                    'name' => $row['professor_name']
                ],
                'subject' => [
                    'name' => $row['subj_name'],
                    'code' => $row['subj_code']
                ],
                'room' => $row['room_id'] ? [
                    'id' => (string)$row['room_id'],
                    'number' => $row['room_number'],
                    'type' => strtolower($row['room_type']),
                    'capacity' => 50 // Default capacity
                ] : null,
                'section' => [
                    'id' => (string)$row['section_id'],
                    'name' => $row['section_name'],
                    'grade_level' => $row['grade_level'],
                    'strand' => $row['strand']
                ],
                'synced_at' => date('c')
            ];
            
            // Sync to Firebase - main schedules collection
            $response = $this->firebase->makeRequest("schedules/{$scheduleId}", 'PUT', $schedule);
            
            // Also sync to professor-specific collection
            $profId = $schedule['professor']['id'];
            $firebaseUid = "prof_{$profId}";
            $profResponse = $this->firebase->makeRequest("prof_schedules/{$firebaseUid}/{$scheduleId}", 'PUT', $schedule);
            
            return [
                'success' => $response['success'],
                'schedule_id' => $scheduleId,
                'firebase_response' => $response,
                'professor_sync' => $profResponse['success']
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Schedule not found',
            'schedule_id' => $scheduleId
        ];
    }
    
    /**
     * Delete a schedule from Firebase
     */
    public function deleteSingleSchedule($scheduleId) {
        // First get the schedule to find the professor ID
        $scheduleResponse = $this->firebase->makeRequest("schedules/{$scheduleId}", 'GET');
        
        // Delete from main schedules collection
        $response = $this->firebase->makeRequest("schedules/{$scheduleId}", 'DELETE');
        
        // Also delete from professor-specific collection if we found the professor ID
        $profResponse = null;
        if ($scheduleResponse['success'] && isset($scheduleResponse['data']['professor']['id'])) {
            $profId = $scheduleResponse['data']['professor']['id'];
            $firebaseUid = "prof_{$profId}";
            $profResponse = $this->firebase->makeRequest("prof_schedules/{$firebaseUid}/{$scheduleId}", 'DELETE');
        }
        
        return [
            'success' => $response['success'],
            'schedule_id' => $scheduleId,
            'firebase_response' => $response,
            'professor_delete' => $profResponse ? $profResponse['success'] : null
        ];
    }
}
?>
