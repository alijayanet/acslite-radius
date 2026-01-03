#!/bin/bash
# Script untuk create dan run sync_onu_serial.php

echo "Creating sync_onu_serial.php..."

cat > /opt/acs/web/api/sync_onu_serial.php << 'ENDOFPHPSCRIPT'
<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "===========================================\n";
echo " Auto-Sync ONU Serial ke Tabel Customers\n";
echo "===========================================\n\n";

$config = ['host' => '127.0.0.1', 'port' => 3306, 'dbname' => 'acs', 'username' => 'root', 'password' => ''];

$envFile = '/opt/acs/.env';
if (file_exists($envFile)) {
    echo "Loading config from .env...\n";
    $envContent = file_get_contents($envFile);
    if (preg_match('/DB_DSN=([^:]+):([^@]+)@tcp\(([^:]+):(\d+)\)\/([^?]+)/', $envContent, $matches)) {
        $config = ['username' => $matches[1], 'password' => $matches[2], 'host' => $matches[3], 'port' => (int)$matches[4], 'dbname' => $matches[5]];
    }
}

try {
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Database connected\n";
} catch (PDOException $e) {
    try {
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4", $config['username'], '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "Database connected (empty password)\n";
    } catch (PDOException $e2) {
        die("Database connection failed: " . $e2->getMessage() . "\n");
    }
}

$genieAcsUrl = 'http://127.0.0.1:7547/devices';
$projection = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username';

echo "Fetching devices from GenieACS...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $genieAcsUrl . '?projection=_id,' . $projection);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    die("Failed to fetch devices from GenieACS (HTTP $httpCode)\n");
}

$devices = json_decode($response, true);
if (!is_array($devices) || count($devices) === 0) {
    die("No devices found in GenieACS\n");
}

echo "Found " . count($devices) . " devices\n\n";
echo "Processing...\n";
echo str_repeat("-", 70) . "\n";

$updated = 0;
$skipped = 0;

foreach ($devices as $device) {
    $serial = $device['_id'] ?? null;
    $pppoeUser = $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['1']['WANPPPConnection']['1']['Username']['_value'] ?? null;
    
    if (!$serial || !$pppoeUser) {
        echo ($serial ?: 'Unknown') . " - No PPPoE username\n";
        $skipped++;
        continue;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE customers SET onu_serial = ? WHERE pppoe_username = ? AND (onu_serial IS NULL OR onu_serial = '')");
        $stmt->execute([$serial, $pppoeUser]);
        
        if ($stmt->rowCount() > 0) {
            echo "$serial - Updated for PPPoE: $pppoeUser\n";
            $updated++;
        } else {
            $checkStmt = $pdo->prepare("SELECT customer_id, onu_serial FROM customers WHERE pppoe_username = ?");
            $checkStmt->execute([$pppoeUser]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                echo "$serial - Customer {$existing['customer_id']} already has serial: {$existing['onu_serial']}\n";
            } else {
                echo "$serial - No customer found with PPPoE: $pppoeUser\n";
            }
            $skipped++;
        }
    } catch (PDOException $e) {
        echo "$serial - Error: " . $e->getMessage() . "\n";
        $skipped++;
    }
}

echo str_repeat("-", 70) . "\n";
echo "\nSync Completed!\n";
echo "Updated: $updated | Skipped: $skipped\n";
echo "===========================================\n";
ENDOFPHPSCRIPT

echo "File created successfully!"
echo ""
echo "Running sync..."
php /opt/acs/web/api/sync_onu_serial.php

echo ""
echo "Checking database (first 5 customers)..."
mysql -u root acs -e "SELECT customer_id, name, pppoe_username, onu_serial FROM customers LIMIT 5;" 2>/dev/null || echo "Note: MySQL password might be required"
