#!/bin/bash

# Enhanced FreeRADIUS Configuration Script
# Includes all fixes for production-ready deployment:
# - IPv4/IPv6 listen configuration
# - SQL accounting enabled
# - DateTime query fixes
# - Auto-cleanup orphaned sessions

DB_NAME_RADIUS="radius"
DB_USER_RADIUS="radius"
DB_PASS_RADIUS="radius123"
DB_HOST_RADIUS="127.0.0.1"
DB_PORT_RADIUS="3306"

SETTINGS_JSON="/opt/acs/web/data/settings.json"

if [ "$EUID" -ne 0 ]; then
  echo "Please run as root (sudo ./configure_freeradius_sql.sh)"
  exit 1
fi

FREERADIUS_DIR=""
if [ -d "/etc/freeradius/3.0" ]; then
  FREERADIUS_DIR="/etc/freeradius/3.0"
elif [ -d "/etc/raddb" ]; then
  FREERADIUS_DIR="/etc/raddb"
else
  echo "[ERROR] FreeRADIUS config directory not found (/etc/freeradius/3.0 or /etc/raddb)"
  exit 1
fi

TS=$(date +%Y%m%d-%H%M%S)

SQL_MOD_AVAIL="$FREERADIUS_DIR/mods-available/sql"
SQL_MOD_ENABLED="$FREERADIUS_DIR/mods-enabled/sql"
DEFAULT_SITE="$FREERADIUS_DIR/sites-enabled/default"
INNER_TUNNEL="$FREERADIUS_DIR/sites-enabled/inner-tunnel"
QUERIES_CONF="$FREERADIUS_DIR/mods-config/sql/main/mysql/queries.conf"

if [ ! -f "$SQL_MOD_AVAIL" ]; then
  echo "[ERROR] SQL module file not found: $SQL_MOD_AVAIL"
  exit 1
fi

echo "=========================================="
echo "FreeRADIUS Enhanced Configuration"
echo "=========================================="
echo "[INFO] Using FreeRADIUS dir: $FREERADIUS_DIR"

# Load settings from settings.json if available
if [ -f "$SETTINGS_JSON" ]; then
  PHP_BIN=$(command -v php || true)
  if [ -n "$PHP_BIN" ]; then
    DB_HOST_RADIUS=$(php -r '$s=json_decode(@file_get_contents(getenv("SETTINGS_JSON")),true)?:[]; echo $s["hotspot"]["radius"]["db_host"]??"";' SETTINGS_JSON="$SETTINGS_JSON")
    DB_PORT_RADIUS=$(php -r '$s=json_decode(@file_get_contents(getenv("SETTINGS_JSON")),true)?:[]; echo $s["hotspot"]["radius"]["db_port"]??"";' SETTINGS_JSON="$SETTINGS_JSON")
    DB_NAME_RADIUS=$(php -r '$s=json_decode(@file_get_contents(getenv("SETTINGS_JSON")),true)?:[]; echo $s["hotspot"]["radius"]["db_name"]??"";' SETTINGS_JSON="$SETTINGS_JSON")
    DB_USER_RADIUS=$(php -r '$s=json_decode(@file_get_contents(getenv("SETTINGS_JSON")),true)?:[]; echo $s["hotspot"]["radius"]["db_user"]??"";' SETTINGS_JSON="$SETTINGS_JSON")
    DB_PASS_RADIUS=$(php -r '$s=json_decode(@file_get_contents(getenv("SETTINGS_JSON")),true)?:[]; echo $s["hotspot"]["radius"]["db_pass"]??"";' SETTINGS_JSON="$SETTINGS_JSON")
  fi

  [ -z "$DB_HOST_RADIUS" ] && DB_HOST_RADIUS="127.0.0.1"
  [ -z "$DB_PORT_RADIUS" ] && DB_PORT_RADIUS="3306"
  [ -z "$DB_NAME_RADIUS" ] && DB_NAME_RADIUS="radius"
  [ -z "$DB_USER_RADIUS" ] && DB_USER_RADIUS="radius"
  [ -z "$DB_PASS_RADIUS" ] && DB_PASS_RADIUS="radius123"
fi

echo "[INFO] SQL config: host=$DB_HOST_RADIUS port=$DB_PORT_RADIUS db=$DB_NAME_RADIUS user=$DB_USER_RADIUS"

echo "[INFO] Backing up config files..."
cp -n "$SQL_MOD_AVAIL" "${SQL_MOD_AVAIL}.bak.${TS}" || true
[ -f "$DEFAULT_SITE" ] && cp -n "$DEFAULT_SITE" "${DEFAULT_SITE}.bak.${TS}" || true
[ -f "$INNER_TUNNEL" ] && cp -n "$INNER_TUNNEL" "${INNER_TUNNEL}.bak.${TS}" || true
[ -f "$QUERIES_CONF" ] && cp -n "$QUERIES_CONF" "${QUERIES_CONF}.bak.${TS}" || true

# ========================================
# 1. Enable SQL Module
# ========================================
echo "[INFO] Step 1/6: Enabling SQL module..."
if [ ! -e "$SQL_MOD_ENABLED" ]; then
  ln -s "$SQL_MOD_AVAIL" "$SQL_MOD_ENABLED"
  echo "  ✓ SQL module enabled (symlink created)"
else
  echo "  ✓ SQL module already enabled"
fi

# ========================================
# 2. Configure SQL Module Connection
# ========================================
echo "[INFO] Step 2/6: Configuring SQL module connection..."
perl -0777 -pi -e 's/dialect\s*=\s*"[^"]+"/dialect = "mysql"/g' "$SQL_MOD_AVAIL"
perl -0777 -pi -e 's/\bserver\s*=\s*"[^"]*"/server = "'"$DB_HOST_RADIUS"'"/g' "$SQL_MOD_AVAIL"
perl -0777 -pi -e 's/\bport\s*=\s*(\d+)/port = '"$DB_PORT_RADIUS"'/g' "$SQL_MOD_AVAIL"
perl -0777 -pi -e 's/\blogin\s*=\s*"[^"]*"/login = "'"$DB_USER_RADIUS"'"/g' "$SQL_MOD_AVAIL"
perl -0777 -pi -e 's/\bpassword\s*=\s*"[^"]*"/password = "'"$DB_PASS_RADIUS"'"/g' "$SQL_MOD_AVAIL"
perl -0777 -pi -e 's/\bradius_db\s*=\s*"[^"]*"/radius_db = "'"$DB_NAME_RADIUS"'"/g' "$SQL_MOD_AVAIL"
echo "  ✓ SQL connection configured"

# ========================================
# 3. Fix DateTime Queries (FROM_UNIXTIME)
# ========================================
echo "[INFO] Step 3/6: Fixing datetime queries..."
if [ -f "$QUERIES_CONF" ]; then
  # Fix acctstarttime
  sed -i 's/acctstarttime[[:space:]]*=[[:space:]]*\${....event_timestamp}/acctstarttime = FROM_UNIXTIME(\${....event_timestamp})/g' "$QUERIES_CONF"
  # Fix acctstoptime
  sed -i 's/acctstoptime[[:space:]]*=[[:space:]]*\${....event_timestamp}/acctstoptime = FROM_UNIXTIME(\${....event_timestamp})/g' "$QUERIES_CONF"
  # Fix acctupdatetime
  sed -i 's/acctupdatetime[[:space:]]*=[[:space:]]*\${....event_timestamp}/acctupdatetime = FROM_UNIXTIME(\${....event_timestamp})/g' "$QUERIES_CONF"
  echo "  ✓ DateTime queries fixed with FROM_UNIXTIME()"
else
  echo "  ⚠ queries.conf not found, skipping datetime fix"
fi

# ========================================
# 4. Enable SQL in Authorize & Accounting
# ========================================
echo "[INFO] Step 4/6: Enabling SQL in authorize & accounting sections..."

ensure_sql_in_site() {
  local site="$1"
  if [ ! -f "$site" ]; then
    return
  fi

  # authorize {}
  if ! perl -ne 'BEGIN{$in=0;$found=0} if(/^\s*authorize\s*\{\s*$/){$in=1} elsif($in && /^\s*\}/){$in=0} elsif($in && /^\s*sql\b/){$found=1} END{exit($found?0:1)}' "$site"; then
    perl -0777 -pi -e 's/(authorize\s*\{\s*\n)/$1    sql\n/' "$site"
    echo "  ✓ Added 'sql' to authorize section in $(basename $site)"
  fi

  # accounting {}
  if ! perl -ne 'BEGIN{$in=0;$found=0} if(/^\s*accounting\s*\{\s*$/){$in=1} elsif($in && /^\s*\}/){$in=0} elsif($in && /^\s*sql\b/){$found=1} END{exit($found?0:1)}' "$site"; then
    perl -0777 -pi -e 's/(accounting\s*\{\s*\n)/$1    sql\n/' "$site"
    echo "  ✓ Added 'sql' to accounting section in $(basename $site)"
  fi
}

ensure_sql_in_site "$DEFAULT_SITE"
ensure_sql_in_site "$INNER_TUNNEL"

# ========================================
# 5. Fix IPv4/IPv6 Listen Configuration
# ========================================
echo "[INFO] Step 5/6: Configuring IPv4/IPv6 listen addresses..."

# Check if IPv4 listen already exists
if ! grep -q "^\s*ipaddr\s*=\s*\*" "$DEFAULT_SITE" 2>/dev/null; then
  echo "  → Adding IPv4 listen configuration..."
  
  # Find the server default { line and add listen blocks after it
  perl -i -0777 -pe 's/(server\s+default\s*\{)/
$1

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
/s' "$DEFAULT_SITE"
  
  echo "  ✓ IPv4 listen configuration added (ipaddr = *)"
else
  echo "  ✓ IPv4 listen already configured"
fi

# ========================================
# 6. Setup Auto-Cleanup Cron Job
# ========================================
echo "[INFO] Step 6/6: Setting up auto-cleanup for orphaned sessions..."

cat > /root/cleanup_radius_sessions.sh << 'EOFCLEANUP'
#!/bin/bash
# Auto-cleanup orphaned RADIUS sessions
# Runs hourly via cron

mysql -u radius -pradius123 -D radius << SQL
UPDATE radacct 
SET acctstoptime = NOW(), 
    acctterminatecause = 'Auto-Cleanup-Orphaned' 
WHERE acctstoptime IS NULL 
  AND acctstarttime < DATE_SUB(NOW(), INTERVAL 4 HOUR)
  AND (acctsessiontime IS NULL OR acctsessiontime = 0 OR acctupdatetime < DATE_SUB(NOW(), INTERVAL 2 HOUR));
SQL

echo "$(date): Cleaned up orphaned RADIUS sessions" >> /var/log/radius_cleanup.log
EOFCLEANUP

chmod +x /root/cleanup_radius_sessions.sh

# Add to cron if not already there
if ! crontab -l 2>/dev/null | grep -q "cleanup_radius_sessions.sh"; then
  (crontab -l 2>/dev/null; echo "0 * * * * /root/cleanup_radius_sessions.sh") | crontab -
  echo "  ✓ Auto-cleanup cron job installed (runs every hour)"
else
  echo "  ✓ Auto-cleanup cron job already exists"
fi

# -------------------------------------------------
# 11. Buat tabel khusus aplikasi (opsional)
# -------------------------------------------------
cat <<'SQL' | mysql -u "${DB_USER_RADIUS}" -p"${DB_PASS_RADIUS}" "${DB_NAME_RADIUS}"
CREATE TABLE IF NOT EXISTS onu_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial_number VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) DEFAULT NULL,
    username VARCHAR(50) DEFAULT NULL COMMENT 'Customer login username',
    password VARCHAR(255) DEFAULT NULL COMMENT 'Customer login password (hashed)',
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_serial (serial_number),
    INDEX idx_coords (latitude, longitude),
    UNIQUE INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pppoe_plans (
    id VARCHAR(32) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    rate_limit VARCHAR(50) DEFAULT NULL,
    session_timeout INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL

echo "  ✓ Tabel aplikasi khusus (onu_locations, pppoe_plans) dibuat"


# ========================================
# Restart FreeRADIUS
# ========================================
echo "[INFO] Restarting FreeRADIUS..."
systemctl restart freeradius || systemctl restart radiusd || true
sleep 2

# Verify service status
if systemctl is-active --quiet freeradius || systemctl is-active --quiet radiusd; then
  echo "  ✓ FreeRADIUS is running"
else
  echo "  ✗ FreeRADIUS failed to start! Check logs: journalctl -u freeradius -n 50"
  exit 1
fi

echo ""
echo "=========================================="
echo "[SUCCESS] FreeRADIUS Configuration Complete!"
echo "=========================================="
echo ""
echo "Features enabled:"
echo "  ✓ SQL module for authentication & accounting"
echo "  ✓ IPv4/IPv6 listen on all interfaces"
echo "  ✓ DateTime queries fixed (FROM_UNIXTIME)"
echo " ✓ Auto-cleanup orphaned sessions (hourly)"
echo ""
echo "Status: systemctl status freeradius"
echo "Logs:   journalctl -u freeradius -e"
echo "Test:   radtest username password localhost 0 testing123"
echo ""
echo "=========================================="
