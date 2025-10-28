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

// Define attribute settings to add
$attribute_settings = [
    [
        'setting_group' => 'attributes',
        'setting_key' => 'auto_generate_attribute_code',
        'setting_value' => '1',
        'display_name' => 'Auto Generate Attribute Code',
        'description' => 'Automatically generate attribute codes',
        'input_type' => 'checkbox',
        'options' => '0,1',
        'display_order' => 1
    ],
    [
        'setting_group' => 'attributes',
        'setting_key' => 'attribute_code_prefix',
        'setting_value' => 'ATTR_',
        'display_name' => 'Attribute Code Prefix',
        'description' => 'Prefix for auto-generated attribute codes',
        'input_type' => 'text',
        'options' => '',
        'display_order' => 2
    ],
    [
        'setting_group' => 'attributes',
        'setting_key' => 'allowed_input_types',
        'setting_value' => 'text,textarea,select,multiselect,number,date,boolean,color,file',
        'display_name' => 'Allowed Input Types',
        'description' => 'Input types that can be used for attributes (comma-separated)',
        'input_type' => 'text',
        'options' => '',
        'display_order' => 3
    ],
    [
        'setting_group' => 'attributes',
        'setting_key' => 'default_input_type',
        'setting_value' => 'text',
        'display_name' => 'Default Input Type',
        'description' => 'Default input type for new attributes',
        'input_type' => 'select',
        'options' => 'text,textarea,select,multiselect,number,date,boolean,color,file',
        'display_order' => 4
    ],
    [
        'setting_group' => 'attributes',
        'setting_key' => 'require_validation_rules',
        'setting_value' => '0',
        'display_name' => 'Require Validation Rules',
        'description' => 'Require validation rules when adding/editing attributes',
        'input_type' => 'checkbox',
        'options' => '0,1',
        'display_order' => 5
    ],
    [
        'setting_group' => 'attributes',
        'setting_key' => 'max_options_count',
        'setting_value' => '50',
        'display_name' => 'Maximum Options Count',
        'description' => 'Maximum number of options for select/multiselect types',
        'input_type' => 'number',
        'options' => '10,20,50,100',
        'display_order' => 6
    ],
    [
        'setting_group' => 'attributes',
        'setting_key' => 'allow_multiple_category_assignments',
        'setting_value' => '1',
        'display_name' => 'Allow Multiple Category Assignments',
        'description' => 'Allow attributes to be assigned to multiple categories',
        'input_type' => 'checkbox',
        'options' => '0,1',
        'display_order' => 7
    ],
    [
        'setting_group' => 'attributes',
        'setting_key' => 'allow_variant_attributes',
        'setting_value' => '1',
        'display_name' => 'Allow Variant Attributes',
        'description' => 'Allow attributes to be used for product variants',
        'input_type' => 'checkbox',
        'options' => '0,1',
        'display_order' => 8
    ],
    [
        'setting_group' => 'attributes',
        'setting_key' => 'max_variant_attributes',
        'setting_value' => '3',
        'display_name' => 'Maximum Variant Attributes',
        'description' => 'Maximum number of attributes that can be used for variants',
        'input_type' => 'number',
        'options' => '1,2,3,4,5',
        'display_order' => 9
    ]
];

// Initialize response array
$response = [
    'success' => false,
    'messages' => []
];

try {
    // Check if attribute settings already exist
    $check_query = "SELECT COUNT(*) as count FROM inventory_settings WHERE setting_group = 'attributes'";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute();
    $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        $response['success'] = true;
        $response['messages'][] = "Attribute settings already exist. Skipping insertion.";
    } else {
        // Insert settings
        $insert_query = "INSERT INTO inventory_settings 
                        (setting_group, setting_key, setting_value, display_name, description, input_type, options, display_order, status, created_at, updated_at) 
                        VALUES 
                        (:setting_group, :setting_key, :setting_value, :display_name, :description, :input_type, :options, :display_order, 1, NOW(), NOW())";
        
        $insert_stmt = $db->prepare($insert_query);
        $count = 0;
        
        foreach ($attribute_settings as $setting) {
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
        $response['messages'][] = "Added $count attribute settings successfully.";
    }

} catch (PDOException $e) {
    $response['success'] = false;
    $response['messages'][] = "Database error: " . $e->getMessage();
    error_log("Database error in add_attribute_settings.php: " . $e->getMessage());
} catch (Exception $e) {
    $response['success'] = false;
    $response['messages'][] = "Error: " . $e->getMessage();
    error_log("Error in add_attribute_settings.php: " . $e->getMessage());
}

// Return JSON response
echo json_encode($response);
