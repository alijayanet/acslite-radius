<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'status';
include __DIR__ . '/api/radius_api.php';
?>
