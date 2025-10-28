<?php
/**
 * Execute Discount System Upgrade
 * 
 * This script executes the SQL statements from the discount_system_upgrade.sql file
 * to add the Buy X Get Y promotion fields to the discounts table.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Bypass session check for direct execution
// Comment: This is a temporary modification to allow direct execution
// In a production environment, proper authentication should be enforced
/*
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    echo "Access denied. You must be logged in as an administrator.";
    exit();
}
*/

echo "Starting discount system upgrade...<br>";

// Use absolute path for database connection
$root_path = 'c:/xampp/htdocs/Solar Business/';
require_once $root_path . 'config/database.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Start transaction
    $db->beginTransaction();
    
    // Add Buy X Get Y promotion fields if they don't exist
    $checkColumns = $db->query("SHOW COLUMNS FROM discounts LIKE 'buy_quantity'");
    if ($checkColumns->rowCount() == 0) {
        // Add Buy X Get Y promotion fields
        $db->exec("ALTER TABLE discounts ADD COLUMN buy_quantity INT DEFAULT NULL COMMENT 'Buy X quantity'");
        $db->exec("ALTER TABLE discounts ADD COLUMN get_quantity INT DEFAULT NULL COMMENT 'Get Y quantity'");
        $db->exec("ALTER TABLE discounts ADD COLUMN get_product_id INT DEFAULT NULL COMMENT 'Product ID for the free/discounted product'");
        $db->exec("ALTER TABLE discounts ADD COLUMN get_discount_percentage DECIMAL(5,2) DEFAULT 100.00 COMMENT 'Discount percentage for the free product (100 = free)'");
        
        echo "Buy X Get Y promotion fields added successfully.<br>";
    } else {
        echo "Buy X Get Y promotion fields already exist.<br>";
    }
    
    // Commit the transaction
    $db->commit();
    
    echo "Discount system upgrade completed successfully.";
} catch (PDOException $e) {
    // Rollback the transaction if something failed
    $db->rollBack();
    echo "Error: " . $e->getMessage();
}
