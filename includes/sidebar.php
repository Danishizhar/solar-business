<?php
// Get current page name
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
$current_path = $_SERVER['PHP_SELF'];
$is_in_module = strpos($current_path, '/modules/') !== false;
$is_in_auth = strpos($current_path, '/auth/') !== false;
$base_path = '';

if ($is_in_module) {
    $base_path = '../../';
} elseif ($is_in_auth) {
    $base_path = '../';
}

// Include company helpers if not already included
if (!function_exists('getCompanyInfo')) {
    require_once __DIR__ . '/company_helpers.php';
}

// Get database connection if not already available
if (!isset($db) && !isset($conn)) {
    require_once $base_path . 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
}

// Use existing connection if available
$connection = isset($conn) ? $conn : $db;

// Get company information from session or database
$company = isset($_SESSION['company']) ? $_SESSION['company'] : getCompanyInfo($connection);
?>
<!-- Sidebar -->
<nav id="sidebar" style="overflow-y: scroll; max-height: 100vh;">
    <div class="sidebar-header">
        <?php if (!empty($company['logo_path'])): ?>
        <div class="text-center mb-2">
            <img src="<?php echo $base_path . $company['logo_path']; ?>" alt="Company Logo" class="sidebar-logo" style="max-width: 80%; max-height: 60px;">
        </div>
        <?php endif; ?>
        <div class="company-name" style="font-size: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($company['company_name'] ?? 'Solar Business'); ?></div>
    </div>

    <ul class="list-unstyled components">
        <li class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
            <a href="<?php echo $base_path; ?>index.php"><i class="fas fa-home"></i> Dashboard</a>
        </li>
        <?php if ($_SESSION['role_id'] == 1): // Admin only ?>
        <li class="<?php echo $current_dir === 'company' ? 'active' : ''; ?>">
            <a href="#companySubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle"><i class="fas fa-building"></i> Company</a>
            <ul class="collapse list-unstyled <?php echo $current_dir === 'company' ? 'show' : ''; ?>" id="companySubmenu">
                <li>
                    <a href="<?php echo $base_path; ?>modules/company/company.php" class="<?php echo $current_page == 'company.php' ? 'active' : ''; ?>"><i class="fas fa-info-circle"></i> Company Profile</a>
                </li>
                <li>
                    <a href="<?php echo $base_path; ?>modules/company/branches.php" class="<?php echo $current_page == 'branches.php' ? 'active' : ''; ?>"><i class="fas fa-code-branch"></i> Branches</a>
                </li>
                <li>
                    <a href="<?php echo $base_path; ?>modules/company/settings.php" class="<?php echo $current_page == 'settings.php' ? 'active' : ''; ?>"><i class="fas fa-cogs"></i> Settings</a>
                </li>
            </ul>
        </li>

        <li class="<?php echo $current_dir === 'users' ? 'active' : ''; ?>">
            <a href="#userSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle"><i class="fas fa-users"></i> User Management</a>
            <ul class="collapse list-unstyled <?php echo $current_dir === 'users' ? 'show' : ''; ?>" id="userSubmenu">
                <li><a href="<?php echo $base_path; ?>modules/users/users.php" class="<?php echo $current_page == 'users.php' ? 'active' : ''; ?>"><i class="fas fa-user"></i> Users</a></li>
                <li><a href="<?php echo $base_path; ?>modules/users/roles.php" class="<?php echo $current_page == 'roles.php' ? 'active' : ''; ?>"><i class="fas fa-user-tag"></i> Roles</a></li>
            </ul>
        </li>
        <?php endif; ?>
        <li class="<?php echo $current_dir === 'accounts' ? 'active' : ''; ?>">
            <a href="#accountsSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle"><i class="fas fa-calculator"></i> Accounts</a>
            <ul class="collapse list-unstyled <?php echo $current_dir === 'accounts' ? 'show' : ''; ?>" id="accountsSubmenu">
                <li><a href="<?php echo $base_path; ?>modules/accounts/chart_of_accounts.php"><i class="fas fa-book"></i> Chart of Accounts</a></li>
                <li><a href="<?php echo $base_path; ?>modules/accounts/account_categories.php"><i class="fas fa-list"></i> Account Categories</a></li>
                <li><a href="<?php echo $base_path; ?>modules/accounts/journal_entries.php"><i class="fas fa-file-invoice"></i> Journal Entries</a></li>
                <li><a href="<?php echo $base_path; ?>modules/accounts/bank_accounts.php"><i class="fas fa-university"></i> Bank Accounts</a></li>
                <li><a href="<?php echo $base_path; ?>modules/accounts/bank_transactions.php"><i class="fas fa-exchange-alt"></i> Bank Transactions</a></li>
                <!-- Bank reconciliation now handled through bank transactions -->
                <li><a href="<?php echo $base_path; ?>modules/accounts/cost_centers.php"><i class="fas fa-sitemap"></i> Cost Centers</a></li>
                <li><a href="<?php echo $base_path; ?>modules/accounts/financial_years.php"><i class="fas fa-calendar-alt"></i> Financial Years</a></li>
                <!-- Temporarily hidden budget menu item
                <li><a href="<?php echo $base_path; ?>modules/accounts/budgets.php"><i class="fas fa-money-bill-wave"></i> Budgets</a></li>
                -->
                <li class="menu-header"><span>Financial Reports</span></li>
                <li><a href="<?php echo $base_path; ?>modules/accounts/balance_sheet.php"><i class="fas fa-balance-scale"></i> Balance Sheet</a></li>
                <li><a href="<?php echo $base_path; ?>modules/accounts/income_statement.php"><i class="fas fa-chart-line"></i> Income Statement</a></li>
                <li><a href="<?php echo $base_path; ?>modules/accounts/cash_flow_statement.php"><i class="fas fa-money-bill-alt"></i> Cash Flow Statement</a></li>
                <li><a href="<?php echo $base_path; ?>modules/accounts/trial_balance.php"><i class="fas fa-list-ol"></i> Trial Balance</a></li>
            </ul>
        </li>
        <li class="<?php echo $current_dir === 'purchase' ? 'active' : ''; ?>">
            <a href="#purchaseSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle"><i class="fas fa-shopping-cart"></i> Purchase</a>
            <ul class="collapse list-unstyled <?php echo $current_dir === 'purchase' ? 'show' : ''; ?>" id="purchaseSubmenu">
                <li><a href="<?php echo $base_path; ?>modules/purchase/purchase_orders.php" class="<?php echo $current_page == 'purchase_orders.php' ? 'active' : ''; ?>"><i class="fas fa-file-invoice"></i> Purchase Orders</a></li>
                <li><a href="<?php echo $base_path; ?>modules/purchase/goods_receiving_notes.php" class="<?php echo $current_page == 'goods_receiving_notes.php' || $current_page == 'grn_detail.php' || $current_page == 'grn_create.php' ? 'active' : ''; ?>"><i class="fas fa-clipboard-check"></i> Goods Receiving Notes</a></li>
                <li><a href="<?php echo $base_path; ?>modules/purchase/purchase_invoices.php" class="<?php echo $current_page == 'purchase_invoices.php' || $current_page == 'purchase_invoice_detail.php' || $current_page == 'purchase_invoice_create.php' || $current_page == 'purchase_invoice_edit.php' ? 'active' : ''; ?>"><i class="fas fa-file-invoice-dollar"></i> Purchase Invoices</a></li>
                <li><a href="<?php echo $base_path; ?>modules/purchase/purchase_returns.php" class="<?php echo $current_page == 'purchase_returns.php' || $current_page == 'purchase_return_detail.php' || $current_page == 'purchase_return_create.php' ? 'active' : ''; ?>"><i class="fas fa-undo-alt"></i> Purchase Returns</a></li>
                <li><a href="<?php echo $base_path; ?>modules/purchase/supplier_refund.php" class="<?php echo $current_page == 'supplier_refund.php' ? 'active' : ''; ?>"><i class="fas fa-hand-holding-usd"></i> Supplier Refunds</a></li>
                <li><a href="<?php echo $base_path; ?>modules/purchase/suppliers.php" class="<?php echo $current_page == 'suppliers.php' || $current_page == 'supplier_detail.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Suppliers</a></li>
                <li><a href="<?php echo $base_path; ?>modules/purchase/settings.php" class="<?php echo $current_page == 'settings.php' && $current_dir === 'purchase' ? 'active' : ''; ?>"><i class="fas fa-cogs"></i> Settings</a></li>
            </ul>
        </li>
        
        <li class="<?php echo $current_dir === 'payment' ? 'active' : ''; ?>">
            <a href="#paymentSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle"><i class="fas fa-money-bill-wave"></i> Payments</a>
            <ul class="collapse list-unstyled <?php echo $current_dir === 'payment' ? 'show' : ''; ?>" id="paymentSubmenu">
                <li><a href="<?php echo $base_path; ?>modules/payment/payment_list.php" class="<?php echo $current_page == 'payment_list.php' ? 'active' : ''; ?>"><i class="fas fa-list"></i> All Payments</a></li>
                <li><a href="<?php echo $base_path; ?>modules/payment/purchase_payments.php" class="<?php echo $current_page == 'purchase_payments.php' ? 'active' : ''; ?>"><i class="fas fa-shopping-cart"></i> Purchase Payments</a></li>
                <li><a href="<?php echo $base_path; ?>modules/payment/sales_payments.php" class="<?php echo $current_page == 'sales_payments.php' ? 'active' : ''; ?>"><i class="fas fa-store"></i> Sales Payments</a></li>
            </ul>
        </li>
        
        <li class="<?php echo $current_dir === 'inventory' ? 'active' : ''; ?>">
            <a href="#inventorySubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle"><i class="fas fa-box"></i> Inventory</a>
            <ul class="collapse list-unstyled <?php echo $current_dir === 'inventory' ? 'show' : ''; ?>" id="inventorySubmenu">
                <li><a href="<?php echo $base_path; ?>modules/inventory/products.php" class="<?php echo $current_page == 'products.php' ? 'active' : ''; ?>"><i class="fas fa-box"></i> Products</a></li>
                <li><a href="<?php echo $base_path; ?>modules/inventory/barcode_management_new.php" class="<?php echo $current_page == 'barcode_management.php' || $current_page == 'barcode_management_new.php' ? 'active' : ''; ?>"><i class="fas fa-barcode"></i> Barcode Management</a></li>
                <li><a href="<?php echo $base_path; ?>modules/inventory/categories.php" class="<?php echo $current_page == 'categories.php' ? 'active' : ''; ?>"><i class="fas fa-tags"></i> Categories</a></li>
                <li><a href="<?php echo $base_path; ?>modules/inventory/brands.php" class="<?php echo $current_page == 'brands.php' ? 'active' : ''; ?>"><i class="fas fa-copyright"></i> Brands</a></li>
                <li><a href="<?php echo $base_path; ?>modules/inventory/attributes.php" class="<?php echo $current_page == 'attributes.php' ? 'active' : ''; ?>"><i class="fas fa-list-alt"></i> Attributes</a></li>
                <li><a href="<?php echo $base_path; ?>modules/inventory/uom.php" class="<?php echo $current_page == 'uom.php' ? 'active' : ''; ?>"><i class="fas fa-ruler"></i> Units of Measure</a></li>
                <li><a href="<?php echo $base_path; ?>modules/inventory/locations.php" class="<?php echo $current_page == 'locations.php' ? 'active' : ''; ?>"><i class="fas fa-map-marker-alt"></i> Locations</a></li>
                <li><a href="<?php echo $base_path; ?>modules/inventory/stock.php" class="<?php echo $current_page == 'stock.php' ? 'active' : ''; ?>"><i class="fas fa-warehouse"></i> Stock</a></li>
                <li><a href="<?php echo $base_path; ?>modules/inventory/discounts.php" class="<?php echo $current_page == 'discounts.php' ? 'active' : ''; ?>"><i class="fas fa-tags"></i> Discounts</a></li>
                <li><a href="<?php echo $base_path; ?>modules/inventory/settings.php" class="<?php echo $current_page == 'settings.php' && $current_dir === 'inventory' ? 'active' : ''; ?>"><i class="fas fa-cogs"></i> Settings</a></li>
            </ul>
        </li>

        <li>
            <a href="#reportsSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle"><i class="fas fa-chart-bar"></i> Reports</a>
            <ul class="collapse list-unstyled" id="reportsSubmenu">
                <li><a href="<?php echo $base_path; ?>modules/reports/sales_report.php"><i class="fas fa-file-alt"></i> Sales Report</a></li>
                <li><a href="<?php echo $base_path; ?>modules/reports/purchase_report.php"><i class="fas fa-file-alt"></i> Purchase Report</a></li>
                <li><a href="<?php echo $base_path; ?>modules/reports/inventory_report.php"><i class="fas fa-file-alt"></i> Inventory Report</a></li>
                <li><a href="<?php echo $base_path; ?>modules/reports/financial_report.php"><i class="fas fa-file-alt"></i> Financial Report</a></li>
            </ul>
        </li>
    </ul>
</nav>
