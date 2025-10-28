<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['payment_add'])) {
    header("Location: ../../index.php");
    exit();
}

// Include database connection
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if invoice ID and type are provided
if (!isset($_GET['invoice_id']) || empty($_GET['invoice_id']) || !isset($_GET['type']) || empty($_GET['type'])) {
    header("Location: payment_list.php?error=" . urlencode("Invoice ID and type are required"));
    exit();
}

$invoice_id = $_GET['invoice_id'];
$type = $_GET['type'];

// Validate type
$valid_types = ['purchase', 'sales'];
if (!in_array($type, $valid_types)) {
    header("Location: payment_list.php?error=" . urlencode("Invalid invoice type"));
    exit();
}

// Get invoice details
try {
    if ($type == 'purchase') {
        // Get invoice details with adjusted amount for returns and credit notes
        $query = "SELECT pi.*, pi.total_amount as original_total, 
                  (pi.total_amount - COALESCE((SELECT SUM(pr.total_amount) FROM purchase_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved'), 0)) as total,
                  s.supplier_name, s.supplier_code, 
                  COALESCE(SUM(pp.amount), 0) as direct_payments,
                  (
                    -- Credit notes from purchase returns
                    SELECT COALESCE(SUM(cn.amount), 0) 
                    FROM purchase_returns pr 
                    JOIN credit_notes cn ON pr.id = cn.return_id 
                    WHERE pr.invoice_id = pi.id AND pr.status = 'approved' AND cn.status IN ('active', 'used', 'partially_used')
                  ) + (
                    -- Supplier refunds adjusted against this invoice
                    SELECT COALESCE(SUM(sr.amount), 0)
                    FROM supplier_refunds sr
                    WHERE sr.invoice_id = pi.id AND sr.status = 'completed'
                  ) as credit_note_adjustments,
                  (SELECT COALESCE(SUM(pr.total_amount), 0) FROM purchase_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved') as returned_amount
                  FROM purchase_invoices pi
                  LEFT JOIN suppliers s ON pi.supplier_id = s.id
                  LEFT JOIN purchase_payments pp ON pi.id = pp.invoice_id AND pp.status = 'completed'
                  WHERE pi.id = :invoice_id
                  GROUP BY pi.id";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':invoice_id', $invoice_id);
        $stmt->execute();
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            header("Location: ../purchase/purchase_invoices.php?error=" . urlencode("Purchase invoice not found"));
            exit();
        }
        
        // Check if invoice is returned - don't allow payments for returned invoices
        if ($invoice['status'] == 'returned') {
            header("Location: ../purchase/purchase_invoices.php?error=" . urlencode("Cannot record payment for returned invoices"));
            exit();
        }
        
        // Make sure we have the total value
        if (!isset($invoice['total']) && isset($invoice['grand_total'])) {
            $invoice['total'] = $invoice['grand_total'];
        }
        
        // Debug values
        error_log("Direct payments: " . $invoice['direct_payments']);
        error_log("Credit note adjustments: " . $invoice['credit_note_adjustments']);
        
        // Calculate total paid amount (direct payments + credit note adjustments)
        $invoice['paid_amount'] = floatval($invoice['direct_payments']) + floatval($invoice['credit_note_adjustments']);
        error_log("Total paid amount: " . $invoice['paid_amount']);
        
        // Calculate remaining amount
        $invoice['remaining_amount'] = (isset($invoice['total']) ? floatval($invoice['total']) : 0) - floatval($invoice['paid_amount']);
        error_log("Remaining amount: " . $invoice['remaining_amount']);
        $redirect_url = "../purchase/purchase_invoices.php";
        $entity_name = $invoice['supplier_name'];
        $invoice_number = $invoice['invoice_number'];
        $account_type = "supplier";
        
    } else { // sales
        $query = "SELECT si.*, c.customer_name, c.customer_code,
                  COALESCE(SUM(sp.amount), 0) as paid_amount
                  FROM sales_invoices si
                  LEFT JOIN customers c ON si.customer_id = c.id
                  LEFT JOIN sales_payments sp ON si.id = sp.invoice_id
                  WHERE si.id = :invoice_id
                  GROUP BY si.id";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':invoice_id', $invoice_id);
        $stmt->execute();
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            header("Location: ../sales/sales_invoices.php?error=" . urlencode("Sales invoice not found"));
            exit();
        }
        
        // Calculate remaining amount
        $invoice['remaining_amount'] = $invoice['total'] - $invoice['paid_amount'];
        $redirect_url = "../sales/sales_invoices.php";
        $entity_name = $invoice['customer_name'];
        $invoice_number = $invoice['invoice_number'];
        $account_type = "customer";
    }
} catch (PDOException $e) {
    header("Location: $redirect_url?error=" . urlencode("Error: " . $e->getMessage()));
    exit();
}

// Get financial years
try {
    $financial_years_query = "SELECT * FROM financial_years WHERE status = 1 AND is_closed = 0 ORDER BY start_date DESC";
    $financial_years_stmt = $conn->prepare($financial_years_query);
    $financial_years_stmt->execute();
    $financial_years = $financial_years_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get current financial year
    $current_date = date('Y-m-d');
    $current_year_id = null;
    
    foreach ($financial_years as $year) {
        if ($current_date >= $year['start_date'] && $current_date <= $year['end_date']) {
            $current_year_id = $year['id'];
            break;
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching financial years: " . $e->getMessage());
    $financial_years = [];
}

// Get bank accounts
try {
    // Based on the database structure you provided, bank accounts are directly linked to chart_of_accounts
    $bank_accounts_query = "SELECT ba.*, coa.account_name as bank_name 
                           FROM bank_accounts ba
                           LEFT JOIN chart_of_accounts coa ON ba.account_id = coa.id
                           WHERE ba.status = 1
                           ORDER BY coa.account_name, ba.account_number";
    $bank_accounts_stmt = $conn->prepare($bank_accounts_query);
    $bank_accounts_stmt->execute();
    $bank_accounts = $bank_accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching bank accounts: " . $e->getMessage());
    $bank_accounts = [];
}

// Get payment methods
$payment_methods = ['Cash', 'Bank Transfer', 'Check', 'Credit Card', 'Online Payment', 'Other'];

// Set page title
$pageTitle = "Record Payment for " . $invoice_number;
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
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-money-bill-wave"></i> Record Payment</h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="<?php echo $redirect_url; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Invoice Details</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="30%">Invoice Number:</th>
                                    <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo ($type == 'purchase') ? 'Supplier:' : 'Customer:'; ?></th>
                                    <td><?php echo htmlspecialchars($entity_name); ?></td>
                                </tr>
                                <tr>
                                    <th>Invoice Date:</th>
                                    <td><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <?php if (isset($invoice['returned_amount']) && $invoice['returned_amount'] > 0): ?>
                                <tr>
                                    <th width="30%">Original Amount:</th>
                                    <td><?php echo formatNumber($conn, $invoice['original_total']); ?></td>
                                </tr>
                                <tr>
                                    <th width="30%">Returned Amount:</th>
                                    <td class="text-danger">(<?php echo formatNumber($conn, $invoice['returned_amount']); ?>)</td>
                                </tr>
                                <tr>
                                    <th width="30%">Adjusted Total:</th>
                                    <td><?php echo formatNumber($conn, $invoice['total']); ?></td>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <th width="30%">Total Amount:</th>
                                    <td><?php echo formatNumber($conn, $invoice['total']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th>Direct Payments:</th>
                                    <td><?php echo formatNumber($conn, $invoice['direct_payments']); ?></td>
                                </tr>
                                <tr>
                                    <th>Credit Note Adjustments:</th>
                                    <td><?php echo formatNumber($conn, $invoice['credit_note_adjustments']); ?></td>
                                </tr>
                                <tr>
                                    <th>Total Paid Amount:</th>
                                    <td><?php echo formatNumber($conn, $invoice['paid_amount']); ?></td>
                                </tr>
                                <tr>
                                    <th>Remaining Amount:</th>
                                    <td><strong><?php echo formatNumber($conn, $invoice['remaining_amount']); ?></strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Payment Details</h6>
                </div>
                <div class="card-body">
                    <form id="paymentForm" method="post" action="actions/save_payment.php">
                        <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                        <input type="hidden" name="type" value="<?php echo $type; ?>">
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Financial Year *</label>
                                <select class="form-select" name="financial_year_id" required>
                                    <option value="">Select Financial Year</option>
                                    <?php foreach ($financial_years as $year): ?>
                                    <option value="<?php echo $year['id']; ?>" <?php echo ($year['id'] == $current_year_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['year_code'] . ' (' . date('d M Y', strtotime($year['start_date'])) . ' - ' . date('d M Y', strtotime($year['end_date'])) . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Payment Date *</label>
                                <input type="date" class="form-control" name="payment_date" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Voucher Number</label>
                                <input type="text" class="form-control" name="voucher_number" readonly value="[Auto Generated]" style="background-color: #f8f9fa;">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Payment Method *</label>
                                <select class="form-select" name="payment_method" id="payment_method" required>
                                    <option value="">Select Method</option>
                                    <?php foreach ($payment_methods as $method): ?>
                                    <option value="<?php echo $method; ?>"><?php echo $method; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Bank Account</label>
                                <select class="form-select" name="bank_account_id" id="bank_account_id">
                                    <option value="">Select Bank Account</option>
                                    <?php foreach ($bank_accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>">
                                        <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_title'] . ' (' . $account['account_number'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Amount *</label>
                                <input type="number" class="form-control" name="amount" id="payment_amount" required step="0.01" 
                                       max="<?php echo $invoice['remaining_amount']; ?>" 
                                       value="<?php echo $invoice['remaining_amount']; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="1"></textarea>
                            </div>
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Payment
                                </button>
                                <a href="<?php echo $redirect_url; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Handle payment method change
    $('#payment_method').change(function() {
        var method = $(this).val();
        if (method == 'Cash') {
            $('#bank_account_id').prop('required', false);
            $('#bank_account_id').closest('.col-md-3').hide();
        } else if (method == 'Bank Transfer' || method == 'Check' || method == 'Credit Card' || method == 'Online Payment') {
            $('#bank_account_id').prop('required', true);
            $('#bank_account_id').closest('.col-md-3').show();
        } else {
            $('#bank_account_id').prop('required', false);
            $('#bank_account_id').closest('.col-md-3').show();
        }
    });
    
    // Form validation
    $('#paymentForm').submit(function(e) {
        var amount = parseFloat($('#payment_amount').val());
        var remaining = parseFloat(<?php echo $invoice['remaining_amount']; ?>);
        
        if (amount <= 0) {
            e.preventDefault();
            showErrorMessage('Payment amount must be greater than zero');
            return false;
        }
        
        if (amount > remaining) {
            e.preventDefault();
            showErrorMessage('Payment amount cannot exceed the remaining amount');
            return false;
        }
        
        var method = $('#payment_method').val();
        if ((method == 'Bank Transfer' || method == 'Check' || method == 'Credit Card' || method == 'Online Payment') && 
            $('#bank_account_id').val() == '') {
            e.preventDefault();
            showErrorMessage('Please select a bank account for this payment method');
            return false;
        }
    });
    
    // Helper functions for alerts
    function showSuccessMessage(message) {
        $('#successMessage').text(message);
        $('#successAlert').addClass('show').show();
        setTimeout(function() {
            $('#successAlert').removeClass('show').hide();
        }, 3000);
    }
    
    function showErrorMessage(message) {
        $('#errorMessage').text(message);
        $('#errorAlert').addClass('show').show();
        setTimeout(function() {
            $('#errorAlert').removeClass('show').hide();
        }, 3000);
    }
    
    // Initialize with current payment method
    $('#payment_method').trigger('change');
});
</script>
