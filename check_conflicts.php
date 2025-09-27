<?php
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include 'connect.php';

// Get request body
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid input data"
    ]);
    exit;
}

// Extract schedule details
$professorId = $conn->real_escape_string($data['professorId']);
$room = isset($data['room']) ? $conn->real_escape_string($data['room']) : null;
$section = $conn->real_escape_string($data['section']);
$subjectId = isset($data['subjectId']) ? $conn->real_escape_string($data['subjectId']) : null;
$days = $data['days'];
$startTime = $conn->real_escape_string($data['startTime']);
$endTime = $conn->real_escape_string($data['endTime']);
$scheduleType = $conn->real_escape_string($data['scheduleType']);
$schoolYear = isset($data['school_year']) ? $conn->real_escape_string($data['school_year']) : null;
$semester = isset($data['semester']) ? $conn->real_escape_string($data['semester']) : null;

// Initialize conflicts array with default values
$conflicts = [
    "professorConflicts" => [],
    "roomConflicts" => [],
    "sectionConflicts" => [],
    "subjectConflicts" => [],
    "professorWorkload" => [
        "currentLoad" => 0,
        "maxLoad" => 8,
        "isOverloaded" => false
    ],
    "alternativeProfessors" => [],
    "alternativeTimes" => [],
    "alternativeRooms" => []
];

// Format days for SQL IN clause
$formattedDays = "'" . implode("','", $days) . "'";

// Helper function to get available time slots
function getAvailableTimeSlots($conn, $day, $schoolYear, $semester) {
    $query = "SELECT start_time, end_time FROM schedules 
              WHERE school_year = ? 
              AND semester = ? 
              AND ? = ANY(days)
              ORDER BY start_time";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sss", $schoolYear, $semester, $day);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Helper function to get available rooms
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
        $timeSlot['start_time'],
        $timeSlot['end_time']
    );
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Helper function to get available professors
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
        $timeSlot['start_time'],
        $timeSlot['end_time']
    );
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Check professor workload
$workloadQuery = "SELECT subj_count FROM professors WHERE prof_id = '$professorId'";
$result = $conn->query($workloadQuery);

if ($result && $row = $result->fetch_assoc()) {
    $conflicts["professorWorkload"] = [
        "currentLoad" => (int)$row['subj_count'],
        "maxLoad" => 8,
        "isOverloaded" => (int)$row['subj_count'] >= 8
    ];

    // If professor is overloaded, suggest alternatives
    if ((int)$row['subj_count'] >= 8) {
        $alternativeQuery = "SELECT prof_id, prof_name, subj_count 
                           FROM professors 
                           WHERE prof_id != '$professorId' 
                           AND subj_count < 8 
                           ORDER BY subj_count ASC 
                           LIMIT 5";
        
        $altResult = $conn->query($alternativeQuery);
        if ($altResult) {
            while ($altRow = $altResult->fetch_assoc()) {
                $conflicts["alternativeProfessors"][] = [
                    "id" => $altRow['prof_id'],
                    "name" => $altRow['prof_name'],
                    "currentLoad" => (int)$altRow['subj_count']
                ];
            }
        }
    }
}

// Check professor conflicts
$professorQuery = "SELECT 
    s.schedule_id, 
    s.start_time, 
    s.end_time, 
    subj.subj_code,
    subj.subj_name,
    sec.section_name,
    d.day_name
FROM schedules s
JOIN subjects subj ON s.subj_id = subj.subj_id
JOIN sections sec ON s.section_id = sec.section_id
JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
JOIN days d ON sd.day_id = d.day_id
WHERE 
    s.prof_id = '$professorId' AND 
    d.day_name IN ($formattedDays) AND
    ((s.start_time <= '$endTime' AND s.end_time >= '$startTime'))";

$result = $conn->query($professorQuery);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $conflicts["professorConflicts"][] = [
            "conflictType" => "PROFESSOR_SCHEDULE_CONFLICT",
            "conflictMessage" => "Professor has a conflict with {$row['subj_code']} ({$row['subj_name']}) for section {$row['section_name']} on {$row['day_name']} {$row['start_time']}-{$row['end_time']}.",
            "id" => $row['schedule_id'],
            "startTime" => $row['start_time'],
            "endTime" => $row['end_time'],
            "subjectCode" => $row['subj_code'],
            "subjectName" => $row['subj_name'],
            "section" => $row['section_name'],
            "day" => $row['day_name']
        ];
    }
}

// Check room conflicts (only if onsite)
if ($scheduleType === "Onsite" && !empty($room)) {
    // First verify room is assigned to section
    $roomAssignmentCheck = "SELECT 1 FROM section_room_assignments WHERE section_id = '$section' AND room_id = '$room'";
    $result = $conn->query($roomAssignmentCheck);
    
    if ($result->num_rows === 0) {
        $conflicts["roomConflicts"][] = [
            "conflictType" => "ROOM_NOT_ASSIGNED",
            "conflictMessage" => "The selected room is not assigned to this section.",
            "error" => true
        ];
    } else {
        $roomQuery = "SELECT 
            s.schedule_id, 
            s.start_time, 
            s.end_time, 
            subj.subj_code,
            subj.subj_name,
            sec.section_name,
            p.prof_name AS professor_name,
            d.day_name
        FROM schedules s
        JOIN subjects subj ON s.subj_id = subj.subj_id
        JOIN professors p ON s.prof_id = p.prof_id
        JOIN sections sec ON s.section_id = sec.section_id
        JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
        JOIN days d ON sd.day_id = d.day_id
        WHERE 
            s.room_id = '$room' AND 
            d.day_name IN ($formattedDays) AND
            ((s.start_time <= '$endTime' AND s.end_time >= '$startTime'))";

        $result = $conn->query($roomQuery);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $conflicts["roomConflicts"][] = [
                    "conflictType" => "ROOM_OCCUPIED",
                    "conflictMessage" => "Room is occupied by {$row['subj_code']} ({$row['subj_name']}) for section {$row['section_name']} with {$row['professor_name']} on {$row['day_name']} {$row['start_time']}-{$row['end_time']}.",
                    "id" => $row['schedule_id'],
                    "startTime" => $row['start_time'],
                    "endTime" => $row['end_time'],
                    "subjectCode" => $row['subj_code'],
                    "subjectName" => $row['subj_name'],
                    "section" => $row['section_name'],
                    "professorName" => $row['professor_name'],
                    "day" => $row['day_name']
                ];
            }
        }

        // Get alternative rooms if there are conflicts
        if (!empty($conflicts["roomConflicts"]) && $schoolYear && $semester) {
            $timeSlot = [
                'start_time' => $startTime,
                'end_time' => $endTime
            ];
            $alternativeRooms = getAvailableRooms($conn, $timeSlot, $days[0], $schoolYear, $semester);
            $conflicts["alternativeRooms"] = $alternativeRooms;
        }
    }
}

// Check section conflicts
$sectionQuery = "SELECT 
    s.schedule_id, 
    s.start_time, 
    s.end_time, 
    subj.subj_code,
    subj.subj_name,
    p.prof_name AS professor_name,
    d.day_name
FROM schedules s
JOIN subjects subj ON s.subj_id = subj.subj_id
JOIN professors p ON s.prof_id = p.prof_id
JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
JOIN days d ON sd.day_id = d.day_id
WHERE 
    s.section_id = '$section' AND 
    d.day_name IN ($formattedDays) AND
    ((s.start_time <= '$endTime' AND s.end_time >= '$startTime'))";

$result = $conn->query($sectionQuery);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $conflicts["sectionConflicts"][] = [
            "conflictType" => "SECTION_SCHEDULE_CONFLICT",
            "conflictMessage" => "Section has a conflict with {$row['subj_code']} ({$row['subj_name']}) with {$row['professor_name']} on {$row['day_name']} {$row['start_time']}-{$row['end_time']}.",
            "id" => $row['schedule_id'],
            "startTime" => $row['start_time'],
            "endTime" => $row['end_time'],
            "subjectCode" => $row['subj_code'],
            "subjectName" => $row['subj_name'],
            "professorName" => $row['professor_name'],
            "day" => $row['day_name']
        ];
    }
}

// Check duplicate subject in section
if ($subjectId) {
    $subjectQuery = "SELECT 
        s.schedule_id, 
        s.start_time, 
        s.end_time, 
        subj.subj_code,
        subj.subj_name,
        p.prof_name AS professor_name,
        d.day_name
    FROM schedules s
    JOIN subjects subj ON s.subj_id = subj.subj_id
    JOIN professors p ON s.prof_id = p.prof_id
    JOIN schedule_days sd ON s.schedule_id = sd.schedule_id
    JOIN days d ON sd.day_id = d.day_id
    WHERE 
        s.section_id = '$section' AND 
        s.subj_id = '$subjectId' AND
        d.day_name IN ($formattedDays)";

    $result = $conn->query($subjectQuery);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $conflicts["subjectConflicts"][] = [
                "conflictType" => "DUPLICATE_SUBJECT",
                "conflictMessage" => "Section already has {$row['subj_code']} ({$row['subj_name']}) scheduled with {$row['professor_name']} on {$row['day_name']} {$row['start_time']}-{$row['end_time']}.",
                "id" => $row['schedule_id'],
                "startTime" => $row['start_time'],
                "endTime" => $row['end_time'],
                "subjectCode" => $row['subj_code'],
                "subjectName" => $row['subj_name'],
                "professorName" => $row['professor_name'],
                "day" => $row['day_name']
            ];
        }
    }
}

// Check for time slot conflicts if school year and semester are provided
if ($schoolYear && $semester) {
    $timeSlots = getAvailableTimeSlots($conn, $days[0], $schoolYear, $semester);
    $hasTimeConflict = false;
    
    foreach ($timeSlots as $slot) {
        if (
            ($startTime >= $slot['start_time'] && $startTime < $slot['end_time']) ||
            ($endTime > $slot['start_time'] && $endTime <= $slot['end_time'])
        ) {
            $hasTimeConflict = true;
            break;
        }
    }
    
    if ($hasTimeConflict) {
        $conflicts["timeConflicts"] = [
            "conflictType" => "TIME_SLOT_CONFLICT",
            "conflictMessage" => "Time slot overlaps with existing schedules",
            "severity" => "warning",
            "recommendations" => [
                "Consider scheduling during off-peak hours",
                "Adjust the duration of the class",
                "Split the class into multiple sessions"
            ]
        ];
    }
}

// Calculate total conflicts
$hasConflicts = !empty($conflicts["professorConflicts"]) || 
                !empty($conflicts["roomConflicts"]) || 
                !empty($conflicts["sectionConflicts"]) ||
                !empty($conflicts["subjectConflicts"]) ||
                !empty($conflicts["timeConflicts"]) ||
                ($conflicts["professorWorkload"] && $conflicts["professorWorkload"]["isOverloaded"]);

// Return response
echo json_encode([
    "success" => true,
    "isValid" => !$hasConflicts,
    "hasConflicts" => $hasConflicts,
    "conflicts" => $conflicts
]);

$conn->close();

// Helper function to generate alternative time slots
function generateAlternativeTimeSlots($busyTimes, $days) {
    $timeSlots = [];
    $startTime = strtotime('07:00');
    $endTime = strtotime('16:30');
    $interval = 30 * 60; // 30 minutes in seconds

    foreach ($days as $day) {
        $dayBusyTimes = array_filter($busyTimes, function($time) use ($day) {
            return $time['day'] === $day;
        });

        $currentTime = $startTime;
        while ($currentTime < $endTime) {
            $slotStart = date('H:i', $currentTime);
            $slotEnd = date('H:i', $currentTime + $interval);
            
            $isAvailable = true;
            foreach ($dayBusyTimes as $busyTime) {
                $busyStart = strtotime($busyTime['start']);
                $busyEnd = strtotime($busyTime['end']);
                
                if (($currentTime >= $busyStart && $currentTime < $busyEnd) ||
                    ($currentTime + $interval > $busyStart && $currentTime + $interval <= $busyEnd)) {
                    $isAvailable = false;
                    break;
                }
            }
            
            if ($isAvailable) {
                $timeSlots[] = [
                    "day" => $day,
                    "startTime" => $slotStart,
                    "endTime" => $slotEnd
                ];
            }
            
            $currentTime += $interval;
        }
    }
    
    return $timeSlots;
}
?>