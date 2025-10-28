<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo "Please log in to access this page.";
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check if inventory_settings table exists
try {
    $check_table_query = "SHOW TABLES LIKE 'inventory_settings'";
    $check_table_stmt = $db->prepare($check_table_query);
    $check_table_stmt->execute();
    
    if ($check_table_stmt->rowCount() == 0) {
        echo "Inventory settings table does not exist.<br>";
        
        // Create the table
        $create_table_query = "
            CREATE TABLE IF NOT EXISTS `inventory_settings` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `setting_key` VARCHAR(50) NOT NULL UNIQUE,
                `setting_value` TEXT,
                `setting_group` VARCHAR(50) NOT NULL,
                `display_name` VARCHAR(100) NOT NULL,
                `description` TEXT,
                `input_type` VARCHAR(20) NOT NULL DEFAULT 'text',
                `input_options` TEXT,
                `display_order` INT DEFAULT 0,
                `status` TINYINT(1) DEFAULT 1,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
                `created_by` INT,
                `updated_by` INT
            )";
        $db->exec($create_table_query);
        echo "Inventory settings table created.<br>";
    } else {
        echo "Inventory settings table exists.<br>";
    }
    
    // Check if barcode settings exist
    $check_settings_query = "SELECT * FROM inventory_settings WHERE setting_key LIKE '%barcode%'";
    $check_settings_stmt = $db->prepare($check_settings_query);
    $check_settings_stmt->execute();
    $barcode_settings = $check_settings_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Existing Barcode Settings:</h3>";
    if (count($barcode_settings) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Setting Key</th><th>Setting Value</th><th>Group</th><th>Display Name</th></tr>";
        foreach ($barcode_settings as $setting) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($setting['setting_key']) . "</td>";
            echo "<td>" . htmlspecialchars($setting['setting_value']) . "</td>";
            echo "<td>" . htmlspecialchars($setting['setting_group']) . "</td>";
            echo "<td>" . htmlspecialchars($setting['display_name']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No barcode settings found.<br>";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
}
?>
