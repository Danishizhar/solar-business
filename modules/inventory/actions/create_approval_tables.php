<?php
/**
 * Create Approval Tables
 * 
 * This script creates the necessary tables and columns for the discount approval system.
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
    // Start transaction
    $db->beginTransaction();
    
    // 1. Add approval_pin and user_type columns to users table
    $alter_users_query = "
        ALTER TABLE users 
        ADD COLUMN IF NOT EXISTS approval_pin VARCHAR(10) NULL AFTER password,
        ADD COLUMN IF NOT EXISTS user_type ENUM('admin', 'manager', 'staff', 'user') NOT NULL DEFAULT 'user' AFTER approval_pin
    ";
    
    // 2. Create discount_approval_logs table
    $create_logs_table_query = "
        CREATE TABLE IF NOT EXISTS `discount_approval_logs` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `discount_id` int(11) NOT NULL,
          `approved_by` int(11) NOT NULL,
          `approved_at` datetime NOT NULL,
          `ip_address` varchar(45) DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `fk_discount_approval_logs_discount` (`discount_id`),
          KEY `fk_discount_approval_logs_user` (`approved_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    
    // Execute queries
    $db->exec($alter_users_query);
    echo "✓ Added approval_pin and user_type columns to users table<br>";
    
    $db->exec($create_logs_table_query);
    echo "✓ Created discount_approval_logs table<br>";
    
    // Set user_type based on role_id
    $update_user_type_query = "
        UPDATE users u
        JOIN roles r ON u.role_id = r.id
        SET u.user_type = 
            CASE 
                WHEN r.role_name = 'Admin' THEN 'admin'
                WHEN r.role_name = 'Manager' THEN 'manager'
                WHEN r.role_name = 'Staff' THEN 'staff'
                ELSE 'user'
            END
    ";
    
    $db->exec($update_user_type_query);
    echo "✓ Updated user_type values based on roles<br>";
    
    // Commit transaction
    $db->commit();
    
    echo "<p style='color:green; font-weight:bold;'>All tables and columns created successfully!</p>";
    echo "<p>You can now set approval PINs for admin and manager users in their profile page.</p>";
    echo "<a href='../../../index.php' class='btn btn-primary'>Return to Dashboard</a>";
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo "<p style='color:red; font-weight:bold;'>Error: " . $e->getMessage() . "</p>";
    echo "<a href='../../../index.php'>Return to Dashboard</a>";
}
?>
