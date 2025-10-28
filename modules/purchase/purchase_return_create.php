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
if ($_SESSION['role_id'] != 1 && (!isset($_SESSION['permissions']['purchase_returns']) || !in_array('create', $_SESSION['permissions']['purchase_returns']))) {
    $_SESSION['failure'] = "You don't have permission to create purchase returns";
    header('Location: ../../index.php');
    exit();
}

// Include database connection
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Include helper functions
require_once '../../includes/company_helpers.php';

// Set page title
$page_title = "Create Purchase Return";

// Set page title
$page_title = "Create Purchase Return";

// Get active financial year
$fy_query = "SELECT id, year_code FROM financial_years WHERE status = 1 AND is_closed = 0 LIMIT 1";
$fy_stmt = $conn->prepare($fy_query);
$fy_stmt->execute();
$financial_year = $fy_stmt->fetch(PDO::FETCH_ASSOC);

if (!$financial_year) {
    $_SESSION['failure'] = "No active financial year found. Please create an active financial year first.";
    header('Location: purchase_returns.php');
    exit();
}

// Get suppliers
$supplier_query = "SELECT id, supplier_name as name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name";
$supplier_stmt = $conn->prepare($supplier_query);
$supplier_stmt->execute();
$suppliers = $supplier_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get locations
$location_query = "SELECT id, location_name FROM inventory_locations WHERE status = 1 ORDER BY location_name";
$location_stmt = $conn->prepare($location_query);
$location_stmt->execute();
$locations = $location_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../../includes/header.php'; ?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-undo-alt"></i> <?php echo $page_title; ?></h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="purchase_returns.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Returns
                    </a>
                </div>
            </div>
            
            <form id="purchase-return-form">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h3 class="card-title">Return Information</h3>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="supplier_id">Supplier <span class="text-danger">*</span></label>
                                            <select class="form-control select2" id="supplier_id" name="supplier_id" required>
                                                <option value="">-- Select Supplier --</option>
                                                <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="invoice_id">Purchase Invoice <span class="text-danger">*</span></label>
                                            <select class="form-control select2" id="invoice_id" name="invoice_id" disabled required>
                                                <option value="">-- Select Invoice --</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="return_date">Return Date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" id="return_date" name="return_date" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="location_id">Return Location <span class="text-danger">*</span></label>
                                            <select class="form-control select2" id="location_id" name="location_id" required>
                                                <option value="">-- Select Location --</option>
                                                <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['id']; ?>"><?php echo htmlspecialchars($location['location_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="form-group">
                                            <label for="notes">Notes</label>
                                            <textarea class="form-control" id="notes" name="notes" rows="1"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Return Items</h3>
                            </div>
                            <div class="card-body">
                                <div id="items-container">
                                    <div class="alert alert-info">
                                        Please select a supplier and invoice to load items.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Additional Information</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-group mb-3">
                                    <label for="return_type">Return Type</label>
                                    <select class="form-control" id="return_type" name="return_type">
                                        <option value="partial">Partial Return</option>
                                        <option value="full">Full Return</option>
                                    </select>
                                    <small class="form-text text-muted">Select 'Full Return' if returning all items from the invoice</small>
                                </div>
                                <div class="form-group">
                                    <label for="delivery_charges">Delivery Charges Return</label>
                                    <input type="number" class="form-control" id="delivery_charges" name="delivery_charges" value="0" min="0" step="0.01">
                                </div>
                                <div class="form-group mt-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="include_delivery_charges" name="include_delivery_charges" value="1" checked>
                                        <label class="form-check-label" for="include_delivery_charges">
                                            Include delivery charges in accounting entries
                                        </label>
                                    </div>
                                </div>
                                <small class="form-text text-muted">Enter delivery charges to be returned (if applicable)</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Summary</h3>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table">
                                        <tr>
                                            <th style="width:50%">Subtotal:</th>
                                            <td><span id="subtotal">0.00</span></td>
                                        </tr>
                                        <tr>
                                            <th>Discount:</th>
                                            <td><span id="discount_amount">0.00</span></td>
                                        </tr>
                                        <tr>
                                            <th>Tax:</th>
                                            <td><span id="tax_amount">0.00</span></td>
                                        </tr>
                                        <tr>
                                            <th>Delivery Charges Return:</th>
                                            <td><span id="delivery_charges_display">0.00</span></td>
                                        </tr>
                                        <tr>
                                            <th>Total:</th>
                                            <td><span id="total_amount">0.00</span></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary float-right">Save Purchase Return</button>
                        <a href="purchase_returns.php" class="btn btn-secondary float-right mr-2">Cancel</a>
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
    $('.select2').select2();

    // Load invoices when supplier is selected
    $('#supplier_id').change(function() {
        var supplierId = $(this).val();
        console.log('Supplier selected:', supplierId);
        
        if (supplierId) {
            // Show loading indicator
            $('#invoice_id').html('<option>Loading invoices...</option>');
            
            $.ajax({
                url: 'actions/get_supplier_invoices.php',
                type: 'POST',
                data: {supplier_id: supplierId},
                dataType: 'json',
                success: function(response) {
                    console.log('Received invoice data', response);
                    
                    // Debug information
                    if (response.debug_info) {
                        console.log('Debug info:', response.debug_info);
                    }
                    
                    var invoices = response.invoices || response;
                    
                    if (invoices.length === 0) {
                        $('#invoice_id').html('<option value="">No eligible invoices found</option>');
                        $('#invoice_id').prop('disabled', true);
                        
                        // Show a clear message to the user
                        Swal.fire({
                            title: 'No Eligible Invoices',
                            html: 'No eligible invoices found for this supplier. <br><br>This could be because:<br>1. All invoices already have returns created<br>2. There are no approved invoices for this supplier',
                            icon: 'info',
                            confirmButtonText: 'OK'
                        });
                        
                        return;
                    }
                    
                    var options = '<option value="">-- Select Invoice --</option>';
                    
                    $.each(invoices, function(i, invoice) {
                        var invoiceDate = new Date(invoice.invoice_date).toLocaleDateString();
                        var status = invoice.payment_status ? ' (' + invoice.payment_status + ')' : '';
                        options += '<option value="' + invoice.id + '">' + invoice.invoice_number + ' - ' + invoiceDate + status + '</option>';
                    });
                    
                    $('#invoice_id').html(options);
                    $('#invoice_id').prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    console.error('Error loading invoices:', error);
                    $('#invoice_id').html('<option value="">Error loading invoices</option>');
                }
            });
        } else {
            $('#invoice_id').html('<option value="">-- Select Invoice --</option>');
            $('#invoice_id').prop('disabled', true);
            $('#items-container').html('<div class="alert alert-info">Please select a supplier and invoice to load items.</div>');
        }
    });

    // Load invoice items when invoice is selected
    $('#invoice_id').change(function() {
        var invoiceId = $(this).val();
        if (invoiceId) {
            // Load invoice items
            $.ajax({
                url: 'actions/get_invoice_items_for_return.php',
                type: 'POST',
                data: {invoice_id: invoiceId},
                success: function(data) {
                    $('#items-container').html(data);
                    // Initialize any item-specific events
                    initializeItemEvents();
                    // Calculate totals
                    calculateTotals();
                    
                    // Get max delivery charges and invoice details
                    $.ajax({
                        url: 'actions/get_invoice_delivery_charges.php',
                        type: 'POST',
                        data: {invoice_id: invoiceId},
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                $('#delivery_charges').attr('max', response.max_delivery_charges);
                                $('#delivery_charges').attr('data-original', response.max_delivery_charges);
                                
                                // If include_delivery_charges is checked and return type is full, set to max
                                if ($('#include_delivery_charges').is(':checked') && $('#return_type').val() === 'full') {
                                    $('#delivery_charges').val(response.max_delivery_charges);
                                }
                                
                                // Store invoice totals for validation
                                $('#purchase-return-form').data('invoice-subtotal', response.invoice_subtotal);
                                $('#purchase-return-form').data('invoice-tax', response.invoice_tax);
                                $('#purchase-return-form').data('invoice-discount', response.invoice_discount);
                                $('#purchase-return-form').data('invoice-total', response.invoice_total);
                                $('#purchase-return-form').data('returned-subtotal', response.returned_subtotal);
                                $('#purchase-return-form').data('returned-tax', response.returned_tax);
                                $('#purchase-return-form').data('returned-discount', response.returned_discount);
                                $('#purchase-return-form').data('returned-total', response.returned_total);
                                
                                // Calculate remaining amounts
                                var remainingTotal = response.invoice_total - response.returned_total;
                                if (remainingTotal <= 0.01) {
                                    // If nothing left to return, show warning
                                    Swal.fire({
                                        icon: 'warning',
                                        title: 'Full Return Already Processed',
                                        text: 'This invoice has already been fully returned. No more returns can be processed.'
                                    });
                                }
                                
                                calculateTotals();
                            }
                        }
                    });
                    
                    // Auto-load location from invoice
                    $.ajax({
                        url: 'actions/get_invoice_location.php',
                        type: 'POST',
                        data: {invoice_id: invoiceId},
                        dataType: 'json',
                        success: function(response) {
                            if (response.success && response.location_id) {
                                $('#location_id').val(response.location_id).trigger('change');
                            }
                        }
                    });
                }
            });
        } else {
            $('#items-container').html('<div class="alert alert-info">Please select a supplier and invoice to load items.</div>');
        }
    });

    // Initialize item events
    function initializeItemEvents() {
        // Update item total when quantity changes
        $('.return-quantity').on('input', function() {
            var row = $(this).closest('tr');
            var maxQty = parseFloat(row.find('.return-quantity').attr('max'));
            var qty = parseFloat($(this).val()) || 0;
            
            // Validate quantity
            if (qty > maxQty) {
                $(this).val(maxQty);
                qty = maxQty;
            } else if (qty < 0) {
                $(this).val(0);
                qty = 0;
            }
            
            var unitPrice = parseFloat(row.find('.unit-price').attr('data-value'));
            var discountPercent = parseFloat(row.find('.discount-percent').attr('data-value')) || 0;
            var taxRate = parseFloat(row.find('.tax-rate').attr('data-value'));
            
            // Calculate item total with discount
            var itemSubtotal = qty * unitPrice;
            var itemDiscount = itemSubtotal * (discountPercent / 100);
            var itemSubtotalAfterDiscount = itemSubtotal - itemDiscount;
            var itemTax = itemSubtotalAfterDiscount * (taxRate / 100);
            var itemTotal = itemSubtotalAfterDiscount + itemTax;
            
            row.find('.item-subtotal').text(itemSubtotal.toFixed(2));
            row.find('.item-discount').text(itemDiscount.toFixed(2));
            row.find('.item-tax').text(itemTax.toFixed(2));
            row.find('.item-total').text(itemTotal.toFixed(2));
            
            // Update totals
            calculateTotals();
        });
    }

    // Calculate totals
    function calculateTotals() {
        var subtotal = 0;
        var totalDiscount = 0;
        var taxAmount = 0;
        var deliveryCharges = parseFloat($('#delivery_charges').val()) || 0;
        
        // Sum up item totals
        $('.item-subtotal').each(function() {
            subtotal += parseFloat($(this).text()) || 0;
        });
        
        $('.item-discount').each(function() {
            totalDiscount += parseFloat($(this).text()) || 0;
        });
        
        $('.item-tax').each(function() {
            taxAmount += parseFloat($(this).text()) || 0;
        });
        
        var subtotalAfterDiscount = subtotal - totalDiscount;
        var total = subtotalAfterDiscount + taxAmount + deliveryCharges;
        
        // Update summary
        $('#subtotal').text(subtotal.toFixed(2));
        $('#discount_amount').text(totalDiscount.toFixed(2));
        $('#tax_amount').text(taxAmount.toFixed(2));
        $('#delivery_charges_display').text(deliveryCharges.toFixed(2));
        $('#total_amount').text(total.toFixed(2));
    }

    // Update totals when delivery charges change
    $('#delivery_charges').on('input', function() {
        var maxDeliveryCharges = parseFloat($(this).attr('data-original')) || 0;
        var deliveryCharges = parseFloat($(this).val()) || 0;
        
        // Validate delivery charges
        if (deliveryCharges > maxDeliveryCharges) {
            $(this).val(maxDeliveryCharges);
            deliveryCharges = maxDeliveryCharges;
        } else if (deliveryCharges < 0) {
            $(this).val(0);
            deliveryCharges = 0;
        }
        
        calculateTotals();
    });
    
    // Handle return type selection
    $('#return_type').on('change', function() {
        var returnType = $(this).val();
        
        if (returnType === 'full') {
            // For full returns, set all quantities to max
            $('.return-quantity').each(function() {
                var maxQty = parseFloat($(this).attr('max'));
                $(this).val(maxQty);
                $(this).trigger('input'); // Trigger calculation
            });
            
            // Set delivery charges to max if include_delivery_charges is checked
            if ($('#include_delivery_charges').is(':checked')) {
                var maxDeliveryCharges = parseFloat($('#delivery_charges').attr('data-original')) || 0;
                $('#delivery_charges').val(maxDeliveryCharges);
                $('#delivery_charges').trigger('input');
            }
        }
    });
    
    // Handle include delivery charges checkbox
    $('#include_delivery_charges').on('change', function() {
        if ($(this).is(':checked')) {
            // If return type is full, set delivery charges to max
            if ($('#return_type').val() === 'full') {
                var maxDeliveryCharges = parseFloat($('#delivery_charges').attr('data-original')) || 0;
                $('#delivery_charges').val(maxDeliveryCharges);
                $('#delivery_charges').trigger('input');
            }
        } else {
            // If unchecked, set delivery charges to 0
            $('#delivery_charges').val(0);
            $('#delivery_charges').trigger('input');
        }
    });

    // Form submission
    $('#purchase-return-form').submit(function(e) {
        e.preventDefault();
        
        // Check if any items have return quantity
        var hasItems = false;
        $('.return-quantity').each(function() {
            if (parseFloat($(this).val()) > 0) {
                hasItems = true;
                return false; // Break the loop
            }
        });
        
        if (!hasItems) {
            Swal.fire({
                icon: 'error',
                title: 'No Items',
                text: 'Please enter return quantity for at least one item.'
            });
            return;
        }
        
        // Validate return amounts against invoice limits
        var subtotal = parseFloat($('#subtotal').text()) || 0;
        var discount = parseFloat($('#discount_amount').text()) || 0;
        var tax = parseFloat($('#tax_amount').text()) || 0;
        var deliveryCharges = parseFloat($('#delivery_charges').val()) || 0;
        var total = parseFloat($('#total_amount').text()) || 0;
        
        // Get stored invoice data
        var invoiceSubtotal = parseFloat($(this).data('invoice-subtotal')) || 0;
        var invoiceDiscount = parseFloat($(this).data('invoice-discount')) || 0;
        var invoiceTax = parseFloat($(this).data('invoice-tax')) || 0;
        var invoiceTotal = parseFloat($(this).data('invoice-total')) || 0;
        var returnedSubtotal = parseFloat($(this).data('returned-subtotal')) || 0;
        var returnedDiscount = parseFloat($(this).data('returned-discount')) || 0;
        var returnedTax = parseFloat($(this).data('returned-tax')) || 0;
        var returnedTotal = parseFloat($(this).data('returned-total')) || 0;
        
        // Calculate what would be the total returned after this return
        var totalReturnedSubtotal = returnedSubtotal + subtotal;
        var totalReturnedDiscount = returnedDiscount + discount;
        var totalReturnedTax = returnedTax + tax;
        var totalReturnedTotal = returnedTotal + total;
        
        // Check if any amount exceeds the original invoice
        var errors = [];
        if (totalReturnedSubtotal > (invoiceSubtotal + 0.01)) {
            errors.push('Subtotal amount exceeds the original invoice subtotal');
        }
        if (totalReturnedDiscount > (invoiceDiscount + 0.01)) {
            errors.push('Discount amount exceeds the original invoice discount');
        }
        if (totalReturnedTax > (invoiceTax + 0.01)) {
            errors.push('Tax amount exceeds the original invoice tax');
        }
        if (totalReturnedTotal > (invoiceTotal + 0.01)) {
            errors.push('Total amount exceeds the original invoice total');
        }
        
        if (errors.length > 0) {
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                html: errors.join('<br>')
            });
            return;
        }
        
        // Collect form data
        var formData = $(this).serializeArray();
        
        // Add items data
        var items = [];
        $('.return-item').each(function() {
            var row = $(this);
            var quantity = parseFloat(row.find('.return-quantity').val()) || 0;
            
            if (quantity > 0) {
                items.push({
                    invoice_item_id: row.data('item-id'),
                    product_id: row.data('product-id'),
                    quantity: quantity,
                    uom_id: row.data('uom-id'),
                    unit_price: parseFloat(row.find('.unit-price').attr('data-value')),
                    discount_percent: parseFloat(row.find('.discount-percent').attr('data-value')) || 0,
                    tax_rate: parseFloat(row.find('.tax-rate').attr('data-value')),
                    batch_number: row.data('batch-number'),
                    expiry_date: row.data('expiry-date')
                });
            }
        });
        
        formData.push({name: 'items', value: JSON.stringify(items)});
        
        // Add totals
        formData.push({name: 'subtotal', value: $('#subtotal').text()});
        formData.push({name: 'discount_amount', value: $('#discount_amount').text()});
        formData.push({name: 'tax_amount', value: $('#tax_amount').text()});
        formData.push({name: 'total_amount', value: $('#total_amount').text()});
        
        // Submit form
        $.ajax({
            url: 'actions/save_purchase_return.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: response.message,
                        showCancelButton: true,
                        confirmButtonText: 'View Return',
                        cancelButtonText: 'Create Another'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = 'purchase_return_detail.php?id=' + response.return_id;
                        } else {
                            window.location.href = 'purchase_return_create.php';
                        }
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message
                    });
                }
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while processing the request.'
                });
            }
        });
    });
});
</script>
