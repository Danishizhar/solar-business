<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Get the correct base path
$base_path = '../../';

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['payment_view'])) {
    header("Location: ../../index.php");
    exit();
}

// Include database connection
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if payment ID and type are provided
if (!isset($_GET['id']) || empty($_GET['id']) || !isset($_GET['type']) || empty($_GET['type'])) {
    echo "<div class='alert alert-danger'>Payment ID and type are required</div>";
    exit();
}

$payment_id = $_GET['id'];
$type = $_GET['type'];

// Validate type
$valid_types = ['purchase', 'sales'];
if (!in_array($type, $valid_types)) {
    echo "<div class='alert alert-danger'>Invalid payment type</div>";
    exit();
}

// Get company information for header
$company_query = "SELECT * FROM company LIMIT 1";
$company_stmt = $conn->prepare($company_query);
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC);

try {
    if ($type == 'purchase') {
        $query = "SELECT pp.*, pi.invoice_number, pi.invoice_date, pi.total_amount as invoice_total,
                 s.supplier_name, s.supplier_code, s.contact_person, s.phone, s.email, s.address,
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Voucher #<?php echo $payment['reference_number']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-size: 14px;
            font-family: Arial, sans-serif;
        }
        .payment-voucher {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .company-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .company-header h2 {
            margin-bottom: 5px;
        }
        .voucher-title {
            text-align: center;
            margin: 20px 0;
            font-weight: bold;
            font-size: 18px;
            text-decoration: underline;
        }
        .table {
            width: 100%;
            margin-bottom: 1rem;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 8px;
            border: 1px solid #dee2e6;
        }
        .table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .signature-section {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            width: 200px;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            margin-bottom: 5px;
        }
        .print-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .print-buttons {
            margin-bottom: 20px;
            text-align: right;
        }
        @media print {
            .print-buttons {
                display: none;
            }
            body {
                margin: 0;
                padding: 0;
            }
            .payment-voucher {
                width: 100%;
                padding: 0;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <div class="payment-voucher">
        <div class="print-buttons">
            <button class="btn btn-primary" onclick="window.print()">Print</button>
            <button class="btn btn-secondary" onclick="window.close()">Close</button>
        </div>
        
        <div class="company-header">
            <?php if (!empty($company['logo_path'])): ?>
            <div class="company-logo">
                <img src="<?php echo $base_path . htmlspecialchars($company['logo_path']); ?>" alt="Company Logo" style="max-height: 100px; margin-bottom: 10px;">
            </div>
            <?php endif; ?>
            <h2><?php echo htmlspecialchars($company['company_name'] ?? 'Company Name'); ?></h2>
            <p><?php echo htmlspecialchars($company['address'] ?? 'Company Address'); ?></p>
            <p>Phone: <?php echo htmlspecialchars($company['phone'] ?? 'Phone Number'); ?> | Email: <?php echo htmlspecialchars($company['email'] ?? 'Email Address'); ?></p>
        </div>
        
        <div class="voucher-title">
            PAYMENT VOUCHER
        </div>
        
        <div class="print-header">
            <div>
                <strong>Voucher #:</strong> <?php echo htmlspecialchars($payment['reference_number'] ?? 'N/A'); ?>
            </div>
            <div>
                <strong>Date:</strong> <?php echo date('d M Y', strtotime($payment['payment_date'])); ?>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <table class="table table-bordered">
                    <tr>
                        <th colspan="2">Supplier Information</th>
                    </tr>
                    <tr>
                        <th width="30%">Supplier Name:</th>
                        <td><?php echo htmlspecialchars($payment['supplier_name']); ?></td>
                    </tr>
                    <tr>
                        <th>Supplier Code:</th>
                        <td><?php echo htmlspecialchars($payment['supplier_code'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>Contact Person:</th>
                        <td><?php echo htmlspecialchars($payment['contact_person'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>Address:</th>
                        <td><?php echo htmlspecialchars($payment['address'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-md-12">
                <table class="table table-bordered">
                    <tr>
                        <th colspan="2">Payment Details</th>
                    </tr>
                    <tr>
                        <th width="30%">Invoice Number:</th>
                        <td><?php echo htmlspecialchars($payment['invoice_number']); ?></td>
                    </tr>
                    <tr>
                        <th>Invoice Date:</th>
                        <td><?php echo date('d M Y', strtotime($payment['invoice_date'])); ?></td>
                    </tr>
                    <tr>
                        <th>Invoice Amount:</th>
                        <td><?php echo formatNumber($conn, $payment['invoice_total']); ?></td>
                    </tr>
                    <tr>
                        <th>Payment Amount:</th>
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
                        <th>Notes:</th>
                        <td><?php echo htmlspecialchars($payment['notes'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Journal entry details section removed as requested -->
        
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line"></div>
                <div>Prepared By</div>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <div>Approved By</div>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <div>Received By</div>
            </div>
        </div>
    </div>

    <script>
        // Auto print when page loads
        window.onload = function() {
            // Uncomment the line below to automatically print when page loads
            // window.print();
        };
    </script>
</body>
</html>
<?php
    } else { // Sales payment
        // Similar logic for sales payments would go here
        echo "<div class='alert alert-info'>Sales payment print not yet implemented</div>";
    }
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
}
?>
