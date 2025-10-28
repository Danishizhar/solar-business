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

// Get active financial years for dropdown
try {
    $years_query = "SELECT id, year_code, start_date, end_date, is_closed FROM financial_years WHERE status = 1 ORDER BY start_date DESC";
    $stmt = $conn->prepare($years_query);
    $stmt->execute();
    $financial_years = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get current financial year
    $current_date = date('Y-m-d');
    $current_year_query = "SELECT id, year_code FROM financial_years WHERE status = 1 AND is_closed = 0 AND '$current_date' BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1";
    $stmt = $conn->prepare($current_year_query);
    $stmt->execute();
    $current_year = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_year_id = $current_year ? $current_year['id'] : ($financial_years[0]['id'] ?? null);
} catch (PDOException $e) {
    error_log("Error fetching financial years: " . $e->getMessage());
    $financial_years = [];
    $current_year_id = null;
}

$pageTitle = "Journal Entries";
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <!-- Success Alert -->
        <div class="alert alert-success alert-dismissible fade" role="alert" id="successAlert" style="display: none;">
            <span id="successMessage"></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <!-- Error Alert -->
        <div class="alert alert-danger alert-dismissible fade" role="alert" id="errorAlert" style="display: none;">
            <span id="errorMessage"></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary"><?php echo $pageTitle; ?></h6>
                    <div>
                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_add'])): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEntryModal">
                            <i class="fas fa-plus"></i> Add New Entry
                        </button>
                        <?php endif; ?>
                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_edit'])): ?>
                        <button type="button" class="btn btn-info" id="updateTotalsBtn">
                            <i class="fas fa-sync"></i> Update Totals
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="entriesTable" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th style="display:none;">ID</th>
                                    <th>Entry Number</th>
                                    <th>Financial Year</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Cost Center</th>
                                    <th>Total Debit</th>
                                    <th>Total Credit</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Entry Modal -->
<div class="modal fade" id="addEntryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Journal Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addEntryForm" method="post" action="actions/save_journal_entry.php">
                <div class="modal-body">
                    <!-- Modal Error Alert -->
                    <div class="alert alert-danger alert-dismissible fade" role="alert" id="modalErrorAlert" style="display: none;">
                        <span id="modalErrorMessage"></span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Financial Year *</label>
                            <select class="form-select" name="financial_year_id" id="financial_year_id" required>
                                <option value="">Select Financial Year</option>
                                <?php foreach ($financial_years as $year): 
                                    // Skip closed financial years
                                    if ($year['is_closed'] == 1) continue;
                                ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo ($year['id'] == $current_year_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_code'] . ' (' . date('d M Y', strtotime($year['start_date'])) . ' - ' . date('d M Y', strtotime($year['end_date'])) . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Entry Date *</label>
                            <input type="date" class="form-control" name="entry_date" id="entry_date" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Entry Type *</label>
                            <select class="form-select" name="entry_type" required>
                                <option value="">Select Type</option>
                                <option value="manual">Manual</option>
                                <option value="sales">Sales</option>
                                <option value="purchase">Purchase</option>
                                <option value="payment">Payment</option>
                                <option value="receipt">Receipt</option>
                                <option value="contra">Contra</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cost Center</label>
                            <select class="form-select" name="cost_center_id">
                                <option value="">Select Cost Center</option>
                                <?php 
                                // Get all cost centers
                                try {
                                    $cost_centers_query = "SELECT id, code, name FROM cost_centers WHERE status = 1 ORDER BY code";
                                    $stmt = $conn->prepare($cost_centers_query);
                                    $stmt->execute();
                                    $cost_centers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    error_log("Error fetching cost centers: " . $e->getMessage());
                                    $cost_centers = [];
                                }
                                
                                foreach ($cost_centers as $cost_center): 
                                ?>
                                <option value="<?php echo $cost_center['id']; ?>">
                                    <?php echo htmlspecialchars($cost_center['code'] . ' - ' . $cost_center['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" rows="2" required></textarea>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="entryDetailsTable">
                                    <thead>
                                        <tr>
                                            <th width="30%">Account *</th>
                                            <th width="30%">Description</th>
                                            <th width="15%">Debit</th>
                                            <th width="15%">Credit</th>
                                            <th width="10%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>
                                                <select class="form-select account-select" name="accounts[]" required>
                                                    <option value="">Select Account</option>
                                                    <?php 
                                                    // Get all accounts for the form
                                                    try {
                                                        $accounts_query = "
                                                            SELECT 
                                                                coa.*,
                                                                ac.name as category_name
                                                            FROM chart_of_accounts coa
                                                            LEFT JOIN account_categories ac ON coa.category_id = ac.id
                                                            WHERE coa.status = 1
                                                            ORDER BY coa.account_code";
                                                        $stmt = $conn->prepare($accounts_query);
                                                        $stmt->execute();
                                                        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                                    } catch (PDOException $e) {
                                                        error_log("Error fetching accounts: " . $e->getMessage());
                                                        $accounts = [];
                                                    }
                                                    
                                                    foreach ($accounts as $account): 
                                                    ?>
                                                    <option value="<?php echo $account['id']; ?>">
                                                        <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control" name="line_descriptions[]">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control debit-amount" name="debits[]" step="0.01" value="0.00">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control credit-amount" name="credits[]" step="0.01" value="0.00">
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-danger btn-sm remove-line">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="2">
                                                <button type="button" class="btn btn-success btn-sm" id="addLine">
                                                    <i class="fas fa-plus"></i> Add Line
                                                </button>
                                            </td>
                                            <td>
                                                <strong>Total Debit:</strong> <span id="totalDebit">0.00</span>
                                            </td>
                                            <td>
                                                <strong>Total Credit:</strong> <span id="totalCredit">0.00</span>
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="saveEntry">Save Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Include Edit Entry Modal -->
<?php include 'edit_entry_modal.php'; ?>

<!-- Include View Entry Modal -->
<?php include 'view_entry_modal.php'; ?>

<?php include '../../includes/footer.php'; ?>

<script src="journal_entries.js"></script>
<script src="journal_entry_print.js"></script>
<script>
$(document).ready(function() {
    // Function to calculate totals
    function calculateTotals() {
        var totalDebit = 0;
        var totalCredit = 0;
        
        $('.debit-amount').each(function() {
            totalDebit += parseFloat($(this).val()) || 0;
        });
        
        $('.credit-amount').each(function() {
            totalCredit += parseFloat($(this).val()) || 0;
        });
        
        $('#totalDebit').text(totalDebit.toFixed(2));
        $('#totalCredit').text(totalCredit.toFixed(2));
        
        // Highlight totals if they don't match
        if (Math.abs(totalDebit - totalCredit) > 0.01) {
            $('#totalDebit, #totalCredit').addClass('text-danger');
        } else {
            $('#totalDebit, #totalCredit').removeClass('text-danger');
        }
    }
    
    // Add new line to the journal entry details table
    $('#addLine').click(function() {
        var newRow = `
            <tr>
                <td>
                    <select class="form-select account-select" name="accounts[]" required>
                        <option value="">Select Account</option>
                        ${$('.account-select').first().html().split('<option').slice(1).join('<option')}
                    </select>
                </td>
                <td>
                    <input type="text" class="form-control" name="line_descriptions[]">
                </td>
                <td>
                    <input type="number" class="form-control debit-amount" name="debits[]" step="0.01" value="0.00">
                </td>
                <td>
                    <input type="number" class="form-control credit-amount" name="credits[]" step="0.01" value="0.00">
                </td>
                <td>
                    <button type="button" class="btn btn-danger btn-sm remove-line">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        $('#entryDetailsTable tbody').append(newRow);
        
        // Attach event handlers to new inputs
        attachInputHandlers();
    });
    
    // Remove line from the journal entry details table
    $(document).on('click', '.remove-line', function() {
        // Don't remove if it's the only row
        if ($('#entryDetailsTable tbody tr').length > 1) {
            $(this).closest('tr').remove();
            calculateTotals();
        } else {
            showError('Cannot remove the last line. At least one line is required.');
        }
    });
    
    // Prevent both debit and credit on the same line
    function attachInputHandlers() {
        $('.debit-amount').off('input').on('input', function() {
            var creditInput = $(this).closest('tr').find('.credit-amount');
            if (parseFloat($(this).val()) > 0) {
                creditInput.val('0.00');
            }
            calculateTotals();
        });
        
        $('.credit-amount').off('input').on('input', function() {
            var debitInput = $(this).closest('tr').find('.debit-amount');
            if (parseFloat($(this).val()) > 0) {
                debitInput.val('0.00');
            }
            calculateTotals();
        });
    }
    
    // Initial attachment of input handlers
    attachInputHandlers();
    
    // Calculate totals on page load
    calculateTotals();
    
    // Initialize DataTable
    var table = $('#entriesTable').DataTable({
        processing: true,
        serverSide: false,
        ajax: {
            url: 'actions/get_journal_entries.php',
            type: 'POST',
            dataSrc: function(json) {
                console.log('DataTable received data:', json);
                return json || [];
            }
        },
        columns: [
            { data: 'id', visible: false },
            { data: 'entry_number' },
            { 
                data: 'financial_year_code',
                render: function(data, type, row) {
                    if (row.financial_year_closed == 1) {
                        return data + ' <span class="badge bg-secondary">Closed</span>';
                    }
                    return data;
                }
            },
            { data: 'entry_date' },
            { 
                data: 'entry_type',
                render: function(data) {
                    return data.charAt(0).toUpperCase() + data.slice(1);
                }
            },
            { data: 'description' },
            { 
                data: 'cost_center_name',
                render: function(data) {
                    return data || 'N/A';
                }
            },
            { 
                data: 'total_debit',
                className: 'text-end',
                render: function(data) {
                    return parseFloat(data).toFixed(2);
                }
            },
            { 
                data: 'total_credit',
                className: 'text-end',
                render: function(data) {
                    return parseFloat(data).toFixed(2);
                }
            },
            {
                data: 'status',
                render: function(data) {
                    var statusClass = {
                        'draft': 'bg-warning',
                        'posted': 'bg-info',
                        'approved': 'bg-success',
                        'rejected': 'bg-danger'
                    };
                    return '<span class="badge ' + (statusClass[data] || 'bg-secondary') + '">' + 
                           data.charAt(0).toUpperCase() + data.slice(1) + '</span>';
                }
            },
            {
                data: null,
                render: function(data, type, row) {
                    var buttons = '';
                    
                    // View button - always available
                    buttons += '<button type="button" class="btn btn-sm btn-info view-entry me-1" ' +
                             'data-id="' + data.id + '">' +
                             '<i class="fas fa-eye"></i></button>';
                    
                    // Status-based buttons
                    if (data.status === 'posted') {
                        // Edit button for posted entries that haven't been approved
                        if (!data.is_approved) {
                            buttons += '<button type="button" class="btn btn-sm btn-primary edit-entry me-1" ' +
                                     'data-id="' + data.id + '" data-number="' + data.entry_number + '">' +
                                     '<i class="fas fa-edit"></i></button>';
                        }
                        
                        // Delete button for posted entries
                        if (data.can_delete) {
                            buttons += '<button type="button" class="btn btn-sm btn-danger delete-entry me-1" ' +
                                     'data-id="' + data.id + '" data-number="' + data.entry_number + '">' +
                                     '<i class="fas fa-trash"></i></button>';
                        }
                        // Approve/Reject buttons for posted entries
                        if (data.can_approve) {
                            buttons += '<button type="button" class="btn btn-sm btn-success approve-entry me-1" ' +
                                     'data-id="' + data.id + '" data-number="' + data.entry_number + '">' +
                                     '<i class="fas fa-check"></i></button>';
                        }
                        
                        // Print button for posted entries
                        buttons += '<button type="button" class="btn btn-sm btn-secondary print-entry me-1" ' +
                                 'data-id="' + data.id + '">' +
                                 '<i class="fas fa-print"></i></button>';
                    } else if (data.status === 'approved' || data.status === 'rejected') {
                        // Print button for approved/rejected entries
                        buttons += '<button type="button" class="btn btn-sm btn-secondary print-entry me-1" ' +
                                 'data-id="' + data.id + '">' +
                                 '<i class="fas fa-print"></i></button>';
                    }
                    
                    return buttons;
                }
            }
        ],
        order: [[0, 'desc']],
        dom: '<"row"<"col-sm-12 col-md-6"B><"col-sm-12 col-md-6"f>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            {
                extend: 'collection',
                text: '<i class="fas fa-download"></i> Export',
                buttons: ['copy', 'excel', 'csv', 'pdf', 'print']
            }
        ]
    });

    // Handle Add Entry Form Submit
    $('#addEntryForm').submit(function(e) {
        e.preventDefault();
        
        // Check if debit and credit are equal
        var totalDebit = 0;
        var totalCredit = 0;
        
        $('.debit-amount').each(function() {
            totalDebit += parseFloat($(this).val()) || 0;
        });
        
        $('.credit-amount').each(function() {
            totalCredit += parseFloat($(this).val()) || 0;
        });
        
        // Validate debit and credit balance
        if (Math.abs(totalDebit - totalCredit) > 0.01) {
            showError('Debits and credits must be equal. Current totals: Debit = ' + totalDebit.toFixed(2) + ', Credit = ' + totalCredit.toFixed(2), true, false);
            return false;
        }
        
        // Clear any previous errors
        $('#modalErrorAlert').removeClass('show').hide();
        
        var formData = $(this).serializeArray();
        
        // Debug
        console.log('Submitting add entry form with data:', formData);
        
        $.ajax({
            url: 'actions/save_journal_entry.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    // Clear any errors
                    $('#modalErrorAlert').removeClass('show').hide();
                    
                    // Hide modal
                    var addEntryModal = bootstrap.Modal.getInstance(document.getElementById('addEntryModal'));
                    if (addEntryModal) addEntryModal.hide();
                    
                    // Show success message
                    showSuccess('Journal entry saved successfully');
                    
                    // Reload table
                    table.ajax.reload();
                } else {
                    showError(response.message || 'Error saving journal entry');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                
                try {
                    // Try to parse the error response
                    var response = JSON.parse(xhr.responseText);
                    if (response && response.message) {
                        showError(response.message, true);
                    } else {
                        showError('Error saving journal entry. Please try again.', true);
                    }
                } catch (e) {
                    showError('Error saving journal entry. Please try again.', true);
                }
            }
        });
    });

    // Handle Edit Entry Form Submit
    $('#editEntryForm').submit(function(e) {
        e.preventDefault();
        
        // Check if debit and credit are equal
        var totalDebit = 0;
        var totalCredit = 0;
        
        $('#editEntryForm .debit-amount').each(function() {
            totalDebit += parseFloat($(this).val()) || 0;
        });
        
        $('#editEntryForm .credit-amount').each(function() {
            totalCredit += parseFloat($(this).val()) || 0;
        });
        
        // Validate debit and credit balance
        if (Math.abs(totalDebit - totalCredit) > 0.01) {
            showError('Debits and credits must be equal. Current totals: Debit = ' + totalDebit.toFixed(2) + ', Credit = ' + totalCredit.toFixed(2), false, true);
            return false;
        }
        
        // Clear any previous errors
        $('#editModalErrorAlert').removeClass('show').hide();
        
        var formData = $(this).serializeArray();
        
        // Debug
        console.log('Submitting edit entry form with data:', formData);
        
        $.ajax({
            url: 'actions/update_journal_entry.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    // Clear any errors
                    $('#editModalErrorAlert').removeClass('show').hide();
                    
                    // Hide modal
                    var editEntryModal = bootstrap.Modal.getInstance(document.getElementById('editEntryModal'));
                    if (editEntryModal) editEntryModal.hide();
                    
                    // Show success message
                    showSuccess('Journal entry updated successfully');
                    
                    // Reload table
                    table.ajax.reload();
                } else {
                    showError(response.message || 'Error updating journal entry');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                
                try {
                    // Try to parse the error response
                    var response = JSON.parse(xhr.responseText);
                    if (response && response.message) {
                        showError(response.message, false, true);
                    } else {
                        showError('Error updating journal entry. Please try again.', false, true);
                    }
                } catch (e) {
                    showError('Error updating journal entry. Please try again.', false, true);
                }
            }
        });
    });

    // Handle Delete Entry
    $(document).on('click', '.delete-entry', function() {
        var id = $(this).data('id');
        var number = $(this).data('number');
        
        // Debug
        console.log('Delete entry clicked, ID:', id, 'Number:', number);
        
        if (confirm('Are you sure you want to delete journal entry ' + number + '?')) {
            $.ajax({
                url: 'actions/update_journal_entry_status.php',
                type: 'POST',
                data: { 
                    id: id,
                    action: 'delete'
                },
                dataType: 'json',
                success: function(response) {
                    console.log('Server response:', response);
                    if (response.success) {
                        // Show success message
                        showSuccess('Journal entry deleted successfully');
                        
                        // Reload table
                        table.ajax.reload();
                    } else {
                        showError(response.message || 'Error deleting journal entry');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    console.error('Response:', xhr.responseText);
                    showError('Error deleting journal entry. Please try again.');
                }
            });
        }
    });

    // Handle Approve Entry
    $(document).on('click', '.approve-entry', function() {
        var id = $(this).data('id');
        var number = $(this).data('number');
        
        // Debug
        console.log('Approve entry clicked, ID:', id, 'Number:', number);
        
        if (confirm('Are you sure you want to approve journal entry ' + number + '?')) {
            $.ajax({
                url: 'actions/approve_journal_entry.php',
                type: 'POST',
                data: { 
                    id: id
                },
                dataType: 'json',
                success: function(response) {
                    console.log('Server response:', response);
                    if (response.success) {
                        // Show success message
                        showSuccess(response.message);
                        
                        // Reload table
                        table.ajax.reload();
                    } else {
                        showError(response.message || 'Error approving journal entry');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    console.error('Response:', xhr.responseText);
                    try {
                        // Try to parse the error response
                        var response = JSON.parse(xhr.responseText);
                        if (response && response.message) {
                            showError(response.message);
                        } else {
                            showError('Error approving journal entry. Please try again.');
                        }
                    } catch (e) {
                        showError('Error approving journal entry. Please try again.');
                    }
                }
            });
        }
    });

    // Handle Post Entry
    $(document).on('click', '.post-entry', function() {
        var id = $(this).data('id');
        var number = $(this).data('number');
        
        // Debug
        console.log('Post entry clicked, ID:', id, 'Number:', number);
        
        if (confirm('Are you sure you want to post journal entry ' + number + '?')) {
            $.ajax({
                url: 'actions/post_journal_entry.php',
                type: 'POST',
                data: { 
                    id: id
                },
                dataType: 'json',
                success: function(response) {
                    console.log('Server response:', response);
                    if (response.success) {
                        // Show success message
                        showSuccess(response.message);
                        
                        // Reload table
                        table.ajax.reload();
                    } else {
                        showError(response.message || 'Error posting journal entry');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    console.error('Response:', xhr.responseText);
                    try {
                        // Try to parse the error response
                        var response = JSON.parse(xhr.responseText);
                        if (response && response.message) {
                            showError(response.message);
                        } else {
                            showError('Error posting journal entry. Please try again.');
                        }
                    } catch (e) {
                        showError('Error posting journal entry. Please try again.');
                    }
                }
            });
        }
    });

    // Handle Reject Entry
    $(document).on('click', '.reject-entry', function() {
        var id = $(this).data('id');
        var number = $(this).data('number');
        
        // Debug
        console.log('Reject entry clicked, ID:', id, 'Number:', number);
        
        if (confirm('Are you sure you want to reject journal entry ' + number + '?')) {
            $.ajax({
                url: 'actions/reject_journal_entry.php',
                type: 'POST',
                data: { 
                    id: id
                },
                dataType: 'json',
                success: function(response) {
                    console.log('Server response:', response);
                    if (response.success) {
                        // Show success message
                        showSuccess(response.message);
                        
                        // Reload table
                        table.ajax.reload();
                    } else {
                        showError(response.message || 'Error rejecting journal entry');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    console.error('Response:', xhr.responseText);
                    try {
                        // Try to parse the error response
                        var response = JSON.parse(xhr.responseText);
                        if (response && response.message) {
                            showError(response.message);
                        } else {
                            showError('Error rejecting journal entry. Please try again.');
                        }
                    } catch (e) {
                        showError('Error rejecting journal entry. Please try again.');
                    }
                }
            });
        }
    });
    
    // Handle Post Entry
    $(document).on('click', '.post-entry', function() {
        var id = $(this).data('id');
        var number = $(this).data('number');
        
        // Debug
        console.log('Post entry clicked, ID:', id, 'Number:', number);
        
        if (confirm('Are you sure you want to post journal entry ' + number + '? Once posted, it cannot be edited.')) {
            $.ajax({
                url: 'actions/update_journal_entry_status.php',
                type: 'POST',
                data: { 
                    id: id,
                    action: 'post'
                },
                dataType: 'json',
                success: function(response) {
                    console.log('Server response:', response);
                    if (response.success) {
                        // Show success message
                        showSuccess('Journal entry posted successfully');
                        
                        // Reload table
                        table.ajax.reload();
                    } else {
                        showError(response.message || 'Error posting journal entry');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    console.error('Response:', xhr.responseText);
                    showError('Error posting journal entry. Please try again.');
                }
            });
        }
    });

    // Handle Print Entry
    $(document).on('click', '.print-entry', function() {
        var id = $(this).data('id');
        window.open('reports/journal_entry_print.php?id=' + id, '_blank');
    });

    // Function to show success message
    function showSuccess(message) {
        $('#successMessage').text(message);
        $('#successAlert').addClass('show').show();
        setTimeout(function() {
            $('#successAlert').removeClass('show').hide();
        }, 3000);
    }
    
    // Function to show error message
    function showError(message, isModal = false, isEditModal = false) {
        if (isEditModal) {
            // Show error in edit modal
            $('#editModalErrorMessage').text(message);
            $('#editModalErrorAlert').addClass('show').show();
            // Don't auto-hide modal errors so user can read them
        } else if (isModal) {
            // Show error in add modal
            $('#modalErrorMessage').text(message);
            $('#modalErrorAlert').addClass('show').show();
            // Don't auto-hide modal errors so user can read them
        } else {
            // Show error on main page
            $('#errorMessage').text(message);
            $('#errorAlert').addClass('show').show();
            setTimeout(function() {
                $('#errorAlert').removeClass('show').hide();
            }, 3000);
        }
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
    
    // Handle Update Totals button click
    $('#updateTotalsBtn').on('click', function() {
        // Show loading spinner
        $(this).html('<i class="fas fa-spinner fa-spin"></i> Updating...');
        $(this).prop('disabled', true);
        
        // Call the update_journal_totals.php script
        $.ajax({
            url: 'actions/update_journal_totals.php',
            type: 'POST',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Show success message
                    $('#successMessage').text(response.message);
                    $('#successAlert').addClass('show').show();
                    setTimeout(function() {
                        $('#successAlert').removeClass('show').hide();
                    }, 3000);
                    
                    // Reload the DataTable to show updated totals
                    $('#entriesTable').DataTable().ajax.reload();
                } else {
                    // Show error message
                    $('#errorMessage').text(response.message);
                    $('#errorAlert').addClass('show').show();
                    setTimeout(function() {
                        $('#errorAlert').removeClass('show').hide();
                    }, 3000);
                }
            },
            error: function() {
                // Show error message
                $('#errorMessage').text('An error occurred while updating journal totals');
                $('#errorAlert').addClass('show').show();
                setTimeout(function() {
                    $('#errorAlert').removeClass('show').hide();
                }, 3000);
            },
            complete: function() {
                // Reset button
                $('#updateTotalsBtn').html('<i class="fas fa-sync"></i> Update Totals');
                $('#updateTotalsBtn').prop('disabled', false);
            }
        });
    });
});
</script>
