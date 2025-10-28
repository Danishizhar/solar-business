<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
require_once '../includes/discount_helpers.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Start transaction
    $db->beginTransaction();
    
    // Get form data
    $discount_id = isset($_POST['discount_id']) ? intval($_POST['discount_id']) : 0;
    $discount_name = trim($_POST['discount_name']);
    $discount_code = trim($_POST['discount_code']);
    $discount_type = $_POST['discount_type'];
    $discount_application = $_POST['discount_application'];
    $discount_value = floatval($_POST['discount_value']);
    $minimum_purchase_amount = floatval($_POST['minimum_purchase_amount']);
    $minimum_quantity = intval($_POST['minimum_quantity']);
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $description = trim($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // New fields from discount system upgrade
    // Discount stacking
    $is_stackable = isset($_POST['is_stackable']) ? 1 : 0;
    $stack_priority = isset($_POST['stack_priority']) ? intval($_POST['stack_priority']) : 0;
    $stack_type = isset($_POST['stack_type']) ? $_POST['stack_type'] : 'additive';
    
    // Time-limited flash discounts
    $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
    $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
    $is_flash_sale = isset($_POST['is_flash_sale']) ? 1 : 0;
    
    // Approval requirement
    $requires_approval = isset($_POST['requires_approval']) ? 1 : 0;
    
    // Usage limits
    $usage_limit = !empty($_POST['usage_limit']) ? intval($_POST['usage_limit']) : null;
    $per_customer_limit = !empty($_POST['per_customer_limit']) ? intval($_POST['per_customer_limit']) : null;
    $budget_cap = !empty($_POST['budget_cap']) ? floatval($_POST['budget_cap']) : null;
    
    // Marketing campaign integration
    $campaign_id = !empty($_POST['campaign_id']) ? intval($_POST['campaign_id']) : null;
    $utm_source = !empty($_POST['utm_source']) ? trim($_POST['utm_source']) : null;
    $utm_medium = !empty($_POST['utm_medium']) ? trim($_POST['utm_medium']) : null;
    $utm_campaign = !empty($_POST['utm_campaign']) ? trim($_POST['utm_campaign']) : null;
    
    // Customer loyalty tier
    $loyalty_tier_id = !empty($_POST['loyalty_tier_id']) ? intval($_POST['loyalty_tier_id']) : null;
    
    // Buy X Get Y promotion
    $buy_quantity = !empty($_POST['buy_quantity']) ? intval($_POST['buy_quantity']) : null;
    $get_quantity = !empty($_POST['get_quantity']) ? intval($_POST['get_quantity']) : null;
    $get_product_id = !empty($_POST['get_product_id']) ? intval($_POST['get_product_id']) : null;
    $get_discount_percentage = !empty($_POST['get_discount_percentage']) ? floatval($_POST['get_discount_percentage']) : 100.00;
    
    // Validate input
    if (empty($discount_name)) {
        throw new Exception("Discount name is required.");
    }
    
    // Check if this is a Buy X Get Y promotion
    $is_bxgy_promotion = ($buy_quantity > 0 && $get_quantity > 0);
    
    // Skip discount value validation for Buy X Get Y promotions
    if (!$is_bxgy_promotion) {
        if ($discount_value <= 0) {
            throw new Exception("Discount value must be greater than zero.");
        }
        
        if ($discount_type === 'percentage' && $discount_value > 100) {
            throw new Exception("Percentage discount cannot be more than 100%.");
        }
    } else {
        // Validate Buy X Get Y fields
        if ($buy_quantity <= 0) {
            throw new Exception("Buy Quantity (X) must be greater than zero.");
        }
        
        if ($get_quantity <= 0) {
            throw new Exception("Get Quantity (Y) must be greater than zero.");
        }
        
        // For Buy X Get Y promotions, set default values for required fields
        if ($discount_value <= 0) {
            $discount_value = 0; // Set to 0 as the discount is handled by the Buy X Get Y logic
        }
        
        // Set a default discount_type for Buy X Get Y promotions if it's empty
        if (empty($discount_type)) {
            $discount_type = 'percentage'; // Default to percentage for Buy X Get Y promotions
        }
    }
    
    // Auto-generate discount code if needed
    if (empty($discount_code) && getDiscountSetting($db, 'auto_generate_discount_code', '1') == '1') {
        $discount_code = generateDiscountCode($db);
    }
    
    // Check if discount code already exists (for new discounts)
    if ($discount_id === 0 && !empty($discount_code)) {
        $check_query = "SELECT COUNT(*) FROM discounts WHERE discount_code = :discount_code AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':discount_code', $discount_code, PDO::PARAM_STR);
        $check_stmt->execute();
        
        if ($check_stmt->fetchColumn() > 0) {
            throw new Exception("Discount code already exists. Please use a different code.");
        }
    }
    
    // Get current user ID
    $user_id = $_SESSION['user_id'];
    
    // Insert or update discount
    if ($discount_id === 0) {
        // Insert new discount
        $query = "INSERT INTO discounts (
                    discount_name, discount_code, discount_type, discount_application, discount_value,
                    minimum_purchase_amount, minimum_quantity, is_active,
                    start_date, end_date, description,
                    is_stackable, stack_priority, stack_type,
                    start_time, end_time, is_flash_sale,
                    usage_limit, per_customer_limit, budget_cap, budget_used,
                    campaign_id, utm_source, utm_medium, utm_campaign,
                    loyalty_tier_id, buy_quantity, get_quantity, get_product_id, get_discount_percentage,
                    approval_status, requires_approval, status, created_by, updated_by
                ) VALUES (
                    :discount_name, :discount_code, :discount_type, :discount_application, :discount_value,
                    :minimum_purchase_amount, :minimum_quantity, :is_active,
                    :start_date, :end_date, :description,
                    :is_stackable, :stack_priority, :stack_type,
                    :start_time, :end_time, :is_flash_sale,
                    :usage_limit, :per_customer_limit, :budget_cap, 0,
                    :campaign_id, :utm_source, :utm_medium, :utm_campaign,
                    :loyalty_tier_id, :buy_quantity, :get_quantity, :get_product_id, :get_discount_percentage,
                    'approved', :requires_approval, 1, :created_by, :updated_by
                )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':discount_name', $discount_name, PDO::PARAM_STR);
        $stmt->bindParam(':discount_code', $discount_code, PDO::PARAM_STR);
        $stmt->bindParam(':discount_type', $discount_type, PDO::PARAM_STR);
        $stmt->bindParam(':discount_application', $discount_application, PDO::PARAM_STR);
        $stmt->bindParam(':discount_value', $discount_value, PDO::PARAM_STR);
        $stmt->bindParam(':minimum_purchase_amount', $minimum_purchase_amount, PDO::PARAM_STR);
        $stmt->bindParam(':minimum_quantity', $minimum_quantity, PDO::PARAM_INT);
        $stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);
        $stmt->bindParam(':start_date', $start_date, $start_date ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':end_date', $end_date, $end_date ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        
        // Bind new parameters
        $stmt->bindParam(':is_stackable', $is_stackable, PDO::PARAM_INT);
        $stmt->bindParam(':stack_priority', $stack_priority, PDO::PARAM_INT);
        $stmt->bindParam(':stack_type', $stack_type, PDO::PARAM_STR);
        $stmt->bindParam(':start_time', $start_time, $start_time ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':end_time', $end_time, $end_time ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':is_flash_sale', $is_flash_sale, PDO::PARAM_INT);
        $stmt->bindParam(':usage_limit', $usage_limit, $usage_limit ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':per_customer_limit', $per_customer_limit, $per_customer_limit ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':budget_cap', $budget_cap, $budget_cap ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':campaign_id', $campaign_id, $campaign_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':utm_source', $utm_source, $utm_source ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':utm_medium', $utm_medium, $utm_medium ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':utm_campaign', $utm_campaign, $utm_campaign ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':loyalty_tier_id', $loyalty_tier_id, $loyalty_tier_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':buy_quantity', $buy_quantity, $buy_quantity ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':get_quantity', $get_quantity, $get_quantity ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':get_product_id', $get_product_id, $get_product_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':get_discount_percentage', $get_discount_percentage, PDO::PARAM_STR);
        $stmt->bindParam(':requires_approval', $requires_approval, PDO::PARAM_INT);
        $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $discount_id = $db->lastInsertId();
        $success_message = "Discount created successfully.";
    } else {
        // Update existing discount
        $query = "UPDATE discounts SET
                    discount_name = :discount_name,
                    discount_code = :discount_code,
                    discount_type = :discount_type,
                    discount_application = :discount_application,
                    discount_value = :discount_value,
                    minimum_purchase_amount = :minimum_purchase_amount,
                    minimum_quantity = :minimum_quantity,
                    is_active = :is_active,
                    start_date = :start_date,
                    end_date = :end_date,
                    description = :description,
                    is_stackable = :is_stackable,
                    stack_priority = :stack_priority,
                    stack_type = :stack_type,
                    start_time = :start_time,
                    end_time = :end_time,
                    is_flash_sale = :is_flash_sale,
                    usage_limit = :usage_limit,
                    per_customer_limit = :per_customer_limit,
                    budget_cap = :budget_cap,
                    campaign_id = :campaign_id,
                    utm_source = :utm_source,
                    utm_medium = :utm_medium,
                    utm_campaign = :utm_campaign,
                    loyalty_tier_id = :loyalty_tier_id,
                    buy_quantity = :buy_quantity,
                    get_quantity = :get_quantity,
                    get_product_id = :get_product_id,
                    get_discount_percentage = :get_discount_percentage,
                    requires_approval = :requires_approval,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :discount_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':discount_name', $discount_name, PDO::PARAM_STR);
        $stmt->bindParam(':discount_code', $discount_code, PDO::PARAM_STR);
        $stmt->bindParam(':discount_type', $discount_type, PDO::PARAM_STR);
        $stmt->bindParam(':discount_application', $discount_application, PDO::PARAM_STR);
        $stmt->bindParam(':discount_value', $discount_value, PDO::PARAM_STR);
        $stmt->bindParam(':minimum_purchase_amount', $minimum_purchase_amount, PDO::PARAM_STR);
        $stmt->bindParam(':minimum_quantity', $minimum_quantity, PDO::PARAM_INT);
        $stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);
        $stmt->bindParam(':start_date', $start_date, $start_date ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':end_date', $end_date, $end_date ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        
        // Bind new parameters
        $stmt->bindParam(':is_stackable', $is_stackable, PDO::PARAM_INT);
        $stmt->bindParam(':stack_priority', $stack_priority, PDO::PARAM_INT);
        $stmt->bindParam(':stack_type', $stack_type, PDO::PARAM_STR);
        $stmt->bindParam(':start_time', $start_time, $start_time ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':end_time', $end_time, $end_time ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':is_flash_sale', $is_flash_sale, PDO::PARAM_INT);
        $stmt->bindParam(':usage_limit', $usage_limit, $usage_limit ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':per_customer_limit', $per_customer_limit, $per_customer_limit ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':budget_cap', $budget_cap, $budget_cap ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':campaign_id', $campaign_id, $campaign_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':utm_source', $utm_source, $utm_source ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':utm_medium', $utm_medium, $utm_medium ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':utm_campaign', $utm_campaign, $utm_campaign ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':loyalty_tier_id', $loyalty_tier_id, $loyalty_tier_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':buy_quantity', $buy_quantity, $buy_quantity ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':get_quantity', $get_quantity, $get_quantity ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':get_product_id', $get_product_id, $get_product_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':get_discount_percentage', $get_discount_percentage, PDO::PARAM_STR);
        $stmt->bindParam(':requires_approval', $requires_approval, PDO::PARAM_INT);
        
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        // Delete existing conditions
        $delete_query = "DELETE FROM discount_conditions WHERE discount_id = :discount_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
        $delete_stmt->execute();
        
        $success_message = "Discount updated successfully.";
    }
    
    // Process conditions
    $condition_types = [
        'product' => isset($_POST['product_conditions']) ? $_POST['product_conditions'] : [],
        'brand' => isset($_POST['brand_conditions']) ? $_POST['brand_conditions'] : [],
        'category' => isset($_POST['category_conditions']) ? $_POST['category_conditions'] : [],
        'location' => isset($_POST['location_conditions']) ? $_POST['location_conditions'] : [],
        'supplier' => isset($_POST['supplier_conditions']) ? $_POST['supplier_conditions'] : [],
        'customer' => isset($_POST['customer_conditions']) ? $_POST['customer_conditions'] : []
    ];
    
    // Handle regular condition types
    foreach ($condition_types as $condition_type => $condition_values) {
        if (!empty($condition_values)) {
            foreach ($condition_values as $condition_value) {
                $condition_query = "INSERT INTO discount_conditions (
                                    discount_id, condition_type, condition_value,
                                    status, created_by, updated_by
                                ) VALUES (
                                    :discount_id, :condition_type, :condition_value,
                                    1, :created_by, :updated_by
                                )";
                
                $condition_stmt = $db->prepare($condition_query);
                $condition_stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
                $condition_stmt->bindParam(':condition_type', $condition_type, PDO::PARAM_STR);
                $condition_stmt->bindParam(':condition_value', $condition_value, PDO::PARAM_STR);
                $condition_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
                $condition_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
                
                $condition_stmt->execute();
            }
        }
    }
    
    // Handle attribute conditions with values - special case
    if (isset($_POST['attribute_conditions']) && is_array($_POST['attribute_conditions'])) {
        foreach ($_POST['attribute_conditions'] as $attribute_id => $attribute_values) {
            if (is_array($attribute_values) && !empty($attribute_values)) {
                foreach ($attribute_values as $attribute_value) {
                    // Create a JSON structure to store both attribute ID and value
                    $condition_data = json_encode([
                        'attribute_id' => $attribute_id,
                        'attribute_value' => $attribute_value
                    ]);
                    
                    $condition_query = "INSERT INTO discount_conditions (
                                        discount_id, condition_type, condition_value,
                                        status, created_by, updated_by
                                    ) VALUES (
                                        :discount_id, 'attribute', :condition_value,
                                        1, :created_by, :updated_by
                                    )";
                    
                    $condition_stmt = $db->prepare($condition_query);
                    $condition_stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
                    $condition_stmt->bindParam(':condition_value', $condition_data, PDO::PARAM_STR);
                    $condition_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
                    $condition_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
                    
                    $condition_stmt->execute();
                }
            }
        }
    }
    
    // Commit transaction
    $db->commit();
    
    // Log activity
    $action = $discount_id ? "update" : "create";
    $description = $discount_id ? "Updated discount: $discount_name" : "Created new discount: $discount_name";
    
    $log_query = "INSERT INTO activity_logs (
                    user_id, action, module, record_id, description
                ) VALUES (
                    :user_id, :action, 'inventory', :record_id, :description
                )";
    
    $log_stmt = $db->prepare($log_query);
    $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $log_stmt->bindParam(':action', $action, PDO::PARAM_STR);
    $log_stmt->bindParam(':record_id', $discount_id, PDO::PARAM_INT);
    $log_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $log_stmt->execute();
    
    $_SESSION['success_message'] = $success_message;
    header("Location: ../discounts.php");
    exit();
} catch (Exception $e) {
    // Rollback transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Error saving discount: " . $e->getMessage());
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
    
    // Redirect back to form
    if (isset($discount_id) && $discount_id > 0) {
        header("Location: ../discount_form.php?id=" . $discount_id);
    } else {
        header("Location: ../discount_form.php");
    }
    exit();
}
?>
