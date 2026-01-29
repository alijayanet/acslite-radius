<?php
/**
 * ACSLite Admin API
 * 
 * Endpoints:
 * - POST with action=login              - Admin login
 * - POST with action=change_password    - Change admin password
 * - POST with action=change_credentials - Change username & password
 */

ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ========================================
// PATHS
// ========================================
define('ADMIN_JSON_PATH', __DIR__ . '/../data/admin.json');

// ========================================
// HELPER FUNCTIONS
// ========================================

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function getAdminData() {
    if (!file_exists(ADMIN_JSON_PATH)) {
        // Create default admin file
        $default = ['admin' => ['username' => 'admin', 'password' => 'admin123']];
        file_put_contents(ADMIN_JSON_PATH, json_encode($default, JSON_PRETTY_PRINT));
        chmod(ADMIN_JSON_PATH, 0600);
        return $default;
    }
    
    $content = file_get_contents(ADMIN_JSON_PATH);
    return json_decode($content, true) ?: ['admin' => ['username' => 'admin', 'password' => 'admin123']];
}

function saveAdminData($data) {
    $result = file_put_contents(ADMIN_JSON_PATH, json_encode($data, JSON_PRETTY_PRINT));
    if ($result !== false) {
        chmod(ADMIN_JSON_PATH, 0600);
    }
    return $result !== false;
}

// ========================================
// MAIN HANDLER
// ========================================

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// GET - Check if API is available
if ($method === 'GET') {
    jsonResponse(['success' => true, 'message' => 'Admin API is running']);
}

// POST - Handle actions
if ($method === 'POST') {
    $action = $input['action'] ?? '';
    
    // ---- LOGIN ----
    if ($action === 'login') {
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(['success' => false, 'message' => 'Username and password required'], 400);
        }
        
        $adminData = getAdminData();
        $admin = $adminData['admin'] ?? null;
        
        if ($admin && $admin['username'] === $username && $admin['password'] === $password) {
            jsonResponse(['success' => true, 'message' => 'Login successful']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Invalid credentials'], 401);
        }
    }
    
    // ---- CHANGE PASSWORD ----
    if ($action === 'change_password') {
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword)) {
            jsonResponse(['success' => false, 'message' => 'Current and new password required'], 400);
        }
        
        $adminData = getAdminData();
        $admin = $adminData['admin'] ?? null;
        
        if (!$admin || $admin['password'] !== $currentPassword) {
            jsonResponse(['success' => false, 'message' => 'Current password is incorrect'], 401);
        }
        
        // Update password
        $adminData['admin']['password'] = $newPassword;
        
        if (saveAdminData($adminData)) {
            jsonResponse(['success' => true, 'message' => 'Password updated successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Failed to save new password'], 500);
        }
    }
    
    // ---- CHANGE CREDENTIALS (username + password) ----
    if ($action === 'change_credentials') {
        $currentPassword = $input['current_password'] ?? '';
        $newUsername = $input['new_username'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        
        if (empty($currentPassword) || empty($newUsername) || empty($newPassword)) {
            jsonResponse(['success' => false, 'message' => 'All fields required'], 400);
        }
        
        $adminData = getAdminData();
        $admin = $adminData['admin'] ?? null;
        
        if (!$admin || $admin['password'] !== $currentPassword) {
            jsonResponse(['success' => false, 'message' => 'Current password is incorrect'], 401);
        }
        
        // Update credentials
        $adminData['admin']['username'] = $newUsername;
        $adminData['admin']['password'] = $newPassword;
        
        if (saveAdminData($adminData)) {
            jsonResponse(['success' => true, 'message' => 'Credentials updated successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Failed to save credentials'], 500);
        }
    }
    
    jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
}

jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
