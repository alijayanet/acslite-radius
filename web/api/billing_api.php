<?php
/**
 * Billing API
 * For ACS-Lite ISP Billing System
 * 
 * Endpoints:
 * - GET  ?action=customers     - List all customers
 * - GET  ?action=customer&id=  - Get single customer
 * - POST action=add_customer   - Add new customer
 * - POST action=update_customer - Update customer
 * - POST action=delete_customer - Delete customer
 * - POST action=isolir         - Isolir customer
 * - POST action=unisolir       - Un-isolir customer
 * - GET  ?action=packages      - List packages
 * - GET  ?action=invoices      - List invoices
 * - POST action=create_invoice - Create invoice
 * - POST action=pay_invoice    - Record payment
 * - GET  ?action=dashboard     - Dashboard stats
 */

ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database connection
function getDB() {
    // Try to load from .env
    $envFile = '/opt/acs/.env';
    $config = [
        'host' => '127.0.0.1',
        'port' => '3306',
        'dbname' => 'acs',
        'username' => 'root',
        'password' => 'secret123'
    ];
    
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'DB_DSN=') === 0) {
                $dsn = substr($line, 7);
                // Parse: root:password@tcp(host:port)/dbname
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
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function generateCustomerId($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(customer_id, 4) AS UNSIGNED)) as max_id FROM customers");
    $result = $stmt->fetch();
    $nextId = ($result['max_id'] ?? 0) + 1;
    return 'CST' . str_pad($nextId, 3, '0', STR_PAD_LEFT);
}

function generateInvoiceNo($pdo) {
    $prefix = 'INV-' . date('Ym') . '-';
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(invoice_no, 13) AS UNSIGNED)) as max_id FROM invoices WHERE invoice_no LIKE ?");
    $stmt->execute([$prefix . '%']);
    $result = $stmt->fetch();
    $nextId = ($result['max_id'] ?? 0) + 1;
    return $prefix . str_pad($nextId, 3, '0', STR_PAD_LEFT);
}

function generatePaymentNo($pdo) {
    $prefix = 'PAY-' . date('Ym') . '-';
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(payment_no, 13) AS UNSIGNED)) as max_id FROM payments WHERE payment_no LIKE ?");
    $stmt->execute([$prefix . '%']);
    $result = $stmt->fetch();
    $nextId = ($result['max_id'] ?? 0) + 1;
    return $prefix . str_pad($nextId, 3, '0', STR_PAD_LEFT);
}

// ========================================
// MAIN HANDLER
// ========================================
$pdo = getDB();
if (!$pdo) {
    jsonResponse(['success' => false, 'error' => 'Database connection failed'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    $action = $input['action'] ?? $action;
}

try {
    switch ($action) {
        // ========== DASHBOARD ==========
        case 'dashboard':
            // Customer stats
            $stats = [];
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM customers");
            $stats['total_customers'] = (int)$stmt->fetch()['total'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM customers WHERE status = 'active'");
            $stats['active_customers'] = (int)$stmt->fetch()['total'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM customers WHERE status = 'isolir'");
            $stats['isolir_customers'] = (int)$stmt->fetch()['total'];
            
            // Invoice stats this month
            $monthStart = date('Y-m-01');
            $monthEnd = date('Y-m-t');
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM invoices WHERE due_date BETWEEN ? AND ? AND status = 'overdue'");
            $stmt->execute([$monthStart, $monthEnd]);
            $stats['overdue_invoices'] = (int)$stmt->fetch()['total'];
            
            // Revenue this month
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_date BETWEEN ? AND ?");
            $stmt->execute([$monthStart, $monthEnd]);
            $stats['monthly_revenue'] = (float)$stmt->fetch()['total'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM invoices WHERE status = 'paid' AND paid_at BETWEEN ? AND ?");
            $stmt->execute([$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
            $stats['paid_invoices'] = (int)$stmt->fetch()['total'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM invoices WHERE status IN ('draft', 'sent') AND due_date BETWEEN ? AND ?");
            $stmt->execute([$monthStart, $monthEnd]);
            $stats['unpaid_invoices'] = (int)$stmt->fetch()['total'];
            
            jsonResponse(['success' => true, 'stats' => $stats]);
            break;
            
        // ========== CUSTOMERS ==========
        case 'customers':
            $status = $_GET['status'] ?? '';
            $search = $_GET['search'] ?? '';
            $limit = (int)($_GET['limit'] ?? 1000);
            $offset = (int)($_GET['offset'] ?? 0);
            
            $sql = "SELECT c.*, p.name as package_name, p.speed as package_speed 
                    FROM customers c 
                    LEFT JOIN packages p ON c.package_id = p.id 
                    WHERE 1=1";
            $params = [];
            
            if ($status) {
                $sql .= " AND c.status = ?";
                $params[] = $status;
            }
            
            if ($search) {
                $sql .= " AND (c.name LIKE ? OR c.customer_id LIKE ? OR c.phone LIKE ? OR c.pppoe_username LIKE ?)";
                $searchTerm = "%{$search}%";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }
            
            // Note: LIMIT and OFFSET must be integers embedded directly, not as parameters
            $sql .= " ORDER BY c.created_at DESC LIMIT {$limit} OFFSET {$offset}";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $customers = $stmt->fetchAll();
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM customers WHERE 1=1";
            $countParams = [];
            if ($status) {
                $countSql .= " AND status = ?";
                $countParams[] = $status;
            }
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($countParams);
            $total = $countStmt->fetch()['total'];
            
            jsonResponse([
                'success' => true, 
                'customers' => $customers, 
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;
            
        case 'customer':
            $id = $_GET['id'] ?? $input['id'] ?? '';
            
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Customer ID required'], 400);
            }
            
            $stmt = $pdo->prepare("SELECT c.*, p.name as package_name, p.speed as package_speed 
                                   FROM customers c 
                                   LEFT JOIN packages p ON c.package_id = p.id 
                                   WHERE c.id = ? OR c.customer_id = ?");
            $stmt->execute([$id, $id]);
            $customer = $stmt->fetch();
            
            if (!$customer) {
                jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
            }
            
            // Get invoices - support both numeric id and string customer_id (CST001)
            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE customer_id = ? OR customer_id = ? ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$customer['id'], $customer['customer_id']]);
            $customer['invoices'] = $stmt->fetchAll();
            
            // Get payments - support both numeric id and string customer_id (CST001)
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE customer_id = ? OR customer_id = ? ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$customer['id'], $customer['customer_id']]);
            $customer['payments'] = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'customer' => $customer]);
            break;
            
        case 'add_customer':
            $required = ['name', 'phone'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    jsonResponse(['success' => false, 'error' => "Field '$field' is required"], 400);
                }
            }
            
            $customerId = generateCustomerId($pdo);
            
            $stmt = $pdo->prepare("INSERT INTO customers 
                (customer_id, name, phone, email, address, pppoe_username, pppoe_password, package_id, monthly_fee, billing_date, onu_serial, registered_at, status, portal_username, portal_password) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active', ?, ?)");
            
            $stmt->execute([
                $customerId,
                $input['name'],
                $input['phone'],
                $input['email'] ?? null,
                $input['address'] ?? null,
                $input['pppoe_username'] ?? null,
                $input['pppoe_password'] ?? null,
                $input['package_id'] ?? null,
                $input['monthly_fee'] ?? 0,
                $input['billing_date'] ?? 1,
                $input['onu_serial'] ?? null,
                !empty($input['portal_username']) ? $input['portal_username'] : ($input['pppoe_username'] ?? $customerId),
                // Default password '123456' if not provided or empty
                password_hash(!empty($input['portal_password']) ? $input['portal_password'] : '123456', PASSWORD_BCRYPT)
            ]);
            
            $id = $pdo->lastInsertId();
            
            jsonResponse([
                'success' => true, 
                'message' => 'Customer added successfully',
                'customer_id' => $customerId,
                'id' => $id
            ]);
            break;
            
        case 'update_customer':
            $id = $input['id'] ?? '';
            
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Customer ID required'], 400);
            }
            
            $fields = ['name', 'phone', 'email', 'address', 'pppoe_username', 'pppoe_password', 
                       'package_id', 'monthly_fee', 'billing_date', 'onu_serial', 'status',
                       'portal_username']; // portal_password handled separately if not empty
            
            $updates = [];
            $params = [];
            
            foreach ($fields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }

            // Handle password update separately to hash it
            if (!empty($input['portal_password'])) {
                $updates[] = "portal_password = ?";
                $params[] = password_hash($input['portal_password'], PASSWORD_BCRYPT);
            }
            
            if (empty($updates)) {
                jsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
            }
            
            $params[] = $id;
            $sql = "UPDATE customers SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            jsonResponse(['success' => true, 'message' => 'Customer updated successfully']);
            break;
            
        case 'delete_customer':
            $id = $input['id'] ?? '';
            
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Customer ID required'], 400);
            }
            
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            
            jsonResponse(['success' => true, 'message' => 'Customer deleted successfully']);
            break;
            
        case 'change_password':
            $customerId = $input['customer_id'] ?? '';
            $oldPassword = $input['old_password'] ?? '';
            $newPassword = $input['new_password'] ?? '';
            
            if (!$customerId || !$oldPassword || !$newPassword) {
                jsonResponse(['success' => false, 'error' => 'All fields required'], 400);
            }
            
            if (strlen($newPassword) < 4) {
                jsonResponse(['success' => false, 'error' => 'Password minimal 4 karakter'], 400);
            }
            
            // Get customer
            $stmt = $pdo->prepare("SELECT id, portal_password FROM customers WHERE id = ?");
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch();
            
            if (!$customer) {
                jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
            }
            
            // Verify old password
            if (!password_verify($oldPassword, $customer['portal_password'])) {
                jsonResponse(['success' => false, 'error' => 'Password lama salah'], 401);
            }
            
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE customers SET portal_password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $customerId]);
            
            jsonResponse(['success' => true, 'message' => 'Password berhasil diubah']);
            break;
            
        case 'isolir':
            $id = $input['id'] ?? $input['customer_id'] ?? '';
            
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Customer ID required'], 400);
            }
            
            // Update customer status
            $stmt = $pdo->prepare("UPDATE customers SET status = 'isolir', isolir_date = CURDATE() WHERE id = ? OR customer_id = ?");
            $stmt->execute([$id, $id]);
            
            // Get PPPoE username for MikroTik
            $stmt = $pdo->prepare("SELECT pppoe_username FROM customers WHERE id = ? OR customer_id = ?");
            $stmt->execute([$id, $id]);
            $customer = $stmt->fetch();
            
            jsonResponse([
                'success' => true, 
                'message' => 'Customer isolated',
                'pppoe_username' => $customer['pppoe_username'] ?? null
            ]);
            break;
            
        case 'unisolir':
            $id = $input['id'] ?? $input['customer_id'] ?? '';
            
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Customer ID required'], 400);
            }
            
            $stmt = $pdo->prepare("UPDATE customers SET status = 'active', isolir_date = NULL WHERE id = ? OR customer_id = ?");
            $stmt->execute([$id, $id]);
            
            $stmt = $pdo->prepare("SELECT pppoe_username FROM customers WHERE id = ? OR customer_id = ?");
            $stmt->execute([$id, $id]);
            $customer = $stmt->fetch();
            
            jsonResponse([
                'success' => true, 
                'message' => 'Customer activated',
                'pppoe_username' => $customer['pppoe_username'] ?? null
            ]);
            break;
            
        // ========== PACKAGES ==========
        case 'packages':
            $includeInactive = $_GET['all'] ?? false;
            if ($includeInactive) {
                $stmt = $pdo->query("SELECT * FROM packages ORDER BY price ASC");
            } else {
                $stmt = $pdo->query("SELECT * FROM packages ORDER BY price ASC");
            }
            $packages = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'packages' => $packages]);
            break;
            
        case 'add_package':
            $required = ['name', 'price'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    jsonResponse(['success' => false, 'error' => "Field '$field' is required"], 400);
                }
            }
            
            $stmt = $pdo->prepare("INSERT INTO packages (name, speed, price, description, mikrotik_profile, mikrotik_profile_isolir, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $input['name'],
                $input['speed'] ?? null,
                $input['price'],
                $input['description'] ?? null,
                $input['mikrotik_profile'] ?? null,
                $input['mikrotik_profile_isolir'] ?? 'isolir',
                $input['is_active'] ?? 1
            ]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Package added successfully',
                'id' => $pdo->lastInsertId()
            ]);
            break;
            
        case 'update_package':
            $id = $input['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Package ID required'], 400);
            }
            
            $fields = ['name', 'speed', 'price', 'description', 'mikrotik_profile', 'mikrotik_profile_isolir', 'is_active'];
            $updates = [];
            $params = [];
            
            foreach ($fields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (empty($updates)) {
                jsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
            }
            
            $params[] = $id;
            $sql = "UPDATE packages SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            jsonResponse(['success' => true, 'message' => 'Package updated successfully']);
            break;
            
        case 'delete_package':
            $id = $input['id'] ?? '';
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'Package ID required'], 400);
            }
            
            // Check if any customers are using this package
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM customers WHERE package_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                jsonResponse(['success' => false, 'error' => "Cannot delete: $count customers are using this package"], 400);
            }
            
            $stmt = $pdo->prepare("DELETE FROM packages WHERE id = ?");
            $stmt->execute([$id]);
            
            jsonResponse(['success' => true, 'message' => 'Package deleted successfully']);
            break;
            
        // ========== INVOICES ==========
        case 'invoices':
            $customerId = $_GET['customer_id'] ?? '';
            $status = $_GET['status'] ?? '';
            $limit = (int)($_GET['limit'] ?? 500);
            
            $sql = "SELECT i.*, c.name as customer_name, c.customer_id as customer_code 
                    FROM invoices i 
                    JOIN customers c ON i.customer_id = c.id 
                    WHERE 1=1";
            $params = [];
            
            if ($customerId) {
                $sql .= " AND i.customer_id = ?";
                $params[] = $customerId;
            }
            
            if ($status) {
                $sql .= " AND i.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY i.created_at DESC LIMIT {$limit}";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $invoices = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'invoices' => $invoices]);
            break;
            
        case 'create_invoice':
            $customerId = $input['customer_id'] ?? '';
            
            if (!$customerId) {
                jsonResponse(['success' => false, 'error' => 'Customer ID required'], 400);
            }
            
            // Get customer
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch();
            
            if (!$customer) {
                jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
            }
            
            $invoiceNo = generateInvoiceNo($pdo);
            $periodStart = $input['period_start'] ?? date('Y-m-01');
            $periodEnd = $input['period_end'] ?? date('Y-m-t');
            $dueDate = $input['due_date'] ?? date('Y-m-d', strtotime('+7 days'));
            $subtotal = $input['subtotal'] ?? $customer['monthly_fee'];
            $discount = $input['discount'] ?? 0;
            $tax = $input['tax'] ?? 0;
            $total = $subtotal - $discount + $tax;
            
            $stmt = $pdo->prepare("INSERT INTO invoices 
                (invoice_no, customer_id, period_start, period_end, due_date, subtotal, discount, tax, total, status, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?)");
            
            $stmt->execute([
                $invoiceNo,
                $customerId,
                $periodStart,
                $periodEnd,
                $dueDate,
                $subtotal,
                $discount,
                $tax,
                $total,
                $input['notes'] ?? null
            ]);
            
            jsonResponse([
                'success' => true, 
                'message' => 'Invoice created',
                'invoice_no' => $invoiceNo,
                'id' => $pdo->lastInsertId()
            ]);
            break;
            
        case 'pay_invoice':
            $invoiceId = $input['invoice_id'] ?? '';
            $amount = $input['amount'] ?? 0;
            
            if (!$invoiceId || !$amount) {
                jsonResponse(['success' => false, 'error' => 'Invoice ID and amount required'], 400);
            }
            
            // Get invoice
            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch();
            
            if (!$invoice) {
                jsonResponse(['success' => false, 'error' => 'Invoice not found'], 404);
            }
            
            // Create payment
            $paymentNo = generatePaymentNo($pdo);
            
            $stmt = $pdo->prepare("INSERT INTO payments 
                (payment_no, invoice_id, customer_id, amount, payment_method, payment_date, reference_no, notes) 
                VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?)");
            
            $stmt->execute([
                $paymentNo,
                $invoiceId,
                $invoice['customer_id'],
                $amount,
                $input['payment_method'] ?? 'cash',
                $input['reference_no'] ?? null,
                $input['notes'] ?? null
            ]);
            
            // Update invoice
            $newPaidAmount = $invoice['paid_amount'] + $amount;
            $newStatus = $newPaidAmount >= $invoice['total'] ? 'paid' : 'sent';
            
            $stmt = $pdo->prepare("UPDATE invoices SET paid_amount = ?, status = ?, paid_at = NOW() WHERE id = ?");
            $stmt->execute([$newPaidAmount, $newStatus, $invoiceId]);
            
            $autoUnisolir = false;
            $mikrotikStatus = 'not_applicable';
            $mikrotikMessage = '';
            
            // If fully paid, check if need to auto-unisolir
            if ($newStatus === 'paid') {
                // Check if customer has other unpaid invoices
                $stmt = $pdo->prepare("SELECT COUNT(*) as unpaid FROM invoices WHERE customer_id = ? AND status != 'paid'");
                $stmt->execute([$invoice['customer_id']]);
                $unpaidResult = $stmt->fetch();
                
                // Only auto-unisolir if ALL invoices are paid
                if ($unpaidResult['unpaid'] == 0) {
                    // Get customer data with package info
                    $stmt = $pdo->prepare("SELECT c.*, p.mikrotik_profile 
                                           FROM customers c 
                                           LEFT JOIN packages p ON c.package_id = p.id 
                                           WHERE c.id = ?");
                    $stmt->execute([$invoice['customer_id']]);
                    $customer = $stmt->fetch();
                    
                    if ($customer && $customer['status'] === 'isolir') {
                        // Update database
                        $stmt = $pdo->prepare("UPDATE customers SET status = 'active', isolir_date = NULL WHERE id = ?");
                        $stmt->execute([$invoice['customer_id']]);
                        
                        $autoUnisolir = true;
                        
                        // === PERBAIKAN: Ubah profile di MikroTik ===
                        if (!empty($customer['pppoe_username'])) {
                            require_once __DIR__ . '/MikroTikAPI.php';
                            
                            $mikrotikJsonFile = __DIR__ . '/../data/mikrotik.json';
                            $mtConfig = null;
                            
                            if (file_exists($mikrotikJsonFile)) {
                                $mikrotikData = json_decode(file_get_contents($mikrotikJsonFile), true);
                                
                                $selectedRouter = null;
                                if (!empty($mikrotikData['routers'])) {
                                    foreach ($mikrotikData['routers'] as $r) {
                                        $isEnabled = filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                                        if ($isEnabled) {
                                            $selectedRouter = $r;
                                            break;
                                        }
                                    }
                                    if (!$selectedRouter && !empty($mikrotikData['routers'][0])) {
                                        $selectedRouter = $mikrotikData['routers'][0];
                                    }
                                    
                                    if ($selectedRouter) {
                                        $mtConfig = [
                                            'host' => $selectedRouter['ip'],
                                            'user' => $selectedRouter['username'],
                                            'password' => $selectedRouter['password'],
                                            'port' => $selectedRouter['port'] ?? 8728,
                                            'default_profile' => $selectedRouter['default_profile'] ?? 'default'
                                        ];
                                    }
                                }
                            }
                            
                            if ($mtConfig) {
                                $mtApi = new MikroTikAPI();
                                if ($mtApi->connect($mtConfig['host'], $mtConfig['user'], $mtConfig['password'], $mtConfig['port'])) {
                                    $normalProfile = $customer['mikrotik_profile'] ?? $mtConfig['default_profile'];
                                    
                                    if ($mtApi->unIsolirUser($customer['pppoe_username'], $normalProfile)) {
                                        $mikrotikStatus = 'success';
                                        $mikrotikMessage = "Profile changed to '$normalProfile' and user disconnected";
                                    } else {
                                        $mikrotikStatus = 'failed';
                                        $mikrotikMessage = "Failed: " . $mtApi->getError();
                                    }
                                    $mtApi->disconnect();
                                } else {
                                    $mikrotikStatus = 'failed';
                                    $mikrotikMessage = "Failed to connect to MikroTik";
                                }
                            } else {
                                $mikrotikStatus = 'no_config';
                                $mikrotikMessage = "No MikroTik router configured";
                            }
                        }
                    }
                }
            }
            
            jsonResponse([
                'success' => true, 
                'message' => 'Payment recorded',
                'payment_no' => $paymentNo,
                'invoice_status' => $newStatus,
                'auto_unisolir' => $autoUnisolir,
                'mikrotik_status' => $mikrotikStatus,
                'mikrotik_message' => $mikrotikMessage
            ]);
            break;

        // ========== DASHBOARD ==========
        case 'dashboard':
            // 1. Customer Stats
            $stmt = $pdo->query("SELECT 
                COUNT(*) as total, 
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'isolir' THEN 1 ELSE 0 END) as isolir,
                SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) as terminated
                FROM customers");
            $custStats = $stmt->fetch();
            
            // 2. Invoice Stats (Due / Unpaid)
            $stmt = $pdo->query("SELECT COUNT(*) as overdue FROM invoices WHERE status = 'overdue'");
            $overdue = $stmt->fetch()['overdue'] ?? 0;
            
            $stmt = $pdo->query("SELECT COUNT(*) as unpaid FROM invoices WHERE status IN ('sent', 'overdue')");
            $unpaid = $stmt->fetch()['unpaid'] ?? 0;
            
            // 3. Monthly Revenue (Paid invoices in current month)
            $currentMonth = date('Y-m');
            $stmt = $pdo->prepare("SELECT SUM(amount) as revenue FROM payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = ?");
            $stmt->execute([$currentMonth]);
            $revenue = $stmt->fetch()['revenue'] ?? 0;
            
            // 4. Paid invoices count
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT invoice_id) as paid_count FROM payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = ?");
            $stmt->execute([$currentMonth]);
            $paidCount = $stmt->fetch()['paid_count'] ?? 0;

            jsonResponse([
                'success' => true,
                'stats' => [
                    'active_customers' => (int)$custStats['active'],
                    'isolir_customers' => (int)$custStats['isolir'],
                    'total_customers' => (int)$custStats['total'],
                    'overdue_invoices' => (int)$overdue,
                    'unpaid_invoices' => (int)$unpaid,
                    'paid_invoices' => (int)$paidCount,
                    'monthly_revenue' => (float)$revenue
                ]
            ]);
            break;
            
        // ========== RECENT TRANSACTIONS ==========
        case 'recent_transactions':
            $limit = (int)($_GET['limit'] ?? 50);
            
            $stmt = $pdo->prepare("
                SELECT 
                    p.payment_no,
                    p.amount,
                    p.payment_method,
                    p.payment_date,
                    c.name as customer_name,
                    c.customer_id as customer_code,
                    i.invoice_no,
                    pkg.name as package_name
                FROM payments p
                JOIN customers c ON p.customer_id = c.id
                JOIN invoices i ON p.invoice_id = i.id
                LEFT JOIN packages pkg ON c.package_id = pkg.id
                ORDER BY p.created_at DESC
                LIMIT {$limit}
            ");
            $stmt->execute();
            $transactions = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'transactions' => $transactions]);
            break;

        // ========== REPORT STATISTICS ==========
        case 'report_stats':
            $period = $_GET['period'] ?? 'month';
            
            // Determine date range based on period (use DATE not DATETIME for payment_date column)
            $today = date('Y-m-d');
            switch ($period) {
                case 'week':
                    $startDate = date('Y-m-d', strtotime('-7 days'));
                    $endDate = $today;
                    $groupBy = 'payment_date';
                    $dateFormat = '%Y-%m-%d';
                    break;
                case 'quarter':
                    $startDate = date('Y-m-d', strtotime('-3 months'));
                    $endDate = $today;
                    $groupBy = 'DATE_FORMAT(payment_date, "%Y-%m")';
                    $dateFormat = '%Y-%m';
                    break;
                case 'year':
                    $startDate = date('Y-m-d', strtotime('-1 year'));
                    $endDate = $today;
                    $groupBy = 'DATE_FORMAT(payment_date, "%Y-%m")';
                    $dateFormat = '%Y-%m';
                    break;
                default: // month - current month (1st to today)
                    $startDate = date('Y-m-01');
                    $endDate = $today;
                    $groupBy = 'payment_date';
                    $dateFormat = '%Y-%m-%d';
            }
            
            // 1. Total Revenue in period
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as count FROM payments WHERE payment_date >= ? AND payment_date <= ?");
            $stmt->execute([$startDate, $endDate]);
            $revenueResult = $stmt->fetch();
            $totalRevenue = (float)$revenueResult['total'];
            $totalPaymentsCount = (int)$revenueResult['count'];
            
            // Also get ALL TIME revenue for comparison
            $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as count FROM payments");
            $allTimeResult = $stmt->fetch();
            $allTimeRevenue = (float)$allTimeResult['total'];
            $allTimePaymentsCount = (int)$allTimeResult['count'];
            
            // 2. Active customers
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM customers WHERE status = 'active'");
            $activeCustomers = (int)$stmt->fetch()['total'];
            
            // 3. Pending invoices (unpaid)
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM invoices WHERE status IN ('sent', 'draft')");
            $pendingInvoices = (int)$stmt->fetch()['total'];
            
            // 4. Total Arrears (unpaid invoice amounts)
            $stmt = $pdo->query("SELECT COALESCE(SUM(total - paid_amount), 0) as total FROM invoices WHERE status IN ('sent', 'overdue', 'draft')");
            $totalArrears = (float)$stmt->fetch()['total'];
            
            // 5. Revenue trend data
            $stmt = $pdo->prepare("
                SELECT 
                    {$groupBy} as date_group,
                    DATE_FORMAT(payment_date, '{$dateFormat}') as label,
                    SUM(amount) as revenue
                FROM payments 
                WHERE payment_date BETWEEN ? AND ?
                GROUP BY date_group, label
                ORDER BY date_group ASC
            ");
            $stmt->execute([$startDate, $endDate]);
            $revenueTrend = $stmt->fetchAll();
            
            // 6. Package distribution (customers per package)
            $stmt = $pdo->query("
                SELECT 
                    COALESCE(p.name, 'Tanpa Paket') as package_name,
                    COUNT(c.id) as customer_count
                FROM customers c
                LEFT JOIN packages p ON c.package_id = p.id
                WHERE c.status = 'active'
                GROUP BY p.id, p.name
                ORDER BY customer_count DESC
                LIMIT 10
            ");
            $packageDistribution = $stmt->fetchAll();
            
            // 7. Payment methods breakdown
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(payment_method, 'cash') as method,
                    COUNT(*) as count,
                    SUM(amount) as total
                FROM payments
                WHERE payment_date BETWEEN ? AND ?
                GROUP BY payment_method
                ORDER BY count DESC
            ");
            $stmt->execute([$startDate, $endDate]);
            $paymentMethods = $stmt->fetchAll();
            
            // 8. Top packages by revenue
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(pkg.name, 'Tanpa Paket') as package_name,
                    COUNT(DISTINCT c.id) as customer_count,
                    SUM(p.amount) as total_revenue
                FROM payments p
                JOIN customers c ON p.customer_id = c.id
                LEFT JOIN packages pkg ON c.package_id = pkg.id
                WHERE p.payment_date BETWEEN ? AND ?
                GROUP BY pkg.id, pkg.name
                ORDER BY total_revenue DESC
                LIMIT 5
            ");
            $stmt->execute([$startDate, $endDate]);
            $topPackages = $stmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'period' => $period,
                'date_range' => ['start' => $startDate, 'end' => $endDate],
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'active_customers' => $activeCustomers,
                    'pending_invoices' => $pendingInvoices,
                    'total_arrears' => $totalArrears,
                    // Debug info
                    'payments_in_period' => $totalPaymentsCount,
                    'all_time_payments' => $allTimePaymentsCount,
                    'all_time_revenue' => $allTimeRevenue
                ],
                'revenue_trend' => $revenueTrend,
                'package_distribution' => $packageDistribution,
                'payment_methods' => $paymentMethods,
                'top_packages' => $topPackages
            ]);
            break;
        
        // ========== DEBUG PAYMENTS (for troubleshooting) ==========
        case 'debug_payments':
            // Get all payments with their dates
            $stmt = $pdo->query("SELECT id, payment_no, payment_date, amount, created_at FROM payments ORDER BY payment_date DESC LIMIT 50");
            $payments = $stmt->fetchAll();
            
            // Get date range info
            $today = date('Y-m-d');
            $monthStart = date('Y-m-01');
            
            // Count payments this month
            $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_date >= ? AND payment_date <= ?");
            $stmt->execute([$monthStart, $today]);
            $monthStats = $stmt->fetch();
            
            // Get all unique payment dates
            $stmt = $pdo->query("SELECT DISTINCT payment_date FROM payments ORDER BY payment_date DESC LIMIT 30");
            $uniqueDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            jsonResponse([
                'success' => true,
                'debug' => [
                    'today' => $today,
                    'month_start' => $monthStart,
                    'payments_this_month' => $monthStats,
                    'unique_payment_dates' => $uniqueDates,
                    'recent_payments' => $payments
                ]
            ]);
            break;
            
        default:
            jsonResponse([
                'success' => true,
                'message' => 'Billing API',
                'endpoints' => [
                    'GET ?action=dashboard' => 'Dashboard statistics',
                    'GET ?action=customers' => 'List customers',
                    'GET ?action=customer&id=' => 'Get customer detail',
                    'POST action=add_customer' => 'Add customer',
                    'POST action=update_customer' => 'Update customer',
                    'POST action=delete_customer' => 'Delete customer',
                    'POST action=isolir' => 'Isolir customer',
                    'POST action=unisolir' => 'Activate customer',
                    'GET ?action=packages' => 'List packages',
                    'GET ?action=invoices' => 'List invoices',
                    'POST action=create_invoice' => 'Create invoice',
                    'POST action=pay_invoice' => 'Record payment'
                ]
            ]);
            
        // ========== SYNC ONU SERIAL ==========
        case 'sync_onu_serial':
            $updated = 0;
            $skipped = 0;
            $details = [];
            
            $stmt = $pdo->query("SELECT customer_id, name, pppoe_username FROM customers WHERE onu_serial IS NULL OR onu_serial = ''");
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($customers as $cust) {
                if (empty($cust['pppoe_username'])) {
                    $details[] = "⊘ {$cust['customer_id']} - PPPoE kosong";
                    $skipped++;
                    continue;
                }
                
                $onuStmt = $pdo->prepare("SELECT serial_number FROM onu_locations WHERE username = ?");
                $onuStmt->execute([$cust['pppoe_username']]);
                $onu = $onuStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($onu) {
                    $updateStmt = $pdo->prepare("UPDATE customers SET onu_serial = ? WHERE customer_id = ?");
                    $updateStmt->execute([$onu['serial_number'], $cust['customer_id']]);
                    $details[] = "✓ {$cust['customer_id']} ({$cust['pppoe_username']}) -> {$onu['serial_number']}";
                    $updated++;
                } else {
                    $details[] = "⊘ {$cust['customer_id']} - ONU tidak ditemukan";
                    $skipped++;
                }
            }
            
            jsonResponse([
                'success' => true,
                'updated' => $updated,
                'skipped' => $skipped,
                'details' => $details
            ]);
            
        // ========== CHANGE PASSWORD ==========
        case 'change_password':
            if (empty($input['customer_id']) || empty($input['old_password']) || empty($input['new_password'])) {
                jsonResponse(['success' => false, 'error' => 'Data tidak lengkap'], 400);
            }
            
            $customerId = $input['customer_id'];
            $oldPassword = $input['old_password'];
            $newPassword = $input['new_password'];
            
            // Get current password
            $stmt = $pdo->prepare("SELECT portal_password FROM customers WHERE id = ?");
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$customer) {
                jsonResponse(['success' => false, 'error' => 'Customer tidak ditemukan'], 404);
            }
            
            // Verify old password
            if (!password_verify($oldPassword, $customer['portal_password'])) {
                jsonResponse(['success' => false, 'error' => 'Password lama tidak sesuai'], 401);
            }
            
            // Update to new password
            $hashedNewPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE customers SET portal_password = ? WHERE id = ?");
            $stmt->execute([$hashedNewPassword, $customerId]);
            
            jsonResponse(['success' => true, 'message' => 'Password berhasil diubah']);
            
        // ========== ISOLIR CUSTOMER ==========
        case 'isolir':
            if (empty($input['customer_id'])) {
                jsonResponse(['success' => false, 'error' => 'Customer ID required'], 400);
            }
            
            $customerId = $input['customer_id'];
            $reason = $input['reason'] ?? 'Belum bayar tagihan';
            
            // Get customer data (need pppoe_username and package for profile)
            $stmt = $pdo->prepare("SELECT c.*, p.mikrotik_profile, p.mikrotik_profile_isolir 
                                   FROM customers c 
                                   LEFT JOIN packages p ON c.package_id = p.id 
                                   WHERE c.id = ? OR c.customer_id = ?");
            $stmt->execute([$customerId, $customerId]);
            $customer = $stmt->fetch();
            
            if (!$customer) {
                jsonResponse(['success' => false, 'error' => 'Customer tidak ditemukan'], 404);
            }
            
            // Update status to isolir in database
            $stmt = $pdo->prepare("UPDATE customers SET status = 'isolir', isolir_date = CURDATE() WHERE id = ?");
            $stmt->execute([$customer['id']]);
            
            $mikrotikSuccess = false;
            $mikrotikMessage = '';
            
            // Try to isolir in MikroTik if pppoe_username exists
            // Try to isolir in MikroTik if pppoe_username exists
            $debugLog = [];
            $debugLog[] = "Starting isolir for customer: " . ($customer['pppoe_username'] ?? 'UNKNOWN');
            
            if (!empty($customer['pppoe_username'])) {
                require_once __DIR__ . '/MikroTikAPI.php';
                
                // Load MikroTik config from settings (mikrotik.json)
                $mikrotikJsonFile = __DIR__ . '/../data/mikrotik.json';
                $debugLog[] = "Config file: $mikrotikJsonFile";
                
                $mtConfig = null;
                
                if (file_exists($mikrotikJsonFile)) {
                    $jsonContent = file_get_contents($mikrotikJsonFile);
                    $debugLog[] = "JSON loaded (len: " . strlen($jsonContent) . ")";
                    
                    $mikrotikData = json_decode($jsonContent, true);
                    
                    // Priority: Enabled router -> First router
                    $selectedRouter = null;
                    
                    if (!empty($mikrotikData['routers'])) {
                        // 1. Try to find enabled router
                        foreach ($mikrotikData['routers'] as $r) {
                            $isEnabled = filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                            if ($isEnabled) {
                                $selectedRouter = $r;
                                $debugLog[] = "Selected ENABLED router: {$r['name']}";
                                break;
                            }
                        }
                        
                        // 2. Fallback to first router if none enabled explicitly
                        if (!$selectedRouter && !empty($mikrotikData['routers'][0])) {
                            $selectedRouter = $mikrotikData['routers'][0];
                            $debugLog[] = "Selected FIRST router (fallback): {$selectedRouter['name']}";
                        }
                        
                        if ($selectedRouter) {
                            $mtConfig = [
                                'host' => $selectedRouter['ip'],
                                'user' => $selectedRouter['username'],
                                'password' => $selectedRouter['password'],
                                'port' => $selectedRouter['port'] ?? 8728,
                                'isolir_profile' => $selectedRouter['isolir_profile'] ?? 'isolir'
                            ];
                        }
                    } else {
                        $debugLog[] = "No routers found in JSON";
                    }
                } else {
                    $debugLog[] = "Config file not found";
                }
                
                if ($mtConfig) {
                    $mtApi = new MikroTikAPI();
                    $debugLog[] = "Connecting to {$mtConfig['host']}:{$mtConfig['port']}...";
                    
                    if ($mtApi->connect($mtConfig['host'], $mtConfig['user'], $mtConfig['password'], $mtConfig['port'])) {
                        $debugLog[] = "Connected!";
                        
                        // PERBAIKAN: Prioritas profile isolir dari PAKET, fallback ke konfigurasi router
                        $isolirProfile = $customer['mikrotik_profile_isolir'] ?? $mtConfig['isolir_profile'] ?? 'isolir';
                        
                        $debugLog[] = "Changing profile for {$customer['pppoe_username']} to '$isolirProfile'";
                        if ($mtApi->isolirUser($customer['pppoe_username'], $isolirProfile)) {
                            $mikrotikSuccess = true;
                            $mikrotikMessage = "Profile changed to '$isolirProfile' and user disconnected";
                            $debugLog[] = "SUCCESS: Profile changed";
                        } else {
                            $mikrotikMessage = "Failed: " . $mtApi->getError();
                            $debugLog[] = "FAILED: " . $mtApi->getError();
                        }
                        $mtApi->disconnect();
                    } else {
                        $mikrotikMessage = "Failed to connect to MikroTik: " . $mtApi->getError();
                        $debugLog[] = "Connection failed: " . $mtApi->getError();
                    }
                } else {
                    $mikrotikMessage = "No MikroTik router configured";
                    $debugLog[] = "No active router config found";
                }
            } else {
                $debugLog[] = "PPPoE username empty";
            }
            
            // Save debug log
            file_put_contents(__DIR__ . '/mikrotik_debug.log', implode("\n", $debugLog) . "\n-------------------\n", FILE_APPEND);
            
            jsonResponse([
                'success' => true, 
                'message' => 'Customer berhasil di-isolir',
                'mikrotik_status' => $mikrotikSuccess ? 'success' : 'failed',
                'mikrotik_message' => $mikrotikMessage,
                'pppoe_username' => $customer['pppoe_username'] ?? null
            ]);
            
        // ========== UNISOLIR CUSTOMER ==========
        case 'unisolir':
            if (empty($input['customer_id'])) {
                jsonResponse(['success' => false, 'error' => 'Customer ID required'], 400);
            }
            
            $customerId = $input['customer_id'];
            
            // Get customer data (need pppoe_username and package for profile)
            $stmt = $pdo->prepare("SELECT c.*, p.mikrotik_profile 
                                   FROM customers c 
                                   LEFT JOIN packages p ON c.package_id = p.id 
                                   WHERE c.id = ? OR c.customer_id = ?");
            $stmt->execute([$customerId, $customerId]);
            $customer = $stmt->fetch();
            
            if (!$customer) {
                jsonResponse(['success' => false, 'error' => 'Customer tidak ditemukan'], 404);
            }
            
            // Update status to active in database
            $stmt = $pdo->prepare("UPDATE customers SET status = 'active', isolir_date = NULL WHERE id = ?");
            $stmt->execute([$customer['id']]);
            
            $mikrotikSuccess = false;
            $mikrotikMessage = '';
            
            // Try to unisolir in MikroTik if pppoe_username exists
            if (!empty($customer['pppoe_username'])) {
                require_once __DIR__ . '/MikroTikAPI.php';
                
                // Load MikroTik config from settings (mikrotik.json)
                $mikrotikJsonFile = __DIR__ . '/../data/mikrotik.json';
                $mtConfig = null;
                
                if (file_exists($mikrotikJsonFile)) {
                    $mikrotikData = json_decode(file_get_contents($mikrotikJsonFile), true);
                    
                    // Priority: Enabled router -> First router
                    $selectedRouter = null;
                    
                    if (!empty($mikrotikData['routers'])) {
                        // 1. Try to find enabled router
                        foreach ($mikrotikData['routers'] as $r) {
                            $isEnabled = filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                            if ($isEnabled) {
                                $selectedRouter = $r;
                                break;
                            }
                        }
                        
                        // 2. Fallback to first router if none enabled explicitly
                        if (!$selectedRouter && !empty($mikrotikData['routers'][0])) {
                            $selectedRouter = $mikrotikData['routers'][0];
                        }
                        
                        if ($selectedRouter) {
                            $mtConfig = [
                                'host' => $selectedRouter['ip'],
                                'user' => $selectedRouter['username'],
                                'password' => $selectedRouter['password'],
                                'port' => $selectedRouter['port'] ?? 8728,
                                'default_profile' => $selectedRouter['default_profile'] ?? 'default'
                            ];
                        }
                    }
                }
                
                if ($mtConfig) {
                    $mtApi = new MikroTikAPI();
                    if ($mtApi->connect($mtConfig['host'], $mtConfig['user'], $mtConfig['password'], $mtConfig['port'])) {
                        // Use package profile OR router default
                        $normalProfile = $customer['mikrotik_profile'] ?? $mtConfig['default_profile']; 
                        
                        if ($mtApi->unIsolirUser($customer['pppoe_username'], $normalProfile)) {
                            $mikrotikSuccess = true;
                            $mikrotikMessage = "Profile changed to '$normalProfile'";
                        } else {
                            $mikrotikMessage = "Failed: " . $mtApi->getError();
                        }
                        $mtApi->disconnect();
                    } else {
                        $mikrotikMessage = "Failed to connect to MikroTik";
                    }
                } else {
                    $mikrotikMessage = "No MikroTik router configured";
                }
            }
            
            jsonResponse([
                'success' => true, 
                'message' => 'Customer berhasil di-unisolir',
                'mikrotik_status' => $mikrotikSuccess ? 'success' : 'failed',
                'mikrotik_message' => $mikrotikMessage,
                'pppoe_username' => $customer['pppoe_username'] ?? null
            ]);
            
        // ========== RECORD PAYMENT + AUTO-UNISOLIR ==========
        case 'record_payment':
            if (empty($input['invoice_id']) || empty($input['amount'])) {
                jsonResponse(['success' => false, 'error' => 'Invoice ID and amount required'], 400);
            }
            
            $invoiceId = $input['invoice_id'];
            $amount = $input['amount'];
            $paymentMethod = $input['payment_method'] ?? 'cash';
            $paymentDate = $input['payment_date'] ?? date('Y-m-d');
            $referenceNo = $input['reference_no'] ?? null;
            
            // Get invoice details
            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch();
            
            if (!$invoice) {
                jsonResponse(['success' => false, 'error' => 'Invoice not found'], 404);
            }
            
            // Generate payment number
            $paymentNo = 'PAY-' . date('Ym') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            // Insert payment
            $stmt = $pdo->prepare("INSERT INTO payments (payment_no, invoice_id, customer_id, amount, payment_method, payment_date, reference_no, notes) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $paymentNo, 
                $invoiceId, 
                $invoice['customer_id'], 
                $amount, 
                $paymentMethod, 
                $paymentDate, 
                $referenceNo,
                'Payment recorded via admin panel'
            ]);
            
            // Update invoice status to paid
            $stmt = $pdo->prepare("UPDATE invoices SET status = 'paid', paid_at = NOW(), paid_amount = ? WHERE id = ?");
            $stmt->execute([$amount, $invoiceId]);
            
            // Check if customer has any unpaid invoices
            $stmt = $pdo->prepare("SELECT COUNT(*) as unpaid FROM invoices WHERE customer_id = ? AND status != 'paid'");
            $stmt->execute([$invoice['customer_id']]);
            $result = $stmt->fetch();
            
            $autoUnisolir = false;
            $unisolirMessage = '';
            
            // Auto-unisolir if no unpaid invoices
            if ($result['unpaid'] == 0) {
                // Get customer data with package info for MikroTik profile
                $stmt = $pdo->prepare("SELECT c.*, p.mikrotik_profile 
                                       FROM customers c 
                                       LEFT JOIN packages p ON c.package_id = p.id 
                                       WHERE c.id = ?");
                $stmt->execute([$invoice['customer_id']]);
                $customer = $stmt->fetch();
                
                if ($customer && $customer['status'] === 'isolir') {
                    // Update database first
                    $stmt = $pdo->prepare("UPDATE customers SET status = 'active', isolir_date = NULL WHERE id = ?");
                    $stmt->execute([$invoice['customer_id']]);
                    
                    $autoUnisolir = true;
                    $unisolirMessage = 'Customer auto-unisolir (all invoices paid)';
                    $mikrotikStatus = 'skipped';
                    $mikrotikMessage = '';
                    
                    // === PERBAIKAN: Ubah profile di MikroTik saat auto-unisolir ===
                    if (!empty($customer['pppoe_username'])) {
                        require_once __DIR__ . '/MikroTikAPI.php';
                        
                        // Load MikroTik config
                        $mikrotikJsonFile = __DIR__ . '/../data/mikrotik.json';
                        $mtConfig = null;
                        
                        if (file_exists($mikrotikJsonFile)) {
                            $mikrotikData = json_decode(file_get_contents($mikrotikJsonFile), true);
                            
                            $selectedRouter = null;
                            if (!empty($mikrotikData['routers'])) {
                                // Find enabled router
                                foreach ($mikrotikData['routers'] as $r) {
                                    $isEnabled = filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                                    if ($isEnabled) {
                                        $selectedRouter = $r;
                                        break;
                                    }
                                }
                                // Fallback to first router
                                if (!$selectedRouter && !empty($mikrotikData['routers'][0])) {
                                    $selectedRouter = $mikrotikData['routers'][0];
                                }
                                
                                if ($selectedRouter) {
                                    $mtConfig = [
                                        'host' => $selectedRouter['ip'],
                                        'user' => $selectedRouter['username'],
                                        'password' => $selectedRouter['password'],
                                        'port' => $selectedRouter['port'] ?? 8728,
                                        'default_profile' => $selectedRouter['default_profile'] ?? 'default'
                                    ];
                                }
                            }
                        }
                        
                        if ($mtConfig) {
                            $mtApi = new MikroTikAPI();
                            if ($mtApi->connect($mtConfig['host'], $mtConfig['user'], $mtConfig['password'], $mtConfig['port'])) {
                                // Use package profile or router default
                                $normalProfile = $customer['mikrotik_profile'] ?? $mtConfig['default_profile'];
                                
                                if ($mtApi->unIsolirUser($customer['pppoe_username'], $normalProfile)) {
                                    $mikrotikStatus = 'success';
                                    $mikrotikMessage = "Profile changed to '$normalProfile' and user disconnected";
                                } else {
                                    $mikrotikStatus = 'failed';
                                    $mikrotikMessage = "Failed: " . $mtApi->getError();
                                }
                                $mtApi->disconnect();
                            } else {
                                $mikrotikStatus = 'failed';
                                $mikrotikMessage = "Failed to connect to MikroTik";
                            }
                        } else {
                            $mikrotikStatus = 'no_config';
                            $mikrotikMessage = "No MikroTik router configured";
                        }
                        
                        $unisolirMessage .= " | MikroTik: $mikrotikStatus - $mikrotikMessage";
                    }
                }
            }
            
            jsonResponse([
                'success' => true,
                'payment_no' => $paymentNo,
                'invoice_status' => 'paid',
                'auto_unisolir' => $autoUnisolir,
                'unisolir_message' => $unisolirMessage,
                'mikrotik_status' => $mikrotikStatus ?? 'not_applicable',
                'mikrotik_message' => $mikrotikMessage ?? ''
            ]);
            
        // ========== UNISOLIR WITHOUT PAYMENT (Buka isolir tanpa bayar) ==========
        case 'unisolir_without_payment':
            if (empty($input['customer_id'])) {
                jsonResponse(['success' => false, 'error' => 'Customer ID required'], 400);
            }
            
            $customerId = $input['customer_id'];
            $notes = $input['notes'] ?? 'Unisolir without payment - special case';
            
            // Get customer data
            $stmt = $pdo->prepare("SELECT c.*, p.mikrotik_profile 
                                   FROM customers c 
                                   LEFT JOIN packages p ON c.package_id = p.id 
                                   WHERE c.id = ? OR c.customer_id = ?");
            $stmt->execute([$customerId, $customerId]);
            $customer = $stmt->fetch();
            
            if (!$customer) {
                jsonResponse(['success' => false, 'error' => 'Customer tidak ditemukan'], 404);
            }
            
            // Update status to active (tapi invoice tetap unpaid!)
            $stmt = $pdo->prepare("UPDATE customers SET status = 'active', isolir_date = NULL WHERE id = ?");
            $stmt->execute([$customer['id']]);
            
            // Log note (optional: save to customer notes or separate table)
            // For now, just return in response
            
            $mikrotikSuccess = false;
            $mikrotikMessage = '';
            
            // Unisolir di MikroTik
            if (!empty($customer['pppoe_username'])) {
                require_once __DIR__ . '/MikroTikAPI.php';
                
                $mikrotikJsonFile = __DIR__ . '/../data/mikrotik.json';
                $mtConfig = null;
                
                if (file_exists($mikrotikJsonFile)) {
                    $mikrotikData = json_decode(file_get_contents($mikrotikJsonFile), true);
                    
                    // Priority: Enabled router -> First router
                    $selectedRouter = null;
                    
                    if (!empty($mikrotikData['routers'])) {
                        // 1. Try to find enabled router
                        foreach ($mikrotikData['routers'] as $r) {
                            $isEnabled = filter_var($r['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                            if ($isEnabled) {
                                $selectedRouter = $r;
                                break;
                            }
                        }
                        
                        // 2. Fallback to first router if none enabled explicitly
                        if (!$selectedRouter && !empty($mikrotikData['routers'][0])) {
                            $selectedRouter = $mikrotikData['routers'][0];
                        }
                        
                        if ($selectedRouter) {
                            $mtConfig = [
                                'host' => $selectedRouter['ip'],
                                'user' => $selectedRouter['username'],
                                'password' => $selectedRouter['password'],
                                'port' => $selectedRouter['port'] ?? 8728,
                                'default_profile' => $selectedRouter['default_profile'] ?? 'default'
                            ];
                        }
                    }
                }
                
                if ($mtConfig) {
                    $mtApi = new MikroTikAPI();
                    if ($mtApi->connect($mtConfig['host'], $mtConfig['user'], $mtConfig['password'], $mtConfig['port'])) {
                        $normalProfile = $customer['mikrotik_profile'] ?? $mtConfig['default_profile'];
                        
                        if ($mtApi->unIsolirUser($customer['pppoe_username'], $normalProfile)) {
                            $mikrotikSuccess = true;
                            $mikrotikMessage = "Profile changed to '{$normalProfile}' and user disconnected";
                        } else {
                            $mikrotikMessage = "Failed: " . $mtApi->getError();
                        }
                        $mtApi->disconnect();
                    } else {
                        $mikrotikMessage = "Failed to connect to MikroTik";
                    }
                } else {
                    $mikrotikMessage = "No MikroTik router configured";
                }
            }
            
            jsonResponse([
                'success' => true, 
                'message' => 'Customer di-unisolir tanpa pembayaran',
                'note' => 'Invoice tetap unpaid - akan digabung bulan berikutnya',
                'mikrotik_status' => $mikrotikSuccess ? 'success' : 'failed',
                'mikrotik_message' => $mikrotikMessage,
                'pppoe_username' => $customer['pppoe_username'] ?? null
            ]);
    }
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
