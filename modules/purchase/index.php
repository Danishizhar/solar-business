<?php
// This is a wrapper file to properly include the purchase order creation page
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include the purchase order create file
include_once 'purchase_order_create.php';
?>
