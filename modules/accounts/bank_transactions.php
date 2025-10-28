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

// Get all active financial years
try {
    $financial_years_query = "SELECT 
        fy.*,
        c.username as created_by_name,
        u.username as updated_by_name,
        cl.username as closed_by_name
    FROM financial_years fy
    LEFT JOIN users c ON fy.created_by = c.id
    LEFT JOIN users u ON fy.updated_by = u.id
    LEFT JOIN users cl ON fy.closed_by = cl.id
    WHERE fy.status = 1
    ORDER BY fy.start_date DESC";
    
    $financial_years_stmt = $conn->prepare($financial_years_query);
    $financial_years_stmt->execute();
    $financial_years = $financial_years_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get current financial year
    $current_date = date('Y-m-d');
    $current_fy_id = null;
    
    foreach ($financial_years as $fy) {
        if ($current_date >= $fy['start_date'] && $current_date <= $fy['end_date']) {
            $current_fy_id = $fy['id'];
            break;
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching financial years: " . $e->getMessage());
    $financial_years = [];
    $current_fy_id = null;
}

$pageTitle = "Bank Transactions";
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
                        <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#depositModal">
                            <i class="fas fa-plus"></i> Deposit
                        </button>
                        <button type="button" class="btn btn-danger me-2" data-bs-toggle="modal" data-bs-target="#withdrawalModal">
                            <i class="fas fa-minus"></i> Withdrawal
                        </button>
                        <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#transferModal">
                            <i class="fas fa-exchange-alt"></i> Transfer
                        </button>
                        <?php endif; ?>
                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_edit'])): ?>
                        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#reconcileModal">
                            <i class="fas fa-check-double"></i> Reconcile
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <form method="get" id="accountSelectForm">
                                <div class="input-group">
                                    <label class="input-group-text" for="account_id">Bank Account</label>
                                    <select class="form-select" id="account_id" name="account_id">
                                        <?php 
                                        try {
                                            // Get all bank accounts
                                            $accounts_query = "
                                                SELECT 
                                                    ba.*,
                                                    coa.account_code,
                                                    coa.account_name
                                                FROM bank_accounts ba
                                                JOIN chart_of_accounts coa ON ba.account_id = coa.id
                                                WHERE ba.status = 1
                                                ORDER BY coa.account_code";

                                            $stmt = $conn->prepare($accounts_query);
                                            $stmt->execute();
                                            $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                            // Get selected account ID from query string or default to first account
                                            $selected_account_id = isset($_GET['account_id']) ? $_GET['account_id'] : ($bank_accounts[0]['account_id'] ?? 0);

                                            foreach ($bank_accounts as $account): 
                                                $account_label = htmlspecialchars($account['account_code'] . ' - ' . $account['account_name'] . ' (' . $account['bank_name'] . ')');
                                                $selected = $account['account_id'] == $selected_account_id ? 'selected' : '';
                                                echo "<option value='" . $account['account_id'] . "' $selected>" . $account_label . "</option>";
                                            endforeach;
                                        } catch (PDOException $e) {
                                            error_log("Error fetching bank accounts: " . $e->getMessage());
                                            $bank_accounts = [];
                                            $selected_account_id = 0;
                                        }
                                        ?>
                                    </select>
                                    <button class="btn btn-outline-secondary" type="submit">View</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-md-6 text-end">
                            <?php 
                            // Get selected bank account details
                            $selected_account = null;
                            if (!empty($bank_accounts) && $selected_account_id) {
                                $selected_account = array_filter($bank_accounts, function($account) use ($selected_account_id) {
                                    return $account['account_id'] == $selected_account_id;
                                });
                                $selected_account = reset($selected_account);
                            }
                            
                            if ($selected_account):
                                // Get current balance
                                $balance_query = "
                                    SELECT 
                                        coa.account_code,
                                        coa.account_name,
                                        ac.type as account_type,
                                        coa.opening_balance + 
                                        CASE 
                                            WHEN ac.type IN ('asset', 'expense') THEN 
                                                COALESCE((
                                                    SELECT SUM(COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0))
                                                    FROM journal_entry_details jed
                                                    JOIN journal_entries je ON je.id = jed.journal_entry_id
                                                    WHERE jed.account_id = coa.id
                                                    AND je.status = 'approved'
                                                ), 0)
                                            ELSE 
                                                COALESCE((
                                                    SELECT SUM(COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0))
                                                    FROM journal_entry_details jed
                                                    JOIN journal_entries je ON je.id = jed.journal_entry_id
                                                    WHERE jed.account_id = coa.id
                                                    AND je.status = 'approved'
                                                ), 0)
                                        END as current_balance
                                    FROM chart_of_accounts coa
                                    JOIN account_categories ac ON coa.category_id = ac.id
                                    WHERE coa.id = ?
                                    AND coa.status = 1";
                                
                                $balance_stmt = $conn->prepare($balance_query);
                                $balance_stmt->bindValue(1, $selected_account['account_id'], PDO::PARAM_INT);
                                $balance_stmt->execute();
                                $result = $balance_stmt->fetch(PDO::FETCH_ASSOC);
                                $current_balance = $result['current_balance'];
                                $account_type = $result['account_type'];
                                
                                // Format balance with 2 decimal places
                                $is_negative = ($account_type == 'asset' || $account_type == 'expense') ? 
                                             ($current_balance < 0) : ($current_balance > 0);
                                $balance_class = $is_negative ? 'danger' : 'success';
                                $formatted_balance = formatNumber($conn, abs($current_balance));
                            ?>
                            <h5>Current Balance: 
                                <span class="text-<?php echo $balance_class; ?>">
                                    PKR <?php echo ($is_negative ? '(' : '') . $formatted_balance . ($is_negative ? ')' : ''); ?>
                                </span>
                            </h5>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="transactionsTable" width="100%">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Description</th>
                                    <th>Type</th>
                                    <th>Financial Year</th>
                                    <th class="text-end">Deposit</th>
                                    <th class="text-end">Withdrawal</th>
                                    <th class="text-end">Balance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Check if financial_year_id column exists in journal_entries table
                                $column_check = $conn->query("SHOW COLUMNS FROM journal_entries LIKE 'financial_year_id'");
                                $has_financial_year = ($column_check->rowCount() > 0);
                                
                                // Get transactions for selected account
                                $transactions_query = "WITH running_balance AS (";
                                $transactions_query .= "
                                        SELECT 
                                            je.id,
                                            je.entry_date,
                                            je.entry_number,
                                            je.entry_type,
                                            je.reference_number,
                                            je.description,
                                            je.status,";
                                            
                                if ($has_financial_year) {
                                    $transactions_query .= "
                                            je.financial_year_id,
                                            COALESCE(fy.year_code, 'N/A') as financial_year_code,
                                            COALESCE(fy.is_closed, 0) as financial_year_closed,";
                                } else {
                                    $transactions_query .= "
                                            NULL as financial_year_id,
                                            'N/A' as financial_year_code,
                                            0 as financial_year_closed,";
                                }
                                
                                $transactions_query .= "
                                            CASE 
                                                WHEN jed.debit_amount > 0 THEN jed.debit_amount 
                                                ELSE NULL 
                                            END as deposit_amount,
                                            CASE 
                                                WHEN jed.credit_amount > 0 THEN jed.credit_amount 
                                                ELSE NULL 
                                            END as withdrawal_amount,
                                            SUM(
                                                CASE 
                                                    WHEN je.status = 'approved' THEN 
                                                        COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0)
                                                    ELSE 0
                                                END
                                            ) OVER (
                                                ORDER BY je.entry_date ASC, je.id ASC
                                            ) as running_balance,
                                            CASE 
                                                WHEN bri.is_reconciled = 1 THEN 'Reconciled'
                                                ELSE NULL
                                            END as reconciliation_status,
                                            br.statement_date as reconciliation_date
                                        FROM journal_entries je
                                        JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                                        LEFT JOIN bank_reconciliation_items bri ON je.id = bri.journal_entry_id
                                        LEFT JOIN bank_reconciliation br ON bri.reconciliation_id = br.id";
                                        
                                if ($has_financial_year) {
                                    $transactions_query .= "
                                        LEFT JOIN financial_years fy ON je.financial_year_id = fy.id";
                                }
                                
                                $transactions_query .= "
                                        WHERE jed.account_id = :account_id
                                        AND je.status IN ('posted', 'approved')
                                    )
                                    SELECT * FROM running_balance
                                    ORDER BY entry_date DESC, id DESC";

                                $stmt = $conn->prepare($transactions_query);
                                $stmt->bindParam(':account_id', $selected_account['account_id'], PDO::PARAM_INT);
                                $stmt->execute();
                                $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($transactions as $transaction): 
                                    // Format values
                                    $entry_date = date('Y-m-d', strtotime($transaction['entry_date']));
                                    $reference = htmlspecialchars($transaction['reference_number'] ?? $transaction['entry_number']);
                                    $description = htmlspecialchars($transaction['description']);
                                    $deposit = $transaction['deposit_amount'] ? formatNumber($conn, $transaction['deposit_amount']) : '';
                                    $withdrawal = $transaction['withdrawal_amount'] ? formatNumber($conn, $transaction['withdrawal_amount']) : '';
                                    $balance = formatNumber($conn, $transaction['running_balance']);
                                    
                                    // Status badges
                                    $status_badge = '';
                                    if ($transaction['status'] === 'approved') {
                                        $status_badge = '<span class="badge bg-success">Approved</span>';
                                    } else {
                                        $status_badge = '<span class="badge bg-warning">Posted</span>';
                                    }
                                    
                                    if ($transaction['reconciliation_status'] === 'Reconciled') {
                                        $status_badge .= ' <span class="badge bg-info">Reconciled</span>';
                                    }
                                    
                                    // Action buttons
                                    $action_buttons = '';
                                    $action_buttons .= '<button type="button" class="btn btn-info btn-sm view-transaction" data-id="'.$transaction['id'].'" title="View"><i class="fas fa-eye"></i></button>';
                                    
                                    // Show delete button for posted status
                                    if ($transaction['status'] === 'posted') {
                                        $action_buttons .= ' <button type="button" class="btn btn-danger btn-sm delete-transaction" data-id="'.$transaction['id'].'" title="Delete"><i class="fas fa-trash"></i></button>';
                                    }
                                    
                                    // Only show approve button if not approved
                                    if ($transaction['status'] !== 'approved') {
                                        $action_buttons .= ' <button type="button" class="btn btn-success btn-sm approve-transaction" data-id="'.$transaction['id'].'" title="Approve"><i class="fas fa-check"></i></button>';
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo $entry_date; ?></td>
                                        <td><?php echo $reference; ?></td>
                                        <td><?php echo $description; ?></td>
                                        <td><?php echo $transaction['entry_type']; ?></td>
                                        <td>
                                            <?php 
                                            if ($transaction['financial_year_code']) {
                                                echo $transaction['financial_year_code'];
                                                if ($transaction['financial_year_closed'] == 1) {
                                                    echo ' <span class="badge bg-secondary">Closed</span>';
                                                }
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td class="text-end"><?php echo $deposit; ?></td>
                                        <td class="text-end"><?php echo $withdrawal; ?></td>
                                        <td class="text-end"><?php echo $balance; ?></td>
                                        <td><?php echo $status_badge; ?></td>
                                        <td><?php echo $action_buttons; ?></td>
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

<!-- Modal for Deposit -->
<div class="modal fade" id="depositModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="depositModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="depositModalLabel">New Deposit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="depositForm">
                <input type="hidden" name="bank_account" id="deposit_bank_account">
                <input type="hidden" name="id" id="deposit_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Date *</label>
                            <input type="date" class="form-control" name="entry_date" id="withdrawal_entry_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" id="withdrawal_reference_number" placeholder="e.g. CHQ-001">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Financial Year *</label>
                            <select class="form-select" name="financial_year_id" required>
                                <?php
                                // Financial years are already fetched above
                                foreach ($financial_years as $fy) {
                                    $selected = ($fy['id'] == $current_fy_id) ? 'selected' : '';
                                    $date_range = date('d M Y', strtotime($fy['start_date'])) . ' - ' . date('d M Y', strtotime($fy['end_date']));
                                    $label = $fy['year_code'] . ' (' . $date_range . ')';
                                    
                                    // Add closed indicator
                                    if ($fy['is_closed'] == 1) {
                                        $label .= ' [CLOSED]';
                                    }
                                    
                                    echo "<option value='{$fy['id']}' {$selected} data-closed='{$fy['is_closed']}'>{$label}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Amount *</label>
                            <div class="input-group">
                                <span class="input-group-text">PKR</span>
                                <input type="number" class="form-control" name="amount" id="transfer_amount" step="0.01" required min="0.01">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Received From Account *</label>
                            <select class="form-select" name="from_account" required>
                                <option value="">Select Account</option>
                                <?php
                                // Get all accounts except bank accounts
                                $accounts_query = "
                                    SELECT coa.id, coa.account_code, coa.account_name 
                                    FROM chart_of_accounts coa
                                    WHERE coa.status = 1 
                                    AND coa.is_bank_account = 0 
                                    ORDER BY coa.account_code";
                                $accounts_stmt = $conn->prepare($accounts_query);
                                $accounts_stmt->execute();
                                while ($account = $accounts_stmt->fetch(PDO::FETCH_ASSOC)) {
                                    echo '<option value="' . $account['id'] . '">' . $account['account_code'] . ' - ' . $account['account_name'] . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea class="form-control" name="description" id="transfer_description" rows="2" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="transfer_notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Deposit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal for Withdrawal -->
<div class="modal fade" id="withdrawalModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="withdrawalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="withdrawalModalLabel">New Withdrawal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="withdrawalForm">
                <input type="hidden" name="bank_account" id="withdrawal_bank_account">
                <input type="hidden" name="id" id="withdrawal_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Date *</label>
                            <input type="date" class="form-control" name="entry_date" id="withdrawal_entry_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" id="withdrawal_reference_number" placeholder="e.g. CHQ-001">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Financial Year *</label>
                            <select class="form-select" name="financial_year_id" required>
                                <?php
                                // Financial years are already fetched above
                                foreach ($financial_years as $fy) {
                                    $selected = ($fy['id'] == $current_fy_id) ? 'selected' : '';
                                    $date_range = date('d M Y', strtotime($fy['start_date'])) . ' - ' . date('d M Y', strtotime($fy['end_date']));
                                    $label = $fy['year_code'] . ' (' . $date_range . ')';
                                    
                                    // Add closed indicator
                                    if ($fy['is_closed'] == 1) {
                                        $label .= ' [CLOSED]';
                                    }
                                    
                                    echo "<option value='{$fy['id']}' {$selected} data-closed='{$fy['is_closed']}'>{$label}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Amount *</label>
                            <div class="input-group">
                                <span class="input-group-text">PKR</span>
                                <input type="number" class="form-control" name="amount" id="transfer_amount" step="0.01" required min="0.01">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Paid To Account *</label>
                            <select class="form-select" name="to_account" required>
                                <option value="">Select Account</option>
                                <?php
                                // Get all accounts except bank accounts
                                $accounts_query = "
                                    SELECT coa.id, coa.account_code, coa.account_name 
                                    FROM chart_of_accounts coa
                                    WHERE coa.status = 1 
                                    AND coa.is_bank_account = 0 
                                    ORDER BY coa.account_code";
                                $accounts_stmt = $conn->prepare($accounts_query);
                                $accounts_stmt->execute();
                                while ($account = $accounts_stmt->fetch(PDO::FETCH_ASSOC)) {
                                    echo '<option value="' . $account['id'] . '">' . $account['account_code'] . ' - ' . $account['account_name'] . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea class="form-control" name="description" id="transfer_description" rows="2" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="transfer_notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Withdrawal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal for Payment -->
<div class="modal fade" id="paymentModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentModalLabel">New Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="paymentForm">
                <div class="modal-body">
                    <!-- Form content here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal for Transfer -->
<div class="modal fade" id="transferModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="transferModalLabel">New Transfer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="transferForm">
                <input type="hidden" name="from_bank_account" id="transfer_from_bank_account">
                <input type="hidden" name="id" id="transfer_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Date *</label>
                            <input type="date" class="form-control" name="entry_date" id="deposit_entry_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" id="transfer_reference_number" placeholder="e.g. TRF-001">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Financial Year *</label>
                            <select class="form-select" name="financial_year_id" required>
                                <?php
                                // Financial years are already fetched above
                                foreach ($financial_years as $fy) {
                                    $selected = ($fy['id'] == $current_fy_id) ? 'selected' : '';
                                    $date_range = date('d M Y', strtotime($fy['start_date'])) . ' - ' . date('d M Y', strtotime($fy['end_date']));
                                    $label = $fy['year_code'] . ' (' . $date_range . ')';
                                    
                                    // Add closed indicator
                                    if ($fy['is_closed'] == 1) {
                                        $label .= ' [CLOSED]';
                                    }
                                    
                                    echo "<option value='{$fy['id']}' {$selected} data-closed='{$fy['is_closed']}'>{$label}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Amount *</label>
                            <div class="input-group">
                                <span class="input-group-text">PKR</span>
                                <input type="number" class="form-control" name="amount" id="transfer_amount" step="0.01" required min="0.01">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">To Bank Account *</label>
                            <select class="form-select" name="to_bank_account" required>
                                <option value="">Select Account</option>
                                <?php
                                // Get all bank accounts except current one
                                $accounts_query = "
                                    SELECT ba.account_id, coa.account_code, coa.account_name, ba.bank_name 
                                    FROM bank_accounts ba
                                    JOIN chart_of_accounts coa ON ba.account_id = coa.id
                                    WHERE ba.status = 1 
                                    ORDER BY coa.account_code";
                                $accounts_stmt = $conn->prepare($accounts_query);
                                $accounts_stmt->execute();
                                while ($account = $accounts_stmt->fetch(PDO::FETCH_ASSOC)) {
                                    echo '<option value="' . $account['account_id'] . '">' . $account['account_code'] . ' - ' . $account['account_name'] . ' (' . $account['bank_name'] . ')</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea class="form-control" name="description" id="transfer_description" rows="2" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="transfer_notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal for Reconciliation -->
<div class="modal fade" id="reconcileModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="reconcileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reconcileModalLabel">Bank Reconciliation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="reconcileForm">
                    <input type="hidden" name="bank_account" id="reconcile_bank_account">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Statement Date *</label>
                            <input type="date" class="form-control" name="statement_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Statement Balance *</label>
                            <div class="input-group">
                                <span class="input-group-text">PKR</span>
                                <input type="number" class="form-control" name="statement_balance" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Statement Reference</label>
                            <input type="text" class="form-control" name="statement_reference" placeholder="e.g. March 2025">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Adjustment Account (Optional)</label>
                            <select class="form-select" name="adjustment_account" id="adjustment_account">
                                <option value="">-- Use Default Variance Account --</option>
                                <?php
                                try {
                                    // Get expense accounts for adjustment
                                    $accounts_query = "SELECT id, account_code, account_name FROM chart_of_accounts 
                                        WHERE (account_code LIKE '6%' OR account_code LIKE '7%') 
                                        AND status = 1 ORDER BY account_code";
                                    $stmt = $conn->prepare($accounts_query);
                                    $stmt->execute();
                                    $expense_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    foreach ($expense_accounts as $account) {
                                        echo "<option value='{$account['id']}'>{$account['account_code']} - {$account['account_name']}</option>";
                                    }
                                } catch (PDOException $e) {
                                    error_log("Error fetching expense accounts: " . $e->getMessage());
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </form>

                <div class="table-responsive mt-3">
                    <table class="table table-sm table-bordered table-hover" id="reconcileTable">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center">
                                    <input type="checkbox" class="form-check-input" id="checkAll">
                                </th>
                                <th>Date</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Financial Year</th>
                                <th class="text-end">Deposit</th>
                                <th class="text-end">Withdrawal</th>
                                <th class="text-end">Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="reconcileTransactions">
                            <!-- Transactions will be loaded here -->
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="6"><strong>Total Selected</strong></td>
                                <td class="text-end" id="totalSelectedDeposits">0.00</td>
                                <td class="text-end" id="totalSelectedWithdrawals">0.00</td>
                                <td class="text-end" id="totalSelectedBalance">0.00</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="8"><strong>Statement Balance</strong></td>
                                <td class="text-end" id="displayStatementBalance">0.00</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="8"><strong>Difference</strong></td>
                                <td class="text-end" id="balanceDifference">0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="saveReconciliation">Save Reconciliation</button>
            </div>
        </div>
    </div>
</div>

<!-- View Transaction Modal -->
<div class="modal fade" id="viewTransactionModal" tabindex="-1" aria-labelledby="viewTransactionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTransactionModalLabel">Transaction Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Content will be loaded dynamically -->
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
    // Initialize DataTable
    var table = $('#transactionsTable').DataTable({
        processing: true,
        serverSide: false,
        order: [[0, 'desc']],
        pageLength: 25,
        columnDefs: [
            { targets: [5, 6, 7], className: 'text-end' },
            { targets: [8, 9], className: 'text-center' }
        ]
    });

    // Handle bank account selection change
    $('#account_id').change(function() {
        $('#accountSelectForm').submit();
    });
    
    // Check if financial year is closed before submitting forms
    function validateFinancialYear(form) {
        var financialYearSelect = form.find('select[name="financial_year_id"]');
        var selectedOption = financialYearSelect.find('option:selected');
        var isClosed = selectedOption.data('closed');
        
        if (isClosed == 1) {
            showError('Cannot create transactions in a closed financial year. Please select an open financial year.');
            return false;
        }
        return true;
    }

    // Set selected bank account when opening deposit modal
    $('#depositModal').on('show.bs.modal', function (e) {
        $('#deposit_bank_account').val($('#account_id').val());
    });
    
    // Handle deposit form submission
    $('#depositForm').submit(function(e) {
        e.preventDefault();
        if (!validateFinancialYear($(this))) return;
        
        // Check if this is an edit (has ID) or a new deposit
        var isEdit = $('#deposit_id').val() !== '';
        var url = isEdit ? 'actions/update_transaction.php' : 'actions/save_bank_deposit.php';
        
        // Continue with form submission
        $.ajax({
            url: url,
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Hide modal
                    $('#depositModal').modal('hide');
                    
                    // Show success message
                    var message = isEdit ? 'Deposit updated successfully' : 'Deposit saved successfully';
                    showSuccess(response.message || message);
                    
                    // Reload page to refresh transactions
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showError(response.message || 'Error processing deposit');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                showError('Server error. Please try again.');
            }
        });
    });

    // Set selected bank account when opening withdrawal modal
    $('#withdrawalModal').on('show.bs.modal', function (e) {
        $('#withdrawal_bank_account').val($('#account_id').val());
    });
    
    // Handle withdrawal form submission
    $('#withdrawalForm').submit(function(e) {
        e.preventDefault();
        if (!validateFinancialYear($(this))) return;
        
        // Check if this is an edit (has ID) or a new withdrawal
        var isEdit = $('#withdrawal_id').val() !== '';
        var url = isEdit ? 'actions/update_transaction.php' : 'actions/save_bank_withdrawal.php';
        
        // Continue with form submission
        $.ajax({
            url: url,
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Hide modal
                    $('#withdrawalModal').modal('hide');
                    
                    // Show success message
                    var message = isEdit ? 'Withdrawal updated successfully' : 'Withdrawal saved successfully';
                    showSuccess(response.message || message);
                    
                    // Reload page to refresh transactions
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showError(response.message || 'Error processing withdrawal');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                showError('Server error. Please try again.');
            }
        });
    });

    // Set selected bank account when opening transfer modal
    $('#transferModal').on('show.bs.modal', function (e) {
        $('#transfer_from_bank_account').val($('#account_id').val());
        
        // Remove current bank account from to_bank_account dropdown
        var currentBankId = $('#account_id').val();
        
    });
    
    // Handle transfer form submission
    $('#transferForm').submit(function(e) {
        e.preventDefault();
        if (!validateFinancialYear($(this))) return;
        
        // Validate that from and to accounts are different
        var fromAccount = $('#transfer_from_bank_account').val();
        var toAccount = $('select[name="to_bank_account"]').val();
        if (fromAccount === toAccount) {
            showError('Cannot transfer to the same account');
            return;
        }
        
        // Check if this is an edit (has ID) or a new transfer
        var isEdit = $('#transfer_id').val() !== '';
        var url = isEdit ? 'actions/update_transaction.php' : 'actions/save_bank_transfer.php';
        
        // Continue with form submission
        $.ajax({
            url: url,
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Hide modal
                    $('#transferModal').modal('hide');
                    
                    // Show success message
                    var message = isEdit ? 'Transfer updated successfully' : 'Transfer saved successfully';
                    showSuccess(response.message || message);
                    
                    // Reload page to refresh transactions
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showError(response.message || 'Error processing transfer');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                showError('Server error. Please try again.');
            }
        });
    });
    
    // Helper function to show error messages
    function showError(message) {
        $('#errorMessage').text(message);
        $('#errorAlert').addClass('show').show();
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $('#errorAlert').removeClass('show').hide();
        }, 5000);
    }
    
    // Helper function to show success messages
    function showSuccess(message) {
        $('#successMessage').text(message);
        $('#successAlert').addClass('show').show();
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $('#successAlert').removeClass('show').hide();
        }, 5000);
    }

    // Add validation for financial year in deposit form
    $('#depositForm').on('change', 'select[name="financial_year_id"]', function() {
        var option = $(this).find('option:selected');
        if (option.data('closed') == 1) {
            showError('Warning: Selected financial year is closed');
        }
    });
    
    // Fix the select options for transfer form
    $('#transferModal').on('show.bs.modal', function() {
        var currentBankId = $('#account_id').val();
        $('select[name="to_bank_account"] option').show();
        $('select[name="to_bank_account"] option[value="' + currentBankId + '"]').hide();
        $('select[name="to_bank_account"]').val('');
    });
    
    // Add validation for financial year in transfer form
    $('#transferForm').on('change', 'select[name="financial_year_id"]', function() {
        var option = $(this).find('option:selected');
        if (option.data('closed') == 1) {
            showError('Warning: Selected financial year is closed');
        }
    });

    // Add validation for financial year in withdrawal form
    $('#withdrawalForm').on('change', 'select[name="financial_year_id"]', function() {
        var option = $(this).find('option:selected');
        if (option.data('closed') == 1) {
            showError('Warning: Selected financial year is closed');
        }
    });

    // Financial year validation function
    function validateFinancialYear(form) {
        var financialYearSelect = form.find('select[name="financial_year_id"]');
        var selectedOption = financialYearSelect.find('option:selected');
        
        if (selectedOption.data('closed') == 1) {
            showError('Cannot create transactions in a closed financial year');
            return false;
        }
        
        return true;
    }

    // View Transaction
    $(document).on('click', '.view-transaction', function() {
        var id = $(this).data('id');
        
        $.ajax({
            url: 'actions/get_transaction_details.php',
            type: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var transaction = response.data;
                    
                    // Build modal content
                    var modalContent = `
                        <div class="table-responsive">
                            <table class="table">
                                <tr>
                                    <th>Entry Number:</th>
                                    <td>${transaction.entry_number}</td>
                                    <th>Date:</th>
                                    <td>${transaction.entry_date}</td>
                                </tr>
                                <tr>
                                    <th>Type:</th>
                                    <td>${transaction.entry_type}</td>
                                    <th>Status:</th>
                                    <td><span class="badge bg-${transaction.status === 'approved' ? 'success' : 'warning'}">${transaction.status}</span></td>
                                </tr>
                                <tr>
                                    <th>Description:</th>
                                    <td colspan="3">${transaction.description}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="table-responsive mt-3">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Account</th>
                                        <th class="text-end">Debit</th>
                                        <th class="text-end">Credit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${transaction.details.map(detail => `
                                        <tr>
                                            <td>${detail.account_name}</td>
                                            <td class="text-end">${detail.debit_amount > 0 ? Number(detail.debit_amount).toFixed(2) : ''}</td>
                                            <td class="text-end">${detail.credit_amount > 0 ? Number(detail.credit_amount).toFixed(2) : ''}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                                <tfoot>
                                    <tr class="fw-bold">
                                        <td>Total</td>
                                        <td class="text-end">${Number(transaction.total_debit).toFixed(2)}</td>
                                        <td class="text-end">${Number(transaction.total_credit).toFixed(2)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    `;
                    
                    // Show modal
                    $('#viewTransactionModal .modal-body').html(modalContent);
                    var viewTransactionModal = new bootstrap.Modal(document.getElementById('viewTransactionModal'));
                    viewTransactionModal.show();
                } else {
                    showError(response.message);
                }
            },
            error: function() {
                showError('Failed to load transaction details');
            }
        });
    });

    // Edit Transaction functionality has been removed
    
    // Delete Transaction
    $(document).on('click', '.delete-transaction', function() {
        var id = $(this).data('id');
        
        if (confirm('Are you sure you want to delete this transaction? This action cannot be undone.')) {
            $.ajax({
                url: 'actions/delete_transaction.php',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showSuccess('Transaction deleted successfully');
                        // Reload the page to refresh balances
                        location.reload();
                    } else {
                        showError(response.message || 'Failed to delete transaction');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    console.error('Response:', xhr.responseText);
                    showError('Failed to delete transaction');
                }
            });
        }
    });
    
    // Approve Transaction
    $(document).on('click', '.approve-transaction', function() {
        var id = $(this).data('id');
        
        if (confirm('Are you sure you want to approve this transaction? This action cannot be undone.')) {
            $.ajax({
                url: 'actions/approve_transaction.php',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showSuccess('Transaction approved successfully');
                        // Reload the page to refresh balances
                        location.reload();
                    } else {
                        showError(response.message || 'Failed to approve transaction');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    console.error('Response:', xhr.responseText);
                    showError('Failed to approve transaction');
                }
            });
        }
    });

    // Handle Reconcile Modal
    $('#reconcileModal').on('show.bs.modal', function (e) {
        $('#reconcile_bank_account').val($('#account_id').val());
        loadReconciliationTransactions();
    });

    // Load transactions for reconciliation
    function loadReconciliationTransactions() {
        var accountId = $('#account_id').val();
        $.ajax({
            url: 'actions/get_reconciliation_transactions.php',
            type: 'POST',
            data: { bank_account: accountId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var html = '';
                    response.data.forEach(function(transaction) {
                        var deposit = parseFloat(transaction.deposit_amount) || 0;
                        var withdrawal = parseFloat(transaction.withdrawal_amount) || 0;
                        var balance = parseFloat(transaction.running_balance) || 0;
                        
                        html += `
                            <tr data-id="${transaction.id}" data-deposit="${deposit}" data-withdrawal="${withdrawal}">
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input transaction-check" 
                                           ${transaction.is_reconciled ? 'checked disabled' : ''}>
                                </td>
                                <td>${transaction.entry_date}</td>
                                <td>${transaction.reference_number || ''}</td>
                                <td>${transaction.description}</td>
                                <td>${transaction.entry_type}</td>
                                <td>
                                    ${transaction.financial_year_code}
                                    ${transaction.financial_year_closed == 1 ? '<span class="badge bg-secondary">Closed</span>' : ''}
                                </td>
                                <td class="text-end">${deposit.toFixed(2)}</td>
                                <td class="text-end">${withdrawal.toFixed(2)}</td>
                                <td class="text-end">${balance.toFixed(2)}</td>
                                <td class="text-center">
                                    <span class="badge ${transaction.status === 'approved' ? 'bg-success' : 'bg-warning'}">
                                        ${transaction.status}
                                    </span>
                                </td>
                            </tr>`;
                    });
                    
                    if (html === '') {
                        html = '<tr><td colspan="10" class="text-center">No transactions found</td></tr>';
                    }
                    $('#reconcileTransactions').html(html);
                    updateReconciliationTotals();
                } else {
                    $('#reconcileTransactions').html('<tr><td colspan="10" class="text-center">Error loading transactions</td></tr>');
                }
            }
        });
    }

    // Check/Uncheck all transactions
    $('#checkAll').on('change', function() {
        $('.transaction-check:not(:disabled)').prop('checked', $(this).prop('checked'));
        updateReconciliationTotals();
    });

    // Update totals when individual transactions are checked/unchecked
    $(document).on('change', '.transaction-check', function() {
        updateReconciliationTotals();
    });

    // Update statement balance display when input changes
    $('input[name="statement_balance"]').on('input', function() {
        updateReconciliationTotals();
    });

    // Calculate and update totals
    function updateReconciliationTotals() {
        var totalDeposits = 0;
        var totalWithdrawals = 0;
        
        $('.transaction-check:checked').each(function() {
            var row = $(this).closest('tr');
            totalDeposits += parseFloat(row.data('deposit')) || 0;
            totalWithdrawals += parseFloat(row.data('withdrawal')) || 0;
        });
        
        var totalBalance = totalDeposits - totalWithdrawals;
        var statementBalance = parseFloat($('input[name="statement_balance"]').val()) || 0;
        var difference = statementBalance - totalBalance;
        
        $('#totalSelectedDeposits').text(totalDeposits.toFixed(2));
        $('#totalSelectedWithdrawals').text(totalWithdrawals.toFixed(2));
        $('#totalSelectedBalance').text(totalBalance.toFixed(2));
        $('#displayStatementBalance').text(statementBalance.toFixed(2));
        $('#balanceDifference').text(difference.toFixed(2));
        
        // Highlight difference if not zero
        $('#balanceDifference').toggleClass('text-danger', Math.abs(difference) > 0.01);
    }

    // Save reconciliation
    $('#saveReconciliation').on('click', function() {
        var form = $('#reconcileForm')[0];
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        var selectedTransactions = [];
        $('.transaction-check:checked:not(:disabled)').each(function() {
            selectedTransactions.push($(this).closest('tr').data('id'));
        });

        if (selectedTransactions.length === 0) {
            showError('Please select at least one transaction to reconcile');
            return;
        }

        var formData = new FormData(form);
        var data = {
            bank_account: formData.get('bank_account'),
            statement_date: formData.get('statement_date'),
            statement_balance: formData.get('statement_balance'),
            statement_reference: formData.get('statement_reference'),
            adjustment_account: formData.get('adjustment_account'),
            transactions: selectedTransactions
        };

        $.ajax({
            url: 'actions/save_reconciliation.php',
            type: 'POST',
            data: JSON.stringify(data),
            contentType: 'application/json',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var reconcileModal = bootstrap.Modal.getInstance(document.getElementById('reconcileModal'));
                    if (reconcileModal) reconcileModal.hide();
                    showSuccess('Reconciliation saved successfully');
                    // Reload the entire page to refresh both tables and balances
                    location.reload();
                } else {
                    showError(response.message || 'Error saving reconciliation');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                
                // Try to parse the response as JSON
                try {
                    var response = JSON.parse(xhr.responseText);
                    showError(response.message || 'Error saving reconciliation. Please try again.');
                } catch (e) {
                    showError('Error saving reconciliation. Please try again.');
                }
            }
        });
    });

    // Helper functions for alerts
    function showSuccess(message) {
        $('#successMessage').text(message);
        $('#successAlert').addClass('show').show();
        setTimeout(function() {
            $('#successAlert').removeClass('show').hide();
        }, 3000);
    }

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
