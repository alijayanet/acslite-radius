<?php
/**
 * Settings API
 * Central configuration management for ACS-Lite
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

// Configuration file paths
$SETTINGS_FILE = __DIR__ . '/../data/settings.json';
$MIKROTIK_FILE = __DIR__ . '/../data/mikrotik.json';
$ADMIN_FILE = __DIR__ . '/../data/admin.json';
$ENV_FILE = '/opt/acs/.env';

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// ========================================
// DATABASE CONNECTION
// ========================================
function getAcsPDO() {
    static $pdo = null;
    
    if ($pdo !== null) {
        return $pdo;
    }
    
    global $ENV_FILE;
    
    // Default configuration
    $config = [
        'host' => '127.0.0.1',
        'port' => '3306',
        'dbname' => 'acs',
        'username' => 'root',
        'password' => 'secret123'
    ];
    
    // Try to get from .env
    if (file_exists($ENV_FILE)) {
        $lines = file($ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
    
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4",
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    return $pdo;
}

function getDefaultSettings() {
    return [
        'general' => [
            'site_name' => 'ACS-Lite ISP Manager',
            'company_name' => 'My ISP',
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'date_format' => 'd/m/Y',
            'language' => 'id',
            'address' => '',
            'phone' => '',
            'email' => ''
        ],
        'acs' => [
            'api_url' => 'http://localhost:7547',
            'api_key' => 'secret',
            'periodic_inform_interval' => 300,
            'auto_refresh_interval' => 15
        ],
        'hotspot' => [
            'backend' => 'mikrotik',
            'backup_to_radius' => false,
            'selected_router_id' => 'router1',
            'radius_server_ip' => '',
            'radius' => [
                'enabled' => false,
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_name' => 'radius',
                'db_user' => 'radius',
                'db_pass' => ''
            ]
        ],
        'telegram' => [
            'enabled' => false,
            'bot_token' => '',
            'chat_id' => '',
            'notify_isolir' => true,
            'notify_payment' => true,
            'notify_new_device' => true
        ],
        'billing' => [
            'enabled' => false,
            'due_day' => 1,
            'grace_period' => 7,
            'auto_isolir' => true,
            'isolir_profile' => 'isolir'
        ],
        'whatsapp' => [
            'enabled' => false,
            'api_url' => '',
            'api_key' => ''
        ]
    ];
}

// ========================================
// LOAD SETTINGS (Database-backed with file fallback)
// ========================================
function loadSettings() {
    global $SETTINGS_FILE;
    
    // Try to load from database first
    try {
        $pdo = getAcsPDO();
        
        // Check if settings table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
        if ($stmt->rowCount() === 0) {
            // Table doesn't exist, fallback to file
            error_log("[Settings] Table 'settings' not found, using file fallback");
            return loadSettingsFromFile();
        }
        
        $stmt = $pdo->query("SELECT category, settings_json FROM settings");
        $rows = $stmt->fetchAll();
        
        $settings = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['settings_json'], true);
            if ($decoded !== null) {
                $settings[$row['category']] = $decoded;
            }
        }
        
        // Merge with defaults
        $defaults = getDefaultSettings();
        return array_replace_recursive($defaults, $settings);
        
    } catch (Exception $e) {
        // Database error, fallback to file
        error_log("[Settings] Database error: " . $e->getMessage() . ", using file fallback");
        return loadSettingsFromFile();
    }
}

// ========================================
// LOAD SETTINGS FROM FILE (Fallback)
// ========================================
function loadSettingsFromFile() {
    global $SETTINGS_FILE;
    
    $defaults = getDefaultSettings();
    
    if (file_exists($SETTINGS_FILE)) {
        $loaded = json_decode(file_get_contents($SETTINGS_FILE), true) ?: [];
        return array_replace_recursive($defaults, $loaded);
    }
    
    return $defaults;
}

// ========================================
// SAVE SETTINGS (Database-backed with file fallback)
// ========================================
function saveSettings($settings) {
    global $SETTINGS_FILE;
    
    // Try to save to database first
    try {
        $pdo = getAcsPDO();
        
        // Check if settings table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
        if ($stmt->rowCount() === 0) {
            // Table doesn't exist, fallback to file
            error_log("[Settings] Table 'settings' not found, saving to file instead");
            return saveSettingsToFile($settings);
        }
        
        foreach ($settings as $category => $data) {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            $stmt = $pdo->prepare("
                INSERT INTO settings (category, settings_json, updated_by) 
                VALUES (:category, :json, 'settings_api')
                ON DUPLICATE KEY UPDATE 
                    settings_json = :json,
                    updated_at = NOW(),
                    updated_by = 'settings_api'
            ");
            
            $stmt->execute([
                'category' => $category,
                'json' => $json
            ]);
        }
        
        // Also save to file as backup
        saveSettingsToFile($settings);
        
        return true;
        
    } catch (Exception $e) {
        // Database error, fallback to file
        error_log("[Settings] Database save error: " . $e->getMessage() . ", saving to file instead");
        return saveSettingsToFile($settings);
    }
}

// ========================================
// SAVE SETTINGS TO FILE (Fallback)
// ========================================
function saveSettingsToFile($settings) {
    global $SETTINGS_FILE;
    
    // Ensure directory exists
    $dir = dirname($SETTINGS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    return file_put_contents($SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function loadMikrotikConfig() {
    global $MIKROTIK_FILE;
    
    if (file_exists($MIKROTIK_FILE)) {
        return json_decode(file_get_contents($MIKROTIK_FILE), true) ?: [];
    }
    
    return [
        'routers' => [
            [
                'id' => 'router1',
                'name' => 'Main Router',
                'ip' => '192.168.88.1',
                'port' => 8728,
                'username' => 'admin',
                'password' => '',
                'isolir_profile' => 'isolir',
                'default_profile' => 'default',
                'enabled' => true
            ]
        ]
    ];
}

function saveMikrotikConfig($config) {
    global $MIKROTIK_FILE;
    
    $dir = dirname($MIKROTIK_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    return file_put_contents($MIKROTIK_FILE, json_encode($config, JSON_PRETTY_PRINT));
}

function loadAdminCredentials() {
    global $ADMIN_FILE;
    
    if (file_exists($ADMIN_FILE)) {
        $data = json_decode(file_get_contents($ADMIN_FILE), true);
        return $data['admin'] ?? ['username' => 'admin', 'password' => ''];
    }
    
    return ['username' => 'admin', 'password' => ''];
}

function saveAdminCredentials($username, $password) {
    global $ADMIN_FILE;
    
    $dir = dirname($ADMIN_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $data = ['admin' => ['username' => $username, 'password' => $password]];
    return file_put_contents($ADMIN_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function loadEnvConfig() {
    global $ENV_FILE;
    
    $config = [
        'ACS_PORT' => '7547',
        'DB_DSN' => '',
        'API_KEY' => 'secret',
        'WEB_DIR' => '/opt/acs/web'
    ];
    
    if (file_exists($ENV_FILE)) {
        $lines = file($ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $config[trim($key)] = trim($value);
            }
        }
    }
    
    return $config;
}

function getSystemInfo() {
    $info = [
        'hostname' => gethostname(),
        'php_version' => phpversion(),
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
        'disk_free' => round(disk_free_space('/') / 1024 / 1024 / 1024, 2) . ' GB'
    ];
    
    // Check services
    $info['services'] = [
        'acslite' => @shell_exec('systemctl is-active acslite 2>/dev/null') ? trim(shell_exec('systemctl is-active acslite')) : 'unknown',
        'php_api' => @shell_exec('systemctl is-active acs-php-api 2>/dev/null') ? trim(shell_exec('systemctl is-active acs-php-api')) : 'unknown',
        'mariadb' => @shell_exec('systemctl is-active mariadb 2>/dev/null') ? trim(shell_exec('systemctl is-active mariadb')) : 'unknown'
    ];
    
    return $info;
}

// ========================================
// MAIN HANDLER
// ========================================
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    $action = $input['action'] ?? $action;
}

try {
    switch ($action) {
        // ---- GET ALL SETTINGS ----
        case 'get':
        case '':
            $settings = loadSettings();
            $mikrotik = loadMikrotikConfig();
            $admin = loadAdminCredentials();
            $env = loadEnvConfig();
            $system = getSystemInfo();
            
            // Hide passwords
            $admin['password'] = $admin['password'] ? '********' : '';
            foreach ($mikrotik['routers'] as &$r) {
                $r['password'] = $r['password'] ? '********' : '';
            }
            if (!empty($settings['telegram']['bot_token'])) {
                $settings['telegram']['bot_token'] = substr($settings['telegram']['bot_token'], 0, 10) . '...';
            }
            
            jsonResponse([
                'success' => true,
                'settings' => $settings,
                'mikrotik' => $mikrotik,
                'admin' => $admin,
                'env' => $env,
                'system' => $system
            ]);
            break;
        
        // ---- GET ALL SETTINGS (for Invoice/Print - no sensitive data hidden) ----
        case 'get_all':
            $settings = loadSettings();
            jsonResponse([
                'success' => true,
                'settings' => $settings
            ]);
            break;
            
        // ---- SAVE GENERAL SETTINGS ----
        case 'save_general':
            $settings = loadSettings();
            $settings['general'] = array_merge($settings['general'] ?? [], $input['general'] ?? []);
            saveSettings($settings);
            jsonResponse(['success' => true, 'message' => 'General settings saved']);
            break;
            
        // ---- SAVE ACS SETTINGS ----
        case 'save_acs':
            $settings = loadSettings();
            $settings['acs'] = array_merge($settings['acs'] ?? [], $input['acs'] ?? []);
            saveSettings($settings);
            jsonResponse(['success' => true, 'message' => 'ACS settings saved']);
            break;

        // ---- SAVE HOTSPOT SETTINGS ----
        case 'save_hotspot':
            $settings = loadSettings();
            $settings['hotspot'] = array_merge($settings['hotspot'] ?? [], $input['hotspot'] ?? []);
            if (isset($input['hotspot']['radius'])) {
                $settings['hotspot']['radius'] = array_merge($settings['hotspot']['radius'] ?? [], $input['hotspot']['radius'] ?? []);
            }
            saveSettings($settings);
            jsonResponse(['success' => true, 'message' => 'Hotspot settings saved']);
            break;
            
        // ---- SAVE TELEGRAM SETTINGS ----
        case 'save_telegram':
            $settings = loadSettings();
            
            // Don't overwrite token if masked
            if (isset($input['telegram']['bot_token']) && strpos($input['telegram']['bot_token'], '...') !== false) {
                unset($input['telegram']['bot_token']);
            }
            
            $settings['telegram'] = array_merge($settings['telegram'] ?? [], $input['telegram'] ?? []);
            saveSettings($settings);
            jsonResponse(['success' => true, 'message' => 'Telegram settings saved']);
            break;
            
        // ---- SAVE BILLING SETTINGS ----
        case 'save_billing':
            $settings = loadSettings();
            $settings['billing'] = array_merge($settings['billing'] ?? [], $input['billing'] ?? []);
            saveSettings($settings);
            jsonResponse(['success' => true, 'message' => 'Billing settings saved']);
            break;
            
        // ---- SAVE MIKROTIK SETTINGS ----
        case 'save_mikrotik':
            $mikrotik = loadMikrotikConfig();
            
            if (!empty($input['routers'])) {
                foreach ($input['routers'] as $newRouter) {
                    $found = false;
                    foreach ($mikrotik['routers'] as &$existingRouter) {
                        if ($existingRouter['id'] === $newRouter['id']) {
                            // Keep password if not changed
                            if ($newRouter['password'] === '********' || empty($newRouter['password'])) {
                                $newRouter['password'] = $existingRouter['password'];
                            }
                            $existingRouter = array_merge($existingRouter, $newRouter);
                            $found = true;
                            break;
                        }
                    }
                    
                    if (!$found) {
                        $newRouter['id'] = $newRouter['id'] ?? 'router_' . time();
                        $mikrotik['routers'][] = $newRouter;
                    }
                }
            }
            
            saveMikrotikConfig($mikrotik);
            jsonResponse(['success' => true, 'message' => 'MikroTik settings saved']);
            break;
            
        // ---- ADD MIKROTIK ROUTER ----
        case 'add_router':
            $mikrotik = loadMikrotikConfig();
            
            $newRouter = [
                'id' => 'router_' . time(),
                'name' => $input['name'] ?? 'New Router',
                'ip' => $input['ip'] ?? '',
                'port' => (int)($input['port'] ?? 8728),
                'username' => $input['username'] ?? 'admin',
                'password' => $input['password'] ?? '',
                'isolir_profile' => $input['isolir_profile'] ?? 'isolir',
                'default_profile' => $input['default_profile'] ?? 'default',
                'enabled' => true
            ];
            
            $mikrotik['routers'][] = $newRouter;
            saveMikrotikConfig($mikrotik);
            
            jsonResponse(['success' => true, 'message' => 'Router added', 'router' => $newRouter]);
            break;
            
        // ---- DELETE MIKROTIK ROUTER ----
        case 'delete_router':
            $routerId = $input['router_id'] ?? '';
            
            if (empty($routerId)) {
                jsonResponse(['success' => false, 'error' => 'Router ID required'], 400);
            }
            
            $mikrotik = loadMikrotikConfig();
            $mikrotik['routers'] = array_filter($mikrotik['routers'], function($r) use ($routerId) {
                return $r['id'] !== $routerId;
            });
            $mikrotik['routers'] = array_values($mikrotik['routers']);
            
            saveMikrotikConfig($mikrotik);
            jsonResponse(['success' => true, 'message' => 'Router deleted']);
            break;
            
        // ---- CHANGE ADMIN PASSWORD ----
        case 'change_password':
            $currentPassword = $input['current_password'] ?? '';
            $newPassword = $input['new_password'] ?? '';
            $confirmPassword = $input['confirm_password'] ?? '';
            
            if (empty($newPassword)) {
                jsonResponse(['success' => false, 'error' => 'New password required'], 400);
            }
            
            if ($newPassword !== $confirmPassword) {
                jsonResponse(['success' => false, 'error' => 'Passwords do not match'], 400);
            }
            
            $admin = loadAdminCredentials();
            
            // Verify current password
            if (!empty($admin['password']) && $admin['password'] !== $currentPassword) {
                jsonResponse(['success' => false, 'error' => 'Current password is incorrect'], 400);
            }
            
            saveAdminCredentials($admin['username'], $newPassword);
            jsonResponse(['success' => true, 'message' => 'Password changed successfully']);
            break;
            
        // ---- CHANGE ADMIN USERNAME ----
        case 'change_username':
            $newUsername = $input['new_username'] ?? '';
            $password = $input['password'] ?? '';
            
            if (empty($newUsername)) {
                jsonResponse(['success' => false, 'error' => 'Username required'], 400);
            }
            
            $admin = loadAdminCredentials();
            
            // Verify password
            if (!empty($admin['password']) && $admin['password'] !== $password) {
                jsonResponse(['success' => false, 'error' => 'Password is incorrect'], 400);
            }
            
            saveAdminCredentials($newUsername, $admin['password']);
            jsonResponse(['success' => true, 'message' => 'Username changed successfully']);
            break;
            
        // ---- TEST TELEGRAM ----
        case 'test_telegram':
            $settings = loadSettings();
            $token = $input['bot_token'] ?? '';
            $chatId = $input['chat_id'] ?? '';
            
            // If token is masked or empty, try to get from settings
            if (empty($token) || strpos($token, '...') !== false) {
                $token = $settings['telegram']['bot_token'] ?? '';
            }
            
            // If chat_id is empty, try to get from settings
            if (empty($chatId)) {
                $chatId = $settings['telegram']['chat_id'] ?? '';
            }
            
            // Try to get from database if still empty
            if (empty($token) || empty($chatId)) {
                try {
                    $envFile = '/opt/acs/.env';
                    $dbConfig = [
                        'host' => '127.0.0.1',
                        'port' => '3306',
                        'dbname' => 'acs',
                        'username' => 'root',
                        'password' => 'secret123'
                    ];
                    
                    if (file_exists($envFile)) {
                        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        foreach ($lines as $line) {
                            if (strpos($line, 'DB_DSN=') === 0) {
                                $dsn = substr($line, 7);
                                if (preg_match('/^([^:]+):([^@]*)@tcp\(([^:]+):(\d+)\)\/(.+)/', $dsn, $m)) {
                                    $dbConfig['username'] = $m[1];
                                    $dbConfig['password'] = $m[2];
                                    $dbConfig['host'] = $m[3];
                                    $dbConfig['port'] = $m[4];
                                    $dbConfig['dbname'] = preg_replace('/\?.*/', '', $m[5]);
                                }
                            }
                        }
                    }
                    
                    $pdo = new PDO(
                        "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset=utf8mb4",
                        $dbConfig['username'],
                        $dbConfig['password']
                    );
                    
                    // Get token from database
                    if (empty($token)) {
                        $stmt = $pdo->query("SELECT bot_token FROM telegram_config WHERE is_active = 1 LIMIT 1");
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $token = $row['bot_token'];
                        }
                    }
                    
                    // Get chat_id from database
                    if (empty($chatId)) {
                        $stmt = $pdo->query("SELECT chat_id FROM telegram_admins WHERE is_active = 1 LIMIT 1");
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $chatId = $row['chat_id'];
                        }
                    }
                } catch (Exception $e) {
                    // Ignore database errors
                }
            }
            
            if (empty($token)) {
                jsonResponse(['success' => false, 'error' => 'Bot token tidak ditemukan. Masukkan token atau simpan di Settings/Database.'], 400);
            }
            
            if (empty($chatId)) {
                jsonResponse(['success' => false, 'error' => 'Chat ID tidak ditemukan. Masukkan chat ID atau simpan di Settings/Database.'], 400);
            }
            
            $message = "🔔 Test notification from ACS-Lite\n\n";
            $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
            $message .= "Server: " . gethostname();
            
            $url = "https://api.telegram.org/bot{$token}/sendMessage";
            $data = [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML'
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                jsonResponse(['success' => false, 'error' => 'Curl error: ' . $error], 500);
            }
            
            if (empty($response)) {
                jsonResponse(['success' => false, 'error' => 'Empty response from Telegram API (HTTP ' . $httpCode . ')'], 500);
            }
            
            $result = json_decode($response, true);
            
            if (!$result) {
                jsonResponse(['success' => false, 'error' => 'Invalid JSON response: ' . substr($response, 0, 200)], 500);
            }
            
            if ($result['ok']) {
                jsonResponse(['success' => true, 'message' => 'Test message sent successfully!']);
            } else {
                jsonResponse(['success' => false, 'error' => $result['description'] ?? 'Unknown error'], 500);
            }
            break;
            
        // ---- RESTART SERVICE ----
        case 'restart_service':
            $service = $input['service'] ?? '';
            $allowed = ['acslite', 'acs-php-api', 'mariadb'];
            
            if (!in_array($service, $allowed)) {
                jsonResponse(['success' => false, 'error' => 'Invalid service name'], 400);
            }
            
            $output = shell_exec("systemctl restart {$service} 2>&1");
            $status = trim(shell_exec("systemctl is-active {$service} 2>&1"));
            
            jsonResponse([
                'success' => $status === 'active',
                'message' => "Service {$service} restarted",
                'status' => $status
            ]);
            break;
            
        default:
            jsonResponse([
                'success' => true,
                'message' => 'Settings API',
                'endpoints' => [
                    'GET ?action=get' => 'Get all settings',
                    'POST action=save_general' => 'Save general settings',
                    'POST action=save_acs' => 'Save ACS settings',
                    'POST action=save_hotspot' => 'Save hotspot settings',
                    'POST action=save_telegram' => 'Save Telegram settings',
                    'POST action=save_billing' => 'Save billing settings',
                    'POST action=save_mikrotik' => 'Save MikroTik settings',
                    'POST action=add_router' => 'Add MikroTik router',
                    'POST action=delete_router' => 'Delete MikroTik router',
                    'POST action=change_password' => 'Change admin password',
                    'POST action=test_telegram' => 'Test Telegram notification',
                    'POST action=restart_service' => 'Restart system service'
                ]
            ]);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
