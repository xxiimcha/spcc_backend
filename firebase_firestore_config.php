<?php
// Firebase Firestore configuration file
// Requires: composer require kreait/firebase-php

require_once __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;

class FirebaseFirestoreConfig {
    private $firestore;
    private $projectId;
    
    public function __construct() {
        // Replace with your actual Firebase project credentials
        $this->projectId = 'spcc-database'; // Your Firebase project ID
        
        // Option 1: Using service account JSON file
        $serviceAccountPath = __DIR__ . '/firebase_credentials.json';
        
        if (file_exists($serviceAccountPath)) {
            $factory = (new Factory)->withServiceAccount($serviceAccountPath);
        } else {
            // Option 2: Using environment variables or direct config
            $firebaseConfig = [
                'type' => 'service_account',
                'project_id' => $this->projectId,
                'private_key_id' => 'YOUR_PRIVATE_KEY_ID',
                'private_key' => 'YOUR_PRIVATE_KEY',
                'client_email' => 'YOUR_CLIENT_EMAIL',
                'client_id' => 'YOUR_CLIENT_ID',
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url' => 'YOUR_CLIENT_CERT_URL'
            ];
            
            $factory = (new Factory)->withServiceAccount($firebaseConfig);
        }
        
        $this->firestore = $factory->createFirestore();
    }
    
    public function getFirestore() {
        return $this->firestore->database();
    }
    
    public function getProjectId() {
        return $this->projectId;
    }
    
    // Method to add document to collection
    public function addDocument($collection, $data, $documentId = null) {
        $collectionRef = $this->firestore->database()->collection($collection);
        
        if ($documentId) {
            $docRef = $collectionRef->document($documentId);
            $docRef->set($data);
            return $documentId;
        } else {
            $docRef = $collectionRef->add($data);
            return $docRef->id();
        }
    }
    
    // Method to update document
    public function updateDocument($collection, $documentId, $data) {
        $docRef = $this->firestore->database()->collection($collection)->document($documentId);
        $docRef->set($data, ['merge' => true]);
        return true;
    }
    
    // Method to get document
    public function getDocument($collection, $documentId) {
        $docRef = $this->firestore->database()->collection($collection)->document($documentId);
        $snapshot = $docRef->snapshot();
        
        if ($snapshot->exists()) {
            return $snapshot->data();
        }
        return null;
    }
    
    // Method to get all documents in collection
    public function getCollection($collection) {
        $collectionRef = $this->firestore->database()->collection($collection);
        $documents = $collectionRef->documents();
        
        $data = [];
        foreach ($documents as $document) {
            $data[$document->id()] = $document->data();
        }
        
        return $data;
    }
    
    // Method to delete document
    public function deleteDocument($collection, $documentId) {
        $docRef = $this->firestore->database()->collection($collection)->document($documentId);
        $docRef->delete();
        return true;
    }
}

// Initialize Firebase Firestore configuration
$firebaseFirestoreConfig = new FirebaseFirestoreConfig();
?>
