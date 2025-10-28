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
if ($_SESSION['role_id'] != 1 && (!isset($_SESSION['permissions']['sales_returns']) || !in_array('view', $_SESSION['permissions']['sales_returns']))) {
    $_SESSION['failure'] = "You don't have permission to view sales returns";
    header('Location: ../../index.php');
    exit();
}

// Include database connection
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Include helper functions
require_once '../../includes/company_helpers.php';

// Check if return_id is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: sales_returns.php");
    exit();
}

$return_id = $_GET['id'];

try {
    // Get return details
    $return_query = "SELECT pr.*, pi.invoice_number, pi.invoice_date, s.customer_name, 
                    s.address as customer_address, s.phone as customer_phone, s.email as customer_email,
                    u.username as created_by_name, 
                    (SELECT username FROM users WHERE id = pr.updated_by) as updated_by_name,
                    (SELECT note_number FROM credit_notes WHERE return_id = pr.id AND status = 'active' LIMIT 1) as credit_note_number
                    FROM sales_returns pr
                    JOIN sales_invoices pi ON pr.invoice_id = pi.id
                    JOIN customers s ON pr.customer_id = s.id
                    JOIN users u ON pr.created_by = u.id
                    WHERE pr.id = :return_id";
    
    $return_stmt = $conn->prepare($return_query);
    $return_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $return_stmt->execute();
    $return = $return_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$return) {
        header("Location: sales_returns.php");
        exit();
    }
    
    // Get return items
    $items_query = "SELECT pri.*, p.product_name, p.product_code, 
                   pii.batch_number, pii.expiry_date,
                   u.uom_name, u.uom_symbol
                   FROM sales_return_items pri
                   JOIN products p ON pri.product_id = p.id
                   JOIN sales_invoice_items pii ON pri.invoice_item_id = pii.id
                   LEFT JOIN uom u ON COALESCE(pii.uom_id, p.sales_uom_id, p.base_uom_id, 1) = u.id
                   WHERE pri.return_id = :return_id";
    
    $items_stmt = $conn->prepare($items_query);
    $items_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $items_stmt->execute();
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get company details
    $company_query = "SELECT * FROM company LIMIT 1";
    $company_stmt = $conn->prepare($company_query);
    $company_stmt->execute();
    $company = $company_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log('Error in print_sales_return.php: ' . $e->getMessage());
    header("Location: sales_returns.php");
    exit();
}

// Format status for display
$status_class = '';
$status_text = ucfirst($return['status']);

switch ($return['status']) {
    case 'draft':
        $status_class = 'badge bg-warning';
        break;
    case 'approved':
        $status_class = 'badge bg-success';
        break;
    case 'cancelled':
        $status_class = 'badge bg-danger';
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Return #<?php echo htmlspecialchars($return['return_number']); ?></title>
    <link rel="stylesheet" href="../../assets/css/bootstrap.min.css">
    <style>
        body {
            font-size: 14px;
        }
        .print-header {
            margin-bottom: 20px;
        }
        .company-logo {
            max-height: 80px;
        }
        .print-title {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .info-table {
            width: 100%;
            margin-bottom: 20px;
        }
        .info-table td {
            padding: 5px;
            vertical-align: top;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th, .items-table td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        .items-table th {
            background-color: #f2f2f2;
            text-align: left;
        }
        .text-right {
            text-align: right;
        }
        .summary-table {
            width: 40%;
            margin-left: auto;
            border-collapse: collapse;
        }
        .summary-table td {
            padding: 5px;
        }
        .summary-table .total-row {
            font-weight: bold;
        }
        .signature-section {
            margin-top: 50px;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 200px;
            display: inline-block;
            margin-top: 70px;
            margin-right: 50px;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                margin: 0;
                padding: 15px;
            }
            .container {
                width: 100%;
                max-width: 100%;
                padding: 0;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row no-print mb-3 mt-3">
            <div class="col-12">
                <button class="btn btn-primary" onclick="window.print()">Print</button>
                <a href="sales_return_detail.php?id=<?php echo $return_id; ?>" class="btn btn-secondary">Back</a>
            </div>
        </div>
        
        <div class="print-header">
            <div class="row">
                <div class="col-6">
                    <?php if (!empty($company['logo'])): ?>
                        <img src="../../uploads/<?php echo htmlspecialchars($company['logo']); ?>" alt="Company Logo" class="company-logo">
                    <?php endif; ?>
                    <h4><?php echo htmlspecialchars($company['name'] ?? 'Company Name'); ?></h4>
                    <p>
                        <?php echo nl2br(htmlspecialchars($company['address'] ?? '')); ?><br>
                        <?php if (!empty($company['phone'])): ?>
                            Phone: <?php echo htmlspecialchars($company['phone']); ?><br>
                        <?php endif; ?>
                        <?php if (!empty($company['email'])): ?>
                            Email: <?php echo htmlspecialchars($company['email']); ?><br>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-6 text-end">
                    <div class="print-title">SALES RETURN</div>
                    <div>
                        <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    </div>
                    <table class="info-table">
                        <tr>
                            <td><strong>Return Number:</strong></td>
                            <td><?php echo htmlspecialchars($return['return_number']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Return Date:</strong></td>
                            <td><?php echo date('d M Y', strtotime($return['return_date'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Invoice Number:</strong></td>
                            <td><?php echo htmlspecialchars($return['invoice_number']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Invoice Date:</strong></td>
                            <td><?php echo date('d M Y', strtotime($return['invoice_date'])); ?></td>
                        </tr>
                        <?php if (!empty($return['credit_note_number'])): ?>
                        <tr>
                            <td><strong>Credit Note:</strong></td>
                            <td><?php echo htmlspecialchars($return['credit_note_number']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-6">
                <h5>Customer</h5>
                <p>
                    <strong><?php echo htmlspecialchars($return['customer_name']); ?></strong><br>
                    <?php echo nl2br(htmlspecialchars($return['customer_address'] ?? '')); ?><br>
                    <?php if (!empty($return['customer_phone'])): ?>
                        Phone: <?php echo htmlspecialchars($return['customer_phone']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($return['customer_email'])): ?>
                        Email: <?php echo htmlspecialchars($return['customer_email']); ?><br>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <div class="row">
            <div class="col-12">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product Code</th>
                            <th>Product Name</th>
                            <th>Batch #</th>
                            <th>Expiry Date</th>
                            <th>Quantity</th>
                            <th>UOM</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right">Discount %</th>
                            <th class="text-right">Tax %</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        foreach ($items as $item): 
                            $uom_display = !empty($item['uom_symbol']) ? $item['uom_symbol'] : $item['uom_name'];
                        ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($item['product_code']); ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['batch_number'] ?? 'N/A'); ?></td>
                            <td><?php echo !empty($item['expiry_date']) ? date('d M Y', strtotime($item['expiry_date'])) : 'N/A'; ?></td>
                            <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                            <td><?php echo htmlspecialchars($uom_display); ?></td>
                            <td class="text-right"><?php echo formatNumber($conn, $item['unit_price']); ?></td>
                            <td class="text-right"><?php echo formatNumber($conn, $item['discount_percent']); ?></td>
                            <td class="text-right"><?php echo formatNumber($conn, $item['tax_rate']); ?></td>
                            <td class="text-right"><?php echo formatNumber($conn, $item['total_amount']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="row">
            <div class="col-7">
                <div class="notes-section">
                    <h5>Notes</h5>
                    <p><?php echo nl2br(htmlspecialchars($return['notes'] ?? '')); ?></p>
                </div>
            </div>
            <div class="col-5">
                <table class="summary-table">
                    <tr>
                        <td>Subtotal:</td>
                        <td class="text-right"><?php echo formatNumber($conn, $return['subtotal']); ?></td>
                    </tr>
                    <tr>
                        <td>Tax:</td>
                        <td class="text-right"><?php echo formatNumber($conn, $return['tax_amount']); ?></td>
                    </tr>
                    <?php if ($return['delivery_charges'] > 0): ?>
                    <tr>
                        <td>Delivery Charges:</td>
                        <td class="text-right"><?php echo formatNumber($conn, $return['delivery_charges']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td>Total:</td>
                        <td class="text-right"><?php echo formatNumber($conn, $return['total_amount']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="signature-section">
            <div class="row">
                <div class="col-4 text-center">
                    <div class="signature-line"></div>
                    <p>Prepared By</p>
                    <p><?php echo htmlspecialchars($return['created_by_name']); ?></p>
                </div>
                <?php if (!empty($return['updated_by_name'])): ?>
                <div class="col-4 text-center">
                    <div class="signature-line"></div>
                    <p>Updated By</p>
                    <p><?php echo htmlspecialchars($return['updated_by_name']); ?></p>
                </div>
                <?php endif; ?>
                <div class="col-4 text-center">
                    <div class="signature-line"></div>
                    <p>Customer</p>
                </div>
            </div>
        </div>
    </div>

    <script src="../../assets/js/jquery-3.6.0.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
