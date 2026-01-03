<?php
/**
 * Test Script untuk Customer API
 * Akses via browser: /web/api/test_customer_api.php
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Customer API - ACSLite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding: 20px; }
        .card { margin-bottom: 20px; }
        pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 8px; overflow-x: auto; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
    </style>
</head>
<body>
<div class="container">
    <h1 class="mb-4">🧪 Test Customer API - ACSLite</h1>
    
    <?php
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
    ?>

    <!-- Test 1: Database Connection -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">📊 Test 1: Database Connection</h5>
        </div>
        <div class="card-body">
            <?php
            $dbConnected = false;
            try {
                $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
                $pdo = new PDO($dsn, $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
                $dbConnected = true;
                echo '<p class="success"><strong>✅ Database Connected!</strong></p>';
                echo '<pre>';
                echo "Host: {$config['host']}\n";
                echo "Port: {$config['port']}\n";
                echo "Database: {$config['dbname']}\n";
                echo "User: {$config['username']}\n";
                echo '</pre>';
            } catch (PDOException $e) {
                echo '<p class="error"><strong>❌ Database Connection Failed!</strong></p>';
                echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
                echo '<div class="alert alert-info mt-3">';
                echo '<strong>Tips:</strong><br>';
                echo '1. Pastikan MariaDB/MySQL berjalan<br>';
                echo '2. Buat database "acs": <code>CREATE DATABASE acs;</code><br>';
                echo '3. Jalankan <code>./install.sh</code> untuk setup otomatis';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <!-- Test 2: Table Structure -->
    <div class="card">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">📋 Test 2: Table Structure (onu_locations)</h5>
        </div>
        <div class="card-body">
            <?php
            if ($dbConnected) {
                try {
                    $stmt = $pdo->query("DESCRIBE onu_locations");
                    $columns = $stmt->fetchAll();
                    
                    echo '<p class="success"><strong>✅ Table exists!</strong></p>';
                    echo '<table class="table table-striped">';
                    echo '<thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr></thead>';
                    echo '<tbody>';
                    
                    $requiredColumns = ['serial_number', 'username', 'password', 'latitude', 'longitude'];
                    $foundColumns = array_column($columns, 'Field');
                    
                    foreach ($columns as $col) {
                        $highlight = in_array($col['Field'], $requiredColumns) ? 'style="background: #d4edda;"' : '';
                        echo "<tr $highlight>";
                        echo '<td>' . htmlspecialchars($col['Field']) . '</td>';
                        echo '<td>' . htmlspecialchars($col['Type']) . '</td>';
                        echo '<td>' . htmlspecialchars($col['Null']) . '</td>';
                        echo '<td>' . htmlspecialchars($col['Key']) . '</td>';
                        echo '<td>' . htmlspecialchars($col['Default'] ?? 'NULL') . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                    
                    // Check for required columns
                    $missing = array_diff($requiredColumns, $foundColumns);
                    if (!empty($missing)) {
                        echo '<div class="alert alert-warning">';
                        echo '<strong>⚠️ Missing columns:</strong> ' . implode(', ', $missing) . '<br>';
                        echo 'Run: <code>mysql -u root -p acs < migrations/002_add_customer_login.sql</code>';
                        echo '</div>';
                    }
                    
                } catch (PDOException $e) {
                    echo '<p class="error"><strong>❌ Table not found!</strong></p>';
                    echo '<div class="alert alert-info">';
                    echo '<strong>Cara membuat tabel:</strong><br>';
                    echo '1. Jalankan <code>./install.sh</code><br>';
                    echo '2. Atau: <code>mysql -u root -p acs < migrations/002_add_customer_login.sql</code>';
                    echo '</div>';
                }
            } else {
                echo '<p class="warning">⚠️ Skipped (database not connected)</p>';
            }
            ?>
        </div>
    </div>

    <!-- Test 3: Existing Data -->
    <div class="card">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">👥 Test 3: Existing Customer Data</h5>
        </div>
        <div class="card-body">
            <?php
            if ($dbConnected) {
                try {
                    $stmt = $pdo->query("SELECT serial_number, name, username, latitude, longitude, created_at FROM onu_locations ORDER BY created_at DESC LIMIT 10");
                    $customers = $stmt->fetchAll();
                    
                    if (empty($customers)) {
                        echo '<p class="warning"><strong>⚠️ No customer data yet</strong></p>';
                        echo '<p>Tambahkan pelanggan melalui Admin Panel → Set Location</p>';
                    } else {
                        echo '<p class="success"><strong>✅ Found ' . count($customers) . ' customer(s)</strong></p>';
                        echo '<table class="table table-striped">';
                        echo '<thead><tr><th>Serial Number</th><th>Name</th><th>Username</th><th>Location</th><th>Created</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($customers as $c) {
                            echo '<tr>';
                            echo '<td><code>' . htmlspecialchars($c['serial_number']) . '</code></td>';
                            echo '<td>' . htmlspecialchars($c['name'] ?? '-') . '</td>';
                            echo '<td><strong>' . htmlspecialchars($c['username'] ?? '-') . '</strong></td>';
                            echo '<td>' . $c['latitude'] . ', ' . $c['longitude'] . '</td>';
                            echo '<td>' . htmlspecialchars($c['created_at']) . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    }
                } catch (PDOException $e) {
                    echo '<p class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
            } else {
                echo '<p class="warning">⚠️ Skipped (database not connected)</p>';
            }
            ?>
        </div>
    </div>

    <!-- Test 4: JSON Fallback -->
    <div class="card">
        <div class="card-header bg-warning">
            <h5 class="mb-0">📁 Test 4: JSON Fallback Storage</h5>
        </div>
        <div class="card-body">
            <?php
            $jsonFile = __DIR__ . '/../data/customers.json';
            
            if (file_exists($jsonFile)) {
                echo '<p class="success"><strong>✅ JSON file exists</strong></p>';
                echo '<p>Path: <code>' . realpath($jsonFile) . '</code></p>';
                
                $content = file_get_contents($jsonFile);
                $data = json_decode($content, true);
                
                if ($data && isset($data['customers'])) {
                    $count = count($data['customers']);
                    echo "<p>Customers in JSON: <strong>$count</strong></p>";
                    
                    if ($count > 0) {
                        echo '<pre>' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
                    }
                }
            } else {
                echo '<p class="warning">⚠️ JSON file not found (will be created automatically)</p>';
            }
            ?>
        </div>
    </div>

    <!-- Test 5: API Test Form -->
    <div class="card">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">🔧 Test 5: Manual API Test</h5>
        </div>
        <div class="card-body">
            <h6>Test Login:</h6>
            <form id="loginForm" class="mb-4">
                <div class="row">
                    <div class="col-md-4">
                        <input type="text" class="form-control" id="testUsername" placeholder="Username" required>
                    </div>
                    <div class="col-md-4">
                        <input type="password" class="form-control" id="testPassword" placeholder="Password" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">Test Login</button>
                    </div>
                </div>
            </form>
            
            <h6>Test Save Location:</h6>
            <form id="saveForm">
                <div class="row">
                    <div class="col-md-3">
                        <input type="text" class="form-control" id="testSN" placeholder="Serial Number" required>
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control" id="testName" placeholder="Username" required>
                    </div>
                    <div class="col-md-2">
                        <input type="password" class="form-control" id="testPass" placeholder="Password">
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control" id="testLat" placeholder="Latitude" value="-6.208812">
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control" id="testLng" placeholder="Longitude" value="106.845599">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-success w-100">Save</button>
                    </div>
                </div>
            </form>
            
            <div id="apiResult" class="mt-3"></div>
        </div>
    </div>

    <!-- Links -->
    <div class="card">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">🔗 Quick Links</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <a href="customer_api.php" target="_blank" class="btn btn-outline-primary w-100 mb-2">
                        API Endpoint
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="../templates/customer_login.html" target="_blank" class="btn btn-outline-success w-100 mb-2">
                        Customer Login
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="../templates/index.html" target="_blank" class="btn btn-outline-info w-100 mb-2">
                        Admin Panel
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="../templates/map.html" target="_blank" class="btn btn-outline-warning w-100 mb-2">
                        Map View
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const API_URL = 'customer_api.php';
    
    document.getElementById('loginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('testUsername').value;
        const password = document.getElementById('testPassword').value;
        
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });
            
            const data = await response.json();
            showResult(response.status, data);
        } catch (error) {
            showResult(500, { error: error.message });
        }
    });
    
    document.getElementById('saveForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const sn = document.getElementById('testSN').value;
        const username = document.getElementById('testName').value;
        const password = document.getElementById('testPass').value;
        const lat = parseFloat(document.getElementById('testLat').value);
        const lng = parseFloat(document.getElementById('testLng').value);
        
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    serial_number: sn,
                    username: username,
                    password: password || undefined,
                    latitude: lat,
                    longitude: lng
                })
            });
            
            const data = await response.json();
            showResult(response.status, data);
        } catch (error) {
            showResult(500, { error: error.message });
        }
    });
    
    function showResult(status, data) {
        const resultDiv = document.getElementById('apiResult');
        const isSuccess = status >= 200 && status < 300;
        resultDiv.innerHTML = `
            <div class="alert alert-${isSuccess ? 'success' : 'danger'}">
                <strong>HTTP ${status}</strong>
                <pre>${JSON.stringify(data, null, 2)}</pre>
            </div>
        `;
    }
</script>
</body>
</html>
