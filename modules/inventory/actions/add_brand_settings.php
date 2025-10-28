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

// Define brand settings to add
$brand_settings = [
    [
        'setting_group' => 'brands',
        'setting_key' => 'auto_generate_brand_code',
        'setting_value' => '1',
        'setting_description' => 'Automatically generate brand codes',
        'setting_type' => 'boolean',
        'setting_options' => '0,1',
        'display_order' => 1
    ],
    [
        'setting_group' => 'brands',
        'setting_key' => 'brand_code_prefix',
        'setting_value' => 'BRD-',
        'setting_description' => 'Prefix for auto-generated brand codes',
        'setting_type' => 'text',
        'setting_options' => '',
        'display_order' => 2
    ],
    [
        'setting_group' => 'brands',
        'setting_key' => 'brand_types',
        'setting_value' => 'Standard,Premium,Economy',
        'setting_description' => 'Available brand types (comma-separated)',
        'setting_type' => 'text',
        'setting_options' => '',
        'display_order' => 3
    ],
    [
        'setting_group' => 'brands',
        'setting_key' => 'default_brand_type',
        'setting_value' => 'Standard',
        'setting_description' => 'Default brand type for new brands',
        'setting_type' => 'select',
        'setting_options' => 'Standard,Premium,Economy',
        'display_order' => 4
    ],
    [
        'setting_group' => 'brands',
        'setting_key' => 'require_brand_logo',
        'setting_value' => '0',
        'setting_description' => 'Require brand logo when adding/editing brands',
        'setting_type' => 'boolean',
        'setting_options' => '0,1',
        'display_order' => 5
    ],
    [
        'setting_group' => 'brands',
        'setting_key' => 'max_logo_file_size',
        'setting_value' => '2',
        'setting_description' => 'Maximum logo file size in MB',
        'setting_type' => 'number',
        'setting_options' => '1,2,5,10',
        'display_order' => 6
    ]
];

echo "Starting insertion of brand settings...\n\n";

try {
    $db->beginTransaction();
    
    // Check if brand settings already exist
    $check_query = "SELECT COUNT(*) as count FROM inventory_settings WHERE setting_group = 'brands'";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute();
    $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        echo "Brand settings already exist. Skipping insertion.\n";
    } else {
        // First, check if we need to add a brand_type column to the brands table
        $column_check_query = "SHOW COLUMNS FROM brands LIKE 'brand_type'";
        $column_check_stmt = $db->prepare($column_check_query);
        $column_check_stmt->execute();
        
        if ($column_check_stmt->rowCount() === 0) {
            // Add brand_type column to brands table
            $add_column_query = "ALTER TABLE brands ADD COLUMN brand_type VARCHAR(50) DEFAULT 'Standard' AFTER brand_name";
            $db->exec($add_column_query);
            echo "Added brand_type column to brands table.\n\n";
        }
        
        // Insert settings
        $insert_query = "INSERT INTO inventory_settings 
                        (setting_group, setting_key, setting_value, setting_description, setting_type, setting_options, display_order) 
                        VALUES 
                        (:setting_group, :setting_key, :setting_value, :setting_description, :setting_type, :setting_options, :display_order)";
        
        $insert_stmt = $db->prepare($insert_query);
        $count = 0;
        
        foreach ($brand_settings as $setting) {
            $insert_stmt->bindParam(':setting_group', $setting['setting_group'], PDO::PARAM_STR);
            $insert_stmt->bindParam(':setting_key', $setting['setting_key'], PDO::PARAM_STR);
            $insert_stmt->bindParam(':setting_value', $setting['setting_value'], PDO::PARAM_STR);
            $insert_stmt->bindParam(':setting_description', $setting['setting_description'], PDO::PARAM_STR);
            $insert_stmt->bindParam(':setting_type', $setting['setting_type'], PDO::PARAM_STR);
            $insert_stmt->bindParam(':setting_options', $setting['setting_options'], PDO::PARAM_STR);
            $insert_stmt->bindParam(':display_order', $setting['display_order'], PDO::PARAM_INT);
            
            $insert_stmt->execute();
            $count++;
            
            echo "Added setting: " . $setting['setting_key'] . "\n";
        }
        
        echo "\nAdded $count brand settings successfully.\n";
    }
    
    $db->commit();
    echo "\nDone.\n";
} catch (PDOException $e) {
    $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    error_log("Database error in add_brand_settings.php: " . $e->getMessage());
} catch (Exception $e) {
    $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    error_log("Error in add_brand_settings.php: " . $e->getMessage());
}
