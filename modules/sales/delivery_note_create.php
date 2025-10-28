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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['sales_add'])) {
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
$dn = [
    'id' => '',
    'delivery_note_number' => '',
    'sales_order_id' => '',
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

$sales_order = [];
$sales_order_items = [];
$received_items = [];
$page_title = "Create Delivery Note";

// Check if SO ID is provided
$so_id = isset($_GET['so_id']) ? $_GET['so_id'] : '';

// Get confirmed and partial sales orders for dropdown that don't have invoices created from them
$soQuery = "SELECT so.id, so.order_number, s.customer_name, s.customer_code
            FROM sales_orders so
            JOIN customers s ON so.customer_id = s.id
            WHERE so.status IN ('confirmed', 'partial')
            AND NOT EXISTS (
                SELECT 1 FROM sales_invoices si 
                WHERE si.sales_order_id = so.id 
                AND si.status != 'cancelled'
            )
            ORDER BY so.created_at DESC";
try {
    $soStmt = $conn->prepare($soQuery);
    $soStmt->execute();
    $sales_orders = $soStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sales_orders = [];
    error_log("Error fetching sales orders: " . $e->getMessage());
}

// If SO ID is provided, load sales order details
if (!empty($so_id)) {
    $dn['sales_order_id'] = $so_id;
    
    // Get sales order details and check if it has any invoices
    $soDetailQuery = "SELECT so.*, s.customer_name, s.customer_code, s.contact_person, s.phone, s.email, s.address
                     FROM sales_orders so
                     JOIN customers s ON so.customer_id = s.id
                     WHERE so.id = :so_id AND so.status IN ('confirmed', 'partial')
                     AND NOT EXISTS (
                         SELECT 1 FROM sales_invoices si 
                         WHERE si.sales_order_id = so.id 
                         AND si.status != 'cancelled'
                     )";
    try {
        $soDetailStmt = $conn->prepare($soDetailQuery);
        $soDetailStmt->bindParam(':so_id', $so_id);
        $soDetailStmt->execute();
        $sales_order = $soDetailStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sales_order) {
            echo "<div class='alert alert-danger'>Sales order not found or not in confirmed status.</div>";
            exit();
        }
        
        // Get sales order items with received quantities and UOM information
        $soItemsQuery = "SELECT soi.*, p.product_name, p.product_code, p.barcode,
                         soi.received_quantity as quantity_received,
                         (soi.quantity - soi.received_quantity) as quantity_pending,
                         soi.uom_id, p.sales_uom_id, p.base_uom_id,
                         COALESCE(soi.uom_id, p.sales_uom_id, p.base_uom_id, 1) as effective_uom_id
                         FROM sales_order_items soi
                         JOIN products p ON soi.product_id = p.id
                         WHERE soi.sales_order_id = :so_id
                         HAVING quantity_pending > 0";
        $soItemsStmt = $conn->prepare($soItemsQuery);
        $soItemsStmt->bindParam(':so_id', $so_id);
        $soItemsStmt->execute();
        $sales_order_items = $soItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate DN number
        $dn['delivery_note_number'] = 'DN-' . date('Ymd') . '-' . rand(1000, 9999);
    } catch (PDOException $e) {
        error_log("Error fetching sales order details: " . $e->getMessage());
        echo "<div class='alert alert-danger'>Error fetching sales order details: " . $e->getMessage() . "</div>";
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
                    <h2><i class="fas fa-clipboard-check"></i> Create Delivery Note</h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="delivery_notes.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to DN List
                    </a>
                </div>
            </div>
            
            <form action="actions/save_delivery_note.php" method="post" id="dnForm">
                <input type="hidden" name="delivery_note_id" value="<?php echo $dn['id']; ?>">
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">DN Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="delivery_note_number" class="form-label">DN Number</label>
                                    <input type="text" class="form-control" id="delivery_note_number" name="delivery_note_number" value="<?php echo !empty($dn['delivery_note_number']) ? $dn['delivery_note_number'] : 'DN-' . date('Ymd') . '-' . rand(1000, 9999); ?>" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sales_order_id" class="form-label">Sales Order</label>
                                    <select class="form-control select2" id="sales_order_id" name="sales_order_id" required <?php echo !empty($so_id) ? 'disabled' : ''; ?>>
                                        <option value="">Select Sales Order</option>
                                        <?php foreach ($sales_orders as $so): ?>
                                        <option value="<?php echo $so['id']; ?>" <?php echo ($so['id'] == $dn['sales_order_id']) ? 'selected' : ''; ?>>
                                            <?php echo $so['order_number'] . ' - ' . $so['customer_name'] . ' (' . $so['customer_code'] . ')'; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($so_id)): ?>
                                    <input type="hidden" name="sales_order_id" value="<?php echo $so_id; ?>">
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Sales Order Details Section (initially hidden) -->
                        <div id="sales_order_details" class="row mt-3 mb-3" style="display: <?php echo !empty($sales_order) ? 'block' : 'none'; ?>">
                            <div class="col-12">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-subtitle mb-2 text-muted">Sales Order Details</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Order Number:</strong> <span id="so_order_number"><?php echo htmlspecialchars($sales_order['order_number'] ?? ''); ?></span></p>
                                                <p class="mb-1"><strong>Customer:</strong> <span id="so_customer_name"><?php echo htmlspecialchars($sales_order['customer_name'] ?? ''); ?></span></p>
                                                <p class="mb-1"><strong>Contact:</strong> <span id="so_contact_person"><?php echo htmlspecialchars($sales_order['contact_person'] ?? ''); ?></span></p>
                                                <p class="mb-0"><strong>Phone:</strong> <span id="so_phone"><?php echo htmlspecialchars($sales_order['phone'] ?? ''); ?></span></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Order Date:</strong> <span id="so_order_date"><?php echo !empty($sales_order['order_date']) ? date('d M Y', strtotime($sales_order['order_date'])) : ''; ?></span></p>
                                                <p class="mb-1"><strong>Expected Delivery:</strong> <span id="so_expected_delivery"><?php echo !empty($sales_order['expected_delivery_date']) ? date('d M Y', strtotime($sales_order['expected_delivery_date'])) : 'Not specified'; ?></span></p>
                                                <p class="mb-1"><strong>Email:</strong> <span id="so_email"><?php echo htmlspecialchars($sales_order['email'] ?? ''); ?></span></p>
                                                <p class="mb-0"><strong>Address:</strong> <span id="so_address"><?php echo htmlspecialchars($sales_order['address'] ?? ''); ?></span></p>
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
                                    <input type="date" class="form-control" id="received_date" name="received_date" value="<?php echo $dn['received_date']; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="location_id" class="form-label">Warehouse Location</label>
                                    <select class="form-control select2" id="location_id" name="location_id" required>
                                        <option value="">Select Location</option>
                                        <?php foreach ($locations as $location): ?>
                                        <option value="<?php echo $location['id']; ?>" <?php echo ($location['id'] == $dn['location_id']) ? 'selected' : ''; ?>>
                                            <?php echo $location['location_name'] . ' (' . $location['location_code'] . ')'; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo $dn['notes']; ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Items to Receive -->
                <div id="items_section" class="card mb-4" style="display: <?php echo !empty($sales_order_items) ? 'block' : 'none'; ?>">
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
                                    <?php if (!empty($sales_order_items)): ?>
                                        <?php foreach ($sales_order_items as $index => $item): ?>
                                        <tr id="item_row_<?php echo $index + 1; ?>" class="item-row">
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($item['product_name']); ?>
                                                <small class="d-block text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                                <input type="hidden" name="items[<?php echo $index; ?>][sales_order_item_id]" value="<?php echo $item['id']; ?>">
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
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='delivery_notes.php'">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveDN">Save DN</button>
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
    
    // Sales Order change event
    $('#sales_order_id').change(function() {
        const soId = $(this).val();
        if (soId) {
            window.location.href = 'delivery_note_create.php?so_id=' + soId;
        } else {
            $('#sales_order_details').hide();
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
    $('#dnForm').submit(function(e) {
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
