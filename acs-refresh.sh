#!/bin/bash
ACS_API="http://localhost:7547/api"
API_KEY="secret"
DEVICES=$(curl -s -H "X-API-Key: $API_KEY" "$ACS_API/devices" 2>/dev/null | grep -oP '"serial_number"\s*:\s*"\K[^"]+')
for SN in $DEVICES; do
    curl -s -X POST -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
        -d '{"name":"GetParameterValues","payload":{"parameters":["InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID","InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress","InternetGatewayDevice.DeviceInfo.X_ALU_RxPower"]}}' \
        "$ACS_API/tasks?sn=$SN" > /dev/null 2>&1
done
