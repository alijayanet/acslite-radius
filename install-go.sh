#!/bin/bash

# ACS Full Installation Script (Database + Service)

# Configuration
DB_NAME="acs"
DB_USER="root"
DB_PASS="secret123"
INSTALL_DIR="/opt/acs"
SERVICE_NAME="acslite"
DB_DSN="$DB_USER:$DB_PASS@tcp(127.0.0.1:3306)/$DB_NAME?parseTime=true"

# Check for Root Privileges
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

# 4. Copy Files
echo "[INFO] Copying application files..."
cp "$BINARY_SOURCE" "$INSTALL_DIR/acs"
chmod +x "$INSTALL_DIR/acs"

if [ -d "web/templates" ]; then
    cp -r web/templates/* "$INSTALL_DIR/web/templates/"
else
    echo "[WARNING] web/templates directory not found! UI might not work."
fi

# 5. Create .env File
echo "[INFO] Creating .env configuration file..."
cat <<EOF > "$INSTALL_DIR/.env"
ACS_PORT=7547
DB_DSN=$DB_DSN
API_KEY=secret
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

# 8. Final Status Check
echo ""
echo "=========================================="
if systemctl is-active --quiet $SERVICE_NAME; then
    echo "[SUCCESS] INSTALLATION COMPLETE!"
    echo "------------------------------------------"
    echo "Web UI: http://$(hostname -I | awk '{print $1}'):7547/web/"
    echo "Config: $INSTALL_DIR/.env"
    echo "------------------------------------------"
else
    echo "[ERROR] Service failed to start."
    echo "Check logs: journalctl -u $SERVICE_NAME -e"
fi
echo "=========================================="
