<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/company_helpers.php';
$database = new Database();
$conn = $database->getConnection();

$pageTitle = "Balance Sheet";
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Balance Sheet</h6>
                    <div>
                        <button type="button" class="btn btn-primary btn-sm" onclick="printReport()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button type="button" class="btn btn-success btn-sm" onclick="exportToExcel()">
                            <i class="fas fa-file-excel"></i> Export
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <form id="balanceSheetForm" class="mb-4">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>As of Date</label>
                                    <input type="date" class="form-control" name="as_of_date" required 
                                           value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Financial Year</label>
                                    <select class="form-control" name="financial_year_id" required>
                                        <option value="">Select Financial Year</option>
                                        <?php 
                                        // Get financial years for dropdown
                                        $years_query = "SELECT id, year_code, start_date, end_date FROM financial_years WHERE status = 1 ORDER BY start_date DESC";
                                        $stmt = $conn->prepare($years_query);
                                        $stmt->execute();
                                        $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($years as $year): ?>
                                            <option value="<?php echo $year['id']; ?>">
                                                <?php echo $year['year_code']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Cost Center</label>
                                    <select class="form-control" name="cost_center_id">
                                        <option value="">All Cost Centers</option>
                                        <?php 
                                        // Get cost centers
                                        $cost_centers_query = "SELECT id, code, name FROM cost_centers WHERE status = 1 ORDER BY code";
                                        $stmt = $conn->prepare($cost_centers_query);
                                        $stmt->execute();
                                        $cost_centers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($cost_centers as $cc): ?>
                                            <option value="<?php echo $cc['id']; ?>">
                                                <?php echo $cc['code'] . ' - ' . $cc['name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary btn-block">
                                        Generate Report
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>

                    <div id="reportContent" style="display: none;">
                        <!-- Report Header -->
                        <div class="text-center mb-4">
                            <?php 
                            // Include company helpers if not already included
                            if (!function_exists('getCompanyInfo')) {
                                require_once '../../includes/company_helpers.php';
                            }
                            
                            // Get company information using helper function
                            $company = isset($_SESSION['company']) ? $_SESSION['company'] : getCompanyInfo($conn);
                            ?>
                            <?php if (!empty($company['logo_path'])): ?>
                            <div style="text-align: center; margin-bottom: 10px;">
                                <img src="<?php echo '../../' . $company['logo_path']; ?>" alt="Company Logo" style="max-height: 80px; max-width: 200px;">
                            </div>
                            <?php endif; ?>
                            <h4><?php echo htmlspecialchars($company['company_name'] ?? 'Solar Business'); ?></h4>
                            <?php if (!empty($company['address'])): ?>
                            <p class="mb-1"><?php echo htmlspecialchars($company['address']); ?></p>
                            <?php endif; ?>
                            <h5>Balance Sheet</h5>
                            <p class="mb-1">As of <span id="reportDate"></span></p>
                            <p id="costCenterInfo"></p>
                        </div>

                        <!-- Balance Sheet Content -->
                        <div class="row">
                            <!-- Assets -->
                            <div class="col-md-6">
                                <h6 class="font-weight-bold">Assets</h6>
                                <div id="assetsContent"></div>
                                <div class="border-top mt-2 pt-2">
                                    <strong>Total Assets: </strong>
                                    <span id="totalAssets" class="float-right"></span>
                                </div>
                            </div>

                            <!-- Liabilities and Equity -->
                            <div class="col-md-6">
                                <h6 class="font-weight-bold">Liabilities</h6>
                                <div id="liabilitiesContent"></div>
                                <div class="border-top mt-2 pt-2">
                                    <strong>Total Liabilities: </strong>
                                    <span id="totalLiabilities" class="float-right"></span>
                                </div>

                                <h6 class="font-weight-bold mt-4">Equity</h6>
                                <div id="equityContent"></div>
                                <div class="border-top mt-2 pt-2">
                                    <strong>Total Equity: </strong>
                                    <span id="totalEquity" class="float-right"></span>
                                </div>

                                <div class="border-top mt-2 pt-2">
                                    <strong>Total Liabilities and Equity: </strong>
                                    <span id="totalLiabilitiesEquity" class="float-right"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Handle form submission
    $('#balanceSheetForm').submit(function(e) {
        e.preventDefault();
        generateReport();
    });

    // Initialize date picker with the first day of current month
    var today = new Date();
    $('input[name="as_of_date"]').val(today.toISOString().split('T')[0]);
});

function generateReport() {
    $.ajax({
        url: 'actions/get_balance_sheet.php',
        type: 'POST',
        data: $('#balanceSheetForm').serialize(),
        success: function(response) {
            var data = JSON.parse(response);
            if (data.success) {
                displayReport(data);
            } else {
                showAlert('error', data.message);
            }
        }
    });
}

function displayReport(data) {
    // Update report header
    $('#reportDate').text(data.as_of_date);
    $('#costCenterInfo').text(data.cost_center ? 'Cost Center: ' + data.cost_center : 'All Cost Centers');
    
    // Display assets
    var assetsHtml = '';
    data.assets.forEach(function(asset) {
        var indent = '&nbsp;'.repeat(asset.level * 4);
        assetsHtml += `
            <div class="row">
                <div class="col-8">${indent}${asset.account_name}</div>
                <div class="col-4 text-right">${formatAmount(asset.balance)}</div>
            </div>
        `;
    });
    $('#assetsContent').html(assetsHtml);
    $('#totalAssets').text(formatAmount(data.total_assets));

    // Display liabilities
    var liabilitiesHtml = '';
    data.liabilities.forEach(function(liability) {
        var indent = '&nbsp;'.repeat(liability.level * 4);
        liabilitiesHtml += `
            <div class="row">
                <div class="col-8">${indent}${liability.account_name}</div>
                <div class="col-4 text-right">${formatAmount(liability.balance)}</div>
            </div>
        `;
    });
    $('#liabilitiesContent').html(liabilitiesHtml);
    $('#totalLiabilities').text(formatAmount(data.total_liabilities));

    // Display equity
    var equityHtml = '';
    data.equity.forEach(function(equity) {
        var indent = '&nbsp;'.repeat(equity.level * 4);
        equityHtml += `
            <div class="row">
                <div class="col-8">${indent}${equity.account_name}</div>
                <div class="col-4 text-right">${formatAmount(equity.balance)}</div>
            </div>
        `;
    });
    $('#equityContent').html(equityHtml);
    $('#totalEquity').text(formatAmount(data.total_equity));
    $('#totalLiabilitiesEquity').text(formatAmount(data.total_liabilities + data.total_equity));

    // Show report content
    $('#reportContent').show();
}

function formatAmount(amount) {
    // Use the global formatCurrency function from format_helpers.js
    return formatCurrency(amount, false);
}

function printReport() {
    window.print();
}

function exportToExcel() {
    // Get the report data
    var data = [];
    
    // Add header
    data.push(['Balance Sheet']);
    data.push(['As of ' + $('#reportDate').text()]);
    data.push([]);
    
    // Add assets
    data.push(['Assets']);
    $('#assetsContent .row').each(function() {
        data.push([
            $(this).find('.col-8').text().trim(),
            $(this).find('.col-4').text().trim()
        ]);
    });
    data.push(['Total Assets', $('#totalAssets').text()]);
    data.push([]);
    
    // Add liabilities
    data.push(['Liabilities']);
    $('#liabilitiesContent .row').each(function() {
        data.push([
            $(this).find('.col-8').text().trim(),
            $(this).find('.col-4').text().trim()
        ]);
    });
    data.push(['Total Liabilities', $('#totalLiabilities').text()]);
    data.push([]);
    
    // Add equity
    data.push(['Equity']);
    $('#equityContent .row').each(function() {
        data.push([
            $(this).find('.col-8').text().trim(),
            $(this).find('.col-4').text().trim()
        ]);
    });
    data.push(['Total Equity', $('#totalEquity').text()]);
    data.push(['Total Liabilities and Equity', $('#totalLiabilitiesEquity').text()]);
    
    // Create worksheet
    var ws = XLSX.utils.aoa_to_sheet(data);
    
    // Create workbook
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Balance Sheet');
    
    // Save file
    XLSX.writeFile(wb, 'balance_sheet.xlsx');
}
</script>
