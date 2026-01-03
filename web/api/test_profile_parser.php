#!/usr/bin/env php
<?php
/**
 * Test MikroTik Profile Parser
 * Jalankan: php web/api/test_profile_parser.php
 */

echo "=== Test MikroTik Profile Parser ===\n\n";

// Load MikroTik API
require_once __DIR__ . '/MikroTikAPI.php';

// Load config
$configFile = __DIR__ . '/../data/mikrotik.json';
if (!file_exists($configFile)) {
    die("❌ Config file not found: $configFile\n");
}

$content = file_get_contents($configFile);
$config = json_decode($content, true);

if (!isset($config['routers'][0])) {
    die("❌ No router config found\n");
}

$router = $config['routers'][0];
echo "📡 Connecting to MikroTik: {$router['ip']}:{$router['port']}\n";

// Connect
$api = new MikroTikAPI();
$connected = $api->connect($router['ip'], $router['username'], $router['password'], $router['port']);

if (!$connected) {
    die("❌ Connection failed: " . $api->getError() . "\n");
}

echo "✅ Connected successfully!\n\n";

// Get profiles
$profiles = $api->getHotspotProfiles();
echo "📋 Found " . count($profiles) . " hotspot profiles:\n\n";

// Parser function (sama seperti di voucher_api.php)
function parseMikhmonScript($script) {
    $result = [
        'price' => 0,
        'duration' => ''
    ];
    
    if (empty($script)) {
        return $result;
    }
    
    if (preg_match('/:put\s*\("([^"]+)"\)/', $script, $matches)) {
        $comment = $matches[1];
        $parts = explode(',', $comment);
        
        echo "   📝 Script parts: " . json_encode($parts) . "\n";
        
        if (count($parts) >= 5) {
            // Price ACTUAL di index 4
            if (isset($parts[4]) && is_numeric($parts[4])) {
                $result['price'] = (int)$parts[4];
                echo "   💰 Found price at index 4: {$parts[4]}\n";
            }
            // Fallback ke index 2
            elseif (isset($parts[2]) && is_numeric($parts[2])) {
                $result['price'] = (int)$parts[2];
                echo "   💰 Using fallback price at index 2: {$parts[2]}\n";
            }
            
            // Duration di index 3
            if (isset($parts[3]) && preg_match('/^\d+[hdw]$/', $parts[3])) {
                $result['duration'] = $parts[3];
                echo "   ⏱️  Found duration: {$parts[3]}\n";
            }
        }
    } else {
        echo "   ⚠️  No :put pattern found in script\n";
    }
    
    return $result;
}

// Test each profile
foreach ($profiles as $p) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Profile: {$p['name']}\n";
    echo "Session Timeout: " . ($p['session-timeout'] ?? 'none') . "\n";
    echo "Rate Limit: " . ($p['rate-limit'] ?? 'none') . "\n";
    
    $onLogin = $p['on-login'] ?? '';
    if (!empty($onLogin)) {
        echo "On-Login Script: " . substr($onLogin, 0, 100) . "...\n";
        echo "\n🔍 Parsing script:\n";
        $parsed = parseMikhmonScript($onLogin);
        echo "\n✅ Result:\n";
        echo "   Price: Rp " . number_format($parsed['price'], 0, ',', '.') . "\n";
        echo "   Duration: " . ($parsed['duration'] ?: 'not found') . "\n";
    } else {
        echo "⚠️  No on-login script\n";
    }
    echo "\n";
}

$api->disconnect();
echo "✅ Done!\n";
