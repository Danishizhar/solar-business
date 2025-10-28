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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['purchase_view'])) {
    header("Location: ../../index.php");
    exit();
}

// Include database connection
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Include helper functions
require_once '../../includes/company_helpers.php';

// Check if purchase order ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: purchase_orders.php?error=" . urlencode("Purchase order ID is required"));
    exit();
}

$purchase_order_id = $_GET['id'];

// Get company details
$companyQuery = "SELECT * FROM company LIMIT 1";
try {
    $companyStmt = $conn->prepare($companyQuery);
    $companyStmt->execute();
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $company = [];
    error_log("Error fetching company details: " . $e->getMessage());
}

// Get purchase order details
$query = "SELECT po.*, s.supplier_name, s.supplier_code, s.contact_person, s.phone, s.email, s.address as supplier_address,
          created.username as created_by_name,
          updated.username as updated_by_name
          FROM purchase_orders po
          LEFT JOIN suppliers s ON po.supplier_id = s.id
          LEFT JOIN users created ON po.created_by = created.id
          LEFT JOIN users updated ON po.updated_by = updated.id
          WHERE po.id = :purchase_order_id";

try {
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':purchase_order_id', $purchase_order_id);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        header("Location: purchase_orders.php?error=" . urlencode("Purchase order not found"));
        exit();
    }
    
    $purchase_order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get purchase order items
    $items_query = "SELECT poi.*, p.product_name, p.product_code, p.barcode,
                   p.purchase_uom_id, p.base_uom_id,
                   COALESCE(uom.uom_name, 'Unit') as unit_name,
                   COALESCE(uom.uom_symbol, '') as unit_symbol
                   FROM purchase_order_items poi
                   LEFT JOIN products p ON poi.product_id = p.id
                   LEFT JOIN uom ON COALESCE(p.purchase_uom_id, poi.uom_id, p.base_uom_id) = uom.id
                   WHERE poi.purchase_order_id = :purchase_order_id
                   ORDER BY poi.id ASC";
    
    $items_stmt = $conn->prepare($items_query);
    $items_stmt->bindParam(':purchase_order_id', $purchase_order_id);
    $items_stmt->execute();
    $purchase_order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    header("Location: purchase_orders.php?error=" . urlencode("Error: " . $e->getMessage()));
    exit();
}

// Status badge colors
$status_colors = [
    'draft' => 'bg-secondary',
    'confirmed' => 'bg-primary',
    'partial' => 'bg-info',
    'completed' => 'bg-success',
    'received' => 'bg-success',
    'cancelled' => 'bg-danger'
];

// Set page title
$page_title = "Purchase Order Details: " . $purchase_order['order_number'];

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
                    <h2><i class="fas fa-file-invoice"></i> Purchase Order Details</h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="purchase_orders.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Purchase Orders
                    </a>
                    
                    <?php if ($purchase_order['status'] == 'draft' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_edit']))): ?>
                    <a href="purchase_order_create.php?id=<?php echo $purchase_order_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <?php endif; ?>
                    
                    <button class="btn btn-info" onclick="printPurchaseOrder()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    
                    <?php
                    // Check if this PO has any GRNs
                    $has_grn_query = "SELECT COUNT(*) as grn_count FROM goods_receiving_notes WHERE purchase_order_id = :po_id AND status != 'cancelled'";
                    $has_grn_stmt = $conn->prepare($has_grn_query);
                    $has_grn_stmt->bindParam(':po_id', $purchase_order_id);
                    $has_grn_stmt->execute();
                    $has_grn = $has_grn_stmt->fetch(PDO::FETCH_ASSOC)['grn_count'] > 0;
                    
                    // Check if this PO has any invoices
                    $has_invoice_query = "SELECT COUNT(*) as invoice_count FROM purchase_invoices WHERE purchase_order_id = :po_id AND status != 'cancelled'";
                    $has_invoice_stmt = $conn->prepare($has_invoice_query);
                    $has_invoice_stmt->bindParam(':po_id', $purchase_order_id);
                    $has_invoice_stmt->execute();
                    $has_invoice = $has_invoice_stmt->fetch(PDO::FETCH_ASSOC)['invoice_count'] > 0;
                    ?>
                    
                    <?php if (($purchase_order['status'] == 'confirmed' || $purchase_order['status'] == 'partial') && 
                           !$has_invoice && 
                           ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_add']))): ?>
                    <a href="grn_create.php?po_id=<?php echo $purchase_order_id; ?>" class="btn btn-success">
                        <i class="fas fa-clipboard-check"></i> Create GRN
                    </a>
                    <?php endif; ?>
                    
                    <?php if (($purchase_order['status'] == 'confirmed' || $purchase_order['status'] == 'partial') && 
                           !$has_grn && 
                           ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_add']))): ?>
                    <a href="purchase_invoice_from_po.php?po_id=<?php echo $purchase_order_id; ?>" class="btn btn-primary">
                        <i class="fas fa-file-invoice-dollar"></i> Create Invoice
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Order Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="30%">Order Number:</th>
                                    <td><?php echo htmlspecialchars($purchase_order['order_number']); ?></td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        <span class="badge <?php echo $status_colors[$purchase_order['status']]; ?>">
                                            <?php echo ucfirst($purchase_order['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Order Date:</th>
                                    <td><?php echo date('d M Y', strtotime($purchase_order['order_date'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Expected Delivery:</th>
                                    <td>
                                        <?php 
                                        if (!empty($purchase_order['expected_delivery_date'])) {
                                            echo date('d M Y', strtotime($purchase_order['expected_delivery_date']));
                                        } else {
                                            echo '<span class="text-muted">Not specified</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Created By:</th>
                                    <td><?php echo htmlspecialchars($purchase_order['created_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Created At:</th>
                                    <td><?php echo date('d M Y H:i', strtotime($purchase_order['created_at'])); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="30%">Supplier:</th>
                                    <td>
                                        <?php echo htmlspecialchars($purchase_order['supplier_name']); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($purchase_order['supplier_code']); ?></small>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Contact Person:</th>
                                    <td><?php echo htmlspecialchars($purchase_order['contact_person']); ?></td>
                                </tr>
                                <tr>
                                    <th>Phone:</th>
                                    <td><?php echo htmlspecialchars($purchase_order['phone']); ?></td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td><?php echo htmlspecialchars($purchase_order['email']); ?></td>
                                </tr>
                                <tr>
                                    <th>Last Updated By:</th>
                                    <td><?php echo htmlspecialchars($purchase_order['updated_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Last Updated At:</th>
                                    <td><?php echo date('d M Y H:i', strtotime($purchase_order['updated_at'])); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Order Items</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th>Barcode</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Discount %</th>
                                    <th class="text-end">Tax %</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($purchase_order_items as $index => $item): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($item['product_name']); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['barcode']); ?></td>
                                    <td class="text-end">
                                        <?php echo formatNumber($conn, $item['quantity']); ?>
                                        <?php if (!empty($item['unit_name'])): ?>
                                            <small class="d-block text-muted">
                                                <?php echo htmlspecialchars($item['unit_name']); ?>
                                                <?php if (!empty($item['unit_symbol'])): ?>
                                                    (<?php echo htmlspecialchars($item['unit_symbol']); ?>)
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['unit_price']); ?></td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['discount_percent']); ?>%</td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['tax_rate']); ?>%</td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['total']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="7" class="text-end">Subtotal:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $purchase_order['subtotal']); ?></td>
                                </tr>
                                <tr>
                                    <th colspan="7" class="text-end">Discount Amount:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $purchase_order['discount_amount']); ?></td>
                                </tr>
                                <tr>
                                    <th colspan="7" class="text-end">Tax Amount:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $purchase_order['tax_amount']); ?></td>
                                </tr>
                                <?php if (isset($purchase_order['delivery_charges']) && $purchase_order['delivery_charges'] > 0): ?>
                                <tr>
                                    <th colspan="7" class="text-end">Delivery Charges:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $purchase_order['delivery_charges']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th colspan="7" class="text-end">Total Amount:</th>
                                    <td class="text-end"><strong><?php echo formatNumber($conn, $purchase_order['total_amount']); ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($purchase_order['notes'])): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Notes</h5>
                </div>
                <div class="card-body">
                    <p><?php echo nl2br(htmlspecialchars($purchase_order['notes'])); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

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
            .text-right {
                text-align: right;
            }
            .company-logo {
                max-height: 80px;
                max-width: 200px;
            }
        }
    </style>

    <div class="container">
        <!-- Header with Company and PO Info -->
        <div class="row print-header">
            <div class="col-6" style="float: left; width: 50%;">
                <?php if (!empty($company['logo_path'])): ?>
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
                    <?php if (!empty($company['website'])): ?>
                    <div>Web: <?php echo htmlspecialchars($company['website']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($company['tax_number'])): ?>
                    <div>Tax #: <?php echo htmlspecialchars($company['tax_number']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-6 text-right" style="float: right; width: 50%; text-align: right;">
                <h2>PURCHASE ORDER</h2>
                <div class="print-subtitle">
                    <div><strong>Order #:</strong> <?php echo htmlspecialchars($purchase_order['order_number']); ?></div>
                    <div><strong>Date:</strong> <?php echo date('d M Y', strtotime($purchase_order['order_date'])); ?></div>
                    <div><strong>Status:</strong> <?php echo ucfirst($purchase_order['status']); ?></div>
                    <?php if (!empty($purchase_order['expected_delivery_date'])): ?>
                    <div><strong>Expected Delivery:</strong> <?php echo date('d M Y', strtotime($purchase_order['expected_delivery_date'])); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="clear: both;"></div>
        </div>
        
        <!-- Supplier Information -->
        <div class="row mb-4">
            <div class="col-12 print-box">
                <div class="print-box-title">Supplier Information</div>
                <table style="width: 100%">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <strong><?php echo htmlspecialchars($purchase_order['supplier_name']); ?></strong> (<?php echo htmlspecialchars($purchase_order['supplier_code']); ?>)<br>
                            <?php if (!empty($purchase_order['supplier_address'])): ?>
                            <?php echo nl2br(htmlspecialchars($purchase_order['supplier_address'])); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($purchase_order['contact_person'])): ?>
                            Contact: <?php echo htmlspecialchars($purchase_order['contact_person']); ?><br>
                            <?php endif; ?>
                        </td>
                        <td style="width: 50%; vertical-align: top;">
                            <?php if (!empty($purchase_order['phone'])): ?>
                            Phone: <?php echo htmlspecialchars($purchase_order['phone']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($purchase_order['email'])): ?>
                            Email: <?php echo htmlspecialchars($purchase_order['email']); ?><br>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-12 print-box">
                <div class="print-box-title">Order Items</div>
                <table class="print-table">
                    <thead>
                        <tr>
                            <th style="width: 5%">#</th>
                            <th style="width: 35%">Product</th>
                            <th style="width: 10%">Quantity</th>
                            <th style="width: 10%">Unit Price</th>
                            <th style="width: 10%">Tax Rate</th>
                            <th style="width: 10%">Tax Amount</th>
                            <th style="width: 10%">Subtotal</th>
                            <th style="width: 10%">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        foreach ($purchase_order_items as $item): 
                        ?>
                        <tr>
                            <td style="text-align: center"><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?>
                                <?php if (!empty($item['product_code'])): ?>
                                <small class="text-muted d-block"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right">
                                <?php echo formatNumber($conn, $item['quantity']); ?>
                                <?php if (!empty($item['unit_name'])): ?>
                                    <small class="d-block text-muted">
                                        <?php echo htmlspecialchars($item['unit_name']); ?>
                                        <?php if (!empty($item['unit_symbol'])): ?>
                                            (<?php echo htmlspecialchars($item['unit_symbol']); ?>)
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['unit_price']); ?></td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['tax_rate']); ?>%</td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['tax_amount']); ?></td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['subtotal']); ?></td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['total']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Order Summary -->
                <div style="width: 100%; margin-top: 20px;">
                    <table style="width: 100%; max-width: 350px; margin-left: auto; border-collapse: collapse;">
                        <tr>
                            <td style="width: 50%; padding: 5px; text-align: right;"><strong>Subtotal:</strong></td>
                            <td style="width: 50%; padding: 5px; text-align: right;">
                                <span style="white-space: nowrap;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $purchase_order['subtotal']); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 5px; text-align: right;"><strong>Tax Amount:</strong></td>
                            <td style="padding: 5px; text-align: right;">
                                <span style="white-space: nowrap;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $purchase_order['tax_amount']); ?></span>
                            </td>
                        </tr>
                        <?php if ($purchase_order['discount_amount'] > 0): ?>
                        <tr>
                            <td style="padding: 5px; text-align: right;"><strong>Discount:</strong></td>
                            <td style="padding: 5px; text-align: right;">
                                <span style="white-space: nowrap;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $purchase_order['discount_amount']); ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($purchase_order['delivery_charges']) && $purchase_order['delivery_charges'] > 0): ?>
                        <tr>
                            <td style="padding: 5px; text-align: right;"><strong>Delivery Charges:</strong></td>
                            <td style="padding: 5px; text-align: right;">
                                <span style="white-space: nowrap;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $purchase_order['delivery_charges']); ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr style="border-top: 1px solid #ddd">
                            <td style="padding: 5px; text-align: right;"><strong>Total Amount:</strong></td>
                            <td style="padding: 5px; text-align: right;">
                                <span style="white-space: nowrap; font-weight: bold;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $purchase_order['total_amount']); ?></span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <?php if (!empty($purchase_order['notes'])): ?>
        <div class="row mb-4">
            <div class="col-12 print-box">
                <div class="print-box-title">Notes</div>
                <p><?php echo nl2br(htmlspecialchars($purchase_order['notes'])); ?></p>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="row" style="margin-top: 50px;">
            <div class="col-4" style="float: left; width: 33%; text-align: center;">
                <div style="border-top: 1px solid #333; padding-top: 5px; margin-top: 20px;">
                    Authorized Signature
                </div>
            </div>
            <div class="col-4" style="float: left; width: 33%; text-align: center;">
                <div style="border-top: 1px solid #333; padding-top: 5px; margin-top: 20px;">
                    Supplier Signature
                </div>
            </div>
            <div class="col-4" style="float: left; width: 33%; text-align: center;">
                <div style="border-top: 1px solid #333; padding-top: 5px; margin-top: 20px;">
                    Date: <?php echo date('d/m/Y'); ?>
                </div>
            </div>
            <div style="clear: both;"></div>
        </div>
        
        <div class="row" style="margin-top: 30px; text-align: center; font-size: 10px; color: #666;">
            <div class="col-12">
                <p>This is a computer generated document. No signature required.</p>
                <p>Printed on: <?php echo date('d M Y H:i:s'); ?></p>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
function printPurchaseOrder() {
    // Clone the print section
    const printContent = document.getElementById('print-section').innerHTML;
    
    // Create a new window for printing
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Purchase Order: <?php echo htmlspecialchars($purchase_order['order_number']); ?></title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    font-size: 12px;
                    margin: 0;
                    padding: 20px;
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
                .text-right {
                    text-align: right;
                }
                .company-logo {
                    max-height: 80px;
                    max-width: 200px;
                }
                .container {
                    width: 100%;
                    max-width: 1140px;
                    margin: 0 auto;
                }
                .row {
                    width: 100%;
                    display: block;
                    margin-bottom: 15px;
                }
                .col-12 {
                    width: 100%;
                }
                @media print {
                    @page { margin: 10mm; }
                    body { margin: 0; }
                }
            </style>
        </head>
        <body>
            ${printContent}
        </body>
        </html>
    `);
    
    // Wait for content to load then print
    printWindow.document.close();
    printWindow.onload = function() {
        printWindow.focus();
        printWindow.print();
    };
}
</script>
