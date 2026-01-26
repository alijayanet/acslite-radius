<?php
/**
 * Security Headers for ACS-Lite API
 * Include this file at the top of all API files
 */

// Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// CORS Configuration - Restrict to specific domains
$allowedOrigins = [
    'http://localhost',
    'http://127.0.0.1',
    'http://localhost:7547',
    'http://localhost:8888',
    'https://localhost',
    'https://127.0.0.1'
];

// Allow environment variable to override origins
if (getenv('ALLOWED_ORIGINS')) {
    $allowedOrigins = array_merge($allowedOrigins, explode(',', getenv('ALLOWED_ORIGINS')));
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // For development, allow all origins if not in production
    if (getenv('APP_ENV') !== 'production') {
        header('Access-Control-Allow-Origin: *');
    }
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
header('Access-Control-Max-Age: 86400');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Content Security Policy for API responses
header("Content-Security-Policy: default-src 'self'; script-src 'none'; object-src 'none';");

// Remove PHP version from headers
header_remove('X-Powered-By');
header_remove('Server');
