<?php
// Script to add location settings to the inventory_settings table
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'c:/xampp/htdocs/Solar Business/config/database.php';
$database = new Database();
$db = $database->getConnection();

try {
    // Start transaction
    $db->beginTransaction();
    
    // Define the settings to add
    $settings = [
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
    
    // Insert each setting if it doesn't already exist
    foreach ($settings as $setting) {
        $check_query = "SELECT COUNT(*) as count FROM inventory_settings WHERE setting_key = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$setting['setting_key']]);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] == 0) {
            // Setting doesn't exist, insert it
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
                $_SESSION['user_id'] ?? 1,
                $_SESSION['user_id'] ?? 1
            ]);
            
            echo "Added setting: " . $setting['setting_key'] . "<br>";
        } else {
            // Setting exists, check if it's in the right group
            $update_query = "UPDATE inventory_settings SET 
                           setting_group = ?, 
                           display_name = ?,
                           description = ?,
                           updated_by = ?,
                           updated_at = NOW()
                           WHERE setting_key = ?";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([
                $setting['setting_group'],
                $setting['display_name'],
                $setting['description'],
                $_SESSION['user_id'] ?? 1,
                $setting['setting_key']
            ]);
            
            echo "Updated setting: " . $setting['setting_key'] . "<br>";
        }
    }
    
    // Commit the transaction
    $db->commit();
    echo "<p>Location settings added/updated successfully!</p>";
    echo "<p><a href='../settings.php'>Return to Settings</a></p>";
    
} catch (PDOException $e) {
    // Rollback the transaction on error
    $db->rollBack();
    echo "Error adding location settings: " . $e->getMessage();
    error_log("Error adding location settings: " . $e->getMessage());
} catch (Exception $e) {
    // Rollback the transaction on error
    $db->rollBack();
    echo "Error: " . $e->getMessage();
    error_log("Error in add_location_settings.php: " . $e->getMessage());
}
?>
