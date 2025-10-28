<?php
// Script to reset location settings
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'c:/xampp/htdocs/Solar Business/config/database.php';
$database = new Database();
$db = $database->getConnection();

try {
    // Start transaction
    $db->beginTransaction();
    
    // 1. First, delete all existing location and warehouse settings
    $query = "DELETE FROM inventory_settings 
              WHERE setting_group = 'locations' 
              OR setting_group = 'warehouse' 
              OR setting_key = 'enable_multi_warehouse' 
              OR setting_key = 'enable_multi_locations'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $deleted = $stmt->rowCount();
    
    echo "Deleted existing settings: " . $deleted . " rows<br>";
    
    // 2. Add the location settings
    $settings = [
        [
            'setting_key' => 'enable_multi_locations',
            'setting_value' => '1',
            'setting_group' => 'locations',
            'display_name' => 'Enable Multi-Locations',
            'description' => 'Enable multiple location management for inventory',
            'input_type' => 'checkbox',
            'options' => null,
            'is_required' => 0,
            'display_order' => 10
        ],
        [
            'setting_key' => 'auto_generate_location_code',
            'setting_value' => '1',
            'setting_group' => 'locations',
            'display_name' => 'Auto-Generate Location Code',
            'description' => 'Automatically generate codes for new locations',
            'input_type' => 'checkbox',
            'options' => null,
            'is_required' => 0,
            'display_order' => 20
        ],
        [
            'setting_key' => 'location_code_prefix',
            'setting_value' => 'LOC',
            'setting_group' => 'locations',
            'display_name' => 'Location Code Prefix',
            'description' => 'Prefix for auto-generated location codes',
            'input_type' => 'text',
            'options' => null,
            'is_required' => 0,
            'display_order' => 30
        ],
        [
            'setting_key' => 'default_location_type',
            'setting_value' => 'Warehouse',
            'setting_group' => 'locations',
            'display_name' => 'Default Location Type',
            'description' => 'Default type for new locations',
            'input_type' => 'select',
            'options' => 'Warehouse,Store,Shelf,Bin,Zone,Section,Other',
            'is_required' => 1,
            'display_order' => 40
        ],
        [
            'setting_key' => 'require_branch_for_locations',
            'setting_value' => '0',
            'setting_group' => 'locations',
            'display_name' => 'Require Branch for Locations',
            'description' => 'Require locations to be associated with a branch',
            'input_type' => 'checkbox',
            'options' => null,
            'is_required' => 0,
            'display_order' => 50
        ],
        [
            'setting_key' => 'allow_hierarchical_locations',
            'setting_value' => '1',
            'setting_group' => 'locations',
            'display_name' => 'Allow Hierarchical Locations',
            'description' => 'Allow locations to have parent-child relationships',
            'input_type' => 'checkbox',
            'options' => null,
            'is_required' => 0,
            'display_order' => 60
        ],
        [
            'setting_key' => 'max_location_hierarchy_depth',
            'setting_value' => '3',
            'setting_group' => 'locations',
            'display_name' => 'Maximum Hierarchy Depth',
            'description' => 'Maximum depth of location hierarchy (0 for unlimited)',
            'input_type' => 'number',
            'options' => null,
            'is_required' => 0,
            'display_order' => 70
        ],
        [
            'setting_key' => 'location_types',
            'setting_value' => 'Warehouse,Store,Shelf,Bin,Zone,Section,Other',
            'setting_group' => 'locations',
            'display_name' => 'Location Types',
            'description' => 'Comma-separated list of available location types',
            'input_type' => 'textarea',
            'options' => null,
            'is_required' => 1,
            'display_order' => 80
        ]
    ];
    
    // Insert each setting
    foreach ($settings as $setting) {
        $query = "INSERT INTO inventory_settings (
                 setting_key, setting_value, setting_group, display_name, 
                 description, input_type, options, is_required, display_order,
                 status, created_by, updated_by, created_at, updated_at
                 ) VALUES (
                 ?, ?, ?, ?, 
                 ?, ?, ?, ?, ?,
                 1, ?, ?, NOW(), NOW()
                 )";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            $setting['setting_key'],
            $setting['setting_value'],
            $setting['setting_group'],
            $setting['display_name'],
            $setting['description'],
            $setting['input_type'],
            $setting['options'],
            $setting['is_required'],
            $setting['display_order'],
            1, // Default user ID
            1  // Default user ID
        ]);
        
        echo "Added setting: " . $setting['setting_key'] . "<br>";
    }
    
    // Commit the transaction
    $db->commit();
    
    echo "<p>Settings reset successfully!</p>";
    echo "<p><a href='../settings.php'>Return to Settings</a></p>";
    
} catch (PDOException $e) {
    // Rollback the transaction on error
    $db->rollBack();
    echo "Error resetting settings: " . $e->getMessage();
    error_log("Error resetting settings: " . $e->getMessage());
} catch (Exception $e) {
    // Rollback the transaction on error
    $db->rollBack();
    echo "Error: " . $e->getMessage();
    error_log("Error in reset_location_settings.php: " . $e->getMessage());
}
?>
