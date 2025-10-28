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

$pageTitle = "Trial Balance";
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Trial Balance</h6>
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
                    <form id="trialBalanceForm" class="mb-4">
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
                                    <label>Include Zero Balances</label>
                                    <select class="form-control" name="include_zero_balances">
                                        <option value="0">No</option>
                                        <option value="1">Yes</option>
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
                            <h5>Trial Balance</h5>
                            <p class="mb-1">As of <span id="reportDate"></span></p>
                        </div>

                        <!-- Trial Balance Content -->
                        <div class="table-responsive">
                            <table class="table table-bordered" id="trialBalanceTable">
                                <thead>
                                    <tr>
                                        <th>Account Code</th>
                                        <th>Account Name</th>
                                        <th class="text-right">Debit</th>
                                        <th class="text-right">Credit</th>
                                    </tr>
                                </thead>
                                <tbody id="trialBalanceBody">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                                <tfoot>
                                    <tr class="font-weight-bold">
                                        <td colspan="2" class="text-right">Total</td>
                                        <td class="text-right" id="totalDebit">0.00</td>
                                        <td class="text-right" id="totalCredit">0.00</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Handle financial year selection to set date range
    $('select[name="financial_year_id"]').change(function() {
        var selectedOption = $(this).find('option:selected');
        if (selectedOption.val()) {
            $('input[name="as_of_date"]').val(selectedOption.data('end'));
        }
    });

    // Handle form submission
    $('#trialBalanceForm').submit(function(e) {
        e.preventDefault();
        generateReport();
    });
});

function generateReport() {
    $.ajax({
        url: 'actions/get_trial_balance.php',
        type: 'POST',
        data: $('#trialBalanceForm').serialize(),
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
    
    // Build the trial balance table
    var html = '';
    var totalDebit = 0;
    var totalCredit = 0;
    
    data.accounts.forEach(function(account) {
        html += `
            <tr>
                <td>${account.account_code}</td>
                <td>${account.account_name}</td>
                <td class="text-right">${account.debit_balance > 0 ? formatAmount(account.debit_balance) : ''}</td>
                <td class="text-right">${account.credit_balance > 0 ? formatAmount(account.credit_balance) : ''}</td>
            </tr>
        `;
        
        totalDebit += parseFloat(account.debit_balance);
        totalCredit += parseFloat(account.credit_balance);
    });

    // Update the table body and totals
    $('#trialBalanceBody').html(html);
    $('#totalDebit').text(formatAmount(totalDebit));
    $('#totalCredit').text(formatAmount(totalCredit));

    // Show report content
    $('#reportContent').show();
}

// Use the centralized formatting helper function
function formatAmount(amount) {
    // Use the global formatCurrency function from format_helpers.js
    return formatNumber(amount);
}

function printReport() {
    var printContents = document.getElementById('reportContent').innerHTML;
    var originalContents = document.body.innerHTML;
    
    document.body.innerHTML = `
        <div class="container mt-4">
            ${printContents}
        </div>
    `;
    
    window.print();
    document.body.innerHTML = originalContents;
    
    // Reinitialize event handlers
    $(document).ready();
}

function exportToExcel() {
    // Create a CSV string
    var csv = [];
    var rows = document.querySelectorAll('#trialBalanceTable tr');
    
    // Add header
    csv.push(['<?php echo $company['company_name']; ?>']);
    csv.push(['Trial Balance']);
    csv.push(['As of ' + $('#reportDate').text()]);
    csv.push([]);
    
    // Add table data
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll('td, th');
        for (var j = 0; j < cols.length; j++) {
            // Clean the text (remove HTML and extra whitespace)
            var text = cols[j].innerText.replace(/[\n\r]+|[\s]{2,}/g, ' ').trim();
            row.push('"' + text + '"');
        }
        csv.push(row.join(','));
    }
    
    // Download CSV file
    var csvContent = csv.join('\n');
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    var url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'trial_balance_' + $('#reportDate').text().replace(/[\s,]/g, '_') + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php include '../../includes/footer.php'; ?>
