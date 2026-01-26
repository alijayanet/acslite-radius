# FreeRADIUS Installation & Configuration

## Quick Install (One Command)

```bash
sudo ./install_radius.sh
```

Script ini akan otomatis:
- ✅ Install FreeRADIUS dan dependencies
- ✅ Buat database `radius` di MySQL
- ✅ Load SQL schema
- ✅ Konfigurasi FreeRADIUS dengan semua fix production-ready
- ✅ Enable IPv4/IPv6 listening
- ✅ Fix DateTime queries
- ✅ Setup auto-cleanup orphaned sessions
- ✅ Start dan enable FreeRADIUS service

## Features

### 1. **Authentication & Accounting**
   - MySQL backend untuk radcheck, radreply, radacct
   - Support PPPoE dan Hotspot
   - Real-time session tracking

### 2. **IPv4/IPv6 Support**
   - Listen di semua interface (`ipaddr = *`)
   - Support IPv4 dan IPv6 client

### 3. **DateTime Query Fix**
   - Otomatis convert Unix timestamp ke DATETIME dengan FROM_UNIXTIME()
   - Tidak ada lagi error 1292

### 4. **Auto-Cleanup Orphaned Sessions**
   - Cron job berjalan tiap jam
   - Close session yang sudah 4 jam tidak update
   - Log di `/var/log/radius_cleanup.log`

## Manual Configuration

Jika ingin konfigurasi manual atau re-run konfigurasi:

```bash
sudo ./configure_freeradius_sql.sh
```

## Verify Installation

### 1. Check Service Status
```bash
systemctl status freeradius
```

### 2. Check Listening Ports
```bash
netstat -ulpn | grep -E '1812|1813'
```

Harusnya muncul:
```
udp   0   0   0.0.0.0:1812   ...   freeradius
udp   0   0   0.0.0.0:1813   ...   freeradius
```

### 3. Test Database Connection
```bash
mysql -u radius -pradius123 -D radius -e "SELECT COUNT(*) FROM radcheck"
```

### 4. Test Authentication
```bash
# Tambah user test dulu
mysql -u radius -pradius123 -D radius << EOF
INSERT INTO radcheck (username, attribute, op, value) 
VALUES ('testuser', 'Cleartext-Password', ':=', 'testpass');
EOF

# Test
radtest testuser testpass localhost 0 testing123
```

Harusnya dapat `Access-Accept`

## Configuration Files

### Important Locations:
- **Config Dir:** `/etc/freeradius/3.0/`
- **SQL Module:** `/etc/freeradius/3.0/mods-available/sql`
- **Default Site:** `/etc/freeradius/3.0/sites-enabled/default`
- **Queries:** `/etc/freeradius/3.0/mods-config/sql/main/mysql/queries.conf`
- **Clients:** `/etc/freeradius/3.0/clients-acs-lite.conf`

### Database:
- **Name:** `radius`
- **User:** `radius`
- **Password:** `radius123`
- **Tables:** radcheck, radreply, radacct

## Add MikroTik as NAS Client

Via web interface (`radius.html`):
1. Go to **NAS Clients** tab
2. Click **Add Client**
3. Fill:
   - Name: `mikrotik-1`
   - IP/CIDR: `192.168.8.1/32` atau `192.168.8.0/24`
   - Secret: `testing123`
4. Click **Apply to FreeRADIUS**

Or via command line:
```bash
cat >> /etc/freeradius/3.0/clients-acs-lite.conf << EOF
client mikrotik {
    ipaddr = 192.168.8.0/24
    secret = testing123
    nastype = mikrotik
}
EOF

systemctl restart freeradius
```

## MikroTik Configuration

### 1. Add RADIUS Server
```
/radius add \
  service=ppp \
  address=192.168.8.126:1812,1813 \
  secret=testing123 \
  timeout=3s
```

### 2. Enable RADIUS for PPPoE
```
/ppp aaa set \
  use-radius=yes \
  accounting=yes \
  interim-update=1m
```

### 3. Verify
```
/radius print detail
/ppp aaa print
```

## Monitoring

### Active Sessions
```bash
mysql -u radius -pradius123 -D radius -e "
SELECT username, framedipaddress, nasipaddress, acctstarttime, acctsessiontime
FROM radacct WHERE acctstoptime IS NULL"
```

### Statistics Today
```bash
mysql -u radius -pradius123 -D radius -e "
SELECT COUNT(*) as sessions, 
       COUNT(DISTINCT username) as unique_users,
       SUM(acctinputoctets) as total_download,
       SUM(acctoutputoctets) as total_upload
FROM radacct WHERE DATE(acctstarttime) = CURDATE()"
```

### Logs
```bash
# Real-time
journalctl -u freeradius -f

# Last 50 lines
journalctl -u freeradius -n 50

# Or file-based
tail -f /var/log/freeradius/radius.log
```

## Troubleshooting

### 1. FreeRADIUS Not Starting
```bash
# Check config
freeradius -CX

# Run in debug mode
systemctl stop freeradius
freeradius -X
```

### 2. Authentication Timeout from MikroTik
```bash
# Check firewall
iptables -L INPUT -n -v | grep -E '1812|1813'

# Allow ports
iptables -I INPUT -p udp --dport 1812 -j ACCEPT
iptables -I INPUT -p udp --dport 1813 -j ACCEPT

# Monitor traffic
tcpdump -i any -n port 1812 or port 1813 -v
```

### 3. Accounting Data Not Saved
```bash
# Verify SQL enabled in accounting section
grep -A 5 "accounting {" /etc/freeradius/3.0/sites-enabled/default

# Should show:
# accounting {
#     sql
#     ...
# }
```

### 4. DateTime Error 1292
```bash
# Check queries.conf has FROM_UNIXTIME()
grep "acctstarttime.*FROM_UNIXTIME" /etc/freeradius/3.0/mods-config/sql/main/mysql/queries.conf

# If not, re-run configure script
sudo ./configure_freeradius_sql.sh
```

## Backup & Restore

### Backup
```bash
# Database
mysqldump -u radius -pradius123 radius > radius_backup_$(date +%Y%m%d).sql

# Configs
tar czf freeradius_config_$(date +%Y%m%d).tar.gz /etc/freeradius/3.0/
```

### Restore
```bash
# Database
mysql -u radius -pradius123 radius < radius_backup_20260103.sql

# Configs
tar xzf freeradius_config_20260103.tar.gz -C /
systemctl restart freeradius
```

## Performance Tuning

### Max Connections
Edit `/etc/freeradius/3.0/radiusd.conf`:
```
max_requests = 16384
max_request_time = 30
cleanup_delay = 5
```

### Database Connection Pool
Edit `/etc/freeradius/3.0/mods-available/sql`:
```
pool {
    start = 5
    min = 3
    max = 32
    spare = 10
    uses = 0
    lifetime = 0
    idle_timeout = 60
}
```

## Security

### 1. Change Default Password
```bash
# MySQL
ALTER USER 'radius'@'localhost' IDENTIFIED BY 'new_strong_password';

# Update in SQL module
nano /etc/freeradius/3.0/mods-available/sql
# Change password = "radius123" to password = "new_strong_password"

systemctl restart freeradius
```

### 2. Change NAS Secret
Edit `/etc/freeradius/3.0/clients-acs-lite.conf` and use strong secret (min 16 chars)

### 3. Firewall Rules
```bash
# Only allow from MikroTik IP
iptables -A INPUT -p udp --dport 1812 -s 192.168.8.1 -j ACCEPT
iptables -A INPUT -p udp --dport 1813 -s 192.168.8.1 -j ACCEPT
iptables -A INPUT -p udp --dport 1812 -j DROP
iptables -A INPUT -p udp --dport 1813 -j DROP
```

## Support

- **Web Dashboard:** `http://server-ip:8888/templates/radius.html`
- **Logs:** `/var/log/freeradius/radius.log`
- **Journal:** `journalctl -u freeradius`
- **Debug Mode:** `freeradius -X`

---

**Version:** Enhanced v2.0 (2026-01-03)  
**Tested on:** Debian 11, Ubuntu 20.04/22.04, CentOS 7/8  
**FreeRADIUS:** 3.0.x
