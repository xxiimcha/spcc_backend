<?php
// Enable CORS
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

include 'connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get JSON data from request
    $data = json_decode(file_get_contents('php://input'),    true);
    
    if (!$data) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON data'
        ]);
        exit;
    }

    // Validate required fields
    $required_fields = ['days', 'startTime', 'endTime', 'professorId', 'room', 'section'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            echo json_encode([
                'success' => false,
                'message' => "Missing required field: $field"
            ]);
            exit;
        }
    }

    try {
        // Initialize response data
        $response = [
            'success' => true,
            'isValid' => true,
            'hasConflicts' => false,
            'conflicts' => [
                'professorConflicts' => [],
                'roomConflicts' => [],
                'sectionConflicts' => [],
                'subjectConflicts' => [],
                'professorWorkload' => [
                    'currentLoad' => 0,
                    'maxLoad' => 8,
                    'isOverloaded' => false
                ],
                'alternativeProfessors' => [],
                'alternativeTimes' => []
            ]
        ];

        // Check professor workload
        $workloadResult = checkProfessorWorkload($conn, $data['professorId']);
        $response['conflicts']['professorWorkload'] = $workloadResult;
        
        if ($workloadResult['isOverloaded']) {
            $response['conflicts']['alternativeProfessors'] = getAlternativeProfessors($conn, $data['professorId']);
            $response['hasConflicts'] = true;
            $response['isValid'] = false;
        }

        // Check for professor conflicts
        $professor_conflicts = checkProfessorConflicts($conn, $data);
        $response['conflicts']['professorConflicts'] = $professor_conflicts;
        if (!empty($professor_conflicts)) {
            $response['hasConflicts'] = true;
            $response['isValid'] = false;
        }

        // Check for room conflicts
        $room_conflicts = checkRoomConflicts($conn, $data);
        $response['conflicts']['roomConflicts'] = $room_conflicts;
        if (!empty($room_conflicts)) {
            $response['conflicts']['alternativeTimes'] = getAlternativeTimeSlots($conn, $data);
            $response['hasConflicts'] = true;
            $response['isValid'] = false;
        }

        // Check for section conflicts
        $section_conflicts = checkSectionConflicts($conn, $data);
        $response['conflicts']['sectionConflicts'] = $section_conflicts;
        if (!empty($section_conflicts)) {
            $response['hasConflicts'] = true;
            $response['isValid'] = false;
        }

        // Check for subject conflicts
        $subject_conflicts = checkSubjectConflicts($conn, $data);
        $response['conflicts']['subjectConflicts'] = $subject_conflicts;
        if (!empty($subject_conflicts)) {
            $response['hasConflicts'] = true;
            $response['isValid'] = false;
        }

        echo json_encode($response);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'isValid' => false,
            'message' => 'Error validating time slots: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

function checkProfessorWorkload($conn, $professorId) {
    $sql = "SELECT subj_count, prof_name FROM professors WHERE prof_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $professorId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $isOverloaded = (int)$row['subj_count'] >= 8;
        return [
            'currentLoad' => (int)$row['subj_count'],
            'maxLoad' => 8,
            'isOverloaded' => $isOverloaded,
            'conflictType' => $isOverloaded ? 'PROFESSOR_OVERLOAD' : null,
            'conflictMessage' => $isOverloaded ? "Professor {$row['prof_name']} is already overloaded with {$row['subj_count']} subjects (maximum is 8)" : null
        ];
    }
    
    return [
        'currentLoad' => 0,
        'maxLoad' => 8,
        'isOverloaded' => false,
        'conflictType' => null,
        'conflictMessage' => null
    ];
}

function getAlternativeProfessors($conn, $currentProfessorId) {
    $sql = "SELECT prof_id, prof_name, subj_count 
            FROM professors 
            WHERE prof_id != ? AND subj_count < 8 
            ORDER BY subj_count ASC 
            LIMIT 5";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $currentProfessorId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $alternatives = [];
    while ($row = $result->fetch_assoc()) {
        $alternatives[] = [
            'id' => $row['prof_id'],
            'name' => $row['prof_name'],
            'currentLoad' => (int)$row['subj_count']
        ];
    }
    
    return $alternatives;
}

function checkProfessorConflicts($conn, $data) {
    $start_time = $data['startTime'];
    $end_time = $data['endTime'];
    $professor_id = $data['professorId'];
    $conflicts = [];

    $sql = "SELECT s.*, sd.day_id, d.day_name, subj.subj_code, subj.subj_name, sec.section_name
            FROM schedules s
            JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
            JOIN days d ON sd.day_id = d.day_id
            JOIN subjects subj ON s.subj_id = subj.subj_id
            JOIN sections sec ON s.section_id = sec.section_id
            WHERE s.prof_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $professor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (in_array($row['day_name'], $data['days'])) {
            if (($start_time >= $row['start_time'] && $start_time < $row['end_time']) ||
                ($end_time > $row['start_time'] && $end_time <= $row['end_time']) ||
                ($start_time <= $row['start_time'] && $end_time >= $row['end_time'])) {
                $conflicts[] = [
                    'id' => $row['schedule_id'],
                    'startTime' => $row['start_time'],
                    'endTime' => $row['end_time'],
                    'subjectCode' => $row['subj_code'],
                    'subjectName' => $row['subj_name'],
                    'section' => $row['section_name'],
                    'day' => $row['day_name'],
                    'conflictType' => 'PROFESSOR_SCHEDULE_CONFLICT',
                    'conflictMessage' => "Professor has a conflicting schedule with {$row['subjectCode']} ({$row['subjectName']}) for section {$row['section_name']} on {$row['day_name']} at {$row['start_time']} - {$row['end_time']}"
                ];
            }
        }
    }

    return $conflicts;
}

function checkRoomConflicts($conn, $data) {
    $start_time = $data['startTime'];
    $end_time = $data['endTime'];
    $room_id = $data['room'];
    $conflicts = [];

    $sql = "SELECT s.*, sd.day_id, d.day_name, subj.subj_code, subj.subj_name, 
            sec.section_name, p.prof_name as professor_name
            FROM schedules s
            JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
            JOIN days d ON sd.day_id = d.day_id
            JOIN subjects subj ON s.subj_id = subj.subj_id
            JOIN sections sec ON s.section_id = sec.section_id
            JOIN professors p ON s.prof_id = p.prof_id
            WHERE s.room_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (in_array($row['day_name'], $data['days'])) {
            if (($start_time >= $row['start_time'] && $start_time < $row['end_time']) ||
                ($end_time > $row['start_time'] && $end_time <= $row['end_time']) ||
                ($start_time <= $row['start_time'] && $end_time >= $row['end_time'])) {
                $conflicts[] = [
                    'id' => $row['schedule_id'],
                    'startTime' => $row['start_time'],
                    'endTime' => $row['end_time'],
                    'subjectCode' => $row['subj_code'],
                    'subjectName' => $row['subj_name'],
                    'section' => $row['section_name'],
                    'professorName' => $row['professor_name'],
                    'day' => $row['day_name'],
                    'conflictType' => 'ROOM_OCCUPIED',
                    'conflictMessage' => "Room is occupied by {$row['subjectCode']} ({$row['subjectName']}) for section {$row['section_name']} with Prof. {$row['professor_name']} on {$row['day_name']} at {$row['start_time']} - {$row['end_time']}"
                ];
            }
        }
    }

    return $conflicts;
}

function checkSectionConflicts($conn, $data) {
    $start_time = $data['startTime'];
    $end_time = $data['endTime'];
    $section_id = $data['section'];
    $conflicts = [];

    $sql = "SELECT s.*, sd.day_id, d.day_name, subj.subj_code, subj.subj_name, 
            p.prof_name as professor_name
            FROM schedules s
            JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
            JOIN days d ON sd.day_id = d.day_id
            JOIN subjects subj ON s.subj_id = subj.subj_id
            JOIN professors p ON s.prof_id = p.prof_id
            WHERE s.section_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (in_array($row['day_name'], $data['days'])) {
            if (($start_time >= $row['start_time'] && $start_time < $row['end_time']) ||
                ($end_time > $row['start_time'] && $end_time <= $row['end_time']) ||
                ($start_time <= $row['start_time'] && $end_time >= $row['end_time'])) {
                $conflicts[] = [
                    'id' => $row['schedule_id'],
                    'startTime' => $row['start_time'],
                    'endTime' => $row['end_time'],
                    'subjectCode' => $row['subj_code'],
                    'subjectName' => $row['subj_name'],
                    'professorName' => $row['professor_name'],
                    'day' => $row['day_name'],
                    'conflictType' => 'SECTION_SCHEDULE_CONFLICT',
                    'conflictMessage' => "Section has a conflict with {$row['subjectCode']} ({$row['subjectName']}) taught by Prof. {$row['professor_name']} on {$row['day_name']} at {$row['start_time']} - {$row['end_time']}"
                ];
            }
        }
    }

    return $conflicts;
}

function getAlternativeTimeSlots($conn, $data) {
    $timeSlots = [];
    $startTime = strtotime('07:00');
    $endTime = strtotime('16:30');
    $interval = 60 * 60; // Changed to 1 hour in seconds
    $duration = strtotime($data['endTime']) - strtotime($data['startTime']); // Duration of requested slot

    // Get busy times for the room
    $busyTimes = getBusyTimes($conn, $data['room'], $data['days']);
    
    // Get all existing schedules for professor and section to avoid suggesting them
    $professorSchedules = getProfessorSchedules($conn, $data['professorId']);
    $sectionSchedules = getSectionSchedules($conn, $data['section']);

    foreach ($data['days'] as $day) {
        $dayBusyTimes = array_filter($busyTimes, function($time) use ($day) {
            return $time['day'] === $day;
        });

        // Filter professor's existing schedules for this day
        $professorDaySchedules = array_filter($professorSchedules, function($sched) use ($day) {
            return in_array($day, $sched['days']);
        });
        
        // Filter section's existing schedules for this day
        $sectionDaySchedules = array_filter($sectionSchedules, function($sched) use ($day) {
            return in_array($day, $sched['days']);
        });

        $currentTime = $startTime;
        while ($currentTime + $duration <= $endTime) {
            $slotStart = date('H:i', $currentTime);
            $slotEnd = date('H:i', $currentTime + $duration);
            
            // Check if this slot conflicts with room busy times
            $isAvailable = true;
            foreach ($dayBusyTimes as $busyTime) {
                $busyStart = strtotime($busyTime['start']);
                $busyEnd = strtotime($busyTime['end']);
                
                if (($currentTime >= $busyStart && $currentTime < $busyEnd) ||
                    ($currentTime + $duration > $busyStart && $currentTime + $duration <= $busyEnd) ||
                    ($currentTime <= $busyStart && $currentTime + $duration >= $busyEnd)) {
                    $isAvailable = false;
                    break;
                }
            }
            
            if ($isAvailable) {
                // Check if this slot would conflict with professor's existing schedule
                foreach ($professorDaySchedules as $sched) {
                    $schedStart = strtotime($sched['startTime']);
                    $schedEnd = strtotime($sched['endTime']);
                    
                    if (($currentTime >= $schedStart && $currentTime < $schedEnd) ||
                        ($currentTime + $duration > $schedStart && $currentTime + $duration <= $schedEnd) ||
                        ($currentTime <= $schedStart && $currentTime + $duration >= $schedEnd)) {
                        $isAvailable = false;
                        break;
                    }
                }
                
                // Check if this slot would conflict with section's existing schedule
                foreach ($sectionDaySchedules as $sched) {
                    $schedStart = strtotime($sched['startTime']);
                    $schedEnd = strtotime($sched['endTime']);
                    
                    if (($currentTime >= $schedStart && $currentTime < $schedEnd) ||
                        ($currentTime + $duration > $schedStart && $currentTime + $duration <= $schedEnd) ||
                        ($currentTime <= $schedStart && $currentTime + $duration >= $schedEnd)) {
                        $isAvailable = false;
                        break;
                    }
                }
                
                if ($isAvailable) {
                    $timeSlots[] = [
                        'day' => $day,
                        'startTime' => $slotStart,
                        'endTime' => $slotEnd,
                        'isAvailable' => true
                    ];
                }
            }
            
            $currentTime += $interval;
        }
    }
    
    // Sort time slots by day and start time
    usort($timeSlots, function($a, $b) {
        $dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $dayCompare = array_search($a['day'], $dayOrder) - array_search($b['day'], $dayOrder);
        if ($dayCompare !== 0) return $dayCompare;
        return strtotime($a['startTime']) - strtotime($b['startTime']);
    });
    
    // Return only the first 5 available slots
    return array_slice($timeSlots, 0, 5);
}

function getProfessorSchedules($conn, $professorId) {
    $sql = "SELECT s.schedule_id, TIME_FORMAT(s.start_time, '%H:%i') as start_time, 
                   TIME_FORMAT(s.end_time, '%H:%i') as end_time,
                   GROUP_CONCAT(d.day_name) as days
            FROM schedules s
            JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
            JOIN days d ON sd.day_id = d.day_id
            WHERE s.prof_id = ?
            GROUP BY s.schedule_id, s.start_time, s.end_time";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $professorId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $schedules = [];
    while ($row = $result->fetch_assoc()) {
        $schedules[] = [
            'startTime' => $row['start_time'],
            'endTime' => $row['end_time'],
            'days' => explode(',', $row['days'])
        ];
    }
    
    return $schedules;
}

function getSectionSchedules($conn, $sectionId) {
    $sql = "SELECT s.schedule_id, TIME_FORMAT(s.start_time, '%H:%i') as start_time, 
                   TIME_FORMAT(s.end_time, '%H:%i') as end_time,
                   GROUP_CONCAT(d.day_name) as days
            FROM schedules s
            JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
            JOIN days d ON sd.day_id = d.day_id
            WHERE s.section_id = ?
            GROUP BY s.schedule_id, s.start_time, s.end_time";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $schedules = [];
    while ($row = $result->fetch_assoc()) {
        $schedules[] = [
            'startTime' => $row['start_time'],
            'endTime' => $row['end_time'],
            'days' => explode(',', $row['days'])
        ];
    }
    
    return $schedules;
}


function getBusyTimes($conn, $roomId, $days) {
    $formattedDays = "'" . implode("','", $days) . "'";
    $sql = "SELECT TIME_FORMAT(s.start_time, '%H:%i') as start_time,
                   TIME_FORMAT(s.end_time, '%H:%i') as end_time,
                   d.day_name
            FROM schedules s
            JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
            JOIN days d ON sd.day_id = d.day_id
            WHERE s.room_id = ? AND d.day_name IN ($formattedDays)
            ORDER BY s.start_time";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $busyTimes = [];
    while ($row = $result->fetch_assoc()) {
        $busyTimes[] = [
            'start' => $row['start_time'],
            'end' => $row['end_time'],
            'day' => $row['day_name']
        ];
    }
    
    return $busyTimes;
}

function checkSubjectConflicts($conn, $data) {
    if (!isset($data['subjectId'])) {
        return [];
    }

    $start_time = $data['startTime'];
    $end_time = $data['endTime'];
    $section_id = $data['section'];
    $subject_id = $data['subjectId'];
    $conflicts = [];

    $sql = "SELECT s.*, sd.day_id, d.day_name, subj.subj_code, subj.subj_name, 
            p.prof_name as professor_name
            FROM schedules s
            JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
            JOIN days d ON sd.day_id = d.day_id
            JOIN subjects subj ON s.subj_id = subj.subj_id
            JOIN professors p ON s.prof_id = p.prof_id
            WHERE s.section_id = ? AND s.subj_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $section_id, $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (in_array($row['day_name'], $data['days'])) {
            if (($start_time >= $row['start_time'] && $start_time < $row['end_time']) ||
                ($end_time > $row['start_time'] && $end_time <= $row['end_time']) ||
                ($start_time <= $row['start_time'] && $end_time >= $row['end_time'])) {
                $conflicts[] = [
                    'id' => $row['schedule_id'],
                    'startTime' => $row['start_time'],
                    'endTime' => $row['end_time'],
                    'subjectCode' => $row['subj_code'],
                    'subjectName' => $row['subj_name'],
                    'professorName' => $row['professor_name'],
                    'day' => $row['day_name'],
                    'conflictType' => 'DUPLICATE_SUBJECT',
                    'conflictMessage' => "Section already has {$row['subjectCode']} ({$row['subjectName']}) scheduled with Prof. {$row['professor_name']} on {$row['day_name']} at {$row['start_time']} - {$row['end_time']}"
                ];
            }
        }
    }

    return $conflicts;
}

$conn->close();
?>
