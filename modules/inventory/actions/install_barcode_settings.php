<?php
/**
 * Install Barcode Settings
 * This script adds barcode settings to the inventory_settings table
 */
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

$response = ['success' => true, 'messages' => []];

try {
    // Check if inventory_settings table exists
    $check_table_query = "SHOW TABLES LIKE 'inventory_settings'";
    $check_table_stmt = $db->prepare($check_table_query);
    $check_table_stmt->execute();
    
    if ($check_table_stmt->rowCount() == 0) {
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
        $response['messages'][] = "Inventory settings table created.";
    }
    
    // Read and execute the SQL script
    $sql_file = file_get_contents('../sql/add_barcode_settings.sql');
    if ($sql_file) {
        // Split into individual statements
        $statements = explode(';', $sql_file);
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $db->exec($statement);
            }
        }
        
        $response['messages'][] = "Barcode settings installed successfully.";
    } else {
        $response['success'] = false;
        $response['messages'][] = "Could not read SQL file.";
    }
    
    // Check if barcode settings were added
    $check_settings_query = "SELECT COUNT(*) as count FROM inventory_settings WHERE setting_group = 'barcode'";
    $check_settings_stmt = $db->prepare($check_settings_query);
    $check_settings_stmt->execute();
    $result = $check_settings_stmt->fetch(PDO::FETCH_ASSOC);
    
    $response['messages'][] = "Found " . $result['count'] . " barcode settings.";
    
} catch (PDOException $e) {
    $response['success'] = false;
    $response['messages'][] = "Database error: " . $e->getMessage();
    error_log("Error installing barcode settings: " . $e->getMessage());
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
