<?php

ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$SETTINGS_FILE = __DIR__ . '/../data/settings.json';
$RADIUS_CLIENTS_FILE = __DIR__ . '/../data/radius_clients.json';
$PPPOE_PLANS_FILE = __DIR__ . '/../data/pppoe_plans.json';

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function loadSettings() {
    global $SETTINGS_FILE;

    $defaults = [
        'hotspot' => [
            'backend' => 'mikrotik',
            'radius' => [
                'enabled' => false,
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_name' => 'radius',
                'db_user' => 'radius',
                'db_pass' => 'radius123'
            ]
        ]
    ];

    if (file_exists($SETTINGS_FILE)) {
        $loaded = json_decode(file_get_contents($SETTINGS_FILE), true) ?: [];
        return array_replace_recursive($defaults, $loaded);
    }

    return $defaults;
}

function getRadiusDbCfg() {
    $settings = loadSettings();
    return $settings['hotspot']['radius'] ?? [];
}

function pdoRadius() {
    $cfg = getRadiusDbCfg();

    if (empty($cfg['db_host']) || empty($cfg['db_name']) || empty($cfg['db_user'])) {
        throw new Exception('RADIUS DB config is incomplete. Configure it in Settings (hotspot.radius.*).');
    }

    $dsn = "mysql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']};charset=utf8mb4";

    return new PDO(
        $dsn,
        $cfg['db_user'],
        $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function upsertRadcheckPassword(PDO $db, $username, $password) {
    $del = $db->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
    $del->execute([$username]);
    $ins = $db->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
    $ins->execute([$username, $password]);
}

function upsertRadreplyAttribute(PDO $db, $username, $attribute, $value) {
    $del = $db->prepare("DELETE FROM radreply WHERE username = ? AND attribute = ?");
    $del->execute([$username, $attribute]);
    $ins = $db->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ':=', ?)");
    $ins->execute([$username, $attribute, (string)$value]);
}

function deleteRadiusUser(PDO $db, $username) {
    $db->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$username]);
    $db->prepare("DELETE FROM radreply WHERE username = ?")->execute([$username]);
}

function serviceIsActive() {
    $os = strtoupper(substr(PHP_OS, 0, 3));
    if ($os === 'WIN') {
        // On Windows, check if radius.exe is running (common for some Windows builds)
        // Or if freeradius service is running via net start or sc
        $out = @shell_exec('sc query freeradius | findstr RUNNING');
        if (!$out) {
            $out = @shell_exec('tasklist /FI "IMAGENAME eq radius.exe" | findstr radius.exe');
        }
        return !empty(trim((string)$out));
    }
    
    $out = @shell_exec('systemctl is-active freeradius 2>/dev/null');
    return trim((string)$out) === 'active';
}

function ensureDir($path) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function loadPppoePlans() {
    global $PPPOE_PLANS_FILE;
    if (file_exists($PPPOE_PLANS_FILE)) {
        return json_decode(file_get_contents($PPPOE_PLANS_FILE), true) ?: ['plans' => []];
    }
    return ['plans' => []];
}

function savePppoePlans($data) {
    global $PPPOE_PLANS_FILE;
    ensureDir($PPPOE_PLANS_FILE);
    file_put_contents($PPPOE_PLANS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function findPppoePlanById($data, $planId) {
    $plans = $data['plans'] ?? [];
    foreach ($plans as $p) {
        if (($p['id'] ?? '') === $planId) return $p;
    }
    return null;
}

function loadClients() {
    global $RADIUS_CLIENTS_FILE;

    if (file_exists($RADIUS_CLIENTS_FILE)) {
        return json_decode(file_get_contents($RADIUS_CLIENTS_FILE), true) ?: [];
    }

    return [
        'clients' => []
    ];
}

function saveClients($data) {
    global $RADIUS_CLIENTS_FILE;
    ensureDir($RADIUS_CLIENTS_FILE);
    file_put_contents($RADIUS_CLIENTS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function maskedClients($data) {
    $clients = $data['clients'] ?? [];
    foreach ($clients as &$c) {
        if (isset($c['secret']) && $c['secret'] !== '') {
            $c['secret'] = '********';
        }
    }
    return $clients;
}

function buildClientsConf($clients) {
    $buf = "# Auto-generated by ACS-Lite (radius_api.php)\n";
    $buf .= "# Do not edit manually. Use RADIUS Manager UI.\n\n";

    foreach ($clients as $c) {
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $c['name'] ?? 'client');
        $ip = trim($c['ip'] ?? '');
        $secret = $c['secret'] ?? '';
        if ($ip === '' || $secret === '') {
            continue;
        }

        $buf .= "client {$name} {\n";
        $buf .= "    ipaddr = {$ip}\n";
        $buf .= "    secret = {$secret}\n";
        $buf .= "    nastype = mikrotik\n";
        $buf .= "}\n\n";
    }

    return $buf;
}

function ensureClientsInclude($clientsConfPath, $includePath) {
    if (!file_exists($clientsConfPath)) {
        return false;
    }

    $conf = file_get_contents($clientsConfPath);
    if ($conf === false) {
        return false;
    }

    $includeLine = '$INCLUDE ' . $includePath;
    if (strpos($conf, $includeLine) !== false) {
        return true;
    }

    // Append include at the end
    $conf .= "\n\n# ACS-Lite managed clients\n" . $includeLine . "\n";
    return file_put_contents($clientsConfPath, $conf) !== false;
}

function writeClientsConf($content) {
    $paths = [
        '/etc/freeradius/3.0/clients.conf',
        '/etc/freeradius/clients.conf',
        '/etc/raddb/clients.conf'
    ];

    $includePaths = [
        '/etc/freeradius/3.0/clients-acs-lite.conf',
        '/etc/freeradius/clients-acs-lite.conf',
        '/etc/raddb/clients-acs-lite.conf'
    ];

    for ($i = 0; $i < count($paths); $i++) {
        $clientsConf = $paths[$i];
        $includeFile = $includePaths[$i];

        if (file_exists($clientsConf)) {
            // Write managed include file
            @file_put_contents($includeFile, $content);
            ensureClientsInclude($clientsConf, $includeFile);
            return $includeFile;
        }
    }

    // Fallback: write to most common include path
    $fallback = '/etc/freeradius/3.0/clients-acs-lite.conf';
    @file_put_contents($fallback, $content);
    return $fallback;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = [];

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;
    $action = $input['action'] ?? $action;
}

try {
    switch ($action) {
        case 'status':
            $serviceActive = serviceIsActive();
            $dbOk = false;
            $stats = [
                'users' => null,
                'online' => null,
                'sessions_today' => null,
                'unique_users_today' => null,
                'total_download_today' => null,
                'total_upload_today' => null
            ];
            $lastError = null;

            try {
                $pdo = pdoRadius();
                $dbOk = true;

                $stats['users'] = (int)$pdo->query("SELECT COUNT(DISTINCT username) FROM radcheck")->fetchColumn();
                
                // Count truly active sessions (updated in last 5 minutes OR started in last 5 minutes)
                $stats['online'] = (int)$pdo->query("
                    SELECT COUNT(DISTINCT username) FROM radacct 
                    WHERE acctstoptime IS NULL 
                    AND (acctupdatetime >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) 
                         OR acctstarttime >= DATE_SUB(NOW(), INTERVAL 5 MINUTE))
                ")->fetchColumn();
                
                // Count orphaned sessions (no stop time but not updated recently)
                $stats['orphaned'] = (int)$pdo->query("
                    SELECT COUNT(*) FROM radacct 
                    WHERE acctstoptime IS NULL 
                    AND acctstarttime < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                    AND (acctupdatetime IS NULL OR acctupdatetime < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
                ")->fetchColumn();
                
                $stats['sessions_today'] = (int)$pdo->query("SELECT COUNT(*) FROM radacct WHERE DATE(acctstarttime) = CURDATE()")->fetchColumn();
                $stats['unique_users_today'] = (int)$pdo->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE DATE(acctstarttime) = CURDATE()")->fetchColumn();
                
                // Total traffic today
                $trafficToday = $pdo->query("SELECT COALESCE(SUM(acctinputoctets), 0) as download, COALESCE(SUM(acctoutputoctets), 0) as upload FROM radacct WHERE DATE(acctstarttime) = CURDATE()")->fetch(PDO::FETCH_ASSOC);
                $stats['total_download_today'] = (int)($trafficToday['download'] ?? 0);
                $stats['total_upload_today'] = (int)($trafficToday['upload'] ?? 0);
            } catch (Exception $e) {
                $lastError = $e->getMessage();
            }

            jsonResponse([
                'success' => true,
                'service_active' => $serviceActive,
                'db_ok' => $dbOk,
                'stats' => $stats,
                'last_error' => $lastError
            ]);
            break;

        case 'test_db':
            $pdo = pdoRadius();
            $pdo->query('SELECT 1');
            jsonResponse(['success' => true, 'message' => 'RADIUS DB connection OK']);
            break;

        case 'diagnostics':
            // Comprehensive diagnostics to prevent long debugging sessions
            $diagnostics = [
                'checks' => [],
                'issues' => [],
                'recommendations' => []
            ];
            
            // 1. Check FreeRADIUS service
            $serviceActive = serviceIsActive();
            $diagnostics['checks']['freeradius_service'] = $serviceActive;
            if (!$serviceActive) {
                $diagnostics['issues'][] = 'FreeRADIUS service is not running';
                $diagnostics['recommendations'][] = 'Run: sudo systemctl start freeradius';
                
                // Try to get error from journal
                $journalError = @shell_exec('journalctl -u freeradius -n 5 --no-pager 2>/dev/null');
                if ($journalError && strpos($journalError, 'duplicate') !== false) {
                    $diagnostics['issues'][] = 'Duplicate client configuration detected';
                    $diagnostics['recommendations'][] = 'Check /etc/freeradius/3.0/clients.conf for duplicate IP addresses';
                }
            }
            
            // 2. Check ports
            $portsOpen = false;
            $portCheck = @shell_exec('netstat -ulnp 2>/dev/null | grep -E "1812|1813"');
            if (!empty(trim((string)$portCheck))) {
                $portsOpen = true;
            } else {
                // Try ss command
                $portCheck = @shell_exec('ss -ulnp 2>/dev/null | grep -E "1812|1813"');
                $portsOpen = !empty(trim((string)$portCheck));
            }
            $diagnostics['checks']['ports_1812_1813'] = $portsOpen;
            if (!$portsOpen && $serviceActive) {
                $diagnostics['issues'][] = 'RADIUS ports (1812/1813) not listening';
                $diagnostics['recommendations'][] = 'Check firewall: sudo ufw allow 1812/udp && sudo ufw allow 1813/udp';
            }
            
            // 3. Check database connection
            $dbOk = false;
            $dbError = null;
            try {
                $pdo = pdoRadius();
                $pdo->query('SELECT 1');
                $dbOk = true;
            } catch (Exception $e) {
                $dbError = $e->getMessage();
            }
            $diagnostics['checks']['database_connection'] = $dbOk;
            if (!$dbOk) {
                $diagnostics['issues'][] = 'Cannot connect to RADIUS database: ' . $dbError;
                $diagnostics['recommendations'][] = 'Check database credentials in Settings > Hotspot > RADIUS';
            }
            
            // 4. Check NAS clients configured
            $nasCount = 0;
            $nasClients = [];
            if ($dbOk) {
                try {
                    $stmt = $pdo->query("SELECT nasname, shortname, secret FROM nas");
                    $nasClients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $nasCount = count($nasClients);
                } catch (Exception $e) {}
            }
            $diagnostics['checks']['nas_clients_count'] = $nasCount;
            if ($nasCount === 0) {
                $diagnostics['issues'][] = 'No NAS clients configured';
                $diagnostics['recommendations'][] = 'Add NAS client in RADIUS Manager > NAS Clients tab';
            }
            
            // 5. Check for duplicate NAS IPs in clients.conf
            $duplicateCheck = @shell_exec('grep -h "ipaddr" /etc/freeradius/3.0/clients*.conf 2>/dev/null | sort | uniq -d');
            if (!empty(trim((string)$duplicateCheck))) {
                $diagnostics['issues'][] = 'Duplicate IP addresses found in clients configuration';
                $diagnostics['recommendations'][] = 'Remove duplicate entries from /etc/freeradius/3.0/clients.conf';
            }
            
            // 6. Check if SQL module is enabled
            $sqlEnabled = file_exists('/etc/freeradius/3.0/mods-enabled/sql');
            $diagnostics['checks']['sql_module_enabled'] = $sqlEnabled;
            if (!$sqlEnabled) {
                $diagnostics['issues'][] = 'SQL module is not enabled in FreeRADIUS';
                $diagnostics['recommendations'][] = 'Run: sudo ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/';
            }
            
            // 7. Check users exist
            $userCount = 0;
            if ($dbOk) {
                try {
                    $userCount = (int)$pdo->query("SELECT COUNT(DISTINCT username) FROM radcheck")->fetchColumn();
                } catch (Exception $e) {}
            }
            $diagnostics['checks']['radius_users_count'] = $userCount;
            if ($userCount === 0) {
                $diagnostics['issues'][] = 'No RADIUS users found in radcheck table';
                $diagnostics['recommendations'][] = 'Run Sync to populate users from vouchers/customers';
            }
            
            // Summary
            $diagnostics['healthy'] = empty($diagnostics['issues']);
            $diagnostics['summary'] = $diagnostics['healthy'] 
                ? 'All checks passed! RADIUS is properly configured.' 
                : count($diagnostics['issues']) . ' issue(s) found. See recommendations.';
            
            // Include NAS list for reference
            $diagnostics['nas_clients'] = array_map(function($n) {
                return ['name' => $n['shortname'], 'ip' => $n['nasname']];
            }, $nasClients);
            
            jsonResponse(['success' => true, 'diagnostics' => $diagnostics]);
            break;



        case 'cleanup_orphaned':
            $pdo = pdoRadius();
            
            // Count orphaned sessions first
            $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn();
            
            // Clean up sessions that:
            // 1. Have no stop time (acctstoptime IS NULL)
            // 2. Started more than 30 minutes ago
            // 3. Haven't been updated in the last 10 minutes
            $stmt = $pdo->prepare("
                UPDATE radacct 
                SET acctstoptime = NOW(), 
                    acctterminatecause = 'Auto-Cleanup-Orphaned' 
                WHERE acctstoptime IS NULL 
                  AND acctstarttime < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                  AND (acctupdatetime IS NULL OR acctupdatetime < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
            ");
            $stmt->execute();
            $cleaned = $stmt->rowCount();
            
            $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn();
            
            jsonResponse([
                'success' => true, 
                'message' => "Cleaned up {$cleaned} orphaned sessions",
                'before' => $countBefore,
                'after' => $countAfter,
                'cleaned' => $cleaned
            ]);
            break;

        case 'get_clients':
            // Load from RADIUS database (nas table) instead of JSON
            try {
                $pdo = pdoRadius();
                $stmt = $pdo->query("SELECT nasname, shortname, type, secret, description FROM nas ORDER BY shortname ASC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $clients = [];
                foreach ($rows as $row) {
                    $clients[] = [
                        'name' => $row['shortname'] ?? $row['nasname'],
                        'ip' => $row['nasname'],
                        'secret' => '********', // Masked for security
                        'type' => $row['type'] ?? 'other',
                        'description' => $row['description'] ?? ''
                    ];
                }
                jsonResponse(['success' => true, 'clients' => $clients, 'source' => 'database']);
            } catch (Exception $e) {
                // Fallback to JSON if DB fails
                $data = loadClients();
                jsonResponse(['success' => true, 'clients' => maskedClients($data), 'source' => 'json', 'db_error' => $e->getMessage()]);
            }
            break;

        case 'add_client':
            $name = trim($input['name'] ?? '');
            $ip = trim($input['ip'] ?? '');
            $secret = (string)($input['secret'] ?? '');
            $description = trim($input['description'] ?? 'Added via RADIUS Manager');

            if ($name === '' || $ip === '' || $secret === '') {
                jsonResponse(['success' => false, 'error' => 'Name, IP, and secret are required'], 400);
            }

            try {
                $pdo = pdoRadius();
                
                // Use INSERT ... ON DUPLICATE KEY UPDATE for upsert
                $stmt = $pdo->prepare("
                    INSERT INTO nas (nasname, shortname, type, ports, secret, description) 
                    VALUES (?, ?, 'other', 0, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        shortname = VALUES(shortname),
                        secret = VALUES(secret),
                        description = VALUES(description)
                ");
                $stmt->execute([$ip, $name, $secret, $description]);
                
                // Also save to JSON for backup
                $data = loadClients();
                $clients = $data['clients'] ?? [];
                // Remove existing with same IP
                $clients = array_values(array_filter($clients, fn($c) => ($c['ip'] ?? '') !== $ip));
                $clients[] = ['name' => $name, 'ip' => $ip, 'secret' => $secret];
                $data['clients'] = $clients;
                saveClients($data);

                jsonResponse(['success' => true, 'message' => "NAS client '{$name}' ({$ip}) added/updated in database"]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
            }
            break;

        case 'update_client':
            $oldIp = trim($input['old_ip'] ?? '');
            $name = trim($input['name'] ?? '');
            $ip = trim($input['ip'] ?? '');
            $secret = (string)($input['secret'] ?? '');
            $description = trim($input['description'] ?? '');

            if ($oldIp === '' || $name === '' || $ip === '') {
                jsonResponse(['success' => false, 'error' => 'old_ip, name, and ip are required'], 400);
            }

            try {
                $pdo = pdoRadius();
                
                if ($secret !== '' && $secret !== '********') {
                    // Update with new secret
                    $stmt = $pdo->prepare("
                        UPDATE nas SET nasname = ?, shortname = ?, secret = ?, description = ?
                        WHERE nasname = ?
                    ");
                    $stmt->execute([$ip, $name, $secret, $description, $oldIp]);
                } else {
                    // Update without changing secret
                    $stmt = $pdo->prepare("
                        UPDATE nas SET nasname = ?, shortname = ?, description = ?
                        WHERE nasname = ?
                    ");
                    $stmt->execute([$ip, $name, $description, $oldIp]);
                }
                
                if ($stmt->rowCount() === 0) {
                    jsonResponse(['success' => false, 'error' => 'NAS client not found'], 404);
                }

                jsonResponse(['success' => true, 'message' => "NAS client '{$name}' updated"]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
            }
            break;

        case 'delete_client':
            // Support both index (legacy) and ip (new)
            $ip = trim($input['ip'] ?? '');
            $index = isset($input['index']) ? (int)$input['index'] : -1;
            
            if ($ip === '' && $index >= 0) {
                // Legacy: delete by index from get_clients result
                try {
                    $pdo = pdoRadius();
                    $stmt = $pdo->query("SELECT nasname FROM nas ORDER BY shortname ASC");
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if ($index < count($rows)) {
                        $ip = $rows[$index];
                    }
                } catch (Exception $e) {
                    // Fallback to JSON
                    $data = loadClients();
                    $clients = $data['clients'] ?? [];
                    if ($index >= 0 && $index < count($clients)) {
                        $ip = $clients[$index]['ip'] ?? '';
                    }
                }
            }

            if ($ip === '') {
                jsonResponse(['success' => false, 'error' => 'IP address is required'], 400);
            }

            try {
                $pdo = pdoRadius();
                $stmt = $pdo->prepare("DELETE FROM nas WHERE nasname = ?");
                $stmt->execute([$ip]);
                
                // Also remove from JSON
                $data = loadClients();
                $clients = $data['clients'] ?? [];
                $clients = array_values(array_filter($clients, fn($c) => ($c['ip'] ?? '') !== $ip));
                $data['clients'] = $clients;
                saveClients($data);

                jsonResponse(['success' => true, 'message' => "NAS client {$ip} deleted"]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
            }
            break;

        case 'apply_clients':
            // Sync NAS from database to clients.conf file
            try {
                $pdo = pdoRadius();
                $stmt = $pdo->query("SELECT nasname, shortname, secret FROM nas");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $clients = [];
                foreach ($rows as $row) {
                    $clients[] = [
                        'name' => $row['shortname'] ?? 'client',
                        'ip' => $row['nasname'],
                        'secret' => $row['secret']
                    ];
                }
                
                $content = buildClientsConf($clients);
                $path = writeClientsConf($content);

                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    @shell_exec('net stop freeradius && net start freeradius');
                } else {
                    @shell_exec('systemctl restart freeradius 2>/dev/null');
                }

                jsonResponse(['success' => true, 'message' => "Applied " . count($clients) . " NAS clients to {$path} and restarted freeradius"]);
            } catch (Exception $e) {
                // Fallback to JSON
                $data = loadClients();
                $clients = $data['clients'] ?? [];
                $content = buildClientsConf($clients);
                $path = writeClientsConf($content);
                
                @shell_exec('systemctl restart freeradius 2>/dev/null');
                jsonResponse(['success' => true, 'message' => "Applied clients from JSON to {$path} (DB error: {$e->getMessage()})"]);
            }
            break;

        case 'import_from_mikrotik':
            // Import routers from mikrotik.json to NAS table
            $mikrotikFile = __DIR__ . '/../data/mikrotik.json';
            if (!file_exists($mikrotikFile)) {
                jsonResponse(['success' => false, 'error' => 'mikrotik.json not found'], 404);
            }
            
            $mikrotikData = json_decode(file_get_contents($mikrotikFile), true) ?: [];
            $routers = $mikrotikData['routers'] ?? [];
            
            if (empty($routers)) {
                jsonResponse(['success' => false, 'error' => 'No routers found in mikrotik.json']);
            }
            
            $defaultSecret = $input['default_secret'] ?? 'radius';
            $imported = 0;
            
            try {
                $pdo = pdoRadius();
                $stmt = $pdo->prepare("
                    INSERT INTO nas (nasname, shortname, type, ports, secret, description) 
                    VALUES (?, ?, 'other', 0, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        shortname = VALUES(shortname),
                        description = VALUES(description)
                ");
                
                foreach ($routers as $r) {
                    $ip = $r['ip'] ?? '';
                    $name = $r['name'] ?? $r['id'] ?? 'mikrotik';
                    if ($ip === '') continue;
                    
                    $stmt->execute([$ip, $name, $defaultSecret, "Imported from mikrotik.json"]);
                    $imported++;
                }
                
                jsonResponse(['success' => true, 'message' => "Imported {$imported} router(s) from mikrotik.json. Default secret: {$defaultSecret}"]);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
            }
            break;



        case 'run_sync':
            // Cross-platform PHP execution
            $syncScript = __DIR__ . '/radius_sync.php';
            
            if (!file_exists($syncScript)) {
                jsonResponse(['success' => false, 'error' => 'Sync script not found: ' . $syncScript], 500);
            }
            
            // Detect OS and set PHP path accordingly
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            
            if ($isWindows) {
                // On Windows, try to use the same PHP that's running this script
                $phpBin = PHP_BINARY;
                $cmd = '"' . $phpBin . '" "' . $syncScript . '" 2>&1';
            } else {
                // On Linux/Unix
                $phpBin = PHP_BINARY ?: '/usr/bin/php';
                $cmd = $phpBin . ' ' . escapeshellarg($syncScript) . ' 2>&1';
            }
            
            $output = @shell_exec($cmd);
            
            if ($output === null || $output === false) {
                // Fallback: try to include and run the sync script directly
                ob_start();
                try {
                    include($syncScript);
                    $output = ob_get_clean();
                } catch (Exception $ex) {
                    ob_end_clean();
                    jsonResponse(['success' => false, 'error' => 'Failed to execute sync: ' . $ex->getMessage()], 500);
                }
            }
            
            $output = trim((string)$output);
            if ($output === '') {
                $output = 'Sync completed (no output)';
            }
            
            jsonResponse(['success' => true, 'message' => $output]);
            break;

        case 'accounting':
            $limit = (int)($_GET['limit'] ?? 200);
            $limit = max(1, min($limit, 1000));
            $username = trim($_GET['username'] ?? '');

            $pdo = pdoRadius();
            
            if ($username !== '') {
                $stmt = $pdo->prepare("SELECT username, acctstarttime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets, nasipaddress, framedipaddress, acctterminatecause FROM radacct WHERE username LIKE ? ORDER BY radacctid DESC LIMIT " . $limit);
                $stmt->execute(["%" . $username . "%"]);
            } else {
                $stmt = $pdo->query("SELECT username, acctstarttime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets, nasipaddress, framedipaddress, acctterminatecause FROM radacct ORDER BY radacctid DESC LIMIT " . $limit);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            jsonResponse(['success' => true, 'rows' => $rows]);
            break;

        case 'active_sessions':
            $pdo = pdoRadius();
            $stmt = $pdo->query("
                SELECT username, framedipaddress, nasipaddress, acctstarttime, 
                       acctsessiontime, acctinputoctets, acctoutputoctets, acctsessionid
                FROM radacct 
                WHERE acctstoptime IS NULL 
                ORDER BY acctstarttime DESC
            ");
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['success' => true, 'sessions' => $sessions]);
            break;

        case 'disconnect_session':
            $username = trim($input['username'] ?? '');
            $sessionId = trim($input['session_id'] ?? '');
            
            if ($username === '') {
                jsonResponse(['success' => false, 'error' => 'Username is required'], 400);
            }
            
            // Get NAS info for this session
            $pdo = pdoRadius();
            $stmt = $pdo->prepare("SELECT nasipaddress, acctsessionid FROM radacct WHERE username = ? AND acctstoptime IS NULL LIMIT 1");
            $stmt->execute([$username]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                jsonResponse(['success' => false, 'error' => 'Session not found or already closed']);
            }
            
            // Note: Full CoA implementation would require sending RADIUS Disconnect-Request
            // For now, we just update the radacct table to mark session as stopped
            $stmt = $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset' WHERE username = ? AND acctstoptime IS NULL");
            $stmt->execute([$username]);
            
            jsonResponse(['success' => true, 'message' => "Session untuk {$username} telah ditandai sebagai disconnected. User mungkin perlu reconnect secara manual di MikroTik."]);
            break;

        case 'list_pppoe_users':
            $limit = (int)($_GET['limit'] ?? 500);
            $limit = max(1, min($limit, 2000));

            $pdo = pdoRadius();

            $sql = "
                SELECT DISTINCT rr.username
                FROM radreply rr
                WHERE rr.attribute = 'Filter-Id' AND rr.value = 'pppoe'
                ORDER BY rr.username ASC
                LIMIT {$limit}
            ";

            $usernames = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            $rows = [];

            $stmtPwd = $pdo->prepare("SELECT value FROM radcheck WHERE username = ? AND attribute='Cleartext-Password' LIMIT 1");
            $stmtRate = $pdo->prepare("SELECT value FROM radreply WHERE username = ? AND attribute='Mikrotik-Rate-Limit' LIMIT 1");
            $stmtTimeout = $pdo->prepare("SELECT value FROM radreply WHERE username = ? AND attribute='Session-Timeout' LIMIT 1");

            foreach ($usernames as $u) {
                $stmtPwd->execute([$u]);
                $pwd = (string)($stmtPwd->fetchColumn() ?: '');
                $stmtRate->execute([$u]);
                $rate = (string)($stmtRate->fetchColumn() ?: '');
                $stmtTimeout->execute([$u]);
                $timeout = $stmtTimeout->fetchColumn();

                $rows[] = [
                    'username' => $u,
                    'password' => ($pwd !== '') ? '********' : '',
                    'rate_limit' => $rate,
                    'session_timeout' => ($timeout !== false && $timeout !== null) ? (int)$timeout : null
                ];
            }

            jsonResponse(['success' => true, 'users' => $rows]);
            break;

        case 'add_pppoe_user':
            $username = trim($input['username'] ?? '');
            $password = (string)($input['password'] ?? '');
            $planId = trim((string)($input['plan_id'] ?? ''));
            $rateLimit = trim((string)($input['rate_limit'] ?? ''));
            $sessionTimeout = (int)($input['session_timeout'] ?? 0);

            if ($username === '' || $password === '') {
                jsonResponse(['success' => false, 'error' => 'Username and password are required'], 400);
            }

            // If plan_id provided, use plan defaults unless explicitly overridden
            if ($planId !== '') {
                $plansData = loadPppoePlans();
                $plan = findPppoePlanById($plansData, $planId);
                if ($plan) {
                    if ($rateLimit === '' && !empty($plan['rate_limit'])) {
                        $rateLimit = (string)$plan['rate_limit'];
                    }
                    if ($sessionTimeout <= 0 && !empty($plan['session_timeout'])) {
                        $sessionTimeout = (int)$plan['session_timeout'];
                    }
                }
            }

            $pdo = pdoRadius();
            upsertRadcheckPassword($pdo, $username, $password);

            // Mark as PPPoE-managed by ACS-Lite
            upsertRadreplyAttribute($pdo, $username, 'Filter-Id', 'pppoe');

            if ($rateLimit !== '') {
                upsertRadreplyAttribute($pdo, $username, 'Mikrotik-Rate-Limit', $rateLimit);
            }
            if ($sessionTimeout > 0) {
                upsertRadreplyAttribute($pdo, $username, 'Session-Timeout', $sessionTimeout);
            }

            jsonResponse(['success' => true, 'message' => 'PPPoE user saved to RADIUS']);
            break;

        case 'list_pppoe_plans':
            $data = loadPppoePlans();
            $plans = $data['plans'] ?? [];
            jsonResponse(['success' => true, 'plans' => $plans]);
            break;

        case 'add_pppoe_plan':
            $name = trim((string)($input['name'] ?? ''));
            $rateLimit = trim((string)($input['rate_limit'] ?? ''));
            $sessionTimeout = (int)($input['session_timeout'] ?? 0);

            if ($name === '') {
                jsonResponse(['success' => false, 'error' => 'Plan name is required'], 400);
            }

            $data = loadPppoePlans();
            $plans = $data['plans'] ?? [];

            $id = 'plan_' . bin2hex(random_bytes(6));
            $plans[] = [
                'id' => $id,
                'name' => $name,
                'rate_limit' => $rateLimit,
                'session_timeout' => $sessionTimeout
            ];

            $data['plans'] = $plans;
            savePppoePlans($data);
            jsonResponse(['success' => true, 'message' => 'Plan added', 'plan' => ['id' => $id]]);
            break;

        case 'delete_pppoe_plan':
            $planId = trim((string)($input['plan_id'] ?? ''));
            if ($planId === '') {
                jsonResponse(['success' => false, 'error' => 'plan_id is required'], 400);
            }

            $data = loadPppoePlans();
            $plans = $data['plans'] ?? [];
            $before = count($plans);
            $plans = array_values(array_filter($plans, function ($p) use ($planId) {
                return ($p['id'] ?? '') !== $planId;
            }));
            if (count($plans) === $before) {
                jsonResponse(['success' => false, 'error' => 'Plan not found'], 404);
            }
            $data['plans'] = $plans;
            savePppoePlans($data);
            jsonResponse(['success' => true, 'message' => 'Plan deleted']);
            break;

        case 'delete_pppoe_user':
            $username = trim($input['username'] ?? '');
            if ($username === '') {
                jsonResponse(['success' => false, 'error' => 'Username is required'], 400);
            }

            $pdo = pdoRadius();
            // Safety: only allow deleting users we manage
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM radreply WHERE username = ? AND attribute='Filter-Id' AND value='pppoe'");
            $stmt->execute([$username]);
            if ((int)$stmt->fetchColumn() <= 0) {
                jsonResponse(['success' => false, 'error' => 'Refusing to delete: user is not marked as pppoe-managed'], 400);
            }

            deleteRadiusUser($pdo, $username);
            jsonResponse(['success' => true, 'message' => 'PPPoE user deleted from RADIUS']);
            break;

        default:
            jsonResponse([
                'success' => true,
                'message' => 'RADIUS API',
                'endpoints' => [
                    'GET ?action=status' => 'Get FreeRADIUS service status + DB stats',
                    'GET ?action=test_db' => 'Test RADIUS DB connection',
                    'POST action=cleanup_orphaned' => 'Cleanup orphaned sessions (no Accounting-Stop)',
                    'GET ?action=get_clients' => 'List NAS clients from database (nas table)',
                    'POST action=add_client' => 'Add/update NAS client to database',
                    'POST action=update_client' => 'Update existing NAS client',
                    'POST action=delete_client' => 'Delete NAS client from database',
                    'POST action=apply_clients' => 'Write clients.conf and restart freeradius',
                    'POST action=import_from_mikrotik' => 'Import routers from mikrotik.json to NAS table',
                    'POST action=run_sync' => 'Run radius_sync.php (sync vouchers/customers to radcheck)',
                    'GET ?action=active_sessions' => 'Get currently active sessions',
                    'POST action=disconnect_session' => 'Disconnect a session by username',
                    'GET ?action=accounting&limit=200' => 'Get accounting rows (radacct)',
                    'GET ?action=list_pppoe_users&limit=500' => 'List PPPoE RADIUS users (Filter-Id=pppoe)',
                    'POST action=add_pppoe_user' => 'Add/update PPPoE RADIUS user',
                    'POST action=delete_pppoe_user' => 'Delete PPPoE RADIUS user',
                    'GET ?action=list_pppoe_plans' => 'List PPPoE plans',
                    'POST action=add_pppoe_plan' => 'Add PPPoE plan',
                    'POST action=delete_pppoe_plan' => 'Delete PPPoE plan'
                ]
            ]);

    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
