<?php
/**
 * MikroTik Configuration
 * Contains MikroTik router connection details
 */

$mtConfig = [
    'host' => '192.168.8.1',        // MikroTik IP Address
    'user' => 'admin',              // MikroTik username
    'password' => '1234',       // MikroTik password
    'port' => 8728                  // API port (default 8728)
];

return $mtConfig;
