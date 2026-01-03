<?php
/**
 * ACSLite Customer Portal API
 * 
 * Endpoints (via direct call or path):
 * - POST with serial_number    - Save ONU location with customer credentials
 * - POST with username only    - Customer login  
 * - GET with sn parameter      - Get device data for customer
 */

ini_set('display_errors', 0); // Suppress errors to ensure valid JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database configuration
$config = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'acs',
    'username' => 'root',
    'password' => 'secret123'
];

// Try to load from .env file
$envFile = '/opt/acs/.env';
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    if (preg_match('/DB_DSN=([^:]+):([^@]+)@tcp\(([^:]+):(\d+)\)\/([^?]+)/', $envContent, $matches)) {
        $config['username'] = $matches[1];
        $config['password'] = $matches[2];
        $config['host'] = $matches[3];
        $config['port'] = (int)$matches[4];
        $config['dbname'] = $matches[5];
    }
}

// ACS API base URL (for device data) - Go ACS server on port 7547
$GENIEACS_URL = 'http://127.0.0.1:7547';

/**
 * Get database connection
 */
function getDB($config) {
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Send JSON response
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/**
 * Get request body as JSON
 */
function getJsonBody() {
    $body = file_get_contents('php://input');
    return json_decode($body, true) ?: [];
}

/**
 * Hash password using bcrypt
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Verify password against hash
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// ========================================
// Helper: Get Device from Go ACS API
// ========================================
function getGenieDevice($serialNumber = '') {
    global $GENIEACS_URL;
    $device = [
        'serial_number' => $serialNumber,
        'product_class' => 'Unknown',
        'manufacturer' => 'Unknown',
        'ip_address' => null,
        'last_inform_time' => null,
        'ssid' => null,
        'pppoe_user' => null,
        'rx_power' => null,
        'temperature' => null,
        'online' => false,
        'parameters' => []
    ];
    
    if (empty($serialNumber) || $serialNumber === 'test') return $device;

    // Go ACS API endpoint
    $acsUrl = "{$GENIEACS_URL}/api/devices";
    
    // Get API key from .env
    $apiKey = 'secret'; // Default
    $envFile = '/opt/acs/.env';
    if (file_exists($envFile)) {
        $envContent = file_get_contents($envFile);
        if (preg_match('/API_KEY=(.+)/', $envContent, $matches)) {
            $apiKey = trim($matches[1]);
        }
    }

    $response = null;
    
    // Try file_get_contents first with API key header
    try {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nX-API-Key: {$apiKey}\r\n"
            ]
        ]);
        $response = @file_get_contents($acsUrl, false, $context);
    } catch (Exception $e) {
        // Silently fail
    }

    // Fallback to curl if available
    if (!$response && function_exists('curl_init')) {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $acsUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ["X-API-Key: {$apiKey}", "Accept: application/json"]
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            // Silently fail
        }
    }

    // Parse response
    if ($response) {
        $result = json_decode($response, true);
        $devices = [];
        
        // Handle different response formats
        if (isset($result['data'])) {
            $devices = $result['data'];
        } elseif (is_array($result)) {
            $devices = $result;
        }
        
        // Find device by serial number
        foreach ($devices as $d) {
            $sn = $d['serial_number'] ?? $d['_id'] ?? '';
            if (strpos($sn, $serialNumber) !== false || $sn === $serialNumber) {
                $device['product_class'] = $d['product_class'] ?? $d['_deviceId']['_ProductClass'] ?? 'Unknown';
                $device['manufacturer'] = $d['manufacturer'] ?? $d['_deviceId']['_Manufacturer'] ?? 'Unknown';
                $device['ip_address'] = $d['ip_address'] ?? $d['ip'] ?? null;
                $device['last_inform_time'] = $d['last_inform_time'] ?? $d['_lastInform'] ?? null;
                $device['rx_power'] = $d['rx_power'] ?? null;
                $device['temperature'] = $d['temperature'] ?? null;
                $device['online'] = $d['online'] ?? false;
                
                // Extract from parameters array
                $params = $d['parameters'] ?? [];
                
                // PPPoE Username - check multiple possible paths
                $pppoeKeys = [
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.2.Username'
                ];
                foreach ($pppoeKeys as $key) {
                    if (!empty($params[$key])) {
                        $device['pppoe_user'] = $params[$key];
                        break;
                    }
                }
                
                // WiFi SSID - check multiple possible paths
                $ssidKeys = [
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID'
                ];
                foreach ($ssidKeys as $key) {
                    if (!empty($params[$key])) {
                        $device['ssid'] = $params[$key];
                        break;
                    }
                }
                
                // External IP Address (if ip_address is empty)
                if (empty($device['ip_address'])) {
                    $ipKeys = [
                        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
                        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANIPConnection.1.ExternalIPAddress'
                    ];
                    foreach ($ipKeys as $key) {
                        if (!empty($params[$key])) {
                            $device['ip_address'] = $params[$key];
                            break;
                        }
                    }
                }
                
                // Store full parameters for reference
                $device['parameters'] = $params;
                break;
            }
        }
    }
    
    return $device;
}

// ========================================
// JSON Storage Helper Functions
// ========================================
$JSON_FILE = __DIR__ . '/../data/customers.json';

function getJsonData() {
    global $JSON_FILE;
    if (!file_exists($JSON_FILE)) {
        return ['customers' => []];
    }
    $content = file_get_contents($JSON_FILE);
    return json_decode($content, true) ?: ['customers' => []];
}

function saveJsonData($data) {
    global $JSON_FILE;
    $dir = dirname($JSON_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($JSON_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonBody();

// ========================================
// POST Request Handler
// ========================================
if ($method === 'POST') {
    $db = getDB($config);
    // Note: We proceed even if DB fails, using JSON fallback

    // Check if this is a LOGIN request (has username+password but NO serial_number)
    $isLoginRequest = !empty($data['username']) && !empty($data['password']) && empty($data['serial_number']);
    
    // Check if this is a SAVE LOCATION request (has serial_number)
    $isSaveLocationRequest = !empty($data['serial_number']);

    // ---- CUSTOMER LOGIN ----
    if ($isLoginRequest) {
        $username = $data['username'];
        $password = $data['password'];

        // 1. Try Database First
        if ($db) {
            try {
                $stmt = $db->prepare("SELECT serial_number, name, username, password, latitude, longitude FROM onu_locations WHERE username = ?");
                $stmt->execute([$username]);
                $location = $stmt->fetch();

                if ($location && verifyPassword($password, $location['password'])) {
                    // Fetch complete device data from Go ACS
                    $deviceInfo = getGenieDevice($location['serial_number']);
                    
                    jsonResponse([
                        'success' => true,
                        'source' => 'database',
                        'serial_number' => $location['serial_number'],
                        'device' => $deviceInfo,
                        'location' => [
                            'name' => $location['name'],
                            'username' => $location['username'],
                            'latitude' => (float)$location['latitude'],
                            'longitude' => (float)$location['longitude']
                        ]
                    ]);
                }
            } catch (PDOException $e) {
                // error_log("DB Login checking failed: " . $e->getMessage());
            }
        }

        // 2. Fallback to JSON
        $jsonData = getJsonData();
        $customers = $jsonData['customers'] ?? [];
        
        $usernameLower = strtolower($username);
        if (isset($customers[$usernameLower])) {
            $cust = $customers[$usernameLower];
            // Verify password (in JSON stored as hash or plain if old data)
            if (verifyPassword($password, $cust['password']) || $password === $cust['password']) {
                 // Fetch complete device data
                 $deviceInfo = getGenieDevice($cust['serial_number']);

                 jsonResponse([
                    'success' => true,
                    'source' => 'json',
                    'serial_number' => $cust['serial_number'],
                    'device' => $deviceInfo,
                    'location' => [
                        'name' => $cust['username'],
                        'username' => $cust['username'],
                        'latitude' => (float)($cust['latitude'] ?? 0),
                        'longitude' => (float)($cust['longitude'] ?? 0)
                    ]
                ]);
            }
        }

        jsonResponse(['success' => false, 'message' => 'Invalid username or password'], 401);
    }

    // ---- SAVE ONU LOCATION ----
    if ($isSaveLocationRequest) {
        if (empty($data['username'])) {
            jsonResponse(['success' => false, 'message' => 'Username is required'], 400);
        }
        
        $serialNumber = $data['serial_number'];
        $username = $data['username'];
        $password = $data['password'] ?? null;
        $latitude = (float)$data['latitude'];
        $longitude = (float)$data['longitude'];
        $hashedPassword = $password ? hashPassword($password) : null;
        
        $successDB = false;
        $messageDB = "";

        // 1. Try Save to Database
        if ($db) {
            try {
                // Check if record exists
                $stmt = $db->prepare("SELECT id, password FROM onu_locations WHERE serial_number = ?");
                $stmt->execute([$serialNumber]);
                $existing = $stmt->fetch();

                if ($existing) {
                    // Update
                    if ($hashedPassword) {
                        $stmt = $db->prepare("UPDATE onu_locations SET name = ?, username = ?, password = ?, latitude = ?, longitude = ?, updated_at = NOW() WHERE serial_number = ?");
                        $stmt->execute([$username, $username, $hashedPassword, $latitude, $longitude, $serialNumber]);
                    } else {
                        $stmt = $db->prepare("UPDATE onu_locations SET name = ?, username = ?, latitude = ?, longitude = ?, updated_at = NOW() WHERE serial_number = ?");
                        $stmt->execute([$username, $username, $latitude, $longitude, $serialNumber]);
                    }
                } else {
                    // Insert
                    if (!$hashedPassword) {
                         jsonResponse(['success' => false, 'message' => 'Password is required for new user'], 400);
                    }
                    $stmt = $db->prepare("INSERT INTO onu_locations (serial_number, name, username, password, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$serialNumber, $username, $username, $hashedPassword, $latitude, $longitude]);
                }
                $successDB = true;
            } catch (PDOException $e) {
                // error_log("DB Save Error: " . $e->getMessage());
                $messageDB = $e->getMessage();
            }
        }

        // 2. Always Save to JSON (Backup/Primary if DB fails)
        try {
            $jsonData = getJsonData();
            $customers = $jsonData['customers'] ?? [];
            
            $usernameLower = strtolower($username);
            
            // Remove old entry if serial moved
            foreach ($customers as $key => $val) {
                if (($val['serial_number'] ?? '') === $serialNumber) {
                    if ($key !== $usernameLower) {
                         unset($customers[$key]);
                    }
                }
            }

            // Update/Create data
            $customerData = [
                'serial_number' => $serialNumber,
                'username' => $username,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'updated_at' => date('c')
            ];
            
            if ($hashedPassword) {
                $customerData['password'] = $hashedPassword;
            } else if (isset($customers[$usernameLower]['password'])) {
                 $customerData['password'] = $customers[$usernameLower]['password'];
            }

            $customers[$usernameLower] = $customerData;
            $jsonData['customers'] = $customers;
            saveJsonData($jsonData);
            
        } catch (Exception $e) {
            // error_log("JSON Save Error: " . $e->getMessage());
        }

        if ($successDB) {
            jsonResponse(['success' => true, 'message' => 'Location and login data saved to Database']);
        } else {
            jsonResponse(['success' => true, 'message' => 'Saved to local JSON storage (Database unavailable)', 'db_error' => $messageDB]);
        }
    }
    
    // If we get here, invalid POST request
    jsonResponse(['success' => false, 'message' => 'Invalid request. Provide either username+password (login) or serial_number (save location)'], 400);
}

// ========================================
// GET Request Handler - Get Device Data
// ========================================
if ($method === 'GET') {
    $serialNumber = $_GET['sn'] ?? '';
    
    if (empty($serialNumber)) {
        jsonResponse(['success' => false, 'message' => 'Serial number is required (use ?sn=XXX)'], 400);
    }

    $db = getDB($config);
    
    // Get location data from database
    $location = null;
    if ($db) {
        try {
            $stmt = $db->prepare("SELECT name, latitude, longitude FROM onu_locations WHERE serial_number = ?");
            $stmt->execute([$serialNumber]);
            $location = $stmt->fetch();
        } catch (PDOException $e) {
            // error_log("Get location error: " . $e->getMessage());
        }
    }

    // Reuse helper to get device
    $device = getGenieDevice($serialNumber);

    // Return response
    jsonResponse([
        'success' => true,
        'device' => $device,
        'location' => $location ?: null
    ]);
}

// Route not found
jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
