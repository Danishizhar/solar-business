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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['purchase_add'])) {
    header("Location: ../../index.php");
    exit();
}

// Include database connection
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Include helper functions
require_once '../../includes/company_helpers.php';

// Initialize variables
$grn = [
    'id' => '',
    'grn_number' => '',
    'purchase_order_id' => '',
    'received_date' => date('Y-m-d'),
    'location_id' => '',
    'notes' => ''
];

// Get warehouse locations
$locationQuery = "SELECT id, location_name, location_code FROM inventory_locations WHERE status = 1 ORDER BY location_name";
try {
    $locationStmt = $conn->prepare($locationQuery);
    $locationStmt->execute();
    $locations = $locationStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $locations = [];
    error_log("Error fetching locations: " . $e->getMessage());
}

$purchase_order = [];
$purchase_order_items = [];
$received_items = [];
$page_title = "Create Goods Receiving Note";

// Check if PO ID is provided
$po_id = isset($_GET['po_id']) ? $_GET['po_id'] : '';

// Get confirmed and partial purchase orders for dropdown that don't have invoices created from them
$poQuery = "SELECT po.id, po.order_number, s.supplier_name, s.supplier_code
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.status IN ('confirmed', 'partial')
            AND NOT EXISTS (
                SELECT 1 FROM purchase_invoices pi 
                WHERE pi.purchase_order_id = po.id 
                AND pi.status != 'cancelled'
            )
            ORDER BY po.created_at DESC";
try {
    $poStmt = $conn->prepare($poQuery);
    $poStmt->execute();
    $purchase_orders = $poStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $purchase_orders = [];
    error_log("Error fetching purchase orders: " . $e->getMessage());
}

// If PO ID is provided, load purchase order details
if (!empty($po_id)) {
    $grn['purchase_order_id'] = $po_id;
    
    // Get purchase order details and check if it has any invoices
    $poDetailQuery = "SELECT po.*, s.supplier_name, s.supplier_code, s.contact_person, s.phone, s.email, s.address
                     FROM purchase_orders po
                     JOIN suppliers s ON po.supplier_id = s.id
                     WHERE po.id = :po_id AND po.status IN ('confirmed', 'partial')
                     AND NOT EXISTS (
                         SELECT 1 FROM purchase_invoices pi 
                         WHERE pi.purchase_order_id = po.id 
                         AND pi.status != 'cancelled'
                     )";
    try {
        $poDetailStmt = $conn->prepare($poDetailQuery);
        $poDetailStmt->bindParam(':po_id', $po_id);
        $poDetailStmt->execute();
        $purchase_order = $poDetailStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$purchase_order) {
            echo "<div class='alert alert-danger'>Purchase order not found or not in confirmed status.</div>";
            exit();
        }
        
        // Get purchase order items with received quantities and UOM information
        $poItemsQuery = "SELECT poi.*, p.product_name, p.product_code, p.barcode,
                         poi.received_quantity as quantity_received,
                         (poi.quantity - poi.received_quantity) as quantity_pending,
                         poi.uom_id, p.purchase_uom_id, p.base_uom_id,
                         COALESCE(poi.uom_id, p.purchase_uom_id, p.base_uom_id, 1) as effective_uom_id
                         FROM purchase_order_items poi
                         JOIN products p ON poi.product_id = p.id
                         WHERE poi.purchase_order_id = :po_id
                         HAVING quantity_pending > 0";
        $poItemsStmt = $conn->prepare($poItemsQuery);
        $poItemsStmt->bindParam(':po_id', $po_id);
        $poItemsStmt->execute();
        $purchase_order_items = $poItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate GRN number
        $grn['grn_number'] = 'GRN-' . date('Ymd') . '-' . rand(1000, 9999);
    } catch (PDOException $e) {
        error_log("Error fetching purchase order details: " . $e->getMessage());
        echo "<div class='alert alert-danger'>Error fetching purchase order details: " . $e->getMessage() . "</div>";
    }
}

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
                    <h2><i class="fas fa-clipboard-check"></i> Create Goods Receiving Note</h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="goods_receiving_notes.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to GRN List
                    </a>
                </div>
            </div>
            
            <form action="actions/save_grn.php" method="post" id="grnForm">
                <input type="hidden" name="grn_id" value="<?php echo $grn['id']; ?>">
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">GRN Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="grn_number" class="form-label">GRN Number</label>
                                    <input type="text" class="form-control" id="grn_number" name="grn_number" value="<?php echo !empty($grn['grn_number']) ? $grn['grn_number'] : 'GRN-' . date('Ymd') . '-' . rand(1000, 9999); ?>" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="purchase_order_id" class="form-label">Purchase Order</label>
                                    <select class="form-control select2" id="purchase_order_id" name="purchase_order_id" required <?php echo !empty($po_id) ? 'disabled' : ''; ?>>
                                        <option value="">Select Purchase Order</option>
                                        <?php foreach ($purchase_orders as $po): ?>
                                        <option value="<?php echo $po['id']; ?>" <?php echo ($po['id'] == $grn['purchase_order_id']) ? 'selected' : ''; ?>>
                                            <?php echo $po['order_number'] . ' - ' . $po['supplier_name'] . ' (' . $po['supplier_code'] . ')'; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($po_id)): ?>
                                    <input type="hidden" name="purchase_order_id" value="<?php echo $po_id; ?>">
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Purchase Order Details Section (initially hidden) -->
                        <div id="purchase_order_details" class="row mt-3 mb-3" style="display: <?php echo !empty($purchase_order) ? 'block' : 'none'; ?>">
                            <div class="col-12">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-subtitle mb-2 text-muted">Purchase Order Details</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Order Number:</strong> <span id="po_order_number"><?php echo htmlspecialchars($purchase_order['order_number'] ?? ''); ?></span></p>
                                                <p class="mb-1"><strong>Supplier:</strong> <span id="po_supplier_name"><?php echo htmlspecialchars($purchase_order['supplier_name'] ?? ''); ?></span></p>
                                                <p class="mb-1"><strong>Contact:</strong> <span id="po_contact_person"><?php echo htmlspecialchars($purchase_order['contact_person'] ?? ''); ?></span></p>
                                                <p class="mb-0"><strong>Phone:</strong> <span id="po_phone"><?php echo htmlspecialchars($purchase_order['phone'] ?? ''); ?></span></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Order Date:</strong> <span id="po_order_date"><?php echo !empty($purchase_order['order_date']) ? date('d M Y', strtotime($purchase_order['order_date'])) : ''; ?></span></p>
                                                <p class="mb-1"><strong>Expected Delivery:</strong> <span id="po_expected_delivery"><?php echo !empty($purchase_order['expected_delivery_date']) ? date('d M Y', strtotime($purchase_order['expected_delivery_date'])) : 'Not specified'; ?></span></p>
                                                <p class="mb-1"><strong>Email:</strong> <span id="po_email"><?php echo htmlspecialchars($purchase_order['email'] ?? ''); ?></span></p>
                                                <p class="mb-0"><strong>Address:</strong> <span id="po_address"><?php echo htmlspecialchars($purchase_order['address'] ?? ''); ?></span></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="received_date" class="form-label">Received Date</label>
                                    <input type="date" class="form-control" id="received_date" name="received_date" value="<?php echo $grn['received_date']; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="location_id" class="form-label">Warehouse Location</label>
                                    <select class="form-control select2" id="location_id" name="location_id" required>
                                        <option value="">Select Location</option>
                                        <?php foreach ($locations as $location): ?>
                                        <option value="<?php echo $location['id']; ?>" <?php echo ($location['id'] == $grn['location_id']) ? 'selected' : ''; ?>>
                                            <?php echo $location['location_name'] . ' (' . $location['location_code'] . ')'; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo $grn['notes']; ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Items to Receive -->
                <div id="items_section" class="card mb-4" style="display: <?php echo !empty($purchase_order_items) ? 'block' : 'none'; ?>">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Items to Receive</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="receive_items_table">
                                <thead>
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="20%">Product</th>
                                        <th width="8%">Ordered</th>
                                        <th width="8%">Prev. Received</th>
                                        <th width="8%">Pending</th>
                                        <th width="10%">Receiving Now</th>
                                        <th width="12%">Batch #</th>
                                        <th width="12%">Expiry Date</th>
                                        <th width="12%">Notes</th>
                                        <th width="5%">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($purchase_order_items)): ?>
                                        <?php foreach ($purchase_order_items as $index => $item): ?>
                                        <tr id="item_row_<?php echo $index + 1; ?>" class="item-row">
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($item['product_name']); ?>
                                                <small class="d-block text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                                <input type="hidden" name="items[<?php echo $index; ?>][purchase_order_item_id]" value="<?php echo $item['id']; ?>">
                                                <input type="hidden" name="items[<?php echo $index; ?>][product_id]" value="<?php echo $item['product_id']; ?>">
                                                <input type="hidden" name="items[<?php echo $index; ?>][unit_price]" value="<?php echo $item['unit_price']; ?>">
                                                <input type="hidden" name="items[<?php echo $index; ?>][uom_id]" value="<?php echo $item['effective_uom_id']; ?>">
                                            </td>
                                            <td class="text-center">
                                                <?php echo formatNumber($conn, $item['quantity']); ?>
                                                <input type="hidden" name="items[<?php echo $index; ?>][quantity_ordered]" value="<?php echo $item['quantity']; ?>">
                                            </td>
                                            <td class="text-center">
                                                <?php echo formatNumber($conn, $item['quantity_received']); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php echo formatNumber($conn, $item['quantity_pending']); ?>
                                                <input type="hidden" class="quantity-pending" value="<?php echo $item['quantity_pending']; ?>">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control quantity-receiving" name="items[<?php echo $index; ?>][quantity_received]" value="<?php echo $item['quantity_pending']; ?>" min="0.01" max="<?php echo $item['quantity_pending']; ?>" step="0.01" required>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control" name="items[<?php echo $index; ?>][batch_number]" placeholder="Batch Number">
                                            </td>
                                            <td>
                                                <input type="date" class="form-control" name="items[<?php echo $index; ?>][expiry_date]">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control" name="items[<?php echo $index; ?>][notes]" placeholder="Optional notes">
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-danger btn-sm skip-item" title="Skip Item">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i> Items that have been fully received will not be shown. You can receive partial quantities by adjusting the "Receiving Now" column.
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12 text-end">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='goods_receiving_notes.php'">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveGRN">Save GRN</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap-5'
    });
    
    // Purchase Order change event
    $('#purchase_order_id').change(function() {
        const poId = $(this).val();
        if (poId) {
            window.location.href = 'grn_create.php?po_id=' + poId;
        } else {
            $('#purchase_order_details').hide();
            $('#items_section').hide();
        }
    });
    
    // Skip item button click
    $('.skip-item').click(function() {
        const row = $(this).closest('tr');
        row.find('.quantity-receiving').val('0');
        row.addClass('table-secondary');
        $(this).removeClass('btn-danger').addClass('btn-success').html('<i class="fas fa-undo"></i>');
        $(this).removeClass('skip-item').addClass('unskip-item');
    });
    
    // Unskip item button click (delegated event)
    $(document).on('click', '.unskip-item', function() {
        const row = $(this).closest('tr');
        const pendingQty = row.find('.quantity-pending').val();
        row.find('.quantity-receiving').val(pendingQty);
        row.removeClass('table-secondary');
        $(this).removeClass('btn-success').addClass('btn-danger').html('<i class="fas fa-times"></i>');
        $(this).removeClass('unskip-item').addClass('skip-item');
    });
    
    // Form validation
    $('#grnForm').submit(function(e) {
        let hasItems = false;
        $('.quantity-receiving').each(function() {
            if (parseFloat($(this).val()) > 0) {
                hasItems = true;
                return false; // Break the loop
            }
        });
        
        if (!hasItems) {
            e.preventDefault();
            alert('Please receive at least one item or adjust quantities.');
            return false;
        }
        
        return true;
    });
});
</script>
