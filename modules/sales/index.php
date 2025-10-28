<?php
// This is a wrapper file to properly include the sales order creation page
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include the sales order create file
include_once 'sales_order_create.php';
?>
