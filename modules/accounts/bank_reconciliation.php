<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Redirect to bank transactions page
header("Location: bank_transactions.php");
exit();
?>
