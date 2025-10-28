<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['payment_view'])) {
    header("Location: ../../../index.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if payment ID and type are provided
if (!isset($_POST['id']) || empty($_POST['id']) || !isset($_POST['type']) || empty($_POST['type'])) {
    echo "<div class='alert alert-danger'>Payment ID and type are required</div>";
    exit();
}

$payment_id = $_POST['id'];
$type = $_POST['type'];

// Validate type
$valid_types = ['purchase', 'sales'];
if (!in_array($type, $valid_types)) {
    echo "<div class='alert alert-danger'>Invalid payment type</div>";
    exit();
}

try {
    if ($type == 'purchase') {
        $query = "SELECT pp.*, pi.invoice_number, pi.invoice_date, pi.total_amount as invoice_total,
                 s.supplier_name, s.supplier_code, s.contact_person, s.phone, s.email,
                 ba.account_number, ba.bank_name,
                 u.username as created_by_name
                 FROM purchase_payments pp
                 LEFT JOIN purchase_invoices pi ON pp.invoice_id = pi.id
                 LEFT JOIN suppliers s ON pi.supplier_id = s.id
                 LEFT JOIN bank_accounts ba ON pp.bank_account_id = ba.id
                 LEFT JOIN users u ON pp.created_by = u.id
                 WHERE pp.id = :payment_id";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':payment_id', $payment_id);
        $stmt->execute();
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            echo "<div class='alert alert-danger'>Payment not found</div>";
            exit();
        }
        
        // Get journal entry details
        $journal_query = "SELECT je.* 
                         FROM journal_entries je
                         WHERE je.reference_number = :reference_number
                         AND je.entry_type = 'payment'
                         AND DATE(je.entry_date) = :payment_date
                         ORDER BY je.id DESC
                         LIMIT 1";
        
        $journal_stmt = $conn->prepare($journal_query);
        $journal_stmt->bindParam(':reference_number', $payment['reference_number']);
        $journal_stmt->bindParam(':payment_date', $payment['payment_date']);
        $journal_stmt->execute();
        $journal = $journal_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get journal entry details if journal entry exists
        $journal_details = [];
        if ($journal) {
            $details_query = "SELECT jed.*, CONCAT('Account ID: ', jed.account_id) as account_name 
                              FROM journal_entry_details jed
                              WHERE jed.journal_entry_id = :journal_id
                              ORDER BY jed.id ASC";
            
            $details_stmt = $conn->prepare($details_query);
            $details_stmt->bindParam(':journal_id', $journal['id']);
            $details_stmt->execute();
            $journal_details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Output payment details
        ?>
        <input type="hidden" class="payment-id" value="<?php echo $payment_id; ?>">
        <input type="hidden" class="payment-type" value="<?php echo $type; ?>">
        
        <div class="row">
            <div class="col-md-6">
                <h5>Payment Information</h5>
                <table class="table table-bordered">
                    <tr>
                        <th width="40%">Payment Date:</th>
                        <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                    </tr>
                    <tr>
                        <th>Voucher Number:</th>
                        <td><?php echo htmlspecialchars($payment['reference_number'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>Amount:</th>
                        <td><?php echo formatNumber($conn, $payment['amount']); ?></td>
                    </tr>
                    <tr>
                        <th>Payment Method:</th>
                        <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                    </tr>
                    <?php if ($payment['bank_account_id']): ?>
                    <tr>
                        <th>Bank Account:</th>
                        <td><?php echo htmlspecialchars($payment['bank_name'] . ' - ' . $payment['account_number']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Status:</th>
                        <td>
                            <?php if ($payment['status'] == 'completed'): ?>
                                <span class="badge bg-success">Completed</span>
                            <?php elseif ($payment['status'] == 'cancelled'): ?>
                                <span class="badge bg-danger">Cancelled</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?php echo ucfirst($payment['status']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Created By:</th>
                        <td><?php echo htmlspecialchars($payment['created_by_name']); ?></td>
                    </tr>
                    <tr>
                        <th>Created At:</th>
                        <td><?php echo date('d M Y H:i', strtotime($payment['created_at'])); ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h5>Invoice Information</h5>
                <table class="table table-bordered">
                    <tr>
                        <th width="40%">Invoice Number:</th>
                        <td><?php echo htmlspecialchars($payment['invoice_number']); ?></td>
                    </tr>
                    <tr>
                        <th>Invoice Date:</th>
                        <td><?php echo date('d M Y', strtotime($payment['invoice_date'])); ?></td>
                    </tr>
                    <tr>
                        <th>Invoice Total:</th>
                        <td><?php echo formatNumber($conn, $payment['invoice_total']); ?></td>
                    </tr>
                    <tr>
                        <th>Supplier:</th>
                        <td><?php echo htmlspecialchars($payment['supplier_name']); ?></td>
                    </tr>
                    <tr>
                        <th>Contact Person:</th>
                        <td><?php echo htmlspecialchars($payment['contact_person'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?php echo htmlspecialchars($payment['phone'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?php echo htmlspecialchars($payment['email'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <?php if (!empty($payment['notes'])): ?>
        <div class="row mt-3">
            <div class="col-md-12">
                <h5>Notes</h5>
                <div class="card">
                    <div class="card-body">
                        <?php echo nl2br(htmlspecialchars($payment['notes'])); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($journal_details)): ?>
        <div class="row mt-3">
            <div class="col-md-12">
                <h5>Journal Entry Details</h5>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Description</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($journal_details as $detail): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($detail['account_name']); ?></td>
                            <td><?php echo htmlspecialchars($detail['description']); ?></td>
                            <td class="text-end"><?php echo formatNumber($conn, $detail['debit_amount'] ?? 0); ?></td>
                            <td class="text-end"><?php echo formatNumber($conn, $detail['credit_amount'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="2" class="text-end">Total</th>
                            <th class="text-end"><?php echo formatNumber($conn, $journal['total_debit']); ?></th>
                            <th class="text-end"><?php echo formatNumber($conn, $journal['total_credit']); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php
    } else { // Sales payment
        // Similar logic for sales payments would go here
        echo "<div class='alert alert-info'>Sales payment details not yet implemented</div>";
    }
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
}
?>
