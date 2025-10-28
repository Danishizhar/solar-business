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

// Check if return ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['failure'] = "Invalid sales return ID";
    header('Location: sales_returns.php');
    exit();
}

$return_id = $_GET['id'];

// Get sales return details
$return_query = "SELECT pr.*, pi.invoice_number, s.customer_name, s.customer_code, l.location_name,
                u.username as created_by_name, u2.username as updated_by_name
                FROM sales_returns pr
                JOIN sales_invoices pi ON pr.invoice_id = pi.id
                JOIN customers s ON pr.customer_id = s.id
                JOIN inventory_locations l ON pr.location_id = l.id
                JOIN users u ON pr.created_by = u.id
                LEFT JOIN users u2 ON pr.updated_by = u2.id
                WHERE pr.id = :return_id";

$return_stmt = $conn->prepare($return_query);
$return_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
$return_stmt->execute();
$return = $return_stmt->fetch(PDO::FETCH_ASSOC);

if (!$return) {
    $_SESSION['failure'] = "Sales return not found";
    header('Location: sales_returns.php');
    exit();
}

// Get return items with proper UOM handling
$items_query = "SELECT pri.*, p.product_name, p.product_code, 
                pri.discount_percent, pri.discount_amount,
                pii.batch_number, pii.expiry_date, pii.uom_id,
                p.sales_uom_id, p.base_uom_id,
                u.uom_name, u.uom_symbol
                FROM sales_return_items pri
                JOIN products p ON pri.product_id = p.id
                JOIN sales_invoice_items pii ON pri.invoice_item_id = pii.id
                LEFT JOIN uom u ON COALESCE(pii.uom_id, p.sales_uom_id, p.base_uom_id, 1) = u.id
                WHERE pri.return_id = :return_id
                ORDER BY pri.id";

$items_stmt = $conn->prepare($items_query);
$items_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
$items_stmt->execute();
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if credit note exists for this return
$credit_note_query = "SELECT * FROM credit_notes WHERE return_id = :return_id AND status = 'active'";
$credit_note_stmt = $conn->prepare($credit_note_query);
$credit_note_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
$credit_note_stmt->execute();
$credit_note = $credit_note_stmt->fetch(PDO::FETCH_ASSOC);

// Set page title
$page_title = "Sales Return Detail: " . $return['return_number'];

// Get company information for print section
$company_query = "SELECT * FROM company LIMIT 1";
$company_stmt = $conn->prepare($company_query);
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC);

// Include header
require_once '../../includes/header.php'; ?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-undo-alt"></i> Sales Return Details</h2>
                </div>
                <div class="col-md-6 text-end">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="sales_returns.php">Sales Returns</a></li>
                        <li class="breadcrumb-item active">Return Detail</li>
                    </ol>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Return Information</h5>
                    <div class="card-tools">
                        <?php if ($return['status'] == 'draft' && ($_SESSION['role_id'] == 1 || (isset($_SESSION['permissions']['sales_returns']) && in_array('approve', $_SESSION['permissions']['sales_returns'])))): ?>
                        <button type="button" class="btn btn-success btn-sm mr-1" id="approve-return">
                            <i class="fas fa-check"></i> Approve Return
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($return['status'] == 'approved' && ($_SESSION['role_id'] == 1 || (isset($_SESSION['permissions']['sales_returns']) && in_array('cancel', $_SESSION['permissions']['sales_returns'])))): ?>
                        <button type="button" class="btn btn-danger btn-sm mr-1" id="cancel-return">
                            <i class="fas fa-times"></i> Cancel Return
                        </button>
                        <?php endif; ?>
                        
                        <button type="button" class="btn btn-primary btn-sm" onclick="printSalesReturn()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="30%">Return Number</th>
                                    <td><?php echo $return['return_number']; ?></td>
                                </tr>
                                <tr>
                                    <th>Return Date</th>
                                    <td><?php echo date('d M Y', strtotime($return['return_date'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td>
                                        <?php if ($return['status'] == 'draft'): ?>
                                        <span class="badge bg-warning text-dark">Draft</span>
                                        <?php elseif ($return['status'] == 'approved'): ?>
                                        <span class="badge bg-success">Approved</span>
                                        <?php elseif ($return['status'] == 'cancelled'): ?>
                                        <span class="badge bg-danger">Cancelled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Location</th>
                                    <td><?php echo htmlspecialchars($return['location_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Notes</th>
                                    <td><?php echo htmlspecialchars($return['notes'] ?? ''); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="30%">Customer</th>
                                    <td><?php echo htmlspecialchars($return['customer_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Invoice Number</th>
                                    <td>
                                        <a href="sales_invoice_detail.php?id=<?php echo $return['invoice_id']; ?>">
                                            <?php echo $return['invoice_number']; ?>
                                        </a>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Created By</th>
                                    <td><?php echo htmlspecialchars($return['created_by_name']); ?> on <?php echo date('d M Y H:i', strtotime($return['created_at'])); ?></td>
                                </tr>
                                <?php if ($return['updated_by']): ?>
                                <tr>
                                    <th>Updated By</th>
                                    <td><?php echo htmlspecialchars($return['updated_by_name']); ?> on <?php echo date('d M Y H:i', strtotime($return['updated_at'])); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($credit_note): ?>
                                <tr>
                                    <th>Credit Note</th>
                                    <td>
                                        <span class="badge bg-info"><?php echo $credit_note['note_number']; ?></span>
                                        Amount: <?php echo formatNumber($conn, $credit_note['amount']); ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Return Items</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th>Batch #</th>
                                    <th>Expiry Date</th>
                                    <th>Quantity</th>
                                    <th>UOM</th>
                                    <th>Unit Price</th>
                                    <th>Discount (%)</th>
                                    <th>Tax Rate (%)</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $counter = 1;
                                foreach ($items as $item): 
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($item['product_code']); ?> - 
                                        <?php echo htmlspecialchars($item['product_name']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['batch_number'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        echo !empty($item['expiry_date']) ? date('d M Y', strtotime($item['expiry_date'])) : 'N/A'; 
                                        ?>
                                    </td>
                                    <td><?php echo formatNumber($conn, $item['quantity']); ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($item['uom_name']) && !empty($item['uom_symbol'])) {
                                            echo htmlspecialchars($item['uom_name']) . ' (' . htmlspecialchars($item['uom_symbol']) . ')';
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['unit_price']); ?></td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['discount_percent']); ?></td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['tax_rate']); ?></td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['total_amount']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="9" class="text-end">Subtotal:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $return['subtotal']); ?></td>
                                </tr>
                                <tr>
                                    <th colspan="9" class="text-end">Discount:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $return['discount_amount']); ?></td>
                                </tr>
                                <tr>
                                    <th colspan="9" class="text-end">Tax:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $return['tax_amount']); ?></td>
                                </tr>
                                <?php if ($return['delivery_charges'] > 0): ?>
                                <tr>
                                    <th colspan="9" class="text-end">Delivery Charges Return:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $return['delivery_charges']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th colspan="9" class="text-end">Total:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $return['total_amount']); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Approve return
    $('#approve-return').click(function() {
        Swal.fire({
            title: 'Approve Return?',
            text: "This will create inventory and accounting entries. Are you sure?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, approve it!'
        }).then((result) => {
            if (result.isConfirmed) {
                // Send POST request instead of GET
                $.ajax({
                    url: 'actions/approve_sales_return.php',
                    type: 'POST',
                    data: {
                        id: <?php echo $return_id; ?>
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                title: 'Success!',
                                text: response.message,
                                icon: 'success'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                title: 'Error!',
                                text: response.message,
                                icon: 'error'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({
                            title: 'Error!',
                            text: 'An error occurred while processing the request.',
                            icon: 'error'
                        });
                    }
                });
            }
        });
    });

    // Cancel return
    $('#cancel-return').click(function() {
        Swal.fire({
            title: 'Cancel Return?',
            text: "This will reverse all inventory and accounting entries. Are you sure?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, cancel it!'
        }).then((result) => {
            if (result.isConfirmed) {
                // Send POST request instead of GET
                $.ajax({
                    url: 'actions/cancel_sales_return.php',
                    type: 'POST',
                    data: {
                        id: <?php echo $return_id; ?>
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                title: 'Success!',
                                text: response.message,
                                icon: 'success'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                title: 'Error!',
                                text: response.message,
                                icon: 'error'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({
                            title: 'Error!',
                            text: 'An error occurred while processing the request.',
                            icon: 'error'
                        });
                    }
                });
            }
        });
    });

});

function printSalesReturn() {
    // Clone the print section
    const printContent = document.getElementById('print-section').innerHTML;
    
    // Create a new window
    const printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Sales Return: <?php echo htmlspecialchars($return["return_number"]); ?></title>');
    printWindow.document.write('<style>');
    printWindow.document.write(document.querySelector('#print-section style').innerHTML);
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write(printContent);
    printWindow.document.write('</body></html>');
    
    // Wait for content to load
    printWindow.document.close();
    printWindow.focus();
    
    // Print after a short delay to ensure content is loaded
    setTimeout(function() {
        printWindow.print();
        printWindow.close();
    }, 250);
}
</script>

<!-- Print Section (Hidden) -->
<div id="print-section" class="d-none">
    <style>
        @media print {
            body {
                font-family: Arial, sans-serif;
                font-size: 12px;
            }
            .print-header {
                border-bottom: 1px solid #ddd;
                padding-bottom: 15px;
                margin-bottom: 20px;
            }
            .print-title {
                font-size: 24px;
                font-weight: bold;
                color: #333;
                margin-bottom: 5px;
            }
            .print-subtitle {
                font-size: 14px;
                color: #666;
            }
            .print-box {
                border: 1px solid #ddd;
                padding: 10px;
                margin-bottom: 15px;
                border-radius: 5px;
            }
            .print-box-title {
                font-weight: bold;
                border-bottom: 1px solid #eee;
                padding-bottom: 5px;
                margin-bottom: 10px;
            }
            .print-table {
                width: 100%;
                border-collapse: collapse;
            }
            .print-table th, .print-table td {
                border: 1px solid #ddd;
                padding: 8px;
            }
            .print-table th {
                background-color: #f5f5f5;
            }
            .text-end {
                text-align: right;
            }
            .company-logo {
                max-height: 80px;
                max-width: 200px;
            }
            .mb-4 {
                margin-bottom: 20px;
            }
            .col-12 {
                width: 100%;
            }
            .col-6 {
                width: 50%;
                float: left;
            }
            .row::after {
                content: "";
                clear: both;
                display: table;
            }
            .badge {
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: bold;
                color: white;
                display: inline-block;
            }
            .bg-success {
                background-color: #28a745;
            }
            .bg-warning {
                background-color: #ffc107;
                color: #212529;
            }
            .bg-danger {
                background-color: #dc3545;
            }
            .bg-info {
                background-color: #17a2b8;
            }
            .signature-section {
                margin-top: 50px;
                page-break-inside: avoid;
            }
            .signature-line {
                border-top: 1px solid #000;
                width: 80%;
                margin: 50px auto 10px;
            }
            .signature-table {
                width: 100%;
                table-layout: fixed;
            }
            .signature-table td {
                padding: 0 15px;
                vertical-align: top;
            }
            .text-center {
                text-align: center;
            }
        }
    </style>
    
    <div class="container">
        <!-- Header with Company and Return Info -->
        <div class="row print-header">
            <div class="col-6">
                <?php 
                // Use the company information already loaded in the main page
                if (!empty($company['logo_path'])): 
                ?>
                <img src="<?php echo htmlspecialchars('../../' . $company['logo_path']); ?>" alt="Company Logo" class="company-logo">
                <?php endif; ?>
                <h3 class="print-title"><?php echo htmlspecialchars($company['company_name'] ?? 'Solar Business'); ?></h3>
                <div class="print-subtitle">
                    <?php if (!empty($company['address'])): ?>
                    <div><?php echo nl2br(htmlspecialchars($company['address'])); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($company['phone'])): ?>
                    <div>Phone: <?php echo htmlspecialchars($company['phone']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($company['email'])): ?>
                    <div>Email: <?php echo htmlspecialchars($company['email']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-6" style="text-align: right;">
                <h2>SALES RETURN</h2>
                <div>
                    <?php if ($return['status'] == 'draft'): ?>
                    <span class="badge bg-warning text-dark">Draft</span>
                    <?php elseif ($return['status'] == 'approved'): ?>
                    <span class="badge bg-success">Approved</span>
                    <?php elseif ($return['status'] == 'cancelled'): ?>
                    <span class="badge bg-danger">Cancelled</span>
                    <?php endif; ?>
                </div>
                <table style="margin-left: auto;">
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
                    <?php if ($credit_note): ?>
                    <tr>
                        <td><strong>Credit Note:</strong></td>
                        <td><span class="badge bg-info"><?php echo $credit_note['note_number']; ?></span></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-6">
                <div class="print-box">
                    <div class="print-box-title">Customer</div>
                    <strong><?php echo htmlspecialchars($return['customer_name']); ?></strong><br>
                    <?php echo htmlspecialchars($return['customer_code']); ?><br>
                    <?php 
                    // Get additional customer details
                    $customer_query = "SELECT s.address, s.phone, s.email, s.contact_person 
                                      FROM customers s WHERE s.id = :customer_id";
                    $customer_stmt = $conn->prepare($customer_query);
                    $customer_stmt->bindParam(':customer_id', $return['customer_id'], PDO::PARAM_INT);
                    $customer_stmt->execute();
                    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!empty($customer['address'])): ?>
                    <?php echo nl2br(htmlspecialchars($customer['address'])); ?><br>
                    <?php endif; ?>
                    
                    <?php if (!empty($customer['phone'])): ?>
                    <strong>Phone:</strong> <?php echo htmlspecialchars($customer['phone']); ?><br>
                    <?php endif; ?>
                    
                    <?php if (!empty($customer['email'])): ?>
                    <strong>Email:</strong> <?php echo htmlspecialchars($customer['email']); ?><br>
                    <?php endif; ?>
                    
                    <?php if (!empty($customer['contact_person'])): ?>
                    <strong>Contact:</strong> <?php echo htmlspecialchars($customer['contact_person']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-6">
                <div class="print-box">
                    <div class="print-box-title">Return Details</div>
                    <strong>Location:</strong> <?php echo htmlspecialchars($return['location_name']); ?><br>
                    <strong>Return Type:</strong> <?php echo ucfirst($return['return_type']); ?><br>
                    <?php if (!empty($return['notes'])): ?>
                    <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($return['notes'])); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-12">
                <table class="print-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product Code</th>
                            <th>Product Name</th>
                            <th>Batch #</th>
                            <th>Expiry Date</th>
                            <th>Quantity</th>
                            <th>UOM</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Discount %</th>
                            <th class="text-end">Tax %</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($items as $item): 
                            $uom_display = !empty($item['uom_symbol']) ? $item['uom_symbol'] : (!empty($item['uom_name']) ? $item['uom_name'] : 'N/A');
                        ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($item['product_code']); ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['batch_number'] ?? 'N/A'); ?></td>
                            <td><?php echo !empty($item['expiry_date']) ? date('d M Y', strtotime($item['expiry_date'])) : 'N/A'; ?></td>
                            <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                            <td><?php echo htmlspecialchars($uom_display); ?></td>
                            <td class="text-end"><?php echo formatNumber($conn, $item['unit_price']); ?></td>
                            <td class="text-end"><?php echo formatNumber($conn, $item['discount_percent']); ?></td>
                            <td class="text-end"><?php echo formatNumber($conn, $item['tax_rate']); ?></td>
                            <td class="text-end"><?php echo formatNumber($conn, $item['total_amount']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="10" class="text-end"><strong>Subtotal:</strong></td>
                            <td class="text-end"><?php echo formatNumber($conn, $return['subtotal']); ?></td>
                        </tr>
                        <tr>
                            <td colspan="10" class="text-end"><strong>Discount:</strong></td>
                            <td class="text-end"><?php echo formatNumber($conn, $return['discount_amount']); ?></td>
                        </tr>
                        <tr>
                            <td colspan="10" class="text-end"><strong>Tax:</strong></td>
                            <td class="text-end"><?php echo formatNumber($conn, $return['tax_amount']); ?></td>
                        </tr>
                        <?php if ($return['delivery_charges'] > 0): ?>
                        <tr>
                            <td colspan="10" class="text-end"><strong>Delivery Charges:</strong></td>
                            <td class="text-end"><?php echo formatNumber($conn, $return['delivery_charges']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td colspan="10" class="text-end"><strong>Total:</strong></td>
                            <td class="text-end"><strong><?php echo formatNumber($conn, $return['total_amount']); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <div class="signature-section">
            <table class="signature-table">
                <tr>
                    <td width="33%" class="text-center">
                        <div class="signature-line"></div>
                        <p>Prepared By</p>
                        <p><?php echo htmlspecialchars($return['created_by_name']); ?></p>
                    </td>
                    <td width="33%" class="text-center">
                        <?php if (!empty($return['updated_by_name'])): ?>
                        <div class="signature-line"></div>
                        <p>Updated By</p>
                        <p><?php echo htmlspecialchars($return['updated_by_name']); ?></p>
                        <?php else: ?>
                        &nbsp;
                        <?php endif; ?>
                    </td>
                    <td width="33%" class="text-center">
                        <div class="signature-line"></div>
                        <p>Customer</p>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</div>
