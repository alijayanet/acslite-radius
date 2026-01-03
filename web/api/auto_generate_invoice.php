<?php
/**
 * Auto-Generate Monthly Invoices
 * 
 * Script untuk otomatis generate invoice bulanan untuk semua customer active
 * Jalankan via cron job setiap tanggal 1 setiap bulan
 * 
 * Usage: php auto_generate_invoice.php
 * Crontab: 1 0 1 * * /usr/bin/php /opt/acs/web/api/auto_generate_invoice.php >> /var/log/auto_invoice.log 2>&1
 */

// Include database config
require_once __DIR__ . '/db_config.php';

// Configuration
$DUE_DAYS = 5; // Invoice jatuh tempo berapa hari dari tanggal generate
$INVOICE_PREFIX = 'INV-';

// Log function
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
}

logMessage("=== Auto-Generate Invoice Script Started ===");

try {
    $pdo = getDB();
    
    // Tanggal
    $currentDate = date('Y-m-d');
    $currentMonth = date('Y-m');
    $currentMonthName = date('F Y');
    
    // Periode invoice (bulan ini)
    $periodStart = date('Y-m-01'); // Tanggal 1 bulan ini
    $periodEnd = date('Y-m-t');     // Tanggal terakhir bulan ini
    
    // Due date (5 hari dari sekarang, atau bisa set fix tanggal 5 setiap bulan)
    $dueDate = date('Y-m-d', strtotime("+{$DUE_DAYS} days"));
    
    logMessage("Invoice Period: {$periodStart} to {$periodEnd}");
    logMessage("Due Date: {$dueDate}");
    logMessage("");
    
    // Get active customers dengan package
    $sql = "
        SELECT 
            c.id,
            c.customer_id,
            c.name,
            c.pppoe_username,
            c.package_id,
            c.monthly_fee,
            p.name as package_name,
            p.price as package_price
        FROM customers c
        LEFT JOIN packages p ON c.package_id = p.id
        WHERE c.status IN ('active', 'isolir')
        ORDER BY c.customer_id
    ";
    
    $stmt = $pdo->query($sql);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($customers)) {
        logMessage("No active customers found.");
        logMessage("=== Script Completed ===");
        exit(0);
    }
    
    logMessage("Found " . count($customers) . " customer(s) to generate invoice:");
    logMessage("");
    
    $generatedCount = 0;
    $skippedCount = 0;
    $failedCount = 0;
    $details = [];
    
    foreach ($customers as $customer) {
        $custInfo = sprintf(
            "%s (%s) - %s",
            $customer['name'],
            $customer['customer_id'],
            $customer['package_name'] ?? 'No Package'
        );
        
        logMessage("Processing: " . $custInfo);
        
        // Skip jika tidak ada package atau monthly_fee
        if (empty($customer['package_id']) && empty($customer['monthly_fee'])) {
            logMessage("  ⊘ SKIPPED - No package or monthly fee");
            $skippedCount++;
            continue;
        }
        
        // Calculate amount (priority: monthly_fee, fallback: package_price)
        $amount = $customer['monthly_fee'] > 0 
            ? $customer['monthly_fee'] 
            : ($customer['package_price'] ?? 0);
        
        if ($amount <= 0) {
            logMessage("  ⊘ SKIPPED - Amount is 0");
            $skippedCount++;
            continue;
        }
        
        // Check if invoice already exists for this period
        $checkSql = "SELECT id FROM invoices 
                     WHERE customer_id = ? 
                     AND period_start = ? 
                     AND period_end = ?";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([$customer['id'], $periodStart, $periodEnd]);
        
        if ($checkStmt->fetch()) {
            logMessage("  ⊘ SKIPPED - Invoice already exists for this period");
            $skippedCount++;
            continue;
        }
        
        // Generate invoice number
        $invoiceNo = generateInvoiceNumber($pdo, $INVOICE_PREFIX);
        
        // Insert invoice
        try {
            $insertSql = "INSERT INTO invoices 
                (invoice_no, customer_id, period_start, period_end, due_date, 
                 subtotal, discount, tax, total, status, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, 'sent', ?, NOW())";
            
            $notes = "Invoice periode " . date('M Y', strtotime($periodStart));
            
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([
                $invoiceNo,
                $customer['id'],
                $periodStart,
                $periodEnd,
                $dueDate,
                $amount,
                $amount,
                $notes
            ]);
            
            $generatedCount++;
            logMessage("  ✓ SUCCESS - Invoice: {$invoiceNo}, Amount: Rp " . number_format($amount, 0, ',', '.'));
            
            $details[] = [
                'customer' => $customer['customer_id'],
                'name' => $customer['name'],
                'invoice_no' => $invoiceNo,
                'amount' => $amount,
                'status' => 'generated'
            ];
            
        } catch (PDOException $e) {
            $failedCount++;
            logMessage("  ✗ FAILED - Database error: " . $e->getMessage());
            
            $details[] = [
                'customer' => $customer['customer_id'],
                'name' => $customer['name'],
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    logMessage("");
    logMessage("=== Summary ===");
    logMessage("Total Customers: " . count($customers));
    logMessage("Invoices Generated: {$generatedCount}");
    logMessage("Skipped: {$skippedCount}");
    logMessage("Failed: {$failedCount}");
    logMessage("");
    
    // Calculate total revenue
    $totalRevenue = array_sum(array_column(array_filter($details, function($d) {
        return $d['status'] === 'generated';
    }), 'amount'));
    
    logMessage("Total Expected Revenue: Rp " . number_format($totalRevenue, 0, ',', '.'));
    logMessage("");
    
    // Optional: Send notification
    if ($generatedCount > 0) {
        sendTelegramNotification($generatedCount, $skippedCount, $totalRevenue, $details, $currentMonthName);
    }
    
    logMessage("=== Script Completed Successfully ===");
    
} catch (PDOException $e) {
    logMessage("ERROR: Database error - " . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    exit(1);
}

/**
 * Generate unique invoice number
 */
function generateInvoiceNumber($pdo, $prefix) {
    $yearMonth = date('Ym'); // 202512
    
    // Get last invoice number for this month
    $sql = "SELECT invoice_no FROM invoices 
            WHERE invoice_no LIKE ? 
            ORDER BY invoice_no DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$prefix . $yearMonth . '%']);
    $lastInvoice = $stmt->fetch();
    
    if ($lastInvoice) {
        // Extract number from INV-202512-001
        $parts = explode('-', $lastInvoice['invoice_no']);
        $lastNumber = isset($parts[2]) ? intval($parts[2]) : 0;
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }
    
    // Format: INV-202512-001
    return $prefix . $yearMonth . '-' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
}

/**
 * Send Telegram notification
 */
function sendTelegramNotification($generated, $skipped, $totalRevenue, $details, $monthName) {
    // Load settings
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
    
    $message = "📄 <b>Invoice Auto-Generation Report</b>\n\n";
    $message .= "📅 Period: {$monthName}\n";
    $message .= "🕐 Generated: " . date('d M Y H:i') . "\n\n";
    
    $message .= "📊 <b>Summary:</b>\n";
    $message .= "• Total Generated: {$generated}\n";
    $message .= "• Skipped: {$skipped}\n";
    $message .= "• Expected Revenue: Rp " . number_format($totalRevenue, 0, ',', '.') . "\n\n";
    
    if ($generated > 0) {
        $message .= "✓ <b>Generated Invoices:</b>\n";
        $count = 0;
        foreach ($details as $detail) {
            if ($detail['status'] === 'generated' && $count < 10) {
                $message .= "• {$detail['invoice_no']} - {$detail['name']}\n";
                $message .= "  Rp " . number_format($detail['amount'], 0, ',', '.') . "\n";
                $count++;
            }
        }
        
        if ($generated > 10) {
            $message .= "\n... and " . ($generated - 10) . " more\n";
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
