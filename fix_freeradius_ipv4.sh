#!/bin/bash

echo "=============================================="
echo "FreeRADIUS IPv4 Listen Fix Script"
echo "=============================================="
echo ""

# Cleanup old backup files from sites-enabled to prevent duplicate server errors
echo "[INFO] Cleaning up old backup files..."
rm -f /etc/freeradius/3.0/sites-enabled/*.bak* 2>/dev/null || true
rm -f /etc/freeradius/3.0/sites-enabled/*.backup.* 2>/dev/null || true
echo "[INFO] Old backups removed"
echo ""

# Backup to separate directory (not in sites-enabled)
BACKUP_DIR="/root/freeradius_backups"
mkdir -p "$BACKUP_DIR"

SITE_FILE="/etc/freeradius/3.0/sites-enabled/default"
BACKUP_FILE="${BACKUP_DIR}/default.backup.$(date +%Y%m%d_%H%M%S)"

if [ ! -f "$SITE_FILE" ]; then
    echo "[ERROR] File $SITE_FILE not found!"
    exit 1
fi

echo "[INFO] Creating backup: $BACKUP_FILE"
cp "$SITE_FILE" "$BACKUP_FILE"

# Check if IPv4 listen already exists
if grep -q "^\s*ipaddr\s*=" "$SITE_FILE"; then
    echo "[INFO] IPv4 listen section already exists"
    echo "[INFO] Checking configuration..."
    grep -A 5 "type = auth" "$SITE_FILE" | grep "ipaddr"
else
    echo "[INFO] No IPv4 listen section found. Adding it..."
fi

# Create a properly formatted default site config with both IPv4 and IPv6
cat > /tmp/radius_listen_fix.conf << 'EOF'
# IPv4 Listen - Authentication
listen {
    type = auth
    ipaddr = *
    port = 1812
    limit {
        max_connections = 16
        lifetime = 0
        idle_timeout = 30
    }
}

# IPv4 Listen - Accounting  
listen {
    type = acct
    ipaddr = *
    port = 1813
    limit {
        max_connections = 16
        lifetime = 0
        idle_timeout = 30
    }
}

# IPv6 Listen - Authentication
listen {
    type = auth
    ipv6addr = ::
    port = 1812
    limit {
        max_connections = 16
        lifetime = 0
        idle_timeout = 30
    }
}

# IPv6 Listen - Accounting
listen {
    type = acct
    ipv6addr = ::
    port = 1813
    limit {
        max_connections = 16
        lifetime = 0
        idle_timeout = 30
    }
}
EOF

echo ""
echo "[INFO] Replacing listen sections in $SITE_FILE"
echo "[INFO] This will ensure both IPv4 and IPv6 are supported"
echo ""

# Use perl to remove old listen blocks and insert new ones
# First, find the location after the 'server default {' line
perl -i -pe 'BEGIN{$done=0} if(!$done && /^server\s+default\s*\{/) { $_ .= `cat /tmp/radius_listen_fix.conf`; $done=1; }' "$SITE_FILE"

# Remove old listen blocks that might be scattered
perl -i -0777 -pe 's/listen\s*\{[^}]*ipv?6?addr[^}]*\}//gs' "$SITE_FILE"

# Re-insert at the beginning of server default block
awk '/^server default \{/{print; system("cat /tmp/radius_listen_fix.conf"); next}1' "$BACKUP_FILE" > /tmp/radius_new_default

# Copy the new config
cp /tmp/radius_new_default "$SITE_FILE"

echo "[INFO] Configuration updated!"
echo ""
echo "[INFO] Testing configuration..."
freeradius -CX

if [ $? -eq 0 ]; then
    echo ""
    echo "[SUCCESS] Configuration is valid!"
    echo ""
    echo "[INFO] Restarting FreeRADIUS..."
    systemctl restart freeradius
    
    echo ""
    echo "[INFO] Current status:"
    systemctl status freeradius --no-pager -l | head -15
    
    echo ""
    echo "=============================================="
    echo "Fix completed successfully!"
    echo "=============================================="
    echo ""
    echo "To verify, run in debug mode:"
    echo "  systemctl stop freeradius"
    echo "  freeradius -X"
    echo ""
    echo "You should see:"
    echo "  Listening on auth address * port 1812"
    echo "  Listening on acct address * port 1813"
    echo ""
else
    echo ""
    echo "[ERROR] Configuration test failed!"
    echo "[INFO] Restoring backup..."
    cp "$BACKUP_FILE" "$SITE_FILE"
    echo "[INFO] Backup restored. Please check manually."
fi

rm -f /tmp/radius_listen_fix.conf /tmp/radius_new_default
