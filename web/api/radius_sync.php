<?php

ini_set('display_errors', 0);

function loadSettings() {
    $settingsFile = __DIR__ . '/../data/settings.json';

    $defaults = [
        'hotspot' => [
            'backend' => 'mikrotik',
            'radius' => [
                'enabled' => false,
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_name' => 'radius',
                'db_user' => 'radius',
                'db_pass' => ''
            ]
        ]
    ];

    if (file_exists($settingsFile)) {
        $loaded = json_decode(file_get_contents($settingsFile), true) ?: [];
        return array_replace_recursive($defaults, $loaded);
    }

    return $defaults;
}

function loadAcsDbConfig() {
    $config = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'acs',
        'username' => 'root',
        'password' => ''
    ];

    $envPaths = [
        __DIR__ . '/../.env',
        __DIR__ . '/../../.env',
        '/opt/acs/.env'
    ];

    foreach ($envPaths as $envFile) {
        if (!file_exists($envFile)) {
            continue;
        }

        $envContent = file_get_contents($envFile);

        // Handle DB_DSN format (preferred)
        if (preg_match('/DB_DSN=([^:]+):([^@]*)@tcp\(([^:]+):(\d+)\)\/([^?\n\r]+)/', $envContent, $m)) {
            $config['username'] = $m[1];
            $config['password'] = $m[2];
            $config['host'] = $m[3];
            $config['port'] = (int)$m[4];
            $config['dbname'] = $m[5];
            return $config;
        }

        // Fallback keys (if present)
        if (preg_match('/DB_HOST=(.+)/', $envContent, $m)) $config['host'] = trim($m[1]);
        if (preg_match('/DB_PORT=(.+)/', $envContent, $m)) $config['port'] = (int)trim($m[1]);
        if (preg_match('/DB_NAME=(.+)/', $envContent, $m)) $config['dbname'] = trim($m[1]);
        if (preg_match('/DB_USER=(.+)/', $envContent, $m)) $config['username'] = trim($m[1]);
        if (preg_match('/DB_PASS=(.+)/', $envContent, $m)) $config['password'] = trim($m[1]);

        return $config;
    }

    return $config;
}

function durationToSeconds($duration) {
    if (!$duration) {
        return 0;
    }
    if (preg_match('/^(\d+)([hdw])$/', $duration, $m)) {
        $value = (int)$m[1];
        $unit = $m[2];
        if ($unit === 'h') return $value * 3600;
        if ($unit === 'd') return $value * 86400;
        if ($unit === 'w') return $value * 604800;
    }
    return 0;
}

function upsertRadcheck(PDO $db, $username, $password) {
    $delete = $db->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
    $delete->execute([$username]);

    $insert = $db->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
    $insert->execute([$username, $password]);
}

function upsertRadreply(PDO $db, $username, $attributes) {
    foreach ($attributes as $attr => $value) {
        $delete = $db->prepare("DELETE FROM radreply WHERE username = ? AND attribute = ?");
        $delete->execute([$username, $attr]);

        $insert = $db->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ':=', ?)");
        $insert->execute([$username, $attr, (string)$value]);
    }
}

function disableUser(PDO $db, $username) {
    $db->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$username]);
    $db->prepare("DELETE FROM radreply WHERE username = ?")->execute([$username]);
}

$settings = loadSettings();
$hotspot = $settings['hotspot'] ?? [];
$backend = $hotspot['backend'] ?? 'mikrotik';

$backupToRadius = (bool)($hotspot['backup_to_radius'] ?? false);

if ($backend !== 'radius' && !$backupToRadius) {
    // When backend is not radius and backup mode is disabled, do nothing (safe default)
    echo "OK: hotspot backend is not radius (backup disabled)\n";
    return;
}

$radiusCfg = $hotspot['radius'] ?? [];
if (!($radiusCfg['enabled'] ?? false)) {
    echo "SKIP: radius is disabled in settings\n";
    return;
}

$acsDbCfg = loadAcsDbConfig();

try {
    $acs = new PDO(
        "mysql:host={$acsDbCfg['host']};port={$acsDbCfg['port']};dbname={$acsDbCfg['dbname']};charset=utf8mb4",
        $acsDbCfg['username'],
        $acsDbCfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Error connecting to ACS DB: " . $e->getMessage() . "\n");
}

try {
    $radius = new PDO(
        "mysql:host={$radiusCfg['db_host']};port={$radiusCfg['db_port']};dbname={$radiusCfg['db_name']};charset=utf8mb4",
        $radiusCfg['db_user'],
        $radiusCfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Error connecting to RADIUS DB: " . $e->getMessage() . "\n");
}

// -----------------------------------------------------------------
// 1. Sync Hotspot Vouchers
// -----------------------------------------------------------------
$sqlVouchers = "
SELECT
    v.username,
    v.password,
    v.status,
    v.limit_uptime,
    v.duration,
    p.rate_limit,
    p.duration_seconds
FROM hotspot_vouchers v
LEFT JOIN hotspot_profiles p ON p.name = v.profile
";

$vouchers = $acs->query($sqlVouchers)->fetchAll(PDO::FETCH_ASSOC);

$vEnabled = 0;
$vDisabled = 0;

foreach ($vouchers as $v) {
    $username = $v['username'] ?? '';
    if ($username === '') continue;

    $status = $v['status'] ?? '';
    $allowed = in_array($status, ['unused', 'active', 'sold'], true);

    if (!$allowed) {
        disableUser($radius, $username);
        $vDisabled++;
        continue;
    }

    $password = $v['password'] ?? '';
    upsertRadcheck($radius, $username, $password);

    $rateLimit = $v['rate_limit'] ?? '';
    $sessionTimeout = 0;
    if (!empty($v['limit_uptime'])) {
        $sessionTimeout = (int)$v['limit_uptime'];
    } elseif (!empty($v['duration_seconds'])) {
        $sessionTimeout = (int)$v['duration_seconds'];
    } elseif (!empty($v['duration'])) {
        $sessionTimeout = durationToSeconds($v['duration']);
    }

    $reply = [];
    if (!empty($rateLimit)) {
        $reply['Mikrotik-Rate-Limit'] = $rateLimit;
    }
    if ($sessionTimeout > 0) {
        $reply['Session-Timeout'] = $sessionTimeout;
    }

    if (!empty($reply)) {
        upsertRadreply($radius, $username, $reply);
    }
    $vEnabled++;
}

// -----------------------------------------------------------------
// 2. Sync PPPoE Customers (Billing)
// -----------------------------------------------------------------
$sqlCustomers = "
SELECT 
    c.pppoe_username, 
    c.pppoe_password, 
    c.status,
    p.mikrotik_profile,
    p.speed
FROM customers c
LEFT JOIN packages p ON c.package_id = p.id
WHERE c.pppoe_username IS NOT NULL AND c.pppoe_username != ''
";

$customers = $acs->query($sqlCustomers)->fetchAll(PDO::FETCH_ASSOC);

$cEnabled = 0;
$cDisabled = 0;

foreach ($customers as $c) {
    $username = $c['pppoe_username'];
    $status = $c['status'] ?? 'active';

    if ($status !== 'active') {
        disableUser($radius, $username);
        $cDisabled++;
        continue;
    }

    $password = $c['pppoe_password'] ?? '';
    if ($password === '') continue;

    upsertRadcheck($radius, $username, $password);

    $reply = [
        'Filter-Id' => 'pppoe'
    ];

    // Map package speed to Mikrotik-Rate-Limit if possible
    $rateLimit = $c['speed'] ?? '';
    if (!empty($rateLimit)) {
        // Convert simple format (10M) to MikroTik format (10M/10M) if needed
        if (strpos($rateLimit, '/') === false) {
            $rateLimit = $rateLimit . '/' . $rateLimit; // Symmetric upload/download
        }
        $reply['Mikrotik-Rate-Limit'] = $rateLimit;
    }

    upsertRadreply($radius, $username, $reply);
    $cEnabled++;
}

echo "OK: Sync completed.\n";
echo "Vouchers: enabled={$vEnabled}, disabled={$vDisabled}\n";
echo "Customers: enabled={$cEnabled}, disabled={$cDisabled}\n";
