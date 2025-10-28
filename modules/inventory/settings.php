<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Check if user has admin role
if ($_SESSION['role_id'] != 1) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
require_once './includes/discount_helpers.php';
$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

/**
 * General function to update any comma-separated setting
 * 
 * @param PDO $db Database connection
 * @param string $group Setting group (e.g., 'stock', 'attributes', 'uom')
 * @param string $key Setting key (e.g., 'default_adjustment_reasons', 'allowed_input_types')
 * @param string $value Raw value from form
 * @param string $label Human-readable label for the setting
 * @param string $input_type Type of input (e.g., 'textarea', 'text')
 * @return bool True if successful, false otherwise
 */
function updateCommaSeparatedSetting($db, $group, $key, $value, $label, $input_type = 'text') {
    try {
        // Clean up the value
        $value = rtrim($value, ', ');
        
        // Split by comma, trim each value, and rejoin
        $reasons = explode(',', $value);
        $cleaned_reasons = [];
        foreach ($reasons as $reason) {
            $trimmed = trim($reason);
            if (!empty($trimmed)) {
                $cleaned_reasons[] = $trimmed;
            }
        }
        $cleaned_value = implode(',', $cleaned_reasons);
        
        // Update the setting
        $query = "UPDATE inventory_settings SET 
                 setting_value = ?,
                 updated_at = NOW(),
                 updated_by = ?
                 WHERE setting_group = ? AND setting_key = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$cleaned_value, $_SESSION['user_id'], $group, $key]);
        
        $affected = $stmt->rowCount();
        error_log("Direct update of {$group}.{$key} affected {$affected} rows");
        
        if ($affected == 0) {
            // Try to insert if it doesn't exist
            $insert = "INSERT INTO inventory_settings 
                      (setting_group, setting_key, setting_value, setting_label, input_type, created_at, created_by) 
                      VALUES (?, ?, ?, ?, ?, NOW(), ?)";
            $insert_stmt = $db->prepare($insert);
            $insert_stmt->execute([$group, $key, $cleaned_value, $label, $input_type, $_SESSION['user_id']]);
            error_log("Inserted {$group}.{$key}: " . ($insert_stmt->rowCount() > 0 ? 'Success' : 'Failed'));
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error updating {$group}.{$key}: " . $e->getMessage());
        return false;
    }
}

/**
 * Special function to update default_adjustment_reasons (for backward compatibility)
 */
function updateDefaultAdjustmentReasons($db, $value) {
    return updateCommaSeparatedSetting($db, 'stock', 'default_adjustment_reasons', $value, 'Default Adjustment Reason Options', 'textarea');
}

/**
 * Function to update a setting
 */
function updateSetting($db, $group, $key, $value, $label, $input_type = 'text') {
    try {
        // Update the setting
        $query = "UPDATE inventory_settings SET 
                 setting_value = ?,
                 updated_at = NOW(),
                 updated_by = ?
                 WHERE setting_group = ? AND setting_key = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$value, $_SESSION['user_id'], $group, $key]);
        
        $affected = $stmt->rowCount();
        
        if ($affected == 0) {
            // Try to insert if it doesn't exist
            $insert = "INSERT INTO inventory_settings 
                      (setting_group, setting_key, setting_value, setting_label, input_type, created_at, created_by) 
                      VALUES (?, ?, ?, ?, ?, NOW(), ?)";
            $insert_stmt = $db->prepare($insert);
            $insert_stmt->execute([$group, $key, $value, $label, $input_type, $_SESSION['user_id']]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error updating {$group}.{$key}: " . $e->getMessage());
        return false;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Start transaction
        $db->beginTransaction();

        // First, get all checkbox settings and set them to 0 (unchecked)
        $checkbox_query = "SELECT setting_key FROM inventory_settings WHERE input_type = 'checkbox'";
        $checkbox_stmt = $db->prepare($checkbox_query);
        $checkbox_stmt->execute();
        $checkboxes = $checkbox_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set all checkboxes to 0 by default
        foreach ($checkboxes as $checkbox) {
            $query = "UPDATE inventory_settings SET 
                     setting_value = '0',
                     updated_at = NOW(),
                     updated_by = ?
                     WHERE setting_key = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$_SESSION['user_id'], $checkbox['setting_key']]);
        }
        
        // Debug: Log all POST data
        error_log("Settings form submitted with data: " . json_encode($_POST));
        
        // Special debug for stock settings
        if (isset($_POST['settings']) && isset($_POST['settings']['stock'])) {
            error_log("STOCK SETTINGS RAW DATA: " . json_encode($_POST['settings']['stock']));
            
            if (isset($_POST['settings']['stock']['default_adjustment_reasons'])) {
                $raw_value = $_POST['settings']['stock']['default_adjustment_reasons'];
                error_log("RAW default_adjustment_reasons: " . $raw_value);
                error_log("LENGTH: " . strlen($raw_value));
                error_log("HEX: " . bin2hex($raw_value));
                
                // Directly update the default_adjustment_reasons setting
                $update_result = updateDefaultAdjustmentReasons($db, $raw_value);
                error_log("Direct update of default_adjustment_reasons result: " . ($update_result ? 'Success' : 'Failed'));
            } else {
                error_log("default_adjustment_reasons NOT FOUND in POST data");
            }
        }
        
        // Special handling for comma-separated settings
        
        // 1. Stock settings
        if (isset($_POST['settings']) && isset($_POST['settings']['stock'])) {
            // Default Adjustment Reasons
            if (isset($_POST['settings']['stock']['default_adjustment_reasons'])) {
                $raw_value = $_POST['settings']['stock']['default_adjustment_reasons'];
                error_log("DIRECT HANDLING: Processing default_adjustment_reasons value: {$raw_value}");
                
                // Force update the default_adjustment_reasons setting
                $update_result = updateCommaSeparatedSetting($db, 'stock', 'default_adjustment_reasons', 
                                                           $raw_value, 'Default Adjustment Reason Options', 'textarea');
                error_log("DIRECT HANDLING: Update result: " . ($update_result ? 'Success' : 'Failed'));
            }
            
            // Transaction Number Prefix
            if (isset($_POST['settings']['stock']['transaction_number_prefix'])) {
                $prefix_value = trim($_POST['settings']['stock']['transaction_number_prefix']);
                error_log("DIRECT HANDLING: Processing transaction_number_prefix value: {$prefix_value}");
                
                // Force update the transaction_number_prefix setting
                $query = "UPDATE inventory_settings SET 
                         setting_value = ?,
                         updated_at = NOW(),
                         updated_by = ?
                         WHERE setting_group = 'stock' AND setting_key = 'transaction_number_prefix'";
                $stmt = $db->prepare($query);
                $stmt->execute([$prefix_value, $_SESSION['user_id']]);
                
                $affected = $stmt->rowCount();
                error_log("Direct update of transaction_number_prefix affected {$affected} rows");
                
                if ($affected == 0) {
                    // Try to insert if it doesn't exist
                    $insert = "INSERT INTO inventory_settings 
                              (setting_group, setting_key, setting_value, setting_label, input_type, created_at, created_by) 
                              VALUES ('stock', 'transaction_number_prefix', ?, 'Transaction Number Prefix', 'text', NOW(), ?)";
                    $insert_stmt = $db->prepare($insert);
                    $insert_stmt->execute([$prefix_value, $_SESSION['user_id']]);
                    error_log("Inserted transaction_number_prefix: " . ($insert_stmt->rowCount() > 0 ? 'Success' : 'Failed'));
                }
            }
        }
        
        // 2. Attributes settings
        if (isset($_POST['settings']) && isset($_POST['settings']['attributes'])) {
            // Allowed Input Types
            if (isset($_POST['settings']['attributes']['allowed_input_types'])) {
                $raw_value = $_POST['settings']['attributes']['allowed_input_types'];
                error_log("DIRECT HANDLING: Processing allowed_input_types value: {$raw_value}");
                
                // Force update the allowed_input_types setting
                $update_result = updateCommaSeparatedSetting($db, 'attributes', 'allowed_input_types', 
                                                           $raw_value, 'Allowed Input Types', 'text');
                error_log("DIRECT HANDLING: Update result: " . ($update_result ? 'Success' : 'Failed'));
            }
        }
        
        // 3. UOM settings
        if (isset($_POST['settings']) && isset($_POST['settings']['uom'])) {
            // UOM Types
            if (isset($_POST['settings']['uom']['uom_types'])) {
                $raw_value = $_POST['settings']['uom']['uom_types'];
                error_log("DIRECT HANDLING: Processing uom_types value: {$raw_value}");
                
                // Force update the uom_types setting
                $update_result = updateCommaSeparatedSetting($db, 'uom', 'uom_types', 
                                                           $raw_value, 'UOM Types', 'text');
                error_log("DIRECT HANDLING: Update result: " . ($update_result ? 'Success' : 'Failed'));
            }
            
            // Update inventory accounting settings
            if (isset($_POST['inventory_asset_account_id'])) {
                updateSetting($db, 'accounting', 'inventory_asset_account_id', $_POST['inventory_asset_account_id'], 'Inventory Asset Account', 'select');
            }
            
            if (isset($_POST['inventory_adjustment_account_id'])) {
                updateSetting($db, 'accounting', 'inventory_adjustment_account_id', $_POST['inventory_adjustment_account_id'], 'Inventory Adjustment Account', 'select');
            }
            
            if (isset($_POST['cogs_account_id'])) {
                updateSetting($db, 'accounting', 'cogs_account_id', $_POST['cogs_account_id'], 'Cost of Goods Sold Account', 'select');
            }
        }
        
        // Now process all submitted settings
        foreach ($_POST as $key => $value) {
            // Handle direct setting_ prefix
            if (strpos($key, 'setting_') === 0) {
                $setting_key = substr($key, 8); // Remove 'setting_' prefix
                
                // Debug: Log the setting being updated
                error_log("Updating direct setting: {$setting_key} with value: {$value}");
                
                $query = "UPDATE inventory_settings SET 
                         setting_value = ?,
                         updated_at = NOW(),
                         updated_by = ?
                         WHERE setting_key = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$value, $_SESSION['user_id'], $setting_key]);
                
                // Verify update was successful
                $affected_rows = $stmt->rowCount();
                error_log("Direct setting update affected {$affected_rows} rows");
                
                if ($affected_rows == 0) {
                    // Setting might not exist, try to insert it
                    error_log("Setting {$setting_key} not found, attempting to insert");
                    // Code to insert new setting could be added here if needed
                }
            }
            
            // Handle nested settings array (e.g., settings[uom][auto_generate_uom_code])
            if ($key === 'settings' && is_array($value)) {
                foreach ($value as $group => $group_settings) {
                    if (is_array($group_settings)) {
                        // Debug: Log the group being processed
                        error_log("Processing settings group: {$group}");
                        
                        foreach ($group_settings as $setting_key => $setting_value) {
                            // Skip specially handled settings as we handled them separately
                            if (($group === 'stock' && $setting_key === 'default_adjustment_reasons') ||
                                ($group === 'stock' && $setting_key === 'transaction_number_prefix') ||
                                ($group === 'attributes' && $setting_key === 'allowed_input_types') ||
                                ($group === 'uom' && $setting_key === 'uom_types')) {
                                error_log("Skipping {$group}.{$setting_key} in regular processing as it was handled directly");
                                continue;
                            }
                            
                            // Debug: Log the setting being updated
                            error_log("Updating {$group} setting: {$setting_key} with value: {$setting_value}");
                            
                            $query = "UPDATE inventory_settings SET 
                                     setting_value = ?,
                                     updated_at = NOW(),
                                     updated_by = ?
                                     WHERE setting_group = ? AND setting_key = ?";
                            $stmt = $db->prepare($query);
                            $stmt->execute([$setting_value, $_SESSION['user_id'], $group, $setting_key]);
                            
                            // Verify update was successful
                            $affected_rows = $stmt->rowCount();
                            error_log("{$group} setting update affected {$affected_rows} rows");
                            
                            // Verify the setting was actually updated by querying it back
                            $verify_query = "SELECT setting_value FROM inventory_settings WHERE setting_group = ? AND setting_key = ? LIMIT 1";
                            $verify_stmt = $db->prepare($verify_query);
                            $verify_stmt->execute([$group, $setting_key]);
                            $verify_result = $verify_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($verify_result) {
                                error_log("Verified {$group}.{$setting_key} is now: {$verify_result['setting_value']}");
                            } else {
                                error_log("WARNING: Could not verify {$group}.{$setting_key} update");
                                
                                // Try to insert if it doesn't exist
                                $insert_query = "INSERT INTO inventory_settings (setting_group, setting_key, setting_value, setting_label, input_type, created_at, created_by) 
                                                VALUES (?, ?, ?, ?, ?, NOW(), ?)"; 
                                $insert_stmt = $db->prepare($insert_query);
                                $insert_stmt->execute([$group, $setting_key, $setting_value, ucwords(str_replace('_', ' ', $setting_key)), 'text', $_SESSION['user_id']]);
                                error_log("INSERT attempt result: " . ($insert_stmt->rowCount() > 0 ? 'Success' : 'Failed'));
                            }
                        }
                    }
                }
            }
        }

        $db->commit();
        $success_message = "Inventory settings updated successfully!";
    } catch (PDOException $e) {
        $db->rollBack();
        $error_message = "Error updating settings: " . $e->getMessage();
        error_log("Error in inventory settings update: " . $e->getMessage());
    }
}

// Check if barcode settings exist and install them if they don't
try {
    $check_barcode_query = "SELECT COUNT(*) as count FROM inventory_settings WHERE setting_group = 'barcode'";
    $check_barcode_stmt = $db->prepare($check_barcode_query);
    $check_barcode_stmt->execute();
    $result = $check_barcode_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] < 1) {
        // Install barcode settings
        $sql_file = file_get_contents('sql/add_barcode_settings.sql');
        if ($sql_file) {
            // Split into individual statements
            $statements = explode(';', $sql_file);
            
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    $db->exec($statement);
                }
            }
            
            $success_message = "Barcode settings installed successfully.";
        }
    }
} catch (PDOException $e) {
    $error_message = "Error checking barcode settings: " . $e->getMessage();
    error_log("Error checking barcode settings: " . $e->getMessage());
}

// Get all settings grouped by category
try {
    $query = "SELECT * FROM inventory_settings ORDER BY setting_group, display_order, display_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group settings by category
    $settings = [];
    foreach ($all_settings as $setting) {
        $settings[$setting['setting_group']][] = $setting;
    }
} catch (PDOException $e) {
    $error_message = "Error fetching settings: " . $e->getMessage();
    error_log("Error fetching inventory settings: " . $e->getMessage());
}

// Get all chart of accounts for selection in accounting tab
try {
    $accounts_query = "SELECT id, account_code, account_name FROM chart_of_accounts WHERE status = 1 ORDER BY account_code";
    $accounts_stmt = $db->prepare($accounts_query);
    $accounts_stmt->execute();
    $accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching chart of accounts: " . $e->getMessage();
    error_log("Error fetching chart of accounts: " . $e->getMessage());
}

// Get current accounting settings
try {
    $accounting_query = "SELECT setting_key, setting_value FROM inventory_settings WHERE setting_group = 'accounting'";
    $accounting_stmt = $db->prepare($accounting_query);
    $accounting_stmt->execute();
    $accounting_settings = [];
    
    while ($row = $accounting_stmt->fetch(PDO::FETCH_ASSOC)) {
        $accounting_settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Set default values if not set
    $inventory_asset_account_id = $accounting_settings['inventory_asset_account_id'] ?? '';
    $inventory_adjustment_account_id = $accounting_settings['inventory_adjustment_account_id'] ?? '';
    $cogs_account_id = $accounting_settings['cogs_account_id'] ?? '';
} catch (PDOException $e) {
    $error_message = "Error fetching accounting settings: " . $e->getMessage();
    error_log("Error fetching accounting settings: " . $e->getMessage());
}

// Include helper functions
require_once '../../includes/company_helpers.php';
require_once 'includes/inventory_helpers.php';
require_once 'includes/category_settings_helper.php';
require_once 'includes/brand_settings_helper.php';
require_once 'includes/attribute_settings_helper.php';
require_once 'includes/uom_settings_helper.php';

// Include header
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col">
                    <h2>Inventory Settings</h2>
                    <p class="text-muted">Configure your inventory module to match your business needs</p>
                </div>
            </div>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <!-- Settings Tabs -->
                        <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
                            <?php 
                            // Reorder tabs to ensure general is first and barcode is second
                            $ordered_groups = [];
                            $has_general = false;
                            $has_barcode = false;
                            
                            // Check if general exists
                            if (isset($settings['general'])) {
                                $ordered_groups[] = 'general';
                                $has_general = true;
                            }
                            
                            // Check if barcode exists in settings
                            if (isset($settings['barcode'])) {
                                $ordered_groups[] = 'barcode';
                                $has_barcode = true;
                            }
                            
                            // Add remaining groups
                            foreach ($settings as $group => $group_settings) {
                                if ($group != 'general' && $group != 'barcode' && $group != 'attributes' && $group != 'uom' && $group != 'accounting') {
                                    $ordered_groups[] = $group;
                                }
                            }
                            
                            $first_tab = true;
                            foreach ($ordered_groups as $group): 
                                $group_id = strtolower(str_replace(' ', '-', $group));
                                $group_settings = $settings[$group];
                            ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $first_tab ? 'active' : ''; ?>" 
                                        id="<?php echo $group_id; ?>-tab" 
                                        data-bs-toggle="tab" 
                                        data-bs-target="#<?php echo $group_id; ?>" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="<?php echo $group_id; ?>" 
                                        aria-selected="<?php echo $first_tab ? 'true' : 'false'; ?>">
                                    <?php echo htmlspecialchars(ucfirst($group)); ?>
                                </button>
                            </li>
                            <?php 
                                $first_tab = false;
                            endforeach; 
                            ?>
                            <!-- Add Barcode Settings Tab if not already present -->
                            <?php if (!$has_barcode): ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo (!$has_general && empty($ordered_groups)) ? 'active' : ''; ?>" 
                                        id="barcode-settings-tab" 
                                        data-bs-toggle="tab" 
                                        data-bs-target="#barcode-settings" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="barcode-settings" 
                                        aria-selected="<?php echo (!$has_general && empty($ordered_groups)) ? 'true' : 'false'; ?>">
                                    Barcode
                                </button>
                            </li>
                            <?php endif; ?>
                            
                            <!-- Attributes Settings Tab -->
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" 
                                        id="attributes-tab" 
                                        data-bs-toggle="tab" 
                                        data-bs-target="#attributes" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="attributes" 
                                        aria-selected="false">
                                    Attributes
                                </button>
                            </li>
                            
                            <!-- Stock Settings Tab (Added through ordered_groups) -->
                            
                            <!-- UOM Settings Tab -->
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" 
                                        id="uom-tab" 
                                        data-bs-toggle="tab" 
                                        data-bs-target="#uom" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="uom" 
                                        aria-selected="false">
                                    Units of Measurement
                                </button>
                            </li>
                            
                            <!-- Accounting Settings Tab -->
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" 
                                        id="accounting-tab" 
                                        data-bs-toggle="tab" 
                                        data-bs-target="#accounting" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="accounting" 
                                        aria-selected="false">
                                    Accounting
                                </button>
                            </li>
                            
                            <!-- Discount Settings Tab is now handled through ordered_groups -->
                        </ul>
                        
                        <!-- Tab Content -->
                        <div class="tab-content">
                            <?php 
                            $first_tab = true;
                            foreach ($ordered_groups as $group): 
                                $group_id = strtolower(str_replace(' ', '-', $group));
                                $group_settings = $settings[$group];
                                
                                // Skip barcode settings if they're being handled separately
                                if ($group === 'barcode' && !isset($settings['barcode'])) {
                                    $first_tab = false;
                                    continue;
                                }
                            ?>
                            <div class="tab-pane fade <?php echo $first_tab ? 'show active' : ''; ?>" 
                                 id="<?php echo $group_id; ?>" 
                                 role="tabpanel"
                                 aria-labelledby="<?php echo $group_id; ?>-tab">
                                <h4 class="mb-4"><?php echo ucfirst(htmlspecialchars($group)); ?> Settings</h4>
                                
                                <?php foreach ($group_settings as $setting): ?>
                                <div class="mb-3">
                                    <label class="form-label">
                                        <?php echo htmlspecialchars($setting['display_name']); ?>
                                        <?php if (!empty($setting['description'])): ?>
                                        <i class="fas fa-info-circle text-muted" 
                                           data-bs-toggle="tooltip" 
                                           title="<?php echo htmlspecialchars($setting['description']); ?>"></i>
                                        <?php endif; ?>
                                    </label>
                                    
                                    <?php if ($setting['input_type'] == 'checkbox'): ?>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" 
                                               class="form-check-input" 
                                               name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>"
                                               value="1"
                                               <?php echo ($setting['setting_value'] == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label">Enabled</label>
                                    </div>
                                    
                                    <?php elseif ($setting['input_type'] == 'select' && !empty($setting['options'])): ?>
                                    <select class="form-select" 
                                            name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>">
                                        <?php 
                                        $options = explode(',', $setting['options']);
                                        foreach ($options as $option):
                                            $selected = ($setting['setting_value'] == $option) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($option); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <?php elseif ($setting['input_type'] == 'textarea'): ?>
                                    <textarea class="form-control" 
                                              name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>"
                                              rows="3"><?php echo htmlspecialchars($setting['setting_value']); ?></textarea>
                                    
                                    <?php elseif ($setting['input_type'] == 'number'): ?>
                                    <input type="number" 
                                           class="form-control" 
                                           name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>"
                                           value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                                    
                                    <?php else: ?>
                                    <input type="text" 
                                           class="form-control" 
                                           name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>"
                                           value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php 
                                $first_tab = false;
                            endforeach; 
                            ?>
                            
                            <?php
                            // Reset the first_tab variable for barcode tab if no other tabs exist
                            if (empty($ordered_groups)) {
                                $first_tab = true;
                            }
                            ?>
                            
                            <!-- Barcode Settings Tab Content -->
                            <?php if (!$has_barcode): ?>
                            <div class="tab-pane fade <?php echo (!$has_general && empty($ordered_groups)) ? 'show active' : ''; ?>" id="barcode-settings" role="tabpanel" aria-labelledby="barcode-settings-tab">
                                <h4 class="mb-4" id="barcode-settings-header">Barcode Settings</h4>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Configure barcode generation settings for your inventory items.
                                </div>
                                
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="card">
                                            <div class="card-header bg-light">
                                                <h5 class="mb-0">Barcode Label Preview</h5>
                                            </div>
                                            <div class="card-body text-center">
                                                <div id="barcode-preview-container" class="p-3 border rounded mb-3">
                                                    <div id="barcode-preview" class="mb-2"></div>
                                                    <div id="preview-product-name" class="fw-bold">Sample Product</div>
                                                    <div id="preview-product-price" class="text-success">$19.99</div>
                                                    <div id="preview-product-code" class="text-muted small">PROD001</div>
                                                </div>
                                                <button type="button" id="generate-preview" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-sync-alt"></i> Update Preview
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                    <label class="form-label">
                                        Default Barcode Format
                                        <i class="fas fa-info-circle text-muted" data-bs-toggle="tooltip" title="The default format to use when generating barcodes"></i>
                                    </label>
                                    <select name="setting_default_barcode_format" class="form-control">
                                        <option value="EAN13" selected>EAN13</option>
                                        <option value="CODE128">CODE128</option>
                                        <option value="CODE39">CODE39</option>
                                        <option value="UPC">UPC</option>
                                        <option value="EAN8">EAN8</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">
                                        Barcode Prefix
                                        <i class="fas fa-info-circle text-muted" data-bs-toggle="tooltip" title="Optional prefix to add to automatically generated barcodes (e.g., country code)"></i>
                                    </label>
                                    <input type="text" name="setting_barcode_prefix" class="form-control" value="" placeholder="Optional prefix">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">
                                        Auto-Generate Barcodes
                                        <i class="fas fa-info-circle text-muted" data-bs-toggle="tooltip" title="Automatically generate barcodes for new products"></i>
                                    </label>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" name="setting_auto_generate_barcode" class="form-check-input" value="1">
                                        <label class="form-check-label">Enable auto-generation</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">
                                        Default Label Size
                                        <i class="fas fa-info-circle text-muted" data-bs-toggle="tooltip" title="Default size for barcode labels"></i>
                                    </label>
                                    <select name="setting_barcode_label_size" class="form-control">
                                        <option value="small">Small (38mm x 25mm)</option>
                                        <option value="medium" selected>Medium (50mm x 30mm)</option>
                                        <option value="large">Large (100mm x 50mm)</option>
                                        <option value="a4-30">A4 - 30 per sheet</option>
                                        <option value="a4-24">A4 - 24 per sheet</option>
                                        <option value="a4-16">A4 - 16 per sheet</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">
                                        Default Label Style
                                        <i class="fas fa-info-circle text-muted" data-bs-toggle="tooltip" title="Default style for barcode labels"></i>
                                    </label>
                                    <select name="setting_barcode_label_style" class="form-control">
                                        <option value="basic" selected>Basic</option>
                                        <option value="compact">Compact</option>
                                        <option value="detailed">Detailed</option>
                                        <option value="minimal">Minimal</option>
                                    </select>
                                </div>
                                
                                        <div class="mt-4">
                                            <button type="button" id="install-barcode-settings" class="btn btn-outline-primary">
                                                <i class="fas fa-wrench"></i> Install Barcode Settings
                                            </button>
                                            <small class="text-muted ms-2">Click to create these settings in the database</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Attributes Settings Tab Content -->
                            <div class="tab-pane fade" 
                                 id="attributes" 
                                 role="tabpanel"
                                 aria-labelledby="attributes-tab">
                                <h4 class="mb-4">Attribute Settings</h4>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Configure attribute settings for your inventory items.
                                </div>
                                
                                <div class="mb-3">
                                    <label for="auto_generate_attribute_code" class="form-label">Auto Generate Attribute Code</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="auto_generate_attribute_code" name="settings[attributes][auto_generate_attribute_code]" value="1" <?php echo (isAutoGenerateAttributeCodeEnabled($db)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="auto_generate_attribute_code">Enable automatic attribute code generation</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="attribute_code_prefix" class="form-label">Attribute Code Prefix</label>
                                    <input type="text" class="form-control" id="attribute_code_prefix" name="settings[attributes][attribute_code_prefix]" value="<?php echo htmlspecialchars(getAttributeCodePrefix($db)); ?>" maxlength="10">
                                    <div class="form-text">Prefix to use when auto-generating attribute codes (max 10 characters)</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="allowed_input_types" class="form-label">Allowed Input Types</label>
                                    <input type="text" class="form-control" id="allowed_input_types" name="settings[attributes][allowed_input_types]" value="<?php echo htmlspecialchars(implode(',', getAllowedInputTypes($db))); ?>">
                                    <div class="form-text">Comma-separated list of allowed input types for attributes</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="default_input_type" class="form-label">Default Input Type</label>
                                    <select class="form-select" id="default_input_type" name="settings[attributes][default_input_type]">
                                        <?php foreach (getAllowedInputTypes($db) as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo (getDefaultInputType($db) === $type) ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Default input type for new attributes</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="require_validation_rules" class="form-label">Require Validation Rules</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="require_validation_rules" name="settings[attributes][require_validation_rules]" value="1" <?php echo (isValidationRulesRequired($db)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_validation_rules">Make validation rules required when adding/editing attributes</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="max_options_count" class="form-label">Maximum Options Count</label>
                                    <input type="number" class="form-control" id="max_options_count" name="settings[attributes][max_options_count]" value="<?php echo htmlspecialchars(getMaxOptionsCount($db)); ?>" min="1" max="200">
                                    <div class="form-text">Maximum number of options allowed for select/multiselect attributes</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="allow_multiple_category_assignments" class="form-label">Allow Multiple Category Assignments</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allow_multiple_category_assignments" name="settings[attributes][allow_multiple_category_assignments]" value="1" <?php echo (allowMultipleCategoryAssignments($db)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="allow_multiple_category_assignments">Allow attributes to be assigned to multiple categories</label>
                                    </div>
                                </div>
                                

                                

                                
                                <div class="mt-4">
                                    <a href="install_attribute_settings.php?install=true" class="btn btn-outline-primary">
                                        <i class="fas fa-wrench"></i> Install Attribute Settings
                                    </a>
                                    <small class="text-muted ms-2">Click to create these settings in the database</small>
                                </div>
                            </div>
                            
                            <!-- UOM Settings Tab Content -->
                            <div class="tab-pane fade" 
                                 id="uom" 
                                 role="tabpanel"
                                 aria-labelledby="uom-tab">
                                <h4 class="mb-4">Units of Measurement Settings</h4>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Configure settings for Units of Measurement (UOM) in your inventory system.
                                </div>
                                
                                <div class="mb-3">
                                    <label for="auto_generate_uom_code" class="form-label">Auto Generate UOM Code</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="auto_generate_uom_code" name="settings[uom][auto_generate_uom_code]" value="1" <?php echo (isAutoGenerateUOMCodeEnabled($db)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="auto_generate_uom_code">Enable automatic UOM code generation</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="uom_code_prefix" class="form-label">UOM Code Prefix</label>
                                    <input type="text" class="form-control" id="uom_code_prefix" name="settings[uom][uom_code_prefix]" value="<?php echo htmlspecialchars(getUOMCodePrefix($db)); ?>" maxlength="10">
                                    <div class="form-text">Prefix to use when auto-generating UOM codes (max 10 characters)</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="uom_types" class="form-label">UOM Types</label>
                                    <input type="text" class="form-control" id="uom_types" name="settings[uom][uom_types]" value="<?php echo htmlspecialchars(implode(',', getAllowedUOMTypes($db))); ?>">
                                    <div class="form-text">Comma-separated list of UOM types (e.g., Weight, Volume, Length)</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="default_uom_type" class="form-label">Default UOM Type</label>
                                    <select class="form-select" id="default_uom_type" name="settings[uom][default_uom_type]">
                                        <?php foreach (getAllowedUOMTypes($db) as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo (getDefaultUOMType($db) === $type) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Default type for new UOMs</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="require_base_unit_validation" class="form-label">Require Base Unit Validation</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="require_base_unit_validation" name="settings[uom][require_base_unit_validation]" value="1" <?php echo (isBaseUnitValidationRequired($db)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_base_unit_validation">Require validation for base units</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="require_conversion_rate" class="form-label">Require Conversion Rate</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="require_conversion_rate" name="settings[uom][require_conversion_rate]" value="1" <?php echo (isConversionRateRequired($db)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_conversion_rate">Require conversion rate for non-base units</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="max_decimal_places" class="form-label">Maximum Decimal Places</label>
                                    <input type="number" class="form-control" id="max_decimal_places" name="settings[uom][max_decimal_places]" value="<?php echo htmlspecialchars(getMaxDecimalPlaces($db)); ?>" min="0" max="10">
                                    <div class="form-text">Maximum decimal places for conversion rates</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="can_be_used_for_purchase" class="form-label">Can Be Used For Purchase</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="can_be_used_for_purchase" name="settings[uom][can_be_used_for_purchase]" value="1" <?php echo (canUOMBeUsedForPurchase($db)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="can_be_used_for_purchase">Allow UOMs to be used for purchasing</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="can_be_used_for_sales" class="form-label">Can Be Used For Sales</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="can_be_used_for_sales" name="settings[uom][can_be_used_for_sales]" value="1" <?php echo (canUOMBeUsedForSales($db)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="can_be_used_for_sales">Allow UOMs to be used for sales</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="can_be_used_for_inventory" class="form-label">Can Be Used For Inventory</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="can_be_used_for_inventory" name="settings[uom][can_be_used_for_inventory]" value="1" <?php echo (canUOMBeUsedForInventory($db)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="can_be_used_for_inventory">Allow UOMs to be used for inventory</label>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <a href="install_uom_settings.php?install=true" class="btn btn-outline-primary">
                                        <i class="fas fa-wrench"></i> Install UOM Settings
                                    </a>
                                    <small class="text-muted ms-2">Click to create these settings in the database</small>
                                </div>
                            </div>
                            
                            <!-- Stock Settings Tab Content -->
                            <div class="tab-pane fade" 
                                 id="stock" 
                                 role="tabpanel"
                                 aria-labelledby="stock-tab">
                                <h4 class="mb-4">Stock Management Settings</h4>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Configure settings for stock management, transactions, and inventory control.
                                </div>
                                
                                <!-- Stock Movement Control Settings -->
                                <h5 class="mt-4 mb-3">Stock Movement Control</h5>
                                
                                <div class="mb-3">
                                    <label for="require_approval_for_adjustments" class="form-label">Require Approval for Stock Adjustments</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="require_approval_for_adjustments" name="settings[stock][require_approval_for_adjustments]" value="1" <?php echo (isset($settings['stock']) && isset($settings['stock'][0]['setting_key']) && $settings['stock'][0]['setting_key'] == 'require_approval_for_adjustments' && $settings['stock'][0]['setting_value'] == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_approval_for_adjustments">Require manager approval before stock adjustments are processed</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="default_adjustment_reasons" class="form-label">Default Adjustment Reason Options</label>
                                    <?php
                                    // Get the current value of default_adjustment_reasons directly from the database
                                    $reasons_query = "SELECT setting_value FROM inventory_settings WHERE setting_key = 'default_adjustment_reasons' AND setting_group = 'stock' LIMIT 1";
                                    $reasons_stmt = $db->prepare($reasons_query);
                                    $reasons_stmt->execute();
                                    $reasons_result = $reasons_stmt->fetch(PDO::FETCH_ASSOC);
                                    $current_reasons = ($reasons_result) ? $reasons_result['setting_value'] : 'Damaged,Expired,Inventory Count,Lost,Found,Theft,Return,Quality Issue';
                                    
                                    // Clean up the value - trim trailing commas
                                    $current_reasons = rtrim($current_reasons, ',');
                                    
                                    // Split by comma, trim each value, and rejoin to ensure clean display
                                    $reasons = explode(',', $current_reasons);
                                    $cleaned_reasons = [];
                                    foreach ($reasons as $reason) {
                                        $trimmed = trim($reason);
                                        if (!empty($trimmed)) {
                                            $cleaned_reasons[] = $trimmed;
                                        }
                                    }
                                    $current_reasons = implode(',', $cleaned_reasons);
                                    
                                    // Debug the current value
                                    error_log("Current default_adjustment_reasons value (after cleanup): " . $current_reasons);
                                    ?>
                                    <textarea class="form-control" id="default_adjustment_reasons" name="settings[stock][default_adjustment_reasons]" rows="3"><?php echo htmlspecialchars($current_reasons); ?></textarea>
                                    <div class="form-text">Comma-separated list of common adjustment reasons (e.g., Damaged,Expired,test)</div>
                                </div>
                                
                                <!-- Stock Transaction Settings -->
                                <h5 class="mt-4 mb-3">Stock Transaction Settings</h5>
                                
                                <div class="mb-3">
                                    <label for="auto_generate_transaction_numbers" class="form-label">Auto-Generate Transaction Numbers</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="auto_generate_transaction_numbers" name="settings[stock][auto_generate_transaction_numbers]" value="1" <?php echo (isset($settings['stock']) && isset($settings['stock'][3]['setting_key']) && $settings['stock'][3]['setting_key'] == 'auto_generate_transaction_numbers' && $settings['stock'][3]['setting_value'] == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="auto_generate_transaction_numbers">Automatically generate transaction numbers for stock movements</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="transaction_number_prefix" class="form-label">Transaction Number Prefix</label>
                                    <?php
                                    // Get the current value of transaction_number_prefix directly from the database
                                    $prefix_query = "SELECT setting_value FROM inventory_settings WHERE setting_key = 'transaction_number_prefix' AND setting_group = 'stock' LIMIT 1";
                                    $prefix_stmt = $db->prepare($prefix_query);
                                    $prefix_stmt->execute();
                                    $prefix_result = $prefix_stmt->fetch(PDO::FETCH_ASSOC);
                                    $current_prefix = ($prefix_result) ? $prefix_result['setting_value'] : 'STKTX-';
                                    
                                    // Debug the current value
                                    error_log("Current transaction_number_prefix value: " . $current_prefix);
                                    ?>
                                    <input type="text" class="form-control" id="transaction_number_prefix" name="settings[stock][transaction_number_prefix]" value="<?php echo htmlspecialchars($current_prefix); ?>" maxlength="10">
                                    <div class="form-text">Prefix to use when auto-generating transaction numbers (max 10 characters)</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="default_transaction_type" class="form-label">Default Transaction Type</label>
                                    <select class="form-select" id="default_transaction_type" name="settings[stock][default_transaction_type]">
                                        <?php 
                                        $transaction_types = ['Purchase', 'Sale', 'Adjustment', 'Transfer', 'Return', 'Scrap', 'Count'];
                                        $default_type = (isset($settings['stock']) && isset($settings['stock'][5]['setting_key']) && $settings['stock'][5]['setting_key'] == 'default_transaction_type') ? $settings['stock'][5]['setting_value'] : 'Adjustment';
                                        foreach ($transaction_types as $type): 
                                        ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($default_type === $type) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Default type for new stock transactions</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="require_notes_for_transactions" class="form-label">Require Notes for Transactions</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="require_notes_for_transactions" name="settings[stock][require_notes_for_transactions]" value="1" <?php echo (isset($settings['stock']) && isset($settings['stock'][6]['setting_key']) && $settings['stock'][6]['setting_key'] == 'require_notes_for_transactions' && $settings['stock'][6]['setting_value'] == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_notes_for_transactions">Make notes mandatory for all stock transactions</label>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <a href="actions/install_stock_settings.php" class="btn btn-outline-primary">
                                        <i class="fas fa-wrench"></i> Install Stock Settings
                                    </a>
                                    <small class="text-muted ms-2">Click to create these settings in the database</small>
                                    
                                    <a href="actions/remove_unused_stock_settings.php" class="btn btn-outline-danger ms-2">
                                        <i class="fas fa-trash"></i> Remove Unused Settings
                                    </a>
                                    <small class="text-muted ms-2">Click to remove settings that aren't implemented in the system</small>
                                </div>
                            </div>
                            
                            <!-- Discount Settings Tab Content -->
                            <div class="tab-pane fade" 
                                 id="discount" 
                                 role="tabpanel"
                                 aria-labelledby="discount-tab">
                                <h4 class="mb-4">Discount Settings</h4>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Configure settings for discounts, including code generation and discount types.
                                </div>
                                
                                <!-- General Discount Settings -->
                                <h5 class="mt-4 mb-3">General Settings</h5>
                                
                                <div class="mb-3">
                                    <label for="enable_discounts" class="form-label">Enable Discounts</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="enable_discounts" name="settings[discount][enable_discounts]" value="1" <?php echo (getDiscountSetting($db, 'enable_discounts', '1') == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="enable_discounts">Enable discount functionality</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="auto_generate_discount_code" class="form-label">Auto-Generate Discount Codes</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="auto_generate_discount_code" name="settings[discount][auto_generate_discount_code]" value="1" <?php echo (getDiscountSetting($db, 'auto_generate_discount_code', '1') == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="auto_generate_discount_code">Automatically generate discount codes</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="discount_code_prefix" class="form-label">Discount Code Prefix</label>
                                    <input type="text" class="form-control" id="discount_code_prefix" name="settings[discount][discount_code_prefix]" value="<?php echo htmlspecialchars(getDiscountSetting($db, 'discount_code_prefix', 'DISC-')); ?>" maxlength="10">
                                    <small class="form-text text-muted">Prefix used when auto-generating discount codes (e.g., DISC-0001)</small>
                                </div>
                                
                                <!-- Discount Database Setup -->
                                <h5 class="mt-4 mb-3">Database Setup</h5>
                                
                                <div class="mb-3">
                                    <p>Create the necessary database tables for the discount system:</p>
                                    <a href="actions/create_discount_tables.php" class="btn btn-outline-primary">
                                        <i class="fas fa-database"></i> Create Discount Tables
                                    </a>
                                    <small class="text-muted ms-2">Click to create or update discount tables in the database</small>
                                </div>
                                
                                <!-- Discount Management Link -->
                                <h5 class="mt-4 mb-3">Discount Management</h5>
                                
                                <div class="mb-3">
                                    <p>Manage your discounts:</p>
                                    <a href="discounts.php" class="btn btn-primary">
                                        <i class="fas fa-tags"></i> Manage Discounts
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Accounting Settings Tab Content -->
                            <div class="tab-pane fade" id="accounting" role="tabpanel" aria-labelledby="accounting-tab">
                                <h4 class="mb-4">Accounting Settings</h4>
                                
                                <div class="mb-3">
                                    <label for="inventory_asset_account_id" class="form-label">Inventory Asset Account</label>
                                    <select class="form-select" id="inventory_asset_account_id" name="inventory_asset_account_id">
                                        <option value="">Select Account</option>
                                        <?php foreach ($accounts as $account): ?>
                                        <option value="<?php echo htmlspecialchars($account['id']); ?>" <?php echo ((string)$inventory_asset_account_id === (string)$account['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="inventory_adjustment_account_id" class="form-label">Inventory Adjustment Account</label>
                                    <select class="form-select" id="inventory_adjustment_account_id" name="inventory_adjustment_account_id">
                                        <option value="">Select Account</option>
                                        <?php foreach ($accounts as $account): ?>
                                        <option value="<?php echo htmlspecialchars($account['id']); ?>" <?php echo ((string)$inventory_adjustment_account_id === (string)$account['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="cogs_account_id" class="form-label">Cost of Goods Sold Account</label>
                                    <select class="form-select" id="cogs_account_id" name="cogs_account_id">
                                        <option value="">Select Account</option>
                                        <?php foreach ($accounts as $account): ?>
                                        <option value="<?php echo htmlspecialchars($account['id']); ?>" <?php echo ((string)$cogs_account_id === (string)$account['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i> Save Settings
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include settings JS -->
<script src="js/settings.js"></script>

<?php include '../../includes/footer.php'; ?>
