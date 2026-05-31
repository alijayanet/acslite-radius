<?php
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
} catch (PDOException $e) {
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4", $config['username'], '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

echo "Updating customer CST001...\n";
$pdo->exec("UPDATE customers SET onu_serial = 'CIOT12C64178' WHERE customer_id = 'CST001'");
echo "✓ Updated!\n\n";

echo "Current customers data:\n";
echo str_repeat("-", 70) . "\n";
$stmt = $pdo->query("SELECT customer_id, name, pppoe_username, onu_serial FROM customers");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    printf("%-10s | %-20s | PPPoE: %-15s | Serial: %s\n", 
        $row['customer_id'], $row['name'], $row['pppoe_username'], $row['onu_serial'] ?: '(empty)');
}
echo str_repeat("-", 70) . "\n";
