<?php
/**
 * ACS-Lite Database Migration Script
 * Ensures database schema is up to date
 */

header('Content-Type: application/json');

function getDB() {
    $envFile = '/opt/acs/.env';
    $config = [
        'host' => '127.0.0.1',
        'port' => '3306',
        'dbname' => 'acs',
        'username' => 'root',
        'password' => 'h6Uems6h4HmW1y7'
    ];
    
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'DB_DSN=') === 0) {
                $dsn = substr($line, 7);
                if (preg_match('/^([^:]+):([^@]*)@tcp\(([^:]+):(\d+)\)\/(.+)/', $dsn, $m)) {
                    $config['username'] = $m[1];
                    $config['password'] = $m[2];
                    $config['host'] = $m[3];
                    $config['port'] = $m[4];
                    $config['dbname'] = preg_replace('/\?.*/', '', $m[5]);
                }
            }
        }
    }
    
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Run migration logic
 * @return array Result of migration
 */
function runMigration() {
    $pdo = getDB();
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database connection failed'];
    }

    $results = [];

    try {
        // 1. Telegram Config Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_token VARCHAR(100) NOT NULL,
            bot_username VARCHAR(50) DEFAULT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $results[] = "Table telegram_config checked";

        // 2. Telegram Admins Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chat_id VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(100) DEFAULT NULL,
            username VARCHAR(50) DEFAULT NULL,
            role ENUM('superadmin', 'admin', 'operator') DEFAULT 'admin',
            is_active BOOLEAN DEFAULT TRUE,
            last_activity TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_chat_id (chat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $results[] = "Table telegram_admins checked";

        // 3. Add mikrotik_profile_isolir to packages
        $stmt = $pdo->query("SHOW COLUMNS FROM packages LIKE 'mikrotik_profile_isolir'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE packages ADD COLUMN mikrotik_profile_isolir VARCHAR(50) DEFAULT 'isolir' AFTER mikrotik_profile");
            $results[] = "Added mikrotik_profile_isolir to packages";
        }

        // 4. Add portal_username/password to customers
        $stmt = $pdo->query("SHOW COLUMNS FROM customers LIKE 'portal_username'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN portal_username VARCHAR(50) DEFAULT NULL AFTER pppoe_password, ADD COLUMN portal_password VARCHAR(255) DEFAULT NULL AFTER portal_username");
            $results[] = "Added portal columns to customers";
        }

        // 5. Hotspot Vouchers Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_vouchers (
            id INT PRIMARY KEY AUTO_INCREMENT,
            batch_id VARCHAR(50) NOT NULL,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(100) NOT NULL,
            profile VARCHAR(50) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('unused', 'sold', 'active', 'expired', 'disabled') DEFAULT 'unused',
            created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $results[] = "Table hotspot_vouchers checked";

        return [
            'success' => true, 
            'message' => 'Database migration completed',
            'details' => $results
        ];

    } catch (Exception $e) {
        return [
            'success' => false, 
            'message' => 'Migration failed: ' . $e->getMessage()
        ];
    }
}

// Only execute and JSON output if called directly (not included)
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json');
    echo json_encode(runMigration());
}
