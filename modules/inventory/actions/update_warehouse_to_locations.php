<?php
// Script to update warehouse setting to locations
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'c:/xampp/htdocs/Solar Business/config/database.php';
$database = new Database();
$db = $database->getConnection();

try {
    // Update the setting_group from 'warehouse' to 'locations'
    $query = "UPDATE inventory_settings 
              SET setting_group = 'locations', 
                  display_name = 'Enable Multi-Locations', 
                  description = 'Enable multiple location management' 
              WHERE setting_key = 'enable_multi_warehouse'";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    // Check if any rows were affected
    $rowCount = $stmt->rowCount();
    
    if ($rowCount > 0) {
        echo "Setting updated successfully: Changed 'warehouse' to 'locations'";
    } else {
        echo "No settings were updated. The setting may not exist.";
    }
    
} catch (PDOException $e) {
    echo "Error updating setting: " . $e->getMessage();
    error_log("Error updating warehouse setting: " . $e->getMessage());
}
?>
