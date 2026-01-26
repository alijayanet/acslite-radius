#!/bin/bash

# =========================================
# Setup PHP API Server untuk ACSLite
# =========================================

set -e

echo "========================================="
echo "ACSLite PHP API Server Setup"
echo "========================================="
echo ""

# Check for Root Privileges
if [ "$EUID" -ne 0 ]; then
    echo "Please run as root (sudo ./setup_php_api.sh)"
    exit 1
fi

# 1. Install PHP if needed
echo "[INFO] Checking PHP installation..."
if ! command -v php &> /dev/null; then
    echo "[INFO] PHP not found. Installing..."
    
    if command -v apt-get &> /dev/null; then
        apt-get update
        apt-get install -y php php-mysql php-json php-curl
    elif command -v yum &> /dev/null; then
        yum install -y php php-mysql php-json php-curl
    else
        echo "[ERROR] Unsupported package manager. Please install PHP manually."
        exit 1
    fi
    echo "[SUCCESS] PHP installed."
else
    PHP_VERSION=$(php -v | head -1)
    echo "[INFO] PHP already installed: $PHP_VERSION"
fi

# 2. Check PHP-MySQL extension
echo "[INFO] Checking PHP MySQL extension..."
if php -m | grep -q "mysql\|pdo_mysql"; then
    echo "[SUCCESS] PHP MySQL extension is available."
else
    echo "[WARNING] PHP MySQL extension not found. Installing..."
    if command -v apt-get &> /dev/null; then
        apt-get install -y php-mysql
    fi
fi

# 3. Copy service file
echo "[INFO] Setting up systemd service..."
SERVICE_FILE="/etc/systemd/system/acs-php-api.service"

cat > "$SERVICE_FILE" <<EOF
[Unit]
Description=ACS PHP API Server
After=network.target mariadb.service acslite.service
Wants=mariadb.service

[Service]
Type=simple
User=root
WorkingDirectory=/opt/acs/web
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

echo "[SUCCESS] Service file created at $SERVICE_FILE"

# 4. Reload and start service
echo "[INFO] Starting PHP API service..."
systemctl daemon-reload
systemctl enable acs-php-api
systemctl restart acs-php-api

# 5. Check service status
echo ""
echo "========================================="
if systemctl is-active --quiet acs-php-api; then
    echo "[SUCCESS] PHP API Server is running!"
    echo "------------------------------------------"
    
    # Get server IP
    SERVER_IP=$(hostname -I | awk '{print $1}')
    
    echo ""
    echo "📍 PHP API Endpoints:"
    echo "   - Test Page: http://$SERVER_IP:8888/api/test_customer_api.php"
    echo "   - Customer API: http://$SERVER_IP:8888/api/customer_api.php"
    echo ""
    echo "📍 Main Application (Go Server):"
    echo "   - Admin Panel: http://$SERVER_IP:7547/web/index.html"
    echo "   - Customer Login: http://$SERVER_IP:7547/web/customer_login.html"
    echo "   - Test API (HTML): http://$SERVER_IP:7547/web/test_api.html"
    echo ""
    echo "📍 Service Commands:"
    echo "   - Status: systemctl status acs-php-api"
    echo "   - Logs: journalctl -u acs-php-api -f"
    echo "   - Restart: systemctl restart acs-php-api"
    echo ""
else
    echo "[ERROR] PHP API Server failed to start."
    echo "Check logs: journalctl -u acs-php-api -e"
fi
echo "========================================="
