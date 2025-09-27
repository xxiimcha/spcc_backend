<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "config.php";

// Function to check for conflicts
function checkConflicts($conn, $schedule) {
    $conflicts = [];
    
    // Check for room conflicts
    $roomQuery = "SELECT * FROM schedules 
                 WHERE room_id = ? 
                 AND school_year = ? 
                 AND semester = ?
                 AND days && ? 
                 AND ((start_time, end_time) OVERLAPS (?, ?))";
    
    $stmt = $conn->prepare($roomQuery);
    $stmt->bind_param("isssss", 
        $schedule['room_id'],
        $schedule['school_year'],
        $schedule['semester'],
        $schedule['days'],
        $schedule['start_time'],
        $schedule['end_time']
    );
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $conflicts[] = [
            'type' => 'room',
            'message' => "Room conflict detected for room {$schedule['room_id']}"
        ];
    }
    
    // Check for professor conflicts
    $profQuery = "SELECT * FROM schedules 
                 WHERE prof_id = ? 
                 AND school_year = ? 
                 AND semester = ?
                 AND days && ? 
                 AND ((start_time, end_time) OVERLAPS (?, ?))";
    
    $stmt = $conn->prepare($profQuery);
    $stmt->bind_param("isssss", 
        $schedule['prof_id'],
        $schedule['school_year'],
        $schedule['semester'],
        $schedule['days'],
        $schedule['start_time'],
        $schedule['end_time']
    );
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $conflicts[] = [
            'type' => 'professor',
            'message' => "Professor conflict detected for professor {$schedule['prof_id']}"
        ];
    }
    
    return $conflicts;
}

// Function to generate time slots
function generateTimeSlots($startTime, $endTime, $duration = 60) {
    $slots = [];
    $current = strtotime($startTime);
    $end = strtotime($endTime);
    
    while ($current < $end) {
        $slots[] = [
            'start' => date('H:i:s', $current),
            'end' => date('H:i:s', $current + $duration * 60)
        ];
        $current += $duration * 60;
    }
    
    return $slots;
}

// Function to get available rooms
function getAvailableRooms($conn, $timeSlot, $day, $schoolYear, $semester) {
    $query = "SELECT r.* FROM rooms r 
              WHERE r.room_id NOT IN (
                  SELECT s.room_id FROM schedules s 
                  WHERE s.school_year = ? 
                  AND s.semester = ? 
                  AND ? = ANY(s.days)
                  AND ((s.start_time, s.end_time) OVERLAPS (?, ?))
              )";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssss", 
        $schoolYear,
        $semester,
        $day,
        $timeSlot['start'],
        $timeSlot['end']
    );
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Function to get available professors
function getAvailableProfessors($conn, $timeSlot, $day, $schoolYear, $semester) {
    $query = "SELECT p.* FROM professors p 
              WHERE p.prof_id NOT IN (
                  SELECT s.prof_id FROM schedules s 
                  WHERE s.school_year = ? 
                  AND s.semester = ? 
                  AND ? = ANY(s.days)
                  AND ((s.start_time, s.end_time) OVERLAPS (?, ?))
              )";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssss", 
        $schoolYear,
        $semester,
        $day,
        $timeSlot['start'],
        $timeSlot['end']
    );
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $schoolYear = $data['school_year'];
    $semester = $data['semester'];
    $level = $data['level'];
    $strand = $data['strand'];
    $startTime = $data['start_time'];
    $endTime = $data['end_time'];
    $maxSubjectsPerDay = $data['max_subjects_per_day'];
    $preferredDays = $data['preferred_days'];
    
    // Get all subjects for the level and strand
    $subjectsQuery = "SELECT * FROM subjects WHERE level = ? AND strand = ?";
    $stmt = $conn->prepare($subjectsQuery);
    $stmt->bind_param("ss", $level, $strand);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $generatedSchedules = [];
    $conflicts = [];
    
    // Generate time slots
    $timeSlots = generateTimeSlots($startTime, $endTime);
    
    // For each subject
    foreach ($subjects as $subject) {
        $scheduled = false;
        
        // Try each day
        foreach ($preferredDays as $day) {
            if ($scheduled) break;
            
            // Try each time slot
            foreach ($timeSlots as $timeSlot) {
                if ($scheduled) break;
                
                // Get available rooms
                $availableRooms = getAvailableRooms($conn, $timeSlot, $day, $schoolYear, $semester);
                
                // Get available professors
                $availableProfessors = getAvailableProfessors($conn, $timeSlot, $day, $schoolYear, $semester);
                
                if (!empty($availableRooms) && !empty($availableProfessors)) {
                    // Select a random room and professor
                    $room = $availableRooms[array_rand($availableRooms)];
                    $professor = $availableProfessors[array_rand($availableProfessors)];
                    
                    $schedule = [
                        'school_year' => $schoolYear,
                        'semester' => $semester,
                        'subj_id' => $subject['subj_id'],
                        'prof_id' => $professor['prof_id'],
                        'room_id' => $room['room_id'],
                        'start_time' => $timeSlot['start'],
                        'end_time' => $timeSlot['end'],
                        'days' => [$day]
                    ];
                    
                    // Check for conflicts
                    $scheduleConflicts = checkConflicts($conn, $schedule);
                    
                    if (empty($scheduleConflicts)) {
                        // Insert the schedule
                        $insertQuery = "INSERT INTO schedules (
                            school_year, semester, subj_id, prof_id, room_id,
                            start_time, end_time, days
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $conn->prepare($insertQuery);
                        $daysJson = json_encode($schedule['days']);
                        $stmt->bind_param("ssssssss",
                            $schedule['school_year'],
                            $schedule['semester'],
                            $schedule['subj_id'],
                            $schedule['prof_id'],
                            $schedule['room_id'],
                            $schedule['start_time'],
                            $schedule['end_time'],
                            $daysJson
                        );
                        
                        if ($stmt->execute()) {
                            $generatedSchedules[] = $schedule;
                            $scheduled = true;
                        }
                    } else {
                        $conflicts = array_merge($conflicts, $scheduleConflicts);
                    }
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'schedules' => $generatedSchedules,
        'conflicts' => $conflicts
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
?>