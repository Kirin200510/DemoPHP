<?php
session_start();
require_once 'controllers/OrderAdvanceController.php';
$controller = new OrderAdvanceController();
$controller->index();
?>