<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Define UOM settings to add
$uom_settings = [
    [
        'setting_group' => 'uom',
        'setting_key' => 'auto_generate_uom_code',
        'setting_value' => '1',
        'display_name' => 'Auto Generate UOM Code',
        'description' => 'Automatically generate UOM codes',
        'input_type' => 'checkbox',
        'options' => '0,1',
        'display_order' => 1
    ],
    [
        'setting_group' => 'uom',
        'setting_key' => 'uom_code_prefix',
        'setting_value' => 'UOM_',
        'display_name' => 'UOM Code Prefix',
        'description' => 'Prefix for auto-generated UOM codes',
        'input_type' => 'text',
        'options' => '',
        'display_order' => 2
    ],
    [
        'setting_group' => 'uom',
        'setting_key' => 'uom_types',
        'setting_value' => 'Weight,Volume,Length,Area,Time,Quantity,Other',
        'display_name' => 'UOM Types',
        'description' => 'Types of units of measurement (comma-separated)',
        'input_type' => 'text',
        'options' => '',
        'display_order' => 3
    ],
    [
        'setting_group' => 'uom',
        'setting_key' => 'default_uom_type',
        'setting_value' => 'Quantity',
        'display_name' => 'Default UOM Type',
        'description' => 'Default type for new UOMs',
        'input_type' => 'select',
        'options' => 'Weight,Volume,Length,Area,Time,Quantity,Other',
        'display_order' => 4
    ],
    [
        'setting_group' => 'uom',
        'setting_key' => 'require_base_unit_validation',
        'setting_value' => '1',
        'display_name' => 'Require Base Unit Validation',
        'description' => 'Require validation for base units',
        'input_type' => 'checkbox',
        'options' => '0,1',
        'display_order' => 5
    ],
    [
        'setting_group' => 'uom',
        'setting_key' => 'require_conversion_rate',
        'setting_value' => '1',
        'display_name' => 'Require Conversion Rate',
        'description' => 'Require conversion rate for non-base units',
        'input_type' => 'checkbox',
        'options' => '0,1',
        'display_order' => 6
    ],
    [
        'setting_group' => 'uom',
        'setting_key' => 'max_decimal_places',
        'setting_value' => '6',
        'display_name' => 'Maximum Decimal Places',
        'description' => 'Maximum decimal places for conversion rates',
        'input_type' => 'number',
        'options' => '2,4,6,8',
        'display_order' => 7
    ],
    [
        'setting_group' => 'uom',
        'setting_key' => 'can_be_used_for_purchase',
        'setting_value' => '1',
        'display_name' => 'Can Be Used For Purchase',
        'description' => 'Allow UOMs to be used for purchasing',
        'input_type' => 'checkbox',
        'options' => '0,1',
        'display_order' => 8
    ],
    [
        'setting_group' => 'uom',
        'setting_key' => 'can_be_used_for_sales',
        'setting_value' => '1',
        'display_name' => 'Can Be Used For Sales',
        'description' => 'Allow UOMs to be used for sales',
        'input_type' => 'checkbox',
        'options' => '0,1',
        'display_order' => 9
    ],
    [
        'setting_group' => 'uom',
        'setting_key' => 'can_be_used_for_inventory',
        'setting_value' => '1',
        'display_name' => 'Can Be Used For Inventory',
        'description' => 'Allow UOMs to be used for inventory',
        'input_type' => 'checkbox',
        'options' => '0,1',
        'display_order' => 10
    ]
];

// Initialize response array
$response = [
    'success' => false,
    'messages' => []
];

try {
    // Check if UOM settings already exist
    $check_query = "SELECT COUNT(*) as count FROM inventory_settings WHERE setting_group = 'uom'";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute();
    $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        $response['success'] = true;
        $response['messages'][] = "UOM settings already exist. Skipping insertion.";
    } else {
        // Insert settings
        $insert_query = "INSERT INTO inventory_settings 
                        (setting_group, setting_key, setting_value, display_name, description, input_type, options, display_order, status, created_at, updated_at) 
                        VALUES 
                        (:setting_group, :setting_key, :setting_value, :display_name, :description, :input_type, :options, :display_order, 1, NOW(), NOW())";
        
        $insert_stmt = $db->prepare($insert_query);
        $count = 0;
        
        foreach ($uom_settings as $setting) {
            $setting_group = $setting['setting_group'];
            $setting_key = $setting['setting_key'];
            $setting_value = $setting['setting_value'];
            $display_name = $setting['display_name'];
            $description = $setting['description'];
            $input_type = $setting['input_type'];
            $options = $setting['options'];
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
            
            $response['messages'][] = "Added setting: " . $setting['setting_key'];
        }
        
        $response['success'] = true;
        $response['messages'][] = "Added $count UOM settings successfully.";
    }
} catch (PDOException $e) {
    $response['success'] = false;
    $response['messages'][] = "Database error: " . $e->getMessage();
    error_log("Database error in add_uom_settings.php: " . $e->getMessage());
} catch (Exception $e) {
    $response['success'] = false;
    $response['messages'][] = "Error: " . $e->getMessage();
    error_log("Error in add_uom_settings.php: " . $e->getMessage());
}

// Return JSON response
echo json_encode($response);
