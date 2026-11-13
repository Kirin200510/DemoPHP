<?php
session_start();
require_once 'controllers/StorageManagement.php';
$controller = new StorageManagement();
$controller->index();
?>