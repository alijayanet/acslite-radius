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

        // 1. Try Database (Customers Table from Billing)
        if ($db) {
            try {
                // Find user by portal_username (primary) or pppoe_username (fallback)
                $stmt = $db->prepare("SELECT * FROM customers WHERE portal_username = ? OR pppoe_username = ?");
                $stmt->execute([$username, $username]);
                $customer = $stmt->fetch();

                if ($customer && (verifyPassword($password, $customer['portal_password']))) {
                    // Fetch device data using Serial Number or PPPoE
                    $serial = $customer['onu_serial'];
                    if (!$serial && $customer['pppoe_username']) {
                        // Optional: Try to find serial via PPPoE from Go ACS (not implemented here)
                    }

                    $deviceInfo = getGenieDevice($serial);
                    
                    jsonResponse([
                        'success' => true,
                        'source' => 'billing_db',
                        'customer_id' => $customer['customer_id'],
                        'username' => $customer['portal_username'],
                        'name' => $customer['name'],
                        'serialNumber' => $serial, // For compatibility
                        'device' => $deviceInfo,
                        'customerData' => [ // Pass full customer data for dashboard
                            'id' => $customer['id'],
                            'name' => $customer['name'],
                            'phone' => $customer['phone'],
                            'address' => $customer['address'],
                            'package_id' => $customer['package_id'],
                            'pppoe_username' => $customer['pppoe_username']
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
        $isAdminTag = !empty($data['admin_tag']);
        
        // Username only required for customer tagging, not for admin tagging
        if (!$isAdminTag && empty($data['username'])) {
            jsonResponse(['success' => false, 'message' => 'Username is required'], 400);
        }
        
        $serialNumber = $data['serial_number'];
        $username = $data['username'] ?? $serialNumber; // Use serial number as default username for admin tags
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
                    // Update - only update password if provided
                    if ($hashedPassword) {
                        $stmt = $db->prepare("UPDATE onu_locations SET username = ?, password = ?, latitude = ?, longitude = ?, updated_at = NOW() WHERE serial_number = ?");
                        $stmt->execute([$username, $hashedPassword, $latitude, $longitude, $serialNumber]);
                    } else {
                        // For admin tag: only update location, keep existing username/password
                        if ($isAdminTag) {
                            $stmt = $db->prepare("UPDATE onu_locations SET latitude = ?, longitude = ?, updated_at = NOW() WHERE serial_number = ?");
                            $stmt->execute([$latitude, $longitude, $serialNumber]);
                        } else {
                            $stmt = $db->prepare("UPDATE onu_locations SET username = ?, latitude = ?, longitude = ?, updated_at = NOW() WHERE serial_number = ?");
                            $stmt->execute([$username, $latitude, $longitude, $serialNumber]);
                        }
                    }
                } else {
                    // Insert - for admin tag, skip if no password
                    if ($isAdminTag) {
                        // Admin tag without password: just save location only
                        $stmt = $db->prepare("INSERT INTO onu_locations (serial_number, name, username, latitude, longitude) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$serialNumber, $username, $username, $latitude, $longitude]);
                    } else {
                        // Customer tag: password required
                        if (!$hashedPassword) {
                             jsonResponse(['success' => false, 'message' => 'Password is required for new user'], 400);
                        }
                        $stmt = $db->prepare("INSERT INTO onu_locations (serial_number, name, username, password, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$serialNumber, $username, $username, $hashedPassword, $latitude, $longitude]);
                    }
                }
                $successDB = true;
            } catch (PDOException $e) {
                // error_log("DB Save Error: " . $e->getMessage());
                $messageDB = $e->getMessage();
            }
        }

        // 2. JSON Storage DISABLED - Using MySQL Database Only
        // Note: JSON fallback removed. All data stored in MySQL onu_locations table.
        // If you need JSON backup, uncomment the section below.
        /*
        try {
            $jsonData = getJsonData();
            $customers = $jsonData['customers'] ?? [];
            $usernameLower = strtolower($username);
            foreach ($customers as $key => $val) {
                if (($val['serial_number'] ?? '') === $serialNumber) {
                    if ($key !== $usernameLower) {
                         unset($customers[$key]);
                    }
                }
            }
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
            // JSON save error
        }
        */

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
