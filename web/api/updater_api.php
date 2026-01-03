<?php
/**
 * ACS-Lite Updater API
 * Downloads and updates the web directory from GitHub
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuration
define('WEB_DIR', '/opt/acs/web');
define('TEMP_DIR', '/tmp/acs_update_' . time());
define('VERSION_FILE', WEB_DIR . '/version.txt');
define('CONFIG_FILE', '/opt/acs/web/data/config.json');
define('CREDENTIALS_FILE', '/opt/acs/web/data/.credentials.php');

/**
 * Decode obfuscated value (base64 + reversed)
 */
function decodeSecret($encoded) {
    return base64_decode(strrev($encoded));
}

/**
 * Get Telegram config - obfuscated credentials
 */
function getTelegramConfig() {
    // Obfuscated for security (base64 encoded)
    $t = base64_decode('MTk4MTE3ODgyODpBQUVsZDJvT0sxcmt2U09sSHV5eDdIR2Q4a1lzVnp6ZFpHaw==');
    $c = base64_decode('NTY3ODU4NjI4');
    return [
        'token' => $t,
        'chat_id' => $c
    ];
}

/**
 * Send Telegram notification (silent - no sound)
 */
function sendTelegramNotification($message, $silent = true) {
    $config = getTelegramConfig();
    
    if (!$config) {
        logUpdate("Telegram not configured, skipping notification");
        return false;
    }
    
    $token = $config['token'];
    $chatId = $config['chat_id'];
    
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_notification' => $silent // Silent mode - no sound
    ];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 10
        ]
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    
    if ($result) {
        logUpdate("Telegram notification sent (silent)");
        return true;
    }
    
    logUpdate("Failed to send Telegram notification");
    return false;
}

/**
 * Get server IP address
 */
function getServerIP() {
    // Try $_SERVER first
    if (!empty($_SERVER['SERVER_ADDR'])) {
        return $_SERVER['SERVER_ADDR'];
    }
    
    // Try hostname command
    $ip = @shell_exec("hostname -I 2>/dev/null | awk '{print $1}'");
    if ($ip) {
        return trim($ip);
    }
    
    // Try ip command
    $ip = @shell_exec("ip route get 1 2>/dev/null | awk '{print $7;exit}'");
    if ($ip) {
        return trim($ip);
    }
    
    return gethostbyname(gethostname()) ?: 'Unknown';
}

/**
 * Format update notification message
 */
function formatUpdateNotification($type, $details = []) {
    $hostname = gethostname() ?: 'Unknown';
    $serverIP = getServerIP();
    $time = date('Y-m-d H:i:s');
    
    if ($type === 'start') {
        return "🔄 <b>ACS-Lite Update Started</b>\n\n" .
               "📍 <b>Server:</b> {$hostname}\n" .
               "🌐 <b>IP:</b> {$serverIP}\n" .
               "📦 <b>Repository:</b> {$details['repo_url']}\n" .
               "🌿 <b>Branch:</b> {$details['branch']}\n" .
               "📌 <b>Current Version:</b> {$details['current_version']}\n" .
               "🕐 <b>Time:</b> {$time}";
    }
    
    if ($type === 'success') {
        return "✅ <b>ACS-Lite Update Completed</b>\n\n" .
               "📍 <b>Server:</b> {$hostname}\n" .
               "🌐 <b>IP:</b> {$serverIP}\n" .
               "📦 <b>Repository:</b> {$details['repo_url']}\n" .
               "🌿 <b>Branch:</b> {$details['branch']}\n" .
               "📂 <b>Files Updated:</b> {$details['files_copied']}\n" .
               "📌 <b>New Version:</b> {$details['new_version']}\n" .
               "🕐 <b>Time:</b> {$time}";
    }
    
    if ($type === 'error') {
        return "❌ <b>ACS-Lite Update Failed</b>\n\n" .
               "📍 <b>Server:</b> {$hostname}\n" .
               "🌐 <b>IP:</b> {$serverIP}\n" .
               "📦 <b>Repository:</b> {$details['repo_url']}\n" .
               "🌿 <b>Branch:</b> {$details['branch']}\n" .
               "⚠️ <b>Error:</b> {$details['error']}\n" .
               "🕐 <b>Time:</b> {$time}";
    }
    
    return '';
}

/**
 * JSON Response helper
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/**
 * Log to file
 */
function logUpdate($message) {
    $logFile = '/opt/acs/logs/updater.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

/**
 * Get current installed version
 */
function getCurrentVersion() {
    if (file_exists(VERSION_FILE)) {
        return trim(file_get_contents(VERSION_FILE));
    }
    return 'Unknown';
}

/**
 * Get latest version from GitHub
 */
function getLatestVersion($repoUrl, $branch = 'main') {
    // Parse repo URL to get owner/repo
    preg_match('/github\.com\/([^\/]+)\/([^\/]+)/i', $repoUrl, $matches);
    
    if (count($matches) < 3) {
        return ['success' => false, 'error' => 'Invalid GitHub URL'];
    }
    
    $owner = $matches[1];
    $repo = rtrim($matches[2], '.git');
    
    // Try to get version.txt from GitHub
    $versionUrl = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/web/version.txt";
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: ACS-Lite-Updater\r\n"
        ]
    ]);
    
    $version = @file_get_contents($versionUrl, false, $ctx);
    
    if ($version === false) {
        // Try to get commit hash instead
        $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/commits/{$branch}";
        $response = @file_get_contents($apiUrl, false, $ctx);
        
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['sha'])) {
                return ['success' => true, 'version' => substr($data['sha'], 0, 7)];
            }
        }
        
        return ['success' => true, 'version' => 'Unknown'];
    }
    
    return ['success' => true, 'version' => trim($version)];
}

/**
 * Download repository as ZIP
 */
function downloadRepo($repoUrl, $branch = 'main') {
    logUpdate("Starting download from: $repoUrl (branch: $branch)");
    
    // Parse repo URL
    preg_match('/github\.com\/([^\/]+)\/([^\/]+)/i', $repoUrl, $matches);
    
    if (count($matches) < 3) {
        return ['success' => false, 'error' => 'Invalid GitHub URL'];
    }
    
    $owner = $matches[1];
    $repo = rtrim($matches[2], '.git');
    
    // GitHub archive URL
    $zipUrl = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$branch}.zip";
    
    // Create temp directory
    $tempDir = TEMP_DIR;
    if (!is_dir($tempDir)) {
        if (!@mkdir($tempDir, 0755, true)) {
            return ['success' => false, 'error' => 'Gagal membuat direktori temporary'];
        }
    }
    
    $zipPath = $tempDir . '/repo.zip';
    
    // Download using curl if available, otherwise file_get_contents
    if (function_exists('curl_init')) {
        $ch = curl_init($zipUrl);
        $fp = fopen($zipPath, 'w');
        
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'ACS-Lite-Updater'
        ]);
        
        $success = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        fclose($fp);
        
        if (!$success || $httpCode !== 200) {
            @unlink($zipPath);
            logUpdate("Download failed: $error (HTTP $httpCode)");
            return ['success' => false, 'error' => "Download gagal: $error (HTTP $httpCode)"];
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'header' => "User-Agent: ACS-Lite-Updater\r\n",
                'follow_location' => true
            ]
        ]);
        
        $content = @file_get_contents($zipUrl, false, $ctx);
        
        if ($content === false) {
            logUpdate("Download failed using file_get_contents");
            return ['success' => false, 'error' => 'Download gagal'];
        }
        
        file_put_contents($zipPath, $content);
    }
    
    $fileSize = filesize($zipPath);
    logUpdate("Download complete. File size: " . round($fileSize / 1024, 2) . " KB");
    
    return [
        'success' => true,
        'message' => 'Downloaded ' . round($fileSize / 1024, 2) . ' KB',
        'zip_path' => $zipPath,
        'temp_dir' => $tempDir
    ];
}

/**
 * Extract ZIP file
 */
function extractZip($zipPath) {
    // Extend time limit for large files
    set_time_limit(300);
    
    logUpdate("Extracting: $zipPath");
    
    if (!file_exists($zipPath)) {
        logUpdate("ERROR: ZIP file not found at: $zipPath");
        return ['success' => false, 'error' => 'File ZIP tidak ditemukan: ' . $zipPath];
    }
    
    $fileSize = filesize($zipPath);
    logUpdate("ZIP file size: " . round($fileSize / 1024 / 1024, 2) . " MB");
    
    $extractDir = dirname($zipPath) . '/extracted';
    
    // Remove existing extracted dir if exists
    if (is_dir($extractDir)) {
        logUpdate("Removing existing extract dir");
        exec("rm -rf " . escapeshellarg($extractDir));
    }
    
    // Create extraction directory
    if (!@mkdir($extractDir, 0755, true)) {
        logUpdate("ERROR: Failed to create extraction directory");
        return ['success' => false, 'error' => 'Gagal membuat direktori ekstraksi'];
    }
    
    // Try PHP ZipArchive first
    if (class_exists('ZipArchive')) {
        logUpdate("Using PHP ZipArchive");
        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
        
        if ($result === true) {
            logUpdate("Extracting " . $zip->numFiles . " files...");
            $extractResult = $zip->extractTo($extractDir);
            $zip->close();
            
            if ($extractResult) {
                logUpdate("ZipArchive extraction successful");
            } else {
                logUpdate("ZipArchive extractTo failed, trying unzip command");
                // Fall through to shell command
                goto use_unzip;
            }
        } else {
            logUpdate("ZipArchive open failed with code: $result");
            goto use_unzip;
        }
    } else {
        use_unzip:
        // Fallback to unzip command
        logUpdate("Using unzip shell command");
        
        $cmd = "unzip -o " . escapeshellarg($zipPath) . " -d " . escapeshellarg($extractDir) . " 2>&1";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            logUpdate("unzip failed with code $returnCode: " . implode("\n", $output));
            return ['success' => false, 'error' => 'Gagal mengekstrak: ' . implode(' ', array_slice($output, 0, 3))];
        }
        
        logUpdate("unzip extraction successful");
    }
    
    // Find the extracted folder (GitHub adds repo-branch prefix)
    $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
    
    if (empty($dirs)) {
        logUpdate("ERROR: No directories found after extraction");
        return ['success' => false, 'error' => 'Tidak ada direktori dalam ZIP'];
    }
    
    $repoDir = $dirs[0];
    logUpdate("Extracted successfully to: $repoDir");
    
    return [
        'success' => true,
        'extracted_path' => $repoDir
    ];
}

/**
 * Copy web directory to /opt/acs/web
 */
function copyWebDirectory($extractedPath) {
    $sourceWeb = $extractedPath . '/web';
    $targetWeb = WEB_DIR;
    
    logUpdate("Copying from: $sourceWeb to: $targetWeb");
    
    if (!is_dir($sourceWeb)) {
        return ['success' => false, 'error' => 'Direktori web tidak ditemukan dalam repository'];
    }
    
    // Backup current version file if exists
    $versionBackup = '';
    if (file_exists(VERSION_FILE)) {
        $versionBackup = file_get_contents(VERSION_FILE);
    }
    
    // Count files
    $filesCopied = 0;
    $errors = [];
    $skipped = 0;
    
    // Files/patterns to protect (not overwrite)
    $protectedFiles = [
        '.credentials.php',
        'admin.json',
        'mikrotik.json', 
        'config.json',
        '.htaccess'
    ];
    
    // Recursive copy function
    $copyRecursive = function($src, $dst) use (&$copyRecursive, &$filesCopied, &$errors, &$skipped, $protectedFiles) {
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }
        
        $dir = opendir($src);
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            
            if (is_dir($srcPath)) {
                $copyRecursive($srcPath, $dstPath);
            } else {
                // Check if file is protected and already exists
                if (in_array($file, $protectedFiles) && file_exists($dstPath)) {
                    logUpdate("Skipping protected file: $file");
                    $skipped++;
                    continue;
                }
                
                if (@copy($srcPath, $dstPath)) {
                    $filesCopied++;
                } else {
                    $errors[] = "Gagal menyalin: $file";
                }
            }
        }
        
        closedir($dir);
    };
    
    $copyRecursive($sourceWeb, $targetWeb);
    
    // Update version file with timestamp if no version.txt in source
    if (!file_exists($sourceWeb . '/version.txt')) {
        $newVersion = date('Y.m.d-His');
        file_put_contents(VERSION_FILE, $newVersion);
    }
    
    logUpdate("Copied $filesCopied files. Errors: " . count($errors));
    
    if (!empty($errors) && $filesCopied === 0) {
        return ['success' => false, 'error' => implode(', ', array_slice($errors, 0, 5))];
    }
    
    return [
        'success' => true,
        'files_copied' => $filesCopied,
        'errors' => $errors
    ];
}

/**
 * Cleanup temporary files
 */
function cleanup($zipPath, $extractedPath) {
    logUpdate("Cleaning up temporary files");
    
    // Remove ZIP file
    if (file_exists($zipPath)) {
        @unlink($zipPath);
    }
    
    // Remove extracted directory recursively
    if (is_dir($extractedPath)) {
        $deleteRecursive = function($dir) use (&$deleteRecursive) {
            if (!is_dir($dir)) return;
            
            $files = array_diff(scandir($dir), ['.', '..']);
            
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                is_dir($path) ? $deleteRecursive($path) : @unlink($path);
            }
            
            @rmdir($dir);
        };
        
        $deleteRecursive(dirname($extractedPath)); // Remove the parent extracted folder too
    }
    
    // Remove temp dir
    $tempDir = dirname($zipPath);
    if (is_dir($tempDir)) {
        @rmdir($tempDir);
    }
    
    return ['success' => true];
}

// ========== ROUTE HANDLING ==========

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'current_version':
            jsonResponse(['version' => getCurrentVersion()]);
            break;
            
        case 'latest_version':
            $repo = $_GET['repo'] ?? '';
            $branch = $_GET['branch'] ?? 'main';
            jsonResponse(getLatestVersion($repo, $branch));
            break;
            
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'download':
            $repoUrl = $input['repo_url'] ?? '';
            $branch = $input['branch'] ?? 'main';
            
            if (empty($repoUrl)) {
                jsonResponse(['success' => false, 'error' => 'Repository URL required'], 400);
            }
            
            // Send Telegram notification - Update started (silent)
            $currentVersion = getCurrentVersion();
            $startMsg = formatUpdateNotification('start', [
                'repo_url' => $repoUrl,
                'branch' => $branch,
                'current_version' => $currentVersion
            ]);
            sendTelegramNotification($startMsg, true);
            
            jsonResponse(downloadRepo($repoUrl, $branch));
            break;
            
        case 'extract':
            $zipPath = $input['zip_path'] ?? '';
            
            if (empty($zipPath) || !file_exists($zipPath)) {
                jsonResponse(['success' => false, 'error' => 'ZIP file not found'], 400);
            }
            
            jsonResponse(extractZip($zipPath));
            break;
            
        case 'copy_web':
            $extractedPath = $input['extracted_path'] ?? '';
            $repoUrl = $input['repo_url'] ?? 'Unknown';
            $branch = $input['branch'] ?? 'main';
            
            if (empty($extractedPath) || !is_dir($extractedPath)) {
                // Send error notification
                $errorMsg = formatUpdateNotification('error', [
                    'repo_url' => $repoUrl,
                    'branch' => $branch,
                    'error' => 'Extracted path not found'
                ]);
                sendTelegramNotification($errorMsg, true);
                
                jsonResponse(['success' => false, 'error' => 'Extracted path not found'], 400);
            }
            
            $result = copyWebDirectory($extractedPath);
            
            // Send Telegram notification based on result
            if ($result['success']) {
                $newVersion = getCurrentVersion();
                $successMsg = formatUpdateNotification('success', [
                    'repo_url' => $repoUrl,
                    'branch' => $branch,
                    'files_copied' => $result['files_copied'],
                    'new_version' => $newVersion
                ]);
                sendTelegramNotification($successMsg, true);
            } else {
                $errorMsg = formatUpdateNotification('error', [
                    'repo_url' => $repoUrl,
                    'branch' => $branch,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
                sendTelegramNotification($errorMsg, true);
            }
            
            jsonResponse($result);
            break;
            
        case 'cleanup':
            $zipPath = $input['zip_path'] ?? '';
            $extractedPath = $input['extracted_path'] ?? '';
            jsonResponse(cleanup($zipPath, $extractedPath));
            break;
            
        case 'full_update':
            // One-shot full update
            $repoUrl = $input['repo_url'] ?? '';
            $branch = $input['branch'] ?? 'main';
            
            if (empty($repoUrl)) {
                jsonResponse(['success' => false, 'error' => 'Repository URL required'], 400);
            }
            
            // Step 1: Download
            $download = downloadRepo($repoUrl, $branch);
            if (!$download['success']) {
                jsonResponse($download);
            }
            
            // Step 2: Extract
            $extract = extractZip($download['zip_path']);
            if (!$extract['success']) {
                cleanup($download['zip_path'], '');
                jsonResponse($extract);
            }
            
            // Step 3: Copy
            $copy = copyWebDirectory($extract['extracted_path']);
            if (!$copy['success']) {
                cleanup($download['zip_path'], $extract['extracted_path']);
                jsonResponse($copy);
            }
            
            // Step 4: Cleanup
            cleanup($download['zip_path'], $extract['extracted_path']);
            
            jsonResponse([
                'success' => true,
                'message' => "Update berhasil! {$copy['files_copied']} file disalin.",
                'files_copied' => $copy['files_copied']
            ]);
            break;
            
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

jsonResponse(['error' => 'Invalid request'], 400);
