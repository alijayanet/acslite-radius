<?php
/**
 * Auto-Isolir Overdue Invoices
 * 
 * Script untuk otomatis isolir customer yang punya invoice overdue
 * Jalankan via cron job setiap hari
 * 
 * Usage: php auto_isolir_overdue.php
 * Crontab: 1 0 * * * /usr/bin/php /opt/acs/web/api/auto_isolir_overdue.php >> /var/log/auto_isolir.log 2>&1
 */

// Include database config
require_once __DIR__ . '/db_config.php';

// Log function
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
}

logMessage("=== Auto-Isolir Overdue Script Started ===");

try {
    $pdo = getDB();
    
    // Get customers dengan invoice overdue (belum bayar & due date sudah lewat)
    $sql = "
        SELECT DISTINCT 
            c.id, 
            c.customer_id, 
            c.name, 
            c.pppoe_username,
            c.status as current_status,
            COUNT(i.id) as overdue_count,
            SUM(i.total) as total_overdue
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE i.status IN ('sent', 'overdue')
        AND i.due_date < CURDATE()
        AND c.status = 'active'
        GROUP BY c.id
        ORDER BY c.customer_id
    ";
    
    $stmt = $pdo->query($sql);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($customers)) {
        logMessage("No customers with overdue invoices found.");
        logMessage("=== Script Completed Successfully ===");
        exit(0);
    }
    
    logMessage("Found " . count($customers) . " customer(s) with overdue invoices:");
    
    $isolatedCount = 0;
    $failedCount = 0;
    $details = [];
    
    foreach ($customers as $customer) {
        $custInfo = sprintf(
            "%s (%s) - %d invoice(s), Total: Rp %s",
            $customer['name'],
            $customer['customer_id'],
            $customer['overdue_count'],
            number_format($customer['total_overdue'], 0, ',', '.')
        );
        
        logMessage("Processing: " . $custInfo);
        
        // Call isolir endpoint via internal API
        $isolirData = [
            'action' => 'isolir',
            'customer_id' => $customer['id'],
            'reason' => 'Auto-isolir: Tagihan overdue'
        ];
        
        $ch = curl_init('http://localhost:8888/api/billing_api.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($isolirData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            
            if ($result['success'] ?? false) {
                $isolatedCount++;
                
                $mikrotikStatus = $result['mikrotik_status'] ?? 'unknown';
                $mikrotikMsg = $result['mikrotik_message'] ?? '';
                
                logMessage("  ✓ SUCCESS - MikroTik: {$mikrotikStatus} - {$mikrotikMsg}");
                
                // Update invoice status to overdue
                $updateSql = "UPDATE invoices SET status = 'overdue' WHERE customer_id = ? AND status = 'sent'";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([$customer['id']]);
                
                $details[] = [
                    'customer' => $customer['customer_id'],
                    'name' => $customer['name'],
                    'status' => 'isolated',
                    'mikrotik' => $mikrotikStatus,
                    'overdue_count' => $customer['overdue_count']
                ];
            } else {
                $failedCount++;
                $error = $result['error'] ?? 'Unknown error';
                logMessage("  ✗ FAILED - " . $error);
                
                $details[] = [
                    'customer' => $customer['customer_id'],
                    'name' => $customer['name'],
                    'status' => 'failed',
                    'error' => $error
                ];
            }
        } else {
            $failedCount++;
            logMessage("  ✗ FAILED - HTTP {$httpCode} or no response");
            
            $details[] = [
                'customer' => $customer['customer_id'],
                'name' => $customer['name'],
                'status' => 'failed',
                'error' => "HTTP {$httpCode}"
            ];
        }
    }
    
    logMessage("");
    logMessage("=== Summary ===");
    logMessage("Total Processed: " . count($customers));
    logMessage("Successfully Isolated: {$isolatedCount}");
    logMessage("Failed: {$failedCount}");
    logMessage("");
    
    // Optional: Send notification (Telegram/Email)
    // sendTelegramNotification($isolatedCount, $failedCount, $details);
    
    logMessage("=== Script Completed Successfully ===");
    
} catch (PDOException $e) {
    logMessage("ERROR: Database error - " . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    exit(1);
}

/**
 * Optional: Send Telegram notification
 */
function sendTelegramNotification($isolated, $failed, $details) {
    // Load settings for Telegram token & chat ID
    $settingsFile = __DIR__ . '/../data/settings.json';
    if (!file_exists($settingsFile)) {
        return;
    }
    
    $settings = json_decode(file_get_contents($settingsFile), true);
    $token = $settings['telegram']['bot_token'] ?? '';
    $chatId = $settings['telegram']['chat_id'] ?? '';
    
    if (empty($token) || empty($chatId)) {
        return;
    }
    
    $message = "🔴 <b>Auto-Isolir Report</b>\n\n";
    $message .= "📅 Date: " . date('d M Y H:i') . "\n\n";
    $message .= "📊 <b>Summary:</b>\n";
    $message .= "• Total Isolated: {$isolated}\n";
    $message .= "• Failed: {$failed}\n\n";
    
    if ($isolated > 0) {
        $message .= "✓ <b>Isolated Customers:</b>\n";
        foreach ($details as $detail) {
            if ($detail['status'] === 'isolated') {
                $message .= "• {$detail['customer']} - {$detail['name']}\n";
                $message .= "  ({$detail['overdue_count']} invoice overdue)\n";
            }
        }
    }
    
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);
}
