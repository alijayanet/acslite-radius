<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Database Connection Check</h2>";

try {
    // Connect without database first
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p style='color:green'>✓ MySQL Connected Successfully!</p>";
    
    // Show databases
    $databases = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<h3>Available Databases:</h3><ul>";
    foreach ($databases as $db) {
        echo "<li>" . htmlspecialchars($db) . ($db === 'acs' ? ' <strong>(target)</strong>' : '') . "</li>";
    }
    echo "</ul>";
    
    // Check if 'acs' database exists
    if (!in_array('acs', $databases)) {
        echo "<p style='color:orange'>⚠ Database 'acs' tidak ditemukan. Membuat database...</p>";
        $pdo->exec("CREATE DATABASE acs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "<p style='color:green'>✓ Database 'acs' berhasil dibuat!</p>";
    }
    
    // Connect to acs database
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=acs', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color:green'>✓ Connected to 'acs' database!</p>";
    
    // Check/Create onu_locations table
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<h3>Tables in 'acs' database:</h3>";
    if (empty($tables)) {
        echo "<p>No tables found. Creating onu_locations table...</p>";
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS onu_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            serial_number VARCHAR(50) UNIQUE NOT NULL,
            name VARCHAR(100) DEFAULT NULL,
            username VARCHAR(50) DEFAULT NULL COMMENT 'Customer login username',
            password VARCHAR(255) DEFAULT NULL COMMENT 'Customer login password (hashed)',
            latitude DECIMAL(10, 8) NOT NULL,
            longitude DECIMAL(11, 8) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_serial (serial_number),
            INDEX idx_coords (latitude, longitude),
            UNIQUE INDEX idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        echo "<p style='color:green'>✓ Table 'onu_locations' created successfully!</p>";
    } else {
        echo "<ul>";
        foreach ($tables as $table) {
            $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "<li>{$table} ({$count} rows)</li>";
        }
        echo "</ul>";
    }
    
    // Show table structure if exists
    if (in_array('onu_locations', $tables) || empty($tables)) {
        echo "<h3>Structure of onu_locations:</h3>";
        $columns = $pdo->query("DESCRIBE onu_locations")->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' cellpadding='5'><tr><th>Field</th><th>Type</th><th>Key</th><th>Default</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>{$col['Field']}</td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "<td>{$col['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Show data
        echo "<h3>Data in onu_locations:</h3>";
        $data = $pdo->query("SELECT * FROM onu_locations")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($data)) {
            echo "<p>No data yet.</p>";
        } else {
            echo "<pre>" . print_r($data, true) . "</pre>";
        }
    }
    
} catch (PDOException $e) {
    echo "<p style='color:red'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Pastikan XAMPP MySQL sudah running!</p>";
}

echo "<p><a href='check_database.php'>→ Buka Database Checker (Full UI)</a></p>";
