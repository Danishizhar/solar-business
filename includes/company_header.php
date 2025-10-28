<?php
// Get the correct base path
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

// Get database connection
if (!isset($db) && !isset($conn)) {
    require_once $base_path . 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
}

// Use existing connection if available
$connection = isset($conn) ? $conn : $db;

// Get company information from session or database
$company = isset($_SESSION['company']) ? $_SESSION['company'] : getCompanyInfo($connection);

// Get current branch information if available
$current_branch = null;
if (isset($_SESSION['user_branch_id'])) {
    $current_branch = getBranchInfo($connection, $_SESSION['user_branch_id']);
}
?>

<div class="company-header mb-4">
    <div class="row align-items-center">
        <div class="col-auto">
            <?php if (!empty($company['logo_path'])): ?>
                <img src="<?php echo $base_path . $company['logo_path']; ?>" alt="<?php echo htmlspecialchars($company['company_name']); ?>" class="company-logo" style="max-height: 60px;">
            <?php endif; ?>
        </div>
        <div class="col">
            <h3 class="mb-0"><?php echo htmlspecialchars($company['company_name'] ?? 'Solar Business'); ?></h3>
            <?php if (!empty($company['address'])): ?>
                <p class="text-muted small mb-0"><?php echo htmlspecialchars($company['address']); ?></p>
            <?php endif; ?>
            <?php if ($current_branch): ?>
                <span class="badge bg-primary"><?php echo htmlspecialchars($current_branch['branch_name']); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>
