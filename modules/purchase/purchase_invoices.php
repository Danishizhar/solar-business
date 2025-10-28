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

// Set page title
$page_title = "Purchase Invoices";

// Include header
include '../../includes/header.php';

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

// Get all purchase invoices with supplier info
$query = "SELECT pi.*, s.supplier_name, s.supplier_code,
          u.username as created_by_name
          FROM purchase_invoices pi
          LEFT JOIN suppliers s ON pi.supplier_id = s.id
          LEFT JOIN users u ON pi.created_by = u.id
          ORDER BY pi.created_at DESC";

try {
    // Debug - check if purchase_invoices table exists
    $tableCheckQuery = "SHOW TABLES LIKE 'purchase_invoices'";
    $tableCheckStmt = $conn->prepare($tableCheckQuery);
    $tableCheckStmt->execute();
    if ($tableCheckStmt->rowCount() == 0) {
        echo "<div class='alert alert-danger'>The purchase_invoices table does not exist. Please run the SQL setup script.</div>";
    }
    
    // Debug - count records in purchase_invoices table
    $countQuery = "SELECT COUNT(*) FROM purchase_invoices";
    $countStmt = $conn->prepare($countQuery);
    $countStmt->execute();
    $invoiceCount = $countStmt->fetchColumn();
    
    // Main query
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $purchase_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug information
    if (empty($purchase_invoices)) {
        echo "<div class='alert alert-info'>No purchase invoices found. Total records in database: $invoiceCount</div>";
    }
} catch (PDOException $e) {
    $purchase_invoices = [];
    // Log error and display to user
    $errorMessage = "Error fetching purchase invoices: " . $e->getMessage();
    error_log($errorMessage);
    echo "<div class='alert alert-danger'>$errorMessage</div>";
}
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-file-invoice-dollar"></i> Purchase Invoice Management</h2>
                </div>
                <div class="col-md-6 text-end">
                    <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_add'])): ?>
                        <a href="purchase_invoice_create.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create New Purchase Invoice
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Purchase Invoices List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="purchaseInvoicesTable" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th width="10%">Invoice #</th>
                                    <th width="15%">Supplier</th>
                                    <th width="10%">Invoice Date</th>
                                    <th width="10%">Due Date</th>
                                    <th width="12%">Total Amount</th>
                                    <th width="8%">Status</th>
                                    <th width="8%">Payment</th>
                                    <th width="15%">Created By</th>
                                    <th width="12%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($purchase_invoices as $invoice): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($invoice['supplier_name']); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($invoice['supplier_code']); ?></small>
                                    </td>
                                    <td class="text-center"><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></td>
                                    <td class="text-center">
                                        <?php 
                                        if (!empty($invoice['due_date'])) {
                                            echo date('d M Y', strtotime($invoice['due_date']));
                                        } else {
                                            echo '<span class="text-muted">Not specified</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $invoice['total_amount']); ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $status = strtolower($invoice['status']);
                                        $badge_class = isset($status_colors[$status]) ? $status_colors[$status] : 'bg-secondary';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo ucfirst($invoice['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $payment_status = strtolower($invoice['payment_status']);
                                        $payment_badge_class = isset($payment_status_colors[$payment_status]) ? $payment_status_colors[$payment_status] : 'bg-secondary';
                                        ?>
                                        <span class="badge <?php echo $payment_badge_class; ?>">
                                            <?php echo ucfirst($invoice['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($invoice['created_by_name']); ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="purchase_invoice_detail.php?id=<?php echo $invoice['id']; ?>" class="btn btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if ($invoice['status'] == 'draft' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_edit']))): ?>
                                            <a href="purchase_invoice_create.php?id=<?php echo $invoice['id']; ?>" class="btn btn-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($invoice['status'] == 'draft' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_delete']))): ?>
                                            <button type="button" class="btn btn-danger delete-invoice" data-id="<?php echo $invoice['id']; ?>" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($invoice['status'] != 'draft' && $invoice['status'] != 'cancelled' && $invoice['status'] != 'returned' && $invoice['payment_status'] != 'paid' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['payment_add']))): ?>
                                            <a href="../payment/payment_create.php?invoice_id=<?php echo $invoice['id']; ?>&type=purchase" class="btn btn-success" title="Record Payment">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($invoice['status'] != 'cancelled' && $invoice['payment_status'] != 'paid' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_cancel']))): ?>
                                            <button type="button" class="btn btn-warning cancel-invoice" data-id="<?php echo $invoice['id']; ?>" title="Cancel Invoice">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Purchase Invoice Modal -->
<div class="modal fade" id="deletePurchaseInvoiceModal" tabindex="-1" role="dialog" aria-labelledby="deletePurchaseInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deletePurchaseInvoiceModalLabel">Delete Purchase Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this purchase invoice? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form id="deletePurchaseInvoiceForm" action="actions/delete_purchase_invoice.php" method="post">
                    <input type="hidden" name="purchase_invoice_id" id="delete_purchase_invoice_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTable with try/catch to handle missing extensions
    try {
        var table = $('#purchaseInvoicesTable').DataTable({
            "responsive": true,
            "lengthChange": true,
            "autoWidth": false,
            "order": [[2, 'desc']], // Order by invoice date by default
            "columnDefs": [
                { "orderable": false, "targets": [7] } // Disable sorting on actions column
            ],
            "buttons": [
                {
                    extend: 'excel',
                    text: '<i class="fas fa-file-excel"></i> Excel',
                    className: 'btn-sm btn-success',
                    exportOptions: {
                        columns: [0, 1, 2, 3, 4, 5, 6]
                    }
                },
                {
                    extend: 'pdf',
                    text: '<i class="fas fa-file-pdf"></i> PDF',
                    className: 'btn-sm btn-danger',
                    exportOptions: {
                        columns: [0, 1, 2, 3, 4, 5, 6]
                    }
                },
                {
                    extend: 'print',
                    text: '<i class="fas fa-print"></i> Print',
                    className: 'btn-sm btn-info',
                    exportOptions: {
                        columns: [0, 1, 2, 3, 4, 5, 6]
                    }
                }
            ]
        });
        
        // Move export buttons to the card header
        table.buttons().container().appendTo('#purchaseInvoicesTable_wrapper .col-md-6:eq(0)');
    } catch (e) {
        console.error("DataTables initialization error:", e);
    }
    
    // Delete Purchase Invoice
    $('.delete-invoice').on('click', function() {
        var invoiceId = $(this).data('id');
        $('#delete_purchase_invoice_id').val(invoiceId);
        $('#deletePurchaseInvoiceModal').modal('show');
    });
    
    // Cancel Purchase Invoice
    $('.cancel-invoice').on('click', function() {
        var invoiceId = $(this).data('id');
        
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
                                return response;
                            } else {
                                Swal.showValidationMessage(`Request failed: ${response.message}`)
                                return false;
                            }
                        })
                        .fail(function(xhr, status, error) {
                            Swal.showValidationMessage(`Request failed: ${error}`)
                            return false;
                        });
                    },
                    allowOutsideClick: () => !Swal.isLoading()
                }).then((result) => {
                    if (result.isConfirmed && result.value && result.value.success) {
                        Swal.fire({
                            title: 'Cancelled!',
                            text: result.value.message,
                            icon: 'success'
                        }).then(() => {
                            // Reload the DataTable
                            $('#purchaseInvoicesTable').DataTable().ajax.reload();
                            // If not using AJAX, reload the page
                            location.reload();
                        });
                    }
                });
            }
        });
    });
});
</script>
