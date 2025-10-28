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
$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Create purchase_settings table if it doesn't exist
try {
    $create_table_sql = "CREATE TABLE IF NOT EXISTS purchase_settings (
        id INT(11) NOT NULL AUTO_INCREMENT,
        setting_group VARCHAR(50) NOT NULL,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT,
        setting_label VARCHAR(255) NOT NULL,
        input_type VARCHAR(50) DEFAULT 'text',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INT(11),
        updated_at DATETIME,
        updated_by INT(11),
        PRIMARY KEY (id),
        UNIQUE KEY unique_setting (setting_group, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $db->exec($create_table_sql);
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}

/**
 * Function to update a setting
 */
function updateSetting($db, $group, $key, $value, $label, $input_type = 'text') {
    try {
        // Update the setting
        $query = "UPDATE purchase_settings SET 
                 setting_value = ?,
                 updated_at = NOW(),
                 updated_by = ?
                 WHERE setting_group = ? AND setting_key = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$value, $_SESSION['user_id'], $group, $key]);
        
        $affected = $stmt->rowCount();
        
        if ($affected == 0) {
            // Try to insert if it doesn't exist
            $insert = "INSERT INTO purchase_settings 
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

// Get all chart of accounts for selection
$accounts_query = "SELECT id, account_code, account_name FROM chart_of_accounts WHERE status = 1 ORDER BY account_code";
$accounts_stmt = $db->prepare($accounts_query);
$accounts_stmt->execute();
$accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Update accounting settings
        if (isset($_POST['accounts_payable_account_id'])) {
            updateSetting($db, 'accounting', 'accounts_payable_account_id', $_POST['accounts_payable_account_id'], 'Accounts Payable Account', 'select');
        }
        
        if (isset($_POST['purchase_tax_account_id'])) {
            updateSetting($db, 'accounting', 'purchase_tax_account_id', $_POST['purchase_tax_account_id'], 'Purchase Tax Account', 'select');
        }
        
        if (isset($_POST['purchase_discount_account_id'])) {
            updateSetting($db, 'accounting', 'purchase_discount_account_id', $_POST['purchase_discount_account_id'], 'Purchase Discount Account', 'select');
        }
        
        if (isset($_POST['delivery_expense_account_id'])) {
            updateSetting($db, 'accounting', 'delivery_expense_account_id', $_POST['delivery_expense_account_id'], 'Delivery Expense Account', 'select');
        }
        
        if (isset($_POST['cash_account_id'])) {
            updateSetting($db, 'accounting', 'cash_account_id', $_POST['cash_account_id'], 'Cash Account', 'select');
        }
        
        // Commit transaction
        $db->commit();
        $success_message = "Settings updated successfully";
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get current settings
$settings_query = "SELECT setting_key, setting_value FROM purchase_settings WHERE setting_group = 'accounting'";
$settings_stmt = $db->prepare($settings_query);
$settings_stmt->execute();
$settings = [];

while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Set default values if not set
$accounts_payable_account_id = $settings['accounts_payable_account_id'] ?? '';
$purchase_tax_account_id = $settings['purchase_tax_account_id'] ?? '';
$purchase_discount_account_id = $settings['purchase_discount_account_id'] ?? '';
$delivery_expense_account_id = $settings['delivery_expense_account_id'] ?? '';
$cash_account_id = $settings['cash_account_id'] ?? '';

// Page title
$page_title = "Purchase Settings";
include_once '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid px-4">
            <h1 class="mt-4"><?php echo $page_title; ?></h1>
            <ol class="breadcrumb mb-4">
                <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="../purchase/index.php">Purchase</a></li>
                <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
            </ol>
    
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger"><?php echo $error_message; ?></div>
    <?php endif; ?>
    
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-cogs me-1"></i>
            Purchase Module Settings
        </div>
        <div class="card-body">
            <form method="post" action="">
                <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="accounting-tab" data-bs-toggle="tab" data-bs-target="#accounting" type="button" role="tab" aria-controls="accounting" aria-selected="true">Accounting</button>
                    </li>
                </ul>
                
                <div class="tab-content mt-3" id="settingsTabContent">
                    <!-- Accounting Settings Tab -->
                    <div class="tab-pane fade show active" id="accounting" role="tabpanel" aria-labelledby="accounting-tab">
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            Configure accounting settings for purchase transactions. These settings determine which accounts are used for purchase-related journal entries.
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="accounts_payable_account_id" class="form-label">Accounts Payable Account</label>
                                <select class="form-select" id="accounts_payable_account_id" name="accounts_payable_account_id">
                                    <option value="">-- Select Account --</option>
                                    <?php foreach ($accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>" <?php echo ((string)$accounts_payable_account_id === (string)$account['id']) ? 'selected' : ''; ?>>
                                            <?php echo $account['account_code'] . ' - ' . $account['account_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Default liability account for supplier payables</div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="purchase_tax_account_id" class="form-label">Purchase Tax Account</label>
                                <select class="form-select" id="purchase_tax_account_id" name="purchase_tax_account_id">
                                    <option value="">-- Select Account --</option>
                                    <?php foreach ($accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>" <?php echo ((string)$purchase_tax_account_id === (string)$account['id']) ? 'selected' : ''; ?>>
                                            <?php echo $account['account_code'] . ' - ' . $account['account_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Account for recording purchase tax expenses</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="purchase_discount_account_id" class="form-label">Purchase Discount Account</label>
                                <select class="form-select" id="purchase_discount_account_id" name="purchase_discount_account_id">
                                    <option value="">-- Select Account --</option>
                                    <?php foreach ($accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>" <?php echo ((string)$purchase_discount_account_id === (string)$account['id']) ? 'selected' : ''; ?>>
                                            <?php echo $account['account_code'] . ' - ' . $account['account_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Account for recording purchase discounts received</div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="delivery_expense_account_id" class="form-label">Delivery Expense Account</label>
                                <select class="form-select" id="delivery_expense_account_id" name="delivery_expense_account_id">
                                    <option value="">-- Select Account --</option>
                                    <?php foreach ($accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>" <?php echo ((string)$delivery_expense_account_id === (string)$account['id']) ? 'selected' : ''; ?>>
                                            <?php echo $account['account_code'] . ' - ' . $account['account_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Account for recording delivery expenses on purchases</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="cash_account_id" class="form-label">Cash Account</label>
                                <select class="form-select" id="cash_account_id" name="cash_account_id">
                                    <option value="">-- Select Account --</option>
                                    <?php foreach ($accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>" <?php echo ((string)$cash_account_id === (string)$account['id']) ? 'selected' : ''; ?>>
                                            <?php echo $account['account_code'] . ' - ' . $account['account_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Default cash account for cash payments</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
        </div>
    </div>
</div>

<?php include_once '../../includes/footer.php'; ?>
