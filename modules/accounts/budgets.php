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
$database = new Database();
$conn = $database->getConnection();

// Get financial years for dropdown
try {
    $years_query = "SELECT id, year_code, start_date, end_date, is_closed FROM financial_years WHERE status = 1 ORDER BY start_date DESC";
    $stmt = $conn->prepare($years_query);
    $stmt->execute();
    $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get current financial year
    $current_date = date('Y-m-d');
    $current_year_query = "SELECT id FROM financial_years WHERE status = 1 AND is_closed = 0 AND '$current_date' BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1";
    $stmt = $conn->prepare($current_year_query);
    $stmt->execute();
    $current_year = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_year_id = $current_year ? $current_year['id'] : ($years[0]['id'] ?? null);
} catch (PDOException $e) {
    error_log("Error fetching financial years: " . $e->getMessage());
    $years = [];
    $current_year_id = null;
}

// Get all accounts for dropdown
try {
    $accounts_query = "SELECT id, account_code, account_name FROM chart_of_accounts WHERE status = 1 ORDER BY account_code";
    $stmt = $conn->prepare($accounts_query);
    $stmt->execute();
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching accounts: " . $e->getMessage());
    $accounts = [];
}

// Get all cost centers for dropdown
try {
    $cost_centers_query = "SELECT id, code, name FROM cost_centers WHERE status = 1 ORDER BY code";
    $stmt = $conn->prepare($cost_centers_query);
    $stmt->execute();
    $cost_centers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching cost centers: " . $e->getMessage());
    $cost_centers = [];
}

$pageTitle = "Budget Management";
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary"><?php echo $pageTitle; ?></h6>
                    <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_add'])): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBudgetModal">
                        <i class="fas fa-plus"></i> New Budget
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="alert alert-success alert-dismissible fade" role="alert" id="successAlert" style="display:none">
                        <span id="successMessage"></span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <div class="alert alert-danger alert-dismissible fade" role="alert" id="errorAlert" style="display:none">
                        <span id="errorMessage"></span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered" id="budgetsTable" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Fiscal Year</th>
                                    <th>Account</th>
                                    <th>Cost Center</th>
                                    <th>Period</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Budget Modal -->
<div class="modal fade" id="addBudgetModal" tabindex="-1" aria-labelledby="addBudgetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addBudgetModalLabel">Add New Budget</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addBudgetForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Financial Year *</label>
                                <select class="form-control" name="fiscal_year" required>
                                    <option value="">Select Financial Year</option>
                                    <?php foreach ($years as $year): 
                                        // Skip closed financial years for new budgets
                                        if ($year['is_closed'] == 1) continue;
                                    ?>
                                    <option value="<?php echo $year['id']; ?>" <?php echo ($year['id'] == $current_year_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['year_code'] . ' (' . date('d M Y', strtotime($year['start_date'])) . ' - ' . date('d M Y', strtotime($year['end_date'])) . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Account</label>
                                <select class="form-control" name="account_id" required>
                                    <option value="">Select Account</option>
                                    <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>"><?php echo $account['account_code']; ?> - <?php echo $account['account_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Cost Center</label>
                                <select class="form-control" name="cost_center_id">
                                    <option value="">Select Cost Center</option>
                                    <?php foreach ($cost_centers as $cost_center): ?>
                                    <option value="<?php echo $cost_center['id']; ?>"><?php echo $cost_center['code']; ?> - <?php echo $cost_center['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Period</label>
                                <select class="form-control" name="period" required>
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="yearly">Yearly</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                    <div id="monthlyAmounts" class="budget-amounts">
                        <h6>Monthly Amounts</h6>
                        <div class="row">
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Month <?php echo $i; ?></label>
                                    <input type="number" class="form-control monthly-amount" name="monthly_amount_<?php echo $i; ?>" step="0.01" value="0.00">
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div id="quarterlyAmounts" class="budget-amounts" style="display: none;">
                        <h6>Quarterly Amounts</h6>
                        <div class="row">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Quarter <?php echo $i; ?></label>
                                    <input type="number" class="form-control quarterly-amount" name="quarterly_amount_<?php echo $i; ?>" step="0.01" value="0.00">
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div id="yearlyAmount" class="budget-amounts" style="display: none;">
                        <h6>Yearly Amount</h6>
                        <div class="form-group">
                            <input type="number" class="form-control" name="yearly_amount" step="0.01" value="0.00">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Budget</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Budget Modal -->
<div class="modal fade" id="editBudgetModal" tabindex="-1" aria-labelledby="editBudgetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editBudgetModalLabel">Edit Budget</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editBudgetForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Financial Year *</label>
                                <select class="form-control" name="fiscal_year" id="edit_fiscal_year" required>
                                    <option value="">Select Financial Year</option>
                                    <?php foreach ($years as $year): ?>
                                    <option value="<?php echo $year['id']; ?>">
                                        <?php echo htmlspecialchars($year['year_code'] . ' (' . date('d M Y', strtotime($year['start_date'])) . ' - ' . date('d M Y', strtotime($year['end_date'])) . ')'); ?>
                                        <?php if ($year['is_closed'] == 1): ?><span class="text-muted">(Closed)</span><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Account</label>
                                <select class="form-control" name="account_id" id="edit_account_id" required>
                                    <option value="">Select Account</option>
                                    <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>"><?php echo $account['account_code']; ?> - <?php echo $account['account_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Cost Center</label>
                                <select class="form-control" name="cost_center_id" id="edit_cost_center_id">
                                    <option value="">Select Cost Center</option>
                                    <?php foreach ($cost_centers as $cost_center): ?>
                                    <option value="<?php echo $cost_center['id']; ?>"><?php echo $cost_center['code']; ?> - <?php echo $cost_center['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label>Period</label>
                                <select class="form-control" name="period" id="edit_period" required>
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="yearly">Yearly</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-4">
                        <label>Notes</label>
                        <textarea class="form-control" name="notes" id="edit_notes" rows="3"></textarea>
                    </div>
                    <div id="edit_monthlyAmounts" class="budget-amounts">
                        <h6 class="mb-3">Monthly Amounts</h6>
                        <div class="row">
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                            <div class="col-md-3 mb-3">
                                <div class="form-group">
                                    <label>Month <?php echo $i; ?></label>
                                    <input type="number" class="form-control monthly-amount" name="monthly_amount_<?php echo $i; ?>" id="edit_monthly_amount_<?php echo $i; ?>" step="0.01" min="0" value="0.00">
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div id="edit_quarterlyAmounts" class="budget-amounts" style="display: none;">
                        <h6 class="mb-3">Quarterly Amounts</h6>
                        <div class="row">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                            <div class="col-md-3 mb-3">
                                <div class="form-group">
                                    <label>Quarter <?php echo $i; ?></label>
                                    <input type="number" class="form-control quarterly-amount" name="quarterly_amount_<?php echo $i; ?>" id="edit_quarterly_amount_<?php echo $i; ?>" step="0.01" min="0" value="0.00">
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div id="edit_yearlyAmount" class="budget-amounts" style="display: none;">
                        <h6 class="mb-3">Yearly Amount</h6>
                        <div class="form-group">
                            <input type="number" class="form-control" name="yearly_amount" id="edit_yearly_amount" step="0.01" min="0" value="0.00">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Budget</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Budget Modal -->
<div class="modal fade" id="viewBudgetModal" tabindex="-1" role="dialog" aria-labelledby="viewBudgetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewBudgetModalLabel">View Budget Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Fiscal Year:</strong> <span id="view_fiscal_year"></span></p>
                        <p><strong>Account:</strong> <span id="view_account"></span></p>
                        <p><strong>Cost Center:</strong> <span id="view_cost_center"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Period:</strong> <span id="view_period"></span></p>
                        <p><strong>Total Amount:</strong> <span id="view_total_amount"></span></p>
                        <p><strong>Notes:</strong> <span id="view_notes"></span></p>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <div id="view_period_amounts"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Function to show success message
    function showSuccess(message) {
        $('#successMessage').text(message);
        $('#successAlert').addClass('show').show();
        setTimeout(function() {
            $('#successAlert').removeClass('show').hide();
        }, 3000);
    }

    // Function to show error message
    function showError(message) {
        $('#errorMessage').text(message);
        $('#errorAlert').addClass('show').show();
        setTimeout(function() {
            $('#errorAlert').removeClass('show').hide();
        }, 3000);
    }

    // Function to reset budget form
    function resetBudgetForm(formId) {
        $(formId)[0].reset();
        
        // Reset select elements to first option
        $(formId + ' select').each(function() {
            $(this).val($(this).find('option:first').val());
        });

        // Reset all amount fields to 0.00
        $(formId + ' input[type="number"]').val('0.00');
        
        // Reset notes
        $(formId + ' textarea').val('');
        
        // Hide all amount sections
        $('.budget-amounts').hide();
        
        // Show monthly amounts by default
        if (formId === '#addBudgetForm') {
            $('#monthlyAmounts').show();
        } else {
            $('#edit_monthlyAmounts').show();
        }
        
        // Clear any validation errors
        $(formId + ' .is-invalid').removeClass('is-invalid');
        $(formId + ' .invalid-feedback').remove();
    }

    // Initialize DataTable with proper configuration
    var table = $('#budgetsTable').DataTable({
        processing: true,
        serverSide: false,
        pageLength: 25,
        dom: '<"row"<"col-sm-12 col-md-6"B><"col-sm-12 col-md-6"f>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            {
                extend: 'collection',
                text: '<i class="fas fa-download"></i> Export',
                buttons: [
                    'copy',
                    'excel',
                    'csv',
                    'pdf',
                    'print'
                ]
            }
        ],
        ajax: {
            url: 'actions/get_budgets.php',
            dataSrc: function(json) {
                if (!json.success) {
                    showError(json.message || 'Error loading budgets');
                    return [];
                }
                return json.data || [];
            }
        },
        order: [[1, 'desc']],
        columns: [
            { 
                data: 'fiscal_year_code',
                render: function(data, type, row) {
                    return data || 'N/A';
                }
            },
            { 
                data: 'account_name',
                render: function(data, type, row) {
                    return row.account_code + ' - ' + data;
                }
            },
            { 
                data: 'cost_center_name',
                render: function(data, type, row) {
                    return row.cost_center_id ? (row.cost_center_code + ' - ' + data) : 'N/A';
                }
            },
            { 
                data: 'period',
                render: function(data) {
                    return data.charAt(0).toUpperCase() + data.slice(1);
                }
            },
            { 
                data: 'amount',
                className: 'text-end',
                render: function(data, type) {
                    if (type === 'display') {
                        return parseFloat(data).toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                    }
                    return data;
                }
            },
            { 
                data: 'status',
                className: 'text-center',
                render: function(data, type, row) {
                    var statusClass = parseInt(data) === 1 ? 'success' : 'danger';
                    var statusText = parseInt(data) === 1 ? 'Active' : 'Inactive';
                    return '<span class="badge bg-' + statusClass + '">' + statusText + '</span>';
                }
            },
            { 
                data: null,
                className: 'text-center',
                orderable: false,
                render: function(data, type, row) {
                    var buttons = '<div class="btn-group" role="group">';
                    
                    // View button - always shown
                    buttons += '<button type="button" class="btn btn-sm btn-info view-budget" ' +
                              'data-id="' + row.id + '" ' +
                              'title="View Details">' +
                              '<i class="fas fa-eye"></i></button>';

                    // Edit button - shown based on permission
                    if (row.can_edit) {
                        buttons += '<button type="button" class="btn btn-sm btn-warning edit-budget" ' +
                                  'data-id="' + row.id + '" ' +
                                  'title="Edit">' +
                                  '<i class="fas fa-edit"></i></button>';
                    }

                    // Delete button - shown based on permission
                    if (row.can_delete) {
                        buttons += '<button type="button" class="btn btn-sm btn-danger delete-budget" ' +
                                  'data-id="' + row.id + '" ' +
                                  'title="Delete">' +
                                  '<i class="fas fa-trash"></i></button>';
                    }

                    buttons += '</div>';
                    return buttons;
                }
            }
        ],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search budgets...",
            lengthMenu: "_MENU_ budgets per page",
            info: "Showing _START_ to _END_ of _TOTAL_ budgets",
            infoEmpty: "No budgets found",
            infoFiltered: "(filtered from _MAX_ total budgets)",
            zeroRecords: "No matching budgets found"
        },
        drawCallback: function() {
            $('[title]').tooltip();
        }
    });
    
    // Toggle budget amount sections based on period selection
    $('select[name="period"]').change(function() {
        var period = $(this).val();
        $('.budget-amounts').hide();
        
        if (period === 'monthly') {
            $('#monthlyAmounts').show();
        } else if (period === 'quarterly') {
            $('#quarterlyAmounts').show();
        } else if (period === 'yearly') {
            $('#yearlyAmount').show();
        }
    });
    
    $('#edit_period').change(function() {
        var period = $(this).val();
        
        // Hide all amount sections first
        $('.budget-amounts').hide();
        
        // Reset amounts to 0 for the hidden sections
        if (period !== 'monthly') {
            $('.monthly-amount').val('0.00');
        }
        if (period !== 'quarterly') {
            $('.quarterly-amount').val('0.00');
        }
        if (period !== 'yearly') {
            $('#edit_yearly_amount').val('0.00');
        }
        
        // Show the selected period's amount section
        if (period === 'monthly') {
            $('#edit_monthlyAmounts').show();
        } else if (period === 'quarterly') {
            $('#edit_quarterlyAmounts').show();
        } else if (period === 'yearly') {
            $('#edit_yearlyAmount').show();
        }
    });
    
    // Add Budget Form Submit
    $('#addBudgetForm').submit(function(e) {
        e.preventDefault();
        
        $.ajax({
            url: 'actions/save_budget.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    resetBudgetForm('#addBudgetForm');
                    var addBudgetModal = bootstrap.Modal.getInstance(document.getElementById('addBudgetModal'));
                    if (addBudgetModal) addBudgetModal.hide();
                    table.ajax.reload(null, false);
                    showSuccess('Budget saved successfully');
                } else {
                    showError(response.message || 'Error saving budget');
                }
            },
            error: function() {
                showError('An error occurred while saving the budget');
            }
        });
    });
    
    // View Budget Handler
    $(document).on('click', '.view-budget', function() {
        var id = $(this).data('id');
        
        // Debug
        console.log('View budget clicked, ID:', id);
        
        $.ajax({
            url: 'actions/get_budget.php',
            type: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    var budget = response.data;
                    
                    // Set basic details
                    $('#view_fiscal_year').text(budget.fiscal_year_code);
                    $('#view_account').text(budget.account_code + ' - ' + budget.account_name);
                    $('#view_cost_center').text(budget.cost_center_id ? budget.cost_center_code + ' - ' + budget.cost_center_name : 'N/A');
                    $('#view_period').text(budget.period.charAt(0).toUpperCase() + budget.period.slice(1));
                    $('#view_total_amount').text(parseFloat(budget.amount).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }));
                    $('#view_notes').text(budget.notes || 'N/A');
                    
                    // Handle period amounts
                    var periodAmountsHtml = '<h6>Period Amounts:</h6>';
                    if (budget.period === 'yearly') {
                        periodAmountsHtml += '<p>Yearly Amount: ' + parseFloat(budget.amount).toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }) + '</p>';
                    } else {
                        var periods = budget.period === 'monthly' ? 12 : 4;
                        var periodLabel = budget.period === 'monthly' ? 'Month' : 'Quarter';
                        
                        for (var i = 1; i <= periods; i++) {
                            var amountKey = budget.period + '_amount_' + i;
                            var amount = budget[amountKey] || 0;
                            periodAmountsHtml += '<p>' + periodLabel + ' ' + i + ': ' + parseFloat(amount).toLocaleString('en-US', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            }) + '</p>';
                        }
                    }
                    $('#view_period_amounts').html(periodAmountsHtml);
                    
                    // Show modal
                    var viewBudgetModal = new bootstrap.Modal(document.getElementById('viewBudgetModal'));
                    viewBudgetModal.show();
                } else {
                    showError(response.message || 'Error loading budget details');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                showError('Error loading budget details');
            }
        });
    });

    // Edit Budget Handler
    $(document).on('click', '.edit-budget', function() {
        var id = $(this).data('id');
        
        // Debug
        console.log('Edit budget clicked, ID:', id);
        
        $.ajax({
            url: 'actions/get_budget.php',
            type: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    var budget = response.data;
                    
                    // Reset form
                    resetBudgetForm('#editBudgetForm');
                    
                    // Set form values
                    $('#edit_id').val(budget.id);
                    $('#edit_fiscal_year').val(budget.fiscal_year);
                    $('#edit_account_id').val(budget.account_id);
                    $('#edit_cost_center_id').val(budget.cost_center_id || '');
                    $('#edit_period').val(budget.period).trigger('change');
                    $('#edit_notes').val(budget.notes || '');

                    // Reset all amount fields to 0
                    $('.monthly-amount, .quarterly-amount, #edit_yearly_amount').val('0.00');

                    // Hide all amount sections first
                    $('.budget-amounts').hide();

                    // Show and populate the correct amount section based on period
                    if (budget.period === 'yearly') {
                        $('#edit_yearlyAmount').show();
                        $('#edit_yearly_amount').val(parseFloat(budget.amount).toFixed(2));
                    } else if (budget.period === 'monthly' || budget.period === 'quarterly') {
                        var containerSelector = budget.period === 'monthly' ? '#edit_monthlyAmounts' : '#edit_quarterlyAmounts';
                        $(containerSelector).show();
                        
                        // Populate period amounts
                        if (budget.amounts && budget.amounts.length > 0) {
                            budget.amounts.forEach(function(amount) {
                                var fieldSelector = '#edit_' + budget.period + '_amount_' + amount.period_number;
                                $(fieldSelector).val(parseFloat(amount.amount).toFixed(2));
                            });
                        }
                    }

                    // Show modal
                    var editBudgetModal = new bootstrap.Modal(document.getElementById('editBudgetModal'));
                    editBudgetModal.show();
                } else {
                    showError(response.message || 'Error loading budget details');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                showError('Error loading budget details');
            }
        });
    });

    // Edit Budget Form Submit
    $('#editBudgetForm').submit(function(e) {
        e.preventDefault();
        
        // Debug: Log form data
        console.log('Form Data:', $(this).serializeArray());
        
        // Collect form data
        var formData = $(this).serializeArray();
        var period = $('#edit_period').val();
        
        console.log('Selected Period:', period);
        
        // Add any missing period amounts with 0
        if (period === 'monthly') {
            for (var i = 1; i <= 12; i++) {
                var found = false;
                for (var j = 0; j < formData.length; j++) {
                    if (formData[j].name === 'monthly_amount_' + i) {
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    formData.push({
                        name: 'monthly_amount_' + i,
                        value: '0.00'
                    });
                }
            }
        } else if (period === 'quarterly') {
            for (var i = 1; i <= 4; i++) {
                var found = false;
                for (var j = 0; j < formData.length; j++) {
                    if (formData[j].name === 'quarterly_amount_' + i) {
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    formData.push({
                        name: 'quarterly_amount_' + i,
                        value: '0.00'
                    });
                }
            }
        } else if (period === 'yearly' && !formData.find(x => x.name === 'yearly_amount')) {
            formData.push({
                name: 'yearly_amount',
                value: '0.00'
            });
        }

        // Convert to plain object and debug
        var data = {};
        $(formData).each(function(index, item) {
            data[item.name] = item.value;
        });
        console.log('Final Data:', data);
        
        $.ajax({
            url: 'actions/update_budget.php',
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                console.log('Server Response:', response);
                if (response.success) {
                    var editBudgetModal = bootstrap.Modal.getInstance(document.getElementById('editBudgetModal'));
                    if (editBudgetModal) editBudgetModal.hide();
                    table.ajax.reload(null, false);
                    showSuccess('Budget updated successfully');
                } else {
                    showError(response.message || 'Error updating budget');
                }
            },
            error: function(xhr, status, error) {
                console.error('Update error:', error);
                console.error('Status:', status);
                console.error('Response:', xhr.responseText);
                showError('Error updating budget. Please check the console for details.');
            }
        });
    });

    // Delete Budget Handler
    $(document).on('click', '.delete-budget', function() {
        var id = $(this).data('id');
        
        if (confirm('Are you sure you want to delete this budget?')) {
            $.ajax({
                url: 'actions/delete_budget.php',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        table.ajax.reload(null, false);
                        showSuccess('Budget deleted successfully');
                    } else {
                        showError(response.message || 'Error deleting budget');
                    }
                },
                error: function() {
                    showError('An error occurred while deleting the budget');
                }
            });
        }
    });

    // Clear form when modal is hidden
    $('#addBudgetModal').on('hidden.bs.modal', function() {
        resetBudgetForm('#addBudgetForm');
    });

    $('#editBudgetModal').on('hidden.bs.modal', function() {
        resetBudgetForm('#editBudgetForm');
    });

    function formatAmount(amount) {
        return parseFloat(amount).toLocaleString('en-US', {
            style: 'decimal',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    // Helper function to clean up modal elements
    function cleanupModalElements() {
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open');
        $('body').css('overflow', '');
        $('body').css('padding-right', '');
    }
    
    // Add event listeners for modal close events to ensure proper cleanup
    $('.modal').on('hidden.bs.modal', function () {
        cleanupModalElements();
    });
    
    // Handle Escape key press to clean up modals
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('.modal.show').length > 0) {
            setTimeout(cleanupModalElements, 200);
        }
    });
    
    // Add click handler for modal close buttons
    $('[data-bs-dismiss="modal"]').on('click', function() {
        setTimeout(cleanupModalElements, 200);
    });
});
</script>
