<?php
// Get all chart of accounts that can be bank accounts (assets)
$accounts_query = "
    SELECT 
        coa.id,
        coa.account_code,
        coa.account_name,
        ac.name as category_name
    FROM chart_of_accounts coa
    JOIN account_categories ac ON coa.category_id = ac.id
    WHERE ac.type = 'asset' 
    AND coa.is_group = 0
    AND coa.status = 1
    AND coa.id NOT IN (SELECT account_id FROM bank_accounts)
    ORDER BY coa.account_code";

$stmt = $conn->prepare($accounts_query);
$stmt->execute();
$available_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- New Bank Account Modal -->
<div class="modal fade" id="newBankAccountModal" tabindex="-1" aria-labelledby="newBankAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newBankAccountModalLabel">New Bank Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="newBankAccountForm" method="post" action="actions/save_bank_account.php">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="new_account_id" class="form-label">Account *</label>
                            <select class="form-select" name="account_id" id="new_account_id" required>
                                <option value="">Select Account</option>
                                <?php foreach ($available_accounts as $account): ?>
                                <option value="<?php echo $account['id']; ?>">
                                    <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="new_bank_name" class="form-label">Bank Name *</label>
                            <input type="text" class="form-control" name="bank_name" id="new_bank_name" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="new_branch_name" class="form-label">Branch Name</label>
                            <input type="text" class="form-control" name="branch_name" id="new_branch_name">
                        </div>
                        <div class="col-md-6">
                            <label for="new_account_number" class="form-label">Account Number *</label>
                            <input type="text" class="form-control" name="account_number" id="new_account_number" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="new_iban" class="form-label">IBAN</label>
                            <input type="text" class="form-control" name="iban" id="new_iban">
                        </div>
                        <div class="col-md-6">
                            <label for="new_swift_code" class="form-label">SWIFT Code</label>
                            <input type="text" class="form-control" name="swift_code" id="new_swift_code">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="new_account_title" class="form-label">Account Title *</label>
                            <input type="text" class="form-control" name="account_title" id="new_account_title" required>
                        </div>
                        <div class="col-md-6">
                            <label for="new_opening_balance" class="form-label">Opening Balance</label>
                            <input type="number" class="form-control" name="opening_balance" id="new_opening_balance" step="0.01" value="0.00">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Bank Account Modal -->
<div class="modal fade" id="viewBankAccountModal" tabindex="-1" aria-labelledby="viewBankAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewBankAccountModalLabel">Bank Account Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Account Code:</strong> <span id="view_account_code"></span></p>
                        <p><strong>Account Name:</strong> <span id="view_account_name"></span></p>
                        <p><strong>Bank Name:</strong> <span id="view_bank_name"></span></p>
                        <p><strong>Branch Name:</strong> <span id="view_branch_name"></span></p>
                        <p><strong>Account Number:</strong> <span id="view_account_number"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Account Title:</strong> <span id="view_account_title"></span></p>
                        <p><strong>IBAN:</strong> <span id="view_iban"></span></p>
                        <p><strong>SWIFT Code:</strong> <span id="view_swift_code"></span></p>
                        <p><strong>Opening Balance:</strong> <span id="view_opening_balance"></span></p>
                        <p><strong>Current Balance:</strong> <span id="view_current_balance"></span></p>
                        <p><strong>Last Reconciliation:</strong> <span id="view_last_reconciliation"></span></p>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <p><strong>Created By:</strong> <span id="view_created_by"></span> on <span id="view_created_at"></span></p>
                        <p><strong>Updated By:</strong> <span id="view_updated_by"></span> on <span id="view_updated_at"></span></p>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <h6>Recent Transactions</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered" id="recentTransactionsTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="recent_transactions_body">
                                    <!-- Transactions will be loaded here -->
                                </tbody>
                            </table>
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

<!-- Edit Bank Account Modal -->
<div class="modal fade" id="editBankAccountModal" tabindex="-1" aria-labelledby="editBankAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editBankAccountModalLabel">Edit Bank Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editBankAccountForm" method="post" action="actions/update_bank_account.php">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_account" class="form-label">Account</label>
                            <input type="text" class="form-control" id="edit_account" readonly>
                            <input type="hidden" name="account_id" id="edit_account_id">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_bank_name" class="form-label">Bank Name *</label>
                            <input type="text" class="form-control" name="bank_name" id="edit_bank_name" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_branch_name" class="form-label">Branch Name</label>
                            <input type="text" class="form-control" name="branch_name" id="edit_branch_name">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_account_number" class="form-label">Account Number *</label>
                            <input type="text" class="form-control" name="account_number" id="edit_account_number" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_iban" class="form-label">IBAN</label>
                            <input type="text" class="form-control" name="iban" id="edit_iban">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_swift_code" class="form-label">SWIFT Code</label>
                            <input type="text" class="form-control" name="swift_code" id="edit_swift_code">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_account_title" class="form-label">Account Title *</label>
                            <input type="text" class="form-control" name="account_title" id="edit_account_title" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_status" class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
