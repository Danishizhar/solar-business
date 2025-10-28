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
$page_title = "Delivery Notes";

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

// Get all delivery notes with sales order info
$query = "SELECT dn.*, so.order_number, s.customer_name, s.customer_code,
          u.username as created_by_name,
          COALESCE(dn.invoice_status, 'pending') as invoice_status
          FROM delivery_notes dn
          LEFT JOIN sales_orders so ON dn.sales_order_id = so.id
          LEFT JOIN customers s ON so.customer_id = s.id
          LEFT JOIN users u ON dn.created_by = u.id
          ORDER BY dn.id DESC";

try {
    // Check if delivery_notes table exists
    $tableCheckQuery = "SHOW TABLES LIKE 'delivery_notes'";
    $tableCheckStmt = $conn->prepare($tableCheckQuery);
    $tableCheckStmt->execute();
    if ($tableCheckStmt->rowCount() == 0) {
        echo "<div class='alert alert-danger'>The delivery_notes table does not exist. Please run the SQL setup script.</div>";
    }
    
    // Main query
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $dn_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug information
    if (empty($dn_list)) {
        echo "<div class='alert alert-info'>No delivery notes found.</div>";
    }
} catch (PDOException $e) {
    $dn_list = [];
    // Log error and display to user
    $errorMessage = "Error fetching delivery notes: " . $e->getMessage();
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
                    <h2><i class="fas fa-clipboard-check"></i> Delivery Notes</h2>
                </div>
                <div class="col-md-6 text-end">
                    <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_add'])): ?>
                        <a href="delivery_note_create.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create New DN
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Delivery Notes List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="dnTable" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th style="display:none;">ID</th>
                                    <th width="10%">DN #</th>
                                    <th width="15%">Sales Order</th>
                                    <th width="15%">Customer</th>
                                    <th width="10%">Received Date</th>
                                    <th width="10%">Status</th>
                                    <th width="10%">Invoice Status</th>
                                    <th width="12%">Created By</th>
                                    <th width="18%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dn_list as $dn): ?>
                                <tr>
                                    <td style="display:none;"><?php echo $dn['id']; ?></td>
                                    <td><?php echo htmlspecialchars($dn['delivery_note_number']); ?></td>
                                    <td><?php echo htmlspecialchars($dn['order_number']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($dn['customer_name']); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($dn['customer_code']); ?></small>
                                    </td>
                                    <td class="text-center"><?php echo date('d M Y', strtotime($dn['received_date'])); ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $status_colors[$dn['status']]; ?>">
                                            <?php echo ucfirst($dn['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $invoice_status = $dn['invoice_status'] ?? 'pending';
                                        $invoice_badge_color = $invoice_status_colors[$invoice_status] ?? 'badge-secondary';
                                        ?>
                                        <span class="badge <?php echo $invoice_badge_color; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $invoice_status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($dn['created_by_name']); ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="delivery_note_detail.php?id=<?php echo $dn['id']; ?>" class="btn btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if ($dn['status'] == 'completed' && ($dn['invoice_status'] == 'pending' || $dn['invoice_status'] == 'partially_invoiced') && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_add']))): ?>
                                            <a href="sales_invoice_from_delivery_note.php?delivery_note_id=<?php echo $dn['id']; ?>" class="btn btn-success" title="Create Invoice">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($dn['status'] != 'cancelled' && $dn['invoice_status'] == 'pending' && ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_delete']))): ?>
                                            <button type="button" class="btn btn-danger cancel-dn" data-id="<?php echo $dn['id']; ?>" title="Cancel">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                            <?php endif; ?>
                                            
                                            <button type="button" class="btn btn-secondary print-dn" data-id="<?php echo $dn['id']; ?>" title="Print">
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

<!-- Cancel DN Modal -->
<div class="modal fade" id="cancelDNModal" tabindex="-1" role="dialog" aria-labelledby="cancelDNModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cancelDNModalLabel">Cancel Delivery Note</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel this DN? This will reverse all inventory changes and cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form id="cancelDNForm" action="actions/cancel_delivery_note.php" method="post">
                    <input type="hidden" name="delivery_note_id" id="cancel_delivery_note_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger">Cancel DN</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#dnTable').DataTable({
        "order": [[0, "desc"]],
        "columnDefs": [
            { "type": "num", "targets": 0 },
            { "visible": false, "targets": 0 }
        ]
    });
    
    // Cancel DN button click
    $('.cancel-dn').click(function() {
        const dnId = $(this).data('id');
        $('#cancel_delivery_note_id').val(dnId);
        $('#cancelDNModal').modal('show');
    });
    
    // Print DN button click
    $('.print-dn').click(function() {
        const dnId = $(this).data('id');
        window.open('delivery_note_print.php?id=' + dnId, '_blank');
    });
});
</script>
