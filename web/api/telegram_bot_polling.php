#!/usr/bin/env php
<?php
/**
 * Telegram Bot Long Polling Service for ACS-Lite
 * 
 * This script runs as a background service and uses long polling
 * instead of webhooks, making it perfect for local applications
 * without HTTPS or public IP.
 * 
 * Features:
 * - No webhook required (no HTTPS needed)
 * - Works on local network
 * - Auto-reconnect on errors
 * - Graceful shutdown
 * 
 * Usage:
 * php telegram_bot_polling.php
 * 
 * Or run as systemd service (recommended)
 */

// Prevent timeout
set_time_limit(0);
ini_set('max_execution_time', 0);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ========================================
// CONFIGURATION
// ========================================

$SCRIPT_DIR = __DIR__;
$LOG_FILE = '/var/log/telegram_bot.log';
$PID_FILE = '/var/run/telegram_bot.pid';

// Load configuration from database or file
function loadConfig() {
    $pdo = getDB();
    
    // Try database first
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT bot_token FROM telegram_config WHERE is_active = 1 LIMIT 1");
            $config = $stmt->fetch();
            
            if ($config && !empty($config['bot_token'])) {
                // Get admin chat IDs
                $stmt = $pdo->query("SELECT chat_id FROM telegram_admins WHERE is_active = 1");
                $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                return [
                    'bot_token' => $config['bot_token'],
                    'admin_chat_ids' => $admins
                ];
            }
        } catch (Exception $e) {
            logMessage("Database config load failed: " . $e->getMessage());
        }
    }
    
    // Fallback to file
    $configFile = $SCRIPT_DIR . '/../data/admin.json';
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
        $botToken = $config['telegram']['bot_token'] ?? '';
        $adminIds = $config['telegram']['admin_chat_ids'] ?? [];
        
        if (!empty($botToken)) {
            return [
                'bot_token' => $botToken,
                'admin_chat_ids' => $adminIds
            ];
        }
    }
    
    return null;
}

// ========================================
// DATABASE CONNECTION
// ========================================
function getDB() {
    static $pdo = null;
    
    if ($pdo !== null) {
        return $pdo;
    }
    
    $envFile = '/opt/acs/.env';
    $config = [
        'host' => '127.0.0.1',
        'port' => '3306',
        'dbname' => 'acs',
        'username' => 'root',
        'password' => 'h6Uems6h4HmW1y7'
    ];
    
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'DB_DSN=') === 0) {
                $dsn = substr($line, 7);
                if (preg_match('/^([^:]+):([^@]*)@tcp\(([^:]+):(\d+)\)\/(.+)/', $dsn, $m)) {
                    $config['username'] = $m[1];
                    $config['password'] = $m[2];
                    $config['host'] = $m[3];
                    $config['port'] = $m[4];
                    $config['dbname'] = preg_replace('/\?.*/', '', $m[5]);
                }
            }
        }
    }
    
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => true
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        logMessage("Database connection failed: " . $e->getMessage());
        return null;
    }
}

// ========================================
// LOGGING
// ========================================
function logMessage($message) {
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    
    // Write to log file
    @file_put_contents($LOG_FILE, $logEntry, FILE_APPEND);
    
    // Also output to console
    echo $logEntry;
}

// ========================================
// TELEGRAM API
// ========================================
function telegramRequest($method, $data = []) {
    global $BOT_TOKEN;
    
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        logMessage("Telegram API error: HTTP {$httpCode}");
        return null;
    }
    
    return json_decode($result, true);
}

function getUpdates($offset = 0, $timeout = 30) {
    return telegramRequest('getUpdates', [
        'offset' => $offset,
        'timeout' => $timeout,
        'allowed_updates' => json_encode(['message', 'callback_query'])
    ]);
}

// ========================================
// MESSAGE PROCESSING
// ========================================
function processUpdate($update) {
    global $ADMIN_CHAT_IDS;
    
    // Extract chat ID
    $chatId = null;
    
    if (isset($update['message'])) {
        $chatId = $update['message']['chat']['id'];
        $text = $update['message']['text'] ?? '';
        
        // Check if user is authorized
        if (!in_array((string)$chatId, $ADMIN_CHAT_IDS)) {
            telegramRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "❌ Unauthorized. Contact admin to get access."
            ]);
            logMessage("Unauthorized access attempt from chat ID: {$chatId}");
            return;
        }
        
        // Process command
        if (strpos($text, '/') === 0) {
            processCommand($chatId, $text);
        }
        
    } elseif (isset($update['callback_query'])) {
        $chatId = $update['callback_query']['message']['chat']['id'];
        $callbackId = $update['callback_query']['id'];
        $data = $update['callback_query']['data'];
        $messageId = $update['callback_query']['message']['message_id'];
        
        // Check if user is authorized
        if (!in_array((string)$chatId, $ADMIN_CHAT_IDS)) {
            telegramRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => 'Unauthorized'
            ]);
            return;
        }
        
        // Process callback
        processCallback($chatId, $messageId, $callbackId, $data);
    }
}

function processCommand($chatId, $text) {
    // Parse command and arguments
    $parts = explode(' ', $text, 2);
    $command = strtolower($parts[0]);
    $args = $parts[1] ?? '';
    
    // Include the main webhook handler functions
    global $SCRIPT_DIR;
    require_once($SCRIPT_DIR . '/telegram_webhook.php');
    
    // Call the command handler
    handleCommand($chatId, $command, $args);
}

function processCallback($chatId, $messageId, $callbackId, $data) {
    // Include the main webhook handler functions
    global $SCRIPT_DIR;
    require_once($SCRIPT_DIR . '/telegram_webhook.php');
    
    // Call the callback handler
    handleCallback($chatId, $messageId, $callbackId, $data);
}

// ========================================
// SIGNAL HANDLERS
// ========================================
$running = true;

function signalHandler($signal) {
    global $running, $PID_FILE;
    
    logMessage("Received signal {$signal}, shutting down gracefully...");
    $running = false;
    
    // Remove PID file
    if (file_exists($PID_FILE)) {
        unlink($PID_FILE);
    }
}

// Register signal handlers
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGINT, 'signalHandler');
}

// ========================================
// MAIN LOOP
// ========================================
function main() {
    global $running, $PID_FILE, $BOT_TOKEN, $ADMIN_CHAT_IDS;
    
    logMessage("===========================================");
    logMessage("Telegram Bot Long Polling Service Starting");
    logMessage("===========================================");
    
    // Load configuration
    $config = loadConfig();
    if (!$config || empty($config['bot_token'])) {
        logMessage("ERROR: Bot token not configured!");
        logMessage("Please set bot_token in database (telegram_config) or admin.json");
        exit(1);
    }
    
    $BOT_TOKEN = $config['bot_token'];
    $ADMIN_CHAT_IDS = $config['admin_chat_ids'];
    
    logMessage("Bot token loaded: " . substr($BOT_TOKEN, 0, 10) . "...");
    logMessage("Authorized admins: " . count($ADMIN_CHAT_IDS));
    
    // Write PID file
    file_put_contents($PID_FILE, getmypid());
    
    // Delete webhook (if set)
    logMessage("Removing webhook (switching to long polling)...");
    telegramRequest('deleteWebhook');
    sleep(1);
    
    // Get bot info
    $botInfo = telegramRequest('getMe');
    if ($botInfo && $botInfo['ok']) {
        $botName = $botInfo['result']['username'];
        logMessage("Bot connected: @{$botName}");
    } else {
        logMessage("ERROR: Failed to connect to Telegram API");
        exit(1);
    }
    
    // Send startup notification to admins
    foreach ($ADMIN_CHAT_IDS as $adminId) {
        telegramRequest('sendMessage', [
            'chat_id' => $adminId,
            'text' => "🤖 <b>ACS-Lite Bot Started</b>\n\n✅ Long polling mode active\n🕐 " . date('Y-m-d H:i:s') . "\n\nKetik /menu untuk mulai",
            'parse_mode' => 'HTML'
        ]);
    }
    
    logMessage("Starting long polling loop...");
    
    $offset = 0;
    $errorCount = 0;
    $maxErrors = 5;
    
    while ($running) {
        // Process signals
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        try {
            // Get updates with long polling (30 second timeout)
            $response = getUpdates($offset, 30);
            
            if (!$response || !isset($response['ok'])) {
                $errorCount++;
                logMessage("Failed to get updates (error {$errorCount}/{$maxErrors})");
                
                if ($errorCount >= $maxErrors) {
                    logMessage("Too many errors, restarting...");
                    sleep(10);
                    $errorCount = 0;
                }
                
                sleep(2);
                continue;
            }
            
            // Reset error count on success
            $errorCount = 0;
            
            if (!isset($response['result']) || empty($response['result'])) {
                // No updates, continue polling
                continue;
            }
            
            // Process each update
            foreach ($response['result'] as $update) {
                try {
                    logMessage("Processing update ID: {$update['update_id']}");
                    processUpdate($update);
                    
                    // Update offset to mark this update as processed
                    $offset = $update['update_id'] + 1;
                    
                } catch (Exception $e) {
                    logMessage("Error processing update: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            $errorCount++;
            logMessage("Exception in main loop: " . $e->getMessage());
            sleep(2);
        }
    }
    
    logMessage("Bot stopped gracefully");
}

// ========================================
// START
// ========================================
main();
