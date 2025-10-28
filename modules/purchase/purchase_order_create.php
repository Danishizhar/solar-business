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
$purchase_order = [
    'id' => '',
    'order_number' => '',
    'supplier_id' => '',
    'order_date' => date('Y-m-d'),
    'expected_delivery_date' => '',
    'subtotal' => '0.00',
    'tax_amount' => '0.00',
    'discount_amount' => '0.00',
    'delivery_charges' => '0.00',
    'total_amount' => '0.00',
    'status' => 'draft',
    'notes' => ''
];

$purchase_order_items = [];
$edit_mode = false;
$page_title = "Create Purchase Order";

// Check if we're in edit mode
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $edit_mode = true;
    $page_title = "Edit Purchase Order";
    
    // Get purchase order details
    $query = "SELECT * FROM purchase_orders WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $purchase_order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get purchase order items
        $query = "SELECT * FROM purchase_order_items WHERE purchase_order_id = :purchase_order_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':purchase_order_id', $_GET['id']);
        $stmt->execute();
        $purchase_order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Purchase order not found, redirect to list
        header("Location: purchase_orders.php");
        exit();
    }
}

// Get all suppliers
$query = "SELECT id, supplier_code, supplier_name FROM suppliers WHERE status = 1 ORDER BY supplier_name";
$stmt = $conn->prepare($query);
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                        <i class="fas fa-file-invoice"></i> 
                        <?php echo $edit_mode ? 'Edit Purchase Order' : 'Create Purchase Order'; ?>
                    </h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="purchase_orders.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Purchase Orders
                    </a>
                </div>
            </div>
            
            <form id="purchaseOrderForm" action="actions/save_purchase_order.php" method="post">
                <input type="hidden" name="purchase_order_id" value="<?php echo $purchase_order['id']; ?>">
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Order Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="order_number" class="form-label">Order Number</label>
                                    <input type="text" class="form-control" id="order_number" name="order_number" value="<?php echo !empty($purchase_order['order_number']) ? $purchase_order['order_number'] : 'PO-' . date('Ymd') . '-' . rand(1000, 9999); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="supplier_id" class="form-label">Supplier</label>
                                    <select class="form-control select2" id="supplier_id" name="supplier_id" required>
                                        <option value="">Select Supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['id']; ?>" <?php echo ($supplier['id'] == $purchase_order['supplier_id']) ? 'selected' : ''; ?>>
                                            <?php echo $supplier['supplier_name'] . ' (' . $supplier['supplier_code'] . ')'; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Supplier Details Section (initially hidden) -->
                        <div id="supplier_details" class="row mt-3 mb-3" style="display: <?php echo !empty($purchase_order['supplier_id']) ? 'block' : 'none'; ?>">
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
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="order_date" class="form-label">Order Date</label>
                                    <input type="date" class="form-control" id="order_date" name="order_date" value="<?php echo $purchase_order['order_date']; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="expected_delivery_date" class="form-label">Expected Delivery Date</label>
                                    <input type="date" class="form-control" id="expected_delivery_date" name="expected_delivery_date" value="<?php echo $purchase_order['expected_delivery_date']; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-control" id="status" name="status" required>
                                        <option value="draft" <?php echo ($purchase_order['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                        <option value="confirmed" <?php echo ($purchase_order['status'] == 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                        <option value="cancelled" <?php echo ($purchase_order['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo $purchase_order['notes']; ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
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
                
                <!-- Order Items Table -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Order Items</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="order_items_table">
                                <thead>
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="30%">Product</th>
                                        <th width="10%">Quantity</th>
                                        <th width="15%">Unit Price</th>
                                        <th width="10%">Discount %</th>
                                        <th width="10%">Tax %</th>
                                        <th width="15%">Total</th>
                                        <th width="5%">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($purchase_order_items)): ?>
                                    <tr id="item_row_1" class="item-row">
                                        <td>1</td>
                                        <td>
                                            <select class="form-control product-select" name="items[0][product_id]" required>
                                                <option value="">Select Product</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control quantity" name="items[0][quantity]" value="1" min="0.01" step="0.01" required>
                                            <input type="hidden" name="items[0][uom_id]" class="item-uom-id" value="">
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
                                            <input type="number" class="form-control row-total" name="items[0][total]" value="0.00" readonly>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-danger btn-sm remove-item">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($purchase_order_items as $index => $item): ?>
                                        <tr id="item_row_<?php echo $index + 1; ?>" class="item-row">
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <select class="form-control product-select" name="items[<?php echo $index; ?>][product_id]" required>
                                                    <option value="">Select Product</option>
                                                    <!-- Products will be loaded via AJAX -->
                                                    <option value="<?php echo $item['product_id']; ?>" selected>Loading...</option>
                                                </select>
                                                <input type="hidden" name="items[<?php echo $index; ?>][id]" value="<?php echo $item['id']; ?>">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control quantity" name="items[<?php echo $index; ?>][quantity]" value="<?php echo $item['quantity']; ?>" min="0.01" step="0.01" required>
                                                <input type="hidden" name="items[<?php echo $index; ?>][uom_id]" class="item-uom-id" value="<?php echo isset($item['uom_id']) ? $item['uom_id'] : ''; ?>">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control unit-price" name="items[<?php echo $index; ?>][unit_price]" value="<?php echo $item['unit_price']; ?>" min="0" step="0.01" required>
                                            </td>
                                            <td>
                                                <input type="number" class="form-control discount-percent" name="items[<?php echo $index; ?>][discount_percent]" value="<?php echo $item['discount_percent']; ?>" min="0" max="100" step="0.01">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control tax-rate" name="items[<?php echo $index; ?>][tax_rate]" value="<?php echo $item['tax_rate']; ?>" min="0" step="0.01">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control row-total" name="items[<?php echo $index; ?>][total]" value="<?php echo $item['total']; ?>" readonly>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-danger btn-sm remove-item">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="8">
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
                
                <!-- Order Summary -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Order Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4"><?php echo htmlspecialchars($purchase_order['notes']); ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table">
                                        <tbody>
                                            <tr>
                                                <th>Subtotal:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="subtotal" name="subtotal" value="<?php echo $purchase_order['subtotal']; ?>" readonly>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Discount Amount:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="discount_amount" name="discount_amount" value="<?php echo $purchase_order['discount_amount']; ?>" readonly>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Tax Amount:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="tax_amount" name="tax_amount" value="<?php echo $purchase_order['tax_amount']; ?>" readonly>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Delivery Charges:</th>
                                                <td>
                                                    <input type="number" step="0.01" class="form-control" id="delivery_charges" name="delivery_charges" value="<?php echo isset($purchase_order['delivery_charges']) ? $purchase_order['delivery_charges'] : 0; ?>" onchange="calculateTotals()">
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Total Amount:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="total_amount" name="total_amount" value="<?php echo $purchase_order['total_amount']; ?>" readonly>
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
                            <a href="purchase_orders.php" class="btn btn-secondary me-2">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Purchase Order</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<!-- Include Select2 CSS and JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Include Toastr for notifications -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Select2 for supplier dropdown
    $('#supplier_id').select2({
        placeholder: 'Select a supplier',
        allowClear: true,
        width: '100%'
    });
    
    // Initialize Select2 for product dropdowns
    initializeProductSelect2();
    
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
                    // Add new row if no empty row exists
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
    const rowCount = $('#order_items_table tbody tr').length;
    const newRowNum = rowCount + 1;
    
    const newRow = `
        <tr id="item_row_${newRowNum}" class="item-row">
            <td>${newRowNum}</td>
            <td>
                <select class="form-control product-select" name="items[${newRowNum}][product_id]" required>
                    <option value="">Select Product</option>
                </select>
            </td>
            <td>
                <input type="number" class="form-control quantity" name="items[${newRowNum}][quantity]" value="1" min="0.01" step="0.01" required>
            </td>
            <td>
                <input type="number" class="form-control unit-price" name="items[${newRowNum}][unit_price]" value="0.00" min="0" step="0.01" required>
                <input type="hidden" name="items[${newRowNum}][uom_id]" class="item-uom-id" value="">
            </td>
            <td>
                <input type="number" class="form-control discount-percent" name="items[${newRowNum}][discount_percent]" value="0" min="0" max="100" step="0.01">
            </td>
            <td>
                <input type="number" class="form-control tax-rate" name="items[${newRowNum}][tax_rate]" value="0" min="0" step="0.01">
            </td>
            <td>
                <input type="text" class="form-control row-total" name="items[${newRowNum}][total]" value="0.00" readonly>
            </td>
            <td>
                <button type="button" class="btn btn-danger btn-sm remove-item">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
    `;
    
    $('#order_items_table tbody').append(newRow);
    
    // Initialize Select2 for the new row
    initializeProductSelect2();
    
    return $(`#item_row_${newRowNum}`);
}

// Renumber rows
function renumberRows() {
    $('#order_items_table tbody tr').each(function(index) {
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
    
    // Update row total
    row.find('.row-total').val(total.toFixed(2));
}

// Calculate order totals
function calculateTotals() {
    let subtotal = 0;
    let totalDiscount = 0;
    let totalTax = 0;
    let deliveryCharges = parseFloat($('#delivery_charges').val()) || 0;
    let grandTotal = 0;
    
    $('#order_items_table tbody tr').each(function() {
        const quantity = parseFloat($(this).find('.quantity').val()) || 0;
        const unitPrice = parseFloat($(this).find('.unit-price').val()) || 0;
        const discountPercent = parseFloat($(this).find('.discount-percent').val()) || 0;
        const taxRate = parseFloat($(this).find('.tax-rate').val()) || 0;
        
        const rowSubtotal = quantity * unitPrice;
        const rowDiscount = rowSubtotal * (discountPercent / 100);
        const rowSubtotalAfterDiscount = rowSubtotal - rowDiscount;
        const rowTax = rowSubtotalAfterDiscount * (taxRate / 100);
        const rowTotal = rowSubtotalAfterDiscount + rowTax;
        
        subtotal += rowSubtotal;
        totalDiscount += rowDiscount;
        totalTax += rowTax;
    });
    
    // Calculate grand total including delivery charges
    grandTotal = (subtotal - totalDiscount) + totalTax + deliveryCharges;
    
    // Update order summary
    $('#subtotal').val(subtotal.toFixed(2));
    $('#discount_amount').val(totalDiscount.toFixed(2));
    $('#tax_amount').val(totalTax.toFixed(2));
    $('#total_amount').val(grandTotal.toFixed(2));
    
    // Update hidden fields for form submission
    $('input[name="subtotal"]').val(subtotal.toFixed(2));
    $('input[name="discount_amount"]').val(totalDiscount.toFixed(2));
    $('input[name="tax_amount"]').val(totalTax.toFixed(2));
    $('input[name="delivery_charges"]').val(deliveryCharges.toFixed(2));
    $('input[name="total_amount"]').val(grandTotal.toFixed(2));
}

// Show notification
function showNotification(type, message) {
    if (typeof toastr !== 'undefined') {
        toastr[type](message);
    } else {
        alert(message);
    }
}
</script>
