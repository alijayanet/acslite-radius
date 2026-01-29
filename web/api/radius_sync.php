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
        if (preg_match('/DB_DSN=([^:]+):([^@]+)@tcp\(([^:]+):(\d+)\)\/([^?\n\r]+)/', $envContent, $m)) {
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
    exit(0);
}

$radiusCfg = $hotspot['radius'] ?? [];
if (!($radiusCfg['enabled'] ?? false)) {
    echo "SKIP: radius is disabled in settings\n";
    exit(0);
}

$acsDbCfg = loadAcsDbConfig();

$acs = new PDO(
    "mysql:host={$acsDbCfg['host']};port={$acsDbCfg['port']};dbname={$acsDbCfg['dbname']};charset=utf8mb4",
    $acsDbCfg['username'],
    $acsDbCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$radius = new PDO(
    "mysql:host={$radiusCfg['db_host']};port={$radiusCfg['db_port']};dbname={$radiusCfg['db_name']};charset=utf8mb4",
    $radiusCfg['db_user'],
    $radiusCfg['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Load vouchers + profiles
$sql = "
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

$rows = $acs->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$enabledCount = 0;
$disabledCount = 0;

foreach ($rows as $r) {
    $username = $r['username'] ?? '';
    if ($username === '') {
        continue;
    }

    $status = $r['status'] ?? '';

    // Allow only these statuses
    $allowed = in_array($status, ['unused', 'active', 'sold'], true);

    if (!$allowed) {
        disableUser($radius, $username);
        $disabledCount++;
        continue;
    }

    $password = $r['password'] ?? '';
    upsertRadcheck($radius, $username, $password);

    $rateLimit = $r['rate_limit'] ?? '';

    // Determine session timeout in seconds
    $sessionTimeout = 0;
    if (!empty($r['limit_uptime'])) {
        $sessionTimeout = (int)$r['limit_uptime'];
    } elseif (!empty($r['duration_seconds'])) {
        $sessionTimeout = (int)$r['duration_seconds'];
    } elseif (!empty($r['duration'])) {
        $sessionTimeout = durationToSeconds($r['duration']);
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

    $enabledCount++;
}

echo "OK: synced vouchers to radius. enabled={$enabledCount} disabled={$disabledCount}\n";
