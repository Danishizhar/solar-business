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

// Category settings to add
$settings = [
    [
        'setting_key' => 'allow_hierarchical_categories',
        'setting_value' => '1',
        'display_name' => 'Allow Hierarchical Categories',
        'description' => 'Enable parent-child relationships for categories',
        'setting_group' => 'categories',
        'input_type' => 'checkbox',
        'options' => null,
        'display_order' => 1
    ],
    [
        'setting_key' => 'category_max_hierarchy_depth',
        'setting_value' => '3',
        'display_name' => 'Maximum Hierarchy Depth',
        'description' => 'Maximum depth of category hierarchy (0 for unlimited)',
        'setting_group' => 'categories',
        'input_type' => 'number',
        'options' => null,
        'display_order' => 2
    ],
    [
        'setting_key' => 'auto_generate_category_code',
        'setting_value' => '1',
        'display_name' => 'Auto-Generate Category Code',
        'description' => 'Automatically generate codes for new categories',
        'setting_group' => 'categories',
        'input_type' => 'checkbox',
        'options' => null,
        'display_order' => 3
    ],
    [
        'setting_key' => 'category_code_prefix',
        'setting_value' => 'CAT',
        'display_name' => 'Category Code Prefix',
        'description' => 'Prefix to use when auto-generating category codes',
        'setting_group' => 'categories',
        'input_type' => 'text',
        'options' => null,
        'display_order' => 4
    ],
    [
        'setting_key' => 'category_types',
        'setting_value' => 'Product,Service,Material,Equipment,Other',
        'display_name' => 'Category Types',
        'description' => 'Comma-separated list of category types',
        'setting_group' => 'categories',
        'input_type' => 'textarea',
        'options' => null,
        'display_order' => 5
    ],
    [
        'setting_key' => 'default_category_type',
        'setting_value' => 'Product',
        'display_name' => 'Default Category Type',
        'description' => 'Default type for new categories',
        'setting_group' => 'categories',
        'input_type' => 'text',
        'options' => null,
        'display_order' => 6
    ]
];

try {
    $db->beginTransaction();
    
    // Check if settings already exist
    $check_query = "SELECT COUNT(*) as count FROM inventory_settings WHERE setting_key = ? AND status = 1";
    $check_stmt = $db->prepare($check_query);
    
    // Insert query
    $insert_query = "INSERT INTO inventory_settings (
                    setting_key, setting_value, display_name, description,
                    setting_group, input_type, options, display_order,
                    status, created_by, updated_by, created_at, updated_at
                    ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW()
                    )";
    $insert_stmt = $db->prepare($insert_query);
    
    $user_id = $_SESSION['user_id'];
    $settings_added = 0;
    
    foreach ($settings as $setting) {
        // Check if setting exists
        $check_stmt->execute([$setting['setting_key']]);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] == 0) {
            // Setting doesn't exist, add it
            $insert_stmt->execute([
                $setting['setting_key'],
                $setting['setting_value'],
                $setting['display_name'],
                $setting['description'],
                $setting['setting_group'],
                $setting['input_type'],
                $setting['options'],
                $setting['display_order'],
                $user_id,
                $user_id
            ]);
            $settings_added++;
        }
    }
    
    $db->commit();
    
    if ($settings_added > 0) {
        $_SESSION['success_message'] = "Added {$settings_added} category settings successfully";
    } else {
        $_SESSION['info_message'] = "All category settings already exist";
    }
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Database error in add_category_settings.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
}

// Redirect back to settings page
header("Location: ../settings.php");
exit();
