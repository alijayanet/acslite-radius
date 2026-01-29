<?php
/**
 * Voucher API - Hotspot Voucher Management
 * 
 * Handles voucher generation, sales, tracking, and management
 * Compatible with RouterOS 6 and 7
 * 
 * @author ACS-Lite Team
 * @version 1.0.0
 */

// Disable error display to prevent breaking JSON output
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database configuration logic
$config = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'acs',
    'username' => 'root',
    'password' => '1234'
];

// Try to load from various possible .env locations
$envPaths = [
    __DIR__ . '/../.env',
    __DIR__ . '/../../.env',
    '/opt/acs/.env'
];

foreach ($envPaths as $envFile) {
    if (file_exists($envFile)) {
        $envContent = file_get_contents($envFile);
        if (preg_match('/DB_HOST=(.+)/', $envContent, $m)) $config['host'] = trim($m[1]);
        if (preg_match('/DB_PORT=(.+)/', $envContent, $m)) $config['port'] = (int)trim($m[1]);
        if (preg_match('/DB_NAME=(.+)/', $envContent, $m)) $config['dbname'] = trim($m[1]);
        if (preg_match('/DB_USER=(.+)/', $envContent, $m)) $config['username'] = trim($m[1]);
        if (preg_match('/DB_PASS=(.+)/', $envContent, $m)) $config['password'] = trim($m[1]);
        
        // Handle DB_DSN format if present
        if (preg_match('/DB_DSN=([^:]+):([^@]+)@tcp\(([^:]+):(\d+)\)\/([^?]+)/', $envContent, $matches)) {
            $config['username'] = $matches[1];
            $config['password'] = $matches[2];
            $config['host'] = $matches[3];
            $config['port'] = (int)$matches[4];
            $config['dbname'] = $matches[5];
        }
        break;
    }
}

// Optional config file fallback
if (file_exists(__DIR__ . '/../config/database.php')) {
    include_once __DIR__ . '/../config/database.php';
}

require_once __DIR__ . '/MikroTikAPI.php';

function loadHotspotSettings() {
    $settingsFile = __DIR__ . '/../data/settings.json';
    $defaults = [
        'hotspot' => [
            'backend' => 'mikrotik',
            'radius' => [
                'enabled' => false
            ]
        ]
    ];

    if (file_exists($settingsFile)) {
        $loaded = json_decode(file_get_contents($settingsFile), true) ?: [];
        $merged = array_replace_recursive($defaults, $loaded);
        return $merged['hotspot'] ?? $defaults['hotspot'];
    }

    return $defaults['hotspot'];
}

class VoucherAPI {
    
    private $db;
    private $mikrotik;
    private $rosVersion = 6; // Default to ROS 6
    
    public function __construct($db) {
        $this->db = $db;
        $this->mikrotik = new MikroTikAPI();
        
        // Detect ROS version
        try {
            $this->rosVersion = $this->detectROSVersion();
        } catch (Exception $e) {
            error_log("Failed to detect ROS version: " . $e->getMessage());
        }
    }
    
    /**
     * Main request handler
     */
    public function handleRequest() {
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        
        try {
            switch ($action) {
                // Profile management
                case 'get_profiles':
                    return $this->getProfiles();
                case 'get_mikrotik_profiles':
                    return $this->getMikroTikProfiles();
                case 'add_profile':
                    return $this->addProfile();
                case 'update_profile':
                    return $this->updateProfile();
                case 'delete_profile':
                    return $this->deleteProfile();
                    
                // Voucher generation
                case 'generate':
                    return $this->generateVouchers();
                case 'generate_preview':
                    return $this->generatePreview();
                    
                // Voucher management
                case 'list':
                    return $this->listVouchers();
                case 'get_voucher':
                    return $this->getVoucher();
                case 'delete_voucher':
                    return $this->deleteVoucher();
                case 'bulk_delete':
                    return $this->bulkDelete();
                    
                // Sales
                case 'sell':
                    return $this->sellVoucher();
                case 'sales_history':
                    return $this->getSalesHistory();
                    
                // Batch management
                case 'list_batches':
                    return $this->listBatches();
                case 'get_batch':
                    return $this->getBatch();
                case 'delete_batch':
                    return $this->deleteBatch();
                    
                // Statistics
                case 'stats':
                    return $this->getStatistics();
                case 'dashboard':
                    return $this->getDashboardData();
                    
                // Sync with MikroTik
                case 'sync':
                    return $this->syncWithMikrotik();
                case 'test':
                    return $this->testConnection();
                    
                // Debug: List all MikroTik profiles
                case 'list_mikrotik_profiles':
                    return $this->listMikroTikProfilesDebug();
                    
                default:
                    throw new Exception("Invalid action: $action");
            }
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * Detect RouterOS version
     */
    private function detectROSVersion() {
        try {
            $resource = $this->mikrotik->getResource();
            if (isset($resource['version'])) {
                preg_match('/^(\d+)\./', $resource['version'], $matches);
                return isset($matches[1]) ? (int)$matches[1] : 6;
            }
        } catch (Exception $e) {
            error_log("ROS version detection failed: " . $e->getMessage());
        }
        return 6; // Default to ROS 6
    }
    
    /**
     * Generate batch ID
     * Format: vc-acslite-YYYYMMDD-HHMMSS
     */
    private function generateBatchId() {
        return 'vc-acslite-' . date('Ymd-His');
    }
    
    /**
     * Generate random voucher code
     */
    private function generateCode($length = 6, $charset = 'ALPHANUMERIC', $excludeSimilar = true) {
        $chars = '';
        
        switch (strtoupper($charset)) {
            case 'UPPERCASE':
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
                break;
            case 'LOWERCASE':
                $chars = 'abcdefghjkmnpqrstuvwxyz';
                break;
            case 'NUMBERS':
                $chars = '23456789';
                break;
            case 'ALPHANUMERIC_LOWER':
                $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
                break;
            case 'MIXED':
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
                break;
            case 'ALPHANUMERIC':
            default:
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                break;
        }
        
        if ($excludeSimilar) {
            $chars = str_replace(['I', 'O', '0', '1', 'l'], '', $chars);
        }
        
        $code = '';
        $charsLength = strlen($chars);
        
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $charsLength - 1)];
        }
        
        return $code;
    }
    
    /**
     * Convert duration to seconds
     */
    private function durationToSeconds($duration) {
        $value = (int)substr($duration, 0, -1);
        $unit = substr($duration, -1);
        
        switch ($unit) {
            case 'h': return $value * 3600;
            case 'd': return $value * 86400;
            case 'w': return $value * 604800;
            default: return 0;
        }
    }
    
    /**
     * Parse MikroTik duration format to simple format
     * Converts "3h00m00s" -> "3h", "1d00h00m00s" -> "1d", etc.
     */
    private function parseMikroTikDuration($duration) {
        if (empty($duration) || $duration === '0s') {
            return '0s';
        }
        
        // If already in simple format (e.g., "3h", "1d"), return as is
        if (preg_match('/^(\d+)([hdw])$/', $duration)) {
            return $duration;
        }
        
        // Parse complex MikroTik format (e.g., "3h00m00s", "1d00h00m00s")
        $hours = 0;
        $days = 0;
        $weeks = 0;
        
        // Extract weeks
        if (preg_match('/(\d+)w/', $duration, $matches)) {
            $weeks = (int)$matches[1];
        }
        
        // Extract days
        if (preg_match('/(\d+)d/', $duration, $matches)) {
            $days = (int)$matches[1];
        }
        
        // Extract hours
        if (preg_match('/(\d+)h/', $duration, $matches)) {
            $hours = (int)$matches[1];
        }
        
        // Return the most significant unit
        if ($weeks > 0) {
            return $weeks . 'w';
        } elseif ($days > 0) {
            return $days . 'd';
        } elseif ($hours > 0) {
            return $hours . 'h';
        }
        
        // Fallback to original if we couldn't parse it
        return $duration;
    }
    
    /**
     * Parse Mikhmon on-login script format
     * Format: :put (",rem,2000,1d,3000,,Disable,");
     * Array: [0]=empty, [1]=remark, [2]=price_display, [3]=duration, [4]=price_actual, [5]=empty, [6]=status
     * Returns: ['price' => 3000, 'duration' => '1d']
     */
    private function parseMikhmonScript($script) {
        $result = [
            'price' => 0,
            'duration' => ''
        ];
        
        if (empty($script)) {
            return $result;
        }
        
        // Cari pattern: :put (",xxx,PRICE,DURATION,PRICE_ACTUAL,xxx
        // Format Mikhmon: ,remark,price_display,duration,price_actual,,status,
        if (preg_match('/:put\s*\("([^"]+)"\)/', $script, $matches)) {
            $comment = $matches[1];
            $parts = explode(',', $comment);
            
            error_log("Mikhmon script parts: " . json_encode($parts));
            
            // Format Mikhmon lengkap:
            // [0] = empty
            // [1] = remark (rem)
            // [2] = price display (2000)
            // [3] = duration (1d)
            // [4] = price actual (3000) <- INI YANG KITA PAKAI
            // [5] = empty
            // [6] = status (Disable)
            
            if (count($parts) >= 5) {
                // Price ACTUAL di index 4
                if (isset($parts[4]) && is_numeric($parts[4])) {
                    $result['price'] = (int)$parts[4];
                    error_log("Found price at index 4: {$parts[4]}");
                }
                // Fallback ke index 2 kalau index 4 kosong
                elseif (isset($parts[2]) && is_numeric($parts[2])) {
                    $result['price'] = (int)$parts[2];
                    error_log("Using fallback price at index 2: {$parts[2]}");
                }
                
                // Duration di index 3 (format: 1d, 3h, 1w, dll)
                if (isset($parts[3]) && preg_match('/^\d+[hdw]$/', $parts[3])) {
                    $result['duration'] = $parts[3];
                    error_log("Found duration: {$parts[3]}");
                }
            }
        }
        
        error_log("Parsed Mikhmon data: price={$result['price']}, duration={$result['duration']}");
        return $result;
    }
    
    /**
     * Format date for RouterOS
     */
    private function formatDateForROS($timestamp) {
        if ($this->rosVersion >= 7) {
            // ROS 7: ISO 8601 format
            return date('Y-m-d H:i:s', $timestamp);
        } else {
            // ROS 6: mmm/dd/yyyy HH:MM:SS
            return date('M/d/Y H:i:s', $timestamp);
        }
    }
    
    /**
     * Get hotspot profiles
     */
    private function getProfiles() {
        $stmt = $this->db->prepare("
            SELECT * FROM hotspot_profiles 
            WHERE is_active = 1 
            ORDER BY name
        ");
        $stmt->execute();
        $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->success(['profiles' => $profiles]);
    }
    
    /**
     * Get MikroTik hotspot profiles
     */
    private function getMikroTikProfiles() {
        try {
            // Load MikroTik config
            if (!file_exists(__DIR__ . '/mikrotik_config.php')) {
                // Should not happen if installed correctly, but fallback to install.sh defaults or fail
                return $this->error("MikroTik config not found");
            }
            
            $mtConfig = require __DIR__ . '/mikrotik_config.php';
            
            // Connect to MikroTik
            if (!$this->mikrotik->isConnected()) {
                if (!$this->mikrotik->connect($mtConfig['host'], $mtConfig['user'], $mtConfig['password'], $mtConfig['port'])) {
                    return $this->error("Failed to connect to MikroTik: " . $this->mikrotik->getError());
                }
            }
            
            $profiles = $this->mikrotik->getHotspotProfiles();
            return $this->success(['profiles' => $profiles]);
            
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }    
    /**
     * Add hotspot profile with AUTO-INJECT on-login script (Mikhmon style)
     */
    private function addProfile() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $name = $data['name'] ?? '';
        $price = $data['price'] ?? 0;
        $duration = $data['duration'] ?? '';
        $rateLimit = $data['rate_limit'] ?? '';
        $sharedUsers = $data['shared_users'] ?? 1;
        $validityType = $data['validity_type'] ?? 'uptime';
        
        // Calculate duration in seconds
        $durationSeconds = $this->durationToSeconds($duration);
        
        // Create on-login script (Mikhmon format)
        $onLoginScript = ":put \",rem,{$price},{$duration},,,Disable,\";";
        
        // Insert to database
        $stmt = $this->db->prepare("
            INSERT INTO hotspot_profiles 
            (name, price, duration, duration_seconds, rate_limit, shared_users, validity_type, on_login_script)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $name, $price, $duration, $durationSeconds, 
            $rateLimit, $sharedUsers, $validityType, $onLoginScript
        ]);
        
        // AUTO-INJECT to MikroTik using NEW method
        try {
            // Load MikroTik config
            $mtConfig = require __DIR__ . '/mikrotik_config.php';
            
            // Connect to MikroTik
            if ($this->mikrotik->connect($mtConfig['host'], $mtConfig['user'], $mtConfig['password'], $mtConfig['port'])) {
                
                // Use createHotspotProfileWithScript() untuk inject script
                $success = $this->mikrotik->createHotspotProfileWithScript(
                    $name,
                    $price,
                    $duration,
                    $rateLimit,
                    $sharedUsers
                );
                
                if (!$success) {
                    error_log("MikroTik profile creation warning: " . $this->mikrotik->getError());
                    // Tidak return error, karena sudah tersimpan di database
                }
                
                $this->mikrotik->disconnect();
            } else {
                error_log("Failed to connect to MikroTik: " . $this->mikrotik->getError());
            }
        } catch (Exception $e) {
            error_log("MikroTik integration error: " . $e->getMessage());
        }
        
        return $this->success([
            'message' => 'Profile created successfully and auto-injected to MikroTik'
        ]);
    }
    
    /**
     * Update existing profile
     */
    private function updateProfile() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $name = $data['name'] ?? '';
        $price = floatval($data['price'] ?? 0);
        $duration = $data['duration'] ?? '';
        $rate_limit = $data['rate_limit'] ?? '';
        $validity_type = $data['validity_type'] ?? 'uptime';
        
        if (empty($name)) {
            throw new Exception("Profile name is required");
        }
        
        // Check if profile exists
        $stmt = $this->db->prepare("SELECT id FROM hotspot_profiles WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch()) {
            throw new Exception("Profile not found");
        }
        
        // Update profile in database
        $stmt = $this->db->prepare("
            UPDATE hotspot_profiles 
            SET price = ?, duration = ?, rate_limit = ?, validity_type = ?, updated_at = NOW()
            WHERE name = ?
        ");
        $stmt->execute([$price, $duration, $rate_limit, $validity_type, $name]);
        
        return $this->success(['message' => 'Profile updated successfully']);
    }
    
    /**
     * Generate vouchers
     */
    private function generateVouchers() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $profile = $data['profile'] ?? '';
        $quantity = (int)($data['quantity'] ?? 1);
        $prefix = $data['prefix'] ?? '';
        $codeLength = (int)($data['code_length'] ?? 6);
        $charset = $data['charset'] ?? 'ALPHANUMERIC';
        $passwordOption = $data['password_option'] ?? 'same_as_code';
        $customPassword = $data['custom_password'] ?? '';
        
        // PRIORITAS: Cek MikroTik dulu, baru database
        $profileData = null;
        $foundInMikroTik = false;
        
        error_log("Generating vouchers for profile: $profile");
        
        // 1. CEK MIKROTIK DULU
        // Load config dari mikrotik.json (sama seperti mikrotik_api.php)
        $configFile = __DIR__ . '/../data/mikrotik.json';
        $mtConfig = null;
        
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            $config = json_decode($content, true);
            
            // Ambil router pertama
            if (isset($config['routers']) && !empty($config['routers'])) {
                $router = $config['routers'][0];
                $mtConfig = [
                    'host' => $router['ip'],
                    'user' => $router['username'],
                    'password' => $router['password'],
                    'port' => $router['port']
                ];
                error_log("Loaded MikroTik config from mikrotik.json: {$mtConfig['host']}");
            }
        }
        
        // Fallback ke mikrotik_config.php
        if (!$mtConfig && file_exists(__DIR__ . '/mikrotik_config.php')) {
            $mtConfig = require __DIR__ . '/mikrotik_config.php';
            error_log("Loaded MikroTik config from mikrotik_config.php (fallback)");
        }
        
        if ($mtConfig) {
            
            // Connect if needed
            if (!$this->mikrotik->isConnected()) {
                $connected = $this->mikrotik->connect($mtConfig['host'], $mtConfig['user'], $mtConfig['password'], $mtConfig['port']);
                if (!$connected) {
                    error_log("Failed to connect to MikroTik: " . $this->mikrotik->getError());
                } else {
                    error_log("Successfully connected to MikroTik at {$mtConfig['host']}");
                }
            }
            
            if ($this->mikrotik->isConnected()) {
                $mtProfiles = $this->mikrotik->getHotspotProfiles();
                error_log("Found " . count($mtProfiles) . " profiles in MikroTik");
                
                foreach ($mtProfiles as $p) {
                    if ($p['name'] === $profile) {
                        error_log("Profile '$profile' found in MikroTik!");
                        
                        // Parse on-login script untuk ambil harga dan durasi (format Mikhmon)
                        $onLogin = $p['on-login'] ?? '';
                        $parsedData = $this->parseMikhmonScript($onLogin);
                        
                        // Parse session-timeout dari MikroTik
                        $duration = $p['session-timeout'] ?? '0s';
                        $duration = $this->parseMikroTikDuration($duration);
                        
                        // Gunakan durasi dari script kalau ada, kalau tidak dari session-timeout
                        if (!empty($parsedData['duration'])) {
                            $duration = $parsedData['duration'];
                            error_log("Using duration from on-login script: $duration");
                        } elseif (empty($duration) || $duration === '0s') {
                            $duration = '1h';
                            error_log("No duration found, defaulting to 1h");
                        }
                        
                        $profileData = [
                            'name' => $p['name'],
                            'price' => $parsedData['price'] ?? 0,
                            'duration' => $duration,
                            'duration_seconds' => $this->durationToSeconds($duration),
                            'is_active' => 1
                        ];
                        
                        $foundInMikroTik = true;
                        error_log("Profile data: price={$profileData['price']}, duration={$profileData['duration']}");
                        break;
                    }
                }
                
                if (!$foundInMikroTik) {
                    $availableProfiles = array_column($mtProfiles, 'name');
                    error_log("Profile '$profile' not found in MikroTik. Available: " . implode(', ', $availableProfiles));
                }
            } else {
                error_log("Not connected to MikroTik");
            }
        }
        
        // 2. FALLBACK KE DATABASE (kalau tidak ada di MikroTik)
        if (!$foundInMikroTik) {
            error_log("Checking database for profile '$profile'...");
            $stmt = $this->db->prepare("SELECT * FROM hotspot_profiles WHERE name = ?");
            $stmt->execute([$profile]);
            $profileData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($profileData) {
                error_log("Profile '$profile' found in database");
            } else {
                error_log("Profile '$profile' NOT FOUND in database or MikroTik!");
                throw new Exception("Profile tidak ditemukan di Database atau MikroTik: $profile");
            }
        }
        
        // Generate batch ID
        $batchId = $this->generateBatchId();
        
        // Create batch record
        $stmt = $this->db->prepare("
            INSERT INTO voucher_batches 
            (batch_id, profile, quantity, price, duration, prefix, code_length, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $batchId, $profile, $quantity, $profileData['price'], 
            $profileData['duration'], $prefix, $codeLength, 'admin'
        ]);
        
        $vouchers = [];
        $mikrotikUsers = [];
        
        for ($i = 0; $i < $quantity; $i++) {
            // Generate unique code
            do {
                $code = $this->generateCode($codeLength, $charset);
                $username = $prefix . $code;
                
                // Check if username exists
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM hotspot_vouchers WHERE username = ?");
                $stmt->execute([$username]);
                $exists = $stmt->fetchColumn() > 0;
            } while ($exists);
            
            // Generate password based on option
            switch ($passwordOption) {
                case 'same_as_username':
                    $password = $username;
                    break;
                case 'same_as_code':
                    $password = $code;
                    break;
                case 'random':
                    $password = $this->generateCode($codeLength, $charset);
                    break;
                case 'custom':
                    $password = $customPassword;
                    break;
                default:
                    $password = $code;
            }
            
            // Create MikroTik comment
            $mikrotikComment = "{$batchId},{$profileData['price']},{$profileData['duration']},,";
            
            // Insert to database
            $stmt = $this->db->prepare("
                INSERT INTO hotspot_vouchers 
                (batch_id, username, password, profile, price, duration, limit_uptime, mikrotik_comment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $batchId, $username, $password, $profile, 
                $profileData['price'], $profileData['duration'], 
                $profileData['duration_seconds'], $mikrotikComment
            ]);
            
            $vouchers[] = [
                'username' => $username,
                'password' => $password,
                'profile' => $profile,
                'price' => $profileData['price'],
                'duration' => $profileData['duration']
            ];
            
            // Prepare for MikroTik
            $mikrotikUsers[] = [
                'name' => $username,
                'password' => $password,
                'profile' => $profile,
                'limit-uptime' => $this->formatDurationForROS($profileData['duration']),
                'comment' => $mikrotikComment
            ];
        }

        $hotspotSettings = loadHotspotSettings();
        $hotspotBackend = $hotspotSettings['backend'] ?? 'mikrotik';
        if ($hotspotBackend === 'radius') {
            return $this->success([
                'message' => "Generated {$quantity} vouchers successfully. Hotspot backend is set to RADIUS; MikroTik auto-sync skipped. Run radius_sync.php (cron) to sync vouchers to RADIUS.",
                'batch_id' => $batchId,
                'vouchers' => $vouchers,
                'backend' => 'radius'
            ]);
        }
        
        // AUTO-SYNC to MikroTik (Mikhmon Style)
        try {
            // Gunakan $mtConfig yang sudah di-load sebelumnya
            if (!$mtConfig) {
                error_log("MikroTik config not available for sync");
                return $this->error("MikroTik config not found");
            }
            
            // Connect to MikroTik (re-use connection atau connect baru)
            if (!$this->mikrotik->isConnected()) {
                if (!$this->mikrotik->connect($mtConfig['host'], $mtConfig['user'], $mtConfig['password'], $mtConfig['port'])) {
                    error_log("Failed to connect to MikroTik for sync: " . $this->mikrotik->getError());
                    return $this->error("Vouchers created in database but failed to connect to MikroTik: " . $this->mikrotik->getError());
                }
            }
            
            // Add each user to MikroTik
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($mikrotikUsers as $user) {
                // Call with correct parameter signature
                $success = $this->mikrotik->addHotspotUser(
                    $user['name'],           // username
                    $user['password'],       // password
                    $user['profile'],        // profile
                    '',                      // mac (empty, akan di-bind saat first login)
                    $user['limit-uptime'],   // uptime
                    $user['comment']         // comment
                );
                
                if ($success) {
                    $successCount++;
                } else {
                    $errorCount++;
                    error_log("Failed to add user {$user['name']} to MikroTik: " . $this->mikrotik->getError());
                }
            }
            
            $this->mikrotik->disconnect();
            
            // Return dengan info sync
            $message = "Generated {$quantity} vouchers successfully";
            if ($successCount > 0) {
                $message .= ". Synced {$successCount} to MikroTik";
            }
            if ($errorCount > 0) {
                $message .= ". {$errorCount} failed to sync (check logs)";
            }
            
            return $this->success([
                'message' => $message,
                'batch_id' => $batchId,
                'vouchers' => $vouchers,
                'mikrotik_sync' => [
                    'success' => $successCount,
                    'failed' => $errorCount,
                    'total' => $quantity
                ],
                'debug' => [
                    'profile_data' => $profileData,
                    'parsed_price' => $profileData['price'],
                    'parsed_duration' => $profileData['duration']
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("MikroTik sync error: " . $e->getMessage());
            
            // Vouchers sudah dibuat di database, tapi gagal sync ke MikroTik
            return $this->success([
                'message' => "Generated {$quantity} vouchers in database. WARNING: Failed to sync to MikroTik: " . $e->getMessage(),
                'batch_id' => $batchId,
                'vouchers' => $vouchers,
                'mikrotik_sync' => [
                    'success' => 0,
                    'failed' => $quantity,
                    'total' => $quantity,
                    'error' => $e->getMessage()
                ]
            ]);
        }
    }
    
    /**
     * Format duration for RouterOS
     */
    private function formatDurationForROS($duration) {
        // Convert "3h" to "3h00m00s" format for ROS
        $value = (int)substr($duration, 0, -1);
        $unit = substr($duration, -1);
        
        switch ($unit) {
            case 'h':
                return "{$value}h00m00s";
            case 'd':
                return ($value * 24) . "h00m00s";
            case 'w':
                return ($value * 24 * 7) . "h00m00s";
            default:
                return $duration;
        }
    }
    
    /**
     * List vouchers with filters
     */
    private function listVouchers() {
        $batchId = $_GET['batch_id'] ?? '';
        $profile = $_GET['profile'] ?? '';
        $status = $_GET['status'] ?? '';
        $search = $_GET['search'] ?? '';
        $limit = max(1, min(1000, (int)($_GET['limit'] ?? 100)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        
        $sql = "SELECT * FROM hotspot_vouchers WHERE 1=1";
        $params = [];
        
        if ($batchId) {
            $sql .= " AND batch_id = ?";
            $params[] = $batchId;
        }
        
        if ($profile) {
            $sql .= " AND profile = ?";
            $params[] = $profile;
        }
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        if ($search) {
            $sql .= " AND (username LIKE ? OR comment LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        
        // Use direct integer values for LIMIT/OFFSET (safe since already cast to int)
        $sql .= " ORDER BY created_date DESC LIMIT {$limit} OFFSET {$offset}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $countSql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
        $countSql = preg_replace('/LIMIT.*/', '', $countSql);
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
        
        return $this->success([
            'vouchers' => $vouchers,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }
    
    /**
     * Sell voucher
     */
    private function sellVoucher() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $voucherId = $data['voucher_id'] ?? 0;
        $actualPrice = $data['actual_price'] ?? null;
        $customerName = $data['customer_name'] ?? '';
        $customerPhone = $data['customer_phone'] ?? '';
        $paymentMethod = $data['payment_method'] ?? 'cash';
        $notes = $data['notes'] ?? '';
        
        // Get voucher
        $stmt = $this->db->prepare("SELECT * FROM hotspot_vouchers WHERE id = ?");
        $stmt->execute([$voucherId]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$voucher) {
            throw new Exception("Voucher not found");
        }
        
        if ($voucher['status'] !== 'unused') {
            throw new Exception("Voucher already sold or used");
        }
        
        $price = $actualPrice ?? $voucher['price'];
        
        // Record sale
        $stmt = $this->db->prepare("
            INSERT INTO hotspot_sales 
            (voucher_id, batch_id, username, price, actual_price, customer_name, customer_phone, payment_method, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $voucherId, $voucher['batch_id'], $voucher['username'],
            $voucher['price'], $price, $customerName, $customerPhone, $paymentMethod, $notes
        ]);
        
        return $this->success(['message' => 'Voucher sold successfully']);
    }
    
    /**
     * Get statistics
     */
    private function getStatistics() {
        $stats = [];
        
        // Total vouchers
        $stmt = $this->db->query("SELECT COUNT(*) FROM hotspot_vouchers");
        $stats['total_vouchers'] = $stmt->fetchColumn();
        
        // By status
        $stmt = $this->db->query("
            SELECT status, COUNT(*) as count 
            FROM hotspot_vouchers 
            GROUP BY status
        ");
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Revenue
        $stmt = $this->db->query("SELECT SUM(actual_price) FROM hotspot_sales");
        $stats['total_revenue'] = $stmt->fetchColumn() ?? 0;
        
        // Today's sales
        $stmt = $this->db->query("
            SELECT COUNT(*), SUM(actual_price) 
            FROM hotspot_sales 
            WHERE DATE(sale_date) = CURDATE()
        ");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $stats['today_sales'] = $row[0] ?? 0;
        $stats['today_revenue'] = $row[1] ?? 0;
        
        return $this->success($stats);
    }
    
    /**
     * List batches
     */
    private function listBatches() {
        $stmt = $this->db->query("SELECT * FROM v_batch_summary ORDER BY created_date DESC");
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->success(['batches' => $batches]);
    }
    
    /**
     * Delete single voucher
     */
    private function deleteVoucher() {
        $data = json_decode(file_get_contents('php://input'), true);
        $voucherId = $data['voucher_id'] ?? 0;
        
        // Get voucher info first
        $stmt = $this->db->prepare("SELECT username FROM hotspot_vouchers WHERE id = ?");
        $stmt->execute([$voucherId]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$voucher) {
            throw new Exception("Voucher not found");
        }
        
        // Delete from database
        $stmt = $this->db->prepare("DELETE FROM hotspot_vouchers WHERE id = ?");
        $stmt->execute([$voucherId]);
        
        // Try to delete from MikroTik
        try {
            $this->mikrotik->deleteHotspotUser($voucher['username']);
        } catch (Exception $e) {
            error_log("Failed to delete user from MikroTik: " . $e->getMessage());
        }
        
        return $this->success(['message' => 'Voucher deleted successfully']);
    }
    
    /**
     * Delete profile
     */
    private function deleteProfile() {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = $data['name'] ?? '';
        
        if (empty($name)) {
            throw new Exception("Profile name is required");
        }
        
        // Check if profile has vouchers
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM hotspot_vouchers WHERE profile = ?");
        $stmt->execute([$name]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            throw new Exception("Cannot delete profile with existing vouchers ($count vouchers found)");
        }
        
        // Delete from database
        $stmt = $this->db->prepare("DELETE FROM hotspot_profiles WHERE name = ?");
        $stmt->execute([$name]);
        
        // Try to delete from MikroTik (if method exists)
        try {
            if ($this->mikrotik && method_exists($this->mikrotik, 'deleteHotspotProfile')) {
                $this->mikrotik->deleteHotspotProfile($name);
            }
        } catch (Throwable $e) {
            error_log("Failed to delete profile from MikroTik: " . $e->getMessage());
        }
        
        return $this->success(['message' => 'Profile deleted successfully']);
    }
    
    /**
     * Get sales history
     */
    private function getSalesHistory() {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        $sales = [];
        
        // Try to get from hotspot_sales first
        try {
            $stmt = $this->db->prepare("
                SELECT s.*, v.profile, v.duration
                FROM hotspot_sales s
                JOIN hotspot_vouchers v ON s.voucher_id = v.id
                WHERE DATE(s.sale_date) BETWEEN ? AND ?
                ORDER BY s.sale_date DESC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Table doesn't exist, fallback to hotspot_vouchers
        }
        
        // Fallback: get sold vouchers directly
        if (empty($sales)) {
            $stmt = $this->db->prepare("
                SELECT id, username, profile, price as actual_price, 
                       sold_date, comment as customer_name, 'cash' as payment_method
                FROM hotspot_vouchers
                WHERE status = 'sold' AND sold_date IS NOT NULL
                  AND DATE(sold_date) BETWEEN ? AND ?
                ORDER BY sold_date DESC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Calculate totals
        $totalSales = count($sales);
        $totalRevenue = array_sum(array_column($sales, 'actual_price'));
        
        return $this->success([
            'sales' => $sales,
            'total_sales' => $totalSales,
            'total_revenue' => $totalRevenue,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
    }
    
    /**
     * Success response
     */
    private function success($data = []) {
        return json_encode(array_merge(['success' => true], $data));
    }
    
    /**
     * Error response
     */
    private function error($message) {
        return json_encode([
            'success' => false,
            'error' => $message
        ]);
    }

    /**
     * Debug: List all MikroTik profiles with details
     */
    private function listMikroTikProfilesDebug() {
        try {
            if (!file_exists(__DIR__ . '/mikrotik_config.php')) {
                return $this->error("MikroTik config not found");
            }
            
            $mtConfig = require __DIR__ . '/mikrotik_config.php';
            
            // Connect
            if (!$this->mikrotik->isConnected()) {
                $connected = $this->mikrotik->connect($mtConfig['host'], $mtConfig['user'], $mtConfig['password'], $mtConfig['port']);
                if (!$connected) {
                    return $this->error("Failed to connect: " . $this->mikrotik->getError());
                }
            }
            
            $profiles = $this->mikrotik->getHotspotProfiles();
            
            // Parse each profile
            $debugData = [];
            foreach ($profiles as $p) {
                $onLogin = $p['on-login'] ?? '';
                $parsed = $this->parseMikhmonScript($onLogin);
                
                $debugData[] = [
                    'name' => $p['name'],
                    'session-timeout' => $p['session-timeout'] ?? 'none',
                    'rate-limit' => $p['rate-limit'] ?? 'none',
                    'on-login' => substr($onLogin, 0, 100) . (strlen($onLogin) > 100 ? '...' : ''),
                    'parsed_price' => $parsed['price'],
                    'parsed_duration' => $parsed['duration']
                ];
            }
            
            return $this->success([
                'total' => count($profiles),
                'profiles' => $debugData,
                'mikrotik_host' => $mtConfig['host']
            ]);
            
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Test connection
     */
    private function testConnection() {
        return $this->success([
            'message' => 'Voucher API is working',
            'database' => 'Connected',
            'mikrotik' => $this->mikrotik->isConnected() ? 'Connected' : 'Disconnected'
        ]);
    }
}

// Initialize and handle request
try {
    ob_start(); // Start output buffering
    
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
    $db = new PDO($dsn, $config['username'], $config['password']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $api = new VoucherAPI($db);
    $response = $api->handleRequest();
    
    ob_clean(); // Clear any warnings/extra output
    echo $response;
    
} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

