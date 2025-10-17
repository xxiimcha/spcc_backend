<?php
require_once __DIR__ . '/firebase_config.php';

class FirebaseSync {
    private const LOG_ENABLED = false;

    private $firebaseConfig;
    private $conn;

    public function __construct($firebaseConfig, mysqli $conn) {
        $this->firebaseConfig = $firebaseConfig;
        $this->conn = $conn;
    }

    private function normPath(string $p): string {
        return ltrim($p, "/");
    }

    private function log(string $msg): void {
        if (!self::LOG_ENABLED) return;
        @file_put_contents(
            __DIR__ . '/logs/firebase_sync.log',
            "[".date('c')."] ".$msg.PHP_EOL,
            FILE_APPEND
        );
    }

    /* ==========================================================
     *  PROFESSORS
     * ========================================================== */

    public function syncProfessors(): array {
        $sql = "
            SELECT 
                prof_id,
                prof_name,
                prof_email,
                prof_password,
                prof_phone,
                prof_qualifications,
                prof_subject_ids,
                subj_count,
                school_year
            FROM professors
            ORDER BY prof_name
        ";
        $res = $this->conn->query($sql);
        if (!$res) {
            return ['success' => false, 'message' => 'Failed to fetch professors: ' . $this->conn->error];
        }

        $list = [];
        while ($row = $res->fetch_assoc()) {
            $quals = json_decode($row['prof_qualifications'] ?? '[]', true);
            if (!is_array($quals)) $quals = [];
            $quals = array_values(array_filter(array_map('strval', $quals), fn($q) => $q !== ''));

            $list[] = [
                'id'             => (int)$row['prof_id'],
                'name'           => $row['prof_name'],
                'email'          => $row['prof_email'],
                'passwor'        => $row['prof_password'],
                'phone'          => $row['prof_phone'],
                'qualifications' => $quals,
                'subject_ids'    => json_decode($row['prof_subject_ids'] ?? '[]', true) ?: [],
                'subject_count'  => (int)($row['subj_count'] ?? 0),
                'school_year'    => $row['school_year'] ?? null,
            ];
        }

        $path = $this->normPath('professors');
        $resp = $this->firebaseConfig->setData($path, $list);
        $ok   = isset($resp['status']) && (int)$resp['status'] === 200;

        $this->log("syncProfessors path=$path status=" . json_encode($resp));

        return [
            'success' => $ok,
            'message' => $ok ? 'Professors synced' : 'Failed to sync professors',
            'count'   => count($list),
            'firebase_response' => $resp,
        ];
    }

    public function syncSingleProfessor(int $id): array {
        $id = (int)$id;
        if ($id <= 0) return ['success' => false, 'message' => 'Invalid professor id'];

        $sql = "SELECT * FROM professors WHERE prof_id = {$id} LIMIT 1";
        $res = $this->conn->query($sql);
        if (!$res || $res->num_rows === 0) {
            return ['success' => false, 'message' => 'Professor not found'];
        }
        $row = $res->fetch_assoc();

        $quals = json_decode($row['prof_qualifications'] ?? '[]', true);
        if (!is_array($quals)) $quals = [];
        $quals = array_values(array_filter(array_map('strval', $quals), fn($q) => $q !== ''));

        $payload = [
            'id'             => (int)$row['prof_id'],
            'name'           => $row['prof_name'],
            'email'          => $row['prof_email'],
            'phone'          => $row['prof_phone'],
            'qualifications' => $quals,
            'subject_ids'    => json_decode($row['prof_subject_ids'] ?? '[]', true) ?: [],
            'subject_count'  => (int)($row['subj_count'] ?? 0),
            'school_year'    => $row['school_year'] ?? null,
        ];

        $path = $this->normPath("professors/{$id}");
        $resp = $this->firebaseConfig->setData($path, $payload);
        $ok   = isset($resp['status']) && (int)$resp['status'] === 200;

        $this->log("syncSingleProfessor path=$path status=" . json_encode($resp) . " payload=" . json_encode($payload));

        return [
            'success' => $ok,
            'message' => $ok ? 'Professor synced' : 'Failed to sync professor',
            'firebase_response' => $resp,
        ];
    }

    public function deleteProfessorInFirebase(int $id): array {
        $id = (int)$id;
        if ($id <= 0) return ['success' => false, 'message' => 'Invalid professor id'];

        $path = $this->normPath("professors/{$id}");
        $resp = $this->firebaseConfig->setData($path, null);
        $ok   = isset($resp['status']) && (int)$resp['status'] === 200;

        $this->log("deleteProfessor path=$path status=" . json_encode($resp));

        return [
            'success' => $ok,
            'message' => $ok ? 'Professor removed in Firebase' : 'Failed to remove professor in Firebase',
            'firebase_response' => $resp,
        ];
    }

    /* ==========================================================
     *  SUBJECTS
     * ========================================================== */

    private function buildSubjectPayload(array $row): array {
        $payload = [
            'id'             => (int)$row['subj_id'],
            'code'           => $row['subj_code'] ?? null,
            'name'           => $row['subj_name'] ?? null,
            'description'    => $row['subj_description'] ?? null,
            'gradeLevel'     => $row['grade_level'] ?? null,
            'strand'         => $row['strand'] ?? null,
            'type'           => $row['subj_type'] ?? null,
            'semester'       => $row['semester'] ?? null,      // include semester too
            'is_active'      => isset($row['is_active']) ? (int)$row['is_active'] : 1,
            'schedule_count' => isset($row['schedule_count']) ? (int)$row['schedule_count'] : 0,
            'school_year'    => $row['school_year'] ?? null,
        ];
        foreach ($payload as $k => $v) {
            if ($v === null) unset($payload[$k]);
        }
        return $payload;
    }

    public function syncSubjects(): array {
        $sql = "
            SELECT s.*,
                   (SELECT COUNT(*) FROM schedules WHERE subj_id = s.subj_id) AS schedule_count
            FROM subjects s
            ORDER BY s.strand IS NULL, s.strand, s.grade_level, s.subj_code
        ";
        $res = $this->conn->query($sql);
        if (!$res) {
            return ['success' => false, 'message' => 'Failed to fetch subjects: ' . $this->conn->error];
        }

        $list = [];
        while ($row = $res->fetch_assoc()) {
            $list[] = $this->buildSubjectPayload($row);
        }

        $path = $this->normPath('subjects');
        $resp = $this->firebaseConfig->setData($path, $list);
        $ok   = isset($resp['status']) && (int)$resp['status'] === 200;

        $this->log("syncSubjects path=$path status=" . json_encode($resp) . " count=" . count($list));

        return [
            'success' => $ok,
            'message' => $ok ? 'Subjects synced' : 'Failed to sync subjects',
            'count'   => count($list),
            'firebase_response' => $resp,
        ];
    }

    public function syncSingleSubject(int $id): array {
        $id = (int)$id;
        if ($id <= 0) return ['success' => false, 'message' => 'Invalid subject id'];

        $sql = "
            SELECT s.*,
                   (SELECT COUNT(*) FROM schedules WHERE subj_id = s.subj_id) AS schedule_count
            FROM subjects s
            WHERE s.subj_id = {$id}
            LIMIT 1
        ";
        $res = $this->conn->query($sql);
        if (!$res || $res->num_rows === 0) {
            return ['success' => false, 'message' => 'Subject not found'];
        }

        $row = $res->fetch_assoc();
        $payload = $this->buildSubjectPayload($row);

        $path = $this->normPath("subjects/{$id}");
        $resp = $this->firebaseConfig->setData($path, $payload);
        $ok   = isset($resp['status']) && (int)$resp['status'] === 200;

        $this->log("syncSingleSubject path=$path status=" . json_encode($resp) . " payload=" . json_encode($payload));

        return [
            'success' => $ok,
            'message' => $ok ? 'Subject synced' : 'Failed to sync subject',
            'firebase_response' => $resp,
        ];
    }

    public function deleteSubjectInFirebase(int $id): array {
        $id = (int)$id;
        if ($id <= 0) return ['success' => false, 'message' => 'Invalid subject id'];

        $path = $this->normPath("subjects/{$id}");
        $resp = $this->firebaseConfig->setData($path, null);
        $ok   = isset($resp['status']) && (int)$resp['status'] === 200;

        $this->log("deleteSubject path=$path status=" . json_encode($resp));

        return [
            'success' => $ok,
            'message' => $ok ? 'Subject removed in Firebase' : 'Failed to remove subject in Firebase',
            'firebase_response' => $resp,
        ];
    }
}
