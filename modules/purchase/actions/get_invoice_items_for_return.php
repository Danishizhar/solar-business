<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Please log in to continue.</div>';
    exit;
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if invoice_id is provided
if (!isset($_POST['invoice_id']) || empty($_POST['invoice_id'])) {
    echo '<div class="alert alert-danger">Invalid invoice selected.</div>';
    exit;
}

$invoice_id = $_POST['invoice_id'];

try {
    // Get invoice details
    $invoice_query = "SELECT pi.*, s.supplier_name 
                     FROM purchase_invoices pi
                     JOIN suppliers s ON pi.supplier_id = s.id
                     WHERE pi.id = :invoice_id";
    $invoice_stmt = $conn->prepare($invoice_query);
    $invoice_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $invoice_stmt->execute();
    $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        echo '<div class="alert alert-danger">Invoice not found.</div>';
        exit;
    }
    
    // Get invoice items with previously returned quantities and proper UOM handling
    $items_query = "SELECT pii.*, p.product_name, p.product_code,
                   p.purchase_uom_id, p.base_uom_id,
                   u.uom_name, u.uom_symbol,
                   pii.batch_number, pii.expiry_date, pii.discount_percent,
                   (SELECT COALESCE(SUM(pri.quantity), 0) 
                    FROM purchase_return_items pri 
                    JOIN purchase_returns pr ON pri.return_id = pr.id 
                    WHERE pri.invoice_item_id = pii.id 
                    AND pr.status = 'approved') as returned_quantity
                   FROM purchase_invoice_items pii
                   JOIN products p ON pii.product_id = p.id
                   LEFT JOIN uom u ON COALESCE(pii.uom_id, p.purchase_uom_id, p.base_uom_id, 1) = u.id
                   WHERE pii.purchase_invoice_id = :invoice_id
                   ORDER BY pii.id";
    
    $items_stmt = $conn->prepare($items_query);
    $items_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $items_stmt->execute();
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($items) == 0) {
        echo '<div class="alert alert-warning">No items found for this invoice.</div>';
        exit;
    }
    
    // Output items table
    ?>
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Unit Price</th>
                    <th>Discount (%)</th>
                    <th>Tax Rate (%)</th>
                    <th>Batch #</th>
                    <th>Expiry Date</th>
                    <th>Invoiced Qty</th>
                    <th>Previously Returned</th>
                    <th>Available for Return</th>
                    <th>Return Qty</th>
                    <th>UOM</th>
                    <th>Subtotal</th>
                    <th>Discount</th>
                    <th>Tax</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): 
                    $available_qty = $item['quantity'] - $item['returned_quantity'];
                ?>
                <tr class="return-item" 
                    data-item-id="<?php echo $item['id']; ?>"
                    data-product-id="<?php echo $item['product_id']; ?>"
                    data-uom-id="<?php echo isset($item['uom_id']) ? $item['uom_id'] : (isset($item['purchase_uom_id']) ? $item['purchase_uom_id'] : (isset($item['base_uom_id']) ? $item['base_uom_id'] : 1)); ?>"
                    data-batch-number="<?php echo htmlspecialchars($item['batch_number'] ?? ''); ?>"
                    data-expiry-date="<?php echo htmlspecialchars($item['expiry_date'] ?? ''); ?>">
                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <td class="unit-price" data-value="<?php echo $item['unit_price']; ?>"><?php echo formatNumber($conn, $item['unit_price']); ?></td>
                    <td class="discount-percent" data-value="<?php echo $item['discount_percent']; ?>"><?php echo formatNumber($conn, $item['discount_percent']); ?></td>
                    <td class="tax-rate" data-value="<?php echo $item['tax_rate']; ?>"><?php echo formatNumber($conn, $item['tax_rate']); ?></td>
                    <td><?php echo htmlspecialchars($item['batch_number'] ?? ''); ?></td>
                    <td><?php echo $item['expiry_date'] ? date('Y-m-d', strtotime($item['expiry_date'])) : ''; ?></td>
                    <td><?php echo formatNumber($conn, $item['quantity']); ?></td>
                    <td><?php echo formatNumber($conn, $item['returned_quantity']); ?></td>
                    <td><?php echo formatNumber($conn, $available_qty); ?></td>
                    <td>
                        <input type="number" class="form-control return-quantity" 
                               name="return_qty[<?php echo $item['id']; ?>]" 
                               min="0" max="<?php echo $available_qty; ?>" 
                               step="0.01" value="0">
                    </td>
                    <td><?php echo htmlspecialchars($item['uom_name'] . ' (' . $item['uom_symbol'] . ')'); ?></td>
                    <td class="item-subtotal">0.00</td>
                    <td class="item-discount">0.00</td>
                    <td class="item-tax">0.00</td>
                    <td class="item-total">0.00</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Error loading invoice items: ' . $e->getMessage() . '</div>';
    error_log('Error in get_invoice_items_for_return.php: ' . $e->getMessage());
}
?>
