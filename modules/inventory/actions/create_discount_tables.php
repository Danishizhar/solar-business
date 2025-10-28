<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$db = $database->getConnection();

try {
    // Read the SQL file
    $sql = file_get_contents('../sql/discount_tables.sql');
    
    // Execute the SQL commands
    $db->exec($sql);
    
    $_SESSION['success_message'] = "Discount tables created successfully.";
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error creating discount tables: " . $e->getMessage();
    error_log("Error creating discount tables: " . $e->getMessage());
}

// Redirect back to the settings page
header("Location: ../settings.php?tab=discount");
exit();
?>
