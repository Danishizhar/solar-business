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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['payment_view'])) {
    header("Location: ../../index.php");
    exit();
}

// Include database connection
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Set page title
$pageTitle = "All Payments";
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-money-bill-wave"></i> All Payments</h2>
                </div>
                <div class="col-md-6 text-end">
                    <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['payment_add'])): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#selectInvoiceModal">
                            <i class="fas fa-plus"></i> Record Purchase Payment
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Success Alert -->
            <div class="alert alert-success alert-dismissible fade" role="alert" id="successAlert" style="display: none;">
                <span id="successMessage"></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            
            <!-- Error Alert -->
            <div class="alert alert-danger alert-dismissible fade" role="alert" id="errorAlert" style="display: none;">
                <span id="errorMessage"></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">All Payment Transactions</h6>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="refreshBtn">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="paymentsTable" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Voucher Number</th>
                                    <th>Type</th>
                                    <th>Invoice Number</th>
                                    <th>Entity</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Data will be loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View Payment Modal -->
<div class="modal fade" id="viewPaymentModal" tabindex="-1" aria-labelledby="viewPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewPaymentModalLabel">Payment Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="paymentDetailsContent">
                <!-- Payment details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="printPaymentBtn">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Select Invoice Modal -->
<div class="modal fade" id="selectInvoiceModal" tabindex="-1" aria-labelledby="selectInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="selectInvoiceModalLabel">Select Purchase Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="selectInvoiceForm">
                    <div class="mb-3">
                        <label for="invoice_id" class="form-label">Select Invoice</label>
                        <select class="form-select" id="invoice_id" name="invoice_id" required>
                            <option value="">-- Select Invoice --</option>
                            <?php
                            // Get unpaid and partially paid purchase invoices with approved status (excluding cancelled)
                            $invoice_query = "SELECT pi.id, pi.invoice_number, pi.total_amount, pi.paid_amount, 
                                             (pi.total_amount - pi.paid_amount) as balance_amount, 
                                             s.supplier_name, pi.payment_status
                                      FROM purchase_invoices pi
                                      LEFT JOIN suppliers s ON pi.supplier_id = s.id
                                      WHERE pi.status != 'draft' 
                                      AND pi.status != 'cancelled'
                                      AND pi.payment_status IN ('unpaid', 'partial')
                                      ORDER BY pi.invoice_date DESC";
                            
                            try {
                                $invoice_stmt = $conn->prepare($invoice_query);
                                $invoice_stmt->execute();
                                $invoices = $invoice_stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($invoices as $invoice) {
                                    echo "<option value=\"{$invoice['id']}\">{$invoice['invoice_number']} - {$invoice['supplier_name']}</option>";
                                }
                            } catch (PDOException $e) {
                                echo "<option value=\"\">Error loading invoices</option>";
                            }
                            ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="proceedToPaymentBtn">Proceed to Payment</button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<!-- Add moment.js library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    var table = $('#paymentsTable').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            "url": "actions/get_all_payments.php",
            "type": "POST",
            "error": function(xhr, error, thrown) {
                console.log('DataTables error: ' + error);
            }
        },
        "columns": [
            { "data": "payment_date", "name": "Payment Date" },
            { "data": "reference_number", "name": "Voucher Number" },
            { "data": "payment_type", "name": "Type" },
            { "data": "invoice_number", "name": "Invoice" },
            { "data": "entity_name", "name": "Entity" },
            { "data": "amount", "name": "Amount" },
            { "data": "payment_method", "name": "Method" },
            { "data": "status", "name": "Status" },
            { "data": null, "orderable": false }
        ],
        "columnDefs": [
            {
                "targets": 0,
                "render": function(data, type, row) {
                    return data ? moment(data).format('DD MMM YYYY') : '';
                }
            },
            {
                "targets": 2,
                "render": function(data, type, row) {
                    if (data === 'purchase') {
                        return '<span class="badge bg-primary">Purchase</span>';
                    } else {
                        return '<span class="badge bg-success">Sales</span>';
                    }
                }
            },
            {
                "targets": 5,
                "render": function(data, type, row) {
                    return data ? parseFloat(data).toFixed(2) : '0.00';
                }
            },
            {
                "targets": 7,
                "render": function(data, type, row) {
                    if (data === 'completed') {
                        return '<span class="badge bg-success">Completed</span>';
                    } else {
                        return '<span class="badge bg-danger">Cancelled</span>';
                    }
                }
            },
            {
                "targets": 8,
                "render": function(data, type, row) {
                    let actions = '<button class="btn btn-sm btn-info view-payment" data-id="' + row.id + '" data-type="' + row.payment_type + '"><i class="fas fa-eye"></i></button> ';
                    actions += '<button class="btn btn-sm btn-secondary print-payment" data-id="' + row.id + '" data-type="' + row.payment_type + '"><i class="fas fa-print"></i></button> ';
                    
                    if (row.status === 'completed') {
                        actions += '<button class="btn btn-sm btn-danger cancel-payment" data-id="' + row.id + '" data-type="' + row.payment_type + '"><i class="fas fa-times"></i></button>';
                    }
                    
                    return actions;
                }
            }
        ],
        order: [[0, 'desc']]
    });
    
    // Refresh button
    $('#refreshBtn').on('click', function() {
        table.ajax.reload();
    });
    
    // View payment details
    $('#paymentsTable').on('click', '.view-payment', function() {
        var id = $(this).data('id');
        var type = $(this).data('type');
        
        $.ajax({
            url: 'actions/get_payment_details.php',
            type: 'POST',
            data: {
                id: id,
                type: type
            },
            success: function(response) {
                $('#paymentDetailsContent').html(response);
                $('#viewPaymentModal').modal('show');
            },
            error: function(xhr, status, error) {
                showErrorMessage('Error loading payment details: ' + error);
            }
        });
    });
    
    // Print payment
    $('#paymentsTable').on('click', '.print-payment', function() {
        var id = $(this).data('id');
        var type = $(this).data('type');
        
        window.open('payment_print.php?id=' + id + '&type=' + type, '_blank');
    });
    
    // Print from modal
    $('#printPaymentBtn').on('click', function() {
        var id = $('#viewPaymentModal').find('.payment-id').val();
        var type = $('#viewPaymentModal').find('.payment-type').val();
        
        if (id && type) {
            window.open('payment_print.php?id=' + id + '&type=' + type, '_blank');
        }
    });
    
    // Cancel payment
    $('#paymentsTable').on('click', '.cancel-payment', function() {
        var id = $(this).data('id');
        var type = $(this).data('type');
        
        if (confirm('Are you sure you want to cancel this payment? This action cannot be undone.')) {
            $.ajax({
                url: 'actions/cancel_payment.php',
                type: 'POST',
                data: {
                    id: id,
                    type: type
                },
                success: function(response) {
                    var result = JSON.parse(response);
                    if (result.success) {
                        showSuccessMessage(result.message);
                        table.ajax.reload();
                    } else {
                        showErrorMessage(result.message);
                    }
                },
                error: function(xhr, status, error) {
                    showErrorMessage('Error cancelling payment: ' + error);
                }
            });
        }
    });
    
    // Helper functions for alerts
    function showSuccessMessage(message) {
        $('#successMessage').text(message);
        $('#successAlert').addClass('show').show();
        setTimeout(function() {
            $('#successAlert').removeClass('show').hide();
        }, 3000);
    }
    
    function showErrorMessage(message) {
        $('#errorMessage').text(message);
        $('#errorAlert').addClass('show').show();
        setTimeout(function() {
            $('#errorAlert').removeClass('show').hide();
        }, 3000);
    }
    
    // Handle Proceed to Payment button click
    $('#proceedToPaymentBtn').on('click', function() {
        const invoiceId = $('#invoice_id').val();
        
        if (!invoiceId) {
            showErrorMessage('Please select an invoice');
            return;
        }
        
        // Redirect to payment create page with the selected invoice
        window.location.href = 'payment_create.php?invoice_id=' + invoiceId + '&type=purchase';
    });
});
</script>
