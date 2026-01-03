<?php
/**
 * ACS-Lite Database Checker
 * 
 * File ini digunakan untuk mengecek dan menampilkan data yang ada di database
 * Akses: http://localhost/acs-lite/web/check_database.php
 */

// Error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$config = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'acs',
    'username' => 'root',
    'password' => ''  // Default XAMPP kosong
];

// Try to load from .env file (jika ada di /opt/acs/.env untuk Linux server)
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

// Jika ada file config lokal, gunakan itu
$localEnvFile = __DIR__ . '/../.env';
if (file_exists($localEnvFile)) {
    $envContent = file_get_contents($localEnvFile);
    if (preg_match('/DB_HOST=(.+)/', $envContent, $m)) $config['host'] = trim($m[1]);
    if (preg_match('/DB_PORT=(.+)/', $envContent, $m)) $config['port'] = (int)trim($m[1]);
    if (preg_match('/DB_NAME=(.+)/', $envContent, $m)) $config['dbname'] = trim($m[1]);
    if (preg_match('/DB_USER=(.+)/', $envContent, $m)) $config['username'] = trim($m[1]);
    if (preg_match('/DB_PASS=(.+)/', $envContent, $m)) $config['password'] = trim($m[1]);
}

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
        return ['error' => $e->getMessage()];
    }
}

// Handle AJAX request untuk JSON data
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    $db = getDB($config);
    
    if (is_array($db) && isset($db['error'])) {
        echo json_encode(['success' => false, 'error' => $db['error']]);
        exit;
    }
    
    $result = ['success' => true, 'tables' => []];
    
    // Get all tables
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        $columns = $db->query("DESCRIBE `$table`")->fetchAll();
        $data = $db->query("SELECT * FROM `$table` LIMIT 50")->fetchAll();
        
        $result['tables'][$table] = [
            'count' => $count,
            'columns' => $columns,
            'data' => $data
        ];
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

$db = getDB($config);
$connectionStatus = 'error';
$tables = [];
$dbVersion = '';
$errorMessage = '';

if (is_array($db) && isset($db['error'])) {
    $errorMessage = $db['error'];
} else {
    $connectionStatus = 'success';
    $dbVersion = $db->query("SELECT VERSION()")->fetchColumn();
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACS-Lite Database Checker</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e1b4b;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-600: #4b5563;
            --gray-800: #1f2937;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #1e1b4b 100%);
            min-height: 100vh;
            color: #fff;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 30px;
            background: rgba(255,255,255,0.1);
            border-radius: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(90deg, #818cf8, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
        }
        
        .header p {
            color: rgba(255,255,255,0.7);
            font-size: 1.1rem;
        }
        
        .status-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
        }
        
        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .card-icon.success { background: rgba(16, 185, 129, 0.2); }
        .card-icon.error { background: rgba(239, 68, 68, 0.2); }
        .card-icon.info { background: rgba(99, 102, 241, 0.2); }
        
        .card h3 {
            font-size: 0.9rem;
            text-transform: uppercase;
            color: rgba(255,255,255,0.6);
            margin-bottom: 8px;
            letter-spacing: 1px;
        }
        
        .card .value {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .card .value.success { color: var(--success); }
        .card .value.error { color: var(--danger); }
        
        .table-section {
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .table-header h2 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .badge {
            background: var(--primary);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .table-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .tab-btn {
            padding: 10px 20px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 10px;
            color: #fff;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .tab-btn:hover,
        .tab-btn.active {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            overflow-x: auto;
            display: block;
        }
        
        .data-table thead {
            background: rgba(99, 102, 241, 0.3);
        }
        
        .data-table th,
        .data-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            white-space: nowrap;
        }
        
        .data-table th {
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255,255,255,0.9);
        }
        
        .data-table tr:hover {
            background: rgba(255,255,255,0.05);
        }
        
        .data-table td {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.8);
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .data-table td.password-cell {
            font-family: monospace;
            font-size: 0.75rem;
            color: var(--warning);
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: rgba(255,255,255,0.5);
        }
        
        .empty-state span {
            font-size: 48px;
            display: block;
            margin-bottom: 15px;
        }
        
        .column-info {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: rgba(0,0,0,0.2);
            border-radius: 10px;
        }
        
        .column-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
        }
        
        .column-item .type {
            background: rgba(99, 102, 241, 0.3);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-family: monospace;
        }
        
        .column-item .key {
            color: var(--warning);
            font-size: 0.7rem;
        }
        
        .btn-refresh {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            border-radius: 10px;
            color: #fff;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: transform 0.2s;
        }
        
        .btn-refresh:hover {
            transform: scale(1.05);
        }
        
        .error-box {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid var(--danger);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .error-box h3 {
            color: var(--danger);
            margin-bottom: 10px;
        }
        
        .config-display {
            background: rgba(0,0,0,0.3);
            border-radius: 10px;
            padding: 15px;
            font-family: monospace;
            font-size: 0.85rem;
            margin-top: 15px;
        }
        
        .config-display .line {
            margin: 5px 0;
        }
        
        .config-display .key {
            color: #818cf8;
        }
        
        .config-display .val {
            color: #34d399;
        }
        
        @media (max-width: 768px) {
            .header h1 { font-size: 1.8rem; }
            .card { padding: 16px; }
            body { padding: 10px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗄️ ACS-Lite Database Checker</h1>
            <p>Cek dan monitor data di database ACS-Lite</p>
        </div>
        
        <div class="status-card">
            <div class="card">
                <div class="card-icon <?php echo $connectionStatus; ?>">
                    <?php echo $connectionStatus === 'success' ? '✓' : '✗'; ?>
                </div>
                <h3>Status Koneksi</h3>
                <div class="value <?php echo $connectionStatus; ?>">
                    <?php echo $connectionStatus === 'success' ? 'Terhubung' : 'Gagal'; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-icon info">🗃️</div>
                <h3>Database</h3>
                <div class="value"><?php echo htmlspecialchars($config['dbname']); ?></div>
            </div>
            
            <div class="card">
                <div class="card-icon info">📊</div>
                <h3>Jumlah Tabel</h3>
                <div class="value"><?php echo count($tables); ?></div>
            </div>
            
            <div class="card">
                <div class="card-icon info">🔧</div>
                <h3>MySQL Version</h3>
                <div class="value"><?php echo $dbVersion ?: 'N/A'; ?></div>
            </div>
        </div>
        
        <?php if ($connectionStatus === 'error'): ?>
        <div class="error-box">
            <h3>❌ Database Connection Error</h3>
            <p><?php echo htmlspecialchars($errorMessage); ?></p>
            
            <div class="config-display">
                <div class="line">Konfigurasi yang digunakan:</div>
                <div class="line"><span class="key">Host:</span> <span class="val"><?php echo $config['host']; ?></span></div>
                <div class="line"><span class="key">Port:</span> <span class="val"><?php echo $config['port']; ?></span></div>
                <div class="line"><span class="key">Database:</span> <span class="val"><?php echo $config['dbname']; ?></span></div>
                <div class="line"><span class="key">Username:</span> <span class="val"><?php echo $config['username']; ?></span></div>
                <div class="line"><span class="key">Password:</span> <span class="val"><?php echo $config['password'] ? '********' : '(kosong)'; ?></span></div>
            </div>
            
            <p style="margin-top: 15px; color: rgba(255,255,255,0.7);">
                <strong>Solusi:</strong> Pastikan database <code><?php echo $config['dbname']; ?></code> sudah dibuat dan XAMPP MySQL sudah running.
            </p>
        </div>
        <?php else: ?>
        
        <div class="table-section">
            <div class="table-header">
                <h2>📋 Data Tables</h2>
                <button class="btn-refresh" onclick="location.reload()">
                    🔄 Refresh
                </button>
            </div>
            
            <?php if (empty($tables)): ?>
            <div class="empty-state">
                <span>📭</span>
                <p>Tidak ada tabel ditemukan di database.</p>
                <p style="margin-top: 10px; font-size: 0.9rem;">Jalankan migrasi untuk membuat tabel.</p>
            </div>
            <?php else: ?>
            
            <div class="table-tabs">
                <?php foreach ($tables as $index => $table): 
                    $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                ?>
                <button class="tab-btn <?php echo $index === 0 ? 'active' : ''; ?>" 
                        onclick="showTable('<?php echo $table; ?>', this)">
                    <?php echo htmlspecialchars($table); ?> 
                    <span class="badge"><?php echo $count; ?></span>
                </button>
                <?php endforeach; ?>
            </div>
            
            <?php foreach ($tables as $index => $table): 
                $columns = $db->query("DESCRIBE `$table`")->fetchAll();
                $data = $db->query("SELECT * FROM `$table` ORDER BY 1 DESC LIMIT 50")->fetchAll();
            ?>
            <div class="table-content" id="table-<?php echo $table; ?>" 
                 style="display: <?php echo $index === 0 ? 'block' : 'none'; ?>">
                
                <h3 style="margin-bottom: 15px;">📌 Struktur Tabel: <?php echo htmlspecialchars($table); ?></h3>
                <div class="column-info">
                    <?php foreach ($columns as $col): ?>
                    <div class="column-item">
                        <span><?php echo htmlspecialchars($col['Field']); ?></span>
                        <span class="type"><?php echo $col['Type']; ?></span>
                        <?php if ($col['Key'] === 'PRI'): ?>
                        <span class="key">🔑 PK</span>
                        <?php elseif ($col['Key'] === 'UNI'): ?>
                        <span class="key">🔐 UNI</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <h3 style="margin: 20px 0 15px;">📄 Data (Max 50 baris)</h3>
                <?php if (empty($data)): ?>
                <div class="empty-state">
                    <span>📭</span>
                    <p>Tabel kosong, belum ada data.</p>
                </div>
                <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $col): ?>
                                <th><?php echo htmlspecialchars($col['Field']); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $row): ?>
                            <tr>
                                <?php foreach ($columns as $col): 
                                    $field = $col['Field'];
                                    $value = $row[$field] ?? '';
                                    $isPassword = stripos($field, 'password') !== false;
                                ?>
                                <td class="<?php echo $isPassword ? 'password-cell' : ''; ?>">
                                    <?php 
                                    if ($isPassword && $value) {
                                        echo substr($value, 0, 20) . '...';
                                    } else {
                                        echo htmlspecialchars(strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value);
                                    }
                                    ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
        
        <div class="card" style="margin-top: 20px;">
            <h3 style="margin-bottom: 15px; font-size: 1rem; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.6);">⚙️ Konfigurasi Database</h3>
            <div class="config-display">
                <div class="line"><span class="key">Host:</span> <span class="val"><?php echo $config['host']; ?>:<?php echo $config['port']; ?></span></div>
                <div class="line"><span class="key">Database:</span> <span class="val"><?php echo $config['dbname']; ?></span></div>
                <div class="line"><span class="key">Username:</span> <span class="val"><?php echo $config['username']; ?></span></div>
                <div class="line"><span class="key">Config Source:</span> <span class="val"><?php 
                    if (file_exists($localEnvFile)) echo 'Local .env';
                    elseif (file_exists($envFile)) echo '/opt/acs/.env';
                    else echo 'Default (XAMPP)';
                ?></span></div>
            </div>
        </div>
        
        <p style="text-align: center; margin-top: 30px; color: rgba(255,255,255,0.4); font-size: 0.85rem;">
            ACS-Lite Database Checker | <?php echo date('Y-m-d H:i:s'); ?>
        </p>
    </div>
    
    <script>
        function showTable(tableName, btn) {
            // Hide all tables
            document.querySelectorAll('.table-content').forEach(el => {
                el.style.display = 'none';
            });
            
            // Remove active from all tabs
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('active');
            });
            
            // Show selected table
            document.getElementById('table-' + tableName).style.display = 'block';
            btn.classList.add('active');
        }
    </script>
</body>
</html>
