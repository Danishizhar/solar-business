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

// Define status colors for badges
$status_colors = [
    'draft' => 'bg-secondary',
    'approved' => 'bg-success',
    'partial' => 'bg-info',
    'paid' => 'bg-primary',
    'cancelled' => 'bg-danger',
    'returned' => 'bg-warning',
    'partially_returned' => 'bg-info'
];

// Define payment status colors for badges
$payment_status_colors = [
    'unpaid' => 'bg-danger',
    'partial' => 'bg-warning',
    'partially_paid' => 'bg-warning',
    'paid' => 'bg-success',
    'adjusted' => 'bg-info'
];

// Check if purchase invoice ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: purchase_invoices.php?error=" . urlencode("Purchase invoice ID is required"));
    exit();
}

$invoice_id = $_GET['id'];

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

// Get purchase invoice details with returned amount calculation
$query = "SELECT pi.*, 
          pi.total_amount as original_total,
          (SELECT COALESCE(SUM(pr.total_amount), 0) FROM purchase_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved') as returned_amount,
          (pi.total_amount - COALESCE((SELECT SUM(pr.total_amount) FROM purchase_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved'), 0)) as adjusted_total,
          s.supplier_name, s.supplier_code, s.contact_person, s.phone, s.email, s.address as supplier_address,
          l.location_name, fy.year_code as financial_year,
          created.username as created_by_name,
          updated.username as updated_by_name
          FROM purchase_invoices pi
          LEFT JOIN suppliers s ON pi.supplier_id = s.id
          LEFT JOIN inventory_locations l ON pi.location_id = l.id
          LEFT JOIN financial_years fy ON pi.financial_year_id = fy.id
          LEFT JOIN users created ON pi.created_by = created.id
          LEFT JOIN users updated ON pi.updated_by = updated.id
          WHERE pi.id = :invoice_id";

try {
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':invoice_id', $invoice_id);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        header("Location: purchase_invoices.php?error=" . urlencode("Purchase invoice not found"));
        exit();
    }
    
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get purchase invoice items
    $items_query = "SELECT pii.*, p.product_name, p.product_code, p.barcode, 
                   p.purchase_uom_id, p.base_uom_id, 
                   COALESCE(uom.uom_name, 'Unit') as unit_name,
                   COALESCE(uom.uom_symbol, '') as unit_symbol
                   FROM purchase_invoice_items pii
                   LEFT JOIN products p ON pii.product_id = p.id
                   LEFT JOIN uom ON COALESCE(p.purchase_uom_id, pii.uom_id, p.base_uom_id) = uom.id
                   WHERE pii.purchase_invoice_id = :invoice_id
                   ORDER BY pii.id ASC";
    
    $items_stmt = $conn->prepare($items_query);
    $items_stmt->bindParam(':invoice_id', $invoice_id);
    $items_stmt->execute();
    $invoice_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get related purchase order if exists
    $purchase_order = null;
    if (!empty($invoice['purchase_order_id'])) {
        $po_query = "SELECT * FROM purchase_orders WHERE id = :po_id";
        $po_stmt = $conn->prepare($po_query);
        $po_stmt->bindParam(':po_id', $invoice['purchase_order_id']);
        $po_stmt->execute();
        $purchase_order = $po_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get related GRN if exists
    $grn = null;
    if (!empty($invoice['grn_id'])) {
        $grn_query = "SELECT * FROM goods_receiving_notes WHERE id = :grn_id";
        $grn_stmt = $conn->prepare($grn_query);
        $grn_stmt->bindParam(':grn_id', $invoice['grn_id']);
        $grn_stmt->execute();
        $grn = $grn_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get related purchase returns and credit notes
    $returns_query = "SELECT pr.*, cn.note_number, cn.amount as credit_note_amount, cn.status as credit_note_status 
                     FROM purchase_returns pr
                     LEFT JOIN credit_notes cn ON pr.id = cn.return_id
                     WHERE pr.invoice_id = :invoice_id AND pr.status = 'approved'
                     ORDER BY pr.created_at DESC";
    $returns_stmt = $conn->prepare($returns_query);
    $returns_stmt->bindParam(':invoice_id', $invoice_id);
    $returns_stmt->execute();
    $purchase_returns = $returns_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get supplier refunds (credit note adjustments) for this invoice
    $refunds_query = "SELECT sr.* FROM supplier_refunds sr 
                     WHERE sr.invoice_id = :invoice_id AND sr.status = 'completed'
                     ORDER BY sr.refund_date DESC";
    $refunds_stmt = $conn->prepare($refunds_query);
    $refunds_stmt->bindParam(':invoice_id', $invoice_id);
    $refunds_stmt->execute();
    $supplier_refunds = $refunds_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    header("Location: purchase_invoices.php?error=" . urlencode("Error: " . $e->getMessage()));
    exit();
}

// Status badge colors
$status_colors = [
    'draft' => 'bg-secondary',
    'approved' => 'bg-success',
    'active' => 'bg-success',
    'cancelled' => 'bg-danger'
];

// Payment status badge colors
$payment_status_colors = [
    'unpaid' => 'bg-danger',
    'partial' => 'bg-warning',
    'paid' => 'bg-success'
];

// Set page title
$page_title = "Purchase Invoice Details: " . $invoice['invoice_number'];

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
                    <h2><i class="fas fa-file-invoice-dollar"></i> Purchase Invoice Details</h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="purchase_invoices.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Purchase Invoices
                    </a>
                    
                    <?php if ($invoice['status'] == 'draft' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_edit']))): ?>
                    <a href="purchase_invoice_create.php?id=<?php echo $invoice_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <?php endif; ?>
                    
                    <button class="btn btn-info" onclick="printPurchaseInvoice()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    
                    <?php if ($invoice['status'] != 'cancelled' && $invoice['status'] != 'returned' && $invoice['payment_status'] != 'paid' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['payment_add']))): ?>
                    <a href="../payment/payment_create.php?invoice_id=<?php echo $invoice_id; ?>&type=purchase" class="btn btn-success">
                        <i class="fas fa-money-bill-wave"></i> Record Payment
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($invoice['status'] != 'cancelled' && $invoice['payment_status'] != 'paid' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_cancel']))): ?>
                    <button class="btn btn-danger" onclick="confirmCancelInvoice(<?php echo $invoice_id; ?>)">
                        <i class="fas fa-ban"></i> Cancel Invoice
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Invoice Information</h5>
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
                                    <th>Status:</th>
                                    <td>
                                        <?php
                                        $status = strtolower($invoice['status']);
                                        $badge_class = isset($status_colors[$status]) ? $status_colors[$status] : 'bg-secondary';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo ucfirst($invoice['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Payment Status:</th>
                                    <td>
                                        <?php
                                        // Use the stored payment status for now, we'll update it later after calculations
                                        $payment_status = strtolower($invoice['payment_status']);
                                        $payment_badge_class = isset($payment_status_colors[$payment_status]) ? $payment_status_colors[$payment_status] : 'bg-secondary';
                                        ?>
                                        <span class="badge <?php echo $payment_badge_class; ?>">
                                            <?php echo ucfirst($invoice['payment_status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Invoice Date:</th>
                                    <td><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Due Date:</th>
                                    <td>
                                        <?php 
                                        if (!empty($invoice['due_date'])) {
                                            echo date('d M Y', strtotime($invoice['due_date']));
                                        } else {
                                            echo '<span class="text-muted">Not specified</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Financial Year:</th>
                                    <td><?php echo htmlspecialchars($invoice['financial_year'] ?? 'Not specified'); ?></td>
                                </tr>
                                <tr>
                                    <th>Location:</th>
                                    <td><?php echo htmlspecialchars($invoice['location_name'] ?? 'Not specified'); ?></td>
                                </tr>
                                <?php if (!empty($invoice['purchase_order_id']) && $purchase_order): ?>
                                <tr>
                                    <th>Purchase Order:</th>
                                    <td>
                                        <a href="purchase_order_detail.php?id=<?php echo $invoice['purchase_order_id']; ?>">
                                            <?php echo htmlspecialchars($purchase_order['order_number']); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php if (!empty($invoice['grn_id']) && $grn): ?>
                                <tr>
                                    <th>GRN:</th>
                                    <td>
                                        <a href="purchase_receipt_detail.php?id=<?php echo $invoice['grn_id']; ?>">
                                            <?php echo htmlspecialchars($grn['grn_number']); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="30%">Supplier:</th>
                                    <td>
                                        <?php echo htmlspecialchars($invoice['supplier_name']); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($invoice['supplier_code']); ?></small>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Contact Person:</th>
                                    <td><?php echo htmlspecialchars($invoice['contact_person']); ?></td>
                                </tr>
                                <tr>
                                    <th>Phone:</th>
                                    <td><?php echo htmlspecialchars($invoice['phone']); ?></td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td><?php echo htmlspecialchars($invoice['email']); ?></td>
                                </tr>
                                <tr>
                                    <th>Payment Terms:</th>
                                    <td><?php echo htmlspecialchars($invoice['payment_terms'] ?? 'Not specified'); ?></td>
                                </tr>
                                <tr>
                                    <th>Created By:</th>
                                    <td><?php echo htmlspecialchars($invoice['created_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Created At:</th>
                                    <td><?php echo date('d M Y H:i', strtotime($invoice['created_at'])); ?></td>
                                </tr>
                                <?php if (!empty($invoice['updated_by_name'])): ?>
                                <tr>
                                    <th>Last Updated By:</th>
                                    <td><?php echo htmlspecialchars($invoice['updated_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Last Updated At:</th>
                                    <td><?php echo date('d M Y H:i', strtotime($invoice['updated_at'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Invoice Items</h5>
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
                                    <th>Unit</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Discount %</th>
                                    <th class="text-end">Tax %</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoice_items as $index => $item): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($item['product_name']); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['barcode']); ?></td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['quantity']); ?></td>
                                    <td><?php echo htmlspecialchars($item['unit_name'] ?? ''); ?></td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['unit_price']); ?></td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['discount_percent']); ?>%</td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['tax_rate']); ?>%</td>
                                    <td class="text-end"><?php echo formatNumber($conn, $item['total']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="8" class="text-end">Subtotal:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $invoice['subtotal']); ?></td>
                                </tr>
                                <tr>
                                    <th colspan="8" class="text-end">Discount Amount:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $invoice['discount_amount']); ?></td>
                                </tr>
                                <tr>
                                    <th colspan="8" class="text-end">Tax Amount:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $invoice['tax_amount']); ?></td>
                                </tr>
                                <?php if (isset($invoice['delivery_charges']) && $invoice['delivery_charges'] > 0): ?>
                                <tr>
                                    <th colspan="8" class="text-end">Delivery Charges:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $invoice['delivery_charges']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($invoice['returned_amount'] > 0): ?>
                                <tr>
                                    <th colspan="8" class="text-end">Original Total:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $invoice['original_total']); ?></td>
                                </tr>
                                <tr>
                                    <th colspan="8" class="text-end">Returned Amount:</th>
                                    <td class="text-end text-danger">(<?php echo formatNumber($conn, $invoice['returned_amount']); ?>)</td>
                                </tr>
                                <tr>
                                    <th colspan="8" class="text-end">Adjusted Total:</th>
                                    <td class="text-end"><strong><?php echo formatNumber($conn, $invoice['adjusted_total']); ?></strong></td>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <th colspan="8" class="text-end">Total Amount:</th>
                                    <td class="text-end"><strong><?php echo formatNumber($conn, $invoice['total_amount']); ?></strong></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th colspan="8" class="text-end">Paid Amount:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $invoice['paid_amount']); ?></td>
                                </tr>
                                <?php 
                                // Calculate total credit note adjustments from purchase returns
                                $credit_note_total = 0;
                                foreach ($purchase_returns as $return) {
                                    if (!empty($return['credit_note_amount'])) {
                                        $credit_note_total += $return['credit_note_amount'];
                                    }
                                }
                                
                                // Calculate total supplier refunds (credit note adjustments)
                                $supplier_refund_total = 0;
                                foreach ($supplier_refunds as $refund) {
                                    $supplier_refund_total += $refund['amount'];
                                }
                                
                                // Total credit note adjustments
                                $total_credit_note_adjustments = $credit_note_total + $supplier_refund_total;
                                
                                // We should only count direct payments as 'Paid Amount'
                                // Credit notes and supplier refunds are shown separately
                                $total_paid = $invoice['paid_amount'];
                                
                                // Add debug info
                                error_log("Invoice: " . $invoice_id . ", Original: " . $invoice['original_total'] . ", Returned: " . $invoice['returned_amount'] . ", Paid: " . $invoice['paid_amount'] . ", Credit Notes: " . $total_credit_note_adjustments);
                                
                                // Display credit note adjustments from purchase returns
                                if ($credit_note_total > 0):
                                ?>
                                <tr>
                                    <th colspan="8" class="text-end">Credit Notes from Returns:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $credit_note_total); ?></td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php if ($supplier_refund_total > 0): ?>
                                <tr>
                                    <th colspan="8" class="text-end">Credit Note Adjustments:</th>
                                    <td class="text-end"><?php echo formatNumber($conn, $supplier_refund_total); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php
                                // Calculate the actual total amount paid
                                // For invoices with returns, only show direct payments
                                // For regular invoices, show direct payments + credit note adjustments
                                if ($invoice['returned_amount'] > 0) {
                                    $actual_total_paid = $total_paid;
                                } else {
                                    $actual_total_paid = $total_paid + $total_credit_note_adjustments;
                                }
                                ?>
                                <tr>
                                    <th colspan="8" class="text-end">Total Paid Amount:</th>
                                    <td class="text-end"><strong><?php echo formatNumber($conn, $actual_total_paid); ?></strong></td>
                                </tr>
                                <tr>
                                    <th colspan="8" class="text-end">Balance Amount:</th>
                                    <?php 
                                    // Calculate the balance correctly based on the adjusted total
                                    // For invoices with returns, we use the adjusted total
                                    // For invoices with returns, we only subtract direct payments (not credit notes)
                                    if ($invoice['returned_amount'] > 0) {
                                        // For invoices with returns, use adjusted_total and only direct payments
                                        $balance = $invoice['adjusted_total'] - $total_paid;
                                    } else {
                                        // For regular invoices, use total_amount and subtract both payments and credit notes
                                        $balance = $invoice['total_amount'] - $actual_total_paid;
                                    }
                                    $balance_class = $balance < 0 ? 'text-danger' : '';
                                    
                                    // Now that we have all the necessary variables, recalculate the payment status
                                    if ($invoice['returned_amount'] > 0) {
                                        // For invoices with returns, only consider direct payments
                                        if ($invoice['adjusted_total'] == 0) {
                                            // If fully returned (adjusted total is 0), mark as 'adjusted'
                                            $calculated_payment_status = 'adjusted';
                                        } else if ($total_paid >= $invoice['adjusted_total']) {
                                            // If paid in full with direct payments
                                            $calculated_payment_status = 'paid';
                                        } else if ($total_paid > 0) {
                                            // If partially paid with direct payments
                                            $calculated_payment_status = 'partial';
                                        } else {
                                            // If not paid at all
                                            $calculated_payment_status = 'unpaid';
                                        }
                                    } else {
                                        // For regular invoices without returns, consider both payments and credit notes
                                        if ($actual_total_paid >= $invoice['total_amount']) {
                                            $calculated_payment_status = 'paid';
                                        } else if ($actual_total_paid > 0) {
                                            $calculated_payment_status = 'partial';
                                        } else {
                                            $calculated_payment_status = 'unpaid';
                                        }
                                    }
                                    
                                    // Update the payment status in the database if it's different from the calculated status
                                    if (strtolower($calculated_payment_status) != strtolower($invoice['payment_status'])) {
                                        $update_status_query = "UPDATE purchase_invoices SET payment_status = :payment_status WHERE id = :invoice_id";
                                        $update_status_stmt = $conn->prepare($update_status_query);
                                        $update_status_stmt->bindParam(':payment_status', $calculated_payment_status);
                                        $update_status_stmt->bindParam(':invoice_id', $invoice_id);
                                        $update_status_stmt->execute();
                                        
                                        // Update the displayed payment status
                                        $invoice['payment_status'] = $calculated_payment_status;
                                    }
                                    
                                    // Update the badge class
                                    $payment_status = strtolower($invoice['payment_status']);
                                    $payment_badge_class = isset($payment_status_colors[$payment_status]) ? $payment_status_colors[$payment_status] : 'bg-secondary';
                                    ?>
                                    <td class="text-end <?php echo $balance_class; ?>"><strong><?php echo formatNumber($conn, $balance); ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($purchase_returns)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Returns & Credit Notes</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Return Number</th>
                                    <th>Return Date</th>
                                    <th>Return Amount</th>
                                    <th>Credit Note</th>
                                    <th>Credit Note Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($purchase_returns as $return): ?>
                                <tr>
                                    <td>
                                        <a href="purchase_return_detail.php?id=<?php echo $return['id']; ?>">
                                            <?php echo htmlspecialchars($return['return_number']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($return['return_date'])); ?></td>
                                    <td class="text-end"><?php echo formatNumber($conn, $return['total_amount']); ?></td>
                                    <td>
                                        <?php if (!empty($return['note_number'])): ?>
                                            <?php echo htmlspecialchars($return['note_number']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">No credit note</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!empty($return['credit_note_amount'])): ?>
                                            <?php echo formatNumber($conn, $return['credit_note_amount']); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($return['credit_note_status'])): ?>
                                            <span class="badge bg-<?php echo $return['credit_note_status'] == 'active' ? 'success' : ($return['credit_note_status'] == 'used' ? 'info' : 'secondary'); ?>">
                                                <?php echo ucfirst($return['credit_note_status']); ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="purchase_return_detail.php?id=<?php echo $return['id']; ?>" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($supplier_refunds)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Credit Note Adjustments</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Reference Number</th>
                                    <th>Refund Date</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Notes</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($supplier_refunds as $refund): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($refund['reference_number']); ?></td>
                                    <td><?php echo date('d M Y', strtotime($refund['refund_date'])); ?></td>
                                    <td class="text-end"><?php echo formatNumber($conn, $refund['amount']); ?></td>
                                    <td><?php echo htmlspecialchars($refund['payment_method']); ?></td>
                                    <td><?php echo htmlspecialchars($refund['notes'] ?? ''); ?></td>
                                    <td>
                                        <span class="badge <?php echo $refund['status'] == 'completed' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($refund['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($invoice['notes'])): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Notes</h5>
                </div>
                <div class="card-body">
                    <p><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>



<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Initialize any plugins or event handlers here
});

function confirmCancelInvoice(invoiceId) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You are about to cancel this purchase invoice. This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, cancel it!',
        cancelButtonText: 'No, keep it'
    }).then((result) => {
        if (result.isConfirmed) {
            // Show notes input dialog
            Swal.fire({
                title: 'Cancellation Notes',
                input: 'textarea',
                inputPlaceholder: 'Enter reason for cancellation (optional)',
                showCancelButton: true,
                confirmButtonText: 'Proceed with Cancellation',
                cancelButtonText: 'Back',
                showLoaderOnConfirm: true,
                preConfirm: (notes) => {
                    return cancelPurchaseInvoice(invoiceId, notes);
                }
            });
        }
    });
}

function cancelPurchaseInvoice(invoiceId, notes) {
    return $.ajax({
        url: 'actions/cancel_purchase_invoice.php',
        type: 'POST',
        data: {
            invoice_id: invoiceId,
            notes: notes || 'Invoice cancelled by user'
        },
        dataType: 'json'
    })
    .done(function(response) {
        if (response.success) {
            Swal.fire({
                title: 'Cancelled!',
                text: response.message,
                icon: 'success'
            }).then(() => {
                // Reload the page to show updated status
                location.reload();
            });
        } else {
            Swal.fire({
                title: 'Error!',
                text: response.message,
                icon: 'error'
            });
        }
    })
    .fail(function(xhr, status, error) {
        Swal.fire({
            title: 'Error!',
            text: 'An error occurred while cancelling the invoice: ' + error,
            icon: 'error'
        });
    });
}

function printPurchaseInvoice() {
    // Clone the print section
    const printContent = document.getElementById('print-section').innerHTML;
    
    // Create a new window
    const printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Purchase Invoice: <?php echo htmlspecialchars($invoice["invoice_number"]); ?></title>');
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
            .text-right {
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
        }
    </style>
    
    <div class="container">
        <!-- Header with Company and Invoice Info -->
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
                <h2>PURCHASE INVOICE</h2>
                <div class="print-subtitle">
                    <div><strong>Invoice #:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                    <div><strong>Date:</strong> <?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></div>
                    <?php if (!empty($invoice['due_date'])): ?>
                    <div><strong>Due Date:</strong> <?php echo date('d M Y', strtotime($invoice['due_date'])); ?></div>
                    <?php endif; ?>
                    <div><strong>Status:</strong> <?php echo ucfirst($invoice['status']); ?></div>
                    <div><strong>Payment Status:</strong> <?php echo ucfirst($invoice['payment_status']); ?></div>
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
                            <strong><?php echo htmlspecialchars($invoice['supplier_name']); ?></strong> (<?php echo htmlspecialchars($invoice['supplier_code']); ?>)<br>
                            <?php if (!empty($invoice['supplier_address'])): ?>
                            <?php echo nl2br(htmlspecialchars($invoice['supplier_address'])); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($invoice['contact_person'])): ?>
                            Contact: <?php echo htmlspecialchars($invoice['contact_person']); ?><br>
                            <?php endif; ?>
                        </td>
                        <td style="width: 50%; vertical-align: top;">
                            <?php if (!empty($invoice['phone'])): ?>
                            Phone: <?php echo htmlspecialchars($invoice['phone']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($invoice['email'])): ?>
                            Email: <?php echo htmlspecialchars($invoice['email']); ?><br>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Invoice Items -->
        <div class="row mb-4">
            <div class="col-12 print-box">
                <div class="print-box-title">Invoice Items</div>
                <table class="print-table">
                    <thead>
                        <tr>
                            <th style="width: 5%">#</th>
                            <th style="width: 35%">Product</th>
                            <th style="width: 10%">Barcode</th>
                            <th style="width: 10%; text-align: right">Quantity</th>
                            <th style="width: 5%">Unit</th>
                            <th style="width: 10%; text-align: right">Unit Price</th>
                            <th style="width: 5%; text-align: right">Disc %</th>
                            <th style="width: 5%; text-align: right">Tax %</th>
                            <th style="width: 15%; text-align: right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        foreach ($invoice_items as $item): 
                        ?>
                        <tr>
                            <td style="text-align: center"><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?>
                                <?php if (!empty($item['product_code'])): ?>
                                <small class="text-muted d-block"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($item['barcode'] ?? ''); ?></td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['quantity']); ?></td>
                            <td><?php 
                                $unitDisplay = htmlspecialchars($item['unit_name'] ?? 'Unit');
                                if (!empty($item['unit_symbol'])) {
                                    $unitDisplay .= ' (' . htmlspecialchars($item['unit_symbol']) . ')';
                                }
                                echo $unitDisplay;
                            ?></td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['unit_price']); ?></td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['discount_percent'] ?? 0); ?>%</td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['tax_rate']); ?>%</td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['total'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Totals -->
                <table style="width: 40%; margin-left: 60%; margin-top: 20px;">
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Subtotal:</strong></td>
                        <td style="text-align: right; padding: 5px;"><?php echo formatNumber($conn, $invoice['subtotal']); ?></td>
                    </tr>
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Discount Amount:</strong></td>
                        <td style="text-align: right; padding: 5px;"><?php echo formatNumber($conn, $invoice['discount_amount']); ?></td>
                    </tr>
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Tax Amount:</strong></td>
                        <td style="text-align: right; padding: 5px;"><?php echo formatNumber($conn, $invoice['tax_amount']); ?></td>
                    </tr>
                    <?php if (isset($invoice['delivery_charges']) && $invoice['delivery_charges'] > 0): ?>
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Delivery Charges:</strong></td>
                        <td style="text-align: right; padding: 5px;"><?php echo formatNumber($conn, $invoice['delivery_charges']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($invoice['returned_amount'] > 0): ?>
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Original Total:</strong></td>
                        <td style="text-align: right; padding: 5px;"><?php echo formatNumber($conn, $invoice['original_total']); ?></td>
                    </tr>
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Returned Amount:</strong></td>
                        <td style="text-align: right; padding: 5px; color: #dc3545;">(<?php echo formatNumber($conn, $invoice['returned_amount']); ?>)</td>
                    </tr>
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Adjusted Total:</strong></td>
                        <td style="text-align: right; padding: 5px;"><strong><?php echo formatNumber($conn, $invoice['adjusted_total']); ?></strong></td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Total Amount:</strong></td>
                        <td style="text-align: right; padding: 5px;"><strong><?php echo formatNumber($conn, $invoice['total_amount']); ?></strong></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Paid Amount:</strong></td>
                        <td style="text-align: right; padding: 5px;"><?php echo formatNumber($conn, $invoice['paid_amount']); ?></td>
                    </tr>
                    <?php if ($credit_note_total > 0): ?>
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Credit Notes from Returns:</strong></td>
                        <td style="text-align: right; padding: 5px;"><?php echo formatNumber($conn, $credit_note_total); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if ($supplier_refund_total > 0): ?>
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Credit Note Adjustments:</strong></td>
                        <td style="text-align: right; padding: 5px;"><?php echo formatNumber($conn, $supplier_refund_total); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr>
                        <td style="text-align: right; padding: 5px;"><strong>Total Paid Amount:</strong></td>
                        <td style="text-align: right; padding: 5px;"><strong><?php echo formatNumber($conn, $actual_total_paid); ?></strong></td>
                    </tr>
                    
                    <tr>
                        <td style="text-align: right; padding: 5px; border-top: 1px solid #ddd;"><strong>Balance Amount:</strong></td>
                        <td style="text-align: right; padding: 5px; border-top: 1px solid #ddd;">
                            <strong><?php echo formatNumber($conn, $balance); ?></strong>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <?php if (!empty($invoice['notes'])): ?>
        <div class="row mb-4">
            <div class="col-12 print-box">
                <div class="print-box-title">Notes</div>
                <p><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Footer -->
        <div class="row" style="margin-top: 50px;">
            <div class="col-4" style="float: left; width: 33%; text-align: center;">
                <div style="border-top: 1px solid #000; padding-top: 10px; margin-top: 40px;">Prepared By</div>
            </div>
            <div class="col-4" style="float: left; width: 33%; text-align: center;">
                <div style="border-top: 1px solid #000; padding-top: 10px; margin-top: 40px;">Approved By</div>
            </div>
            <div class="col-4" style="float: left; width: 33%; text-align: center;">
                <div style="border-top: 1px solid #000; padding-top: 10px; margin-top: 40px;">Received By</div>
            </div>
        </div>
    </div>
</div>
