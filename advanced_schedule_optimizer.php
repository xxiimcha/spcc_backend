<?php
/**
 * Advanced Schedule Optimizer
 * Uses genetic algorithm and constraint satisfaction for optimal schedule generation
 * Implements workload balancing and resource optimization
 */

include 'cors_helper.php';
handleCORS();
include 'connect.php';

header('Content-Type: application/json');

class AdvancedScheduleOptimizer {
    private $conn;
    private $constraints;
    private $population_size = 50;
    private $generations = 100;
    private $mutation_rate = 0.1;
    private $crossover_rate = 0.8;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Main optimization function
     */
    public function optimizeSchedule($data) {
        try {
            // Set constraints from input data
            $this->setConstraints($data);
            
            // Get all required data
            $subjects = $this->getSubjects($data['level'], $data['strand']);
            $professors = $this->getProfessors();
            $rooms = $this->getRooms();
            $sections = $this->getSections($data['level'], $data['strand']);
            $timeSlots = $this->generateTimeSlots($data['start_time'], $data['end_time']);
            
            // Generate initial population
            $population = $this->generateInitialPopulation($subjects, $professors, $rooms, $sections, $timeSlots);
            
            // Run genetic algorithm
            $bestSchedule = $this->runGeneticAlgorithm($population);
            
            // Balance workload
            $optimizedSchedule = $this->balanceWorkload($bestSchedule);
            
            // Validate final schedule
            $conflicts = $this->validateSchedule($optimizedSchedule);
            
            return [
                'success' => true,
                'schedule' => $optimizedSchedule,
                'conflicts' => $conflicts,
                'fitness_score' => $this->calculateFitness($optimizedSchedule),
                'optimization_stats' => [
                    'generations_run' => $this->generations,
                    'population_size' => $this->population_size,
                    'workload_balance_score' => $this->calculateWorkloadBalance($optimizedSchedule)
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Optimization failed: ' . $e->getMessage(),
                'error' => $e->getTraceAsString()
            ];
        }
    }
    
    /**
     * Set optimization constraints
     */
    private function setConstraints($data) {
        $this->constraints = [
            'school_year' => $data['school_year'],
            'semester' => $data['semester'],
            'level' => $data['level'],
            'strand' => $data['strand'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'max_subjects_per_day' => $data['max_subjects_per_day'] ?? 6,
            'max_hours_per_day' => $data['max_hours_per_day'] ?? 8,
            'preferred_days' => $data['preferred_days'] ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'min_break_time' => $data['min_break_time'] ?? 15, // minutes
            'max_professor_load' => $data['max_professor_load'] ?? 8, // subjects
            'room_utilization_target' => $data['room_utilization_target'] ?? 0.8 // 80%
        ];
    }
    
    /**
     * Generate initial population for genetic algorithm
     */
    private function generateInitialPopulation($subjects, $professors, $rooms, $sections, $timeSlots) {
        $population = [];
        
        for ($i = 0; $i < $this->population_size; $i++) {
            $individual = $this->createRandomSchedule($subjects, $professors, $rooms, $sections, $timeSlots);
            $population[] = $individual;
        }
        
        return $population;
    }
    
    /**
     * Create a random schedule (individual in genetic algorithm)
     */
    private function createRandomSchedule($subjects, $professors, $rooms, $sections, $timeSlots) {
        $schedule = [];
        
        foreach ($sections as $section) {
            foreach ($subjects as $subject) {
                // Randomly assign professor, room, and time slot
                $professor = $professors[array_rand($professors)];
                $room = $rooms[array_rand($rooms)];
                $timeSlot = $timeSlots[array_rand($timeSlots)];
                $days = $this->getRandomDays();
                
                $schedule[] = [
                    'section_id' => $section['section_id'],
                    'subject_id' => $subject['subj_id'],
                    'professor_id' => $professor['prof_id'],
                    'room_id' => $room['room_id'],
                    'start_time' => $timeSlot['start'],
                    'end_time' => $timeSlot['end'],
                    'days' => $days,
                    'schedule_type' => $this->getScheduleType($room)
                ];
            }
        }
        
        return $schedule;
    }
    
    /**
     * Run genetic algorithm optimization
     */
    private function runGeneticAlgorithm($population) {
        $bestFitness = 0;
        $bestIndividual = null;
        
        for ($generation = 0; $generation < $this->generations; $generation++) {
            // Evaluate fitness for each individual
            $fitnessScores = [];
            foreach ($population as $individual) {
                $fitness = $this->calculateFitness($individual);
                $fitnessScores[] = $fitness;
                
                if ($fitness > $bestFitness) {
                    $bestFitness = $fitness;
                    $bestIndividual = $individual;
                }
            }
            
            // Selection, crossover, and mutation
            $newPopulation = [];
            
            // Keep best individuals (elitism)
            $eliteCount = intval($this->population_size * 0.1);
            $elite = $this->selectElite($population, $fitnessScores, $eliteCount);
            $newPopulation = array_merge($newPopulation, $elite);
            
            // Generate rest of population through crossover and mutation
            while (count($newPopulation) < $this->population_size) {
                $parent1 = $this->selectParent($population, $fitnessScores);
                $parent2 = $this->selectParent($population, $fitnessScores);
                
                if (rand() / getrandmax() < $this->crossover_rate) {
                    $offspring = $this->crossover($parent1, $parent2);
                } else {
                    $offspring = $parent1;
                }
                
                if (rand() / getrandmax() < $this->mutation_rate) {
                    $offspring = $this->mutate($offspring);
                }
                
                $newPopulation[] = $offspring;
            }
            
            $population = $newPopulation;
        }
        
        return $bestIndividual;
    }
    
    /**
     * Calculate fitness score for a schedule
     */
    private function calculateFitness($schedule) {
        $score = 1000; // Start with perfect score
        
        // Penalty for conflicts
        $conflicts = $this->validateSchedule($schedule);
        $score -= count($conflicts['professor_conflicts']) * 100;
        $score -= count($conflicts['room_conflicts']) * 50;
        $score -= count($conflicts['section_conflicts']) * 75;
        
        // Bonus for workload balance
        $workloadBalance = $this->calculateWorkloadBalance($schedule);
        $score += $workloadBalance * 50;
        
        // Bonus for room utilization
        $roomUtilization = $this->calculateRoomUtilization($schedule);
        $score += $roomUtilization * 30;
        
        // Penalty for time gaps
        $timeGaps = $this->calculateTimeGaps($schedule);
        $score -= $timeGaps * 10;
        
        return max(0, $score); // Ensure non-negative
    }
    
    /**
     * Balance workload across professors
     */
    private function balanceWorkload($schedule) {
        $professorLoads = [];
        
        // Count current loads
        foreach ($schedule as $item) {
            $profId = $item['professor_id'];
            if (!isset($professorLoads[$profId])) {
                $professorLoads[$profId] = 0;
            }
            $professorLoads[$profId]++;
        }
        
        // Redistribute if necessary
        $maxLoad = $this->constraints['max_professor_load'];
        $overloadedProfs = array_filter($professorLoads, function($load) use ($maxLoad) {
            return $load > $maxLoad;
        });
        
        if (!empty($overloadedProfs)) {
            $schedule = $this->redistributeWorkload($schedule, $professorLoads);
        }
        
        return $schedule;
    }
    
    /**
     * Get subjects for specific level and strand
     */
    private function getSubjects($level, $strand) {
        $sql = "SELECT * FROM subjects WHERE grade_level = ? AND strand = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $level, $strand);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Get all professors
     */
    private function getProfessors() {
        $sql = "SELECT * FROM professors";
        $result = $this->conn->query($sql);
        
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Get all rooms
     */
    private function getRooms() {
        $sql = "SELECT * FROM rooms";
        $result = $this->conn->query($sql);
        
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Get sections for specific level and strand
     */
    private function getSections($level, $strand) {
        $sql = "SELECT * FROM sections WHERE grade_level = ? AND strand = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $level, $strand);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Generate time slots based on start and end time
     */
    private function generateTimeSlots($startTime, $endTime) {
        $slots = [];
        $start = new DateTime($startTime);
        $end = new DateTime($endTime);
        $interval = new DateInterval('PT1H30M'); // 1.5 hour slots
        
        while ($start < $end) {
            $slotEnd = clone $start;
            $slotEnd->add($interval);
            
            if ($slotEnd <= $end) {
                $slots[] = [
                    'start' => $start->format('H:i'),
                    'end' => $slotEnd->format('H:i')
                ];
            }
            
            $start->add(new DateInterval('PT1H45M')); // 15 min break between slots
        }
        
        return $slots;
    }
    
    /**
     * Get random days for schedule
     */
    private function getRandomDays() {
        $availableDays = $this->constraints['preferred_days'];
        $numDays = rand(1, min(3, count($availableDays))); // 1-3 days per subject
        
        return array_slice(array_rand(array_flip($availableDays), $numDays), 0, $numDays);
    }
    
    /**
     * Determine schedule type based on room
     */
    private function getScheduleType($room) {
        return $room['room_type'] === 'Laboratory' ? 'Onsite' : 'Onsite';
    }
    
    /**
     * Validate schedule for conflicts
     */
    private function validateSchedule($schedule) {
        $conflicts = [
            'professor_conflicts' => [],
            'room_conflicts' => [],
            'section_conflicts' => []
        ];
        
        // Check for conflicts (simplified version)
        for ($i = 0; $i < count($schedule); $i++) {
            for ($j = $i + 1; $j < count($schedule); $j++) {
                $item1 = $schedule[$i];
                $item2 = $schedule[$j];
                
                // Check if same time and day
                if ($this->hasTimeOverlap($item1, $item2) && $this->hasDayOverlap($item1, $item2)) {
                    // Professor conflict
                    if ($item1['professor_id'] === $item2['professor_id']) {
                        $conflicts['professor_conflicts'][] = [$i, $j];
                    }
                    
                    // Room conflict
                    if ($item1['room_id'] === $item2['room_id']) {
                        $conflicts['room_conflicts'][] = [$i, $j];
                    }
                    
                    // Section conflict
                    if ($item1['section_id'] === $item2['section_id']) {
                        $conflicts['section_conflicts'][] = [$i, $j];
                    }
                }
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Helper functions for genetic algorithm operations
     */
    private function selectElite($population, $fitnessScores, $count) {
        $combined = array_combine(array_keys($population), $fitnessScores);
        arsort($combined);
        $eliteKeys = array_slice(array_keys($combined), 0, $count);
        
        return array_intersect_key($population, array_flip($eliteKeys));
    }
    
    private function selectParent($population, $fitnessScores) {
        // Tournament selection
        $tournamentSize = 5;
        $tournament = array_rand($fitnessScores, $tournamentSize);
        
        $bestIndex = $tournament[0];
        $bestFitness = $fitnessScores[$bestIndex];
        
        foreach ($tournament as $index) {
            if ($fitnessScores[$index] > $bestFitness) {
                $bestFitness = $fitnessScores[$index];
                $bestIndex = $index;
            }
        }
        
        return $population[$bestIndex];
    }
    
    private function crossover($parent1, $parent2) {
        $crossoverPoint = rand(1, min(count($parent1), count($parent2)) - 1);
        
        $offspring = array_merge(
            array_slice($parent1, 0, $crossoverPoint),
            array_slice($parent2, $crossoverPoint)
        );
        
        return $offspring;
    }
    
    private function mutate($individual) {
        if (empty($individual)) return $individual;
        
        $mutationIndex = rand(0, count($individual) - 1);
        
        // Randomly change one aspect of the selected schedule item
        $aspects = ['professor_id', 'room_id', 'start_time', 'end_time'];
        $aspect = $aspects[array_rand($aspects)];
        
        // This is a simplified mutation - in practice, you'd want more sophisticated logic
        switch ($aspect) {
            case 'start_time':
                $timeSlots = $this->generateTimeSlots($this->constraints['start_time'], $this->constraints['end_time']);
                $newSlot = $timeSlots[array_rand($timeSlots)];
                $individual[$mutationIndex]['start_time'] = $newSlot['start'];
                $individual[$mutationIndex]['end_time'] = $newSlot['end'];
                break;
        }
        
        return $individual;
    }
    
    private function calculateWorkloadBalance($schedule) {
        $professorLoads = [];
        
        foreach ($schedule as $item) {
            $profId = $item['professor_id'];
            $professorLoads[$profId] = ($professorLoads[$profId] ?? 0) + 1;
        }
        
        if (empty($professorLoads)) return 1;
        
        $mean = array_sum($professorLoads) / count($professorLoads);
        $variance = array_sum(array_map(function($load) use ($mean) {
            return pow($load - $mean, 2);
        }, $professorLoads)) / count($professorLoads);
        
        // Lower variance = better balance
        return 1 / (1 + $variance);
    }
    
    private function calculateRoomUtilization($schedule) {
        $roomUsage = [];
        $totalTimeSlots = count($this->generateTimeSlots($this->constraints['start_time'], $this->constraints['end_time']));
        $totalDays = count($this->constraints['preferred_days']);
        
        foreach ($schedule as $item) {
            $roomId = $item['room_id'];
            $roomUsage[$roomId] = ($roomUsage[$roomId] ?? 0) + count($item['days']);
        }
        
        if (empty($roomUsage)) return 0;
        
        $maxPossibleUsage = $totalTimeSlots * $totalDays;
        $averageUsage = array_sum($roomUsage) / count($roomUsage);
        
        return min(1, $averageUsage / $maxPossibleUsage);
    }
    
    private function calculateTimeGaps($schedule) {
        // Calculate gaps in schedule (simplified)
        $gaps = 0;
        
        // Group by section and day
        $sectionSchedules = [];
        foreach ($schedule as $item) {
            foreach ($item['days'] as $day) {
                $key = $item['section_id'] . '_' . $day;
                $sectionSchedules[$key][] = $item;
            }
        }
        
        // Count gaps in each section's daily schedule
        foreach ($sectionSchedules as $dailySchedule) {
            usort($dailySchedule, function($a, $b) {
                return strcmp($a['start_time'], $b['start_time']);
            });
            
            for ($i = 0; $i < count($dailySchedule) - 1; $i++) {
                $endTime = new DateTime($dailySchedule[$i]['end_time']);
                $nextStartTime = new DateTime($dailySchedule[$i + 1]['start_time']);
                
                $gapMinutes = ($nextStartTime->getTimestamp() - $endTime->getTimestamp()) / 60;
                
                if ($gapMinutes > $this->constraints['min_break_time'] + 30) { // More than necessary break
                    $gaps += $gapMinutes / 60; // Convert to hours
                }
            }
        }
        
        return $gaps;
    }
    
    private function redistributeWorkload($schedule, $professorLoads) {
        // Simplified workload redistribution
        $maxLoad = $this->constraints['max_professor_load'];
        $professors = $this->getProfessors();
        
        foreach ($schedule as &$item) {
            $currentProf = $item['professor_id'];
            
            if ($professorLoads[$currentProf] > $maxLoad) {
                // Find a professor with lower load
                foreach ($professorLoads as $profId => $load) {
                    if ($load < $maxLoad) {
                        $item['professor_id'] = $profId;
                        $professorLoads[$currentProf]--;
                        $professorLoads[$profId]++;
                        break;
                    }
                }
            }
        }
        
        return $schedule;
    }
    
    private function hasTimeOverlap($item1, $item2) {
        $start1 = new DateTime($item1['start_time']);
        $end1 = new DateTime($item1['end_time']);
        $start2 = new DateTime($item2['start_time']);
        $end2 = new DateTime($item2['end_time']);
        
        return ($start1 < $end2) && ($end1 > $start2);
    }
    
    private function hasDayOverlap($item1, $item2) {
        return !empty(array_intersect($item1['days'], $item2['days']));
    }
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid input data'
        ]);
        exit;
    }
    
    $optimizer = new AdvancedScheduleOptimizer($conn);
    $result = $optimizer->optimizeSchedule($data);
    
    echo json_encode($result);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Only POST method is allowed'
    ]);
}

$conn->close();
?>
