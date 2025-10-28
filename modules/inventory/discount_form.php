<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Include database connection
require_once '../../config/database.php';
require_once './includes/discount_helpers.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

// Get company currency
$currency_symbol = 'PKR'; // Default currency symbol
try {
    $company_query = "SELECT currency FROM company WHERE id = 1";
    $company_stmt = $db->prepare($company_query);
    $company_stmt->execute();
    $company_data = $company_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($company_data && isset($company_data['currency'])) {
        $currency = $company_data['currency'];
        
        // Set currency symbol based on currency code
        switch ($currency) {
            case 'USD':
                $currency_symbol = '$';
                break;
            case 'EUR':
                $currency_symbol = '€';
                break;
            case 'GBP':
                $currency_symbol = '£';
                break;
            case 'PKR':
                $currency_symbol = 'Rs';
                break;
            default:
                $currency_symbol = $currency;
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching company currency: " . $e->getMessage());
}

// Check if discounts feature is enabled
$enable_discounts = getDiscountSetting($db, 'enable_discounts', '1');
if ($enable_discounts != '1') {
    $_SESSION['error_message'] = "Discount feature is not enabled. Please enable it in settings.";
    header("Location: ./settings.php?tab=discount");
    exit();
}

// Initialize variables
$discount_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit_mode = ($discount_id > 0);
$page_title = $is_edit_mode ? "Edit Discount" : "Add New Discount";
$discount = [];
$conditions = [];

// If edit mode, get discount data
if ($is_edit_mode) {
    $discount = getDiscountById($db, $discount_id);
    
    if (!$discount) {
        $_SESSION['error_message'] = "Discount not found.";
        header("Location: ./discounts.php");
        exit();
    }
    
    $conditions = $discount['conditions'] ?? [];
}

// Get data for condition dropdowns
try {
    // Initialize arrays to prevent undefined variable errors
    $products = $brands = $categories = $attributes = $locations = $suppliers = $customers = [];
    
    // Get products
    $products_query = "SELECT id, product_name, product_code FROM products ORDER BY product_name";
    $products_stmt = $db->prepare($products_query);
    $products_stmt->execute();
    $products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get brands
    $brands_query = "SELECT id, brand_name FROM brands ORDER BY brand_name";
    $brands_stmt = $db->prepare($brands_query);
    $brands_stmt->execute();
    $brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get categories
    $categories_query = "SELECT id, category_name FROM product_categories ORDER BY category_name";
    $categories_stmt = $db->prepare($categories_query);
    $categories_stmt->execute();
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attributes
    $attributes_query = "SELECT id, attribute_name FROM attribute_definitions ORDER BY attribute_name";
    $attributes_stmt = $db->prepare($attributes_query);
    $attributes_stmt->execute();
    $attributes = $attributes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get locations
    $locations_query = "SELECT id, location_name FROM inventory_locations ORDER BY location_name";
    $locations_stmt = $db->prepare($locations_query);
    $locations_stmt->execute();
    $locations = $locations_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get suppliers
    $suppliers_query = "SELECT id, supplier_name FROM suppliers ORDER BY supplier_name";
    $suppliers_stmt = $db->prepare($suppliers_query);
    $suppliers_stmt->execute();
    $suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get customers
    $customers_query = "SELECT id, customer_name FROM customers ORDER BY customer_name";
    $customers_stmt = $db->prepare($customers_query);
    $customers_stmt->execute();
    $customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Log the detailed error for debugging
    error_log("Error fetching data for discount form: " . $e->getMessage());
    
    // Set a more descriptive error message that includes the actual error
    $_SESSION['error_message'] = "Error loading form data: " . $e->getMessage();
    
    // Redirect to discounts list if there's a critical error
    if (empty($products) && empty($brands) && empty($categories)) {
        header("Location: ./discounts.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Inventory Management</title>
    <!-- Include CSS files -->
    <?php include '../../includes/header.php'; ?>
    <!-- Select2 CSS from CDN -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <!-- Date Range Picker CSS from CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    <!-- Custom CSS -->
    <style>
        .tab-content {
            padding: 20px 0;
        }
        .condition-row {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px dashed #ddd;
        }
        .remove-condition {
            color: #dc3545;
            cursor: pointer;
        }
        .select2-container--bootstrap5 .select2-selection--multiple .select2-selection__choice {
            background-color: #007bff;
            border-color: #006fe6;
            color: #fff;
        }
        #sidebar {
            height: 100vh;
            position: fixed;
            overflow-y: auto;
            z-index: 1000;
        }
        #content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
            max-width: calc(100vw - 250px);
            overflow-x: hidden;
        }
        @media (max-width: 768px) {
            #content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div id="wrapper">
        <!-- Include Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <div id="content">
            <!-- Include Navbar -->
            <?php include '../../includes/navbar.php'; ?>
            <div class="container-fluid">
                <div class="row mb-4">
                    <div class="col">
                        <h2><?php echo $page_title; ?></h2>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                                <li class="breadcrumb-item"><a href="./index.php">Inventory</a></li>
                                <li class="breadcrumb-item"><a href="./discounts.php">Discounts</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><?php echo $page_title; ?></li>
                            </ol>
                        </nav>
                    </div>
                </div>

            <!-- Main content -->
                <!-- Display Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['success_message']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['error_message']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
                    
                    <!-- Discount Form Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><?php echo $page_title; ?></h3>
                        </div>
                        <div class="card-body">
                            <form id="discountForm" action="actions/save_discount.php" method="POST">
                                <?php if ($is_edit_mode): ?>
                                    <input type="hidden" name="discount_id" value="<?php echo $discount_id; ?>">
                                <?php endif; ?>
                                
                                <div class="row">
                                    <!-- Basic Discount Information -->
                                    <div class="col-md-6">
                                        <div class="card card-primary">
                                            <div class="card-header">
                                                <h3 class="card-title">Basic Information</h3>
                                            </div>
                                            <div class="card-body">
                                                <div class="form-group">
                                                    <label for="discount_name">Discount Name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" id="discount_name" name="discount_name" 
                                                           value="<?php echo $discount['discount_name'] ?? ''; ?>" required>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="discount_code">Discount Code</label>
                                                    <input type="text" class="form-control" id="discount_code" name="discount_code" 
                                                           value="<?php echo $discount['discount_code'] ?? ''; ?>" 
                                                           <?php echo (!$is_edit_mode && getDiscountSetting($db, 'auto_generate_discount_code', '1') == '1') ? 'readonly' : ''; ?>>
                                                    <?php if (!$is_edit_mode && getDiscountSetting($db, 'auto_generate_discount_code', '1') == '1'): ?>
                                                        <small class="form-text text-muted">Code will be auto-generated</small>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="discount_type">Discount Type <span class="text-danger discount-required">*</span></label>
                                                    <select class="form-control" id="discount_type" name="discount_type">
                                                        <option value="percentage" <?php echo (isset($discount['discount_type']) && $discount['discount_type'] == 'percentage') ? 'selected' : ''; ?>>
                                                            Percentage (%)
                                                        </option>
                                                        <option value="fixed_amount" <?php echo (isset($discount['discount_type']) && $discount['discount_type'] == 'fixed_amount') ? 'selected' : ''; ?>>
                                                            Fixed Amount (<?php echo $currency_symbol; ?>)
                                                        </option>
                                                    </select>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="discount_application">Discount Application <span class="text-danger">*</span></label>
                                                    <select class="form-control" id="discount_application" name="discount_application" required>
                                                        <option value="sales" <?php echo (isset($discount['discount_application']) && $discount['discount_application'] == 'sales') ? 'selected' : ''; ?>>
                                                            Sales Only
                                                        </option>
                                                        <option value="purchase" <?php echo (isset($discount['discount_application']) && $discount['discount_application'] == 'purchase') ? 'selected' : ''; ?>>
                                                            Purchase Only
                                                        </option>
                                                        <option value="both" <?php echo (isset($discount['discount_application']) && $discount['discount_application'] == 'both') ? 'selected' : ''; ?>>
                                                            Both Sales and Purchase
                                                        </option>
                                                    </select>
                                                    <small class="form-text text-muted">Specify where this discount can be applied</small>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="discount_value">Discount Value <span class="text-danger discount-required">*</span></label>
                                                    <div class="input-group">
                                                        <input type="number" class="form-control" id="discount_value" name="discount_value" 
                                                               value="<?php echo $discount['discount_value'] ?? ''; ?>" 
                                                               step="0.01" min="0">
                                                        <div class="input-group-append">
                                                            <span class="input-group-text" id="discount_value_suffix">%</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="date_range">Valid Date Range</label>
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text">
                                                                <i class="far fa-calendar-alt"></i>
                                                            </span>
                                                        </div>
                                                        <input type="text" class="form-control" id="date_range" name="date_range">
                                                        <input type="hidden" id="start_date" name="start_date" value="<?php echo $discount['start_date'] ?? ''; ?>">
                                                        <input type="hidden" id="end_date" name="end_date" value="<?php echo $discount['end_date'] ?? ''; ?>">
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="description">Description</label>
                                                    <textarea class="form-control" id="description" name="description" rows="3"><?php echo $discount['description'] ?? ''; ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Additional Settings -->
                                    <div class="col-md-6">
                                        <div class="card card-info">
                                            <div class="card-header">
                                                <h3 class="card-title">Additional Settings</h3>
                                            </div>
                                            <div class="card-body">
                                                <div class="form-group">
                                                    <label for="minimum_purchase_amount">Minimum Purchase Amount</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><?php echo $currency_symbol; ?></span>
                                                        <input type="number" class="form-control" id="minimum_purchase_amount" name="minimum_purchase_amount" 
                                                               value="<?php echo $discount['minimum_purchase_amount'] ?? '0'; ?>" step="0.01" min="0">
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="minimum_quantity">Minimum Quantity</label>
                                                    <input type="number" class="form-control" id="minimum_quantity" name="minimum_quantity" 
                                                           value="<?php echo $discount['minimum_quantity'] ?? '0'; ?>" min="0">
                                                </div>
                                                
                                                <!-- Additional settings for discount conditions will be added here -->
                                                
                                                <!-- Discount Stacking -->
                                                <div class="form-group mt-4">
                                                    <label>Discount Stacking</label>
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="is_stackable" name="is_stackable" 
                                                               <?php echo (isset($discount['is_stackable']) && $discount['is_stackable'] == 1) ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="is_stackable">Allow stacking with other discounts</label>
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group stacking-options" style="display: <?php echo (isset($discount['is_stackable']) && $discount['is_stackable'] == 1) ? 'block' : 'none'; ?>">
                                                    <label for="stack_priority">Stacking Priority</label>
                                                    <input type="number" class="form-control" id="stack_priority" name="stack_priority" 
                                                           value="<?php echo $discount['stack_priority'] ?? '0'; ?>" min="0">
                                                    <small class="form-text text-muted">Higher priority discounts are applied first</small>
                                                </div>
                                                
                                                <div class="form-group stacking-options" style="display: <?php echo (isset($discount['is_stackable']) && $discount['is_stackable'] == 1) ? 'block' : 'none'; ?>">
                                                    <label for="stack_type">Stacking Type</label>
                                                    <select class="form-control" id="stack_type" name="stack_type">
                                                        <option value="additive" <?php echo (isset($discount['stack_type']) && $discount['stack_type'] == 'additive') ? 'selected' : ''; ?>>Additive (discounts are added together)</option>
                                                        <option value="multiplicative" <?php echo (isset($discount['stack_type']) && $discount['stack_type'] == 'multiplicative') ? 'selected' : ''; ?>>Multiplicative (discounts are applied one after another)</option>
                                                    </select>
                                                </div>
                                                
                                                <!-- Time-Limited Discounts -->
                                                <!-- Approval Requirement -->
                                                <div class="form-group mt-4">
                                                    <label>Approval Requirement</label>
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="requires_approval" name="requires_approval" 
                                                               <?php echo (isset($discount['requires_approval']) && $discount['requires_approval'] == 1) ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="requires_approval">Require admin/manager approval when applying this discount</label>
                                                    </div>
                                                    <small class="form-text text-muted">If enabled, users will need to enter an admin or manager password/PIN to apply this discount during purchase or sale.</small>
                                                </div>

                                                <div class="form-group mt-4">
                                                    <label>Time Restrictions</label>
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="has_time_restriction" name="has_time_restriction" 
                                                               <?php echo (isset($discount['start_time']) || isset($discount['end_time'])) ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="has_time_restriction">Restrict to specific hours</label>
                                                    </div>
                                                </div>
                                                
                                                <div class="row time-restriction-options" style="display: <?php echo (isset($discount['start_time']) || isset($discount['end_time'])) ? 'flex' : 'none'; ?>">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label for="start_time">Start Time</label>
                                                            <input type="time" class="form-control" id="start_time" name="start_time" 
                                                                   value="<?php echo $discount['start_time'] ?? ''; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label for="end_time">End Time</label>
                                                            <input type="time" class="form-control" id="end_time" name="end_time" 
                                                                   value="<?php echo $discount['end_time'] ?? ''; ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group time-restriction-options" style="display: <?php echo (isset($discount['start_time']) || isset($discount['end_time'])) ? 'block' : 'none'; ?>">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="is_flash_sale" name="is_flash_sale" 
                                                               <?php echo (isset($discount['is_flash_sale']) && $discount['is_flash_sale'] == 1) ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="is_flash_sale">Mark as Flash Sale</label>
                                                    </div>
                                                </div>
                                                
                                                <!-- Usage Limits -->
                                                <div class="form-group mt-4">
                                                    <label>Usage Limits</label>
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="has_usage_limit" name="has_usage_limit" 
                                                               <?php echo (isset($discount['usage_limit']) && $discount['usage_limit'] > 0) ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="has_usage_limit">Limit total usage</label>
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group usage-limit-options" style="display: <?php echo (isset($discount['usage_limit']) && $discount['usage_limit'] > 0) ? 'block' : 'none'; ?>">
                                                    <label for="usage_limit">Total Usage Limit</label>
                                                    <input type="number" class="form-control" id="usage_limit" name="usage_limit" 
                                                           value="<?php echo $discount['usage_limit'] ?? ''; ?>" min="1">
                                                    <?php if (isset($discount['usage_count']) && $discount['usage_count'] > 0): ?>
                                                        <small class="form-text text-muted">Used <?php echo $discount['usage_count']; ?> times so far</small>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="form-group usage-limit-options" style="display: <?php echo (isset($discount['usage_limit']) && $discount['usage_limit'] > 0) ? 'block' : 'none'; ?>">
                                                    <label for="per_customer_limit">Per Customer Limit</label>
                                                    <input type="number" class="form-control" id="per_customer_limit" name="per_customer_limit" 
                                                           value="<?php echo $discount['per_customer_limit'] ?? ''; ?>" min="1">
                                                    <small class="form-text text-muted">Leave empty for no per-customer limit</small>
                                                </div>
                                                
                                                <div class="form-group usage-limit-options" style="display: <?php echo (isset($discount['usage_limit']) && $discount['usage_limit'] > 0) ? 'block' : 'none'; ?>">
                                                    <label for="budget_cap">Budget Cap (<?php echo $currency_symbol; ?>)</label>
                                                    <input type="number" class="form-control" id="budget_cap" name="budget_cap" 
                                                           value="<?php echo $discount['budget_cap'] ?? ''; ?>" step="0.01" min="0">
                                                    <small class="form-text text-muted">Maximum total discount amount to be given</small>
                                                    <?php if (isset($discount['budget_used']) && $discount['budget_used'] > 0): ?>
                                                        <small class="form-text text-muted"><?php echo $currency_symbol; ?><?php echo $discount['budget_used']; ?> used so far</small>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <!-- Status -->
                                                <div class="form-group mt-4">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" 
                                                               <?php echo (isset($discount['is_active']) && $discount['is_active'] == 1) || !$is_edit_mode ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="is_active">Active</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Marketing Campaign Integration -->
                                <div class="card card-warning" style="display: none;">
                                    <div class="card-header">
                                        <h3 class="card-title">Marketing Campaign Integration</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="campaign_id">Associated Campaign</label>
                                                    <select class="form-control" id="campaign_id" name="campaign_id">
                                                        <option value="">None</option>
                                                        <?php 
                                                        // Get marketing campaigns if they exist
                                                        try {
                                                            $campaign_query = "SELECT id, campaign_name FROM marketing_campaigns WHERE status = 1 ORDER BY campaign_name ASC";
                                                            $campaign_stmt = $db->prepare($campaign_query);
                                                            $campaign_stmt->execute();
                                                            $campaigns = $campaign_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                            
                                                            foreach ($campaigns as $campaign): 
                                                        ?>
                                                            <option value="<?php echo $campaign['id']; ?>" <?php echo (isset($discount['campaign_id']) && $discount['campaign_id'] == $campaign['id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($campaign['campaign_name']); ?>
                                                            </option>
                                                        <?php 
                                                            endforeach;
                                                        } catch (PDOException $e) {
                                                            // Table might not exist yet if upgrade hasn't been run
                                                            error_log("Error fetching marketing campaigns: " . $e->getMessage());
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="loyalty_tier_id">Required Loyalty Tier</label>
                                                    <select class="form-control" id="loyalty_tier_id" name="loyalty_tier_id">
                                                        <option value="">None (Available to all customers)</option>
                                                        <?php 
                                                        // Get loyalty tiers if they exist
                                                        try {
                                                            $tier_query = "SELECT id, tier_name FROM customer_loyalty_tiers WHERE is_active = 1 ORDER BY min_purchase_amount ASC";
                                                            $tier_stmt = $db->prepare($tier_query);
                                                            $tier_stmt->execute();
                                                            $tiers = $tier_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                            
                                                            foreach ($tiers as $tier): 
                                                        ?>
                                                            <option value="<?php echo $tier['id']; ?>" <?php echo (isset($discount['loyalty_tier_id']) && $discount['loyalty_tier_id'] == $tier['id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($tier['tier_name']); ?>
                                                            </option>
                                                        <?php 
                                                            endforeach;
                                                        } catch (PDOException $e) {
                                                            // Table might not exist yet if upgrade hasn't been run
                                                            error_log("Error fetching loyalty tiers: " . $e->getMessage());
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="utm_source">UTM Source</label>
                                                    <input type="text" class="form-control" id="utm_source" name="utm_source" 
                                                           value="<?php echo $discount['utm_source'] ?? ''; ?>" placeholder="e.g., facebook, newsletter">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="utm_medium">UTM Medium</label>
                                                    <input type="text" class="form-control" id="utm_medium" name="utm_medium" 
                                                           value="<?php echo $discount['utm_medium'] ?? ''; ?>" placeholder="e.g., cpc, email">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="utm_campaign">UTM Campaign</label>
                                                    <input type="text" class="form-control" id="utm_campaign" name="utm_campaign" 
                                                           value="<?php echo $discount['utm_campaign'] ?? ''; ?>" placeholder="e.g., summer_sale">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Buy X Get Y Promotion -->
                                <div class="card card-info">
                                    <div class="card-header">
                                        <h3 class="card-title">Buy X Get Y Promotion</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-group">
                                            <div class="custom-control custom-switch">
                                                <input type="checkbox" class="custom-control-input" id="is_bxgy" name="is_bxgy" 
                                                       <?php echo (isset($discount['buy_quantity']) && $discount['buy_quantity'] > 0) ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="is_bxgy">Enable Buy X Get Y promotion</label>
                                            </div>
                                        </div>
                                        
                                        <div class="row bxgy-options" style="display: <?php echo (isset($discount['buy_quantity']) && $discount['buy_quantity'] > 0) ? 'flex' : 'none'; ?>">
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="buy_quantity">Buy Quantity (X)</label>
                                                    <input type="number" class="form-control" id="buy_quantity" name="buy_quantity" 
                                                           value="<?php echo $discount['buy_quantity'] ?? ''; ?>" min="1">
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="get_quantity">Get Quantity (Y)</label>
                                                    <input type="number" class="form-control" id="get_quantity" name="get_quantity" 
                                                           value="<?php echo $discount['get_quantity'] ?? ''; ?>" min="1">
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="get_discount_percentage">Discount %</label>
                                                    <input type="number" class="form-control" id="get_discount_percentage" name="get_discount_percentage" 
                                                           value="<?php echo $discount['get_discount_percentage'] ?? '100'; ?>" min="1" max="100">
                                                    <small class="form-text text-muted">100% = free product</small>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="get_product_id">Specific Product (Optional)</label>
                                                    <select class="form-control" id="get_product_id" name="get_product_id">
                                                        <option value="">Same as purchased product</option>
                                                        <?php foreach ($products as $product): ?>
                                                            <option value="<?php echo $product['id']; ?>" <?php echo (isset($discount['get_product_id']) && $discount['get_product_id'] == $product['id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($product['product_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Discount Conditions -->
                                <div class="card card-success">
                                    <div class="card-header">
                                        <h3 class="card-title">Discount Conditions</h3>
                                    </div>
                                    <div class="card-body">
                                        <p class="text-muted">
                                            Define conditions for when this discount should be applied. If no conditions are specified, 
                                            the discount will be applied to all applicable transactions.
                                        </p>
                                        
                                        <ul class="nav nav-tabs" id="conditionTabs" role="tablist">
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link active" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab" aria-controls="products" aria-selected="true">
                                                    Products
                                                </button>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link" id="brands-tab" data-bs-toggle="tab" data-bs-target="#brands" type="button" role="tab" aria-controls="brands" aria-selected="false">
                                                    Brands
                                                </button>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab" aria-controls="categories" aria-selected="false">
                                                    Categories
                                                </button>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link" id="attributes-tab" data-bs-toggle="tab" data-bs-target="#attributes" type="button" role="tab" aria-controls="attributes" aria-selected="false">
                                                    Attributes
                                                </button>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link" id="locations-tab" data-bs-toggle="tab" data-bs-target="#locations" type="button" role="tab" aria-controls="locations" aria-selected="false">
                                                    Locations
                                                </button>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link" id="suppliers-tab" data-bs-toggle="tab" data-bs-target="#suppliers" type="button" role="tab" aria-controls="suppliers" aria-selected="false">
                                                    Suppliers
                                                </button>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link" id="customers-tab" data-bs-toggle="tab" data-bs-target="#customers" type="button" role="tab" aria-controls="customers" aria-selected="false">
                                                    Customers
                                                </button>
                                            </li>
                                        </ul>
                                        
                                        <div class="tab-content" id="conditionTabsContent">
                                            <!-- Products Tab -->
                                            <div class="tab-pane fade show active" id="products" role="tabpanel" aria-labelledby="products-tab">
                                                <div class="form-group">
                                                    <label>Select Products</label>
                                                    <select class="select2" multiple="multiple" id="product_conditions" name="product_conditions[]" style="width: 100%;">
                                                        <?php foreach ($products as $product): ?>
                                                            <option value="<?php echo $product['id']; ?>" 
                                                                <?php 
                                                                    if (!empty($conditions)) {
                                                                        foreach ($conditions as $condition) {
                                                                            if ($condition['condition_type'] == 'product' && $condition['condition_value'] == $product['id']) {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                ?>>
                                                                <?php echo $product['product_name'] . ' (' . $product['product_code'] . ')'; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Discount will apply only to selected products</small>
                                                </div>
                                            </div>
                                            
                                            <!-- Brands Tab -->
                                            <div class="tab-pane fade" id="brands" role="tabpanel" aria-labelledby="brands-tab">
                                                <div class="form-group">
                                                    <label>Select Brands</label>
                                                    <select class="select2" multiple="multiple" id="brand_conditions" name="brand_conditions[]" style="width: 100%;">
                                                        <?php foreach ($brands as $brand): ?>
                                                            <option value="<?php echo $brand['id']; ?>"
                                                                <?php 
                                                                    if (!empty($conditions)) {
                                                                        foreach ($conditions as $condition) {
                                                                            if ($condition['condition_type'] == 'brand' && $condition['condition_value'] == $brand['id']) {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                ?>>
                                                                <?php echo $brand['brand_name']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Discount will apply only to products from selected brands</small>
                                                </div>
                                            </div>
                                            
                                            <!-- Categories Tab -->
                                            <div class="tab-pane fade" id="categories" role="tabpanel" aria-labelledby="categories-tab">
                                                <div class="form-group">
                                                    <label>Select Categories</label>
                                                    <select class="select2" multiple="multiple" id="category_conditions" name="category_conditions[]" style="width: 100%;">
                                                        <?php foreach ($categories as $category): ?>
                                                            <option value="<?php echo $category['id']; ?>"
                                                                <?php 
                                                                    if (!empty($conditions)) {
                                                                        foreach ($conditions as $condition) {
                                                                            if ($condition['condition_type'] == 'category' && $condition['condition_value'] == $category['id']) {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                ?>>
                                                                <?php echo $category['category_name']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Discount will apply only to products in selected categories</small>
                                                </div>
                                            </div>
                                            
                                            <!-- Attributes Tab -->
                                            <div class="tab-pane fade" id="attributes" role="tabpanel" aria-labelledby="attributes-tab">
                                                <div class="form-group mb-3">
                                                    <label>Select Attribute</label>
                                                    <select class="form-control" id="attribute_selector" style="width: 100%;">
                                                        <option value="">-- Select an attribute --</option>
                                                        <?php foreach ($attributes as $attribute): ?>
                                                            <option value="<?php echo $attribute['id']; ?>" data-type="<?php echo $attribute['input_type'] ?? 'text'; ?>">
                                                                <?php echo $attribute['attribute_name']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                
                                                <div id="attribute_value_container" style="display: none;">
                                                    <div class="form-group">
                                                        <label>Select Attribute Value(s)</label>
                                                        <select class="select2" multiple="multiple" id="attribute_value_conditions" name="attribute_value_conditions[]" style="width: 100%;">
                                                            <!-- Values will be populated dynamically -->
                                                        </select>
                                                        <small class="form-text text-muted">Discount will apply only to products with selected attribute values</small>
                                                    </div>
                                                </div>
                                                
                                                <div class="mt-3">
                                                    <h6>Selected Attribute Conditions</h6>
                                                    <div id="selected_attribute_conditions" class="border p-2 rounded" style="min-height: 50px;">
                                                        <?php if (!empty($conditions)): ?>
                                                            <?php foreach ($conditions as $condition): ?>
                                                                <?php if ($condition['condition_type'] == 'attribute'): ?>
                                                                    <?php 
                                                                        // For attribute conditions, the condition_value is stored as JSON
                                                                        $attr_data = json_decode($condition['condition_value'], true);
                                                                        $attr_id = isset($attr_data['attribute_id']) ? $attr_data['attribute_id'] : 0;
                                                                        $attr_value = isset($attr_data['attribute_value']) ? $attr_data['attribute_value'] : '';
                                                                        
                                                                        // Get attribute name
                                                                        $attr_name = '';
                                                                        foreach ($attributes as $attr) {
                                                                            if ($attr['id'] == $attr_id) {
                                                                                $attr_name = $attr['attribute_name'];
                                                                                break;
                                                                            }
                                                                        }
                                                                        
                                                                        // Debug output
                                                                        error_log("Attribute condition: " . $condition['condition_value'] . " " . __FILE__ . " on line " . __LINE__);
                                                                    ?>
                                                                    <div class="badge bg-info m-1 p-2">
                                                                        <?php echo $attr_name; ?>: <?php echo $attr_value; ?>
                                                                        <input type="hidden" name="attribute_conditions[<?php echo $attr_id; ?>][]" value="<?php echo $attr_value; ?>">
                                                                        <span class="ms-2 remove-attribute-condition" data-id="<?php echo $condition['id']; ?>" style="cursor: pointer;">×</span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Locations Tab -->
                                            <div class="tab-pane fade" id="locations" role="tabpanel" aria-labelledby="locations-tab">
                                                <div class="alert alert-info">
                                                    <h6><i class="fas fa-info-circle"></i> How Location Discounts Work</h6>
                                                    <p class="small mb-0">
                                                        Location-based discounts apply when products are purchased from or sold to specific inventory locations.
                                                        When products are added to inventory through purchase orders, they are assigned to the selected location.
                                                        During sales, the system checks if the transaction location matches any location-based discount conditions.
                                                    </p>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label>Select Locations</label>
                                                    <select class="select2" multiple="multiple" id="location_conditions" name="location_conditions[]" style="width: 100%;">
                                                        <?php foreach ($locations as $location): ?>
                                                            <option value="<?php echo $location['id']; ?>"
                                                                <?php 
                                                                    if (!empty($conditions)) {
                                                                        foreach ($conditions as $condition) {
                                                                            if ($condition['condition_type'] == 'location' && $condition['condition_value'] == $location['id']) {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                ?>>
                                                                <?php echo $location['location_name']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Discount will apply only to transactions at these locations</small>
                                                </div>
                                            </div>
                                            
                                            <!-- Suppliers Tab -->
                                            <div class="tab-pane fade" id="suppliers" role="tabpanel" aria-labelledby="suppliers-tab">
                                                <div class="form-group">
                                                    <label>Select Suppliers</label>
                                                    <select class="select2" multiple="multiple" id="supplier_conditions" name="supplier_conditions[]" style="width: 100%;">
                                                        <?php foreach ($suppliers as $supplier): ?>
                                                            <option value="<?php echo $supplier['id']; ?>"
                                                                <?php 
                                                                    if (!empty($conditions)) {
                                                                        foreach ($conditions as $condition) {
                                                                            if ($condition['condition_type'] == 'supplier' && $condition['condition_value'] == $supplier['id']) {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                ?>>
                                                                <?php echo $supplier['supplier_name']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Discount will apply only to products from selected suppliers</small>
                                                </div>
                                            </div>
                                            
                                            <!-- Customers Tab -->
                                            <div class="tab-pane fade" id="customers" role="tabpanel" aria-labelledby="customers-tab">
                                                <div class="form-group">
                                                    <label>Select Customers</label>
                                                    <select class="select2" multiple="multiple" id="customer_conditions" name="customer_conditions[]" style="width: 100%;">
                                                        <?php foreach ($customers as $customer): ?>
                                                            <option value="<?php echo $customer['id']; ?>"
                                                                <?php 
                                                                    if (!empty($conditions)) {
                                                                        foreach ($conditions as $condition) {
                                                                            if ($condition['condition_type'] == 'customer' && $condition['condition_value'] == $customer['id']) {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                ?>>
                                                                <?php echo $customer['customer_name']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Discount will apply only to selected customers (loyalty program)</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Form Actions -->
                                <div class="row">
                                    <div class="col-12">
                                        <a href="discounts.php" class="btn btn-secondary">Cancel</a>
                                        <button type="submit" class="btn btn-primary float-right">
                                            <?php echo $is_edit_mode ? 'Update Discount' : 'Create Discount'; ?>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>
    
    <!-- jQuery and Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Select2 from CDN -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- Moment.js and DateRangePicker from CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    
    <!-- Page specific script -->
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.select2').select2({
                theme: 'bootstrap-5'
            });
            
            // Initialize DateRangePicker
            $('#date_range').daterangepicker({
                autoUpdateInput: false,
                locale: {
                    cancelLabel: 'Clear',
                    format: 'YYYY-MM-DD'
                }
            });
            
            // Set date range if editing
            <?php if (!empty($discount['start_date']) && !empty($discount['end_date'])): ?>
                $('#date_range').val('<?php echo $discount['start_date']; ?> - <?php echo $discount['end_date']; ?>');
            <?php endif; ?>
            
            $('#date_range').on('apply.daterangepicker', function(ev, picker) {
                $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
                $('#start_date').val(picker.startDate.format('YYYY-MM-DD'));
                $('#end_date').val(picker.endDate.format('YYYY-MM-DD'));
            });
            
            $('#date_range').on('cancel.daterangepicker', function(ev, picker) {
                $(this).val('');
                $('#start_date').val('');
                $('#end_date').val('');
            });
            
            // Update discount value suffix based on type
            $('#discount_type').change(function() {
                updateDiscountValueSuffix();
            });
            
            function updateDiscountValueSuffix() {
                var discountType = $('#discount_type').val();
                if (discountType === 'percentage') {
                    $('#discount_value_suffix').text('%');
                } else {
                    $('#discount_value_suffix').text('<?php echo $currency_symbol; ?>');
                }
            }
            
            // Initialize suffix
            updateDiscountValueSuffix();
            
            // Discount Stacking Toggle
            $('#is_stackable').change(function() {
                if($(this).is(':checked')) {
                    $('.stacking-options').slideDown();
                } else {
                    $('.stacking-options').slideUp();
                }
            });
            
            // Time Restriction Toggle
            $('#has_time_restriction').change(function() {
                if($(this).is(':checked')) {
                    $('.time-restriction-options').slideDown();
                } else {
                    $('.time-restriction-options').slideUp();
                    $('#start_time, #end_time').val('');
                    $('#is_flash_sale').prop('checked', false);
                }
            });
            
            // Usage Limit Toggle
            $('#has_usage_limit').change(function() {
                if($(this).is(':checked')) {
                    $('.usage-limit-options').slideDown();
                } else {
                    $('.usage-limit-options').slideUp();
                    $('#usage_limit, #per_customer_limit, #budget_cap').val('');
                }
            });
            
            // Buy X Get Y Toggle
            $('#is_bxgy').change(function() {
                if($(this).is(':checked')) {
                    $('.bxgy-options').css('display', 'flex');
                    // Disable discount type and value fields
                    $('#discount_type').prop('disabled', true);
                    $('#discount_value').prop('disabled', true);
                    // Make discount type and value not required
                    $('#discount_type').removeAttr('required');
                    $('#discount_value').removeAttr('required');
                    $('.discount-required').hide();
                } else {
                    $('.bxgy-options').hide();
                    $('#buy_quantity, #get_quantity').val('');
                    $('#get_product_id').val('');
                    $('#get_discount_percentage').val('100');
                    // Enable discount type and value fields
                    $('#discount_type').prop('disabled', false);
                    $('#discount_value').prop('disabled', false);
                    // Make discount type and value required again
                    $('#discount_type').attr('required', 'required');
                    $('#discount_value').attr('required', 'required');
                    $('.discount-required').show();
                }
            });
            
            // Initialize BXGY state on page load
            if($('#is_bxgy').is(':checked')) {
                $('#discount_type').prop('disabled', true);
                $('#discount_value').prop('disabled', true);
                $('#discount_type').removeAttr('required');
                $('#discount_value').removeAttr('required');
                $('.discount-required').hide();
            }
            
            // Form validation
            $('#discountForm').submit(function(e) {
                var isValid = true;
                var isBxgyEnabled = $('#is_bxgy').is(':checked');
                
                // Basic validation
                if ($('#discount_name').val().trim() === '') {
                    alert('Please enter a discount name');
                    $('#discount_name').focus();
                    isValid = false;
                    return false;
                }
                
                // Skip discount value validation if Buy X Get Y is enabled
                if (!isBxgyEnabled) {
                    if ($('#discount_value').val().trim() === '' || parseFloat($('#discount_value').val()) <= 0) {
                        alert('Please enter a valid discount value greater than zero');
                        $('#discount_value').focus();
                        isValid = false;
                        return false;
                    }
                    
                    // If percentage discount, ensure it's not more than 100%
                    if ($('#discount_type').val() === 'percentage' && parseFloat($('#discount_value').val()) > 100) {
                        e.preventDefault();
                        toastr.error('Percentage discount cannot be more than 100%');
                        return false;
                    }
                } else {
                    // Validate Buy X Get Y fields
                    if ($('#buy_quantity').val().trim() === '' || parseInt($('#buy_quantity').val()) <= 0) {
                        e.preventDefault();
                        toastr.error('Please enter a valid Buy Quantity (X) greater than zero');
                        $('#buy_quantity').focus();
                        return false;
                    }
                    
                    if ($('#get_quantity').val().trim() === '' || parseInt($('#get_quantity').val()) <= 0) {
                        e.preventDefault();
                        toastr.error('Please enter a valid Get Quantity (Y) greater than zero');
                        $('#get_quantity').focus();
                        return false;
                    }
                }
                
                return true;
            });
            
            // Attribute Value Selection Functionality
            $('#attribute_selector').on('change', function() {
                const attributeId = $(this).val();
                const attributeName = $(this).find('option:selected').text();
                const inputType = $(this).find('option:selected').data('type');
                
                if (!attributeId) {
                    $('#attribute_value_container').hide();
                    return;
                }
                
                // Show the attribute value container
                $('#attribute_value_container').show();
                
                // Clear previous values
                $('#attribute_value_conditions').empty().trigger('change');
                
                // Fetch attribute values for the selected attribute
                console.log('Fetching attribute values for ID:', attributeId, 'Type:', inputType);
                
                $.ajax({
                    url: 'actions/get_attribute_values.php',
                    type: 'POST',
                    data: { attribute_id: attributeId },
                    dataType: 'json',
                    success: function(response) {
                        console.log('Attribute values response:', response);
                        
                        if (response.success && response.values) {
                            // Get the values array
                            const values = response.values;
                            console.log('Values to populate:', values);
                            
                            // Clear the dropdown completely
                            const selectElement = document.getElementById('attribute_value_conditions');
                            selectElement.innerHTML = '';
                            
                            // Process each value
                            if (Array.isArray(values)) {
                                values.forEach(function(value) {
                                    // Create a new option element
                                    const option = document.createElement('option');
                                    
                                    // Handle different value formats
                                    if (typeof value === 'object' && value !== null && value.hasOwnProperty('label')) {
                                        option.text = value.label;
                                        option.value = value.value || value.label;
                                        console.log('Added option with label/value:', option.text, option.value);
                                    } else if (typeof value === 'string') {
                                        option.text = value;
                                        option.value = value;
                                        console.log('Added string option:', value);
                                    } else {
                                        // Fallback for other types
                                        const text = String(value);
                                        option.text = text;
                                        option.value = text;
                                        console.log('Added fallback option:', text);
                                    }
                                    
                                    // Add the option to the select element
                                    selectElement.appendChild(option);
                                });
                            } else {
                                console.log('Values is not an array');
                                // Add a placeholder option
                                const option = document.createElement('option');
                                option.text = 'No valid values found';
                                option.value = '';
                                selectElement.appendChild(option);
                            }
                            
                            // Initialize or refresh Select2
                            $(selectElement).trigger('change');
                        } else {
                            console.log('No values found or error in response');
                            // If no values found, show a message
                            const option = document.createElement('option');
                            option.text = 'No values found';
                            option.value = '';
                            document.getElementById('attribute_value_conditions').appendChild(option);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        console.log('Response text:', xhr.responseText);
                        toastr.error('Error fetching attribute values');
                    }
                });
            });
            
            // Add attribute condition when values are selected
            $('#attribute_value_conditions').on('change', function() {
                const attributeId = $('#attribute_selector').val();
                const attributeName = $('#attribute_selector option:selected').text();
                const selectedValues = $(this).val();
                
                if (!attributeId || !selectedValues || selectedValues.length === 0) {
                    return;
                }
                
                // Add each selected value as a condition
                $.each(selectedValues, function(index, value) {
                    if (!value) return; // Skip empty values
                    
                    // Get the display text for the value
                    let displayValue = value;
                    let storeValue = value;
                    
                    // If using select2, get the text from the selected option
                    const selectedOption = $('#attribute_value_conditions option[value="' + value + '"]');
                    if (selectedOption.length > 0) {
                        displayValue = selectedOption.text();
                    }
                    
                    // Create a badge for the selected attribute value
                    const badge = `
                        <div class="badge bg-info m-1 p-2">
                            ${attributeName}: ${displayValue}
                            <input type="hidden" name="attribute_conditions[${attributeId}][]" value="${storeValue}">
                            <span class="ms-2 remove-attribute-condition" style="cursor: pointer;">×</span>
                        </div>
                    `;
                    
                    // Add the badge to the selected conditions container
                    $('#selected_attribute_conditions').append(badge);
                });
                
                // Clear the selection
                $(this).val(null).trigger('change');
            });
            
            // Remove attribute condition when × is clicked
            $(document).on('click', '.remove-attribute-condition', function() {
                $(this).closest('.badge').remove();
            });
        });
    </script>
</body>
</html>
