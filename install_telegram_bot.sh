#!/bin/bash

# Telegram Bot Long Polling Service Installer
# This script installs the Telegram bot as a systemd service

set -e

echo "=========================================="
echo "Telegram Bot Long Polling Service Installer"
echo "=========================================="

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "Please run as root (sudo)"
    exit 1
fi

# Configuration
INSTALL_DIR="/opt/acs"
SERVICE_NAME="telegram-bot"
PHP_SCRIPT="$INSTALL_DIR/web/api/telegram_bot_polling.php"

# Check if PHP script exists
if [ ! -f "$PHP_SCRIPT" ]; then
    echo "ERROR: PHP script not found at: $PHP_SCRIPT"
    echo "Please ensure telegram_bot_polling.php is in the correct location"
    exit 1
fi

# Make script executable
chmod +x "$PHP_SCRIPT"

# Create systemd service file
echo "Creating systemd service file..."
cat <<EOF > /etc/systemd/system/$SERVICE_NAME.service
[Unit]
Description=ACS-Lite Telegram Bot (Long Polling)
After=network.target mariadb.service
Wants=mariadb.service

[Service]
Type=simple
User=root
WorkingDirectory=$INSTALL_DIR/web/api
ExecStart=/usr/bin/php $PHP_SCRIPT
Restart=always
RestartSec=10

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=$SERVICE_NAME

# Process management
KillMode=mixed
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
EOF

# Create log directory
mkdir -p /var/log
touch /var/log/telegram_bot.log
chmod 644 /var/log/telegram_bot.log

# Reload systemd
echo "Reloading systemd daemon..."
systemctl daemon-reload

# Enable service
echo "Enabling service..."
systemctl enable $SERVICE_NAME

# Start service
echo "Starting service..."
systemctl start $SERVICE_NAME

# Wait a moment
sleep 2

# Check status
echo ""
echo "=========================================="
echo "Installation Complete!"
echo "=========================================="
echo ""
echo "Service Status:"
systemctl status $SERVICE_NAME --no-pager -l || true
echo ""
echo "=========================================="
echo "Useful Commands:"
echo "=========================================="
echo "Start:   systemctl start $SERVICE_NAME"
echo "Stop:    systemctl stop $SERVICE_NAME"
echo "Restart: systemctl restart $SERVICE_NAME"
echo "Status:  systemctl status $SERVICE_NAME"
echo "Logs:    journalctl -u $SERVICE_NAME -f"
echo "         tail -f /var/log/telegram_bot.log"
echo ""
echo "=========================================="
echo "Configuration:"
echo "=========================================="
echo "Bot token and admin chat IDs are loaded from:"
echo "1. Database: telegram_config table (preferred)"
echo "2. File: $INSTALL_DIR/web/data/admin.json (fallback)"
echo ""
echo "To configure via database:"
echo "mysql -u root -p acs"
echo "INSERT INTO telegram_config (bot_token, is_active) VALUES ('YOUR_BOT_TOKEN', 1);"
echo "INSERT INTO telegram_admins (chat_id, name, is_active) VALUES ('YOUR_CHAT_ID', 'Admin Name', 1);"
echo ""
echo "=========================================="
