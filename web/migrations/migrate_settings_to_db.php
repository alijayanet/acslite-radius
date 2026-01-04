#!/usr/bin/env php
<?php
/**
 * Migrate settings.json to MySQL database
 * This script reads settings.json and migrates data to settings table
 * 
 * Location: web/migrations/migrate_settings_to_db.php
 * Run: php web/migrations/migrate_settings_to_db.php
 */

// Configuration
$SETTINGS_FILE = __DIR__ . '/../data/settings.json';
$ENV_FILE = '/opt/acs/.env';

echo "========================================\n";
echo "Settings Migration: JSON → MySQL\n";
echo "========================================\n\n";

// ========================================
// STEP 1: Get database credentials
// ========================================
echo "[1/4] Reading database credentials...\n";

$dbConfig = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'dbname' => 'acs',
    'username' => 'root',
    'password' => 'secret123'
];

if (file_exists($ENV_FILE)) {
    $lines = file($ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, 'DB_DSN=') === 0) {
            $dsn = substr($line, 7);
            if (preg_match('/^([^:]+):([^@]*)@tcp\(([^:]+):(\d+)\)\/(.+)/', $dsn, $m)) {
                $dbConfig['username'] = $m[1];
                $dbConfig['password'] = $m[2];
                $dbConfig['host'] = $m[3];
                $dbConfig['port'] = $m[4];
                $dbConfig['dbname'] = preg_replace('/\?.*/', '', $m[5]);
            }
        }
    }
}

echo "  ✓ Database: {$dbConfig['dbname']} @ {$dbConfig['host']}:{$dbConfig['port']}\n";
echo "  ✓ User: {$dbConfig['username']}\n\n";

// ========================================
// STEP 2: Connect to database
// ========================================
echo "[2/4] Connecting to MySQL...\n";

$connected = false;
$pdo = null;

// Try 1: Standard password auth
try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    echo "  ✓ Connected successfully (password auth)\n\n";
    $connected = true;
} catch (PDOException $e) {
    // Ubuntu 20.04+ uses unix_socket for root, try without password via socket
    if (strpos($e->getMessage(), 'Access denied') !== false && $dbConfig['username'] === 'root') {
        echo "  ⊙ Password auth failed, trying socket auth...\n";
        
        try {
            // Try unix socket connection (localhost without password)
            $pdo = new PDO(
                "mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname={$dbConfig['dbname']};charset=utf8mb4",
                $dbConfig['username'],
                '', // No password for socket auth
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            echo "  ✓ Connected successfully (unix socket auth)\n\n";
            $connected = true;
        } catch (PDOException $e2) {
            echo "  ✗ Socket auth also failed: " . $e2->getMessage() . "\n";
        }
    }
    
    if (!$connected) {
        echo "  ✗ ERROR: " . $e->getMessage() . "\n";
        echo "  → Migration skipped, using database defaults instead.\n";
        echo "  → This is OK for fresh installation.\n\n";
        exit(0); // Exit gracefully, not critical
    }
}

// ========================================
// STEP 3: Read settings.json
// ========================================
echo "[3/4] Reading settings.json...\n";

if (!file_exists($SETTINGS_FILE)) {
    echo "  ⚠ WARNING: settings.json not found at: $SETTINGS_FILE\n";
    echo "  → Using default settings only\n\n";
    $settings = [];
} else {
    $settingsContent = file_get_contents($SETTINGS_FILE);
    $settings = json_decode($settingsContent, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "  ✗ ERROR: Invalid JSON in settings.json\n";
        echo "  → " . json_last_error_msg() . "\n";
        exit(1);
    }
    
    echo "  ✓ Found " . count($settings) . " categories\n";
    foreach (array_keys($settings) as $category) {
        echo "    - $category\n";
    }
    echo "\n";
}

// ========================================
// STEP 4: Migrate to database
// ========================================
echo "[4/4] Migrating to database...\n";

$migrated = 0;
$errors = 0;

// Default categories if settings.json is empty
$defaultCategories = ['general', 'acs', 'telegram', 'billing', 'whatsapp', 'hotspot'];

// Merge with existing categories
$allCategories = array_unique(array_merge($defaultCategories, array_keys($settings)));

foreach ($allCategories as $category) {
    try {
        if (isset($settings[$category])) {
            // Use data from settings.json
            $json = json_encode($settings[$category], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $source = 'settings.json';
        } else {
            // Skip if not in settings.json (will use DB defaults)
            echo "  ⊙ Skipping $category (not in settings.json, using DB default)\n";
            continue;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO settings (category, settings_json, updated_by) 
            VALUES (:category, :json, :updated_by)
            ON DUPLICATE KEY UPDATE 
                settings_json = :json,
                updated_at = NOW(),
                updated_by = :updated_by
        ");
        
        $stmt->execute([
            'category' => $category,
            'json' => $json,
            'updated_by' => 'migration_script'
        ]);
        
        echo "  ✓ Migrated: $category (from $source)\n";
        $migrated++;
        
    } catch (PDOException $e) {
        echo "  ✗ ERROR migrating $category: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n========================================\n";
echo "Migration Complete!\n";
echo "========================================\n";
echo "Migrated: $migrated categories\n";
echo "Errors: $errors\n";

if ($errors === 0) {
    echo "\n✓ SUCCESS: All settings migrated successfully!\n";
    
    // Backup settings.json
    if (file_exists($SETTINGS_FILE)) {
        $backupFile = $SETTINGS_FILE . '.backup.' . date('Y-m-d_His');
        if (copy($SETTINGS_FILE, $backupFile)) {
            echo "✓ Backup created: $backupFile\n";
        }
    }
    
    echo "\nNext steps:\n";
    echo "1. Verify data: SELECT * FROM settings;\n";
    echo "2. Test API: curl http://localhost:8888/api/settings_api.php?action=get\n";
    echo "3. Keep settings.json as fallback for 1-2 weeks\n";
    echo "4. After stable, you can remove settings.json\n";
    
    exit(0);
} else {
    echo "\n⚠ WARNING: Migration completed with errors\n";
    echo "Please check the errors above and fix manually\n";
    exit(1);
}
