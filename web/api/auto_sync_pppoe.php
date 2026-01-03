<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "===========================================\n";
echo " Auto-Sync serial ONU berdasarkan PPPoE\n";
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
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4", $config['username'], '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "✓ Database connected\n\n";
}

// 1. List PPPoE yang tersedia
echo "PPPoE Username yang tersedia di sistem:\n";
echo str_repeat("-", 70) . "\n";
$stmt = $pdo->query("SELECT username, serial_number, name FROM onu_locations ORDER BY username");
$available = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($available) > 0) {
    foreach ($available as $onu) {
        printf("  PPPoE: %-20s | Serial: %-20s | Name: %s\n", $onu['username'], $onu['serial_number'], $onu['name']);
    }
    echo "\n";
} else {
    echo "  (Tidak ada ONU terdaftar)\n\n";
}

// 2. Customers yang belum punya serial
echo "Customers yang belum punya serial ONU:\n";
echo str_repeat("-", 70) . "\n";
$stmt = $pdo->query("SELECT customer_id, name, pppoe_username FROM customers WHERE onu_serial IS NULL OR onu_serial = ''");
$needSync = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($needSync) > 0) {
    foreach ($needSync as $cust) {
        printf("  %-10s | %-25s | PPPoE: %s\n", $cust['customer_id'], $cust['name'], $cust['pppoe_username'] ?: '(kosong)');
    }
    echo "\n";
} else {
    echo "  (Semua customer sudah punya serial ONU)\n\n";
}

// 3. Auto-Sync
echo "Auto-Sync berdasarkan PPPoE username...\n";
echo str_repeat("-", 70) . "\n";

$updated = 0;
$skipped = 0;

foreach ($needSync as $cust) {
    if (empty($cust['pppoe_username'])) {
        echo "⊘ {$cust['customer_id']} | {$cust['name']} - PPPoE username kosong, skip\n";
        $skipped++;
        continue;
    }
    
    // Cari ONU dengan PPPoE username yang sama
    $stmt = $pdo->prepare("SELECT serial_number FROM onu_locations WHERE username = ?");
    $stmt->execute([$cust['pppoe_username']]);
    $onu = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($onu) {
        $updateStmt = $pdo->prepare("UPDATE customers SET onu_serial = ? WHERE customer_id = ?");
        $updateStmt->execute([$onu['serial_number'], $cust['customer_id']]);
        echo "✓ {$cust['customer_id']} | PPPoE: {$cust['pppoe_username']} -> Serial: {$onu['serial_number']}\n";
        $updated++;
    } else {
        echo "⊘ {$cust['customer_id']} | PPPoE: {$cust['pppoe_username']} - Tidak ditemukan di ONU\n";
        $skipped++;
    }
}

echo str_repeat("-", 70) . "\n";
echo "\n✓ Sync Selesai!\n";
echo "  Updated: $updated | Skipped: $skipped\n";
echo "===========================================\n\n";

// 4. Hasil akhir
echo "Data customers setelah sync:\n";
echo str_repeat("-", 70) . "\n";
$stmt = $pdo->query("SELECT customer_id, name, pppoe_username, onu_serial FROM customers");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $serial = $row['onu_serial'] ?: '(belum di-set)';
    printf("%-10s | %-20s | PPPoE: %-15s | Serial: %s\n", 
        $row['customer_id'], $row['name'], $row['pppoe_username'], $serial);
}
echo str_repeat("-", 70) . "\n";
