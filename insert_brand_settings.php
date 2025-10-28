<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

echo "Starting insertion of brand settings...\n\n";

try {
    
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
        
        // Insert settings
        $insert_query = "INSERT INTO inventory_settings 
                        (setting_group, setting_key, setting_value, display_name, description, input_type, options, display_order, status, created_at, updated_at) 
                        VALUES 
                        (:setting_group, :setting_key, :setting_value, :display_name, :description, :input_type, :options, :display_order, 1, NOW(), NOW())";
        
        $insert_stmt = $db->prepare($insert_query);
        $count = 0;
        
        foreach ($brand_settings as $setting) {
            $setting_group = $setting['setting_group'];
            $setting_key = $setting['setting_key'];
            $setting_value = $setting['setting_value'];
            $display_name = ucwords(str_replace('_', ' ', $setting['setting_key']));
            $description = $setting['setting_description'];
            $input_type = $setting['setting_type'] === 'boolean' ? 'checkbox' : $setting['setting_type'];
            $options = $setting['setting_options'];
            $display_order = $setting['display_order'];
            
            $insert_stmt->bindParam(':setting_group', $setting_group, PDO::PARAM_STR);
            $insert_stmt->bindParam(':setting_key', $setting_key, PDO::PARAM_STR);
            $insert_stmt->bindParam(':setting_value', $setting_value, PDO::PARAM_STR);
            $insert_stmt->bindParam(':display_name', $display_name, PDO::PARAM_STR);
            $insert_stmt->bindParam(':description', $description, PDO::PARAM_STR);
            $insert_stmt->bindParam(':input_type', $input_type, PDO::PARAM_STR);
            $insert_stmt->bindParam(':options', $options, PDO::PARAM_STR);
            $insert_stmt->bindParam(':display_order', $display_order, PDO::PARAM_INT);
            
            $insert_stmt->execute();
            $count++;
            
            echo "Added setting: " . $setting['setting_key'] . "\n";
        }
        
        echo "\nAdded $count brand settings successfully.\n";
    }
    
    echo "\nDone.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    error_log("Database error in insert_brand_settings.php: " . $e->getMessage());
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    error_log("Error in insert_brand_settings.php: " . $e->getMessage());
}
