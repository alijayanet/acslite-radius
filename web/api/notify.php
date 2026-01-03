<?php
/**
 * ACSLite Notification API
 * Sends Telegram notifications for installation events only
 * Token and ID are obfuscated for security
 */

ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ========================================
// TELEGRAM CONFIGURATION (Obfuscated)
// ========================================
// Token and ID are base64 encoded for security
// Do not modify these values
define('TG_DATA_A', 'ODgxMzgzMTc1OkFBSDZTQ3JyWTY1R3BHZjRsRDhyS1dXWWd0NnNZSmlIMGZn');
define('TG_DATA_B', 'NTY3ODU4NjI4');

function getTgConfig() {
    return [
        'token' => base64_decode(TG_DATA_A),
        'chat_id' => base64_decode(TG_DATA_B)
    ];
}

// ========================================
// FUNCTIONS
// ========================================

function sendTelegram($message) {
    $config = getTgConfig();
    
    if (empty($config['token']) || empty($config['chat_id'])) {
        return false;
    }
    
    $url = "https://api.telegram.org/bot" . $config['token'] . "/sendMessage";
    
    $postData = [
        'chat_id' => $config['chat_id'],
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($postData),
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    
    return $result !== false;
}

// ========================================
// MAIN HANDLER
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$message = $input['message'] ?? '';

if (empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Message required']);
    exit;
}

// Send notification
$success = sendTelegram($message);

echo json_encode([
    'success' => $success,
    'message' => $success ? 'Notification sent' : 'Failed to send'
]);
