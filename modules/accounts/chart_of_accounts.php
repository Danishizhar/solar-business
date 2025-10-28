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

$pageTitle = "Chart of Accounts";
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
                    <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_add'])): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                        <i class="fas fa-plus"></i> Add New Account
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="accountsTable">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Type</th>
                                    <th>Parent Account</th>
                                    <th>Balance Type</th>
                                    <th>Current Balance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Get all accounts with their categories
                                $balance_query = "
                                    SELECT 
                                        coa.*,
                                        ac.name as category_name,
                                        ac.type as category_type,
                                        p.account_name as parent_name,
                                        c.username as created_by_name,
                                        u.username as updated_by_name,
                                        c.created_at,
                                        coa.opening_balance + COALESCE(
                                            (SELECT SUM(
                                                CASE 
                                                    WHEN ac.type IN ('asset', 'expense') THEN 
                                                        COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0)
                                                    ELSE 
                                                        COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0)
                                                END
                                            )
                                            FROM journal_entries je
                                            JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                                            WHERE jed.account_id = coa.id
                                            AND je.status IN ('posted', 'approved')
                                            ), 0
                                        ) as current_balance
                                    FROM chart_of_accounts coa
                                    LEFT JOIN account_categories ac ON coa.category_id = ac.id
                                    LEFT JOIN chart_of_accounts p ON coa.parent_id = p.id
                                    LEFT JOIN users c ON coa.created_by = c.id
                                    LEFT JOIN users u ON coa.updated_by = u.id
                                    WHERE coa.status = 1
                                    ORDER BY coa.account_code";
                                try {
                                    $stmt = $conn->prepare($balance_query);
                                    $stmt->execute();
                                    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    error_log("Error in chart_of_accounts.php: " . $e->getMessage());
                                    echo "<div class='alert alert-danger'>An error occurred while loading accounts. Please try again later.</div>";
                                    $accounts = [];
                                }
                                
                                foreach ($accounts as $account): 
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($account['account_code']); ?></td>
                                    <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                    <td><?php echo htmlspecialchars($account['category_name']); ?></td>
                                    <td><?php echo ucfirst(htmlspecialchars($account['category_type'])); ?></td>
                                    <td><?php echo htmlspecialchars($account['parent_name'] ?? 'None'); ?></td>
                                    <td><?php echo ucfirst(htmlspecialchars($account['balance_type'])); ?></td>
                                    <td class="text-end">
                                        <?php 
                                        $balance = $account['current_balance'];
                                        // For asset/expense accounts, negative means credit balance
                                        // For liability/equity/revenue accounts, negative means debit balance
                                        $is_negative = ($account['category_type'] == 'asset' || $account['category_type'] == 'expense') ? 
                                                     ($balance < 0) : ($balance > 0);
                                        $class = $is_negative ? 'text-danger' : 'text-success';
                                        $formatted_balance = formatNumber($conn, abs($balance));
                                        $balance_text = $is_negative ? "(" . $formatted_balance . ")" : $formatted_balance;
                                        echo "<span class='$class'>" . $balance_text . "</span>";
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($account['status']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_edit'])): ?>
                                        <button type="button" class="btn btn-sm btn-primary edit-account" 
                                                data-id="<?php echo $account['id']; ?>"
                                                data-bs-toggle="modal" data-bs-target="#editAccountModal">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_view'])): ?>
                                        <button type="button" class="btn btn-sm btn-info view-account"
                                                data-id="<?php echo $account['id']; ?>"
                                                data-bs-toggle="modal" data-bs-target="#viewAccountModal">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php endif; ?>

                                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_delete'])): ?>
                                        <button type="button" class="btn btn-sm btn-danger delete-account"
                                                data-id="<?php echo $account['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($account['account_name']); ?>"
                                                data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1" aria-labelledby="addAccountModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addAccountModalLabel">Add New Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addAccountForm">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Code *</label>
                            <input type="text" class="form-control" name="account_code" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account Name *</label>
                            <input type="text" class="form-control" name="account_name" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Category *</label>
                            <select class="form-select" name="category_id" required>
                                <option value="">Select Category</option>
                                <?php 
                                $category_query = "SELECT * FROM account_categories WHERE status = 1 ORDER BY name";
                                $category_stmt = $conn->prepare($category_query);
                                $category_stmt->execute();
                                $categories = $category_stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($categories as $category): 
                                ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Parent Account</label>
                            <select class="form-select" name="parent_id">
                                <option value="">None</option>
                                <?php foreach ($accounts as $account): ?>
                                <option value="<?php echo $account['id']; ?>">
                                    <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Balance Type *</label>
                            <select class="form-select" name="balance_type" required>
                                <option value="debit">Debit</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Opening Balance</label>
                            <input type="number" step="0.01" class="form-control" name="opening_balance" value="0">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_bank_account" id="is_bank_account">
                                <label class="form-check-label" for="is_bank_account">This is a bank account</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bank Account Details -->
                    <div id="bankDetails" class="mt-3" style="display: none;">
                        <h5>Bank Account Details</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="bank_name">Bank Name *</label>
                                    <input type="text" class="form-control" id="bank_name" name="bank_name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="account_number">Account Number *</label>
                                    <input type="text" class="form-control" id="account_number" name="account_number">
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="branch_name">Branch Name (Optional)</label>
                                    <input type="text" class="form-control" id="branch_name" name="branch_name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="swift_code">Swift Code (Optional)</label>
                                    <input type="text" class="form-control" id="swift_code" name="swift_code">
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="iban">IBAN (Optional)</label>
                                    <input type="text" class="form-control" id="iban" name="iban">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Account Modal -->
<div class="modal fade" id="editAccountModal" tabindex="-1" aria-labelledby="editAccountModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editAccountModalLabel">Edit Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editAccountForm" method="post" action="actions/update_account.php">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_account_code">Account Code *</label>
                                <input type="text" class="form-control" id="edit_account_code" name="account_code" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_account_name">Account Name *</label>
                                <input type="text" class="form-control" id="edit_account_name" name="account_name" required>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_category_id">Category *</label>
                                <select class="form-control" id="edit_category_id" name="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo $category['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_parent_id">Parent Account</label>
                                <select class="form-control" id="edit_parent_id" name="parent_id">
                                    <option value="">Select Parent Account</option>
                                    <?php foreach ($accounts as $acc): ?>
                                        <option value="<?php echo $acc['id']; ?>"><?php echo $acc['account_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_balance_type">Balance Type *</label>
                                <select class="form-control" id="edit_balance_type" name="balance_type" required>
                                    <option value="debit">Debit</option>
                                    <option value="credit">Credit</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_opening_balance">Opening Balance *</label>
                                <input type="number" step="0.01" class="form-control" id="edit_opening_balance" name="opening_balance" required>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="edit_description">Description</label>
                                <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_bank_account" id="edit_is_bank_account">
                                <label class="form-check-label" for="edit_is_bank_account">This is a bank account</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bank Account Details -->
                    <div id="editBankDetails" class="mt-3" style="display: none;">
                        <h5>Bank Account Details</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_bank_name">Bank Name *</label>
                                    <input type="text" class="form-control bank-field" id="edit_bank_name" name="bank_name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_account_number">Account Number *</label>
                                    <input type="text" class="form-control bank-field" id="edit_account_number" name="account_number">
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_branch_name">Branch Name (Optional)</label>
                                    <input type="text" class="form-control" id="edit_branch_name" name="branch_name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_swift_code">Swift Code (Optional)</label>
                                    <input type="text" class="form-control" id="edit_swift_code" name="swift_code">
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="edit_iban">IBAN (Optional)</label>
                                    <input type="text" class="form-control" id="edit_iban" name="iban">
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary" form="editAccountForm">Update Account</button>
            </div>
        </div>
    </div>
</div>

<!-- View Account Modal -->
<div class="modal fade" id="viewAccountModal" tabindex="-1" aria-labelledby="viewAccountModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewAccountModalLabel">Account Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Account Code:</strong> <span id="view_account_code"></span></p>
                        <p><strong>Account Name:</strong> <span id="view_account_name"></span></p>
                        <p><strong>Category:</strong> <span id="view_category_name"></span></p>
                        <p><strong>Type:</strong> <span id="view_account_type"></span></p>
                        <p><strong>Parent Account:</strong> <span id="view_parent_name"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Balance Type:</strong> <span id="view_balance_type"></span></p>
                        <p><strong>Opening Balance:</strong> <span id="view_opening_balance"></span></p>
                        <p><strong>Current Balance:</strong> <span id="view_current_balance"></span></p>
                        <p><strong>Description:</strong> <span id="view_description"></span></p>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <p><strong>Created By:</strong> <span id="view_created_by"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Updated By:</strong> <span id="view_updated_by"></span></p>
                    </div>
                </div>
                <div id="viewBankDetails" class="mt-3" style="display: none;">
                    <h5>Bank Account Details</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Bank Name:</strong> <span id="view_bank_name"></span></p>
                            <p><strong>Account Number:</strong> <span id="view_account_number"></span></p>
                            <p><strong>Branch Name:</strong> <span id="view_branch_name"></span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Swift Code:</strong> <span id="view_swift_code"></span></p>
                            <p><strong>IBAN:</strong> <span id="view_iban"></span></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Account Modal -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="deleteAccountForm">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete the account "<span id="delete_name"></span>"?</p>
                    <p class="text-danger">This action cannot be undone if the account has no transactions.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTable
    var table = $('#accountsTable').DataTable({
        processing: true,
        serverSide: false,
        responsive: true,
        order: [[0, 'asc']], // Sort by account code
        columnDefs: [
            { orderable: false, targets: -1 } // Disable sorting on action column
        ]
    });

    // Show/Hide Bank Details based on Checkbox
    $('#is_bank_account').change(function() {
        if ($(this).is(':checked')) {
            $('#bankDetails').show();
            $('.bank-field').prop('required', true);
        } else {
            $('#bankDetails').hide();
            $('.bank-field').prop('required', false);
        }
    });

    // Show/Hide Bank Details based on Checkbox in Edit Form
    $('#edit_is_bank_account').change(function() {
        if ($(this).is(':checked')) {
            $('#editBankDetails').show();
            $('.bank-field').prop('required', true);
        } else {
            $('#editBankDetails').hide();
            $('.bank-field').prop('required', false);
        }
    });

    // Add Account Form Submission
    $('#addAccountForm').submit(function(e) {
        e.preventDefault();
        
        var formData = $(this).serializeArray();
        
        // Check if bank account is checked
        if ($('#is_bank_account').is(':checked')) {
            // Validate bank details
            var bankName = $('input[name="bank_name"]').val();
            var accountNumber = $('input[name="account_number"]').val();
            
            if (!bankName) {
                showError('Bank Name is required for bank accounts');
                return;
            }
            if (!accountNumber) {
                showError('Account Number is required for bank accounts');
                return;
            }
        }
        
        // Debug
        console.log('Submitting add form with data:', formData);
        
        $.ajax({
            url: 'actions/save_account.php',
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('Server response:', response);
                try {
                    var result = typeof response === 'string' ? JSON.parse(response) : response;
                    
                    if (result.success) {
                        // Show success message
                        showSuccess(result.message);
                        
                        // Close modal and reset form
                        var addAccountModal = bootstrap.Modal.getInstance(document.getElementById('addAccountModal'));
                        if (addAccountModal) {
                            addAccountModal.hide();
                            // Give time for modal to hide then remove backdrop and reset body styles
                            setTimeout(function() {
                                $('.modal-backdrop').remove();
                                $('body').removeClass('modal-open');
                                $('body').css('overflow', '');
                                $('body').css('padding-right', '');
                            }, 200);
                        }
                        $('#addAccountForm')[0].reset();
                        $('#bankDetails').hide();
                        
                        // Reload the page to refresh the table
                        location.reload();
                    } else {
                        // Show error message
                        showError(result.message);
                    }
                } catch (e) {
                    console.error('JSON parse error:', e);
                    showError('Invalid server response. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('Ajax error:', error);
                showError('An error occurred while saving the account. Please try again.');
            }
        });
    });

    // Handle Edit Account Click
    $(document).on('click', '.edit-account', function() {
        var accountId = $(this).data('id');
        console.log('Edit account clicked, ID:', accountId);
        
        // Reset form
        $('#editAccountForm')[0].reset();
        
        // Get account data via AJAX
        $.ajax({
            url: 'actions/get_account.php',
            type: 'POST',
            data: { account_id: accountId },
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    var account = response.data;
                    
                    // Set form values
                    $('#edit_id').val(account.id);
                    $('#edit_account_code').val(account.account_code);
                    $('#edit_account_name').val(account.account_name);
                    $('#edit_category_id').val(account.category_id);
                    $('#edit_parent_id').val(account.parent_id || '');
                    $('#edit_description').val(account.description || '');
                    $('#edit_balance_type').val(account.balance_type);
                    $('#edit_opening_balance').val(parseFloat(account.opening_balance).toFixed(2));
                    $('#edit_is_bank_account').prop('checked', account.is_bank_account == 1);

                    // Handle bank account details
                    if (account.is_bank_account == 1) {
                        $('#editBankDetails').show();
                        // Load bank details
                        $.ajax({
                            url: 'actions/get_bank_details.php',
                            type: 'POST',
                            data: { account_id: accountId },
                            dataType: 'json',
                            success: function(bankResponse) {
                                if (bankResponse.success) {
                                    var bankDetails = bankResponse.data;
                                    $('#edit_bank_name').val(bankDetails.bank_name || '');
                                    $('#edit_account_number').val(bankDetails.account_number || '');
                                    $('#edit_branch_name').val(bankDetails.branch_name || '');
                                    $('#edit_swift_code').val(bankDetails.swift_code || '');
                                    $('#edit_iban').val(bankDetails.iban || '');
                                } else {
                                    console.error('Error loading bank details:', bankResponse.message);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Error:', error);
                            }
                        });
                    } else {
                        $('#editBankDetails').hide();
                    }

                    // Show modal
                    // Clean up any existing modal elements first
                    $('.modal-backdrop').remove();
                    $('body').removeClass('modal-open');
                    $('body').css('overflow', '');
                    $('body').css('padding-right', '');
                    
                    // Then show the modal
                    var editAccountModal = new bootstrap.Modal(document.getElementById('editAccountModal'));
                    editAccountModal.show();
                } else {
                    alert(response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                alert('Error loading account details');
            }
        });
    });

    // Handle View Account Click
    $(document).on('click', '.view-account', function() {
        var accountId = $(this).data('id');
        $.ajax({
            url: 'actions/get_account.php',
            type: 'POST',
            data: { account_id: accountId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var account = response.data;
                    
                    // Set basic account information
                    $('#view_account_code').text(account.account_code);
                    $('#view_account_name').text(account.account_name);
                    $('#view_category_name').text(account.category_name);
                    $('#view_account_type').text(account.account_type);
                    $('#view_parent_name').text(account.parent_name || 'None');
                    $('#view_balance_type').text(account.balance_type);
                    $('#view_opening_balance').text(parseFloat(account.opening_balance).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }));
                    $('#view_current_balance').text(parseFloat(account.current_balance).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }));
                    $('#view_description').text(account.description || 'N/A');
                    $('#view_created_by').text(account.created_by + ' on ' + account.created_at);
                    $('#view_updated_by').text(account.updated_by ? account.updated_by + ' on ' + account.updated_at : 'Not updated');

                    // Handle bank account details
                    if (account.is_bank_account == 1) {
                        $('#viewBankDetails').show();
                        // Load bank details
                        $.ajax({
                            url: 'actions/get_bank_details.php',
                            type: 'POST',
                            data: { account_id: accountId },
                            dataType: 'json',
                            success: function(bankResponse) {
                                if (bankResponse.success) {
                                    var bankDetails = bankResponse.data;
                                    $('#view_bank_name').text(bankDetails.bank_name || 'N/A');
                                    $('#view_account_number').text(bankDetails.account_number || 'N/A');
                                    $('#view_branch_name').text(bankDetails.branch_name || 'N/A');
                                    $('#view_swift_code').text(bankDetails.swift_code || 'N/A');
                                    $('#view_iban').text(bankDetails.iban || 'N/A');
                                } else {
                                    console.error('Error loading bank details:', bankResponse.message);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Error:', error);
                            }
                        });
                    } else {
                        $('#viewBankDetails').hide();
                    }

                    // Clean up any existing modal elements first
                    $('.modal-backdrop').remove();
                    $('body').removeClass('modal-open');
                    $('body').css('overflow', '');
                    $('body').css('padding-right', '');
                    
                    // Then show the modal
                    var viewAccountModal = new bootstrap.Modal(document.getElementById('viewAccountModal'));
                    viewAccountModal.show();
                } else {
                    alert(response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                alert('Error loading account details');
            }
        });
    });

    // Handle Delete Account Click
    $(document).on('click', '.delete-account', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        
        // Debug
        console.log('Delete account clicked, ID:', id, 'Name:', name);
        
        // Set modal content
        $('#delete_id').val(id);
        $('#delete_name').text(name);
        
        // Show modal
        // Clean up any existing modal elements first
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open');
        $('body').css('overflow', '');
        $('body').css('padding-right', '');
        
        // Then show the modal
        var deleteAccountModal = new bootstrap.Modal(document.getElementById('deleteAccountModal'));
        deleteAccountModal.show();
    });

    // Handle Delete Account Form Submit
    $('#deleteAccountForm').submit(function(e) {
        e.preventDefault();
        
        var formData = $(this).serializeArray();
        
        // Debug
        console.log('Submitting delete form with data:', formData);
        
        $.ajax({
            url: 'actions/update_account_status.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    // Hide modal
                    var deleteAccountModal = bootstrap.Modal.getInstance(document.getElementById('deleteAccountModal'));
                    if (deleteAccountModal) {
                        deleteAccountModal.hide();
                        // Give time for modal to hide then remove backdrop and reset body styles
                        setTimeout(function() {
                            $('.modal-backdrop').remove();
                            $('body').removeClass('modal-open');
                            $('body').css('overflow', '');
                            $('body').css('padding-right', '');
                        }, 200);
                    }
                    
                    // Show success message
                    showSuccess('Account deleted successfully');
                    
                    // Reload the page after a short delay
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    // Show error message
                    showError(response.message || 'Error deleting account');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                showError('Error deleting account. Please try again.');
            }
        });
    });

    // Handle Edit Account Form Submit
    $('#editAccountForm').on('submit', function(e) {
        e.preventDefault();
        console.log('Submitting edit form');
        
        // Get form data
        var formData = {};
        $(this).serializeArray().forEach(function(item) {
            formData[item.name] = item.value;
        });
        
        // Handle checkboxes properly
        formData.is_bank_account = $('#edit_is_bank_account').is(':checked') ? 1 : 0;
        
        console.log('Submitting edit form with data:', formData);
        
        $.ajax({
            url: 'actions/update_account.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    alert(response.message);
                    var editAccountModal = bootstrap.Modal.getInstance(document.getElementById('editAccountModal'));
                    if (editAccountModal) {
                        editAccountModal.hide();
                        // Give time for modal to hide then remove backdrop and reset body styles
                        setTimeout(function() {
                            $('.modal-backdrop').remove();
                            $('body').removeClass('modal-open');
                            $('body').css('overflow', '');
                            $('body').css('padding-right', '');
                        }, 200);
                    }
                    location.reload(); // Refresh to show updated data
                } else {
                    alert(response.message || 'Error updating account');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                alert('Error updating account: ' + error);
            }
        });
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
    function showError(message) {
        $('#errorMessage').text(message);
        $('#errorAlert').addClass('show').show();
        setTimeout(function() {
            $('#errorAlert').removeClass('show').hide();
        }, 3000);
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
