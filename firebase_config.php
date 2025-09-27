<?php
// Firebase configuration file
// You'll need to install the Firebase PHP SDK or use cURL for HTTP requests

// Firebase Realtime Database configuration
class FirebaseConfig {
    private $databaseUrl;
    private $apiKey;
    private $authToken;
    
    public function __construct() {
        // Replace these with your actual Firebase project credentials
        $this->databaseUrl = "https://spcc-database-default-rtdb.firebaseio.com"; // Replace with your Firebase database URL
        $this->apiKey = "AIzaSyB1NGawsW6KXWqIxoaNEGgMoD57lSOi7js"; // Replace with your Firebase API key
        $this->authToken = null; // For anonymous auth or service account token
    }
    
    public function getDatabaseUrl() {
        return $this->databaseUrl;
    }
    
    public function getApiKey() {
        return $this->apiKey;
    }
    
    public function getAuthToken() {
        return $this->authToken;
    }
    
    // Method to make HTTP requests to Firebase
    public function makeRequest($path, $method = 'GET', $data = null) {
        $url = $this->databaseUrl . '/' . $path . '.json';
        
        // For Realtime Database, we don't need auth parameter for public access
        // The API key is not used for database access, only for other Firebase services
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen(json_encode($data))
            ]);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return [
                'status' => 0,
                'data' => null,
                'error' => $curlError
            ];
        }
        
        return [
            'status' => $httpCode,
            'data' => json_decode($response, true)
        ];
    }
    
    // Method to push data to Firebase
    public function pushData($path, $data) {
        return $this->makeRequest($path, 'POST', $data);
    }
    
    // Method to set data in Firebase
    public function setData($path, $data) {
        return $this->makeRequest($path, 'PUT', $data);
    }
    
    // Method to update data in Firebase
    public function updateData($path, $data) {
        return $this->makeRequest($path, 'PATCH', $data);
    }
    
    // Method to get data from Firebase
    public function getData($path) {
        return $this->makeRequest($path, 'GET');
    }
    
    // Method to delete data from Firebase
    public function deleteData($path) {
        return $this->makeRequest($path, 'DELETE');
    }
}

// Initialize Firebase configuration
$firebaseConfig = new FirebaseConfig();
?> 