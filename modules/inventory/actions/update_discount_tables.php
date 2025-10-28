<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get the SQL file content
    $sql_file = file_get_contents('../sql/discount_tables_update_application.sql');
    
    // Execute the SQL statements
    $db->exec($sql_file);
    
    // Set success message
    $_SESSION['success_message'] = "Discount tables updated successfully with discount application field.";
} catch (PDOException $e) {
    // Set error message
    $_SESSION['error_message'] = "Error updating discount tables: " . $e->getMessage();
}

// Redirect back to discounts page
header("Location: ../discounts.php");
exit();
?>
