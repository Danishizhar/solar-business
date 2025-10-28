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

$pageTitle = "Income Statement";
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Income Statement</h6>
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
                    <form id="incomeStatementForm" class="mb-4">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Start Date</label>
                                    <input type="date" class="form-control" name="start_date" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>End Date</label>
                                    <input type="date" class="form-control" name="end_date" required>
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
                                            <option value="<?php echo $year['id']; ?>" 
                                                    data-start="<?php echo $year['start_date']; ?>" 
                                                    data-end="<?php echo $year['end_date']; ?>">
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
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="compareWithPrevious" name="compare_with_previous">
                                        <label class="custom-control-label" for="compareWithPrevious">Compare with Previous Period</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="showPercentages" name="show_percentages">
                                        <label class="custom-control-label" for="showPercentages">Show Percentages</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 text-right">
                                <button type="submit" class="btn btn-primary">
                                    Generate Report
                                </button>
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
                            <h5>Income Statement</h5>
                            <p class="mb-1">For the period <span id="reportPeriod"></span></p>
                            <p id="costCenterInfo"></p>
                        </div>

                        <!-- Income Statement Content -->
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Account</th>
                                        <th class="text-right">Current Period</th>
                                        <th class="text-right compare-column" style="display: none;">Previous Period</th>
                                        <th class="text-right compare-column" style="display: none;">Variance</th>
                                        <th class="text-right percentage-column" style="display: none;">% of Revenue</th>
                                    </tr>
                                </thead>
                                <tbody id="incomeStatementBody">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                            </table>
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
    // Handle financial year selection to set date range
    $('select[name="financial_year_id"]').change(function() {
        var selectedOption = $(this).find('option:selected');
        if (selectedOption.val()) {
            $('input[name="start_date"]').val(selectedOption.data('start'));
            $('input[name="end_date"]').val(selectedOption.data('end'));
        }
    });

    // Handle form submission
    $('#incomeStatementForm').submit(function(e) {
        e.preventDefault();
        generateReport();
    });

    // Handle checkbox changes
    $('#compareWithPrevious').change(function() {
        if ($(this).is(':checked')) {
            $('.compare-column').show();
        } else {
            $('.compare-column').hide();
        }
    });

    $('#showPercentages').change(function() {
        if ($(this).is(':checked')) {
            $('.percentage-column').show();
        } else {
            $('.percentage-column').hide();
        }
    });
});

function generateReport() {
    $.ajax({
        url: 'actions/get_income_statement.php',
        type: 'POST',
        data: $('#incomeStatementForm').serialize(),
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
    $('#reportPeriod').text(data.period);
    $('#costCenterInfo').text(data.cost_center ? 'Cost Center: ' + data.cost_center : 'All Cost Centers');
    
    var html = '';
    
    // Revenue section
    html += '<tr class="table-primary"><td colspan="5"><strong>Revenue</strong></td></tr>';
    data.revenue.forEach(function(item) {
        var indent = '&nbsp;'.repeat(item.level * 4);
        html += `
            <tr>
                <td>${indent}${item.account_name}</td>
                <td class="text-right">${formatAmount(item.amount)}</td>
                ${data.compare ? `
                    <td class="text-right compare-column">${formatAmount(item.previous_amount)}</td>
                    <td class="text-right compare-column">${formatAmount(item.variance)}</td>
                ` : ''}
                ${data.show_percentages ? `
                    <td class="text-right percentage-column">${formatPercentage(item.percentage)}</td>
                ` : ''}
            </tr>
        `;
    });
    
    // Total Revenue
    html += `
        <tr class="font-weight-bold">
            <td>Total Revenue</td>
            <td class="text-right">${formatAmount(data.total_revenue)}</td>
            ${data.compare ? `
                <td class="text-right compare-column">${formatAmount(data.previous_total_revenue)}</td>
                <td class="text-right compare-column">${formatAmount(data.revenue_variance)}</td>
            ` : ''}
            ${data.show_percentages ? `
                <td class="text-right percentage-column">100%</td>
            ` : ''}
        </tr>
    `;
    
    // Expenses section
    html += '<tr class="table-primary"><td colspan="5"><strong>Expenses</strong></td></tr>';
    data.expenses.forEach(function(item) {
        var indent = '&nbsp;'.repeat(item.level * 4);
        html += `
            <tr>
                <td>${indent}${item.account_name}</td>
                <td class="text-right">${formatAmount(item.amount)}</td>
                ${data.compare ? `
                    <td class="text-right compare-column">${formatAmount(item.previous_amount)}</td>
                    <td class="text-right compare-column">${formatAmount(item.variance)}</td>
                ` : ''}
                ${data.show_percentages ? `
                    <td class="text-right percentage-column">${formatPercentage(item.percentage)}</td>
                ` : ''}
            </tr>
        `;
    });
    
    // Total Expenses
    html += `
        <tr class="font-weight-bold">
            <td>Total Expenses</td>
            <td class="text-right">${formatAmount(data.total_expenses)}</td>
            ${data.compare ? `
                <td class="text-right compare-column">${formatAmount(data.previous_total_expenses)}</td>
                <td class="text-right compare-column">${formatAmount(data.expenses_variance)}</td>
            ` : ''}
            ${data.show_percentages ? `
                <td class="text-right percentage-column">${formatPercentage(data.expenses_percentage)}</td>
            ` : ''}
        </tr>
    `;
    
    // Net Income/Loss
    html += `
        <tr class="font-weight-bold table-info">
            <td>Net ${data.net_income >= 0 ? 'Income' : 'Loss'}</td>
            <td class="text-right">${formatAmount(data.net_income)}</td>
            ${data.compare ? `
                <td class="text-right compare-column">${formatAmount(data.previous_net_income)}</td>
                <td class="text-right compare-column">${formatAmount(data.net_income_variance)}</td>
            ` : ''}
            ${data.show_percentages ? `
                <td class="text-right percentage-column">${formatPercentage(data.net_income_percentage)}</td>
            ` : ''}
        </tr>
    `;
    
    $('#incomeStatementBody').html(html);
    
    // Show report content
    $('#reportContent').show();
}

// Use the centralized formatting helper functions
function formatAmount(amount) {
    // Use the global formatNumber function from format_helpers.js
    return formatNumber(amount);
}

function formatPercentage(value) {
    // Use the global formatPercentage function from format_helpers.js
    return window.formatPercentage(value);
}

function printReport() {
    window.print();
}

function exportToExcel() {
    // Get the report data
    var data = [];
    
    // Add header
    data.push(['Income Statement']);
    data.push(['For the period ' + $('#reportPeriod').text()]);
    data.push([$('#costCenterInfo').text()]);
    data.push([]);
    
    // Add revenue section
    data.push(['Revenue']);
    $('#incomeStatementBody tr').each(function() {
        if ($(this).find('td:first').text().trim() !== '') {
            var row = [
                $(this).find('td:first').text().trim(),
                $(this).find('td:eq(1)').text().trim()
            ];
            
            if ($('#compareWithPrevious').is(':checked')) {
                row.push($(this).find('td:eq(2)').text().trim());
                row.push($(this).find('td:eq(3)').text().trim());
            }
            
            if ($('#showPercentages').is(':checked')) {
                row.push($(this).find('td:last').text().trim());
            }
            
            data.push(row);
        }
    });
    
    // Create worksheet
    var ws = XLSX.utils.aoa_to_sheet(data);
    
    // Create workbook
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Income Statement');
    
    // Save file
    XLSX.writeFile(wb, 'income_statement.xlsx');
}
</script>
