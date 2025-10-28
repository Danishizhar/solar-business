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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['sales_view'])) {
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
$page_title = "Sales Orders";

// Include header
include '../../includes/header.php';

// Define status colors for badges
$status_colors = [
    'draft' => 'bg-secondary',
    'pending' => 'bg-warning',
    'approved' => 'bg-success',
    'partial' => 'bg-info',
    'received' => 'bg-primary',
    'cancelled' => 'bg-danger'
];

// Get all sales orders with customer info
$query = "SELECT so.*, s.customer_name, s.customer_code,
          u.username as created_by_name
          FROM sales_orders so
          LEFT JOIN customers s ON so.customer_id = s.id
          LEFT JOIN users u ON so.created_by = u.id
          ORDER BY so.created_at DESC";

try {
    // Debug - check if sales_orders table exists
    $tableCheckQuery = "SHOW TABLES LIKE 'sales_orders'";
    $tableCheckStmt = $conn->prepare($tableCheckQuery);
    $tableCheckStmt->execute();
    if ($tableCheckStmt->rowCount() == 0) {
        echo "<div class='alert alert-danger'>The sales_orders table does not exist. Please run the SQL setup script.</div>";
    }
    
    // Debug - count records in sales_orders table
    $countQuery = "SELECT COUNT(*) FROM sales_orders";
    $countStmt = $conn->prepare($countQuery);
    $countStmt->execute();
    $orderCount = $countStmt->fetchColumn();
    
    // Main query
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $sales_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug information
    if (empty($sales_orders)) {
        echo "<div class='alert alert-info'>No sales orders found. Total records in database: $orderCount</div>";
    }
} catch (PDOException $e) {
    $sales_orders = [];
    // Log error and display to user
    $errorMessage = "Error fetching sales orders: " . $e->getMessage();
    error_log($errorMessage);
    echo "<div class='alert alert-danger'>$errorMessage</div>";
}

// Status badge colors
$status_colors = [
    'draft' => 'bg-secondary',
    'confirmed' => 'bg-primary',
    'cancelled' => 'bg-danger',
    'completed' => 'bg-success'
];
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-file-invoice"></i> Sales Order Management</h2>
                </div>
                <div class="col-md-6 text-end">
                    <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_add'])): ?>
                        <a href="sales_order_create.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create New Sales Order
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Sales Orders List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="salesOrdersTable" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th width="10%">Order #</th>
                                    <th width="20%">Customer</th>
                                    <th width="10%">Order Date</th>
                                    <th width="12%">Expected Delivery</th>
                                    <th width="12%">Total Amount</th>
                                    <th width="8%">Status</th>
                                    <th width="15%">Created By</th>
                                    <th width="13%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sales_orders as $order): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($order['customer_name']); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($order['customer_code']); ?></small>
                                    </td>
                                    <td class="text-center"><?php echo date('d M Y', strtotime($order['order_date'])); ?></td>
                                    <td class="text-center">
                                        <?php 
                                        if (!empty($order['expected_delivery_date'])) {
                                            echo date('d M Y', strtotime($order['expected_delivery_date']));
                                        } else {
                                            echo '<span class="text-muted">Not specified</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end"><?php echo htmlspecialchars($company['currency'] ?? 'PKR'); ?>&nbsp;<?php echo formatNumber($conn, $order['total_amount']); ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $status = strtolower($order['status']);
                                        $badge_class = isset($status_colors[$status]) ? $status_colors[$status] : 'bg-secondary';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($order['created_by_name']); ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="sales_order_detail.php?id=<?php echo $order['id']; ?>" class="btn btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if ($order['status'] == 'draft' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_edit']))): ?>
                                            <a href="sales_order_create.php?id=<?php echo $order['id']; ?>" class="btn btn-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php
                                            // Check if this SO has any DNs
                                            $has_dn_query = "SELECT COUNT(*) as dn_count FROM delivery_notes WHERE sales_order_id = :so_id AND status != 'cancelled'";
                                            $has_dn_stmt = $conn->prepare($has_dn_query);
                                            $has_dn_stmt->bindParam(':so_id', $order['id']);
                                            $has_dn_stmt->execute();
                                            $has_dn = $has_dn_stmt->fetch(PDO::FETCH_ASSOC)['dn_count'] > 0;
                                            
                                            // Check if this SO has any invoices
                                            $has_invoice_query = "SELECT COUNT(*) as invoice_count FROM sales_invoices WHERE sales_order_id = :so_id AND status != 'cancelled'";
                                            $has_invoice_stmt = $conn->prepare($has_invoice_query);
                                            $has_invoice_stmt->bindParam(':so_id', $order['id']);
                                            $has_invoice_stmt->execute();
                                            $has_invoice = $has_invoice_stmt->fetch(PDO::FETCH_ASSOC)['invoice_count'] > 0;
                                            
                                            // Show DN button if no invoices exist
                                            if (($order['status'] == 'confirmed' || $order['status'] == 'partial') && 
                                                !$has_invoice && 
                                                ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_add']))): ?>
                                            <a href="delivery_note_create.php?so_id=<?php echo $order['id']; ?>" class="btn btn-success" title="Create DN">
                                                <i class="fas fa-clipboard-check"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php 
                                            // Show Invoice button if no DNs exist
                                            if (($order['status'] == 'confirmed' || $order['status'] == 'partial') && 
                                                !$has_dn && 
                                                ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_add']))): ?>
                                            <a href="sales_invoice_from_order.php?so_id=<?php echo $order['id']; ?>" class="btn btn-primary" title="Create Invoice">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($order['status'] == 'draft' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_delete']))): ?>
                                            <button type="button" class="btn btn-danger delete-order" data-id="<?php echo $order['id']; ?>" title="Delete">
                                                <i class="fas fa-trash"></i>
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

<!-- Delete Sales Order Modal -->
<div class="modal fade" id="deleteSalesOrderModal" tabindex="-1" role="dialog" aria-labelledby="deleteSalesOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteSalesOrderModalLabel">Delete Sales Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this sales order? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form id="deleteSalesOrderForm" action="actions/delete_sales_order.php" method="post">
                    <input type="hidden" name="sales_order_id" id="delete_sales_order_id">
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
        var table = $('#salesOrdersTable').DataTable({
            "responsive": true,
            "lengthChange": true,
            "autoWidth": false,
            "order": [[2, 'desc']], // Order by order date by default
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
        table.buttons().container().appendTo('#salesOrdersTable_wrapper .col-md-6:eq(0)');
    } catch (e) {
        console.error("DataTables initialization error:", e);
    }
    
    // Delete Sales Order
    $('.delete-order').on('click', function() {
        var orderId = $(this).data('id');
        $('#delete_sales_order_id').val(orderId);
        $('#deleteSalesOrderModal').modal('show');
    });
});
</script>
