<?php
// Script to update location settings
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'c:/xampp/htdocs/Solar Business/config/database.php';
$database = new Database();
$db = $database->getConnection();

try {
    // Start transaction
    $db->beginTransaction();
    
    // 1. First, update the existing enable_multi_warehouse setting to locations group
    $query = "UPDATE inventory_settings 
              SET setting_group = 'locations', 
                  display_name = 'Enable Multi-Locations',
                  description = 'Enable multiple location management for inventory',
                  setting_key = 'enable_multi_locations'
              WHERE setting_key = 'enable_multi_warehouse'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $updated = $stmt->rowCount();
    
    echo "Updated warehouse setting to locations: " . $updated . " rows<br>";
    
    // 2. Add the remaining location settings if they don't exist
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
                1, // Default user ID
                1  // Default user ID
            ]);
            
            echo "Added setting: " . $setting['setting_key'] . "<br>";
        }
    }
    
    // 3. Check for duplicate enable_multi_locations settings
    $query = "SELECT id FROM inventory_settings 
              WHERE setting_key = 'enable_multi_locations' 
              ORDER BY id ASC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If we have more than one, keep only the first one
    if (count($results) > 1) {
        // Keep the first ID
        $keep_id = $results[0]['id'];
        
        // Delete all others
        $delete_ids = [];
        for ($i = 1; $i < count($results); $i++) {
            $delete_ids[] = $results[$i]['id'];
        }
        
        if (!empty($delete_ids)) {
            $delete_query = "DELETE FROM inventory_settings WHERE id IN (" . implode(',', $delete_ids) . ")";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->execute();
            
            echo "Removed " . count($delete_ids) . " duplicate settings.<br>";
        }
    }
    
    // Commit the transaction
    $db->commit();
    
    echo "<p>Settings updated successfully!</p>";
    echo "<p><a href='../settings.php'>Return to Settings</a></p>";
    
} catch (PDOException $e) {
    // Rollback the transaction on error
    $db->rollBack();
    echo "Error updating settings: " . $e->getMessage();
    error_log("Error updating settings: " . $e->getMessage());
} catch (Exception $e) {
    // Rollback the transaction on error
    $db->rollBack();
    echo "Error: " . $e->getMessage();
    error_log("Error in update_location_settings.php: " . $e->getMessage());
}
?>
