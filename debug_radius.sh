#!/bin/bash

echo "=============================================="
echo "RADIUS Server Diagnostic Script"
echo "=============================================="
echo ""

echo "[1] FreeRADIUS Service Status:"
systemctl status freeradius --no-pager -l
echo ""

echo "[2] FreeRADIUS Ports (1812/1813):"
netstat -tulpn | grep -E '1812|1813'
echo ""

echo "[3] NAS Clients Configuration:"
echo "=== /etc/freeradius/3.0/clients.conf ==="
cat /etc/freeradius/3.0/clients.conf 2>/dev/null || cat /etc/freeradius/clients.conf 2>/dev/null || cat /etc/raddb/clients.conf 2>/dev/null
echo ""
echo "=== /etc/freeradius/3.0/clients-acs-lite.conf ==="
cat /etc/freeradius/3.0/clients-acs-lite.conf 2>/dev/null || echo "File not found"
echo ""

echo "[4] RADIUS Database Check:"
echo "=== radcheck table (users) ==="
mysql -u radius -pradius123 -D radius -e "SELECT * FROM radcheck LIMIT 10" 2>/dev/null || echo "Failed to connect to DB"
echo ""
echo "=== radreply table (attributes) ==="
mysql -u radius -pradius123 -D radius -e "SELECT * FROM radreply LIMIT 10" 2>/dev/null
echo ""
echo "=== radacct table (active sessions) ==="
mysql -u radius -pradius123 -D radius -e "SELECT username, nasipaddress, framedipaddress, acctstarttime FROM radacct WHERE acctstoptime IS NULL" 2>/dev/null
echo ""

echo "[5] FreeRADIUS SQL Module Configuration:"
cat /etc/freeradius/3.0/mods-enabled/sql 2>/dev/null | grep -A 5 -E "dialect|server|port|login|password|radius_db" || echo "SQL module not found"
echo ""

echo "[6] Firewall Rules (iptables):"
iptables -L -n -v | grep -E '1812|1813' || echo "No specific firewall rules for RADIUS ports"
echo ""

echo "[7] Recent FreeRADIUS Log (last 50 lines):"
journalctl -u freeradius -n 50 --no-pager
echo ""

echo "[8] Network Connectivity Test:"
echo "Server IP addresses:"
ip addr show | grep 'inet ' | grep -v '127.0.0.1'
echo ""

echo "=============================================="
echo "Diagnostic Complete!"
echo "=============================================="
echo ""
echo "TROUBLESHOOTING TIPS:"
echo "1. If no logs appear in [7], request might not be reaching FreeRADIUS"
echo "2. Check if MikroTik IP is in NAS Clients list [3]"
echo "3. Verify MikroTik RADIUS secret matches the one in clients config"
echo "4. Ensure users exist in radcheck table [4]"
echo "5. Check if firewall is blocking ports 1812/1813"
echo ""
echo "To monitor RADIUS traffic in real-time, run:"
echo "  tcpdump -i any -n port 1812 or port 1813"
echo ""
echo "To test with radtest (if available):"
echo "  radtest <username> <password> localhost 0 testing123"
echo ""
