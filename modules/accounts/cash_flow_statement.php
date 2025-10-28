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

$pageTitle = "Cash Flow Statement";
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Cash Flow Statement</h6>
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
                    <form id="cashFlowForm" class="mb-4">
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
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary btn-block">
                                        Generate Report
                                    </button>
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
                            <h5>Cash Flow Statement</h5>
                            <p class="mb-1">For the period <span id="reportPeriod"></span></p>
                        </div>

                        <!-- Cash Flow Statement Content -->
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th class="text-right">Current Period</th>
                                        <th class="text-right compare-column" style="display: none;">Previous Period</th>
                                        <th class="text-right compare-column" style="display: none;">Variance</th>
                                    </tr>
                                </thead>
                                <tbody id="cashFlowBody">
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
    $('#cashFlowForm').submit(function(e) {
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
});

function generateReport() {
    $.ajax({
        url: 'actions/get_cash_flow_statement.php',
        type: 'POST',
        data: $('#cashFlowForm').serialize(),
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
    
    var html = '';
    
    // Operating Activities
    html += '<tr class="table-primary"><td colspan="4"><strong>Cash Flows from Operating Activities</strong></td></tr>';
    
    // Net Income
    html += `
        <tr>
            <td>Net Income</td>
            <td class="text-right">${formatAmount(data.net_income)}</td>
            ${data.compare ? `
                <td class="text-right compare-column">${formatAmount(data.previous_net_income)}</td>
                <td class="text-right compare-column">${formatAmount(data.net_income_variance)}</td>
            ` : ''}
        </tr>
    `;
    
    // Adjustments
    html += '<tr class="table-secondary"><td colspan="4"><strong>Adjustments for non-cash items:</strong></td></tr>';
    data.adjustments.forEach(function(item) {
        html += `
            <tr>
                <td>${item.description}</td>
                <td class="text-right">${formatAmount(item.amount)}</td>
                ${data.compare ? `
                    <td class="text-right compare-column">${formatAmount(item.previous_amount)}</td>
                    <td class="text-right compare-column">${formatAmount(item.variance)}</td>
                ` : ''}
            </tr>
        `;
    });
    
    // Operating Assets and Liabilities Changes
    html += '<tr class="table-secondary"><td colspan="4"><strong>Changes in operating assets and liabilities:</strong></td></tr>';
    data.operating_changes.forEach(function(item) {
        html += `
            <tr>
                <td>${item.description}</td>
                <td class="text-right">${formatAmount(item.amount)}</td>
                ${data.compare ? `
                    <td class="text-right compare-column">${formatAmount(item.previous_amount)}</td>
                    <td class="text-right compare-column">${formatAmount(item.variance)}</td>
                ` : ''}
            </tr>
        `;
    });
    
    // Net Operating Cash Flow
    html += `
        <tr class="font-weight-bold">
            <td>Net Cash from Operating Activities</td>
            <td class="text-right">${formatAmount(data.net_operating_cash)}</td>
            ${data.compare ? `
                <td class="text-right compare-column">${formatAmount(data.previous_net_operating_cash)}</td>
                <td class="text-right compare-column">${formatAmount(data.operating_cash_variance)}</td>
            ` : ''}
        </tr>
    `;
    
    // Investing Activities
    html += '<tr class="table-primary"><td colspan="4"><strong>Cash Flows from Investing Activities</strong></td></tr>';
    data.investing_activities.forEach(function(item) {
        html += `
            <tr>
                <td>${item.description}</td>
                <td class="text-right">${formatAmount(item.amount)}</td>
                ${data.compare ? `
                    <td class="text-right compare-column">${formatAmount(item.previous_amount)}</td>
                    <td class="text-right compare-column">${formatAmount(item.variance)}</td>
                ` : ''}
            </tr>
        `;
    });
    
    // Net Investing Cash Flow
    html += `
        <tr class="font-weight-bold">
            <td>Net Cash from Investing Activities</td>
            <td class="text-right">${formatAmount(data.net_investing_cash)}</td>
            ${data.compare ? `
                <td class="text-right compare-column">${formatAmount(data.previous_net_investing_cash)}</td>
                <td class="text-right compare-column">${formatAmount(data.investing_cash_variance)}</td>
            ` : ''}
        </tr>
    `;
    
    // Financing Activities
    html += '<tr class="table-primary"><td colspan="4"><strong>Cash Flows from Financing Activities</strong></td></tr>';
    data.financing_activities.forEach(function(item) {
        html += `
            <tr>
                <td>${item.description}</td>
                <td class="text-right">${formatAmount(item.amount)}</td>
                ${data.compare ? `
                    <td class="text-right compare-column">${formatAmount(item.previous_amount)}</td>
                    <td class="text-right compare-column">${formatAmount(item.variance)}</td>
                ` : ''}
            </tr>
        `;
    });
    
    // Net Financing Cash Flow
    html += `
        <tr class="font-weight-bold">
            <td>Net Cash from Financing Activities</td>
            <td class="text-right">${formatAmount(data.net_financing_cash)}</td>
            ${data.compare ? `
                <td class="text-right compare-column">${formatAmount(data.previous_net_financing_cash)}</td>
                <td class="text-right compare-column">${formatAmount(data.financing_cash_variance)}</td>
            ` : ''}
        </tr>
    `;
    
    // Net Change in Cash
    html += `
        <tr class="font-weight-bold table-info">
            <td>Net Increase (Decrease) in Cash</td>
            <td class="text-right">${formatAmount(data.net_change)}</td>
            ${data.compare ? `
                <td class="text-right compare-column">${formatAmount(data.previous_net_change)}</td>
                <td class="text-right compare-column">${formatAmount(data.net_change_variance)}</td>
            ` : ''}
        </tr>
    `;
    
    // Cash Balance Summary
    html += `
        <tr>
            <td>Cash at Beginning of Period</td>
            <td class="text-right">${formatAmount(data.beginning_cash)}</td>
            ${data.compare ? `
                <td class="text-right compare-column">${formatAmount(data.previous_beginning_cash)}</td>
                <td class="text-right compare-column">${formatAmount(data.beginning_cash_variance)}</td>
            ` : ''}
        </tr>
        <tr class="font-weight-bold">
            <td>Cash at End of Period</td>
            <td class="text-right">${formatAmount(data.ending_cash)}</td>
            ${data.compare ? `
                <td class="text-right compare-column">${formatAmount(data.previous_ending_cash)}</td>
                <td class="text-right compare-column">${formatAmount(data.ending_cash_variance)}</td>
            ` : ''}
        </tr>
    `;
    
    $('#cashFlowBody').html(html);
    $('#reportContent').show();
}

// Use the centralized formatting helper function
function formatAmount(amount) {
    // Use the global formatNumber function from format_helpers.js
    return formatNumber(amount);
}

function printReport() {
    window.print();
}

function exportToExcel() {
    // Get the report data
    var data = [];
    
    // Add header
    data.push(['Cash Flow Statement']);
    data.push(['For the period ' + $('#reportPeriod').text()]);
    data.push([]);
    
    // Add table data
    $('#cashFlowBody tr').each(function() {
        var row = [
            $(this).find('td:first').text().trim(),
            $(this).find('td:eq(1)').text().trim()
        ];
        
        if ($('#compareWithPrevious').is(':checked')) {
            row.push($(this).find('td:eq(2)').text().trim());
            row.push($(this).find('td:eq(3)').text().trim());
        }
        
        data.push(row);
    });
    
    // Create worksheet
    var ws = XLSX.utils.aoa_to_sheet(data);
    
    // Create workbook
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Cash Flow Statement');
    
    // Save file
    XLSX.writeFile(wb, 'cash_flow_statement.xlsx');
}
</script>
