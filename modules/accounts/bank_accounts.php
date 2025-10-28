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

$pageTitle = "Bank Accounts";
include '../../includes/header.php'; ?>

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary"><?php echo $pageTitle; ?></h6>
                    <a href="../accounts/chart_of_accounts.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Bank Account from Chart of Accounts
                    </a>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> To create a new bank account, please go to <a href="../accounts/chart_of_accounts.php">Chart of Accounts</a>, create a new account and check "This is a bank account" option to enter bank details.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered" id="bankAccountsTable" width="100%">
                            <thead>
                                <tr>
                                    <th>Account Code</th>
                                    <th>Account Name</th>
                                    <th>Bank Name</th>
                                    <th>Branch Name</th>
                                    <th>Account Number</th>
                                    <th>Current Balance</th>
                                    <th>Status</th>
                                    <th class="text-center">Actions</th>
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

<!-- Edit Bank Account Modal -->
<div class="modal fade" id="editBankAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Bank Account Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editBankAccountForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Code</label>
                            <input type="text" class="form-control" id="edit_account_code" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account Name</label>
                            <input type="text" class="form-control" id="edit_account_name" readonly>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Bank Name *</label>
                            <input type="text" class="form-control" name="bank_name" id="edit_bank_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Branch Name</label>
                            <input type="text" class="form-control" name="branch_name" id="edit_branch_name">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Number *</label>
                            <input type="text" class="form-control" name="account_number" id="edit_account_number" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account Title *</label>
                            <input type="text" class="form-control" name="account_title" id="edit_account_title" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">SWIFT Code</label>
                            <input type="text" class="form-control" name="swift_code" id="edit_swift_code">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IBAN</label>
                            <input type="text" class="form-control" name="iban" id="edit_iban">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Details</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Bank Account Modal -->
<div class="modal fade" id="viewBankAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">View Bank Account Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Account Code</label>
                        <input type="text" class="form-control" id="view_account_code" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Account Name</label>
                        <input type="text" class="form-control" id="view_account_name" readonly>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Bank Name</label>
                        <input type="text" class="form-control" id="view_bank_name" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Branch Name</label>
                        <input type="text" class="form-control" id="view_branch_name" readonly>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Account Number</label>
                        <input type="text" class="form-control" id="view_account_number" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Account Title</label>
                        <input type="text" class="form-control" id="view_account_title" readonly>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">SWIFT Code</label>
                        <input type="text" class="form-control" id="view_swift_code" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">IBAN</label>
                        <input type="text" class="form-control" id="view_iban" readonly>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Current Balance</label>
                        <input type="text" class="form-control" id="view_current_balance" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <input type="text" class="form-control" id="view_status" readonly>
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

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    var table = $('#bankAccountsTable').DataTable({
        processing: true,
        serverSide: false,
        responsive: true,
        ajax: {
            url: 'actions/get_bank_accounts.php',
            method: 'POST',
            dataSrc: function(json) {
                console.log('Received data:', json);
                return json.data || [];
            }
        },
        columns: [
            { data: 'account_code' },
            { data: 'account_name' },
            { data: 'bank_name' },
            { data: 'branch_name' },
            { data: 'account_number' },
            { 
                data: 'current_balance',
                render: function(data, type, row) {
                    return row.currency + ' ' + data;
                }
            },
            { 
                data: 'is_active',
                render: function(data, type, row) {
                    return data == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>';
                }
            },
            {
                data: null,
                orderable: false,
                render: function(data, type, row) {
                    var buttons = '';
                    if (row.can_view) {
                        buttons += '<button class="btn btn-info btn-sm view-account" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button> ';
                    }
                    if (row.can_delete) {
                        buttons += '<button class="btn btn-danger btn-sm delete-account" data-id="' + row.id + '" title="Delete"><i class="fas fa-trash"></i></button>';
                    }
                    return buttons;
                }
            }
        ],
        order: [[0, 'asc']],
        language: {
            emptyTable: 'No bank accounts found',
            zeroRecords: 'No matching bank accounts found'
        }
    });

    // Edit Bank Account
    $(document).on('click', '.edit-account', function() {
        var accountId = $(this).data('id');
        
        // Reset form
        $('#editBankAccountForm')[0].reset();
        $('#edit_id').val(accountId);
        
        // Get account data
        $.ajax({
            url: 'actions/get_bank_account.php',
            type: 'POST',
            data: { id: accountId },
            success: function(response) {
                try {
                    var account = typeof response === 'string' ? JSON.parse(response) : response;
                    
                    // Populate form fields
                    $('#edit_account_code').val(account.account_code);
                    $('#edit_account_name').val(account.account_name);
                    $('#edit_bank_name').val(account.bank_name);
                    $('#edit_branch_name').val(account.branch_name);
                    $('#edit_account_number').val(account.account_number);
                    $('#edit_swift_code').val(account.swift_code);
                    $('#edit_iban').val(account.iban);
                    $('#edit_account_title').val(account.account_title);
                    
                } catch (e) {
                    console.error('JSON parse error:', e);
                    showError('Failed to load account details');
                }
            },
            error: function(xhr, status, error) {
                console.error('Ajax error:', error);
                showError('Failed to load account details');
            }
        });
    });

    // Update Bank Account Form Submission
    $('#editBankAccountForm').on('submit', function(e) {
        e.preventDefault();
        
        var formData = $(this).serializeArray();
        
        $.ajax({
            url: 'actions/update_bank_account.php',
            type: 'POST',
            data: formData,
            success: function(response) {
                try {
                    var result = typeof response === 'string' ? JSON.parse(response) : response;
                    
                    if (result.success) {
                        // Show success message
                        showSuccess(result.message);
                        
                        // Close modal
                        var editBankAccountModal = bootstrap.Modal.getInstance(document.getElementById('editBankAccountModal'));
                        if (editBankAccountModal) editBankAccountModal.hide();
                        
                        // Refresh the DataTable
                        table.ajax.reload();
                    } else {
                        showError(result.message);
                    }
                } catch (e) {
                    console.error('JSON parse error:', e);
                    showError('Invalid server response');
                }
            },
            error: function(xhr, status, error) {
                console.error('Ajax error:', error);
                showError('Failed to update bank account');
            }
        });
    });

    // View Bank Account
    $(document).on('click', '.view-account', function() {
        var accountId = $(this).data('id');
        
        // Get account data
        $.ajax({
            url: 'actions/get_bank_account.php',
            type: 'POST',
            data: { id: accountId },
            success: function(response) {
                console.log('Raw response:', response); // Debug log
                try {
                    var account = typeof response === 'string' ? JSON.parse(response) : response;
                    console.log('Parsed account:', account); // Debug log
                    
                    if (account.success && account.data) {
                        account = account.data; // Get the actual account data
                        
                        // Populate form fields
                        $('#view_account_code').val(account.account_code || '');
                        $('#view_account_name').val(account.account_name || '');
                        $('#view_bank_name').val(account.bank_name || '');
                        $('#view_branch_name').val(account.branch_name || '');
                        $('#view_account_number').val(account.account_number || '');
                        $('#view_account_title').val(account.account_title || '');
                        $('#view_swift_code').val(account.swift_code || '');
                        $('#view_iban').val(account.iban || '');
                        $('#view_current_balance').val((account.currency || 'PKR') + ' ' + (account.current_balance || '0.00'));
                        $('#view_status').val(account.status == 1 ? 'Active' : 'Inactive');
                        
                        // Show modal
                        var viewBankAccountModal = new bootstrap.Modal(document.getElementById('viewBankAccountModal'));
                        viewBankAccountModal.show();
                    } else {
                        showError('Failed to load account details: ' + (account.message || 'Unknown error'));
                    }
                } catch (e) {
                    console.error('JSON parse error:', e);
                    showError('Failed to load account details: Invalid response format');
                }
            },
            error: function(xhr, status, error) {
                console.error('Ajax error:', error);
                showError('Failed to load account details: ' + error);
            }
        });
    });

    // Delete Bank Account
    $(document).on('click', '.delete-account', function() {
        var accountId = $(this).data('id');
        var accountName = $(this).closest('tr').find('td:eq(1)').text(); // Get account name from the row
        
        if (confirm('Are you sure you want to delete bank account "' + accountName + '"?')) {
            $.ajax({
                url: 'actions/delete_bank_account.php',
                type: 'POST',
                data: { id: accountId },
                success: function(response) {
                    try {
                        var result = typeof response === 'string' ? JSON.parse(response) : response;
                        
                        if (result.success) {
                            showSuccess(result.message);
                            table.ajax.reload();
                        } else {
                            showError(result.message || 'Failed to delete bank account');
                        }
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        showError('Failed to delete bank account');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Ajax error:', error);
                    showError('Failed to delete bank account');
                }
            });
        }
    });

    // Show success message
    function showSuccess(message) {
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: message,
            timer: 2000,
            showConfirmButton: false
        });
    }

    // Show error message
    function showError(message) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: message
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
});</script>
