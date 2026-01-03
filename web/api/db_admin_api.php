<?php
/**
 * ACS-Lite Database Admin API
 * 
 * Endpoints:
 * - GET ?action=tables         - List all tables
 * - GET ?action=describe&table=xxx  - Describe table structure
 * - GET ?action=select&table=xxx    - Get all data from table
 * - POST action=query         - Execute custom SQL query
 * - POST action=insert        - Insert data into table
 * - POST action=create_table  - Create new table
 */

ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ========================================
// DATABASE CONFIG
// ========================================
function getConfig() {
    $config = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'acs',
        'username' => 'root',
        'password' => 'secret123'
    ];
    
    // Try to load from .env
    $envPaths = ['/opt/acs/.env', __DIR__ . '/../../.env'];
    foreach ($envPaths as $envFile) {
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            if (preg_match('/DB_DSN=([^:]+):([^@]+)@tcp\(([^:]+):(\d+)\)\/([^?\s]+)/', $envContent, $matches)) {
                $config['username'] = $matches[1];
                $config['password'] = $matches[2];
                $config['host'] = $matches[3];
                $config['port'] = (int)$matches[4];
                $config['dbname'] = $matches[5];
            }
            break;
        }
    }
    return $config;
}

function getDB() {
    $config = getConfig();
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// ========================================
// MAIN HANDLER
// ========================================
$db = getDB();
if (!$db) {
    jsonResponse(['success' => false, 'error' => 'Database connection failed'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Parse JSON body for POST
$input = [];
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    $action = $input['action'] ?? $action;
}

try {
    switch ($action) {
        // ---- LIST TABLES ----
        case 'tables':
            $stmt = $db->query("SHOW TABLES");
            $tables = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tableName = $row[0];
                // Get row count
                $countStmt = $db->query("SELECT COUNT(*) as cnt FROM `$tableName`");
                $count = $countStmt->fetch()['cnt'];
                $tables[] = ['name' => $tableName, 'rows' => (int)$count];
            }
            jsonResponse(['success' => true, 'tables' => $tables]);
            break;

        // ---- DESCRIBE TABLE ----
        case 'describe':
            $table = $_GET['table'] ?? $input['table'] ?? '';
            if (!$table) {
                jsonResponse(['success' => false, 'error' => 'Table name required'], 400);
            }
            $stmt = $db->query("DESCRIBE `$table`");
            $columns = $stmt->fetchAll();
            jsonResponse(['success' => true, 'table' => $table, 'columns' => $columns]);
            break;

        // ---- SELECT ALL FROM TABLE ----
        case 'select':
            $table = $_GET['table'] ?? $input['table'] ?? '';
            $limit = (int)($_GET['limit'] ?? $input['limit'] ?? 1000);
            if (!$table) {
                jsonResponse(['success' => false, 'error' => 'Table name required'], 400);
            }
            $stmt = $db->query("SELECT * FROM `$table` LIMIT $limit");
            $data = $stmt->fetchAll();
            jsonResponse(['success' => true, 'table' => $table, 'data' => $data, 'count' => count($data)]);
            break;

        // ---- EXECUTE CUSTOM QUERY ----
        case 'query':
            $sql = $input['sql'] ?? '';
            if (!$sql) {
                jsonResponse(['success' => false, 'error' => 'SQL query required'], 400);
            }
            
            // Security check - only allow SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER
            $sqlLower = strtolower(trim($sql));
            $allowed = ['select', 'insert', 'update', 'delete', 'create', 'alter', 'describe', 'show'];
            $isAllowed = false;
            foreach ($allowed as $cmd) {
                if (strpos($sqlLower, $cmd) === 0) {
                    $isAllowed = true;
                    break;
                }
            }
            if (!$isAllowed) {
                jsonResponse(['success' => false, 'error' => 'Query type not allowed'], 403);
            }
            
            $stmt = $db->query($sql);
            
            // Check if it's a SELECT query
            if (strpos($sqlLower, 'select') === 0 || strpos($sqlLower, 'show') === 0 || strpos($sqlLower, 'describe') === 0) {
                $data = $stmt->fetchAll();
                jsonResponse(['success' => true, 'data' => $data, 'count' => count($data)]);
            } else {
                $affected = $stmt->rowCount();
                jsonResponse(['success' => true, 'message' => "Query executed. Rows affected: $affected", 'affected' => $affected]);
            }
            break;

        // ---- INSERT DATA ----
        case 'insert':
            $table = $input['table'] ?? '';
            $data = $input['data'] ?? [];
            if (!$table || empty($data)) {
                jsonResponse(['success' => false, 'error' => 'Table and data required'], 400);
            }
            
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');
            $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($data));
            
            jsonResponse(['success' => true, 'message' => 'Data inserted', 'id' => $db->lastInsertId()]);
            break;

        // ---- CREATE TABLE ----
        case 'create_table':
            $tableName = $input['table_name'] ?? '';
            $columns = $input['columns'] ?? [];
            
            if (!$tableName || empty($columns)) {
                jsonResponse(['success' => false, 'error' => 'Table name and columns required'], 400);
            }
            
            // Build CREATE TABLE SQL
            $colDefs = [];
            foreach ($columns as $col) {
                $name = $col['name'] ?? '';
                $type = $col['type'] ?? 'VARCHAR(255)';
                $nullable = ($col['nullable'] ?? true) ? '' : 'NOT NULL';
                $default = isset($col['default']) ? "DEFAULT '{$col['default']}'" : '';
                $primary = ($col['primary'] ?? false) ? 'PRIMARY KEY AUTO_INCREMENT' : '';
                
                if ($name) {
                    $colDefs[] = "`$name` $type $nullable $default $primary";
                }
            }
            
            if (empty($colDefs)) {
                jsonResponse(['success' => false, 'error' => 'No valid columns defined'], 400);
            }
            
            $sql = "CREATE TABLE IF NOT EXISTS `$tableName` (\n  " . implode(",\n  ", $colDefs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $db->exec($sql);
            jsonResponse(['success' => true, 'message' => "Table '$tableName' created", 'sql' => $sql]);
            break;

        // ---- DELETE ROW ----
        case 'delete':
            $table = $input['table'] ?? '';
            $where = $input['where'] ?? [];
            
            if (!$table || empty($where)) {
                jsonResponse(['success' => false, 'error' => 'Table and where conditions required'], 400);
            }
            
            $conditions = [];
            $values = [];
            foreach ($where as $col => $val) {
                $conditions[] = "`$col` = ?";
                $values[] = $val;
            }
            
            $sql = "DELETE FROM `$table` WHERE " . implode(' AND ', $conditions);
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
            
            jsonResponse(['success' => true, 'message' => 'Row(s) deleted', 'affected' => $stmt->rowCount()]);
            break;

        default:
            jsonResponse([
                'success' => true, 
                'message' => 'ACS Database Admin API',
                'endpoints' => [
                    'GET ?action=tables' => 'List all tables',
                    'GET ?action=describe&table=xxx' => 'Describe table structure',
                    'GET ?action=select&table=xxx' => 'Get data from table',
                    'POST action=query, sql=xxx' => 'Execute SQL query',
                    'POST action=insert, table=xxx, data={...}' => 'Insert row',
                    'POST action=create_table, table_name=xxx, columns=[...]' => 'Create table',
                    'POST action=delete, table=xxx, where={...}' => 'Delete row(s)'
                ]
            ]);
    }
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
