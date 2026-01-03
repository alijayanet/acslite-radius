<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "===========================================\n";
echo " Cek Data ONU & Sync ke Customers\n";
echo "===========================================\n\n";

$config = ['host' => '127.0.0.1', 'port' => 3306, 'dbname' => 'acs', 'username' => 'root', 'password' => ''];

$envFile = '/opt/acs/.env';
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    if (preg_match('/DB_DSN=([^:]+):([^@]+)@tcp\(([^:]+):(\d+)\)\/([^?]+)/', $envContent, $m)) {
        $config = ['username' => $m[1], 'password' => $m[2], 'host' => $m[3], 'port' => (int)$m[4], 'dbname' => $m[5]];
    }
}

try {
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "✓ Database connected\n\n";
} catch (PDOException $e) {
    try {
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4", $config['username'], '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "✓ Database connected (no password)\n\n";
    } catch (PDOException $e2) {
        die("DB Error: " . $e2->getMessage() . "\n");
    }
}

// 1. Cek table onu_locations
echo "Checking onu_locations table...\n";
echo str_repeat("-", 70) . "\n";
try {
    $stmt = $pdo->query("SELECT serial_number, name, username FROM onu_locations LIMIT 10");
    $onuLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($onuLocations) > 0) {
        echo "Found " . count($onuLocations) . " ONU in onu_locations:\n";
        foreach ($onuLocations as $onu) {
            echo "  - {$onu['serial_number']} | {$onu['username']} | {$onu['name']}\n";
        }
        echo "\n";
    } else {
        echo "No ONU found in onu_locations\n\n";
    }
} catch (PDOException $e) {
    echo "Table onu_locations not found or error: " . $e->getMessage() . "\n\n";
    $onuLocations = [];
}

// 2. Cek customers table
echo "Checking customers table...\n";
echo str_repeat("-", 70) . "\n";
$stmt = $pdo->query("SELECT customer_id, name, pppoe_username, onu_serial FROM customers LIMIT 10");
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($customers) . " customers:\n";
foreach ($customers as $cust) {
    $serial = $cust['onu_serial'] ?: '(empty)';
    echo "  - {$cust['customer_id']} | {$cust['name']} | PPPoE: {$cust['pppoe_username']} | Serial: $serial\n";
}
echo "\n";

// 3. Sync dari onu_locations ke customers (jika ada)
if (count($onuLocations) > 0) {
    echo "Syncing from onu_locations to customers...\n";
    echo str_repeat("-", 70) . "\n";
    
    $updated = 0;
    foreach ($onuLocations as $onu) {
        try {
            $stmt = $pdo->prepare("UPDATE customers SET onu_serial = ? WHERE pppoe_username = ? AND (onu_serial IS NULL OR onu_serial = '')");
            $stmt->execute([$onu['serial_number'], $onu['username']]);
            
            if ($stmt->rowCount() > 0) {
                echo "✓ {$onu['serial_number']} -> {$onu['username']}\n";
                $updated++;
            }
        } catch (PDOException $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\nSync completed! Updated: $updated\n";
}

echo "===========================================\n";
