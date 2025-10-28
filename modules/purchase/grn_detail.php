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

// Get company information
$company = getCompanyInfo($conn);

// Check if GRN ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: goods_receiving_notes.php");
    exit();
}

$grn_id = $_GET['id'];

// Get GRN details
$query = "SELECT grn.*, po.order_number, po.order_date, po.expected_delivery_date, po.notes as po_notes,
          s.supplier_name, s.supplier_code, s.contact_person, s.phone, s.email, s.address,
          u.username as created_by_name
          FROM goods_receiving_notes grn
          LEFT JOIN purchase_orders po ON grn.purchase_order_id = po.id
          LEFT JOIN suppliers s ON po.supplier_id = s.id
          LEFT JOIN users u ON grn.created_by = u.id
          WHERE grn.id = :grn_id";

try {
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':grn_id', $grn_id);
    $stmt->execute();
    $grn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$grn) {
        echo "<div class='alert alert-danger'>Goods Receiving Note not found.</div>";
        exit();
    }
    
    // Get GRN items with product information
    $itemsQuery = "SELECT grni.*, p.product_name, p.product_code, p.barcode, 
                  p.purchase_uom_id, p.base_uom_id,
                  COALESCE(grni.tax_rate, 0) as tax_rate,
                  COALESCE(grni.discount_percent, 0) as discount_percent,
                  COALESCE(uom.uom_name, 'Unit') as unit_name,
                  COALESCE(uom.uom_symbol, '') as unit_symbol
                  FROM goods_receiving_note_items grni
                  JOIN products p ON grni.product_id = p.id
                  LEFT JOIN uom ON COALESCE(p.purchase_uom_id, grni.uom_id, p.base_uom_id) = uom.id
                  WHERE grni.grn_id = :grn_id";
    $itemsStmt = $conn->prepare($itemsQuery);
    $itemsStmt->bindParam(':grn_id', $grn_id);
    $itemsStmt->execute();
    $grn_items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Use financial values directly from the GRN record
    $subtotal = $grn['subtotal'] ?? 0;
    $tax_amount = $grn['tax_amount'] ?? 0;
    $discount_amount = $grn['discount_amount'] ?? 0;
    $delivery_charges = $grn['delivery_charges'] ?? 0;
    $total = $grn['total_amount'] ?? 0;
    
    // If total_amount is not available or zero, calculate it
    if (empty($total)) {
        // Calculate totals from items as fallback
        $calculated_subtotal = 0;
        foreach ($grn_items as $item) {
            $calculated_subtotal += $item['total'];
        }
        $total = $calculated_subtotal + $delivery_charges;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching GRN details: " . $e->getMessage());
    echo "<div class='alert alert-danger'>Error fetching GRN details: " . $e->getMessage() . "</div>";
    exit();
}

// Set page title
$page_title = "GRN Details: " . $grn['grn_number'];

// Status badge colors
$status_colors = [
    'completed' => 'bg-success',
    'cancelled' => 'bg-danger'
];

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
                    <h2><i class="fas fa-clipboard-check"></i> Goods Receiving Note Details</h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="goods_receiving_notes.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to GRN List
                    </a>
                    
                    <button class="btn btn-info" onclick="printGRN()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    
                    <?php if ($grn['status'] == 'completed' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_add']))): ?>
                    <a href="purchase_invoice_from_grn.php?grn_id=<?php echo $grn_id; ?>" class="btn btn-success">
                        <i class="fas fa-file-invoice-dollar"></i> Create Invoice
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($grn['status'] != 'cancelled' && $grn['invoice_status'] == 'pending' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_delete']))): ?>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelGRNModal">
                        <i class="fas fa-ban"></i> Cancel GRN
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h5 class="card-title mb-0">GRN Information</h5>
                        </div>
                        <div class="col-md-6 text-end">
                            <span class="badge <?php echo $status_colors[$grn['status']]; ?> fs-6">
                                <?php echo ucfirst($grn['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="35%">GRN Number:</th>
                                    <td><?php echo htmlspecialchars($grn['grn_number']); ?></td>
                                </tr>
                                <tr>
                                    <th>Purchase Order:</th>
                                    <td>
                                        <a href="purchase_order_detail.php?id=<?php echo $grn['purchase_order_id']; ?>">
                                            <?php echo htmlspecialchars($grn['order_number']); ?>
                                        </a>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Supplier:</th>
                                    <td>
                                        <?php echo htmlspecialchars($grn['supplier_name']); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($grn['supplier_code']); ?></small>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Contact Person:</th>
                                    <td><?php echo htmlspecialchars($grn['contact_person']); ?></td>
                                </tr>
                                <tr>
                                    <th>Phone:</th>
                                    <td><?php echo htmlspecialchars($grn['phone']); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="35%">Received Date:</th>
                                    <td><?php echo date('d M Y', strtotime($grn['received_date'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Order Date:</th>
                                    <td><?php echo date('d M Y', strtotime($grn['order_date'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Expected Delivery:</th>
                                    <td>
                                        <?php 
                                        if (!empty($grn['expected_delivery_date'])) {
                                            echo date('d M Y', strtotime($grn['expected_delivery_date']));
                                        } else {
                                            echo '<span class="text-muted">Not specified</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Created By:</th>
                                    <td><?php echo htmlspecialchars($grn['created_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Created At:</th>
                                    <td><?php echo date('d M Y H:i', strtotime($grn['created_at'])); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <?php if (!empty($grn['notes'])): ?>
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-subtitle mb-2 text-muted">Notes</h6>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($grn['notes'])); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Received Items</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th width="3%">#</th>
                                <th width="20%">Product</th>
                                <th width="8%" class="text-center">Qty Ordered</th>
                                <th width="8%" class="text-center">Qty Received</th>
                                <th width="7%" class="text-center">UOM</th>
                                <th width="8%">Batch #</th>
                                <th width="8%">Expiry Date</th>
                                <th width="8%" class="text-end">Unit Price</th>
                                <th width="6%" class="text-center">Tax %</th>
                                <th width="6%" class="text-center">Disc %</th>
                                <th width="10%" class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grn_items as $index => $item): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($item['product_name']); ?>
                                    <small class="d-block text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                    <?php if (!empty($item['notes'])): ?>
                                    <small class="d-block text-muted"><em>Note: <?php echo htmlspecialchars($item['notes']); ?></em></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo formatNumber($conn, $item['quantity_ordered']); ?></td>
                                <td class="text-center"><?php echo formatNumber($conn, $item['quantity_received']); ?></td>
                                <td class="text-center">
                                    <?php if (!empty($item['unit_name'])): ?>
                                        <?php echo htmlspecialchars($item['unit_name']); ?>
                                        <?php if (!empty($item['unit_symbol'])): ?>
                                            (<?php echo htmlspecialchars($item['unit_symbol']); ?>)
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($item['batch_number'] ?? '-'); ?></td>
                                <td><?php echo !empty($item['expiry_date']) ? date('d M Y', strtotime($item['expiry_date'])) : '-'; ?></td>
                                <td class="text-end"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $item['unit_price']); ?></td>
                                <td class="text-center"><?php echo formatNumber($conn, $item['tax_rate']); ?>%</td>
                                <td class="text-center"><?php echo formatNumber($conn, $item['discount_percent']); ?>%</td>
                                <td class="text-end"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $item['total']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            </tbody>
                        </table>
                        
                        <!-- Order Summary -->
                        <div class="row mt-3">
                            <div class="col-md-6 ms-auto">
                                <table class="table table-sm">
                                    <tr>
                                        <td class="text-end"><strong>Subtotal:</strong></td>
                                        <td class="text-end" width="150"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $subtotal); ?></td>
                                    </tr>
                                    <?php if ($tax_amount > 0): ?>
                                    <tr>
                                        <td class="text-end"><strong>Tax:</strong></td>
                                        <td class="text-end"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $tax_amount); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($discount_amount > 0): ?>
                                    <tr>
                                        <td class="text-end"><strong>Discount:</strong></td>
                                        <td class="text-end"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $discount_amount); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($delivery_charges > 0): ?>
                                    <tr>
                                        <td class="text-end"><strong>Delivery Charges:</strong></td>
                                        <td class="text-end"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $delivery_charges); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td class="text-end"><strong>Total:</strong></td>
                                        <td class="text-end"><strong><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $total); ?></strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cancel GRN Modal -->
<div class="modal fade" id="cancelGRNModal" tabindex="-1" role="dialog" aria-labelledby="cancelGRNModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cancelGRNModalLabel">Cancel Goods Receiving Note</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel this GRN? This will reverse all inventory changes and cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form action="actions/cancel_grn.php" method="post">
                    <input type="hidden" name="grn_id" value="<?php echo $grn_id; ?>">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger">Cancel GRN</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Print Section (hidden by default) -->
<div id="print-section" class="d-none">
    <div class="container">
        <div class="print-header">
            <div style="float: left; width: 50%;">
                <?php if (!empty($company['logo_path'])): ?>
                <img src="../../<?php echo htmlspecialchars($company['logo_path']); ?>" alt="Company Logo" class="company-logo">
                <?php endif; ?>
                <div class="print-title"><?php echo htmlspecialchars($company['company_name'] ?? 'Solar Business'); ?></div>
                <div class="print-subtitle"><?php echo nl2br(htmlspecialchars($company['address'] ?? '')); ?></div>
                <div>Phone: <?php echo htmlspecialchars($company['phone'] ?? ''); ?></div>
                <div>Email: <?php echo htmlspecialchars($company['email'] ?? ''); ?></div>
            </div>
            <div style="float: right; width: 50%; text-align: right;">
                <div class="print-title">Goods Receiving Note</div>
                <div><strong>GRN #:</strong> <?php echo htmlspecialchars($grn['grn_number']); ?></div>
                <div><strong>Date:</strong> <?php echo date('d M Y', strtotime($grn['received_date'])); ?></div>
                <div><strong>PO #:</strong> <?php echo htmlspecialchars($grn['order_number']); ?></div>
                <div><strong>Status:</strong> <?php echo ucfirst($grn['status']); ?></div>
            </div>
            <div style="clear: both;"></div>
        </div>
        
        <div class="row">
            <div class="col-12">
                <table style="width: 100%; margin-bottom: 20px;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div class="print-box-title">Supplier Information</div>
                            <strong><?php echo htmlspecialchars($grn['supplier_name']); ?></strong><br>
                            <?php if (!empty($grn['supplier_code'])): ?>
                            Code: <?php echo htmlspecialchars($grn['supplier_code']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($grn['address'])): ?>
                            Address: <?php echo nl2br(htmlspecialchars($grn['address'])); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($grn['contact_person'])): ?>
                            Contact: <?php echo htmlspecialchars($grn['contact_person']); ?><br>
                            <?php endif; ?>
                        </td>
                        <td style="width: 50%; vertical-align: top;">
                            <?php if (!empty($grn['phone'])): ?>
                            Phone: <?php echo htmlspecialchars($grn['phone']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($grn['email'])): ?>
                            Email: <?php echo htmlspecialchars($grn['email']); ?><br>
                            <?php endif; ?>
                            <strong>Received Date:</strong> <?php echo date('d M Y', strtotime($grn['received_date'])); ?><br>
                            <strong>Order Date:</strong> <?php echo date('d M Y', strtotime($grn['order_date'])); ?><br>
                            <strong>Received By:</strong> <?php echo htmlspecialchars($grn['created_by_name']); ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-12 print-box">
                <div class="print-box-title">Received Items</div>
                <table class="print-table">
                    <thead>
                        <tr>
                            <th style="width: 3%">#</th>
                            <th style="width: 20%">Product</th>
                            <th style="width: 7%; text-align: center;">Qty Ordered</th>
                            <th style="width: 7%; text-align: center;">Qty Received</th>
                            <th style="width: 6%; text-align: center;">UOM</th>
                            <th style="width: 8%">Batch #</th>
                            <th style="width: 8%">Expiry Date</th>
                            <th style="width: 8%; text-align: right;">Unit Price</th>
                            <th style="width: 6%; text-align: center;">Tax %</th>
                            <th style="width: 6%; text-align: center;">Disc %</th>
                            <th style="width: 12%; text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grn_items as $index => $item): ?>
                        <tr>
                            <td style="text-align: center"><?php echo $index + 1; ?></td>
                            <td>
                                <?php echo htmlspecialchars($item['product_name']); ?>
                                <?php if (!empty($item['product_code'])): ?>
                                <small class="d-block"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($item['notes'])): ?>
                                <small class="d-block"><em>Note: <?php echo htmlspecialchars($item['notes']); ?></em></small>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['quantity_ordered']); ?></td>
                            <td style="text-align: right"><?php echo formatNumber($conn, $item['quantity_received']); ?></td>
                            <td style="text-align: center">
                                <?php 
                                if (!empty($item['unit_name'])) {
                                    echo htmlspecialchars($item['unit_name']);
                                    if (!empty($item['unit_symbol'])) {
                                        echo ' (' . htmlspecialchars($item['unit_symbol']) . ')';
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($item['batch_number'] ?? '-'); ?></td>
                            <td><?php echo !empty($item['expiry_date']) ? date('d M Y', strtotime($item['expiry_date'])) : '-'; ?></td>
                            <td style="text-align: right;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $item['unit_price']); ?></td>
                            <td style="text-align: center;"><?php echo formatNumber($conn, $item['tax_rate']); ?>%</td>
                            <td style="text-align: center;"><?php echo formatNumber($conn, $item['discount_percent']); ?>%</td>
                            <td style="text-align: right;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $item['total']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Order Summary -->
                <div style="width: 100%; margin-top: 20px;">
                    <table style="width: 100%; max-width: 350px; margin-left: auto; border-collapse: collapse;">
                        <tr>
                            <td style="width: 60%; padding: 5px; text-align: right;"><strong>Subtotal:</strong></td>
                            <td style="width: 40%; padding: 5px; text-align: right;">
                                <span style="white-space: nowrap;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $subtotal); ?></span>
                            </td>
                        </tr>
                        <?php if ($tax_amount > 0): ?>
                        <tr>
                            <td style="padding: 5px; text-align: right;"><strong>Tax:</strong></td>
                            <td style="padding: 5px; text-align: right;">
                                <span style="white-space: nowrap;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $tax_amount); ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($discount_amount > 0): ?>
                        <tr>
                            <td style="padding: 5px; text-align: right;"><strong>Discount:</strong></td>
                            <td style="padding: 5px; text-align: right;">
                                <span style="white-space: nowrap;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $discount_amount); ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($delivery_charges > 0): ?>
                        <tr>
                            <td style="padding: 5px; text-align: right;"><strong>Delivery Charges:</strong></td>
                            <td style="padding: 5px; text-align: right;">
                                <span style="white-space: nowrap;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $delivery_charges); ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr style="border-top: 1px solid #ddd">
                            <td style="padding: 5px; text-align: right;"><strong>Total Amount:</strong></td>
                            <td style="padding: 5px; text-align: right;">
                                <span style="white-space: nowrap; font-weight: bold;"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $total); ?></span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <?php if (!empty($grn['notes'])): ?>
        <div class="row mb-4">
            <div class="col-12 print-box">
                <div class="print-box-title">Notes</div>
                <p><?php echo nl2br(htmlspecialchars($grn['notes'])); ?></p>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="row" style="margin-top: 50px;">
            <div class="col-4" style="float: left; width: 33%; text-align: center;">
                <div style="border-top: 1px solid #333; padding-top: 5px; margin-top: 20px;">
                    Prepared By
                </div>
            </div>
            <div class="col-4" style="float: left; width: 33%; text-align: center;">
                <div style="border-top: 1px solid #333; padding-top: 5px; margin-top: 20px;">
                    Checked By
                </div>
            </div>
            <div class="col-4" style="float: left; width: 33%; text-align: center;">
                <div style="border-top: 1px solid #333; padding-top: 5px; margin-top: 20px;">
                    Approved By
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
function printGRN() {
    // Clone the print section
    const printContent = document.getElementById('print-section').innerHTML;
    
    // Create a new window for printing
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Goods Receiving Note: <?php echo htmlspecialchars($grn['grn_number']); ?></title>
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
                .d-block {
                    display: block;
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

<!-- Cancel GRN Modal -->
<div class="modal fade" id="cancelGRNModal" tabindex="-1" aria-labelledby="cancelGRNModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cancelGRNModalLabel">Cancel GRN</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/cancel_grn.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="grn_id" value="<?php echo $grn_id; ?>">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> Warning: Cancelling this GRN will reverse all inventory transactions and update the purchase order status. This action cannot be undone.
                    </div>
                    <div class="mb-3">
                        <label for="cancellation_reason" class="form-label">Reason for Cancellation <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
                </div>
            </form>
        </div>
    </div>
</div>
