<?php
/**
 * Execute Discount Approval SQL
 * 
 * This script executes the SQL to create the discount_approval_logs table.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo "User not logged in. Please log in to continue.";
    exit();
}

// Include database connection
require_once '../../../config/database.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Read SQL file
    $sql_file = '../sql/add_discount_approval_logs.sql';
    $sql = file_get_contents($sql_file);
    
    if ($sql === false) {
        throw new Exception("Error reading SQL file: $sql_file");
    }
    
    // Execute SQL
    $result = $db->exec($sql);
    
    echo "SQL executed successfully!<br>";
    echo "Discount approval logs table created.<br>";
    echo "<a href='../../../index.php'>Return to Dashboard</a>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
