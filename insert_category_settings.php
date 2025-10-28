<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

echo "Starting insertion of category settings...\n\n";

try {
    $db->beginTransaction();
    
    // Insert category settings directly with SQL
    $queries = [
        "INSERT INTO inventory_settings (setting_key, setting_value, display_name, description, setting_group, input_type, options, display_order, status, created_by, updated_by, created_at, updated_at) 
         VALUES ('allow_hierarchical_categories', '1', 'Allow Hierarchical Categories', 'Enable parent-child relationships for categories', 'categories', 'checkbox', NULL, 1, 1, 1, 1, NOW(), NOW())",
        
        "INSERT INTO inventory_settings (setting_key, setting_value, display_name, description, setting_group, input_type, options, display_order, status, created_by, updated_by, created_at, updated_at) 
         VALUES ('category_max_hierarchy_depth', '3', 'Maximum Hierarchy Depth', 'Maximum depth of category hierarchy (0 for unlimited)', 'categories', 'number', NULL, 2, 1, 1, 1, NOW(), NOW())",
        
        "INSERT INTO inventory_settings (setting_key, setting_value, display_name, description, setting_group, input_type, options, display_order, status, created_by, updated_by, created_at, updated_at) 
         VALUES ('auto_generate_category_code', '1', 'Auto-Generate Category Code', 'Automatically generate codes for new categories', 'categories', 'checkbox', NULL, 3, 1, 1, 1, NOW(), NOW())",
        
        "INSERT INTO inventory_settings (setting_key, setting_value, display_name, description, setting_group, input_type, options, display_order, status, created_by, updated_by, created_at, updated_at) 
         VALUES ('category_code_prefix', 'CAT', 'Category Code Prefix', 'Prefix to use when auto-generating category codes', 'categories', 'text', NULL, 4, 1, 1, 1, NOW(), NOW())",
        
        "INSERT INTO inventory_settings (setting_key, setting_value, display_name, description, setting_group, input_type, options, display_order, status, created_by, updated_by, created_at, updated_at) 
         VALUES ('category_types', 'Product,Service,Material,Equipment,Other', 'Category Types', 'Comma-separated list of category types', 'categories', 'textarea', NULL, 5, 1, 1, 1, NOW(), NOW())",
        
        "INSERT INTO inventory_settings (setting_key, setting_value, display_name, description, setting_group, input_type, options, display_order, status, created_by, updated_by, created_at, updated_at) 
         VALUES ('default_category_type', 'Product', 'Default Category Type', 'Default type for new categories', 'categories', 'text', NULL, 6, 1, 1, 1, NOW(), NOW())"
    ];
    
    $settings_added = 0;
    
    foreach ($queries as $query) {
        // Check if setting already exists
        $setting_key = '';
        if (preg_match("/VALUES\s*\(\s*'([^']+)'/i", $query, $matches)) {
            $setting_key = $matches[1];
        }
        
        if (!empty($setting_key)) {
            $check_query = "SELECT COUNT(*) as count FROM inventory_settings WHERE setting_key = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$setting_key]);
            $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0) {
                // Setting doesn't exist, add it
                $stmt = $db->prepare($query);
                $stmt->execute();
                $settings_added++;
                echo "Added setting: $setting_key\n";
            } else {
                echo "Setting already exists: $setting_key\n";
            }
        }
    }
    
    $db->commit();
    
    echo "\nAdded $settings_added category settings successfully.\n";
    
} catch (PDOException $e) {
    $db->rollBack();
    echo "Database error: " . $e->getMessage() . "\n";
}

echo "\nDone.";
