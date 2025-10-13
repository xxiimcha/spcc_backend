<?php
// firebase_sync_lib.php
require_once __DIR__ . '/firebase_config.php';

class FirebaseSync {
    private $firebaseConfig;
    private $conn;

    public function __construct($firebaseConfig, mysqli $conn) {
        $this->firebaseConfig = $firebaseConfig;
        $this->conn = $conn;
    }

    /** Push ALL professors snapshot to Firebase: path "professors" (array or map) */
    public function syncProfessors(): array {
        $sql = "
            SELECT 
                prof_id,
                prof_name,
                prof_email,
                prof_phone,
                prof_qualifications,
                subj_count
            FROM professors
            ORDER BY prof_name
        ";
        $res = $this->conn->query($sql);
        if (!$res) {
            return ['success' => false, 'message' => 'Failed to fetch professors: '.$this->conn->error];
        }

        $list = [];
        while ($row = $res->fetch_assoc()) {
            $quals = json_decode($row['prof_qualifications'] ?? '[]', true);
            if (!is_array($quals)) $quals = [];
            $quals = array_values(array_filter(array_map('strval', $quals), fn($q)=>$q!==''));
            $list[] = [
                'id'             => (int)$row['prof_id'],
                'name'           => $row['prof_name'],
                'email'          => $row['prof_email'],
                'phone'          => $row['prof_phone'],
                'qualifications' => $quals,
                'subject_count'  => (int)($row['subj_count'] ?? 0),
            ];
        }

        $resp = $this->firebaseConfig->setData('professors', $list);
        $ok   = isset($resp['status']) && (int)$resp['status'] === 200;
        return [
            'success' => $ok,
            'message' => $ok ? 'Professors synced' : 'Failed to sync professors',
            'count'   => count($list),
            'firebase_response' => $resp,
        ];
    }

    /** Push ONE professor doc to "professors/{id}" */
    public function syncSingleProfessor(int $id): array {
        $id = (int)$id;
        if ($id <= 0) return ['success'=>false,'message'=>'Invalid professor id'];

        $sql = "SELECT * FROM professors WHERE prof_id=$id LIMIT 1";
        $res = $this->conn->query($sql);
        if (!$res || $res->num_rows === 0) {
            return ['success'=>false,'message'=>'Professor not found'];
        }
        $row = $res->fetch_assoc();

        $quals = json_decode($row['prof_qualifications'] ?? '[]', true);
        if (!is_array($quals)) $quals = [];
        $quals = array_values(array_filter(array_map('strval', $quals), fn($q)=>$q!==''));

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

        $resp = $this->firebaseConfig->setData("professors/{$id}", $payload);
        $ok   = isset($resp['status']) && (int)$resp['status'] === 200;
        return [
            'success' => $ok,
            'message' => $ok ? 'Professor synced' : 'Failed to sync professor',
            'firebase_response' => $resp,
        ];
    }

    /** Delete ONE professor in Firebase (Realtime DB supports null delete) */
    public function deleteProfessorInFirebase(int $id): array {
        $id = (int)$id;
        if ($id <= 0) return ['success'=>false,'message'=>'Invalid professor id'];

        // If your firebase_config has a delete() method, use that here instead.
        $resp = $this->firebaseConfig->setData("professors/{$id}", null);
        $ok   = isset($resp['status']) && (int)$resp['status'] === 200;
        return [
            'success' => $ok,
            'message' => $ok ? 'Professor removed in Firebase' : 'Failed to remove professor in Firebase',
            'firebase_response' => $resp,
        ];
    }
}
