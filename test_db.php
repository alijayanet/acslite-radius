<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=radius', 'radius', 'radius123');
    echo 'OK';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
