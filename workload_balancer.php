<?php
/**
 * Workload Balancer API
 * Automatically balances professor workloads and redistributes schedules
 */

include 'cors_helper.php';
handleCORS();
include 'connect.php';

header('Content-Type: application/json');

class WorkloadBalancer {
    private $conn;
    private $maxWorkloadHours = 40; // Maximum weekly hours per professor
    private $targetWorkloadHours = 24; // Target weekly hours per professor
    private $maxSubjectsPerProfessor = 8; // Maximum subjects per professor
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Analyze current workload distribution
     */
    public function analyzeWorkload($schoolYear, $semester) {
        try {
            $analysis = [
                'total_professors' => 0,
                'total_schedules' => 0,
                'average_workload' => 0,
                'workload_distribution' => [],
                'overloaded_professors' => [],
                'underloaded_professors' => [],
                'recommendations' => []
            ];
            
            // Get all professors with their current workloads
            $sql = "
                SELECT 
                    p.prof_id,
                    p.prof_name,
                    p.department,
                    COUNT(s.id) as subject_count,
                    SUM(CASE 
                        WHEN s.start_time IS NOT NULL AND s.end_time IS NOT NULL 
                        THEN TIME_TO_SEC(TIMEDIFF(s.end_time, s.start_time)) / 3600 
                        ELSE 3 
                    END) as total_hours,
                    GROUP_CONCAT(DISTINCT subj.subj_name) as subjects
                FROM professors p
                LEFT JOIN schedules s ON p.prof_id = s.prof_id 
                    AND s.school_year = ? AND s.semester = ?
                LEFT JOIN subjects subj ON s.subj_id = subj.subj_id
                GROUP BY p.prof_id, p.prof_name, p.department
                ORDER BY total_hours DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ss", $schoolYear, $semester);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $totalHours = 0;
            $professorCount = 0;
            
            while ($row = $result->fetch_assoc()) {
                $professorCount++;
                $hours = floatval($row['total_hours'] ?? 0);
                $totalHours += $hours;
                
                $professorData = [
                    'prof_id' => $row['prof_id'],
                    'prof_name' => $row['prof_name'],
                    'department' => $row['department'],
                    'subject_count' => intval($row['subject_count']),
                    'total_hours' => $hours,
                    'subjects' => $row['subjects'] ? explode(',', $row['subjects']) : [],
                    'status' => $this->getWorkloadStatus($hours, intval($row['subject_count']))
                ];
                
                $analysis['workload_distribution'][] = $professorData;
                
                // Categorize professors
                if ($hours > $this->maxWorkloadHours || intval($row['subject_count']) > $this->maxSubjectsPerProfessor) {
                    $analysis['overloaded_professors'][] = $professorData;
                } elseif ($hours < ($this->targetWorkloadHours * 0.7)) {
                    $analysis['underloaded_professors'][] = $professorData;
                }
            }
            
            $analysis['total_professors'] = $professorCount;
            $analysis['total_schedules'] = $this->getTotalSchedules($schoolYear, $semester);
            $analysis['average_workload'] = $professorCount > 0 ? round($totalHours / $professorCount, 2) : 0;
            
            // Generate recommendations
            $analysis['recommendations'] = $this->generateRecommendations($analysis);
            
            return [
                'success' => true,
                'analysis' => $analysis
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to analyze workload: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Automatically balance workload
     */
    public function balanceWorkload($schoolYear, $semester, $options = []) {
        try {
            $this->conn->begin_transaction();
            
            // Get current workload analysis
            $analysisResult = $this->analyzeWorkload($schoolYear, $semester);
            if (!$analysisResult['success']) {
                throw new Exception('Failed to analyze current workload');
            }
            
            $analysis = $analysisResult['analysis'];
            $balanceActions = [];
            
            // Strategy 1: Redistribute from overloaded to underloaded professors
            $redistributed = $this->redistributeSchedules(
                $analysis['overloaded_professors'], 
                $analysis['underloaded_professors'],
                $schoolYear, 
                $semester
            );
            
            $balanceActions = array_merge($balanceActions, $redistributed);
            
            // Strategy 2: Split heavy subjects if possible
            if (isset($options['allow_subject_splitting']) && $options['allow_subject_splitting']) {
                $splitActions = $this->splitHeavySubjects($schoolYear, $semester);
                $balanceActions = array_merge($balanceActions, $splitActions);
            }
            
            // Strategy 3: Optimize room and time assignments
            $optimizeActions = $this->optimizeAssignments($schoolYear, $semester);
            $balanceActions = array_merge($balanceActions, $optimizeActions);
            
            $this->conn->commit();
            
            // Get updated analysis
            $updatedAnalysis = $this->analyzeWorkload($schoolYear, $semester);
            
            return [
                'success' => true,
                'message' => 'Workload balanced successfully',
                'actions_taken' => $balanceActions,
                'before' => $analysis,
                'after' => $updatedAnalysis['success'] ? $updatedAnalysis['analysis'] : null,
                'improvements' => $this->calculateImprovements($analysis, $updatedAnalysis['analysis'] ?? [])
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'message' => 'Failed to balance workload: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get workload recommendations
     */
    public function getRecommendations($schoolYear, $semester) {
        try {
            $analysisResult = $this->analyzeWorkload($schoolYear, $semester);
            if (!$analysisResult['success']) {
                throw new Exception('Failed to analyze workload');
            }
            
            $analysis = $analysisResult['analysis'];
            $recommendations = $this->generateDetailedRecommendations($analysis, $schoolYear, $semester);
            
            return [
                'success' => true,
                'recommendations' => $recommendations,
                'analysis_summary' => [
                    'total_professors' => $analysis['total_professors'],
                    'average_workload' => $analysis['average_workload'],
                    'overloaded_count' => count($analysis['overloaded_professors']),
                    'underloaded_count' => count($analysis['underloaded_professors'])
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to generate recommendations: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Redistribute schedules from overloaded to underloaded professors
     */
    private function redistributeSchedules($overloaded, $underloaded, $schoolYear, $semester) {
        $actions = [];
        
        foreach ($overloaded as $overloadedProf) {
            if (empty($underloaded)) break;
            
            // Get schedules that can be redistributed
            $redistributableSchedules = $this->getRedistributableSchedules(
                $overloadedProf['prof_id'], 
                $schoolYear, 
                $semester
            );
            
            foreach ($redistributableSchedules as $schedule) {
                // Find suitable underloaded professor
                $suitableProf = $this->findSuitableProfessor($schedule, $underloaded);
                
                if ($suitableProf) {
                    // Check for conflicts before reassigning
                    if (!$this->hasScheduleConflict($suitableProf['prof_id'], $schedule, $schoolYear, $semester)) {
                        // Reassign the schedule
                        $updateSql = "UPDATE schedules SET prof_id = ? WHERE id = ?";
                        $stmt = $this->conn->prepare($updateSql);
                        $stmt->bind_param("si", $suitableProf['prof_id'], $schedule['id']);
                        
                        if ($stmt->execute()) {
                            $actions[] = [
                                'action' => 'redistribute',
                                'schedule_id' => $schedule['id'],
                                'subject' => $schedule['subject'],
                                'from_professor' => $overloadedProf['prof_name'],
                                'to_professor' => $suitableProf['prof_name'],
                                'reason' => 'Workload balancing'
                            ];
                            
                            // Update underloaded professor's workload tracking
                            foreach ($underloaded as &$prof) {
                                if ($prof['prof_id'] === $suitableProf['prof_id']) {
                                    $prof['total_hours'] += floatval($schedule['hours']);
                                    $prof['subject_count']++;
                                    break;
                                }
                            }
                        }
                    }
                }
                
                // Stop if professor is no longer overloaded
                if ($this->getWorkloadStatus($overloadedProf['total_hours'], $overloadedProf['subject_count']) !== 'overloaded') {
                    break;
                }
            }
        }
        
        return $actions;
    }
    
    /**
     * Get schedules that can be redistributed
     */
    private function getRedistributableSchedules($profId, $schoolYear, $semester) {
        $sql = "
            SELECT 
                s.id,
                s.subj_id,
                subj.subj_name as subject,
                s.section_id,
                sec.section_name,
                s.start_time,
                s.end_time,
                s.days,
                CASE 
                    WHEN s.start_time IS NOT NULL AND s.end_time IS NOT NULL 
                    THEN TIME_TO_SEC(TIMEDIFF(s.end_time, s.start_time)) / 3600 
                    ELSE 3 
                END as hours
            FROM schedules s
            JOIN subjects subj ON s.subj_id = subj.subj_id
            JOIN sections sec ON s.section_id = sec.section_id
            WHERE s.prof_id = ? AND s.school_year = ? AND s.semester = ?
            ORDER BY hours DESC
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $profId, $schoolYear, $semester);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Find suitable professor for reassignment
     */
    private function findSuitableProfessor($schedule, $underloadedProfessors) {
        // Sort by current workload (ascending)
        usort($underloadedProfessors, function($a, $b) {
            return $a['total_hours'] <=> $b['total_hours'];
        });
        
        foreach ($underloadedProfessors as $prof) {
            // Check if professor can handle additional workload
            $newHours = $prof['total_hours'] + floatval($schedule['hours']);
            $newSubjectCount = $prof['subject_count'] + 1;
            
            if ($newHours <= $this->maxWorkloadHours && $newSubjectCount <= $this->maxSubjectsPerProfessor) {
                // Additional checks can be added here (department match, subject expertise, etc.)
                return $prof;
            }
        }
        
        return null;
    }
    
    /**
     * Check for schedule conflicts
     */
    private function hasScheduleConflict($profId, $schedule, $schoolYear, $semester) {
        $sql = "
            SELECT COUNT(*) as conflict_count
            FROM schedules s
            WHERE s.prof_id = ? 
            AND s.school_year = ? 
            AND s.semester = ?
            AND s.id != ?
            AND (
                (s.start_time <= ? AND s.end_time > ?) OR
                (s.start_time < ? AND s.end_time >= ?) OR
                (s.start_time >= ? AND s.end_time <= ?)
            )
            AND (
                FIND_IN_SET('Monday', s.days) AND FIND_IN_SET('Monday', ?) OR
                FIND_IN_SET('Tuesday', s.days) AND FIND_IN_SET('Tuesday', ?) OR
                FIND_IN_SET('Wednesday', s.days) AND FIND_IN_SET('Wednesday', ?) OR
                FIND_IN_SET('Thursday', s.days) AND FIND_IN_SET('Thursday', ?) OR
                FIND_IN_SET('Friday', s.days) AND FIND_IN_SET('Friday', ?) OR
                FIND_IN_SET('Saturday', s.days) AND FIND_IN_SET('Saturday', ?)
            )
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "ssissssssssssss",
            $profId, $schoolYear, $semester, $schedule['id'],
            $schedule['start_time'], $schedule['start_time'],
            $schedule['end_time'], $schedule['end_time'],
            $schedule['start_time'], $schedule['end_time'],
            $schedule['days'], $schedule['days'], $schedule['days'],
            $schedule['days'], $schedule['days'], $schedule['days']
        );
        
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return intval($row['conflict_count']) > 0;
    }
    
    /**
     * Generate recommendations
     */
    private function generateRecommendations($analysis) {
        $recommendations = [];
        
        if (count($analysis['overloaded_professors']) > 0) {
            $recommendations[] = [
                'type' => 'redistribution',
                'priority' => 'high',
                'message' => count($analysis['overloaded_professors']) . ' professors are overloaded. Consider redistributing schedules.',
                'affected_professors' => array_column($analysis['overloaded_professors'], 'prof_name')
            ];
        }
        
        if (count($analysis['underloaded_professors']) > 0) {
            $recommendations[] = [
                'type' => 'utilization',
                'priority' => 'medium',
                'message' => count($analysis['underloaded_professors']) . ' professors are underutilized. They can take on more classes.',
                'affected_professors' => array_column($analysis['underloaded_professors'], 'prof_name')
            ];
        }
        
        $variance = $this->calculateWorkloadVariance($analysis['workload_distribution']);
        if ($variance > 50) {
            $recommendations[] = [
                'type' => 'balance',
                'priority' => 'medium',
                'message' => 'High workload variance detected. Consider automatic balancing.',
                'variance' => round($variance, 2)
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Get workload status
     */
    private function getWorkloadStatus($hours, $subjectCount) {
        if ($hours > $this->maxWorkloadHours || $subjectCount > $this->maxSubjectsPerProfessor) {
            return 'overloaded';
        } elseif ($hours < ($this->targetWorkloadHours * 0.7)) {
            return 'underloaded';
        } else {
            return 'balanced';
        }
    }
    
    /**
     * Calculate workload variance
     */
    private function calculateWorkloadVariance($workloadDistribution) {
        if (empty($workloadDistribution)) return 0;
        
        $hours = array_column($workloadDistribution, 'total_hours');
        $mean = array_sum($hours) / count($hours);
        
        $variance = array_sum(array_map(function($hour) use ($mean) {
            return pow($hour - $mean, 2);
        }, $hours)) / count($hours);
        
        return $variance;
    }
    
    /**
     * Get total schedules count
     */
    private function getTotalSchedules($schoolYear, $semester) {
        $sql = "SELECT COUNT(*) as total FROM schedules WHERE school_year = ? AND semester = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $schoolYear, $semester);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return intval($row['total']);
    }
    
    /**
     * Split heavy subjects (placeholder for future implementation)
     */
    private function splitHeavySubjects($schoolYear, $semester) {
        // This would implement subject splitting logic
        // For now, return empty array
        return [];
    }
    
    /**
     * Optimize assignments (placeholder for future implementation)
     */
    private function optimizeAssignments($schoolYear, $semester) {
        // This would implement assignment optimization
        // For now, return empty array
        return [];
    }
    
    /**
     * Calculate improvements between before and after
     */
    private function calculateImprovements($before, $after) {
        if (empty($after)) return [];
        
        return [
            'variance_reduction' => $this->calculateWorkloadVariance($before['workload_distribution']) - 
                                   $this->calculateWorkloadVariance($after['workload_distribution']),
            'overloaded_reduction' => count($before['overloaded_professors']) - count($after['overloaded_professors']),
            'average_workload_change' => $after['average_workload'] - $before['average_workload']
        ];
    }
    
    /**
     * Generate detailed recommendations
     */
    private function generateDetailedRecommendations($analysis, $schoolYear, $semester) {
        $recommendations = $this->generateRecommendations($analysis);
        
        // Add specific action recommendations
        foreach ($analysis['overloaded_professors'] as $prof) {
            $recommendations[] = [
                'type' => 'specific_action',
                'priority' => 'high',
                'professor' => $prof['prof_name'],
                'current_hours' => $prof['total_hours'],
                'target_hours' => $this->targetWorkloadHours,
                'message' => "Reduce {$prof['prof_name']}'s workload by " . 
                           round($prof['total_hours'] - $this->targetWorkloadHours, 1) . " hours",
                'suggestions' => [
                    'Reassign ' . ceil(($prof['total_hours'] - $this->targetWorkloadHours) / 3) . ' subjects to other professors',
                    'Consider splitting large classes',
                    'Review if all assigned subjects are necessary'
                ]
            ];
        }
        
        return $recommendations;
    }
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON data'
        ]);
        exit;
    }
    
    $balancer = new WorkloadBalancer($conn);
    $action = $data['action'] ?? 'analyze';
    
    switch ($action) {
        case 'analyze':
            if (!isset($data['school_year']) || !isset($data['semester'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'School year and semester are required'
                ]);
                break;
            }
            
            $result = $balancer->analyzeWorkload($data['school_year'], $data['semester']);
            echo json_encode($result);
            break;
            
        case 'balance':
            if (!isset($data['school_year']) || !isset($data['semester'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'School year and semester are required'
                ]);
                break;
            }
            
            $options = $data['options'] ?? [];
            $result = $balancer->balanceWorkload($data['school_year'], $data['semester'], $options);
            echo json_encode($result);
            break;
            
        case 'recommendations':
            if (!isset($data['school_year']) || !isset($data['semester'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'School year and semester are required'
                ]);
                break;
            }
            
            $result = $balancer->getRecommendations($data['school_year'], $data['semester']);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action. Supported actions: analyze, balance, recommendations'
            ]);
            break;
    }
    
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Only POST method is allowed'
    ]);
}

$conn->close();
?>
