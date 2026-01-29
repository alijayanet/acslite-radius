<?php
/**
 * Telegram Webhook Handler for ACS-Lite Admin Bot
 * 
 * Features:
 * - MikroTik: PPPoE management, Hotspot voucher generation
 * - Billing: Customer, Invoice, Payment management
 * - Isolir/Unisolir customers
 * 
 * Setup:
 * 1. Set your BOT_TOKEN in database (telegram_config) or settings file
 * 2. Set webhook: https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://yourdomain.com/api/telegram_webhook.php
 * 3. Add authorized admin chat IDs in database (telegram_admins) or settings file
 */

header('Content-Type: application/json');

// ========================================
// CONFIGURATION - Priority: Database > JSON File
// ========================================

// Initialize global config
$BOT_TOKEN = '';
$ADMIN_CHAT_IDS = [];

// Try to load from database first
function loadTelegramConfigFromDB() {
    $pdo = getDB();
    if (!$pdo) return null;
    
    try {
        // Get bot token
        $stmt = $pdo->query("SELECT bot_token FROM telegram_config WHERE is_active = 1 LIMIT 1");
        $config = $stmt->fetch();
        
        if (!$config) return null;
        
        // Get admin chat IDs
        $stmt = $pdo->query("SELECT chat_id FROM telegram_admins WHERE is_active = 1");
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return [
            'bot_token' => $config['bot_token'],
            'admin_chat_ids' => $admins
        ];
    } catch (Exception $e) {
        return null;
    }
}

// Fallback to JSON config file
function loadTelegramConfigFromFile() {
    $configFile = __DIR__ . '/../data/admin.json';
    $config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
    
    $botToken = $config['telegram']['bot_token'] ?? '';
    $adminIds = $config['telegram']['admin_chat_ids'] ?? [];
    
    // If no config from admin.json, try settings.json
    if (empty($botToken)) {
        $settingsFile = __DIR__ . '/../data/settings.json';
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true);
            $botToken = $settings['telegram']['bot_token'] ?? '';
            $adminIds = [$settings['telegram']['chat_id'] ?? ''];
        }
    }
    
    return [
        'bot_token' => $botToken,
        'admin_chat_ids' => array_filter($adminIds)
    ];
}

// ========================================
// DATABASE CONNECTION
// ========================================
function getDB() {
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
        return new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        return null;
    }
}

// ========================================
// LOAD CONFIGURATION
// ========================================
// Try database first, fallback to file
$dbConfig = loadTelegramConfigFromDB();
if ($dbConfig && !empty($dbConfig['bot_token'])) {
    $BOT_TOKEN = $dbConfig['bot_token'];
    $ADMIN_CHAT_IDS = $dbConfig['admin_chat_ids'];
} else {
    $fileConfig = loadTelegramConfigFromFile();
    $BOT_TOKEN = $fileConfig['bot_token'];
    $ADMIN_CHAT_IDS = $fileConfig['admin_chat_ids'];
}

// Verify bot token exists
if (empty($BOT_TOKEN)) {
    die(json_encode(['error' => 'Bot token not configured. Set in database (telegram_config) or admin.json']));
}

// ========================================
// TELEGRAM API FUNCTIONS
// ========================================
function sendMessage($chatId, $text, $keyboard = null, $parseMode = 'HTML') {
    global $BOT_TOKEN;
    
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true);
}

function editMessage($chatId, $messageId, $text, $keyboard = null) {
    global $BOT_TOKEN;
    
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/editMessageText");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true);
}

function answerCallback($callbackId, $text = '') {
    global $BOT_TOKEN;
    
    $data = [
        'callback_query_id' => $callbackId,
        'text' => $text
    ];
    
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/answerCallbackQuery");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// ========================================
// KEYBOARD BUILDERS
// ========================================
function mainMenuKeyboard() {
    return [
        'inline_keyboard' => [
            [
                ['text' => '👥 Pelanggan', 'callback_data' => 'menu_customers'],
                ['text' => '📄 Invoice', 'callback_data' => 'menu_invoices']
            ],
            [
                ['text' => '💰 Pembayaran', 'callback_data' => 'menu_payments'],
                ['text' => '📦 Paket', 'callback_data' => 'menu_packages']
            ],
            [
                ['text' => '🔌 MikroTik PPPoE', 'callback_data' => 'menu_pppoe'],
                ['text' => '📡 Hotspot', 'callback_data' => 'menu_hotspot']
            ],
            [
                ['text' => '📊 Dashboard', 'callback_data' => 'dashboard'],
                ['text' => '❓ Help', 'callback_data' => 'help']
            ]
        ]
    ];
}

function customerMenuKeyboard() {
    return [
        'inline_keyboard' => [
            [
                ['text' => '📋 List Pelanggan', 'callback_data' => 'cust_list'],
                ['text' => '🔍 Cari Pelanggan', 'callback_data' => 'cust_search']
            ],
            [
                ['text' => '🔴 List Isolir', 'callback_data' => 'cust_isolir'],
                ['text' => '🟢 List Aktif', 'callback_data' => 'cust_active']
            ],
            [
                ['text' => '⬅️ Kembali', 'callback_data' => 'main_menu']
            ]
        ]
    ];
}

function invoiceMenuKeyboard() {
    return [
        'inline_keyboard' => [
            [
                ['text' => '📋 List Invoice', 'callback_data' => 'inv_list'],
                ['text' => '⏰ Jatuh Tempo', 'callback_data' => 'inv_overdue']
            ],
            [
                ['text' => '✅ Lunas', 'callback_data' => 'inv_paid'],
                ['text' => '⏳ Belum Lunas', 'callback_data' => 'inv_unpaid']
            ],
            [
                ['text' => '⬅️ Kembali', 'callback_data' => 'main_menu']
            ]
        ]
    ];
}

function pppoeMenuKeyboard() {
    return [
        'inline_keyboard' => [
            [
                ['text' => '👥 List PPPoE Users', 'callback_data' => 'pppoe_list'],
                ['text' => '🟢 Active Sessions', 'callback_data' => 'pppoe_active']
            ],
            [
                ['text' => '➕ Tambah User', 'callback_data' => 'pppoe_add'],
                ['text' => '🔄 Disconnect All', 'callback_data' => 'pppoe_disconnect']
            ],
            [
                ['text' => '⬅️ Kembali', 'callback_data' => 'main_menu']
            ]
        ]
    ];
}

function hotspotMenuKeyboard() {
    return [
        'inline_keyboard' => [
            [
                ['text' => '🎫 Generate Voucher', 'callback_data' => 'hs_generate'],
                ['text' => '👥 Active Users', 'callback_data' => 'hs_active']
            ],
            [
                ['text' => '📋 List Profiles', 'callback_data' => 'hs_profiles'],
                ['text' => '📊 Statistics', 'callback_data' => 'hs_stats']
            ],
            [
                ['text' => '⬅️ Kembali', 'callback_data' => 'main_menu']
            ]
        ]
    ];
}

function backToMainKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '⬅️ Menu Utama', 'callback_data' => 'main_menu']]
        ]
    ];
}

// ========================================
// COMMAND HANDLERS
// ========================================
function handleCommand($chatId, $command, $args = '') {
    $pdo = getDB();
    
    switch ($command) {
        case '/start':
        case '/menu':
            $text = "🏠 <b>ACS-Lite Admin Bot</b>\n\n";
            $text .= "Selamat datang! Pilih menu di bawah:\n\n";
            $text .= "📱 <i>Atau ketik perintah langsung:</i>\n";
            $text .= "/cari [nama] - Cari pelanggan\n";
            $text .= "/tagihan [kode] - Cek tagihan\n";
            $text .= "/isolir [kode] - Isolir pelanggan\n";
            $text .= "/unisolir [kode] - Buka isolir\n";
            $text .= "/voucher [profile] [jumlah] - Generate voucher\n";
            sendMessage($chatId, $text, mainMenuKeyboard());
            break;
            
        case '/help':
            sendHelpMessage($chatId);
            break;
            
        case '/dashboard':
            sendDashboard($chatId, $pdo);
            break;
            
        case '/cari':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /cari [nama/kode/pppoe]\n\nContoh: /cari Ahmad", backToMainKeyboard());
            } else {
                searchCustomer($chatId, $pdo, $args);
            }
            break;
            
        case '/tagihan':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /tagihan [kode_pelanggan]\n\nContoh: /tagihan CST001", backToMainKeyboard());
            } else {
                checkTagihan($chatId, $pdo, $args);
            }
            break;
            
        case '/isolir':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /isolir [kode_pelanggan]\n\nContoh: /isolir CST001", backToMainKeyboard());
            } else {
                isolirCustomer($chatId, $pdo, $args);
            }
            break;
            
        case '/unisolir':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /unisolir [kode_pelanggan]\n\nContoh: /unisolir CST001", backToMainKeyboard());
            } else {
                unisolirCustomer($chatId, $pdo, $args);
            }
            break;
            
        case '/bayar':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /bayar [invoice_no] [jumlah]\n\nContoh: /bayar INV-202412-001 150000", backToMainKeyboard());
            } else {
                recordPayment($chatId, $pdo, $args);
            }
            break;
            
        case '/voucher':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /voucher [profile] [jumlah]\n\nContoh: /voucher 10k 5", backToMainKeyboard());
            } else {
                generateVoucher($chatId, $args);
            }
            break;
            
        // Voucher dengan username = password (random 5 digit)
        case '/vcr':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /vcr [user] [profile]\n\nContoh: /vcr 12345 10k\n\n💡 User dan password sama", backToMainKeyboard());
            } else {
                createVoucherManual($chatId, $args, true);
            }
            break;
            
        // Member dengan username dan password berbeda
        case '/member':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /member [user] [password] [profile]\n\nContoh: /member ahmad secret123 10k\n\n💡 User dan password berbeda", backToMainKeyboard());
            } else {
                createMemberManual($chatId, $args);
            }
            break;
            
        case '/pppoe':
            listPPPoEUsers($chatId);
            break;
            
        case '/addpppoe':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /addpppoe [username] [password] [profile]\n\nContoh: /addpppoe user123 pass123 10Mbps", backToMainKeyboard());
            } else {
                addPPPoEUser($chatId, $args);
            }
            break;
            
        case '/delpppoe':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /delpppoe [username]\n\nContoh: /delpppoe user123", backToMainKeyboard());
            } else {
                deletePPPoEUser($chatId, $args);
            }
            break;
            
        case '/editpppoe':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /editpppoe [username] [profile]\n\nContoh: /editpppoe user123 20Mbps\n\n💡 Profile akan diubah dan session aktif di-disconnect", backToMainKeyboard());
            } else {
                editPPPoEUser($chatId, $args);
            }
            break;
            
        case '/offpppoe':
        case '/offlinepppoe':
            listOfflinePPPoE($chatId);
            break;
            
        // MikroTik Commands
        case '/ping':
            if (empty($args)) {
                sendMessage($chatId, "❌ Format: /ping [host]\n\nContoh: /ping 8.8.8.8", backToMainKeyboard());
            } else {
                pingHost($chatId, $args);
            }
            break;
            
        case '/resource':
            showResource($chatId);
            break;
            
        case '/interface':
            showInterfaces($chatId);
            break;
            
        case '/log':
            showLog($chatId, $args);
            break;
            
        case '/traffic':
            showTraffic($chatId, $args);
            break;
            
        case '/dhcp':
            showDHCPLeases($chatId);
            break;
            
        default:
            sendMessage($chatId, "❓ Perintah tidak dikenali.\n\nKetik /help untuk melihat daftar perintah.", mainMenuKeyboard());
    }
}

// ========================================
// CALLBACK HANDLERS
// ========================================
function handleCallback($chatId, $messageId, $callbackId, $data) {
    $pdo = getDB();
    
    // Parse callback data
    $parts = explode('_', $data, 2);
    $action = $parts[0] ?? '';
    $param = $parts[1] ?? '';
    
    answerCallback($callbackId);
    
    switch ($data) {
        case 'main_menu':
            $text = "🏠 <b>Menu Utama</b>\n\nPilih menu:";
            editMessage($chatId, $messageId, $text, mainMenuKeyboard());
            break;
            
        case 'menu_customers':
            $text = "👥 <b>Menu Pelanggan</b>\n\nPilih aksi:";
            editMessage($chatId, $messageId, $text, customerMenuKeyboard());
            break;
            
        case 'menu_invoices':
            $text = "📄 <b>Menu Invoice</b>\n\nPilih aksi:";
            editMessage($chatId, $messageId, $text, invoiceMenuKeyboard());
            break;
            
        case 'menu_pppoe':
            $text = "🔌 <b>Menu MikroTik PPPoE</b>\n\nPilih aksi:";
            editMessage($chatId, $messageId, $text, pppoeMenuKeyboard());
            break;
            
        case 'menu_hotspot':
            $text = "📡 <b>Menu Hotspot</b>\n\nPilih aksi:";
            editMessage($chatId, $messageId, $text, hotspotMenuKeyboard());
            break;
            
        case 'dashboard':
            sendDashboard($chatId, $pdo);
            break;
            
        case 'help':
            sendHelpMessage($chatId);
            break;
            
        // Customer callbacks
        case 'cust_list':
            listCustomers($chatId, $pdo);
            break;
            
        case 'cust_isolir':
            listCustomersByStatus($chatId, $pdo, 'isolir');
            break;
            
        case 'cust_active':
            listCustomersByStatus($chatId, $pdo, 'active');
            break;
            
        case 'cust_search':
            sendMessage($chatId, "🔍 <b>Cari Pelanggan</b>\n\nKetik: /cari [nama/kode/pppoe]\n\nContoh:\n/cari Ahmad\n/cari CST001\n/cari pppoe_user", backToMainKeyboard());
            break;
            
        // Invoice callbacks
        case 'inv_list':
            listInvoices($chatId, $pdo);
            break;
            
        case 'inv_overdue':
            listInvoicesByStatus($chatId, $pdo, 'overdue');
            break;
            
        case 'inv_paid':
            listInvoicesByStatus($chatId, $pdo, 'paid');
            break;
            
        case 'inv_unpaid':
            listInvoicesByStatus($chatId, $pdo, 'sent');
            break;
            
        // PPPoE callbacks
        case 'pppoe_list':
            listPPPoEUsers($chatId);
            break;
            
        case 'pppoe_active':
            listActivePPPoE($chatId);
            break;
            
        case 'pppoe_add':
            sendMessage($chatId, "➕ <b>Tambah PPPoE User</b>\n\nKetik:\n/addpppoe [username] [password] [profile]\n\nContoh:\n/addpppoe user123 pass123 10Mbps", backToMainKeyboard());
            break;
            
        // Hotspot callbacks
        case 'hs_generate':
            sendVoucherProfileSelection($chatId);
            break;
            
        case 'hs_active':
            listActiveHotspotUsers($chatId);
            break;
            
        case 'hs_profiles':
            listHotspotProfiles($chatId);
            break;
            
        default:
            // Handle dynamic callbacks like isolir_123, unisolir_123, etc.
            if (strpos($data, 'isolir_') === 0) {
                $customerId = substr($data, 7);
                isolirCustomerById($chatId, $pdo, $customerId);
            } elseif (strpos($data, 'unisolir_') === 0) {
                $customerId = substr($data, 9);
                unisolirCustomerById($chatId, $pdo, $customerId);
            } elseif (strpos($data, 'detail_') === 0) {
                $customerId = substr($data, 7);
                showCustomerDetail($chatId, $pdo, $customerId);
            } elseif (strpos($data, 'genvoucher_') === 0) {
                $profile = substr($data, 11);
                generateVoucherForProfile($chatId, $profile, 1);
            }
    }
}

// ========================================
// FEATURE FUNCTIONS
// ========================================
function sendHelpMessage($chatId) {
    $text = "❓ <b>Daftar Perintah</b>\n\n";
    
    $text .= "📱 <b>PELANGGAN:</b>\n";
    $text .= "/cari [keyword] - Cari pelanggan\n";
    $text .= "/tagihan [kode] - Cek tagihan\n";
    $text .= "/isolir [kode] - Isolir pelanggan\n";
    $text .= "/unisolir [kode] - Buka isolir\n\n";
    
    $text .= "💰 <b>PEMBAYARAN:</b>\n";
    $text .= "/bayar [inv_no] [jumlah] - Catat bayar\n\n";
    
    $text .= "🔌 <b>MIKROTIK PPPoE:</b>\n";
    $text .= "/pppoe - List PPPoE users\n";
    $text .= "/offpppoe - List user offline\n";
    $text .= "/addpppoe [user] [pass] [profile]\n";
    $text .= "/editpppoe [user] [profile] - Ubah profile\n";
    $text .= "/delpppoe [username]\n\n";
    
    $text .= "📡 <b>HOTSPOT VOUCHER:</b>\n";
    $text .= "/voucher [profile] [jumlah] - Random 5 digit\n";
    $text .= "/vcr [user] [profile] - User=Pass manual\n";
    $text .= "/member [user] [pass] [profile]\n\n";
    
    $text .= "🛠️ <b>MIKROTIK TOOLS:</b>\n";
    $text .= "/resource - Cek resource router\n";
    $text .= "/ping [host] - Ping host\n";
    $text .= "/interface - List interface\n";
    $text .= "/log - Lihat log terbaru\n";
    $text .= "/traffic [iface] - Traffic interface\n";
    $text .= "/dhcp - DHCP leases\n\n";
    
    $text .= "📊 <b>LAINNYA:</b>\n";
    $text .= "/dashboard - Statistik\n";
    $text .= "/menu - Menu utama\n";
    
    sendMessage($chatId, $text, mainMenuKeyboard());
}

function sendDashboard($chatId, $pdo) {
    if (!$pdo) {
        sendMessage($chatId, "❌ Database tidak terhubung", backToMainKeyboard());
        return;
    }
    
    // Get stats
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM customers");
    $stats['total'] = $stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as active FROM customers WHERE status = 'active'");
    $stats['active'] = $stmt->fetch()['active'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as isolir FROM customers WHERE status = 'isolir'");
    $stats['isolir'] = $stmt->fetch()['isolir'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as overdue FROM invoices WHERE status = 'overdue'");
    $stats['overdue'] = $stmt->fetch()['overdue'];
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as revenue FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())");
    $stmt->execute();
    $stats['revenue'] = $stmt->fetch()['revenue'];
    
    $text = "📊 <b>Dashboard</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $text .= "👥 <b>Pelanggan:</b>\n";
    $text .= "   Total: {$stats['total']}\n";
    $text .= "   🟢 Aktif: {$stats['active']}\n";
    $text .= "   🔴 Isolir: {$stats['isolir']}\n\n";
    $text .= "📄 <b>Invoice:</b>\n";
    $text .= "   ⚠️ Jatuh Tempo: {$stats['overdue']}\n\n";
    $text .= "💰 <b>Pendapatan Bulan Ini:</b>\n";
    $text .= "   Rp " . number_format($stats['revenue'], 0, ',', '.') . "\n";
    $text .= "\n🕐 Update: " . date('d/m/Y H:i');
    
    sendMessage($chatId, $text, mainMenuKeyboard());
}

function listCustomers($chatId, $pdo, $limit = 10) {
    $stmt = $pdo->prepare("SELECT id, customer_id, name, status, pppoe_username FROM customers ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    $customers = $stmt->fetchAll();
    
    if (empty($customers)) {
        sendMessage($chatId, "📋 Tidak ada pelanggan", backToMainKeyboard());
        return;
    }
    
    $text = "👥 <b>Daftar Pelanggan</b> (10 Terbaru)\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $keyboard = ['inline_keyboard' => []];
    
    foreach ($customers as $c) {
        $statusIcon = $c['status'] === 'active' ? '🟢' : '🔴';
        $text .= "{$statusIcon} <b>{$c['customer_id']}</b> - {$c['name']}\n";
        $text .= "    PPPoE: {$c['pppoe_username']}\n\n";
        
        $keyboard['inline_keyboard'][] = [
            ['text' => "📋 {$c['customer_id']}", 'callback_data' => "detail_{$c['id']}"]
        ];
    }
    
    $keyboard['inline_keyboard'][] = [['text' => '⬅️ Kembali', 'callback_data' => 'menu_customers']];
    
    sendMessage($chatId, $text, $keyboard);
}

function listCustomersByStatus($chatId, $pdo, $status) {
    $stmt = $pdo->prepare("SELECT id, customer_id, name, pppoe_username FROM customers WHERE status = ? ORDER BY name LIMIT 20");
    $stmt->execute([$status]);
    $customers = $stmt->fetchAll();
    
    $statusLabel = $status === 'active' ? '🟢 Aktif' : '🔴 Isolir';
    
    if (empty($customers)) {
        sendMessage($chatId, "📋 Tidak ada pelanggan $statusLabel", backToMainKeyboard());
        return;
    }
    
    $text = "👥 <b>Pelanggan $statusLabel</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $keyboard = ['inline_keyboard' => []];
    
    foreach ($customers as $c) {
        $text .= "<b>{$c['customer_id']}</b> - {$c['name']}\n";
        
        if ($status === 'isolir') {
            $keyboard['inline_keyboard'][] = [
                ['text' => "🟢 Unisolir {$c['customer_id']}", 'callback_data' => "unisolir_{$c['id']}"]
            ];
        } else {
            $keyboard['inline_keyboard'][] = [
                ['text' => "🔴 Isolir {$c['customer_id']}", 'callback_data' => "isolir_{$c['id']}"]
            ];
        }
    }
    
    $keyboard['inline_keyboard'][] = [['text' => '⬅️ Kembali', 'callback_data' => 'menu_customers']];
    
    sendMessage($chatId, $text, $keyboard);
}

function searchCustomer($chatId, $pdo, $keyword) {
    $search = "%{$keyword}%";
    $stmt = $pdo->prepare("SELECT id, customer_id, name, status, pppoe_username, phone FROM customers WHERE name LIKE ? OR customer_id LIKE ? OR pppoe_username LIKE ? LIMIT 10");
    $stmt->execute([$search, $search, $search]);
    $customers = $stmt->fetchAll();
    
    if (empty($customers)) {
        sendMessage($chatId, "🔍 Tidak ditemukan pelanggan dengan kata kunci: <b>$keyword</b>", backToMainKeyboard());
        return;
    }
    
    $text = "🔍 <b>Hasil Pencarian:</b> $keyword\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $keyboard = ['inline_keyboard' => []];
    
    foreach ($customers as $c) {
        $statusIcon = $c['status'] === 'active' ? '🟢' : '🔴';
        $text .= "{$statusIcon} <b>{$c['customer_id']}</b> - {$c['name']}\n";
        $text .= "    📞 {$c['phone']} | PPPoE: {$c['pppoe_username']}\n\n";
        
        $keyboard['inline_keyboard'][] = [
            ['text' => "📋 Detail {$c['customer_id']}", 'callback_data' => "detail_{$c['id']}"]
        ];
    }
    
    $keyboard['inline_keyboard'][] = [['text' => '⬅️ Menu Utama', 'callback_data' => 'main_menu']];
    
    sendMessage($chatId, $text, $keyboard);
}

function showCustomerDetail($chatId, $pdo, $customerId) {
    $stmt = $pdo->prepare("SELECT c.*, p.name as package_name, p.price as package_price 
                           FROM customers c 
                           LEFT JOIN packages p ON c.package_id = p.id 
                           WHERE c.id = ?");
    $stmt->execute([$customerId]);
    $c = $stmt->fetch();
    
    if (!$c) {
        sendMessage($chatId, "❌ Pelanggan tidak ditemukan", backToMainKeyboard());
        return;
    }
    
    $statusIcon = $c['status'] === 'active' ? '🟢' : '🔴';
    
    $text = "👤 <b>Detail Pelanggan</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $text .= "<b>Kode:</b> {$c['customer_id']}\n";
    $text .= "<b>Nama:</b> {$c['name']}\n";
    $text .= "<b>Status:</b> {$statusIcon} {$c['status']}\n";
    $text .= "<b>Telepon:</b> {$c['phone']}\n";
    $text .= "<b>PPPoE:</b> {$c['pppoe_username']}\n";
    $text .= "<b>Paket:</b> {$c['package_name']}\n";
    $text .= "<b>Tagihan:</b> Rp " . number_format($c['monthly_fee'], 0, ',', '.') . "/bln\n";
    
    if ($c['isolir_date']) {
        $text .= "<b>Tgl Isolir:</b> {$c['isolir_date']}\n";
    }
    
    // Get unpaid invoices
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(total) as total FROM invoices WHERE customer_id = ? AND status != 'paid'");
    $stmt->execute([$customerId]);
    $inv = $stmt->fetch();
    
    if ($inv['count'] > 0) {
        $text .= "\n⚠️ <b>Tagihan Belum Lunas:</b> {$inv['count']} invoice\n";
        $text .= "💰 Total: Rp " . number_format($inv['total'], 0, ',', '.') . "\n";
    }
    
    $keyboard = ['inline_keyboard' => []];
    
    if ($c['status'] === 'active') {
        $keyboard['inline_keyboard'][] = [
            ['text' => '🔴 Isolir', 'callback_data' => "isolir_{$c['id']}"]
        ];
    } else {
        $keyboard['inline_keyboard'][] = [
            ['text' => '🟢 Unisolir', 'callback_data' => "unisolir_{$c['id']}"]
        ];
    }
    
    $keyboard['inline_keyboard'][] = [['text' => '⬅️ Menu Utama', 'callback_data' => 'main_menu']];
    
    sendMessage($chatId, $text, $keyboard);
}

function checkTagihan($chatId, $pdo, $customerCode) {
    $stmt = $pdo->prepare("SELECT c.*, 
                           (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND status != 'paid') as unpaid_count,
                           (SELECT SUM(total) FROM invoices WHERE customer_id = c.id AND status != 'paid') as unpaid_total
                           FROM customers c WHERE c.customer_id = ?");
    $stmt->execute([$customerCode]);
    $c = $stmt->fetch();
    
    if (!$c) {
        sendMessage($chatId, "❌ Pelanggan dengan kode <b>$customerCode</b> tidak ditemukan", backToMainKeyboard());
        return;
    }
    
    $text = "💳 <b>Info Tagihan</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $text .= "<b>Pelanggan:</b> {$c['name']}\n";
    $text .= "<b>Kode:</b> {$c['customer_id']}\n\n";
    
    if ($c['unpaid_count'] > 0) {
        $text .= "⚠️ <b>Tagihan Belum Lunas:</b>\n";
        $text .= "   Jumlah Invoice: {$c['unpaid_count']}\n";
        $text .= "   Total: <b>Rp " . number_format($c['unpaid_total'], 0, ',', '.') . "</b>\n\n";
        
        // Get invoice details
        $stmt = $pdo->prepare("SELECT invoice_no, total, due_date, status FROM invoices WHERE customer_id = ? AND status != 'paid' ORDER BY due_date LIMIT 5");
        $stmt->execute([$c['id']]);
        $invoices = $stmt->fetchAll();
        
        foreach ($invoices as $inv) {
            $statusIcon = $inv['status'] === 'overdue' ? '🔴' : '⏳';
            $text .= "{$statusIcon} {$inv['invoice_no']}\n";
            $text .= "   Rp " . number_format($inv['total'], 0, ',', '.') . " - Jatuh tempo: {$inv['due_date']}\n\n";
        }
    } else {
        $text .= "✅ <b>Tidak ada tagihan yang belum lunas</b>\n";
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function isolirCustomer($chatId, $pdo, $customerCode) {
    $stmt = $pdo->prepare("SELECT id, name, status FROM customers WHERE customer_id = ?");
    $stmt->execute([$customerCode]);
    $c = $stmt->fetch();
    
    if (!$c) {
        sendMessage($chatId, "❌ Pelanggan tidak ditemukan", backToMainKeyboard());
        return;
    }
    
    if ($c['status'] === 'isolir') {
        sendMessage($chatId, "⚠️ Pelanggan <b>{$c['name']}</b> sudah dalam status ISOLIR", backToMainKeyboard());
        return;
    }
    
    isolirCustomerById($chatId, $pdo, $c['id']);
}

function isolirCustomerById($chatId, $pdo, $customerId) {
    // Call billing API
    $response = callBillingAPI('isolir', ['customer_id' => $customerId]);
    
    if ($response['success']) {
        $text = "🔴 <b>Isolir Berhasil!</b>\n\n";
        $text .= "📌 PPPoE: {$response['pppoe_username']}\n";
        $text .= "📡 MikroTik: {$response['mikrotik_status']}\n";
        if (!empty($response['mikrotik_message'])) {
            $text .= "💬 {$response['mikrotik_message']}\n";
        }
    } else {
        $text = "❌ <b>Gagal Isolir</b>\n\n";
        $text .= "Error: {$response['error']}";
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function unisolirCustomer($chatId, $pdo, $customerCode) {
    $stmt = $pdo->prepare("SELECT id, name, status FROM customers WHERE customer_id = ?");
    $stmt->execute([$customerCode]);
    $c = $stmt->fetch();
    
    if (!$c) {
        sendMessage($chatId, "❌ Pelanggan tidak ditemukan", backToMainKeyboard());
        return;
    }
    
    if ($c['status'] === 'active') {
        sendMessage($chatId, "⚠️ Pelanggan <b>{$c['name']}</b> sudah AKTIF", backToMainKeyboard());
        return;
    }
    
    unisolirCustomerById($chatId, $pdo, $c['id']);
}

function unisolirCustomerById($chatId, $pdo, $customerId) {
    // Call billing API
    $response = callBillingAPI('unisolir', ['customer_id' => $customerId]);
    
    if ($response['success']) {
        $text = "🟢 <b>Unisolir Berhasil!</b>\n\n";
        $text .= "📌 Customer ID: {$customerId}\n";
        $text .= "📡 MikroTik: {$response['mikrotik_status']}\n";
        if (!empty($response['mikrotik_message'])) {
            $text .= "💬 {$response['mikrotik_message']}\n";
        }
    } else {
        $text = "❌ <b>Gagal Unisolir</b>\n\n";
        $text .= "Error: {$response['error']}";
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function recordPayment($chatId, $pdo, $args) {
    $parts = explode(' ', $args);
    if (count($parts) < 2) {
        sendMessage($chatId, "❌ Format: /bayar [invoice_no] [jumlah]\n\nContoh: /bayar INV-202412-001 150000", backToMainKeyboard());
        return;
    }
    
    $invoiceNo = $parts[0];
    $amount = intval($parts[1]);
    
    // Get invoice
    $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.invoice_no = ?");
    $stmt->execute([$invoiceNo]);
    $inv = $stmt->fetch();
    
    if (!$inv) {
        sendMessage($chatId, "❌ Invoice <b>$invoiceNo</b> tidak ditemukan", backToMainKeyboard());
        return;
    }
    
    // Call billing API
    $response = callBillingAPI('pay_invoice', [
        'invoice_id' => $inv['id'],
        'amount' => $amount,
        'payment_method' => 'cash'
    ]);
    
    if ($response['success']) {
        $text = "✅ <b>Pembayaran Berhasil!</b>\n\n";
        $text .= "📄 Invoice: {$invoiceNo}\n";
        $text .= "👤 Pelanggan: {$inv['customer_name']}\n";
        $text .= "💰 Jumlah: Rp " . number_format($amount, 0, ',', '.') . "\n";
        $text .= "📝 No. Pembayaran: {$response['payment_no']}\n";
        
        if ($response['auto_unisolir']) {
            $text .= "\n🟢 <b>Pelanggan auto-unisolir!</b>\n";
            $text .= "📡 MikroTik: {$response['mikrotik_status']}\n";
        }
    } else {
        $text = "❌ <b>Gagal Record Pembayaran</b>\n\n";
        $text .= "Error: {$response['error']}";
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function listInvoices($chatId, $pdo) {
    $stmt = $pdo->query("SELECT i.invoice_no, i.total, i.status, c.name as customer_name 
                         FROM invoices i 
                         JOIN customers c ON i.customer_id = c.id 
                         ORDER BY i.created_at DESC LIMIT 10");
    $invoices = $stmt->fetchAll();
    
    if (empty($invoices)) {
        sendMessage($chatId, "📄 Tidak ada invoice", backToMainKeyboard());
        return;
    }
    
    $text = "📄 <b>Invoice Terbaru</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    foreach ($invoices as $inv) {
        // PHP 7.4 compatible
        $statusIcon = '⏳';
        if ($inv['status'] === 'paid') $statusIcon = '✅';
        elseif ($inv['status'] === 'overdue') $statusIcon = '🔴';
        $text .= "{$statusIcon} <b>{$inv['invoice_no']}</b>\n";
        $text .= "   {$inv['customer_name']} - Rp " . number_format($inv['total'], 0, ',', '.') . "\n\n";
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function listInvoicesByStatus($chatId, $pdo, $status) {
    $stmt = $pdo->prepare("SELECT i.invoice_no, i.total, i.due_date, c.name as customer_name, c.customer_id 
                           FROM invoices i 
                           JOIN customers c ON i.customer_id = c.id 
                           WHERE i.status = ?
                           ORDER BY i.due_date LIMIT 20");
    $stmt->execute([$status]);
    $invoices = $stmt->fetchAll();
    
    // PHP 7.4 compatible
    $statusLabels = [
        'paid' => '✅ Lunas',
        'overdue' => '🔴 Jatuh Tempo',
        'sent' => '⏳ Belum Lunas'
    ];
    $statusLabel = $statusLabels[$status] ?? '📄 ' . ucfirst($status);
    
    if (empty($invoices)) {
        sendMessage($chatId, "📄 Tidak ada invoice $statusLabel", backToMainKeyboard());
        return;
    }
    
    $text = "📄 <b>Invoice $statusLabel</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    foreach ($invoices as $inv) {
        $text .= "<b>{$inv['invoice_no']}</b>\n";
        $text .= "   {$inv['customer_name']} ({$inv['customer_id']})\n";
        $text .= "   Rp " . number_format($inv['total'], 0, ',', '.') . " - Due: {$inv['due_date']}\n\n";
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

// ========================================
// MIKROTIK FUNCTIONS
// ========================================
function listPPPoEUsers($chatId) {
    $response = callMikroTikAPI('secrets');
    
    if (!$response['success']) {
        sendMessage($chatId, "❌ Gagal terhubung ke MikroTik\n\n{$response['error']}", backToMainKeyboard());
        return;
    }
    
    $users = $response['secrets'] ?? [];
    
    if (empty($users)) {
        sendMessage($chatId, "📋 Tidak ada PPPoE users", backToMainKeyboard());
        return;
    }
    
    $text = "👥 <b>PPPoE Users</b> (" . count($users) . ")\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $count = 0;
    foreach (array_slice($users, 0, 15) as $user) {
        $count++;
        $text .= "{$count}. <b>{$user['name']}</b>\n";
        $text .= "    Profile: {$user['profile']}\n\n";
    }
    
    if (count($users) > 15) {
        $text .= "...dan " . (count($users) - 15) . " user lainnya\n";
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function listActivePPPoE($chatId) {
    $response = callMikroTikAPI('active');
    
    if (!$response['success']) {
        sendMessage($chatId, "❌ Gagal terhubung ke MikroTik", backToMainKeyboard());
        return;
    }
    
    $sessions = $response['active'] ?? [];
    
    if (empty($sessions)) {
        sendMessage($chatId, "📋 Tidak ada session aktif", backToMainKeyboard());
        return;
    }
    
    $text = "🟢 <b>Active PPPoE Sessions</b> (" . count($sessions) . ")\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    foreach (array_slice($sessions, 0, 15) as $s) {
        $text .= "• <b>{$s['name']}</b>\n";
        $text .= "  IP: {$s['address']} | Uptime: {$s['uptime']}\n\n";
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function listOfflinePPPoE($chatId) {
    // Get all secrets
    $secretsResponse = callMikroTikAPI('secrets');
    // Get active sessions
    $activeResponse = callMikroTikAPI('active');
    
    if (!$secretsResponse['success']) {
        sendMessage($chatId, "❌ Gagal terhubung ke MikroTik", backToMainKeyboard());
        return;
    }
    
    $secrets = $secretsResponse['secrets'] ?? [];
    $active = $activeResponse['active'] ?? [];
    
    // Get list of active usernames
    $activeUsers = array_column($active, 'name');
    
    // Filter offline users
    $offlineUsers = array_filter($secrets, function($s) use ($activeUsers) {
        return !in_array($s['name'], $activeUsers);
    });
    
    if (empty($offlineUsers)) {
        sendMessage($chatId, "✅ Semua PPPoE user sedang online!", backToMainKeyboard());
        return;
    }
    
    $text = "🔴 <b>Offline PPPoE Users</b> (" . count($offlineUsers) . "/" . count($secrets) . ")\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $count = 0;
    foreach ($offlineUsers as $u) {
        if ($count >= 20) {
            $text .= "\n...dan " . (count($offlineUsers) - 20) . " user lainnya";
            break;
        }
        $text .= "• <b>{$u['name']}</b> [{$u['profile']}]\n";
        if (!empty($u['last_logged_out'])) {
            $text .= "  📅 Last: {$u['last_logged_out']}\n";
        }
        $count++;
    }
    
    $text .= "\n\n📊 Online: " . count($active) . " | Offline: " . count($offlineUsers);
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function addPPPoEUser($chatId, $args) {
    $parts = explode(' ', $args);
    if (count($parts) < 3) {
        sendMessage($chatId, "❌ Format: /addpppoe [username] [password] [profile]", backToMainKeyboard());
        return;
    }
    
    $username = $parts[0];
    $password = $parts[1];
    $profile = $parts[2];
    
    $response = callMikroTikAPI('add_secret', [
        'name' => $username,
        'password' => $password,
        'profile' => $profile
    ]);
    
    if ($response['success']) {
        $text = "✅ <b>PPPoE User Ditambahkan!</b>\n\n";
        $text .= "👤 Username: {$username}\n";
        $text .= "🔑 Password: {$password}\n";
        $text .= "📦 Profile: {$profile}\n";
    } else {
        $text = "❌ <b>Gagal Menambahkan User</b>\n\n";
        $text .= "Error: " . ($response['error'] ?? 'Unknown error');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function deletePPPoEUser($chatId, $username) {
    $response = callMikroTikAPI('delete_secret', ['name' => $username]);
    
    if ($response['success']) {
        sendMessage($chatId, "✅ PPPoE User <b>{$username}</b> dihapus!", backToMainKeyboard());
    } else {
        sendMessage($chatId, "❌ Gagal menghapus user: " . ($response['error'] ?? 'Unknown'), backToMainKeyboard());
    }
}

function editPPPoEUser($chatId, $args) {
    $parts = explode(' ', $args);
    if (count($parts) < 2) {
        sendMessage($chatId, "❌ Format: /editpppoe [username] [profile]\n\nContoh: /editpppoe user123 20Mbps", backToMainKeyboard());
        return;
    }
    
    $username = $parts[0];
    $newProfile = $parts[1];
    
    // 1. Change profile
    $response = callMikroTikAPI('change_profile', [
        'username' => $username,
        'profile' => $newProfile
    ]);
    
    if ($response['success']) {
        // 2. Disconnect active session
        $disconnectResponse = callMikroTikAPI('disconnect', [
            'username' => $username
        ]);
        
        $text = "✅ <b>PPPoE User Berhasil Diubah!</b>\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "👤 Username: <b>{$username}</b>\n";
        $text .= "📦 Profile Baru: <b>{$newProfile}</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        
        if ($disconnectResponse['success']) {
            $text .= "🔌 Session aktif telah di-disconnect\n";
            $text .= "💡 User akan reconnect dengan profile baru";
        } else {
            $text .= "ℹ️ User tidak sedang online (tidak perlu disconnect)";
        }
    } else {
        $text = "❌ <b>Gagal Mengubah Profile</b>\n\n";
        $text .= "Error: " . ($response['error'] ?? 'Unknown error');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function sendVoucherProfileSelection($chatId) {
    $response = callMikroTikAPI('hotspot_profiles');
    
    $text = "🎫 <b>Generate Voucher</b>\n\n";
    $text .= "Pilih profile atau ketik:\n";
    $text .= "/voucher [profile] [jumlah]\n\n";
    $text .= "Contoh: /voucher 10k 5\n";
    
    $keyboard = ['inline_keyboard' => []];
    
    if (isset($response['profiles'])) {
        foreach (array_slice($response['profiles'], 0, 6) as $p) {
            $keyboard['inline_keyboard'][] = [
                ['text' => "🎫 {$p['name']}", 'callback_data' => "genvoucher_{$p['name']}"]
            ];
        }
    }
    
    $keyboard['inline_keyboard'][] = [['text' => '⬅️ Kembali', 'callback_data' => 'menu_hotspot']];
    
    sendMessage($chatId, $text, $keyboard);
}

function generateVoucher($chatId, $args) {
    $parts = explode(' ', $args);
    $profile = $parts[0];
    $count = isset($parts[1]) ? intval($parts[1]) : 1;
    
    if ($count < 1 || $count > 20) {
        $count = 1;
    }
    
    generateVoucherForProfile($chatId, $profile, $count);
}

function generateVoucherForProfile($chatId, $profile, $count = 1) {
    $response = callMikroTikAPI('generate_voucher', [
        'profile' => $profile,
        'count' => $count
    ]);
    
    if ($response['success'] && !empty($response['vouchers'])) {
        $text = "🎫 <b>Voucher Generated!</b>\n\n";
        $text .= "📦 Profile: {$profile}\n";
        $text .= "🔢 Jumlah: {$count}\n\n";
        $text .= "<b>Kode Voucher:</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        
        foreach ($response['vouchers'] as $v) {
            $text .= "<code>{$v}</code>\n";
        }
    } else {
        $text = "❌ <b>Gagal Generate Voucher</b>\n\n";
        $text .= "Profile: {$profile}\n";
        $text .= "Error: " . ($response['error'] ?? 'Unknown error');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function listActiveHotspotUsers($chatId) {
    $response = callMikroTikAPI('hotspot_active');
    
    if (!$response['success']) {
        sendMessage($chatId, "❌ Gagal terhubung ke MikroTik", backToMainKeyboard());
        return;
    }
    
    $users = $response['active'] ?? [];
    
    if (empty($users)) {
        sendMessage($chatId, "📋 Tidak ada Hotspot user aktif", backToMainKeyboard());
        return;
    }
    
    $text = "📡 <b>Active Hotspot Users</b> (" . count($users) . ")\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    foreach (array_slice($users, 0, 15) as $u) {
        $text .= "• <b>{$u['user']}</b>\n";
        $text .= "  IP: {$u['address']} | Uptime: {$u['uptime']}\n\n";
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function listHotspotProfiles($chatId) {
    $response = callMikroTikAPI('hotspot_profiles');
    
    if (!$response['success']) {
        sendMessage($chatId, "❌ Gagal terhubung ke MikroTik", backToMainKeyboard());
        return;
    }
    
    $profiles = $response['profiles'] ?? [];
    
    if (empty($profiles)) {
        sendMessage($chatId, "📋 Tidak ada Hotspot profiles", backToMainKeyboard());
        return;
    }
    
    $text = "📋 <b>Hotspot Profiles</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    foreach ($profiles as $p) {
        $text .= "📦 <b>{$p['name']}</b>\n";
        if (isset($p['rate-limit'])) {
            $text .= "   Speed: {$p['rate-limit']}\n";
        }
        $text .= "\n";
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

// ========================================
// NEW VOUCHER & MEMBER FUNCTIONS
// ========================================

// Create voucher dengan username = password (manual)
function createVoucherManual($chatId, $args, $samePassword = true) {
    $parts = explode(' ', $args);
    if (count($parts) < 2) {
        sendMessage($chatId, "❌ Format: /vcr [user] [profile]\n\nContoh: /vcr 12345 10k", backToMainKeyboard());
        return;
    }
    
    $username = $parts[0];
    $profile = $parts[1];
    $comment = 'vc-Go-acs-' . date('Y-m-d');
    
    $response = callMikroTikAPI('add_hotspot_user', [
        'username' => $username,
        'password' => $username, // password = username
        'profile' => $profile,
        'comment' => $comment
    ]);
    
    if ($response['success']) {
        $text = "🎫 <b>Voucher Berhasil Dibuat!</b>\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "👤 User: <code>{$username}</code>\n";
        $text .= "🔑 Pass: <code>{$username}</code>\n";
        $text .= "📦 Profile: {$profile}\n";
        $text .= "📝 Comment: {$comment}\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "💡 Copy kode di atas untuk share ke customer";
    } else {
        $text = "❌ <b>Gagal Membuat Voucher</b>\n\n";
        $text .= "Error: " . ($response['error'] ?? 'Unknown error');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

// Create member dengan username dan password berbeda
function createMemberManual($chatId, $args) {
    $parts = explode(' ', $args);
    if (count($parts) < 3) {
        sendMessage($chatId, "❌ Format: /member [user] [password] [profile]\n\nContoh: /member ahmad secret123 10k", backToMainKeyboard());
        return;
    }
    
    $username = $parts[0];
    $password = $parts[1];
    $profile = $parts[2];
    $comment = 'vc-Go-acs-' . date('Y-m-d');
    
    $response = callMikroTikAPI('add_hotspot_user', [
        'username' => $username,
        'password' => $password,
        'profile' => $profile,
        'comment' => $comment
    ]);
    
    if ($response['success']) {
        $text = "👤 <b>Member Berhasil Dibuat!</b>\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "👤 User: <code>{$username}</code>\n";
        $text .= "🔑 Pass: <code>{$password}</code>\n";
        $text .= "📦 Profile: {$profile}\n";
        $text .= "📝 Comment: {$comment}\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    } else {
        $text = "❌ <b>Gagal Membuat Member</b>\n\n";
        $text .= "Error: " . ($response['error'] ?? 'Unknown error');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

// ========================================
// MIKROTIK TOOLS FUNCTIONS
// ========================================

function pingHost($chatId, $host) {
    $host = trim($host);
    $response = callMikroTikAPI('ping', ['address' => $host, 'count' => 4]);
    
    if ($response['success']) {
        $results = $response['results'] ?? [];
        
        $text = "📡 <b>Ping Results: {$host}</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        
        if (!empty($results)) {
            $sent = count($results);
            $received = 0;
            $totalTime = 0;
            
            foreach ($results as $r) {
                if (isset($r['time'])) {
                    $received++;
                    $totalTime += intval($r['time']);
                    $text .= "✅ Reply: {$r['time']}ms\n";
                } else {
                    $text .= "❌ Timeout\n";
                }
            }
            
            $avgTime = $received > 0 ? round($totalTime / $received) : 0;
            $packetLoss = round((($sent - $received) / $sent) * 100);
            
            $text .= "\n📊 <b>Summary:</b>\n";
            $text .= "Sent: {$sent}, Received: {$received}\n";
            $text .= "Packet Loss: {$packetLoss}%\n";
            if ($received > 0) {
                $text .= "Avg Time: {$avgTime}ms";
            }
        } else {
            $text .= "ℹ️ " . ($response['message'] ?? 'No response');
        }
    } else {
        $text = "❌ <b>Ping Failed</b>\n\nError: " . ($response['error'] ?? 'Unknown error');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function showResource($chatId) {
    $response = callMikroTikAPI('resource');
    
    if ($response['success']) {
        $r = $response['resource'] ?? [];
        
        $text = "📊 <b>Router Resource</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $text .= "🏷️ <b>Identity:</b> " . ($r['identity'] ?? '-') . "\n";
        $text .= "📦 <b>Version:</b> " . ($r['version'] ?? '-') . "\n";
        $text .= "🏗️ <b>Board:</b> " . ($r['board-name'] ?? '-') . "\n";
        $text .= "⏱️ <b>Uptime:</b> " . ($r['uptime'] ?? '-') . "\n\n";
        
        $text .= "💾 <b>Memory:</b>\n";
        $totalMem = isset($r['total-memory']) ? round($r['total-memory'] / 1048576) : 0;
        $freeMem = isset($r['free-memory']) ? round($r['free-memory'] / 1048576) : 0;
        $usedMem = $totalMem - $freeMem;
        $memPercent = $totalMem > 0 ? round(($usedMem / $totalMem) * 100) : 0;
        $text .= "   Used: {$usedMem}MB / {$totalMem}MB ({$memPercent}%)\n\n";
        
        $text .= "💿 <b>Disk:</b>\n";
        $totalHdd = isset($r['total-hdd-space']) ? round($r['total-hdd-space'] / 1048576) : 0;
        $freeHdd = isset($r['free-hdd-space']) ? round($r['free-hdd-space'] / 1048576) : 0;
        $usedHdd = $totalHdd - $freeHdd;
        $text .= "   Used: {$usedHdd}MB / {$totalHdd}MB\n\n";
        
        $text .= "⚡ <b>CPU Load:</b> " . ($r['cpu-load'] ?? '-') . "%";
    } else {
        $text = "❌ <b>Gagal Mendapatkan Resource</b>\n\nError: " . ($response['error'] ?? 'Unknown');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function showInterfaces($chatId) {
    $response = callMikroTikAPI('interfaces');
    
    if ($response['success']) {
        $interfaces = $response['interfaces'] ?? [];
        
        $text = "🔌 <b>Interfaces</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        
        foreach (array_slice($interfaces, 0, 15) as $iface) {
            $status = ($iface['running'] ?? 'false') === 'true' ? '🟢' : '🔴';
            $name = $iface['name'] ?? '-';
            $type = $iface['type'] ?? '-';
            $text .= "{$status} <b>{$name}</b> ({$type})\n";
        }
        
        if (count($interfaces) > 15) {
            $text .= "\n...dan " . (count($interfaces) - 15) . " interface lainnya";
        }
    } else {
        $text = "❌ <b>Gagal Mendapatkan Interface</b>\n\nError: " . ($response['error'] ?? 'Unknown');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function showLog($chatId, $filter = '') {
    $response = callMikroTikAPI('log', ['limit' => 20, 'filter' => $filter]);
    
    if ($response['success']) {
        $logs = $response['logs'] ?? [];
        
        $text = "📋 <b>Router Log</b>";
        if ($filter) $text .= " (filter: {$filter})";
        $text .= "\n━━━━━━━━━━━━━━━━━━━━\n\n";
        
        if (empty($logs)) {
            $text .= "📭 Tidak ada log";
        } else {
            foreach (array_slice($logs, 0, 15) as $log) {
                $time = $log['time'] ?? '';
                $topics = $log['topics'] ?? '';
                $message = $log['message'] ?? '';
                $text .= "<code>{$time}</code> [{$topics}]\n{$message}\n\n";
            }
        }
    } else {
        $text = "❌ <b>Gagal Mendapatkan Log</b>\n\nError: " . ($response['error'] ?? 'Unknown');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function showTraffic($chatId, $interface = 'ether1') {
    if (empty($interface)) $interface = 'ether1';
    
    $response = callMikroTikAPI('traffic', ['interface' => trim($interface)]);
    
    if ($response['success']) {
        $t = $response['traffic'] ?? [];
        
        $text = "📈 <b>Traffic: {$interface}</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $txBps = isset($t['tx-bits-per-second']) ? round($t['tx-bits-per-second'] / 1000000, 2) : 0;
        $rxBps = isset($t['rx-bits-per-second']) ? round($t['rx-bits-per-second'] / 1000000, 2) : 0;
        
        $text .= "⬆️ <b>TX:</b> {$txBps} Mbps\n";
        $text .= "⬇️ <b>RX:</b> {$rxBps} Mbps\n";
    } else {
        $text = "❌ <b>Gagal Mendapatkan Traffic</b>\n\nError: " . ($response['error'] ?? 'Unknown');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

function showDHCPLeases($chatId) {
    $response = callMikroTikAPI('dhcp_leases');
    
    if ($response['success']) {
        $leases = $response['leases'] ?? [];
        
        $text = "📋 <b>DHCP Leases</b> (" . count($leases) . ")\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        
        if (empty($leases)) {
            $text .= "📭 Tidak ada DHCP lease";
        } else {
            foreach (array_slice($leases, 0, 15) as $lease) {
                $status = ($lease['status'] ?? '') === 'bound' ? '🟢' : '⏳';
                $ip = $lease['address'] ?? '-';
                $mac = $lease['mac-address'] ?? '-';
                $host = $lease['host-name'] ?? 'Unknown';
                $text .= "{$status} <b>{$ip}</b>\n";
                $text .= "   {$host} | {$mac}\n\n";
            }
            
            if (count($leases) > 15) {
                $text .= "...dan " . (count($leases) - 15) . " lease lainnya";
            }
        }
    } else {
        $text = "❌ <b>Gagal Mendapatkan DHCP Leases</b>\n\nError: " . ($response['error'] ?? 'Unknown');
    }
    
    sendMessage($chatId, $text, backToMainKeyboard());
}

// ========================================
// API HELPER FUNCTIONS
// ========================================
function callBillingAPI($action, $data = []) {
    $apiUrl = 'http://localhost:8888/api/billing_api.php';
    
    $postData = array_merge(['action' => $action], $data);
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true) ?: ['success' => false, 'error' => 'API error'];
}

function callMikroTikAPI($action, $data = []) {
    $apiUrl = 'http://localhost:8888/api/mikrotik_api.php?action=' . $action;
    
    $ch = curl_init($apiUrl);
    
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true) ?: ['success' => false, 'error' => 'MikroTik API error'];
}

// ========================================
// MAIN WEBHOOK HANDLER
// ========================================
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    die(json_encode(['error' => 'Invalid update']));
}

// Handle message
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    
    // Check if authorized
    if (!empty($ADMIN_CHAT_IDS) && !in_array($chatId, $ADMIN_CHAT_IDS)) {
        sendMessage($chatId, "⛔ Akses ditolak.\n\nChat ID Anda: <code>{$chatId}</code>\n\nHubungi admin untuk mendapatkan akses.");
        exit;
    }
    
    // Parse command
    if (strpos($text, '/') === 0) {
        $parts = explode(' ', $text, 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? '';
        handleCommand($chatId, $command, $args);
    } else {
        // Non-command message
        sendMessage($chatId, "👋 Halo! Ketik /menu untuk melihat menu atau /help untuk bantuan.", mainMenuKeyboard());
    }
}

// Handle callback query
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $callbackId = $callback['id'];
    $data = $callback['data'];
    
    // Check if authorized
    if (!empty($ADMIN_CHAT_IDS) && !in_array($chatId, $ADMIN_CHAT_IDS)) {
        answerCallback($callbackId, '⛔ Akses ditolak');
        exit;
    }
    
    handleCallback($chatId, $messageId, $callbackId, $data);
}

echo json_encode(['ok' => true]);
