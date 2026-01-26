<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=acs', 'root', 'secret123');
    echo 'Connected to ACS DB';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
