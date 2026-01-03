<?php
// Test MikroTik API Connection
$host = '192.168.8.1';
$port = 8728;
$timeout = 5;

echo "Testing connection to MikroTik API...\n";
echo "Host: $host\n";
echo "Port: $port\n\n";

// Test 1: Ping (via exec)
echo "=== Test 1: Ping ===\n";
exec("ping -c 3 $host 2>&1", $output, $return);
echo implode("\n", $output) . "\n\n";

// Test 2: Socket connection
echo "=== Test 2: Socket Connection ===\n";
$socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

if ($socket) {
    echo "✅ SUCCESS! Connected to $host:$port\n";
    fclose($socket);
} else {
    echo "❌ FAILED! Cannot connect to $host:$port\n";
    echo "Error ($errno): $errstr\n";
}

echo "\n=== Test 3: MikroTik API Class ===\n";
require_once __DIR__ . '/web/api/MikroTikAPI.php';

$api = new MikroTikAPI();
$connected = $api->connect($host, 'admin', '1234', $port);

if ($connected) {
    echo "✅ MikroTik API connected successfully!\n";
    $identity = $api->getIdentity();
    echo "Router Identity: $identity\n";
    $api->disconnect();
} else {
    echo "❌ MikroTik API connection failed!\n";
    echo "Error: " . $api->getError() . "\n";
}
