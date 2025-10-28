<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Include necessary files
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/company_helpers.php';

// Check permissions
if ($_SESSION['role_id'] != 1 && (!isset($_SESSION['permissions']['purchase_payments']) || !in_array('create', $_SESSION['permissions']['purchase_payments']))) {
    header("Location: ../../index.php?error=You don't have permission to access this page");
    exit();
}

// Get invoice ID from URL if available
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
$supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;
$overpayment_amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;

// Initialize database connection
$db = new Database();
$conn = $db->getConnection();

// Get all financial years for selection
$all_financial_years_query = "SELECT * FROM financial_years WHERE status = 1 ORDER BY start_date DESC";
$all_financial_years_stmt = $conn->prepare($all_financial_years_query);
$all_financial_years_stmt->execute();
$all_financial_years = $all_financial_years_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current financial year as default (the one that includes today's date)
$financial_year_query = "SELECT * FROM financial_years WHERE is_closed = 0 AND status = 1 AND CURDATE() BETWEEN start_date AND end_date LIMIT 1";
$financial_year_stmt = $conn->prepare($financial_year_query);
$financial_year_stmt->execute();
$financial_year = $financial_year_stmt->fetch(PDO::FETCH_ASSOC);

// If no current financial year found, get the most recent active one
if (!$financial_year) {
    $financial_year_query = "SELECT * FROM financial_years WHERE is_closed = 0 AND status = 1 ORDER BY start_date DESC LIMIT 1";
    $financial_year_stmt = $conn->prepare($financial_year_query);
    $financial_year_stmt->execute();
    $financial_year = $financial_year_stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$financial_year) {
    header("Location: ../settings/financial_years.php?error=No active financial year found");
    exit();
}

// Get invoice details if invoice_id is provided
$invoice = null;
if ($invoice_id > 0) {
    $invoice_query = "SELECT pi.*, s.supplier_name, s.supplier_code 
                     FROM purchase_invoices pi
                     JOIN suppliers s ON pi.supplier_id = s.id
                     WHERE pi.id = :invoice_id";
    $invoice_stmt = $conn->prepare($invoice_query);
    $invoice_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $invoice_stmt->execute();
    $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($invoice) {
        $supplier_id = $invoice['supplier_id'];
    }
}

// Get supplier details if supplier_id is provided
$supplier = null;
if ($supplier_id > 0) {
    $supplier_query = "SELECT * FROM suppliers WHERE id = :supplier_id";
    $supplier_stmt = $conn->prepare($supplier_query);
    $supplier_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $supplier_stmt->execute();
    $supplier = $supplier_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all suppliers for dropdown
$suppliers_query = "SELECT id, supplier_name, supplier_code FROM suppliers WHERE is_active = 1 ORDER BY supplier_name";
$suppliers_stmt = $conn->prepare($suppliers_query);
$suppliers_stmt->execute();
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment methods
$payment_methods = ['Cash', 'Bank Transfer', 'Cheque', 'Credit Card', 'Online Payment', 'Other'];

// Get bank accounts
try {
    // Based on the database structure, bank accounts are directly linked to chart_of_accounts
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

// Get cash account
try {
    $cash_account_query = "SELECT id FROM chart_of_accounts WHERE account_code = 'CASH' OR account_name LIKE '%cash%' LIMIT 1";
    $cash_account_stmt = $conn->prepare($cash_account_query);
    $cash_account_stmt->execute();
    $cash_account = $cash_account_stmt->fetch(PDO::FETCH_ASSOC);
    $cash_account_id = $cash_account ? $cash_account['id'] : null;
} catch (PDOException $e) {
    error_log("Error fetching cash account: " . $e->getMessage());
    $cash_account_id = null;
}

// Get supplier's other invoices for adjustment option
$other_invoices = [];
if ($supplier_id > 0) {
    $invoices_query = "SELECT pi.id, pi.invoice_number, pi.invoice_date, pi.total_amount, pi.paid_amount, pi.balance_amount
                      FROM purchase_invoices pi
                      WHERE pi.supplier_id = :supplier_id 
                      AND pi.status IN ('approved', 'partially_returned')
                      AND pi.balance_amount > 0
                      ORDER BY pi.invoice_date DESC";
    $invoices_stmt = $conn->prepare($invoices_query);
    $invoices_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $invoices_stmt->execute();
    $other_invoices = $invoices_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Page title
$page_title = "Supplier Refund / Adjustment";

// Include header
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-hand-holding-usd"></i> <?php echo $page_title; ?></h2>
                </div>
                <div class="col-md-6 text-right">
                    <a href="purchase_invoices.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Invoices</a>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Process Supplier Refund or Payment Adjustment</h5>
                </div>
                <div class="card-body">
                    <?php if (isset($_GET['success'])): ?>
                        <div class="alert alert-success">
                            <?php echo htmlspecialchars($_GET['success']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_GET['error'])): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($_GET['error']); ?>
                        </div>
                    <?php endif; ?>
                            
                    <form id="refundForm" action="actions/process_supplier_refund.php" method="post">
                                
                        <?php if ($invoice): ?>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="alert alert-info">
                                        <h5>Source Invoice Details</h5>
                                        <p><strong>Invoice:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                                        <p><strong>Supplier:</strong> <?php echo htmlspecialchars($invoice['supplier_name']); ?> (<?php echo htmlspecialchars($invoice['supplier_code']); ?>)</p>
                                        <p><strong>Total Amount:</strong> <?php echo formatNumber($conn, $invoice['total_amount']); ?></p>
                                        <p><strong>Paid Amount:</strong> <?php echo formatNumber($conn, $invoice['paid_amount']); ?></p>
                                        <p><strong>Status:</strong> <?php echo ucfirst($invoice['status']); ?></p>
                                        <p><strong>Payment Status:</strong> <?php echo ucfirst($invoice['payment_status']); ?></p>
                                        <p><strong>Overpayment Amount:</strong> <?php echo formatNumber($conn, $overpayment_amount); ?></p>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                            <input type="hidden" name="supplier_id" value="<?php echo $supplier_id; ?>">
                        <?php else: ?>
                            <div class="form-group">
                                <label for="supplier_id">Supplier</label>
                                <select name="supplier_id" id="supplier_id" class="form-control select2" required>
                                    <option value="">-- Select Supplier --</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?php echo $sup['id']; ?>" <?php echo ($supplier_id == $sup['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sup['supplier_name']); ?> (<?php echo htmlspecialchars($sup['supplier_code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="supplierOverpaymentInfo" class="alert alert-info mt-3" style="display: none;">
                                <h5><i class="fas fa-info-circle"></i> Supplier Overpayment Information</h5>
                                <div id="overpaymentDetails">
                                    <p><strong>Total Overpaid Amount:</strong> <span id="totalOverpayment">0.00</span></p>
                                </div>
                            </div>
                            
                            <div id="supplierInvoicesContainer" style="<?php echo ($supplier_id > 0) ? '' : 'display: none;'; ?>">
                                <div class="form-group">
                                    <label for="invoice_id">Select Credit Note</label>
                                    <select name="invoice_id" id="invoice_id" class="form-control select2">
                                        <option value="">-- Select Credit Note --</option>
                                        <?php if ($supplier_id > 0): ?>
                                            <?php
                                            // Get only credit notes for this supplier
                                            $all_invoices_query = "SELECT pi.id, pi.invoice_number, pi.invoice_date, pi.total_amount, pi.paid_amount, pi.balance_amount, pi.status, pi.payment_status
                                                                  FROM purchase_invoices pi
                                                                  WHERE pi.supplier_id = :supplier_id 
                                                                  AND pi.status = 'credit_note'
                                                                  ORDER BY pi.invoice_date DESC";
                                            $all_invoices_stmt = $conn->prepare($all_invoices_query);
                                            $all_invoices_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
                                            $all_invoices_stmt->execute();
                                            $all_invoices = $all_invoices_stmt->fetchAll(PDO::FETCH_ASSOC);
                                            
                                            foreach ($all_invoices as $inv):
                                                $status_label = '';
                                                if ($inv['payment_status'] == 'adjusted' || $inv['status'] == 'returned' || $inv['status'] == 'partially_returned') {
                                                    $status_label = ' [' . ucfirst($inv['status']) . ' - ' . ucfirst($inv['payment_status']) . ']';
                                                }
                                            ?>
                                                <option value="<?php echo $inv['id']; ?>" <?php echo ($invoice_id == $inv['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($inv['invoice_number']); ?> - 
                                                    <?php echo date('d M Y', strtotime($inv['invoice_date'])); ?> - 
                                                    <?php echo formatNumber($conn, $inv['total_amount']); ?>
                                                    <?php echo $status_label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                                <?php endif; ?>
                                
                            <div class="form-group">
                                <label for="refund_type">Refund Type</label>
                                <select name="refund_type" id="refund_type" class="form-control" required>
                                    <option value="refund">Refund from Supplier</option>
                                    <option value="adjust">Adjust Against Another Invoice</option>
                                </select>
                            </div>
                            
                            <div id="refundSection">
                                <div class="form-group">
                                    <label for="amount">Refund Amount</label>
                                    <input type="number" step="0.01" name="amount" id="amount" class="form-control" value="<?php echo $overpayment_amount; ?>" required>
                                    <small class="text-muted">Maximum amount: <span id="maxRefundAmount"><?php echo formatNumber($conn, $overpayment_amount); ?></span></small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="refund_date">Refund Date</label>
                                    <input type="date" name="refund_date" id="refund_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="payment_method">Payment Method *</label>
                                    <select name="payment_method" id="payment_method" class="form-control" required>
                                        <option value="">-- Select Payment Method --</option>
                                        <?php foreach ($payment_methods as $method): ?>
                                            <option value="<?php echo $method; ?>"><?php echo $method; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group" id="bank_account_container">
                                    <label for="bank_account_id">Bank Account *</label>
                                    <select class="form-control" name="bank_account_id" id="bank_account_id">
                                        <option value="">Select Bank Account</option>
                                        <?php foreach ($bank_accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>">
                                            <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_title'] . ' (' . $account['account_number'] . ')'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="cash_account_id" id="cash_account_id" value="<?php echo $cash_account_id; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="reference_details">Reference Details</label>
                                    <input type="text" name="reference_details" id="reference_details" class="form-control" placeholder="Cheque number, transaction ID, etc.">
                                </div>
                                
                                <div class="form-group">
                                    <label for="financial_year_id">Financial Year *</label>
                                    <select name="financial_year_id" id="financial_year_id" class="form-control" required>
                                        <option value="">-- Select Financial Year --</option>
                                        <?php foreach ($all_financial_years as $fy): ?>
                                            <option value="<?php echo $fy['id']; ?>" 
                                                <?php echo ($financial_year['id'] == $fy['id']) ? 'selected' : ''; ?>
                                                data-start="<?php echo $fy['start_date']; ?>" 
                                                data-end="<?php echo $fy['end_date']; ?>">
                                                <?php echo htmlspecialchars($fy['year_code'] . ' (' . date('d M Y', strtotime($fy['start_date'])) . ' - ' . date('d M Y', strtotime($fy['end_date'])) . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div id="adjustSection" style="display: none;">
                                <div class="form-group">
                                    <label for="target_invoice_id">Target Invoice for Adjustment</label>
                                    <select name="target_invoice_id" id="target_invoice_id" class="form-control select2">
                                        <option value="">-- Select Invoice --</option>
                                        <?php foreach ($other_invoices as $inv): ?>
                                            <option value="<?php echo $inv['id']; ?>" data-balance="<?php echo $inv['balance_amount']; ?>">
                                                <?php echo htmlspecialchars($inv['invoice_number']); ?> - 
                                                <?php echo date('d M Y', strtotime($inv['invoice_date'])); ?> - 
                                                Balance: <?php echo formatNumber($conn, $inv['balance_amount']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="adjustment_amount">Adjustment Amount</label>
                                    <input type="number" step="0.01" name="adjustment_amount" id="adjustment_amount" class="form-control" value="<?php echo $overpayment_amount; ?>" required>
                                    <small class="text-muted">Maximum amount: <span id="maxAdjustmentAmount"><?php echo formatNumber($conn, $overpayment_amount); ?></span></small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="adjustment_date">Adjustment Date</label>
                                    <input type="date" name="adjustment_date" id="adjustment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="financial_year_id_adjust">Financial Year *</label>
                                    <select name="financial_year_id" id="financial_year_id_adjust" class="form-control" required>
                                        <option value="">-- Select Financial Year --</option>
                                        <?php foreach ($all_financial_years as $fy): ?>
                                            <option value="<?php echo $fy['id']; ?>" 
                                                <?php echo ($financial_year['id'] == $fy['id']) ? 'selected' : ''; ?>
                                                data-start="<?php echo $fy['start_date']; ?>" 
                                                data-end="<?php echo $fy['end_date']; ?>">
                                                <?php echo htmlspecialchars($fy['year_code'] . ' (' . date('d M Y', strtotime($fy['start_date'])) . ' - ' . date('d M Y', strtotime($fy['end_date'])) . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Additional notes or comments"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">Process</button>
                                <a href="purchase_invoices.php" class="btn btn-secondary">Cancel</a>
                            </div>
                            </form>
                        </div>
                        <!-- /.card-body -->
                    </div>
                    <!-- /.card -->
                </div>
                <!-- /.col -->
            </div>
            <!-- /.row -->
        </div>
        <!-- /.container-fluid -->
    </section>
    <!-- /.content -->
</div>

<?php include '../../includes/footer.php'; ?>

<script>
    // Function to format date from MySQL format to readable format
    function formatDate(dateString) {
        // Handle null, undefined, empty string
        if (!dateString || dateString === null || dateString === undefined || dateString === '') {
            return 'No Date';
        }
        
        try {
            // Convert to string if not already
            var dateStr = String(dateString);
            
            // Simple regex check for YYYY-MM-DD format
            var match = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (match) {
                var year = parseInt(match[1]);
                var month = parseInt(match[2]);
                var day = parseInt(match[3]);
                
                // Validate ranges
                if (year >= 1900 && year <= 2100 && month >= 1 && month <= 12 && day >= 1 && day <= 31) {
                    var monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    return day.toString().padStart(2, '0') + ' ' + monthNames[month - 1] + ' ' + year;
                }
            }
            
            // If not MySQL format, just return the original string
            // We'll avoid Date object completely to prevent errors
            console.log('Date not in MySQL format, returning original:', dateStr);
            
            // If all else fails, return original string
            return dateStr;
            
        } catch (error) {
            console.error('Error in formatDate:', error, 'Input:', dateString);
            return String(dateString) || 'Invalid Date';
        }
    }
    
    $(document).ready(function() {
        // Initialize select2
        $('.select2').select2();
        
        // Handle payment method change
        $('#payment_method').change(function() {
            var method = $(this).val();
            if (method == 'Cash') {
                $('#bank_account_id').prop('required', false);
                $('#bank_account_container').hide();
            } else if (method == 'Bank Transfer' || method == 'Cheque' || method == 'Credit Card' || method == 'Online Payment') {
                $('#bank_account_id').prop('required', true);
                $('#bank_account_container').show();
            } else {
                $('#bank_account_id').prop('required', false);
                $('#bank_account_container').show();
            }
        });
        
        // Initialize with current payment method
        $('#payment_method').trigger('change');
        
        // Toggle between refund and adjustment sections
        $('#refund_type').change(function() {
            var selectedType = $(this).val();
            if (selectedType === 'refund') {
                $('#refundSection').show();
                $('#adjustSection').hide();
                
                // Enable required fields for refund
                $('#amount').prop('required', true);
                $('#refund_date').prop('required', true);
                $('#payment_method').prop('required', true);
                $('#financial_year_id').prop('required', true);
                
                // Disable adjustment fields
                $('#target_invoice_id').prop('required', false);
                $('#adjustment_amount').prop('required', false);
                $('#adjustment_date').prop('required', false);
                
            } else if (selectedType === 'adjust') {
                $('#refundSection').hide();
                $('#adjustSection').show();
                
                // Disable required fields for refund
                $('#amount').prop('required', false);
                $('#refund_date').prop('required', false);
                $('#payment_method').prop('required', false);
                
                // Enable adjustment fields
                $('#target_invoice_id').prop('required', true);
                $('#adjustment_amount').prop('required', true);
                $('#adjustment_date').prop('required', true);
                $('#financial_year_id_adjust').prop('required', true);
            }
        });
        
        // Update target invoice dropdown when supplier changes
        $('#supplier_id').change(function() {
            var supplierId = $(this).val();
            if (supplierId) {
                // Get supplier overpayment information
                console.log('Sending AJAX request to get supplier overpayment for ID:', supplierId);
                $.ajax({
                    url: 'actions/get_supplier_overpayment.php',
                    type: 'POST',
                    data: { supplier_id: supplierId },
                    dataType: 'json',
                    success: function(data) {
                        console.log('Received supplier overpayment data:', data);
                        console.log('Detailed data received:', JSON.stringify(data));
                        if (data.success && data.total_overpayment > 0) {
                            $('#totalOverpayment').text(parseFloat(data.total_overpayment).toFixed(2));
                            $('#maxRefundAmount').text(parseFloat(data.total_overpayment).toFixed(2));
                            $('#maxAdjustmentAmount').text(parseFloat(data.total_overpayment).toFixed(2));
                            $('#amount').val(parseFloat(data.total_overpayment).toFixed(2));
                            $('#adjustment_amount').val(parseFloat(data.total_overpayment).toFixed(2));
                            $('#supplierOverpaymentInfo').show();
                            
                            // Add invoice details if available
                            var detailsHtml = '<p><strong>Total Overpaid Amount:</strong> <span id="totalOverpayment">' + 
                                            parseFloat(data.total_overpayment).toFixed(2) + '</span></p>';
                            
                            // Add credit note information if present
                            var hasCreditNotes = false;
                            console.log('Processing invoices array, length:', data.invoices ? data.invoices.length : 0);
                            if (data.invoices && data.invoices.length > 0) {
                                detailsHtml += '<h6 class="mt-3">Sources of Overpayment:</h6><ul>';
                                
                                $.each(data.invoices, function(index, item) {
                                    console.log('Processing item:', item);
                                    if (item.source_type === 'credit_note') {
                                        hasCreditNotes = true;
                                        var originalInvoice = item.original_invoice_number && item.original_invoice_number !== '' ? 
                                            ' (from invoice ' + item.original_invoice_number + ')' : '';
                                        detailsHtml += '<li class="text-success"><strong>Credit Note:</strong> ' + item.invoice_number + originalInvoice + 
                                                    ' - Amount: ' + parseFloat(item.overpaid_amount).toFixed(2) + '</li>';
                                    } else {
                                        detailsHtml += '<li><strong>Invoice:</strong> ' + item.invoice_number + 
                                                    ' - Overpaid: ' + parseFloat(item.overpaid_amount).toFixed(2) + '</li>';
                                    }
                                });
                                
                                detailsHtml += '</ul>';
                            }
                            
                            // Overpayment details are already displayed above
                            
                            $('#overpaymentDetails').html(detailsHtml);
                        } else {
                            $('#supplierInvoicesContainer').hide();
                            $('#supplierOverpaymentInfo').hide();
                            console.log('No overpayment found. Data received:', data);
                            alert('This supplier has no overpayment balance or active credit notes to refund or adjust');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#supplierInvoicesContainer').hide();
                        $('#supplierOverpaymentInfo').hide();
                        console.error('AJAX Error:', status, error);
                        console.log('Response:', xhr.responseText);
                        alert('Error loading supplier overpayment information. Please check console for details.');
                    }
                });
                
                $('#supplierInvoicesContainer').show();
                // Load invoices and credit notes for this supplier via AJAX
                $.ajax({
                    url: 'actions/get_supplier_invoices.php',
                    type: 'POST',
                    data: { supplier_id: supplierId },
                    dataType: 'json',
                    success: function(data) {
                        var invoiceSelect = $('#invoice_id');
                        invoiceSelect.empty();
                        invoiceSelect.append('<option value="">-- Select Invoice --</option>');
                        
                        // Add credit notes first by directly fetching from overpayment data
                        $.ajax({
                            url: 'actions/get_supplier_overpayment.php',
                            type: 'POST',
                            data: { supplier_id: supplierId },
                            dataType: 'json',
                            async: false, // Use synchronous request to ensure credit notes are added before invoices
                            success: function(overpaymentData) {
                                console.log('Adding credit notes to dropdown:', overpaymentData);
                                if (overpaymentData.success && overpaymentData.invoices && overpaymentData.invoices.length > 0) {
                                    // Add an optgroup for credit notes
                                    var creditNoteGroup = $('<optgroup label="Credit Notes"></optgroup>');
                                    var hasCreditNotes = false;
                                    
                                    $.each(overpaymentData.invoices, function(index, item) {
                                        if (item.source_type === 'credit_note') {
                                            hasCreditNotes = true;
                                            var originalInvoice = item.original_invoice_number ? ' (from invoice ' + item.original_invoice_number + ')' : '';
                                            creditNoteGroup.append('<option value="' + item.id + '" data-source-type="credit_note">' + 
                                                               item.invoice_number + originalInvoice + ' - ' + 
                                                               parseFloat(item.overpaid_amount).toFixed(2) + 
                                                               '</option>');
                                        }
                                    });
                                    
                                    if (hasCreditNotes) {
                                        invoiceSelect.append(creditNoteGroup);
                                    }
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Error loading credit notes:', error);
                                console.log('Response:', xhr.responseText);
                            }
                        });
                        
                        // We don't need to add regular invoices to the source dropdown
                        // Only credit notes should be shown as source for refunds
                        
                        // Refresh select2
                        invoiceSelect.trigger('change');
                    },
                    error: function() {
                        alert('Error loading invoices. Please try again.');
                    }
                });
                
                // Also load target invoices for adjustment
                $.ajax({
                    url: 'actions/get_supplier_target_invoices.php',
                    type: 'POST',
                    data: { supplier_id: supplierId },
                    dataType: 'json',
                    success: function(data) {
                        var targetInvoiceSelect = $('#target_invoice_id');
                        targetInvoiceSelect.empty();
                        targetInvoiceSelect.append('<option value="">-- Select Invoice --</option>');
                        
                        console.log('Target invoices data received:', data);
                        $.each(data, function(index, invoice) {
                            console.log('Processing target invoice:', invoice);
                            console.log('Invoice date value:', invoice.invoice_date, 'Type:', typeof invoice.invoice_date);
                            
                            var formattedDate = '';
                            try {
                                formattedDate = formatDate(invoice.invoice_date);
                            } catch (error) {
                                console.error('Error formatting date for invoice:', invoice.invoice_number, 'Date:', invoice.invoice_date, 'Error:', error);
                                formattedDate = invoice.invoice_date || 'Invalid Date';
                            }
                            
                            targetInvoiceSelect.append('<option value="' + invoice.id + '" data-balance="' + invoice.balance_amount + '">' + 
                                                     invoice.invoice_number + ' - ' + 
                                                     formattedDate + ' - ' + 
                                                     'Balance: ' + parseFloat(invoice.balance_amount).toFixed(2) + '</option>');
                        });
                        
                        // Refresh select2
                        targetInvoiceSelect.trigger('change');
                    },
                    error: function() {
                        alert('Error loading target invoices. Please try again.');
                    }
                });
            } else {
                $('#supplierInvoicesContainer').hide();
                $('#supplierOverpaymentInfo').hide();
            }
        });
        
        // Update adjustment amount based on selected invoice
        $('#target_invoice_id').change(function() {
            var selectedOption = $(this).find('option:selected');
            var balanceAmount = parseFloat(selectedOption.data('balance') || 0);
            var overpaymentAmount = parseFloat($('#totalOverpayment').text() || 0);
            
            // Set adjustment amount to the minimum of balance and overpayment
            var adjustmentAmount = Math.min(balanceAmount, overpaymentAmount);
            $('#adjustment_amount').val(adjustmentAmount.toFixed(2));
        });
        
        // Validate refund amount doesn't exceed overpayment
        $('#amount').on('input', function() {
            var enteredAmount = parseFloat($(this).val() || 0);
            var maxAmount = parseFloat($('#totalOverpayment').text() || 0);
            
            if (enteredAmount > maxAmount) {
                alert('Refund amount cannot exceed the total overpayment amount of ' + maxAmount.toFixed(2));
                $(this).val(maxAmount.toFixed(2));
            }
        });
        
        // Validate adjustment amount doesn't exceed overpayment
        $('#adjustment_amount').on('input', function() {
            var enteredAmount = parseFloat($(this).val() || 0);
            var maxAmount = parseFloat($('#totalOverpayment').text() || 0);
            var selectedOption = $('#target_invoice_id').find('option:selected');
            var balanceAmount = parseFloat(selectedOption.data('balance') || 0);
            var maxPossible = Math.min(maxAmount, balanceAmount);
            
            if (enteredAmount > maxPossible) {
                alert('Adjustment amount cannot exceed ' + maxPossible.toFixed(2) + ' (the lesser of overpayment and invoice balance)');
                $(this).val(maxPossible.toFixed(2));
            }
        });
        
        // Form validation before submit
        $('#refundForm').submit(function(e) {
            var refundType = $('#refund_type').val();
            var isValid = true;
            
            // Get the correct financial year field based on refund type
            var financialYearId, selectedFY, fyStartDate, fyEndDate;
            if (refundType === 'adjust') {
                financialYearId = $('#financial_year_id_adjust').val();
                selectedFY = $('#financial_year_id_adjust option:selected');
            } else {
                financialYearId = $('#financial_year_id').val();
                selectedFY = $('#financial_year_id option:selected');
            }
            
            // Validate financial year is selected
            if (!financialYearId) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please select a financial year.'
                });
                isValid = false;
                e.preventDefault();
                return false;
            }
            
            // Get selected financial year date range
            fyStartDate = new Date(selectedFY.data('start'));
            fyEndDate = new Date(selectedFY.data('end'));
            
            if (refundType === 'refund') {
                // Validate refund fields
                var amount = parseFloat($('#amount').val() || 0);
                var paymentMethod = $('#payment_method').val();
                var maxAmount = parseFloat($('#totalOverpayment').text() || 0);
                var refundDate = new Date($('#refund_date').val());
                var creditNoteId = $('#invoice_id').val();
                
                // Validate refund date falls within financial year
                if (refundDate < fyStartDate || refundDate > fyEndDate) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Date Validation Error',
                        text: 'Refund date must be within the selected financial year\'s date range: ' + 
                              formatDateForAlert(fyStartDate) + ' to ' + formatDateForAlert(fyEndDate)
                    });
                    isValid = false;
                } else if (amount <= 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation Error',
                        text: 'Please enter a valid refund amount greater than zero.'
                    });
                    isValid = false;
                } else if (amount > maxAmount) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation Error',
                        text: 'Refund amount cannot exceed the total overpayment amount of ' + maxAmount.toFixed(2)
                    });
                    isValid = false;
                } else if (!paymentMethod) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation Error',
                        text: 'Please select a payment method.'
                    });
                    isValid = false;
                }
            } else if (refundType === 'adjust') {
                // Validate adjustment fields
                var targetInvoiceId = $('#target_invoice_id').val();
                var adjustmentAmount = parseFloat($('#adjustment_amount').val() || 0);
                var maxAmount = parseFloat($('#totalOverpayment').text() || 0);
                var adjustmentDate = new Date($('#adjustment_date').val());
                
                // Validate adjustment date falls within financial year
                if (adjustmentDate < fyStartDate || adjustmentDate > fyEndDate) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Date Validation Error',
                        text: 'Adjustment date must be within the selected financial year\'s date range: ' + 
                              formatDateForAlert(fyStartDate) + ' to ' + formatDateForAlert(fyEndDate)
                    });
                    isValid = false;
                } else if (!targetInvoiceId) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation Error',
                        text: 'Please select a target invoice for adjustment.'
                    });
                    isValid = false;
                } else if (adjustmentAmount <= 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation Error',
                        text: 'Please enter a valid adjustment amount greater than zero.'
                    });
                    isValid = false;
                } else if (adjustmentAmount > maxAmount) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation Error',
                        text: 'Adjustment amount cannot exceed the total overpayment amount of ' + maxAmount.toFixed(2)
                    });
                    isValid = false;
                }
            }
            
            if (!isValid) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // Helper function to format dates for display in alerts
        function formatDateForAlert(date) {
            try {
                if (!date || typeof date.getDate !== 'function') {
                    return 'Invalid Date';
                }
                var day = date.getDate();
                var monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                var month = monthNames[date.getMonth()];
                var year = date.getFullYear();
                return day + ' ' + month + ' ' + year;
            } catch (error) {
                console.error('Error in formatDateForAlert:', error);
                return 'Invalid Date';
            }
        }
        
        // Initialize refund type to set correct required fields
        $('#refund_type').trigger('change');
        
        // If supplier is already selected, trigger change to load data
        if ($('#supplier_id').val()) {
            $('#supplier_id').trigger('change');
        }
    });
</script>
