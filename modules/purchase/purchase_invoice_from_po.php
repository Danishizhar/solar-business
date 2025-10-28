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

// Check if purchase order ID is provided
$from_po = false;
$po_data = [];
$po_items = [];
$total_po_quantity = 0;

if (isset($_GET['po_id']) && !empty($_GET['po_id'])) {
    $from_po = true;
    $po_id = $_GET['po_id'];
    
    // Get purchase order details and check if it has any GRNs
    $po_query = "SELECT po.*, s.supplier_name, s.supplier_code, s.payment_terms 
                FROM purchase_orders po
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                WHERE po.id = :po_id
                AND NOT EXISTS (
                    SELECT 1 FROM goods_receiving_notes grn 
                    WHERE grn.purchase_order_id = po.id 
                    AND grn.status != 'cancelled'
                )";
    
    try {
        $po_stmt = $conn->prepare($po_query);
        $po_stmt->bindParam(':po_id', $po_id);
        $po_stmt->execute();
        
        if ($po_stmt->rowCount() > 0) {
            $po_data = $po_stmt->fetch(PDO::FETCH_ASSOC);
            
            // First, get all distinct product IDs from the purchase order
            $product_ids_query = "SELECT DISTINCT poi.product_id 
                                FROM purchase_order_items poi 
                                WHERE poi.purchase_order_id = :po_id";
            $product_ids_stmt = $conn->prepare($product_ids_query);
            $product_ids_stmt->bindParam(':po_id', $po_id);
            $product_ids_stmt->execute();
            $product_ids = $product_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Initialize the array to store PO items
            $po_items = [];
            
            // For each product ID, get the corresponding item details
            foreach ($product_ids as $product_id) {
                $item_query = "SELECT poi.*, p.product_name, p.product_code, p.barcode, 
                               p.purchase_uom_id, p.base_uom_id,
                               COALESCE(uom.uom_name, 'Unit') as unit_name,
                               COALESCE(uom.uom_symbol, '') as unit_symbol
                               FROM purchase_order_items poi
                               LEFT JOIN products p ON poi.product_id = p.id
                               LEFT JOIN uom ON COALESCE(p.purchase_uom_id, p.base_uom_id) = uom.id
                               WHERE poi.purchase_order_id = :po_id 
                               AND poi.product_id = :product_id
                               ORDER BY poi.id ASC
                               LIMIT 1";
                
                $item_stmt = $conn->prepare($item_query);
                $item_stmt->bindParam(':po_id', $po_id);
                $item_stmt->bindParam(':product_id', $product_id);
                $item_stmt->execute();
                $item = $item_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($item) {
                    $po_items[] = $item;
                }
            }
            
            // Products fetched successfully
            
            // Get already invoiced quantities for each item
            $invoiced_quantities = [];
            $invoiced_query = "SELECT pii.product_id, pii.purchase_order_item_id, SUM(pii.quantity) as invoiced_quantity 
                              FROM purchase_invoice_items pii 
                              JOIN purchase_invoices pi ON pii.purchase_invoice_id = pi.id 
                              WHERE pi.purchase_order_id = :po_id 
                              AND pi.status != 'cancelled' 
                              GROUP BY pii.purchase_order_item_id";
            
            $invoiced_stmt = $conn->prepare($invoiced_query);
            $invoiced_stmt->bindParam(':po_id', $po_id);
            $invoiced_stmt->execute();
            
            while ($row = $invoiced_stmt->fetch(PDO::FETCH_ASSOC)) {
                $invoiced_quantities[$row['purchase_order_item_id']] = $row['invoiced_quantity'];
            }
            
            // Calculate total quantity for prorating delivery charges and update remaining quantities
            foreach ($po_items as &$item) {
                // Calculate remaining quantity
                $invoiced = isset($invoiced_quantities[$item['id']]) ? $invoiced_quantities[$item['id']] : 0;
                $item['remaining_quantity'] = max(0, $item['quantity'] - $invoiced);
                $item['invoiced_quantity'] = $invoiced;
                
                // Use original quantity for total calculation
                $total_po_quantity += $item['quantity'];
            }
        } else {
            // Purchase order not found, redirect to list
            header("Location: purchase_orders.php?error=" . urlencode("Purchase order not found"));
            exit();
        }
    } catch (PDOException $e) {
        header("Location: purchase_orders.php?error=" . urlencode("Error: " . $e->getMessage()));
        exit();
    }
}

// Initialize variables
$purchase_invoice = [
    'id' => '',
    'invoice_number' => '',
    'supplier_id' => $from_po ? $po_data['supplier_id'] : '',
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'location_id' => '',
    'subtotal' => $from_po ? $po_data['subtotal'] : '0.00',
    'tax_amount' => $from_po ? $po_data['tax_amount'] : '0.00',
    'discount_amount' => $from_po ? $po_data['discount_amount'] : '0.00',
    'delivery_charges' => $from_po ? $po_data['delivery_charges'] : '0.00',
    'total_amount' => $from_po ? $po_data['total_amount'] : '0.00',
    'status' => 'approved',
    'notes' => $from_po ? "Created from Purchase Order: " . $po_data['order_number'] : '',
    'payment_terms' => $from_po ? $po_data['payment_terms'] : ''
];

$purchase_invoice_items = [];
$edit_mode = false;
$page_title = $from_po ? "Create Purchase Invoice from Purchase Order" : "Create Purchase Invoice";

// Check if we're in edit mode
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $edit_mode = true;
    $page_title = "Edit Purchase Invoice";
    
    // Get purchase invoice details
    $query = "SELECT * FROM purchase_invoices WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $purchase_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get purchase invoice items
        $query = "SELECT * FROM purchase_invoice_items WHERE purchase_invoice_id = :purchase_invoice_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':purchase_invoice_id', $_GET['id']);
        $stmt->execute();
        $purchase_invoice_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Purchase invoice not found, redirect to list
        header("Location: purchase_invoices.php");
        exit();
    }
} else if ($from_po) {
    // Start fresh with an empty array
    $purchase_invoice_items = [];
    
    // Now create purchase invoice items one by one
    for ($i = 0; $i < count($po_items); $i++) {
        // Get the current PO item
        $current_item = $po_items[$i];
        $product_id = $current_item['product_id'];
        $product_name = $current_item['product_name'];
        $product_code = $current_item['product_code'];
        
        // Skip items that have been fully invoiced
        if (isset($current_item['remaining_quantity']) && $current_item['remaining_quantity'] <= 0) {
            continue;
        }
        
        // Calculate total after discount
        $unit_price = $current_item['unit_price'];
        $discount_percent = $current_item['discount_percent'];
        $discounted_price = $unit_price * (1 - $discount_percent / 100);
        $quantity = isset($current_item['remaining_quantity']) ? $current_item['remaining_quantity'] : $current_item['quantity'];
        $tax_rate = $current_item['tax_rate'];
        $total_before_tax = $discounted_price * $quantity;
        $tax_amount = $total_before_tax * ($tax_rate / 100);
        $total = $total_before_tax + $tax_amount;
        
        // Determine UOM ID with proper fallback mechanism
        $uom_id = 1; // Default fallback
        if (isset($current_item['uom_id']) && !empty($current_item['uom_id'])) {
            $uom_id = $current_item['uom_id'];
        } else if (isset($current_item['purchase_uom_id']) && !empty($current_item['purchase_uom_id'])) {
            $uom_id = $current_item['purchase_uom_id'];
        } else if (isset($current_item['base_uom_id']) && !empty($current_item['base_uom_id'])) {
            $uom_id = $current_item['base_uom_id'];
        }
        
        // Create a new invoice item
        $new_invoice_item = [
            'product_id' => $product_id,
            'product_name' => $product_name,
            'product_code' => $product_code,
            'barcode' => $current_item['barcode'] ?? '',
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'discount_percent' => $discount_percent,
            'tax_rate' => $tax_rate,
            'total' => $total,
            'uom_id' => $uom_id,
            'unit_name' => $current_item['unit_name'] ?? 'Unit',
            'unit_symbol' => $current_item['unit_symbol'] ?? '',
            'ordered_quantity' => $current_item['quantity'],
            'received_quantity' => isset($current_item['received_quantity']) ? $current_item['received_quantity'] : 0,
            'invoiced_quantity' => isset($invoiced_quantities[$current_item['id']]) ? $invoiced_quantities[$current_item['id']] : 0,
            'remaining_quantity' => $quantity,
            'purchase_order_item_id' => $current_item['id']
        ];
        
        // Add to the purchase invoice items array
        $purchase_invoice_items[] = $new_invoice_item;
    }
}

// Get all suppliers
$query = "SELECT id, supplier_code, supplier_name FROM suppliers WHERE status = 1 ORDER BY supplier_name";
$stmt = $conn->prepare($query);
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Get active financial years
$financialYears = [];
try {
    $fyQuery = "SELECT id, year_code, start_date, end_date, is_closed 
               FROM financial_years 
               WHERE status = 1 
               ORDER BY start_date DESC";
    $fyStmt = $conn->prepare($fyQuery);
    $fyStmt->execute();
    $financialYears = $fyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Find the financial year for the current invoice date if in edit mode
    $currentFinancialYearId = null;
    if ($edit_mode && !empty($purchase_invoice['invoice_date'])) {
        $dateQuery = "SELECT id FROM financial_years 
                     WHERE status = 1 
                     AND :invoice_date BETWEEN start_date AND end_date 
                     LIMIT 1";
        $dateStmt = $conn->prepare($dateQuery);
        $dateStmt->bindParam(':invoice_date', $purchase_invoice['invoice_date']);
        $dateStmt->execute();
        $fyResult = $dateStmt->fetch(PDO::FETCH_ASSOC);
        if ($fyResult) {
            $currentFinancialYearId = $fyResult['id'];
        }
    }
} catch (PDOException $e) {
    $financialYears = [];
    error_log("Error fetching financial years: " . $e->getMessage());
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
                    <h2>
                        <i class="fas fa-file-invoice-dollar"></i> 
                        <?php echo $from_po ? 'Create Purchase Invoice from Purchase Order' : ($edit_mode ? 'Edit Purchase Invoice' : 'Create Purchase Invoice'); ?>
                    </h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="purchase_invoices.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Purchase Invoices
                    </a>
                </div>
            </div>
            
            <form id="purchaseInvoiceForm" action="actions/save_purchase_invoice_from_po.php" method="post">
                <?php if ($from_po): ?>
                <input type="hidden" name="purchase_order_id" value="<?php echo $po_id; ?>">
                <?php endif; ?>
                <input type="hidden" name="invoice_id" value="<?php echo $purchase_invoice['id']; ?>">
                <?php if ($from_po): ?>
                <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                <input type="hidden" id="total_po_quantity" value="<?php echo $total_po_quantity; ?>">
                <input type="hidden" id="original_po_delivery_charges" value="<?php echo $po_data['delivery_charges']; ?>">
                <?php endif; ?>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Invoice Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="invoice_number" class="form-label">Invoice Number</label>
                                    <input type="text" class="form-control" id="invoice_number" name="invoice_number" value="<?php echo !empty($purchase_invoice['invoice_number']) ? $purchase_invoice['invoice_number'] : 'INV-' . date('Ymd') . '-' . rand(1000, 9999); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                <label for="supplier_id">Supplier <span class="text-danger">*</span></label>
                                <?php if ($from_po): ?>
                                    <input type="hidden" name="supplier_id" value="<?php echo $purchase_invoice['supplier_id']; ?>">
                                    <select class="form-control supplier-select" id="supplier_id" disabled>
                                        <option value="<?php echo $purchase_invoice['supplier_id']; ?>" selected>
                                            <?php 
                                            $supplier_name = '';
                                            foreach ($suppliers as $supplier) {
                                                if ($supplier['id'] == $purchase_invoice['supplier_id']) {
                                                    $supplier_name = $supplier['supplier_name'] . ' (' . $supplier['supplier_code'] . ')';
                                                    break;
                                                }
                                            }
                                            echo $supplier_name;
                                            ?>
                                        </option>
                                    </select>
                                <?php else: ?>
                                    <select class="form-control supplier-select" id="supplier_id" name="supplier_id" required>
                                        <option value="">Select Supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['id']; ?>" <?php echo ($supplier['id'] == $purchase_invoice['supplier_id']) ? 'selected' : ''; ?>>
                                            <?php echo $supplier['supplier_name'] . ' (' . $supplier['supplier_code'] . ')'; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            </div>
                        </div>
                        
                        <!-- Supplier Details Section (initially hidden) -->
                        <div id="supplier_details" class="row mt-3 mb-3" style="display: <?php echo !empty($purchase_invoice['supplier_id']) ? 'block' : 'none'; ?>">
                            <div class="col-12">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-subtitle mb-2 text-muted">Supplier Details</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Address:</strong></p>
                                                <p id="supplier_address" class="mb-3">Loading...</p>
                                                
                                                <p class="mb-1"><strong>Contact:</strong></p>
                                                <p id="supplier_contact" class="mb-0">Loading...</p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Payment Terms:</strong></p>
                                                <p id="supplier_payment_terms" class="mb-3">Loading...</p>
                                                
                                                <p class="mb-1"><strong>Tax Number:</strong></p>
                                                <p id="supplier_tax_number" class="mb-0">Loading...</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="invoice_date" class="form-label">Invoice Date</label>
                                    <input type="date" class="form-control" id="invoice_date" name="invoice_date" value="<?php echo $purchase_invoice['invoice_date']; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="due_date" class="form-label">Due Date</label>
                                    <input type="date" class="form-control" id="due_date" name="due_date" value="<?php echo $purchase_invoice['due_date']; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="financial_year_id" class="form-label">Financial Year <span class="text-danger">*</span></label>
                                    <select class="form-control select2" id="financial_year_id" name="financial_year_id" required>
                                        <option value="">Select Financial Year</option>
                                        <?php foreach ($financialYears as $fy): ?>
                                            <?php if ($fy['is_closed'] == 0): ?>
                                                <option value="<?php echo $fy['id']; ?>" 
                                                    <?php echo (isset($currentFinancialYearId) && $fy['id'] == $currentFinancialYearId) ? 'selected' : ''; ?>
                                                    data-start="<?php echo $fy['start_date']; ?>" 
                                                    data-end="<?php echo $fy['end_date']; ?>">
                                                    <?php echo 'FY ' . $fy['year_code'] . ': ' . date('d M Y', strtotime($fy['start_date'])) . ' - ' . date('d M Y', strtotime($fy['end_date'])); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($financialYears)): ?>
                                        <div class="text-danger mt-1">No active financial years found. Please create a financial year first.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="location_id" class="form-label">Warehouse Location</label>
                                    <select class="form-control select2" id="location_id" name="location_id" required>
                                        <option value="">Select Location</option>
                                        <?php foreach ($locations as $location): ?>
                                        <option value="<?php echo $location['id']; ?>" <?php echo (isset($purchase_invoice['location_id']) && $location['id'] == $purchase_invoice['location_id']) ? 'selected' : ''; ?>>
                                            <?php echo $location['location_name'] . ' (' . $location['location_code'] . ')'; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-control" id="status" name="status" required>
                                        <option value="draft" <?php echo ($purchase_invoice['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                        <option value="approved" <?php echo ($purchase_invoice['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="payment_terms" class="form-label">Payment Terms</label>
                                    <input type="text" class="form-control" id="payment_terms" name="payment_terms" value="<?php echo isset($purchase_invoice['payment_terms']) ? $purchase_invoice['payment_terms'] : ''; ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo $purchase_invoice['notes']; ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (!$from_po): ?>
                <!-- Barcode Scanner Section -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Add Products</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="input-group mb-3">
                                    <input type="text" class="form-control" id="barcode_input" placeholder="Scan or enter barcode">
                                    <button class="btn btn-primary" type="button" id="add_by_barcode">
                                        <i class="fas fa-barcode"></i> Add Product
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Invoice Items Table -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Invoice Items</h5>
                        <?php if (!$from_po): ?>
                        <button type="button" class="btn btn-sm btn-primary" id="add_item_btn">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="invoice_items_table">
                                <thead>
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="20%">Product</th>
                                        <th width="10%">Quantity</th>
                                        <th width="10%">Unit Price</th>
                                        <th width="8%">Discount %</th>
                                        <th width="8%">Tax %</th>
                                        <th width="12%">Batch #</th>
                                        <th width="12%">Expiry Date</th>
                                        <th width="10%">Total</th>
                                        <th width="5%">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($purchase_invoice_items)): ?>
                                    <tr id="item_row_1" class="item-row">
                                        <td>1</td>
                                        <td>
                                            <select class="form-control product-select" name="items[0][product_id]" required>
                                                <option value="">Select Product</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control quantity" name="items[0][quantity]" value="1" min="0.01" step="0.01" required>
                                            <input type="hidden" class="item-uom-id" name="items[0][uom_id]" value="">
                                        </td>
                                        <td>
                                            <input type="number" class="form-control unit-price" name="items[0][unit_price]" value="0.00" min="0" step="0.01" required>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control discount-percent" name="items[0][discount_percent]" value="0" min="0" max="100" step="0.01">
                                        </td>
                                        <td>
                                            <input type="number" class="form-control tax-rate" name="items[0][tax_rate]" value="0" min="0" step="0.01">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control" name="items[0][batch_number]" placeholder="Batch Number">
                                        </td>
                                        <td>
                                            <input type="date" class="form-control" name="items[0][expiry_date]">
                                        </td>
                                        <td>
                                            <input type="number" class="form-control row-total" name="items[0][total]" value="0.00" readonly>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-danger btn-sm remove-item">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php else: ?>

                                        <?php foreach ($purchase_invoice_items as $index => $item): ?>

                                        <tr id="item_row_<?php echo $index + 1; ?>" class="item-row" 
                                            <?php echo isset($item['po_quantity']) ? 'data-po-quantity="'.$item['po_quantity'].'"' : ''; ?>
                                            <?php echo isset($item['remaining_quantity']) ? 'data-remaining-quantity="'.$item['remaining_quantity'].'"' : ''; ?>
                                            <?php echo isset($item['invoiced_quantity']) ? 'data-invoiced-quantity="'.$item['invoiced_quantity'].'"' : ''; ?>>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>

                                            
                                            <?php if ($from_po): ?>
                                                    <!-- When creating from PO, just display the product name and use hidden fields -->
                                                    <div class="product-display">
                                                        <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                        <small class="d-block text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                                    </div>

                                                    <input type="hidden" name="items[<?php echo $index; ?>][product_id]" value="<?php echo $item['product_id']; ?>">
                                                <?php else: ?>
                                                    <!-- Normal select for non-PO mode -->
                                                    <select class="form-control product-select" id="product_select_<?php echo $index; ?>" name="items[<?php echo $index; ?>][product_id]" required>
                                                        <option value="">Select Product</option>
                                                        <?php if (isset($item['product_id']) && !empty($item['product_id'])): ?>
                                                        <option value="<?php echo $item['product_id']; ?>" selected>
                                                            <?php echo isset($item['product_name']) ? htmlspecialchars($item['product_name']) . ' (' . htmlspecialchars($item['product_code']) . ')' : 'Loading...'; ?>
                                                        </option>
                                                        <?php endif; ?>
                                                    </select>
                                                <?php endif; ?>
                                                <input type="hidden" name="items[<?php echo $index; ?>][id]" value="<?php echo isset($item['id']) ? $item['id'] : ''; ?>">
                                                <?php if (isset($item['po_quantity'])): ?>
                                                <input type="hidden" name="items[<?php echo $index; ?>][po_quantity]" value="<?php echo $item['po_quantity']; ?>">
                                                <?php endif; ?>
                                                <?php if (isset($item['purchase_order_item_id']) && $from_po): ?>
                                                <input type="hidden" name="items[<?php echo $index; ?>][purchase_order_item_id]" value="<?php echo $item['purchase_order_item_id']; ?>">
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <input type="number" class="form-control quantity" name="items[<?php echo $index; ?>][quantity]" value="<?php echo $item['quantity']; ?>" min="0.01" step="0.01" required>
                                                <input type="hidden" class="item-uom-id" name="items[<?php echo $index; ?>][uom_id]" value="<?php echo isset($item['uom_id']) ? $item['uom_id'] : ''; ?>">
                                                <?php if (!empty($item['unit_name'])): ?>
                                                <small class="d-block text-muted">
                                                    <?php echo htmlspecialchars($item['unit_name']); ?>
                                                    <?php if (!empty($item['unit_symbol'])): ?>
                                                        (<?php echo htmlspecialchars($item['unit_symbol']); ?>)
                                                    <?php endif; ?>
                                                </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <input type="number" class="form-control unit-price" name="items[<?php echo $index; ?>][unit_price]" value="<?php echo $item['unit_price']; ?>" min="0" step="0.01" required <?php echo $from_po ? 'readonly' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" class="form-control discount-percent" name="items[<?php echo $index; ?>][discount_percent]" value="<?php echo $item['discount_percent']; ?>" min="0" max="100" step="0.01" <?php echo $from_po ? 'readonly' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="number" class="form-control tax-rate" name="items[<?php echo $index; ?>][tax_rate]" value="<?php echo $item['tax_rate']; ?>" min="0" step="0.01" <?php echo $from_po ? 'readonly' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control" name="items[<?php echo $index; ?>][batch_number]" value="<?php echo isset($item['batch_number']) ? $item['batch_number'] : ''; ?>" placeholder="Batch Number">
                                            </td>
                                            <td>
                                                <input type="date" class="form-control" name="items[<?php echo $index; ?>][expiry_date]" value="<?php echo isset($item['expiry_date']) ? $item['expiry_date'] : ''; ?>">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control row-total" name="items[<?php echo $index; ?>][total]" value="<?php echo $item['total']; ?>" readonly>
                                            </td>
                                            <td>
                                                <?php if (!$from_po): ?>
                                                <button type="button" class="btn btn-danger btn-sm remove-item">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="10">
                                            <button type="button" class="btn btn-success" id="add_item">
                                                <i class="fas fa-plus"></i> Add Item
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Invoice Summary -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Invoice Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="payment_terms_info" class="form-label">Payment Terms Info</label>
                                    <textarea class="form-control" id="payment_terms_info" name="payment_terms_info" rows="4" placeholder="Additional payment information"><?php echo isset($purchase_invoice['payment_terms_info']) ? htmlspecialchars($purchase_invoice['payment_terms_info']) : ''; ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table">
                                        <tbody>
                                            <tr>
                                                <th>Subtotal:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="subtotal" name="subtotal" value="<?php echo $purchase_invoice['subtotal']; ?>" readonly>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Discount Amount:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="discount_amount" name="discount_amount" value="<?php echo $purchase_invoice['discount_amount']; ?>" readonly>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Tax Amount:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="tax_amount" name="tax_amount" value="<?php echo $purchase_invoice['tax_amount']; ?>" readonly>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Delivery Charges:</th>
                                                 <td>
                                                     <input type="number" step="0.01" class="form-control" id="delivery_charges" name="delivery_charges" value="<?php echo isset($purchase_invoice['delivery_charges']) ? $purchase_invoice['delivery_charges'] : 0; ?>" onchange="calculateTotals()">
                                                 </td>
                                            </tr>
                                            <tr>
                                                <th>Total Amount:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="total_amount" name="total_amount" value="<?php echo $purchase_invoice['total_amount']; ?>" readonly>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-end">
                            <a href="purchase_invoices.php" class="btn btn-secondary me-2">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Purchase Invoice</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

<!-- SweetAlert2 for better alerts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize Select2 for dropdowns
    $('.select2').select2();
    
    // Initialize product select2
    initializeProductSelect2();
    
    // Calculate row totals for existing rows first
    $('#invoice_items_table tbody tr').each(function() {
        calculateRowTotal($(this));
    });
    
    // Calculate totals on page load
    calculateTotals();
    
    // Initialize Select2 for location dropdown
    $('#location_id').select2({
        theme: 'bootstrap-5',
        placeholder: 'Select a location',
        allowClear: true
    });
    
    // Add event handler for quantity changes when from PO
    $(document).on('change', '.quantity', function() {
        const row = $(this).closest('tr');
        const poQuantity = parseFloat(row.data('po-quantity')) || 0;
        let enteredQuantity = parseFloat($(this).val()) || 0;
        
        // Ensure quantity doesn't exceed PO quantity if from PO
        if (poQuantity > 0 && enteredQuantity > poQuantity) {
            alert('Quantity cannot exceed the purchase order quantity of ' + poQuantity);
            $(this).val(poQuantity);
            enteredQuantity = poQuantity;
        }
        
        // Recalculate row total which will update discount and tax amounts
        calculateRowTotal(row);
        
        // Calculate invoice totals
        calculateTotals();
    });
    
    // Load supplier details when a supplier is selected
    $('#supplier_id').on('change', function() {
        const supplierId = $(this).val();
        
        if (supplierId) {
            // Show supplier details section
            $('#supplier_details').show();
            
            // Fetch supplier details via AJAX
            $.ajax({
                url: 'actions/get_supplier_details.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    supplier_id: supplierId
                },
                success: function(response) {
                    if (response.success) {
                        const supplier = response.supplier;
                        
                        // Update supplier details in the UI
                        $('#supplier_address').html(supplier.formatted_address);
                        
                        let contactHtml = '';
                        if (supplier.contact_person) {
                            contactHtml += supplier.contact_person + '<br>';
                        }
                        if (supplier.phone) {
                            contactHtml += 'Phone: ' + supplier.phone + '<br>';
                        }
                        if (supplier.email) {
                            contactHtml += 'Email: ' + supplier.email;
                        }
                        $('#supplier_contact').html(contactHtml || 'No contact information available');
                        
                        // Payment terms (convert days to readable format)
                        const paymentTerms = supplier.payment_terms ? supplier.payment_terms + ' days' : 'Not specified';
                        $('#supplier_payment_terms').text(paymentTerms);
                        
                        // Tax number
                        $('#supplier_tax_number').text(supplier.tax_number || 'Not specified');
                    } else {
                        // Show error
                        showNotification('error', 'Error: ' + response.message);
                        $('#supplier_details').hide();
                    }
                },
                error: function(xhr, status, error) {
                    showNotification('error', 'Error fetching supplier details');
                    $('#supplier_details').hide();
                }
            });
        } else {
            // Hide supplier details section if no supplier selected
            $('#supplier_details').hide();
        }
    });
    
    // Barcode scanning
    $('#add_by_barcode').on('click', function() {
        const barcode = $('#barcode_input').val().trim();
        
        if (barcode) {
            addProductByBarcode(barcode);
            $('#barcode_input').val('').focus();
        } else {
            showNotification('warning', 'Please enter a barcode');
        }
    });
    
    // Enter key in barcode field
    $('#barcode_input').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#add_by_barcode').click();
        }
    });
    
    // Add new item row
    $('#add_item').on('click', function() {
        addItemRow();
    });
    
    // Remove item row
    $(document).on('click', '.remove-item', function() {
        $(this).closest('tr').remove();
        renumberRows();
        calculateTotals();
    });
    
    // Calculate row total when quantity or price changes
    $(document).on('change keyup', '.quantity, .unit-price, .discount-percent, .tax-rate', function() {
        const row = $(this).closest('tr');
        calculateRowTotal(row);
        calculateTotals();
    });
    
    // Product selection via dropdown
    $(document).on('change', '.product-select', function() {
        const row = $(this).closest('tr');
        const productId = $(this).val();
        
        if (productId) {
            // Get supplier ID
            const supplierId = $('#supplier_id').val();
            
            // Fetch product details
            $.ajax({
                url: 'actions/get_product_details.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    product_id: productId,
                    supplier_id: supplierId
                },
                success: function(response) {
                    if (response.success) {
                        const product = response.product;
                        
                        // Update row with product details
                        row.find('.unit-price').val(product.unit_price);
                        row.find('.tax-rate').val(product.tax_rate);
                        
                        // Set UOM ID (use purchase_uom_id if available, otherwise base_uom_id)
                        const uomId = product.purchase_uom_id || product.base_uom_id || 1;
                        row.find('.item-uom-id').val(uomId);
                        
                        // Calculate totals
                        calculateRowTotal(row);
                        calculateTotals();
                    } else {
                        showNotification('error', 'Error: ' + response.message);
                    }
                },
                error: function(xhr, status, error) {
                    showNotification('error', 'Error fetching product details');
                }
            });
        }
    });
    
    // Initialize existing rows if any (for edit mode)
    $('.item-row').each(function() {
        const row = $(this);
        const productId = row.find('.product-select').val();
        
        if (productId) {
            // Load product details for existing items
            $.ajax({
                url: 'actions/get_product_details.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        // Format the product data for Select2
                        const product = response.product;
                        const option = new Option(
                            product.text, 
                            product.id, 
                            true, 
                            true
                        );
                        
                        // Update the select2 dropdown with the product data
                        row.find('.product-select').empty().append(option).trigger('change');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading product details:', error);
                }
            });
        }
        
        calculateRowTotal(row);
    });
    
    // Calculate initial totals
    calculateTotals();
    
    // Trigger supplier change if a supplier is already selected (for edit mode)
    if ($('#supplier_id').val()) {
        $('#supplier_id').trigger('change');
    }
});

// Initialize Select2 for product dropdowns
function initializeProductSelect2() {
    $('.product-select').select2({
        theme: 'bootstrap-5',
        placeholder: 'Select a product',
        allowClear: true,
        width: '100%',
        ajax: {
            url: 'actions/get_products.php',
            type: 'POST',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    search: params.term,
                    supplier_id: $('#supplier_id').val(),
                    page: params.page || 1
                };
            },
            processResults: function(data, params) {
                params.page = params.page || 1;
                console.log('Product data received:', data);
                
                return {
                    results: data.products || [],
                    pagination: {
                        more: data.more || false
                    }
                };
            },
            cache: true,
            error: function(xhr, status, error) {
                console.error('Error loading products:', error);
                showNotification('error', 'Error loading products. Please try again.');
            }
        },
        escapeMarkup: function(markup) {
            return markup;
        },
        minimumInputLength: 0,
        templateResult: formatProduct,
        templateSelection: formatProductSelection
    });
}

// Format product for Select2 dropdown
function formatProduct(product) {
    if (product.loading) {
        return product.text;
    }
    
    let markup = "<div class='select2-result-product clearfix'>";
    markup += "<div class='select2-result-product__name'>" + product.text + "</div>";
    if (product.barcode) {
        markup += "<div class='select2-result-product__barcode text-muted small'>Barcode: " + product.barcode + "</div>";
    }
    markup += "</div>";
    
    return markup;
}

// Format selected product
function formatProductSelection(product) {
    return product.text || product.id;
}

// Add product by barcode
function addProductByBarcode(barcode) {
    // Get supplier ID
    const supplierId = $('#supplier_id').val();
    
    console.log('Searching for barcode:', barcode);
    
    // Show loading notification
    showNotification('info', 'Searching for product...');
    
    // Create a form data object
    const formData = new FormData();
    formData.append('barcode', barcode);
    
    // Use fetch API instead of jQuery AJAX
    fetch('actions/get_product_by_barcode.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(response => {
        console.log('Barcode search response:', response);
        
        if (response.success && response.product) {
            const product = response.product;
            
            // Check if product already exists in the table
            let existingRow = null;
            $('.product-select').each(function() {
                if ($(this).val() == product.id) {
                    existingRow = $(this).closest('tr');
                    return false;
                }
            });
            
            if (existingRow) {
                // Increment quantity if product already exists
                const currentQty = parseFloat(existingRow.find('.quantity').val()) || 0;
                existingRow.find('.quantity').val(currentQty + 1);
                calculateRowTotal(existingRow);
            } else {
                // Check if there's an empty row (first row or any row with no product selected)
                let emptyRow = null;
                $('.product-select').each(function() {
                    if (!$(this).val()) {
                        emptyRow = $(this).closest('tr');
                        return false;
                    }
                });
                
                let rowToUse;
                if (emptyRow) {
                    // Use the existing empty row
                    rowToUse = emptyRow;
                } else {
                    // Add new row to invoice items table
                    rowToUse = addItemRow();
                }
                
                // Create new option and append to select
                const newOption = new Option(product.text, product.id, true, true);
                rowToUse.find('.product-select').append(newOption).trigger('change');
                
                // Set product details
                rowToUse.find('.unit-price').val(product.unit_price);
                rowToUse.find('.tax-rate').val(product.tax_rate);
                rowToUse.find('.quantity').val(1);
                
                // Set UOM ID (use purchase_uom_id if available, otherwise base_uom_id)
                const uomId = product.purchase_uom_id || product.base_uom_id || 1;
                rowToUse.find('.item-uom-id').val(uomId);
                
                // Calculate totals
                calculateRowTotal(rowToUse);
            }
            
            calculateTotals();
            showNotification('success', 'Product added successfully');
        } else {
            showNotification('error', response.message || 'Product not found');
        }
    })
    .catch(error => {
        console.error('Error fetching product by barcode:', error);
        showNotification('error', 'Error fetching product: ' + error);
    });
}

// Add new item row
function addItemRow() {
    const rowCount = $('#invoice_items_table tbody tr').length;
    const newRowNum = rowCount + 1;
    
    const newRow = `
        <tr id="item_row_${newRowNum}" class="item-row">
            <td>${newRowNum}</td>
            <td>
                <select class="form-control product-select" name="items[${newRowNum-1}][product_id]" required>
                    <option value="">Select Product</option>
                </select>
            </td>
            <td>
                <input type="number" class="form-control quantity" name="items[${newRowNum-1}][quantity]" value="1" min="0.01" step="0.01" required>
                <input type="hidden" name="items[${newRowNum-1}][uom_id]" class="item-uom-id" value="">
            </td>
            <td>
                <input type="number" class="form-control unit-price" name="items[${newRowNum-1}][unit_price]" value="0.00" min="0" step="0.01" required>
            </td>
            <td>
                <input type="number" class="form-control discount-percent" name="items[${newRowNum-1}][discount_percent]" value="0" min="0" max="100" step="0.01">
            </td>
            <td>
                <input type="number" class="form-control tax-rate" name="items[${newRowNum-1}][tax_rate]" value="0" min="0" step="0.01">
            </td>
            <td>
                <input type="text" class="form-control" name="items[${newRowNum-1}][batch_number]" placeholder="Batch Number">
            </td>
            <td>
                <input type="date" class="form-control" name="items[${newRowNum-1}][expiry_date]">
            </td>
            <td>
                <input type="number" class="form-control row-total" name="items[${newRowNum-1}][total]" value="0.00" readonly>
            </td>
            <td>
                <button type="button" class="btn btn-danger btn-sm remove-item">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
    `;
    
    $('#invoice_items_table tbody').append(newRow);
    
    // Initialize the new row's product select2
    initializeProductSelect2();
    
    return $(`#item_row_${newRowNum}`);
}

// Renumber rows
function renumberRows() {
    $('#invoice_items_table tbody tr').each(function(index) {
        $(this).find('td:first').text(index + 1);
    });
}

// Calculate row total
function calculateRowTotal(row) {
    const quantity = parseFloat(row.find('.quantity').val()) || 0;
    const unitPrice = parseFloat(row.find('.unit-price').val()) || 0;
    const discountPercent = parseFloat(row.find('.discount-percent').val()) || 0;
    const taxRate = parseFloat(row.find('.tax-rate').val()) || 0;
    
    // Calculate discount amount
    const discountAmount = (quantity * unitPrice) * (discountPercent / 100);
    
    // Calculate subtotal after discount
    const subtotalAfterDiscount = (quantity * unitPrice) - discountAmount;
    
    // Calculate tax amount
    const taxAmount = subtotalAfterDiscount * (taxRate / 100);
    
    // Calculate total
    const total = subtotalAfterDiscount + taxAmount;
    
    // Update row total and store discount and tax amounts in hidden fields
    row.find('.row-total').val(total.toFixed(2));
    
    // Add hidden fields for discount and tax amounts if they don't exist
    // Get the row index from the row ID
    const rowId = row.attr('id');
    const rowIndex = rowId ? rowId.replace('item_row_', '') : 0;
    
    if (row.find('.discount-amount').length === 0) {
        row.append('<input type="hidden" class="discount-amount" name="items[' + rowIndex + '][discount_amount]" value="' + discountAmount.toFixed(2) + '">');
    } else {
        row.find('.discount-amount').val(discountAmount.toFixed(2));
    }
    
    if (row.find('.tax-amount').length === 0) {
        row.append('<input type="hidden" class="tax-amount" name="items[' + rowIndex + '][tax_amount]" value="' + taxAmount.toFixed(2) + '">');
    } else {
        row.find('.tax-amount').val(taxAmount.toFixed(2));
    }
}

// Calculate invoice totals
function calculateTotals() {
    let subtotal = 0;
    let totalDiscount = 0;
    let totalTax = 0;
    let totalQuantity = 0;
    let originalPoDeliveryCharges = parseFloat($('#original_po_delivery_charges').val()) || 0;
    let originalPoQuantity = parseFloat($('#total_po_quantity').val()) || 0;
    let deliveryCharges = parseFloat($('#delivery_charges').val()) || 0;
    
    // Calculate total quantity for prorating delivery charges
    $('.quantity').each(function() {
        totalQuantity += parseFloat($(this).val()) || 0;
    });
    
    // Calculate subtotal, discount, and tax directly from each row
    // This matches the calculation in purchase_order_create.php
    $('#invoice_items_table tbody tr').each(function() {
        const quantity = parseFloat($(this).find('.quantity').val()) || 0;
        const unitPrice = parseFloat($(this).find('.unit-price').val()) || 0;
        const discountPercent = parseFloat($(this).find('.discount-percent').val()) || 0;
        const taxRate = parseFloat($(this).find('.tax-rate').val()) || 0;
        
        const rowSubtotal = quantity * unitPrice;
        const rowDiscount = rowSubtotal * (discountPercent / 100);
        const rowSubtotalAfterDiscount = rowSubtotal - rowDiscount;
        const rowTax = rowSubtotalAfterDiscount * (taxRate / 100);
        
        subtotal += rowSubtotal;
        totalDiscount += rowDiscount;
        totalTax += rowTax;
    });
    
    // Prorate delivery charges based on quantity ratio if from PO
    if (originalPoQuantity > 0 && originalPoDeliveryCharges > 0) {
        deliveryCharges = (totalQuantity / originalPoQuantity) * originalPoDeliveryCharges;
        $('#delivery_charges').val(deliveryCharges.toFixed(2));
    }
    
    // Calculate grand total
    let grandTotal = (subtotal - totalDiscount) + totalTax + deliveryCharges;
    
    // Update summary fields
    $('#subtotal').val(subtotal.toFixed(2));
    $('#discount_amount').val(totalDiscount.toFixed(2));
    $('#tax_amount').val(totalTax.toFixed(2));
    $('#total_amount').val(grandTotal.toFixed(2));
    
    // Update summary display
    $('#subtotal_display').text(subtotal.toFixed(2));
    $('#discount_display').text(totalDiscount.toFixed(2));
    $('#tax_display').text(totalTax.toFixed(2));
    $('#delivery_display').text(deliveryCharges.toFixed(2));
    $('#total_display').text(grandTotal.toFixed(2));
}

// Show notification
function showNotification(type, message) {
    if (typeof toastr !== 'undefined') {
        toastr[type](message);
    } else {
        alert(message);
    }
}

// Document ready function to ensure all elements are properly initialized
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Initialize datepicker if available
    if ($.fn.datepicker) {
        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            autoclose: true
        });
    }
    
    // Disable unit price, discount, tax fields when creating invoice from PO
    <?php if ($from_po): ?>
    // Disable add item button and barcode scanner functionality
    $('#add_item_btn').prop('disabled', true).addClass('disabled');
    $('#add_by_barcode').prop('disabled', true).addClass('disabled');
    $('#barcode_input').prop('disabled', true);
    
    // Disable product selection, unit price, discount, and tax fields
    $('.product-select').prop('disabled', true);
    $('.unit-price').prop('readonly', true);
    $('.discount-percent').prop('readonly', true);
    $('.tax-rate').prop('readonly', true);
    
    // Prevent adding new rows
    window.addItemRow = function() {
        showNotification('warning', 'Cannot add new items to an invoice created from PO');
        return false;
    };
    <?php endif; ?>
    
    // Add event listener for quantity changes to validate against remaining quantity
    $(document).on('change', '.quantity', function() {
        const row = $(this).closest('tr');
        const remainingQuantity = parseFloat(row.data('remaining-quantity')) || 0;
        const poQuantity = parseFloat(row.data('po-quantity')) || 0;
        const invoicedQuantity = parseFloat(row.data('invoiced-quantity')) || 0;
        const enteredQuantity = parseFloat($(this).val()) || 0;
        
        // If this is from a PO and has remaining quantity data
        if (remainingQuantity > 0) {
            if (enteredQuantity > remainingQuantity) {
                // Show warning and reset to remaining quantity
                showNotification('warning', 'Quantity cannot exceed remaining quantity of ' + remainingQuantity);
                $(this).val(remainingQuantity);
            }
            
            // Update tooltip to show quantity information
            $(this).attr('title', 'Original: ' + poQuantity + ' | Invoiced: ' + invoicedQuantity + ' | Remaining: ' + remainingQuantity);
            $(this).tooltip('dispose').tooltip();
        }
        
        // Recalculate row total
        calculateRowTotal(row);
        calculateTotals();
    });
    
    // Calculate totals on page load
    calculateTotals();
    
    // Supplier change event to load supplier details
    $('#supplier_id').change(function() {
        const supplierId = $(this).val();
        if (supplierId) {
            // Fetch supplier details using AJAX
            $.ajax({
                url: 'actions/get_supplier_details.php',
                type: 'POST',
                data: { supplier_id: supplierId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Update supplier details
                        $('#supplier_name').text(response.supplier.supplier_name);
                        $('#supplier_code').text(response.supplier.supplier_code || '');
                        $('#supplier_contact').text(response.supplier.contact_person);
                        $('#supplier_phone').text(response.supplier.phone);
                        $('#supplier_email').text(response.supplier.email);
                        $('#supplier_address').text(response.supplier.formatted_address || response.supplier.address);
                        $('#supplier_tax_number').text(response.supplier.tax_number || 'N/A');
                        
                        // Auto-fill payment terms from supplier data but keep it editable
                        if (response.supplier.payment_terms) {
                            $('#payment_terms').val(response.supplier.payment_terms);
                        }
                        
                        // Show supplier details section
                        $('#supplier_details_section').show();
                    } else {
                        showNotification('error', 'Failed to load supplier details');
                    }
                },
                error: function() {
                    showNotification('error', 'Error loading supplier details');
                }
            });
        } else {
            // Hide supplier details section if no supplier selected
            $('#supplier_details_section').hide();
        }
    });
    
    // Trigger change event if supplier is already selected (for edit mode)
    if ($('#supplier_id').val()) {
        $('#supplier_id').trigger('change');
    }
    
    // Function to validate invoice date against financial year
    function validateInvoiceDate() {
        const financialYearSelect = $('#financial_year_id');
        const invoiceDateInput = $('#invoice_date');
        
        if (financialYearSelect.val() && invoiceDateInput.val()) {
            const selectedOption = financialYearSelect.find('option:selected');
            const startDate = new Date(selectedOption.data('start'));
            const endDate = new Date(selectedOption.data('end'));
            const invoiceDate = new Date(invoiceDateInput.val());
            
            // Reset to midnight for date-only comparison
            startDate.setHours(0, 0, 0, 0);
            endDate.setHours(0, 0, 0, 0);
            invoiceDate.setHours(0, 0, 0, 0);
            
            if (invoiceDate < startDate || invoiceDate > endDate) {
                const startFormatted = startDate.toLocaleDateString();
                const endFormatted = endDate.toLocaleDateString();
                
                Swal.fire({
                    icon: 'error',
                    title: 'Date Range Error',
                    text: `Invoice date must be within the selected financial year's date range: ${startFormatted} to ${endFormatted}`,
                });
                
                return false;
            }
        }
        return true;
    }
    
    // Validate when financial year changes
    $('#financial_year_id').change(function() {
        validateInvoiceDate();
    });
    
    // Validate when invoice date changes
    $('#invoice_date').change(function() {
        validateInvoiceDate();
    });
    
    // Validate form before submission
    $('#purchaseInvoiceForm').submit(function(e) {
        if (!validateInvoiceDate()) {
            e.preventDefault();
        }
    });
    
    // Form submission
    $('#purchase-invoice-form').on('submit', function(e) {
        // Debug form values before submission
        console.log('Form submission triggered');
        
        // Debug product IDs in the form
        let productIds = [];
        $('input[name^="items["][name$="][product_id]"]').each(function(index) {
            let name = $(this).attr('name');
            let value = $(this).val();
            console.log(`Product ID field ${index}: ${name} = ${value}`);
            productIds.push(value);
        });
        
        // Check for duplicate product IDs
        let uniqueIds = [...new Set(productIds)];
        console.log('All product IDs:', productIds);
        console.log('Unique product IDs:', uniqueIds);
        if (productIds.length !== uniqueIds.length) {
            console.error('WARNING: Duplicate product IDs detected in form!');
        }
        
        // Validate form
        if (!$(this).get(0).checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('was-validated');
            return false;
        }
        
        // Add a hidden field with debug info
        $('<input>').attr({
            type: 'hidden',
            name: 'debug_product_ids',
            value: JSON.stringify(productIds)
        }).appendTo('#purchase-invoice-form');
    });
});
</script>
