<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Debug session information
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'Not set';
$is_admin = isset($_SESSION['is_admin']) ? $_SESSION['is_admin'] : 'Not set';

// Alternative check for admin status based on user_role
if (isset($_SESSION['user_role']) && ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'Admin')) {
    $is_admin_by_role = true;
} else {
    $is_admin_by_role = false;
}

// Allow access if either is_admin is 1 or user_role is admin
if ((!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) && !$is_admin_by_role) {
    // Debug information
    echo "<div style='background-color: #f8d7da; color: #721c24; padding: 10px; margin: 10px; border-radius: 5px;'>";
    echo "<h3>Debug Information</h3>";
    echo "<p>User ID: " . $_SESSION['user_id'] . "</p>";
    echo "<p>User Role: " . $user_role . "</p>";
    echo "<p>Is Admin Flag: " . $is_admin . "</p>";
    echo "<p>Is Admin By Role: " . ($is_admin_by_role ? 'Yes' : 'No') . "</p>";
    echo "<p>Session Data: <pre>" . print_r($_SESSION, true) . "</pre></p>";
    echo "</div>";
    
    // Still show error but don't redirect
    echo "<div style='background-color: #f8d7da; color: #721c24; padding: 10px; margin: 10px; border-radius: 5px;'>";
    echo "You must be an administrator to access this page. However, we're showing debug info instead of redirecting.";
    echo "</div>";
}

// Page title
$page_title = "Upgrade Discount System";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Inventory Management</title>
    <!-- Include CSS files -->
    <?php include '../../includes/header.php'; ?>
    <style>
        .upgrade-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .feature-list {
            margin-bottom: 20px;
        }
        .feature-item {
            margin-bottom: 10px;
            padding-left: 20px;
            position: relative;
        }
        .feature-item:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
        }
        .warning {
            background-color: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        #upgradeBtn {
            margin-top: 20px;
        }
        #upgradeResult {
            margin-top: 20px;
            padding: 15px;
            border-radius: 5px;
            display: none;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include '../../includes/sidebar.php'; ?>
        
        <div id="content">
            <?php include '../../includes/navbar.php'; ?>
            
            <div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h2><?php echo $page_title; ?></h2>
                    </div>
                    <div class="col-md-6 text-end">
                        <a href="discounts.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Discounts
                        </a>
                    </div>
                </div>
                
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="discounts.php">Discounts</a></li>
                        <li class="breadcrumb-item active">Upgrade System</li>
                    </ol>
                </nav>
                
                <div class="upgrade-container">
                    <h3>Discount Management System Upgrade</h3>
                    <p>This upgrade will add the following features to your discount management system:</p>
                    
                    <div class="feature-list">
                        <div class="feature-item">
                            <strong>Discount Stacking/Combination Rules</strong>: Allow multiple discounts to be combined with configurable rules.
                        </div>
                        <div class="feature-item">
                            <strong>Customer Loyalty Tiers</strong>: Create customer tiers with automatic upgrades based on purchase history.
                        </div>
                        <div class="feature-item">
                            <strong>Bulk/Volume Discounts</strong>: Implement tiered quantity discounts and "Buy X Get Y" promotions.
                        </div>
                        <div class="feature-item">
                            <strong>Time-Limited Flash Discounts</strong>: Create time-specific flash sales with precise scheduling.
                        </div>
                        <div class="feature-item">
                            <strong>Discount Usage Limits</strong>: Control how many times a discount can be used globally or per customer.
                        </div>
                        <div class="feature-item">
                            <strong>Discount Analytics & Reporting</strong>: Track discount performance and impact on sales.
                        </div>
                        <div class="feature-item">
                            <strong>Discount Approval Workflow</strong>: Implement approval process for discounts with audit trail.
                        </div>
                        <div class="feature-item">
                            <strong>Coupon Code Management</strong>: Enhanced coupon code generation and tracking.
                        </div>
                        <div class="feature-item">
                            <strong>Marketing Campaign Integration</strong>: Link discounts to marketing campaigns with UTM tracking.
                        </div>
                        <div class="feature-item">
                            <strong>Mobile Discount Notifications</strong>: Send notifications about available discounts to customers.
                        </div>
                    </div>
                    
                    <div class="warning">
                        <strong>Warning:</strong> This upgrade will modify your database structure. Please make sure you have a backup of your database before proceeding.
                    </div>
                    
                    <button id="upgradeBtn" class="btn btn-primary">
                        <i class="fas fa-sync"></i> Upgrade Discount System
                    </button>
                    
                    <div id="upgradeResult"></div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../../includes/footer.php'; ?>
    
    <!-- jQuery and Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Upgrade Script -->
    <script>
        $(document).ready(function() {
            $('#upgradeBtn').on('click', function() {
                if (confirm('Are you sure you want to upgrade the discount system? This action cannot be undone.')) {
                    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Upgrading...');
                    
                    $.ajax({
                        url: 'actions/upgrade_discount_system.php',
                        type: 'POST',
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                $('#upgradeResult').removeClass('error').addClass('success').html(response.message).show();
                                setTimeout(function() {
                                    window.location.href = 'discounts.php?upgraded=1';
                                }, 3000);
                            } else {
                                $('#upgradeResult').removeClass('success').addClass('error').html(response.message).show();
                                $('#upgradeBtn').prop('disabled', false).html('<i class="fas fa-sync"></i> Retry Upgrade');
                            }
                        },
                        error: function() {
                            $('#upgradeResult').removeClass('success').addClass('error').html('An error occurred during the upgrade process.').show();
                            $('#upgradeBtn').prop('disabled', false).html('<i class="fas fa-sync"></i> Retry Upgrade');
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
