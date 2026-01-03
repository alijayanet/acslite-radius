<?php
/**
 * MikroTik API Test Backend
 * Test koneksi ke MikroTik dari server
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/MikroTikAPI.php';

$action = $_GET['action'] ?? 'help';

// Load MikroTik config dari mikrotik.json (sama seperti voucher_api.php dan mikrotik_api.php)
$configFile = __DIR__ . '/../data/mikrotik.json';
$mtConfig = [
    'host' => '192.168.8.1',
    'user' => 'admin',
    'password' => 'password',
    'port' => 8728
];

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
    }
}

function success($data = []) {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function error($message, $details = []) {
    echo json_encode(array_merge([
        'success' => false,
        'error' => $message
    ], $details));
    exit;
}

switch ($action) {
    case 'get_config':
        success([
            'config' => [
                'host' => $mtConfig['host'],
                'port' => $mtConfig['port'],
                'user' => $mtConfig['user']
            ]
        ]);
        break;
        
    case 'socket':
        // Test socket connection
        $socket = @fsockopen($mtConfig['host'], $mtConfig['port'], $errno, $errstr, 5);
        
        if ($socket) {
            fclose($socket);
            success([
                'message' => 'Socket connection successful',
                'host' => $mtConfig['host'],
                'port' => $mtConfig['port']
            ]);
        } else {
            error('Socket connection failed', [
                'errno' => $errno,
                'errstr' => $errstr,
                'host' => $mtConfig['host'],
                'port' => $mtConfig['port']
            ]);
        }
        break;
        
    case 'api_login':
        // Test MikroTik API login
        $api = new MikroTikAPI();
        $connected = $api->connect(
            $mtConfig['host'],
            $mtConfig['user'],
            $mtConfig['password'],
            $mtConfig['port']
        );
        
        if ($connected) {
            $api->disconnect();
            success([
                'message' => 'MikroTik API login successful',
                'host' => $mtConfig['host'],
                'user' => $mtConfig['user']
            ]);
        } else {
            error('MikroTik API login failed', [
                'error_detail' => $api->getError(),
                'host' => $mtConfig['host'],
                'port' => $mtConfig['port'],
                'user' => $mtConfig['user']
            ]);
        }
        break;
        
    case 'get_identity':
        // Get router identity
        $api = new MikroTikAPI();
        $connected = $api->connect(
            $mtConfig['host'],
            $mtConfig['user'],
            $mtConfig['password'],
            $mtConfig['port']
        );
        
        if (!$connected) {
            error('Connection failed', [
                'error_detail' => $api->getError()
            ]);
        }
        
        $identity = $api->getIdentity();
        $resource = $api->getResource();
        $api->disconnect();
        
        success([
            'identity' => $identity,
            'version' => $resource['version'] ?? 'unknown',
            'uptime' => $resource['uptime'] ?? 'unknown',
            'board_name' => $resource['board-name'] ?? 'unknown'
        ]);
        break;
        
    case 'get_profiles':
        // Get hotspot profiles
        $api = new MikroTikAPI();
        $connected = $api->connect(
            $mtConfig['host'],
            $mtConfig['user'],
            $mtConfig['password'],
            $mtConfig['port']
        );
        
        if (!$connected) {
            error('Connection failed', [
                'error_detail' => $api->getError()
            ]);
        }
        
        $profiles = $api->getHotspotProfiles();
        $api->disconnect();
        
        // Parse profiles
        $profileList = [];
        foreach ($profiles as $p) {
            $onLogin = $p['on-login'] ?? '';
            $hasScript = !empty($onLogin) && strpos($onLogin, ':put') !== false;
            
            $profileList[] = [
                'name' => $p['name'],
                'session-timeout' => $p['session-timeout'] ?? 'none',
                'rate-limit' => $p['rate-limit'] ?? 'none',
                'has_onlogin_script' => $hasScript,
                'script_preview' => $hasScript ? substr($onLogin, 0, 50) . '...' : 'none'
            ];
        }
        
        success([
            'total_profiles' => count($profiles),
            'profiles' => $profileList
        ]);
        break;
        
    default:
        error('Invalid action', [
            'available_actions' => [
                'get_config',
                'socket',
                'api_login',
                'get_identity',
                'get_profiles'
            ]
        ]);
}
