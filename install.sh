#!/bin/bash

# ACS Full Installation Script (Database + Service)

# ========================================
# CONFIGURATION - Customize Here
# ========================================
DB_NAME="acs"
DB_USER="root"
DB_PASS="radius123"
INSTALL_DIR="/opt/acs"
SERVICE_NAME="acslite"
DB_DSN="$DB_USER:$DB_PASS@tcp(127.0.0.1:3306)/$DB_NAME?parseTime=true"

# Admin Login Credentials (stored in web/data/admin.json)
ADMIN_USER="admin"
ADMIN_PASS="admin123"

# Note: Telegram notifications are configured in web/api/admin_api.php

# ========================================
# Send Telegram Notification Function
# Calls PHP API which has token stored securely
# ========================================
send_telegram_via_php() {
    local message="$1"
    local php_api="http://localhost:8888/api/notify.php"
    
    # Try to send via PHP API (if PHP is running)
    curl -s -X POST "$php_api" \
        -H "Content-Type: application/json" \
        -d "{\"message\": \"$message\"}" > /dev/null 2>&1 || true
}

# ========================================
# Check for Root Privileges
# ========================================
if [ "$EUID" -ne 0 ]; then
  echo "Please run as root (sudo ./install.sh)"
  exit 1
fi

echo "=========================================="
echo "ACS Full Installer"
echo "=========================================="

# ---------------------------------------------------------
# PART 1: DATABASE SETUP
# ---------------------------------------------------------
echo ""
echo ">>> STEP 1: Setting up Database..."

# 1. Install MariaDB Server
if ! command -v mysql &> /dev/null; then
    echo "[INFO] MariaDB not found. Installing..."
    
    if command -v apt-get &> /dev/null; then
        apt-get update
        apt-get install -y mariadb-server
    elif command -v yum &> /dev/null; then
        yum install -y mariadb-server
    else
        echo "[ERROR] Unsupported package manager. Please install MariaDB manually."
        exit 1
    fi
else
    echo "[INFO] MariaDB is already installed."
fi

# 2. Start and Enable Service
echo "[INFO] Starting MariaDB Service..."
systemctl start mariadb
systemctl enable mariadb

# 2.1 Install PHP dependencies (curl module for Telegram API, etc.)
echo "[INFO] Checking PHP modules..."
if ! php -m | grep -qi curl; then
    echo "[INFO] Installing php-curl module..."
    if command -v apt-get &> /dev/null; then
        apt-get install -y php-curl
    elif command -v yum &> /dev/null; then
        yum install -y php-curl
    fi
    echo "[SUCCESS] php-curl installed."
else
    echo "[INFO] php-curl is already installed."
fi

# 3. Secure Installation & Set Root Password
echo "[INFO] Configuring Database..."

# Check if we can login without password (fresh install)
if mysql -u root -e "status" &>/dev/null; then
    echo "[INFO] Setting root password to '$DB_PASS'..."
    mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$DB_PASS'; FLUSH PRIVILEGES;"
else
    echo "[INFO] Root password already set or requires authentication."
    echo "[INFO] Attempting to connect with password..."
fi

# 4. Create Database
echo "[INFO] Creating database '$DB_NAME'..."
mysql -u $DB_USER -p$DB_PASS -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;"

if [ $? -ne 0 ]; then
    echo "[ERROR] Failed to create database. Please check your password."
    exit 1
fi
echo "[SUCCESS] Database ready."

# 5. Create Tables
echo "[INFO] Creating database tables..."

mysql -u $DB_USER -p$DB_PASS $DB_NAME <<EOF
-- Create onu_locations table (with customer login support)
CREATE TABLE IF NOT EXISTS onu_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial_number VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) DEFAULT NULL,
    username VARCHAR(50) DEFAULT NULL COMMENT 'Customer login username',
    password VARCHAR(255) DEFAULT NULL COMMENT 'Customer login password (hashed)',
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_serial (serial_number),
    INDEX idx_coords (latitude, longitude),
    UNIQUE INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customers Table
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id VARCHAR(20) UNIQUE NOT NULL COMMENT 'Format: CST001',
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    address TEXT,
    pppoe_username VARCHAR(50) DEFAULT NULL,
    pppoe_password VARCHAR(100) DEFAULT NULL,
    portal_username VARCHAR(50) DEFAULT NULL COMMENT 'Customer portal login username',
    portal_password VARCHAR(255) DEFAULT NULL COMMENT 'Customer portal login password (hashed)',
    package_id INT DEFAULT NULL,
    monthly_fee DECIMAL(12,2) DEFAULT 0,
    billing_date TINYINT DEFAULT 1 COMMENT 'Day of month for billing',
    status ENUM('active', 'isolir', 'suspended', 'terminated') DEFAULT 'active',
    isolir_date DATE DEFAULT NULL,
    onu_serial VARCHAR(50) DEFAULT NULL COMMENT 'Link to ONU device',
    registered_at DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_pppoe (pppoe_username),
    INDEX idx_portal (portal_username),
    INDEX idx_onu (onu_serial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Packages Table
CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    speed VARCHAR(20) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    description TEXT,
    mikrotik_profile VARCHAR(50) DEFAULT NULL,
    mikrotik_profile_isolir VARCHAR(50) DEFAULT 'isolir',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices Table
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(30) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    due_date DATE NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    discount DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) NOT NULL,
    status ENUM('draft', 'sent', 'paid', 'overdue', 'cancelled') DEFAULT 'draft',
    paid_at DATETIME DEFAULT NULL,
    paid_amount DECIMAL(12,2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments Table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_no VARCHAR(30) UNIQUE NOT NULL,
    invoice_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash', 'transfer', 'qris', 'ewallet', 'other') DEFAULT 'cash',
    payment_date DATE NOT NULL,
    reference_no VARCHAR(100) DEFAULT NULL,
    notes TEXT,
    recorded_by VARCHAR(50) DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice (invoice_id),
    INDEX idx_customer (customer_id),
    INDEX idx_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Telegram Bot Configuration Table
CREATE TABLE IF NOT EXISTS telegram_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_token VARCHAR(100) NOT NULL COMMENT 'Bot Token dari BotFather',
    bot_username VARCHAR(50) DEFAULT NULL COMMENT 'Username bot (opsional)',
    webhook_url VARCHAR(255) DEFAULT NULL COMMENT 'URL webhook',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Telegram Admin Users Table (authorized chat IDs)
CREATE TABLE IF NOT EXISTS telegram_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(20) NOT NULL UNIQUE COMMENT 'Telegram Chat ID',
    name VARCHAR(100) DEFAULT NULL COMMENT 'Nama admin',
    username VARCHAR(50) DEFAULT NULL COMMENT 'Telegram username',
    role ENUM('superadmin', 'admin', 'operator') DEFAULT 'admin',
    is_active BOOLEAN DEFAULT TRUE,
    last_activity TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_chat_id (chat_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert Default Admin (if not exists)
-- You can add default admin user here if needed

EOF

if [ $? -eq 0 ]; then
    echo "[SUCCESS] Database tables created (onu_locations + billing tables)."
else
    echo "[WARNING] Failed to create tables. You may need to run migration manually."
fi

# 6. Run Database Migrations (for existing installations)
echo "[INFO] Running database migrations..."

mysql -u $DB_USER -p$DB_PASS $DB_NAME <<MIGRATE

-- Migration 004: Add mikrotik_profile_isolir column to packages (if not exists)
-- This column stores the profile name used when customer is isolated
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'packages' 
    AND COLUMN_NAME = 'mikrotik_profile_isolir'
);
SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE packages ADD COLUMN mikrotik_profile_isolir VARCHAR(50) DEFAULT ''isolir'' COMMENT ''MikroTik profile untuk isolir'' AFTER mikrotik_profile',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure default value for existing packages
UPDATE packages SET mikrotik_profile_isolir = 'isolir' WHERE mikrotik_profile_isolir IS NULL OR mikrotik_profile_isolir = '';

-- Migration: Add portal_username and portal_password to customers if not exists
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'customers' 
    AND COLUMN_NAME = 'portal_username'
);
SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE customers ADD COLUMN portal_username VARCHAR(50) DEFAULT NULL AFTER pppoe_password, ADD COLUMN portal_password VARCHAR(255) DEFAULT NULL AFTER portal_username',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Migration: Create telegram_config table if not exists
CREATE TABLE IF NOT EXISTS telegram_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bot_token VARCHAR(100) NOT NULL COMMENT 'Bot Token dari BotFather',
    bot_username VARCHAR(50) DEFAULT NULL COMMENT 'Username bot (opsional)',
    webhook_url VARCHAR(255) DEFAULT NULL COMMENT 'URL webhook',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: Create telegram_admins table if not exists
CREATE TABLE IF NOT EXISTS telegram_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(20) NOT NULL UNIQUE COMMENT 'Telegram Chat ID',
    name VARCHAR(100) DEFAULT NULL COMMENT 'Nama admin',
    username VARCHAR(50) DEFAULT NULL COMMENT 'Telegram username',
    role ENUM('superadmin', 'admin', 'operator') DEFAULT 'admin',
    is_active BOOLEAN DEFAULT TRUE,
    last_activity TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_chat_id (chat_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

MIGRATE

if [ $? -eq 0 ]; then
    echo "[SUCCESS] Database migrations completed."
else
    echo "[WARNING] Some migrations may have failed. Check manually if needed."
fi

# 7. Run Hotspot Voucher System Migration
echo "[INFO] Running hotspot voucher system migration..."

mysql -u $DB_USER -p$DB_PASS $DB_NAME <<HOTSPOT

-- Hotspot Vouchers Table
CREATE TABLE IF NOT EXISTS hotspot_vouchers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    batch_id VARCHAR(50) NOT NULL COMMENT 'Format: vc-acslite-YYYYMMDD-HHMMSS',
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(100) NOT NULL,
    profile VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration VARCHAR(20) NOT NULL COMMENT 'Format: 3h, 1d, 7d',
    limit_uptime INT NULL COMMENT 'Seconds',
    created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sold_date DATETIME NULL,
    first_login DATETIME NULL,
    last_login DATETIME NULL,
    expired_date DATETIME NULL,
    status ENUM('unused', 'sold', 'active', 'expired', 'disabled') DEFAULT 'unused',
    mac_address VARCHAR(17) NULL,
    comment TEXT NULL,
    scheduler_name VARCHAR(100) NULL,
    mikrotik_comment TEXT NULL,
    INDEX idx_batch (batch_id),
    INDEX idx_profile (profile),
    INDEX idx_status (status),
    INDEX idx_created (created_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voucher Batches Table
CREATE TABLE IF NOT EXISTS voucher_batches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    batch_id VARCHAR(50) UNIQUE NOT NULL,
    profile VARCHAR(50) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration VARCHAR(20) NOT NULL,
    prefix VARCHAR(20) NULL,
    code_length INT NOT NULL DEFAULT 6,
    created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(100) NULL,
    total_unused INT DEFAULT 0,
    total_sold INT DEFAULT 0,
    total_active INT DEFAULT 0,
    total_expired INT DEFAULT 0,
    total_disabled INT DEFAULT 0,
    revenue DECIMAL(10,2) DEFAULT 0,
    notes TEXT NULL,
    INDEX idx_profile (profile),
    INDEX idx_created (created_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hotspot Sales Table
CREATE TABLE IF NOT EXISTS hotspot_sales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    voucher_id INT NOT NULL,
    batch_id VARCHAR(50) NOT NULL,
    username VARCHAR(100) NOT NULL,
    sale_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    price DECIMAL(10,2) NOT NULL,
    actual_price DECIMAL(10,2) NULL,
    seller VARCHAR(100) NULL,
    customer_name VARCHAR(100) NULL,
    customer_phone VARCHAR(20) NULL,
    payment_method ENUM('cash', 'transfer', 'qris', 'ewallet', 'other') DEFAULT 'cash',
    notes TEXT NULL,
    INDEX idx_batch (batch_id),
    INDEX idx_sale_date (sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hotspot Profiles Table
CREATE TABLE IF NOT EXISTS hotspot_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) UNIQUE NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration VARCHAR(20) NOT NULL,
    duration_seconds INT NOT NULL COMMENT 'Duration in seconds',
    rate_limit VARCHAR(50) NULL,
    shared_users INT DEFAULT 1,
    session_timeout INT NULL,
    idle_timeout INT NULL,
    validity_type ENUM('uptime', 'time', 'both') DEFAULT 'uptime',
    on_login_script TEXT NULL,
    created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_date DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_name (name),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample profiles if table is empty
INSERT IGNORE INTO hotspot_profiles (name, price, duration, duration_seconds, rate_limit, validity_type) VALUES
('3JAM', 3000, '3h', 10800, '2M/2M', 'uptime'),
('1HARI', 5000, '1d', 86400, '2M/2M', 'uptime'),
('3HARI', 10000, '3d', 259200, '2M/2M', 'uptime'),
('1MINGGU', 20000, '7d', 604800, '3M/3M', 'uptime');

HOTSPOT

if [ $? -eq 0 ]; then
    echo "[SUCCESS] Hotspot voucher tables created."
else
    echo "[WARNING] Hotspot tables may have failed. Check manually if needed."
fi


# ---------------------------------------------------------
# PART 2: SERVICE SETUP
# ---------------------------------------------------------
echo ""
echo ">>> STEP 2: Installing Application Service..."

# 1. Detect Architecture
ARCH=$(uname -m)
echo "[INFO] Detected Architecture: $ARCH"

if [ "$ARCH" = "x86_64" ]; then
    BINARY_SOURCE="build/acs-linux-amd64"
elif [ "$ARCH" = "aarch64" ]; then
    BINARY_SOURCE="build/acs-linux-arm64"
else
    echo "[ERROR] Unsupported architecture: $ARCH"
    exit 1
fi

# 2. Verify Binary Exists
if [ ! -f "$BINARY_SOURCE" ]; then
    echo "[ERROR] Binary not found at: $BINARY_SOURCE"
    echo "Please ensure you have uploaded the 'build' folder."
    exit 1
fi

# 3. Create Installation Directory
echo "[INFO] Creating installation directory at $INSTALL_DIR..."
mkdir -p "$INSTALL_DIR/web/templates"
mkdir -p "$INSTALL_DIR/web/api"
mkdir -p "$INSTALL_DIR/web/data"
mkdir -p "$INSTALL_DIR/web/js"

# 4. Copy Files
echo "[INFO] Copying application files..."
cp "$BINARY_SOURCE" "$INSTALL_DIR/acs"
chmod +x "$INSTALL_DIR/acs"

# Copy web/templates TO web/ root (so accessible at /web/xxx.html)
if [ -d "web/templates" ]; then
    cp -r web/templates/* "$INSTALL_DIR/web/"
    echo "[INFO] Copied web/templates/* to web/"
else
    echo "[WARNING] web/templates directory not found! UI might not work."
fi

# Copy web/js (shared JavaScript)
if [ -d "web/js" ]; then
    cp -r web/js/* "$INSTALL_DIR/web/js/"
    echo "[INFO] Copied web/js/"
fi

# Copy web/api (PHP API files)
if [ -d "web/api" ]; then
    cp -r web/api/* "$INSTALL_DIR/web/api/"
    echo "[INFO] Copied web/api/"
fi

# Copy web/data if exists
if [ -d "web/data" ]; then
    cp -r web/data/* "$INSTALL_DIR/web/data/" 2>/dev/null || true
    echo "[INFO] Copied web/data/"
fi

# Copy .htaccess if exists
if [ -f "web/.htaccess" ]; then
    cp "web/.htaccess" "$INSTALL_DIR/web/.htaccess"
    echo "[INFO] Copied web/.htaccess"
fi

# 5. Create .env File
echo "[INFO] Creating .env configuration file..."
cat <<EOF > "$INSTALL_DIR/.env"
ACS_PORT=7547
DB_DSN=$DB_DSN
API_KEY=secret
WEB_DIR=$INSTALL_DIR/web
EOF
chmod 600 "$INSTALL_DIR/.env"

# 6. Create Systemd Service File
echo "[INFO] Creating systemd service file..."
cat <<EOF > /etc/systemd/system/$SERVICE_NAME.service
[Unit]
Description=GoACS TR-069 Auto Configuration Server
After=network.target mariadb.service

[Service]
Type=simple
User=root
WorkingDirectory=$INSTALL_DIR
ExecStart=$INSTALL_DIR/acs
Restart=always
RestartSec=5

# Load Environment Variables from .env
EnvironmentFile=$INSTALL_DIR/.env

# Logging
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=$SERVICE_NAME

[Install]
WantedBy=multi-user.target
EOF

# 7. Enable and Start Service
echo "[INFO] Reloading systemd daemon..."
systemctl daemon-reload
systemctl enable $SERVICE_NAME
systemctl restart $SERVICE_NAME

# ---------------------------------------------------------
# PART 3: PHP API SERVER (Customer Portal)
# ---------------------------------------------------------
echo ""
echo ">>> STEP 3: Installing PHP API Server..."

# 1. Fix potential repository issues
echo "[INFO] Fixing repository configuration..."
sed -i '/backports/d' /etc/apt/sources.list 2>/dev/null || true

# 2. Install PHP
echo "[INFO] Installing PHP..."
if ! command -v php &> /dev/null; then
    if command -v apt-get &> /dev/null; then
        apt-get update
        apt-get install -y php-cli php-mysql php-json 2>/dev/null || apt-get install -y php php-mysql 2>/dev/null || echo "[WARNING] PHP installation failed. Customer API may not work."
    elif command -v yum &> /dev/null; then
        yum install -y php php-mysql php-json 2>/dev/null || echo "[WARNING] PHP installation failed."
    fi
else
    echo "[INFO] PHP is already installed."
fi

# 3. Customer data is now stored in MySQL (onu_locations table)
# Note: customers.json is no longer needed as fallback

# 4. Ensure admin.json exists with default credentials
echo "[INFO] Checking admin.json..."
if [ ! -f "$INSTALL_DIR/web/data/admin.json" ]; then
    cat <<ADMINJSON > "$INSTALL_DIR/web/data/admin.json"
{
    "admin": {
        "username": "$ADMIN_USER",
        "password": "$ADMIN_PASS"
    }
}
ADMINJSON
    chmod 600 "$INSTALL_DIR/web/data/admin.json"
    echo "[INFO] Created admin.json with default credentials"
else
    echo "[INFO] admin.json already exists, keeping current credentials"
fi


# 5. Create PHP API systemd service
echo "[INFO] Creating PHP API service..."
cat <<EOF > /etc/systemd/system/acs-php-api.service
[Unit]
Description=ACS PHP Customer API Server
After=network.target mariadb.service $SERVICE_NAME.service
Wants=mariadb.service

[Service]
Type=simple
User=root
WorkingDirectory=$INSTALL_DIR/web
ExecStart=/usr/bin/php -S 0.0.0.0:8888
Restart=always
RestartSec=5

# Logging
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=acs-php-api

[Install]
WantedBy=multi-user.target
EOF

# 5. Enable and Start PHP API Service
if command -v php &> /dev/null; then
    echo "[INFO] Starting PHP API service..."
    systemctl daemon-reload
    systemctl enable acs-php-api
    systemctl restart acs-php-api
    PHP_STATUS="Running"
else
    echo "[WARNING] PHP not installed. Customer API will not be available."
    PHP_STATUS="Not Available (PHP not installed)"
fi

# ---------------------------------------------------------
# PART 4: REAL-TIME MONITORING CONFIGURATION
# ---------------------------------------------------------
echo ""
echo ">>> STEP 4: Configuring Real-Time Monitoring..."

# Wait for ACS service to be fully ready
sleep 3

# Configure all connected devices to send data every 5 minutes
configure_realtime() {
    local INTERVAL=300  # 5 minutes in seconds
    local ACS_API="http://localhost:7547/api"
    local API_KEY="secret"
    
    echo "[INFO] Setting Periodic Inform Interval to $((INTERVAL/60)) minutes for all devices..."
    
    # Get all device serial numbers
    DEVICES=$(curl -s -H "X-API-Key: $API_KEY" "$ACS_API/devices" 2>/dev/null | grep -oP '"serial_number"\s*:\s*"\K[^"]+')
    
    if [ -z "$DEVICES" ]; then
        echo "[INFO] No devices connected yet. Real-time config will apply when devices connect."
    else
        for SN in $DEVICES; do
            echo "[INFO] Configuring $SN..."
            curl -s -X POST \
                -H "X-API-Key: $API_KEY" \
                -H "Content-Type: application/json" \
                -d "{\"name\":\"SetParameterValues\",\"payload\":{\"parameters\":{\"InternetGatewayDevice.ManagementServer.PeriodicInformInterval\":$INTERVAL}}}" \
                "$ACS_API/tasks?sn=$SN" > /dev/null 2>&1
        done
        echo "[SUCCESS] Real-time monitoring configured for $(echo "$DEVICES" | wc -w) device(s)."
    fi
}

# Run real-time configuration
configure_realtime

# ---------------------------------------------------------
# PART 5: AUTO-REFRESH CRON JOB
# ---------------------------------------------------------
echo ""
echo ">>> STEP 5: Setting up Auto-Refresh Cron Job..."

# Copy refresh script
if [ -f "acs-refresh.sh" ]; then
    cp acs-refresh.sh "$INSTALL_DIR/acs-refresh.sh"
    chmod +x "$INSTALL_DIR/acs-refresh.sh"
    echo "[INFO] Copied acs-refresh.sh to $INSTALL_DIR"
else
    # Create refresh script inline
    cat <<'REFRESHSCRIPT' > "$INSTALL_DIR/acs-refresh.sh"
#!/bin/bash
ACS_API="http://localhost:7547/api"
API_KEY="secret"
DEVICES=$(curl -s -H "X-API-Key: $API_KEY" "$ACS_API/devices" 2>/dev/null | grep -oP '"serial_number"\s*:\s*"\K[^"]+')
for SN in $DEVICES; do
    curl -s -X POST -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
        -d '{"name":"GetParameterValues","payload":{"parameters":["InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID","InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress","InternetGatewayDevice.DeviceInfo.X_ALU_RxPower"]}}' \
        "$ACS_API/tasks?sn=$SN" > /dev/null 2>&1
done
REFRESHSCRIPT
    chmod +x "$INSTALL_DIR/acs-refresh.sh"
    echo "[INFO] Created acs-refresh.sh"
fi

# Add cron job (every 5 minutes)
CRON_JOB="*/5 * * * * $INSTALL_DIR/acs-refresh.sh"
(crontab -l 2>/dev/null | grep -v "acs-refresh.sh"; echo "$CRON_JOB") | crontab -
echo "[SUCCESS] Cron job added: Auto-refresh every 5 minutes"

# ---------------------------------------------------------
# PART 6: SETUP CRON JOBS FOR AUTOMATION
# ---------------------------------------------------------
echo ""
echo ">>> STEP 6: Setting up Automated Cron Jobs..."

# Create log directory if not exists
mkdir -p /var/log

# Setup crontab for auto-isolir and auto-invoice
echo "[INFO] Configuring cron jobs..."

# Check if cron jobs already exist to prevent duplicates
CRON_ISOLIR="1 0 * * * /usr/bin/php $INSTALL_DIR/web/api/auto_isolir_overdue.php >> /var/log/auto_isolir.log 2>&1"
CRON_INVOICE="1 0 1 * * /usr/bin/php $INSTALL_DIR/web/api/auto_generate_invoice.php >> /var/log/auto_invoice.log 2>&1"

# Get existing crontab
crontab -l > /tmp/current_crontab 2>/dev/null || true

# Check and add auto-isolir cron if not exists
if ! grep -q "auto_isolir_overdue.php" /tmp/current_crontab 2>/dev/null; then
    echo "$CRON_ISOLIR" >> /tmp/current_crontab
    echo "[INFO] Added auto-isolir cron job (daily at 00:01)"
fi

# Check and add auto-invoice cron if not exists
if ! grep -q "auto_generate_invoice.php" /tmp/current_crontab 2>/dev/null; then
    echo "$CRON_INVOICE" >> /tmp/current_crontab
    echo "[INFO] Added auto-invoice cron job (monthly on 1st at 00:01)"
fi

# Install the crontab
crontab /tmp/current_crontab
rm /tmp/current_crontab

echo "[SUCCESS] Cron jobs configured:"
echo "  - Auto-isolir overdue: Daily at 00:01"
echo "  - Auto-generate invoice: Monthly (1st) at 00:01"

# ---------------------------------------------------------
# FINAL STATUS
# ---------------------------------------------------------

echo ""
echo "=========================================="
if systemctl is-active --quiet $SERVICE_NAME; then
    SERVER_IP=$(hostname -I | awk '{print $1}')
    echo "[SUCCESS] INSTALLATION COMPLETE!"
    echo "------------------------------------------"
    echo ""
    echo "📍 Main Application (Go Server - Port 7547):"
    echo "   Admin Panel:     http://$SERVER_IP:7547/web/index.html"
    echo "   Admin Login:     http://$SERVER_IP:7547/web/login.html"
    echo "   Map View:        http://$SERVER_IP:7547/web/map.html"
    echo "   Database Admin:  http://$SERVER_IP:7547/web/db_admin.html"
    echo "   Data Viewer:     http://$SERVER_IP:7547/web/check_database.html"
    echo ""
    echo "📍 Customer Portal (PHP API - Port 8888):"
    echo "   Customer Login: http://$SERVER_IP:7547/web/customer_login.html"
    echo "   API Status: $PHP_STATUS"
    echo ""
    echo "📍 Admin Credentials:"
    echo "   Username: $ADMIN_USER"
    echo "   Password: $ADMIN_PASS"
    echo ""
    echo "📍 Configuration:"
    echo "   Config File: $INSTALL_DIR/.env"
    echo "   Database: $DB_NAME (user: $DB_USER)"
    echo "   Auto-Refresh: Every 15 seconds (built-in)"
    echo "   ONU Inform Interval: 5 minutes (configured)"
    echo ""
    echo "📍 Service Commands:"
    echo "   ACS Status:     systemctl status $SERVICE_NAME"
    echo "   PHP API Status: systemctl status acs-php-api"
    echo "------------------------------------------"

    
    # Send success notification to Telegram via PHP API
    send_telegram_via_php "✅ <b>Go-ACS Installation Complete!</b>

📍 Server: ${SERVER_IP}
🕐 Time: $(date '+%Y-%m-%d %H:%M:%S')
💻 Hostname: $(hostname)

🌐 <b>Access URLs:</b>
• Admin Panel: http://${SERVER_IP}:7547/web/index.html
• Admin Login: http://${SERVER_IP}:7547/web/login.html
• Customer Portal: http://${SERVER_IP}:7547/web/customer_login.html

🔐 <b>Admin Credentials:</b>
• Username: ${ADMIN_USER}
• Password: ${ADMIN_PASS}

📊 PHP API: ${PHP_STATUS}

📞 Support: wa.me/6281947215703"

else
    echo "[ERROR] Service failed to start."
    echo "Check logs: journalctl -u $SERVICE_NAME -e"
    
    # Send failure notification to Telegram via PHP API
    send_telegram_via_php "❌ <b>Go-ACS Installation Failed!</b>

📍 Server: ${SERVER_IP}
🕐 Time: $(date '+%Y-%m-%d %H:%M:%S')
💻 Hostname: $(hostname)

⚠️ Service failed to start.
Please check logs: journalctl -u $SERVICE_NAME -e

📞 Support: wa.me/6281947215703"
fi
echo "=========================================="
