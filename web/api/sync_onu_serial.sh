#!/bin/bash

# Script Auto-Sync Serial Number ONU dari GenieACS ke Tabel Customers
# Mencocokkan berdasarkan PPPoE Username

echo "=================================="
echo "Auto-Sync ONU Serial to Customers"
echo "=================================="
echo ""

# Database Configuration
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="acs"
DB_USER="root"
DB_PASS=""

# Coba baca dari .env jika ada
if [ -f "/opt/acs/.env" ]; then
    echo "Loading database config from .env..."
    # Extract DB credentials
    DB_DSN=$(grep "DB_DSN=" /opt/acs/.env | cut -d'=' -f2)
    if [ ! -z "$DB_DSN" ]; then
        # Parse DSN format: user:pass@tcp(host:port)/dbname
        DB_USER=$(echo $DB_DSN | cut -d':' -f1)
        DB_PASS=$(echo $DB_DSN | cut -d':' -f2 | cut -d'@' -f1)
        DB_HOST=$(echo $DB_DSN | cut -d'(' -f2 | cut -d':' -f1)
        DB_PORT=$(echo $DB_DSN | cut -d':' -f3 | cut -d')' -f1)
        DB_NAME=$(echo $DB_DSN | cut -d'/' -f2 | cut -d'?' -f1)
    fi
fi

# GenieACS API Configuration
GENIE_HOST="localhost"
GENIE_PORT="7547"
GENIE_API="http://${GENIE_HOST}:${GENIE_PORT}/devices"

echo "GenieACS API: $GENIE_API"
echo "Database: ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
echo ""

# Function: Get all devices from GenieACS
get_all_devices() {
    curl -s "$GENIE_API?projection=_id,InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username" 2>/dev/null || echo "[]"
}

# Function: Update customer onu_serial
update_customer_serial() {
    local pppoe_user="$1"
    local serial="$2"
    
    mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" <<EOF 2>/dev/null
UPDATE customers 
SET onu_serial = '$serial' 
WHERE pppoe_username = '$pppoe_user' AND (onu_serial IS NULL OR onu_serial = '');
EOF
    
    if [ $? -eq 0 ]; then
        echo "✓ Updated: $pppoe_user -> $serial"
        return 0
    else
        return 1
    fi
}

# Main Process
echo "Fetching devices from GenieACS..."
devices=$(get_all_devices)

if [ "$devices" == "[]" ] || [ -z "$devices" ]; then
    echo "❌ No devices found or GenieACS is not accessible"
    exit 1
fi

echo "Processing devices..."
echo ""

updated_count=0
skipped_count=0

# Parse JSON using jq (if available) or basic grep/awk
if command -v jq &> /dev/null; then
    # Use jq for proper JSON parsing
    echo "$devices" | jq -r '.[] | "\(._id)|\(.["InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username"])"' | while IFS='|' read -r serial pppoe; do
        if [ ! -z "$pppoe" ] && [ "$pppoe" != "null" ]; then
            if update_customer_serial "$pppoe" "$serial"; then
                ((updated_count++))
            else
                ((skipped_count++))
            fi
        else
            echo "⊘ Skipped: $serial (No PPPoE username)"
            ((skipped_count++))
        fi
    done
else
    # Fallback: Simple grep parsing (less reliable)
    echo "⚠ jq not found, using basic parsing (install jq for better results)"
    echo "$devices" | grep -o '"_id":"[^"]*"' | cut -d'"' -f4 | while read serial; do
        # Try to find PPPoE username for this device
        pppoe=$(echo "$devices" | grep -A 5 "\"_id\":\"$serial\"" | grep -o '"Username":{"_value":"[^"]*"' | cut -d'"' -f7 | head -1)
        
        if [ ! -z "$pppoe" ]; then
            if update_customer_serial "$pppoe" "$serial"; then
                ((updated_count++))
            else
                ((skipped_count++))
            fi
        else
            echo "⊘ Skipped: $serial (No PPPoE username)"
            ((skipped_count++))
        fi
    done
fi

echo ""
echo "=================================="
echo "Sync completed!"
echo "Updated: $updated_count"
echo "Skipped: $skipped_count"
echo "=================================="
