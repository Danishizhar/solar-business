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
$page_title = "Goods Receiving Notes";

// Define status colors
$status_colors = [
    'completed' => 'bg-success',
    'cancelled' => 'bg-danger',
    'pending' => 'bg-warning'
];

// Define invoice status colors
$invoice_status_colors = [
    'pending' => 'bg-secondary',
    'partially_invoiced' => 'bg-info',
    'invoiced' => 'bg-success'
];

// Include header
include '../../includes/header.php';

// Get all goods receiving notes with purchase order info
$query = "SELECT grn.*, po.order_number, s.supplier_name, s.supplier_code,
          u.username as created_by_name,
          COALESCE(grn.invoice_status, 'pending') as invoice_status
          FROM goods_receiving_notes grn
          LEFT JOIN purchase_orders po ON grn.purchase_order_id = po.id
          LEFT JOIN suppliers s ON po.supplier_id = s.id
          LEFT JOIN users u ON grn.created_by = u.id
          ORDER BY grn.id DESC";

try {
    // Check if goods_receiving_notes table exists
    $tableCheckQuery = "SHOW TABLES LIKE 'goods_receiving_notes'";
    $tableCheckStmt = $conn->prepare($tableCheckQuery);
    $tableCheckStmt->execute();
    if ($tableCheckStmt->rowCount() == 0) {
        echo "<div class='alert alert-danger'>The goods_receiving_notes table does not exist. Please run the SQL setup script.</div>";
    }
    
    // Main query
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $grn_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug information
    if (empty($grn_list)) {
        echo "<div class='alert alert-info'>No goods receiving notes found.</div>";
    }
} catch (PDOException $e) {
    $grn_list = [];
    // Log error and display to user
    $errorMessage = "Error fetching goods receiving notes: " . $e->getMessage();
    error_log($errorMessage);
    echo "<div class='alert alert-danger'>$errorMessage</div>";
}

// Status badge colors
$status_colors = [
    'completed' => 'bg-success',
    'cancelled' => 'bg-danger'
];
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-clipboard-check"></i> Goods Receiving Notes</h2>
                </div>
                <div class="col-md-6 text-end">
                    <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_add'])): ?>
                        <a href="grn_create.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create New GRN
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Goods Receiving Notes List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="grnTable" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th style="display:none;">ID</th>
                                    <th width="10%">GRN #</th>
                                    <th width="15%">Purchase Order</th>
                                    <th width="15%">Supplier</th>
                                    <th width="10%">Received Date</th>
                                    <th width="10%">Status</th>
                                    <th width="10%">Invoice Status</th>
                                    <th width="12%">Created By</th>
                                    <th width="18%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grn_list as $grn): ?>
                                <tr>
                                    <td style="display:none;"><?php echo $grn['id']; ?></td>
                                    <td><?php echo htmlspecialchars($grn['grn_number']); ?></td>
                                    <td><?php echo htmlspecialchars($grn['order_number']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($grn['supplier_name']); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($grn['supplier_code']); ?></small>
                                    </td>
                                    <td class="text-center"><?php echo date('d M Y', strtotime($grn['received_date'])); ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $status_colors[$grn['status']]; ?>">
                                            <?php echo ucfirst($grn['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $invoice_status = $grn['invoice_status'] ?? 'pending';
                                        $invoice_badge_color = $invoice_status_colors[$invoice_status] ?? 'badge-secondary';
                                        ?>
                                        <span class="badge <?php echo $invoice_badge_color; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $invoice_status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($grn['created_by_name']); ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="grn_detail.php?id=<?php echo $grn['id']; ?>" class="btn btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if ($grn['status'] == 'completed' && ($grn['invoice_status'] == 'pending' || $grn['invoice_status'] == 'partially_invoiced') && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_add']))): ?>
                                            <a href="purchase_invoice_from_grn.php?grn_id=<?php echo $grn['id']; ?>" class="btn btn-success" title="Create Invoice">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($grn['status'] != 'cancelled' && $grn['invoice_status'] == 'pending' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_delete']))): ?>
                                            <button type="button" class="btn btn-danger cancel-grn" data-id="<?php echo $grn['id']; ?>" title="Cancel">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                            <?php endif; ?>
                                            
                                            <button type="button" class="btn btn-secondary print-grn" data-id="<?php echo $grn['id']; ?>" title="Print">
                                                <i class="fas fa-print"></i>
                                            </button>
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

<!-- Cancel GRN Modal -->
<div class="modal fade" id="cancelGRNModal" tabindex="-1" role="dialog" aria-labelledby="cancelGRNModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cancelGRNModalLabel">Cancel Goods Receiving Note</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel this GRN? This will reverse all inventory changes and cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form id="cancelGRNForm" action="actions/cancel_grn.php" method="post">
                    <input type="hidden" name="grn_id" id="cancel_grn_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger">Cancel GRN</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#grnTable').DataTable({
        "order": [[0, "desc"]],
        "columnDefs": [
            { "type": "num", "targets": 0 },
            { "visible": false, "targets": 0 }
        ]
    });
    
    // Cancel GRN button click
    $('.cancel-grn').click(function() {
        const grnId = $(this).data('id');
        $('#cancel_grn_id').val(grnId);
        $('#cancelGRNModal').modal('show');
    });
    
    // Print GRN button click
    $('.print-grn').click(function() {
        const grnId = $(this).data('id');
        window.open('grn_print.php?id=' + grnId, '_blank');
    });
});
</script>
