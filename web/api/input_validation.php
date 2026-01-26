<?php
/**
 * Input Validation Helper
 * Centralized validation functions for ACS-Lite API
 */

/**
 * Sanitize string input
 */
function sanitizeString($input, $maxLength = 255) {
    $input = trim((string)$input);
    if (strlen($input) > $maxLength) {
        $input = substr($input, 0, $maxLength);
    }
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email address
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate positive integer
 */
function validatePositiveInt($value, $min = 1, $max = PHP_INT_MAX) {
    $value = (int)$value;
    return $value >= $min && $value <= $max;
}

/**
 * Validate boolean value
 */
function validateBool($value) {
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null;
}

/**
 * Validate table name (whitelist approach)
 */
function validateTableName($table, $allowedTables = []) {
    if (empty($allowedTables)) {
        $allowedTables = [
            'customers', 'packages', 'invoices', 'payments',
            'hotspot_vouchers', 'hotspot_profiles', 'voucher_batches', 'hotspot_sales',
            'onu_locations', 'telegram_config', 'telegram_admins',
            'settings', 'nas', 'radcheck', 'radreply', 'radacct',
            'radgroupcheck', 'radgroupreply', 'radusergroup', 'radpostauth'
        ];
    }
    return in_array($table, $allowedTables);
}

/**
 * Validate column name
 */
function validateColumnName($name) {
    return preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name) === 1;
}

/**
 * Validate limit parameter for pagination
 */
function validateLimit($limit, $default = 100, $max = 1000) {
    $limit = (int)$limit;
    if ($limit < 1) return $default;
    if ($limit > $max) return $max;
    return $limit;
}

/**
 * Validate offset parameter for pagination
 */
function validateOffset($offset) {
    return max(0, (int)$offset);
}

/**
 * Validate date format
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Validate date range
 */
function validateDateRange($startDate, $endDate, $format = 'Y-m-d') {
    if (!validateDate($startDate, $format) || !validateDate($endDate, $format)) {
        return false;
    }
    return strtotime($startDate) <= strtotime($endDate);
}

/**
 * Validate phone number (basic validation)
 */
function validatePhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return strlen($phone) >= 10 && strlen($phone) <= 15;
}

/**
 * Validate password strength
 */
function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return ['valid' => false, 'error' => 'Password must be at least 8 characters'];
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'error' => 'Password must contain at least one uppercase letter'];
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        return ['valid' => false, 'error' => 'Password must contain at least one lowercase letter'];
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'error' => 'Password must contain at least one number'];
    }
    
    return ['valid' => true];
}

/**
 * Validate username (alphanumeric, underscore, hyphen)
 */
function validateUsername($username) {
    return preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $username) === 1;
}

/**
 * Validate serial number format
 */
function validateSerialNumber($serial) {
    return preg_match('/^[A-Z0-9]{10,20}$/', $serial) === 1;
}

/**
 * Sanitize array of strings
 */
function sanitizeArray(array $array, $maxLength = 255) {
    return array_map(function($item) use ($maxLength) {
        return sanitizeString($item, $maxLength);
    }, $array);
}

/**
 * Validate required fields
 */
function validateRequired($fields, $data) {
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $missing[] = $field;
        }
    }
    return $missing;
}

/**
 * Validate enum value
 */
function validateEnum($value, array $allowedValues) {
    return in_array($value, $allowedValues, true);
}

/**
 * Sanitize SQL LIKE pattern (prevent SQL injection in LIKE queries)
 */
function sanitizeLikePattern($pattern) {
    $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pattern);
    return $pattern;
}

/**
 * Validate IP address
 */
function validateIP($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

/**
 * Validate port number
 */
function validatePort($port) {
    $port = (int)$port;
    return $port >= 1 && $port <= 65535;
}

/**
 * Validate URL
 */
function validateURL($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Sanitize JSON input
 */
function sanitizeJSONInput($input) {
    $decoded = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    return $decoded;
}
