<?php
/**
 * Firebase Admin SDK Configuration
 * 
 * This file configures Firebase Admin SDK using your service account credentials
 * for secure server-side operations with Firebase Realtime Database
 */

require_once __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;

class FirebaseAdminConfig {
    private $database;
    private $auth;
    
    public function __construct() {
        // Path to your service account JSON file
        $serviceAccountPath = __DIR__ . '/spcc-database-firebase-adminsdk-fbsvc-5f7afc98b9.json';
        
        if (!file_exists($serviceAccountPath)) {
            throw new Exception('Firebase service account file not found: ' . $serviceAccountPath);
        }
        
        try {
            // Initialize Firebase with service account
            $factory = (new Factory)
                ->withServiceAccount($serviceAccountPath)
                ->withDatabaseUri('https://spcc-database-default-rtdb.firebaseio.com/');
            
            // Get Firebase services
            $this->database = $factory->createDatabase();
            $this->auth = $factory->createAuth();
            
        } catch (Exception $e) {
            throw new Exception('Failed to initialize Firebase: ' . $e->getMessage());
        }
    }
    
    public function getDatabase() {
        return $this->database;
    }
    
    public function getAuth() {
        return $this->auth;
    }
    

    
    /**
     * Push data to Firebase Realtime Database
     */
    public function pushData($path, $data) {
        try {
            $reference = $this->database->getReference($path);
            $reference->set($data);
            return ['success' => true, 'message' => 'Data pushed successfully to ' . $path];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error pushing data: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get data from Firebase Realtime Database
     */
    public function getData($path) {
        try {
            $reference = $this->database->getReference($path);
            $snapshot = $reference->getSnapshot();
            return $snapshot->getValue();
        } catch (Exception $e) {
            return ['error' => 'Error getting data: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update data in Firebase Realtime Database
     */
    public function updateData($path, $data) {
        try {
            $reference = $this->database->getReference($path);
            $reference->update($data);
            return ['success' => true, 'message' => 'Data updated successfully at ' . $path];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error updating data: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete data from Firebase Realtime Database
     */
    public function deleteData($path) {
        try {
            $reference = $this->database->getReference($path);
            $reference->remove();
            return ['success' => true, 'message' => 'Data deleted successfully from ' . $path];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error deleting data: ' . $e->getMessage()];
        }
    }
    
    /**
     * Create custom token for user authentication
     */
    public function createCustomToken($uid, $claims = []) {
        try {
            $token = $this->auth->createCustomToken($uid, $claims);
            return $token->toString();
        } catch (Exception $e) {
            return ['error' => 'Error creating custom token: ' . $e->getMessage()];
        }
    }
    
    /**
     * Verify ID token from client
     */
    public function verifyIdToken($idToken) {
        try {
            $verifiedToken = $this->auth->verifyIdToken($idToken);
            return $verifiedToken;
        } catch (Exception $e) {
            return ['error' => 'Error verifying token: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get user by UID
     */
    public function getUser($uid) {
        try {
            $user = $this->auth->getUser($uid);
            return $user;
        } catch (Exception $e) {
            return ['error' => 'Error getting user: ' . $e->getMessage()];
        }
    }
    
    /**
     * Create user with email and password
     */
    public function createUser($email, $password, $displayName = null) {
        try {
            $userProperties = [
                'email' => $email,
                'password' => $password
            ];
            
            if ($displayName) {
                $userProperties['displayName'] = $displayName;
            }
            
            $user = $this->auth->createUser($userProperties);
            return $user;
        } catch (Exception $e) {
            return ['error' => 'Error creating user: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update user
     */
    public function updateUser($uid, $properties) {
        try {
            $user = $this->auth->updateUser($uid, $properties);
            return $user;
        } catch (Exception $e) {
            return ['error' => 'Error updating user: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete user
     */
    public function deleteUser($uid) {
        try {
            $this->auth->deleteUser($uid);
            return ['success' => true, 'message' => 'User deleted successfully'];
        } catch (Exception $e) {
            return ['error' => 'Error deleting user: ' . $e->getMessage()];
        }
    }
}

// No direct output in production; this file is included by API endpoints
?>
