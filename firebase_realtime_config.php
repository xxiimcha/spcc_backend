<?php
/**
 * Simplified Firebase Realtime Database Configuration
 * Uses HTTP REST API for direct database access
 */

class FirebaseRealtimeConfig {
    private $databaseUrl;
    private $apiKey;
    
    public function __construct() {
        $this->databaseUrl = "https://spcc-database-default-rtdb.firebaseio.com";
        $this->apiKey = "AIzaSyB1NGawsW6KXWqIxoaNEGgMoD57lSOi7js";
    }
    
    /**
     * Make HTTP request to Firebase Realtime Database
     */
    public function makeRequest($path, $method = 'GET', $data = null) {
        $url = $this->databaseUrl . '/' . trim($path, '/') . '.json';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return [
                'success' => false,
                'error' => $curlError,
                'http_code' => 0
            ];
        }
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'data' => json_decode($response, true),
            'http_code' => $httpCode,
            'raw_response' => $response
        ];
    }
    
    /**
     * Set data at a specific path
     */
    public function setData($path, $data) {
        return $this->makeRequest($path, 'PUT', $data);
    }
    
    /**
     * Update data at a specific path
     */
    public function updateData($path, $data) {
        return $this->makeRequest($path, 'PATCH', $data);
    }
    
    /**
     * Get data from a specific path
     */
    public function getData($path) {
        return $this->makeRequest($path, 'GET');
    }
    
    /**
     * Push data to a path (creates new child with auto-generated key)
     */
    public function pushData($path, $data) {
        return $this->makeRequest($path, 'POST', $data);
    }
    
    /**
     * Delete data at a specific path
     */
    public function deleteData($path) {
        return $this->makeRequest($path, 'DELETE');
    }
}

// Create global instance
$firebaseRealtime = new FirebaseRealtimeConfig();
?>

