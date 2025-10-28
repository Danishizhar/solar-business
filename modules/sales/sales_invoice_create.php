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
$sales_invoice = [
    'id' => '',
    'invoice_number' => '',
    'customer_id' => '',
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'location_id' => '',
    'subtotal' => '0.00',
    'tax_amount' => '0.00',
    'discount_amount' => '0.00',
    'delivery_charges' => '0.00',
    'total_amount' => '0.00',
    'status' => 'approved',
    'notes' => '',
    'payment_terms' => ''
];

$sales_invoice_items = [];
$edit_mode = false;
$page_title = "Create Sales Invoice";

// Check if we're in edit mode
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $edit_mode = true;
    $page_title = "Edit Sales Invoice";
    
    // Get sales invoice details
    $query = "SELECT * FROM sales_invoices WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $sales_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get sales invoice items
        $query = "SELECT * FROM sales_invoice_items WHERE sales_invoice_id = :sales_invoice_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':sales_invoice_id', $_GET['id']);
        $stmt->execute();
        $sales_invoice_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Sales invoice not found, redirect to list
        header("Location: sales_invoices.php");
        exit();
    }
}

// Get all customers
$query = "SELECT id, customer_code, customer_name FROM customers WHERE status = 1 ORDER BY customer_name";
$stmt = $conn->prepare($query);
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    if ($edit_mode && !empty($sales_invoice['invoice_date'])) {
        $dateQuery = "SELECT id FROM financial_years 
                     WHERE status = 1 
                     AND :invoice_date BETWEEN start_date AND end_date 
                     LIMIT 1";
        $dateStmt = $conn->prepare($dateQuery);
        $dateStmt->bindParam(':invoice_date', $sales_invoice['invoice_date']);
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
                        <?php echo $edit_mode ? 'Edit Sales Invoice' : 'Create Sales Invoice'; ?>
                    </h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="sales_invoices.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Sales Invoices
                    </a>
                </div>
            </div>
            
            <form id="salesInvoiceForm" action="actions/save_sales_invoice.php" method="post">
                <input type="hidden" name="invoice_id" value="<?php echo $sales_invoice['id']; ?>">
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Invoice Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="invoice_number" class="form-label">Invoice Number</label>
                                    <input type="text" class="form-control" id="invoice_number" name="invoice_number" value="<?php echo !empty($sales_invoice['invoice_number']) ? $sales_invoice['invoice_number'] : 'INV-' . date('Ymd') . '-' . rand(1000, 9999); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customer_id" class="form-label">Customer</label>
                                    <select class="form-control select2" id="customer_id" name="customer_id" required>
                                        <option value="">Select Customer</option>
                                        <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo $customer['id']; ?>" <?php echo ($customer['id'] == $sales_invoice['customer_id']) ? 'selected' : ''; ?>>
                                            <?php echo $customer['customer_name'] . ' (' . $customer['customer_code'] . ')'; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Customer Details Section (initially hidden) -->
                        <div id="customer_details" class="row mt-3 mb-3" style="display: <?php echo !empty($sales_invoice['customer_id']) ? 'block' : 'none'; ?>">
                            <div class="col-12">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-subtitle mb-2 text-muted">Customer Details</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Address:</strong></p>
                                                <p id="customer_address" class="mb-3">Loading...</p>
                                                
                                                <p class="mb-1"><strong>Contact:</strong></p>
                                                <p id="customer_contact" class="mb-0">Loading...</p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-1"><strong>Payment Terms:</strong></p>
                                                <p id="customer_payment_terms" class="mb-3">Loading...</p>
                                                
                                                <p class="mb-1"><strong>Tax Number:</strong></p>
                                                <p id="customer_tax_number" class="mb-0">Loading...</p>
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
                                    <input type="date" class="form-control" id="invoice_date" name="invoice_date" value="<?php echo $sales_invoice['invoice_date']; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="due_date" class="form-label">Due Date</label>
                                    <input type="date" class="form-control" id="due_date" name="due_date" value="<?php echo $sales_invoice['due_date']; ?>" required>
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
                                        <option value="<?php echo $location['id']; ?>" <?php echo (isset($sales_invoice['location_id']) && $location['id'] == $sales_invoice['location_id']) ? 'selected' : ''; ?>>
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
                                        <option value="draft" <?php echo ($sales_invoice['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                                        <option value="approved" <?php echo ($sales_invoice['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="payment_terms" class="form-label">Payment Terms</label>
                                    <input type="text" class="form-control" id="payment_terms" name="payment_terms" value="<?php echo isset($sales_invoice['payment_terms']) ? $sales_invoice['payment_terms'] : ''; ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo $sales_invoice['notes']; ?></textarea>
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
                
                <!-- Invoice Items Table -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Invoice Items</h5>
                        <button type="button" class="btn btn-sm btn-primary" id="add_item_btn">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
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
                                    <?php if (empty($sales_invoice_items)): ?>
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
                                        <?php foreach ($sales_invoice_items as $index => $item): ?>
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
                                                <input type="hidden" class="item-uom-id" name="items[<?php echo $index; ?>][uom_id]" value="<?php echo isset($item['uom_id']) ? $item['uom_id'] : ''; ?>">
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
                                                <input type="text" class="form-control" name="items[<?php echo $index; ?>][batch_number]" value="<?php echo isset($item['batch_number']) ? $item['batch_number'] : ''; ?>" placeholder="Batch Number">
                                            </td>
                                            <td>
                                                <input type="date" class="form-control" name="items[<?php echo $index; ?>][expiry_date]" value="<?php echo isset($item['expiry_date']) ? $item['expiry_date'] : ''; ?>">
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
                                    <textarea class="form-control" id="payment_terms_info" name="payment_terms_info" rows="4" placeholder="Additional payment information"><?php echo isset($sales_invoice['payment_terms_info']) ? htmlspecialchars($sales_invoice['payment_terms_info']) : ''; ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table">
                                        <tbody>
                                            <tr>
                                                <th>Subtotal:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="subtotal" name="subtotal" value="<?php echo $sales_invoice['subtotal']; ?>" readonly>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Discount Amount:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="discount_amount" name="discount_amount" value="<?php echo $sales_invoice['discount_amount']; ?>" readonly>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Tax Amount:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="tax_amount" name="tax_amount" value="<?php echo $sales_invoice['tax_amount']; ?>" readonly>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Delivery Charges:</th>
                                                 <td>
                                                     <input type="number" step="0.01" class="form-control" id="delivery_charges" name="delivery_charges" value="<?php echo isset($sales_invoice['delivery_charges']) ? $sales_invoice['delivery_charges'] : 0; ?>" onchange="calculateTotals()">
                                                 </td>
                                            </tr>
                                            <tr>
                                                <th>Total Amount:</th>
                                                <td>
                                                    <input type="number" class="form-control" id="total_amount" name="total_amount" value="<?php echo $sales_invoice['total_amount']; ?>" readonly>
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
                            <a href="sales_invoices.php" class="btn btn-secondary me-2">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Sales Invoice</button>
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
    // Initialize Select2 for customer dropdown
    $('#customer_id').select2({
        placeholder: 'Select a customer',
        allowClear: true,
        width: '100%'
    });
    
    // Initialize Select2 for product dropdowns
    initializeProductSelect2();
    
    // Load customer details when a customer is selected
    $('#customer_id').on('change', function() {
        const customerId = $(this).val();
        
        if (customerId) {
            // Show customer details section
            $('#customer_details').show();
            
            // Fetch customer details via AJAX
            $.ajax({
                url: 'actions/get_customer_details.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    customer_id: customerId
                },
                success: function(response) {
                    if (response.success) {
                        const customer = response.customer;
                        
                        // Update customer details in the UI
                        $('#customer_address').html(customer.formatted_address);
                        
                        let contactHtml = '';
                        if (customer.contact_person) {
                            contactHtml += customer.contact_person + '<br>';
                        }
                        if (customer.phone) {
                            contactHtml += 'Phone: ' + customer.phone + '<br>';
                        }
                        if (customer.email) {
                            contactHtml += 'Email: ' + customer.email;
                        }
                        $('#customer_contact').html(contactHtml || 'No contact information available');
                        
                        // Payment terms (convert days to readable format)
                        const paymentTerms = customer.payment_terms ? customer.payment_terms + ' days' : 'Not specified';
                        $('#customer_payment_terms').text(paymentTerms);
                        
                        // Tax number
                        $('#customer_tax_number').text(customer.tax_number || 'Not specified');
                    } else {
                        // Show error
                        showNotification('error', 'Error: ' + response.message);
                        $('#customer_details').hide();
                    }
                },
                error: function(xhr, status, error) {
                    showNotification('error', 'Error fetching customer details');
                    $('#customer_details').hide();
                }
            });
        } else {
            // Hide customer details section if no customer selected
            $('#customer_details').hide();
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
            // Get customer ID
            const customerId = $('#customer_id').val();
            
            // Fetch product details
            $.ajax({
                url: 'actions/get_product_details.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    product_id: productId,
                    customer_id: customerId
                },
                success: function(response) {
                    if (response.success) {
                        const product = response.product;
                        
                        // Update row with product details
                        row.find('.unit-price').val(product.unit_price);
                        row.find('.tax-rate').val(product.tax_rate);
                        
                        // Set UOM ID (use sales_uom_id if available, otherwise base_uom_id)
                        const uomId = product.sales_uom_id || product.base_uom_id || 1;
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
    
    // Trigger customer change if a customer is already selected (for edit mode)
    if ($('#customer_id').val()) {
        $('#customer_id').trigger('change');
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
                    customer_id: $('#customer_id').val(),
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
    // Get customer ID
    const customerId = $('#customer_id').val();
    
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
                
                // Set UOM ID (use sales_uom_id if available, otherwise base_uom_id)
                const uomId = product.sales_uom_id || product.base_uom_id || 1;
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
    
    // Update row total
    row.find('.row-total').val(total.toFixed(2));
}

// Calculate invoice totals
function calculateTotals() {
    let subtotal = 0;
    let totalDiscount = 0;
    let totalTax = 0;
    // Calculate delivery charges
    var deliveryCharges = parseFloat($('#delivery_charges').val()) || 0;
    let grandTotal = 0;
    
    $('#invoice_items_table tbody tr').each(function() {
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
    
    // Calculate grand total
    grandTotal = subtotal - totalDiscount + totalTax + deliveryCharges;
    
    // Update invoice summary
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
    
    // Calculate totals on page load
    calculateTotals();
    
    // Customer change event to load customer details
    $('#customer_id').change(function() {
        const customerId = $(this).val();
        if (customerId) {
            // Fetch customer details using AJAX
            $.ajax({
                url: 'actions/get_customer_details.php',
                type: 'POST',
                data: { customer_id: customerId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Update customer details
                        $('#customer_name').text(response.customer.customer_name);
                        $('#customer_code').text(response.customer.customer_code || '');
                        $('#customer_contact').text(response.customer.contact_person);
                        $('#customer_phone').text(response.customer.phone);
                        $('#customer_email').text(response.customer.email);
                        $('#customer_address').text(response.customer.formatted_address || response.customer.address);
                        $('#customer_tax_number').text(response.customer.tax_number || 'N/A');
                        
                        // Auto-fill payment terms from customer data but keep it editable
                        if (response.customer.payment_terms) {
                            $('#payment_terms').val(response.customer.payment_terms);
                        }
                        
                        // Show customer details section
                        $('#customer_details_section').show();
                    } else {
                        showNotification('error', 'Failed to load customer details');
                    }
                },
                error: function() {
                    showNotification('error', 'Error loading customer details');
                }
            });
        } else {
            // Hide customer details section if no customer selected
            $('#customer_details_section').hide();
        }
    });
    
    // Trigger change event if customer is already selected (for edit mode)
    if ($('#customer_id').val()) {
        $('#customer_id').trigger('change');
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
    $('#salesInvoiceForm').submit(function(e) {
        if (!validateInvoiceDate()) {
            e.preventDefault();
        }
    });
});
</script>
