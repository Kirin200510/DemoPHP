<?php
session_start();
require_once 'controllers/StorageManagementController.php';
$controller = new StorageManagementController();
$controller->index();
?>