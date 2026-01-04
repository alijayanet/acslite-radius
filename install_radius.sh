#!/bin/bash

DB_NAME_RADIUS="radius"
DB_USER_RADIUS="radius"
DB_PASS_RADIUS="radius123"

MYSQL_ROOT_USER="root"
MYSQL_ROOT_PASS=""

if [ "$EUID" -ne 0 ]; then
  echo "Please run as root (sudo ./install_radius.sh)"
  exit 1
fi

echo "=========================================="
echo "RADIUS Installer (FreeRADIUS + MySQL)"
echo "=========================================="

# Cleanup old backup files from previous installations (prevents duplicate virtual server errors)
echo "[INFO] Cleaning up old FreeRADIUS backup files..."
if [ -d "/etc/freeradius/3.0/sites-enabled" ]; then
    rm -f /etc/freeradius/3.0/sites-enabled/*.bak* 2>/dev/null || true
    rm -f /etc/freeradius/3.0/sites-enabled/*.backup* 2>/dev/null || true
    echo "[INFO] Old backup files removed from sites-enabled"
fi
echo ""

if command -v apt-get &> /dev/null; then
  apt-get update
  apt-get install -y freeradius freeradius-mysql mariadb-client
elif command -v yum &> /dev/null; then
  yum install -y freeradius freeradius-mysql mariadb
else
  echo "[ERROR] Unsupported package manager. Please install FreeRADIUS manually."
  exit 1
fi

echo "[INFO] Detecting MySQL root credentials..."

if [ -f "/opt/acs/.env" ]; then
  if grep -q '^DB_DSN=' /opt/acs/.env 2>/dev/null; then
    DSN_LINE=$(grep '^DB_DSN=' /opt/acs/.env | head -n 1)
    # DB_DSN=user:pass@tcp(host:port)/db?...
    if [[ "$DSN_LINE" =~ ^DB_DSN=([^:]+):([^@]*)@tcp\(([^:]+):([0-9]+)\)/([^?]+) ]]; then
      MYSQL_ROOT_USER="${BASH_REMATCH[1]}"
      MYSQL_ROOT_PASS="${BASH_REMATCH[2]}"
    fi
  fi
fi

if [ -z "$MYSQL_ROOT_PASS" ]; then
  echo "[INFO] MySQL root password appears empty."
  MYSQL_CMD=(mysql -u "$MYSQL_ROOT_USER")
else
  MYSQL_CMD=(mysql -u "$MYSQL_ROOT_USER" -p"$MYSQL_ROOT_PASS")
fi

echo "[INFO] Creating database '$DB_NAME_RADIUS' and user '$DB_USER_RADIUS'..."

"${MYSQL_CMD[@]}" -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME_RADIUS};"
"${MYSQL_CMD[@]}" -e "CREATE USER IF NOT EXISTS '${DB_USER_RADIUS}'@'localhost' IDENTIFIED BY '${DB_PASS_RADIUS}';"
"${MYSQL_CMD[@]}" -e "GRANT ALL PRIVILEGES ON ${DB_NAME_RADIUS}.* TO '${DB_USER_RADIUS}'@'localhost'; FLUSH PRIVILEGES;"

# -----------------------------------------------------------------
# 1. Add NAS entry (router) to radius DB if not present
# -----------------------------------------------------------------
NAS_IP="${Mikrotik_IP:-192.168.1.1}"          # can be overridden via env var
NAS_SECRET="${Mikrotik_SECRET:-radius}"      # same secret as used on MikroTik
NAS_NAME="${Mikrotik_NAME:-mikrotik1}"
"${MYSQL_CMD[@]}" ${DB_NAME_RADIUS} -e "INSERT IGNORE INTO nas (nasname, shortname, type, ports, secret, description) VALUES ('${NAS_IP}', '${NAS_NAME}', 'other', 0, '${NAS_SECRET}', 'Added by install_radius.sh');"

# -----------------------------------------------------------------
# 2. Add default RADIUS user for testing (demo)
# -----------------------------------------------------------------
RADIUS_USER="${DEFAULT_RADIUS_USER:-demo}"
RADIUS_PASS="${DEFAULT_RADIUS_PASS:-demo123}"
"${MYSQL_CMD[@]}" ${DB_NAME_RADIUS} -e "INSERT IGNORE INTO radcheck (username, attribute, op, value) VALUES ('${RADIUS_USER}', 'Cleartext-Password', ':=', '${RADIUS_PASS}');"

# (Optional) Assign a static IP to the demo user – uncomment if needed
#"${MYSQL_CMD[@]}" ${DB_NAME_RADIUS} -e "INSERT IGNORE INTO radreply (username, attribute, op, value) VALUES ('${RADIUS_USER}', 'Framed-IP-Address', '=', '192.168.100.50');"

# Update settings.json (only if it exists)
SETTINGS_JSON="/opt/acs/web/data/settings.json"
if [ -f "$SETTINGS_JSON" ] && command -v php > /dev/null 2>&1; then
  echo "[INFO] Updating $SETTINGS_JSON with hotspot.radius DB config..."
  php -r '
    $path = getenv("SETTINGS_JSON");
    if (!$path || !file_exists($path)) exit(0);
    $s = json_decode(@file_get_contents($path), true) ?: [];
    if (!isset($s["hotspot"])) $s["hotspot"] = [];
    if (!isset($s["hotspot"]["radius"])) $s["hotspot"]["radius"] = [];
    $s["hotspot"]["radius"]["db_host"] = "127.0.0.1";
    $s["hotspot"]["radius"]["db_port"] = 3306;
    $s["hotspot"]["radius"]["db_name"] = getenv("DB_NAME_RADIUS");
    $s["hotspot"]["radius"]["db_user"] = getenv("DB_USER_RADIUS");
    $s["hotspot"]["radius"]["db_pass"] = getenv("DB_PASS_RADIUS");
    file_put_contents($path, json_encode($s, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  ' SETTINGS_JSON="$SETTINGS_JSON" DB_NAME_RADIUS="$DB_NAME_RADIUS" DB_USER_RADIUS="$DB_USER_RADIUS" DB_PASS_RADIUS="$DB_PASS_RADIUS"
else
  echo "[INFO] Skipping settings.json update (file not found or PHP not available)."
fi

echo "[INFO] Loading FreeRADIUS SQL schema into '${DB_NAME_RADIUS}'..."

SCHEMA_PATHS=(
  "/etc/freeradius/3.0/mods-config/sql/main/mysql/schema.sql"
  "/etc/freeradius/mods-config/sql/main/mysql/schema.sql"
  "/etc/raddb/mods-config/sql/main/mysql/schema.sql"
)

SCHEMA_FILE=""
for p in "${SCHEMA_PATHS[@]}"; do
  if [ -f "$p" ]; then
    SCHEMA_FILE="$p"
    break
  fi
done

if [ -n "$SCHEMA_FILE" ]; then
  mysql -u "${DB_USER_RADIUS}" -p"${DB_PASS_RADIUS}" "${DB_NAME_RADIUS}" < "$SCHEMA_FILE"
else
  mysql -u "${DB_USER_RADIUS}" -p"${DB_PASS_RADIUS}" "${DB_NAME_RADIUS}" <<'EOF'
CREATE TABLE IF NOT EXISTS radcheck (
  id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL DEFAULT '',
  attribute VARCHAR(64) NOT NULL DEFAULT '',
  op CHAR(2) NOT NULL DEFAULT '==',
  value VARCHAR(253) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radreply (
  id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL DEFAULT '',
  attribute VARCHAR(64) NOT NULL DEFAULT '',
  op CHAR(2) NOT NULL DEFAULT '=',
  value VARCHAR(253) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radacct (
  radacctid BIGINT(21) NOT NULL AUTO_INCREMENT,
  acctsessionid VARCHAR(64) NOT NULL DEFAULT '',
  acctuniqueid VARCHAR(32) NOT NULL DEFAULT '',
  username VARCHAR(64) NOT NULL DEFAULT '',
  realm VARCHAR(64) DEFAULT '',
  nasipaddress VARCHAR(15) NOT NULL DEFAULT '',
  nasportid VARCHAR(15) DEFAULT NULL,
  nasporttype VARCHAR(32) DEFAULT NULL,
  acctstarttime DATETIME DEFAULT NULL,
  acctupdatetime DATETIME DEFAULT NULL,
  acctstoptime DATETIME DEFAULT NULL,
  acctinterval INT(12) DEFAULT NULL,
  acctsessiontime INT(12) UNSIGNED DEFAULT NULL,
  acctauthentic VARCHAR(32) DEFAULT NULL,
  connectinfo_start VARCHAR(50) DEFAULT NULL,
  connectinfo_stop VARCHAR(50) DEFAULT NULL,
  acctinputoctets BIGINT(20) DEFAULT NULL,
  acctoutputoctets BIGINT(20) DEFAULT NULL,
  calledstationid VARCHAR(50) NOT NULL DEFAULT '',
  callingstationid VARCHAR(50) NOT NULL DEFAULT '',
  acctterminatecause VARCHAR(32) NOT NULL DEFAULT '',
  servicetype VARCHAR(32) DEFAULT NULL,
  framedprotocol VARCHAR(32) DEFAULT NULL,
  framedipaddress VARCHAR(15) NOT NULL DEFAULT '',
  PRIMARY KEY (radacctid),
  KEY username (username(32)),
  KEY framedipaddress (framedipaddress),
  KEY acctsessionid (acctsessionid),
  KEY acctuniqueid (acctuniqueid),
  KEY acctstarttime (acctstarttime),
  KEY acctstoptime (acctstoptime),
  KEY nasipaddress (nasipaddress)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS nas (
  nasname VARCHAR(128) NOT NULL,
  shortname VARCHAR(32) DEFAULT NULL,
  type VARCHAR(30) DEFAULT NULL,
  ports INT(5) DEFAULT NULL,
  secret VARCHAR(60) NOT NULL,
  server VARCHAR(64) DEFAULT NULL,
  community VARCHAR(50) DEFAULT NULL,
  description VARCHAR(200) DEFAULT NULL,
  PRIMARY KEY (nasname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radgroupcheck (
  id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  groupname VARCHAR(64) NOT NULL DEFAULT '',
  attribute VARCHAR(64) NOT NULL DEFAULT '',
  op CHAR(2) NOT NULL DEFAULT '==',
  value VARCHAR(253) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radgroupreply (
  id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  groupname VARCHAR(64) NOT NULL DEFAULT '',
  attribute VARCHAR(64) NOT NULL DEFAULT '',
  op CHAR(2) NOT NULL DEFAULT '=',
  value VARCHAR(253) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radpostauth (
  id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL DEFAULT '',
  pass VARCHAR(64) NOT NULL DEFAULT '',
  reply VARCHAR(32) NOT NULL DEFAULT '',
  authdate DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radusergroup (
  id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL DEFAULT '',
  groupname VARCHAR(64) NOT NULL DEFAULT '',
  priority INT(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (id),
  KEY username (username(32)),
  KEY groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert dummy data using proper SQL syntax
INSERT IGNORE INTO nas (nasname, shortname, type, ports, secret, description) 
VALUES ('192.168.1.1', 'mikrotik1', 'other', 0, 'radius', 'Dummy NAS entry for testing');

INSERT IGNORE INTO radcheck (username, attribute, op, value) 
VALUES ('demo', 'Cleartext-Password', ':=', 'demo123');

INSERT IGNORE INTO radreply (username, attribute, op, value) 
VALUES ('demo', 'Framed-IP-Address', '=', '192.168.100.50');

INSERT IGNORE INTO radgroupcheck (groupname, attribute, op, value) 
VALUES ('demo-group', 'Auth-Type', ':=', 'Accept');

INSERT IGNORE INTO radgroupreply (groupname, attribute, op, value) 
VALUES ('demo-group', 'Framed-IP-Address', '=', '192.168.100.51');

INSERT IGNORE INTO radusergroup (username, groupname, priority) 
VALUES ('demo', 'demo-group', 1);

INSERT IGNORE INTO radpostauth (username, pass, reply, authdate) 
VALUES ('demo', 'demo123', 'Access-Accept', NOW());

EOF
fi

# -----------------------------------------------------------------
# Insert custom NAS (router) if environment variables are set
# -----------------------------------------------------------------
NAS_IP="${Mikrotik_IP:-192.168.1.1}"
NAS_SECRET="${Mikrotik_SECRET:-radius}"
NAS_NAME="${Mikrotik_NAME:-mikrotik1}"

echo "[INFO] Inserting custom NAS entry (${NAS_NAME} @ ${NAS_IP})..."
"${MYSQL_CMD[@]}" "${DB_NAME_RADIUS}" -e "
  INSERT IGNORE INTO nas (nasname, shortname, type, ports, secret, description) 
  VALUES ('${NAS_IP}', '${NAS_NAME}', 'other', 0, '${NAS_SECRET}', 'Added by install_radius.sh') 
  ON DUPLICATE KEY UPDATE 
    secret='${NAS_SECRET}', 
    shortname='${NAS_NAME}',
    description='Updated by install_radius.sh';
"

# -----------------------------------------------------------------
# Insert custom test user if environment variables are set
# -----------------------------------------------------------------
RADIUS_USER="${DEFAULT_RADIUS_USER:-demo}"
RADIUS_PASS="${DEFAULT_RADIUS_PASS:-demo123}"

if [ "$RADIUS_USER" != "demo" ] || [ "$RADIUS_PASS" != "demo123" ]; then
  echo "[INFO] Inserting custom test user (${RADIUS_USER})..."
  "${MYSQL_CMD[@]}" "${DB_NAME_RADIUS}" -e "
    INSERT IGNORE INTO radcheck (username, attribute, op, value) 
    VALUES ('${RADIUS_USER}', 'Cleartext-Password', ':=', '${RADIUS_PASS}');
  "
fi

if [ -f "./configure_freeradius_sql.sh" ]; then
  echo "[INFO] Configuring FreeRADIUS to use SQL (authorize + accounting)..."
  chmod +x ./configure_freeradius_sql.sh
  ./configure_freeradius_sql.sh
else
  echo "[WARNING] configure_freeradius_sql.sh not found. FreeRADIUS may not use SQL until configured."
fi

# Apply IPv4/IPv6 listen fix if script is present
if [ -f "./fix_freeradius_ipv4.sh" ]; then
  echo "[INFO] Applying FreeRADIUS IPv4/IPv6 listen fix..."
  chmod +x ./fix_freeradius_ipv4.sh
  ./fix_freeradius_ipv4.sh
else
  echo "[WARNING] fix_freeradius_ipv4.sh not found. Skipping listen fix."
fi

echo "[INFO] Enabling and starting FreeRADIUS..."

systemctl enable freeradius || true
systemctl restart freeradius || true

# -----------------------------------------------------------------
# Ensure hourly cleanup cron job for orphaned RADIUS sessions
# -----------------------------------------------------------------
if ! crontab -l 2>/dev/null | grep -q "/root/cleanup_radius_sessions.sh"; then
  (crontab -l 2>/dev/null; echo "0 * * * * /root/cleanup_radius_sessions.sh") | crontab -
  echo "  ✓ Auto-cleanup cron job installed (runs every hour)"
else
  echo "  ✓ Auto-cleanup cron job already exists"
fi

echo "=========================================="
echo "[SUCCESS] RADIUS setup complete"
echo "------------------------------------------"
echo "DB: ${DB_NAME_RADIUS}"
echo "User: ${DB_USER_RADIUS}"
echo "Pass: ${DB_PASS_RADIUS}"
echo "Ports: 1812/udp (auth), 1813/udp (acct)"
echo "Logs: journalctl -u freeradius -e"
echo "=========================================="
