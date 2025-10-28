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

// Check if supplier ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: suppliers.php");
    exit();
}

$supplier_id = $_GET['id'];

// Get supplier details
$query = "SELECT s.*, coa.account_code, coa.account_name 
          FROM suppliers s
          LEFT JOIN chart_of_accounts coa ON s.account_id = coa.id
          WHERE s.id = :id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':id', $supplier_id);
$stmt->execute();

if ($stmt->rowCount() == 0) {
    header("Location: suppliers.php");
    exit();
}

$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

// Get purchase orders for this supplier
$po_query = "SELECT po.*, u.username as created_by_name, 
            COALESCE(received.received_count, 0) as received_items,
            COALESCE(ordered.ordered_count, 0) as total_items
            FROM purchase_orders po
            LEFT JOIN users u ON po.created_by = u.id
            LEFT JOIN (
                SELECT purchase_order_id, COUNT(*) as ordered_count 
                FROM purchase_order_items 
                GROUP BY purchase_order_id
            ) ordered ON po.id = ordered.purchase_order_id
            LEFT JOIN (
                SELECT purchase_order_id, COUNT(*) as received_count 
                FROM purchase_order_items 
                WHERE received_quantity > 0
                GROUP BY purchase_order_id
            ) received ON po.id = received.purchase_order_id
            WHERE po.supplier_id = :supplier_id
            ORDER BY po.order_date DESC
            LIMIT 10";
$po_stmt = $conn->prepare($po_query);
$po_stmt->bindParam(':supplier_id', $supplier_id);
$po_stmt->execute();
$purchase_orders = $po_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get purchase invoices for this supplier
$invoice_query = "SELECT pi.*, u.username as created_by_name
                FROM purchase_invoices pi
                LEFT JOIN users u ON pi.created_by = u.id
                WHERE pi.supplier_id = :supplier_id
                ORDER BY pi.invoice_date DESC
                LIMIT 10";
$invoice_stmt = $conn->prepare($invoice_query);
$invoice_stmt->bindParam(':supplier_id', $supplier_id);
$invoice_stmt->execute();
$invoices = $invoice_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get goods receiving notes for this supplier
$grn_query = "SELECT grn.*, u.username as created_by_name, po.order_number
                FROM goods_receiving_notes grn
                LEFT JOIN users u ON grn.created_by = u.id
                LEFT JOIN purchase_orders po ON grn.purchase_order_id = po.id
                WHERE po.supplier_id = :supplier_id
                ORDER BY grn.received_date DESC
                LIMIT 10";
$grn_stmt = $conn->prepare($grn_query);
$grn_stmt->bindParam(':supplier_id', $supplier_id);
$grn_stmt->execute();
$grns = $grn_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get purchase returns for this supplier
$return_query = "SELECT pr.*, u.username as created_by_name, pi.invoice_number
                FROM purchase_returns pr
                LEFT JOIN users u ON pr.created_by = u.id
                LEFT JOIN purchase_invoices pi ON pr.invoice_id = pi.id
                WHERE pr.supplier_id = :supplier_id
                ORDER BY pr.return_date DESC
                LIMIT 10";
$return_stmt = $conn->prepare($return_query);
$return_stmt->bindParam(':supplier_id', $supplier_id);
$return_stmt->execute();
$returns = $return_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get supplier refunds for this supplier
$refund_query = "SELECT sr.*, u.username as created_by_name, pi.invoice_number
                FROM supplier_refunds sr
                LEFT JOIN users u ON sr.created_by = u.id
                LEFT JOIN purchase_invoices pi ON sr.invoice_id = pi.id
                WHERE sr.supplier_id = :supplier_id
                ORDER BY sr.refund_date DESC
                LIMIT 10";
$refund_stmt = $conn->prepare($refund_query);
$refund_stmt->bindParam(':supplier_id', $supplier_id);
$refund_stmt->execute();
$refunds = $refund_stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = "Supplier Details: " . $supplier['supplier_name'];

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
                    <h2><i class="fas fa-truck"></i> Supplier Details</h2>
                </div>
                <div class="col-md-6 text-end">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-end">
                            <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="suppliers.php">Suppliers</a></li>
                            <li class="breadcrumb-item active"><?php echo htmlspecialchars($supplier['supplier_name']); ?></li>
                        </ol>
                    </nav>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4">
                    <!-- Supplier Information Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Supplier Information</h3>
                            <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_edit'])): ?>
                            <div class="card-tools">
                                <a href="supplier_statement.php?supplier_id=<?php echo $supplier['id']; ?>" class="btn btn-success btn-sm me-2">
                                    <i class="fas fa-file-invoice-dollar"></i> Statement
                                </a>
                                <button type="button" class="btn btn-primary btn-sm edit-supplier" 
                                        data-id="<?php echo $supplier['id']; ?>"
                                        data-code="<?php echo htmlspecialchars($supplier['supplier_code']); ?>"
                                        data-name="<?php echo htmlspecialchars($supplier['supplier_name']); ?>"
                                        data-contact="<?php echo htmlspecialchars($supplier['contact_person']); ?>"
                                        data-phone="<?php echo htmlspecialchars($supplier['phone']); ?>"
                                        data-email="<?php echo htmlspecialchars($supplier['email']); ?>"
                                        data-website="<?php echo htmlspecialchars($supplier['website']); ?>"
                                        data-address="<?php echo htmlspecialchars($supplier['address']); ?>"
                                        data-city="<?php echo htmlspecialchars($supplier['city']); ?>"
                                        data-state="<?php echo htmlspecialchars($supplier['state']); ?>"
                                        data-postal="<?php echo htmlspecialchars($supplier['postal_code']); ?>"
                                        data-country="<?php echo htmlspecialchars($supplier['country']); ?>"
                                        data-tax="<?php echo htmlspecialchars($supplier['tax_number']); ?>"
                                        data-terms="<?php echo $supplier['payment_terms']; ?>"
                                        data-credit="<?php echo $supplier['credit_limit']; ?>"
                                        data-account="<?php echo $supplier['account_id']; ?>"
                                        data-notes="<?php echo htmlspecialchars($supplier['notes']); ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </div>
                            <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <i class="fas fa-building fa-5x text-primary"></i>
                            <h4 class="mt-2"><?php echo htmlspecialchars($supplier['supplier_name']); ?></h4>
                            <p class="text-muted"><?php echo htmlspecialchars($supplier['supplier_code']); ?></p>
                        </div>
                        
                        <strong><i class="fas fa-user mr-1"></i> Contact Person</strong>
                        <p class="text-muted"><?php echo htmlspecialchars($supplier['contact_person'] ?: 'Not specified'); ?></p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-phone mr-1"></i> Phone</strong>
                        <p class="text-muted"><?php echo htmlspecialchars($supplier['phone'] ?: 'Not specified'); ?></p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-envelope mr-1"></i> Email</strong>
                        <p class="text-muted">
                            <?php if ($supplier['email']): ?>
                                <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>"><?php echo htmlspecialchars($supplier['email']); ?></a>
                            <?php else: ?>
                                Not specified
                            <?php endif; ?>
                        </p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-globe mr-1"></i> Website</strong>
                        <p class="text-muted">
                            <?php if ($supplier['website']): ?>
                                <a href="<?php echo htmlspecialchars($supplier['website']); ?>" target="_blank"><?php echo htmlspecialchars($supplier['website']); ?></a>
                            <?php else: ?>
                                Not specified
                            <?php endif; ?>
                        </p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-map-marker-alt mr-1"></i> Address</strong>
                        <p class="text-muted">
                            <?php if ($supplier['address']): ?>
                                <?php echo nl2br(htmlspecialchars($supplier['address'])); ?><br>
                                <?php echo htmlspecialchars(implode(', ', array_filter([
                                    $supplier['city'],
                                    $supplier['state'],
                                    $supplier['postal_code'],
                                    $supplier['country']
                                ]))); ?>
                            <?php else: ?>
                                Not specified
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <!-- Financial Information Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Financial Information</h3>
                    </div>
                    <div class="card-body">
                        <strong><i class="fas fa-file-invoice-dollar mr-1"></i> Tax Number</strong>
                        <p class="text-muted"><?php echo htmlspecialchars($supplier['tax_number'] ?: 'Not specified'); ?></p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-calendar-alt mr-1"></i> Payment Terms</strong>
                        <p class="text-muted"><?php echo $supplier['payment_terms']; ?> days</p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-credit-card mr-1"></i> Credit Limit</strong>
                        <p class="text-muted"><?php echo formatNumber($conn, $supplier['credit_limit']); ?></p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-book mr-1"></i> Account</strong>
                        <p class="text-muted">
                            <?php if ($supplier['account_id']): ?>
                                <?php echo htmlspecialchars($supplier['account_code'] . ' - ' . $supplier['account_name']); ?>
                            <?php else: ?>
                                Not assigned
                            <?php endif; ?>
                        </p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-sticky-note mr-1"></i> Notes</strong>
                        <p class="text-muted"><?php echo nl2br(htmlspecialchars($supplier['notes'] ?: 'No notes')); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <!-- Purchase Orders Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Purchase Orders</h3>
                        <div class="card-tools">
                            <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_add'])): ?>
                            <a href="purchase_order_create.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> New Order
                            </a>
                            <?php endif; ?>
                            <a href="purchase_orders.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-info btn-sm">
                                <i class="fas fa-list"></i> View All
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($purchase_orders) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>PO Number</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Items</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($purchase_orders as $po): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($po['po_number']); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($po['po_date'])); ?></td>
                                        <td><?php echo formatNumber($conn, $po['total_amount']); ?></td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            switch ($po['status']) {
                                                case 'Draft': $status_class = 'badge bg-secondary'; break;
                                                case 'Pending': $status_class = 'badge bg-warning'; break;
                                                case 'Approved': $status_class = 'badge bg-success'; break;
                                                case 'Sent': $status_class = 'badge bg-info'; break;
                                                case 'Partially Received': $status_class = 'badge bg-primary'; break;
                                                case 'Fully Received': $status_class = 'badge bg-success'; break;
                                                case 'Cancelled': $status_class = 'badge bg-danger'; break;
                                                case 'Closed': $status_class = 'badge bg-dark'; break;
                                                default: $status_class = 'badge bg-secondary';
                                            }
                                            ?>
                                            <span class="<?php echo $status_class; ?>"><?php echo $po['status']; ?></span>
                                        </td>
                                        <td>
                                            <?php echo $po['received_items']; ?> / <?php echo $po['total_items']; ?>
                                        </td>
                                        <td>
                                            <a href="purchase_order_detail.php?id=<?php echo $po['id']; ?>" class="btn btn-info btn-sm">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No purchase orders found for this supplier.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Purchase Invoices Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Invoices</h3>
                        <div class="card-tools">
                            <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_add'])): ?>
                            <a href="purchase_invoice_create.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> New Invoice
                            </a>
                            <?php endif; ?>
                            <a href="purchase_invoices.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-info btn-sm">
                                <i class="fas fa-list"></i> View All
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($invoices) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Invoice Number</th>
                                        <th>Date</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($invoices as $invoice): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($invoice['invoice_date'])); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($invoice['due_date'])); ?></td>
                                        <td><?php echo formatNumber($conn, $invoice['total_amount']); ?></td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            switch ($invoice['payment_status']) {
                                                case 'Unpaid': $status_class = 'badge bg-danger'; break;
                                                case 'Partially Paid': $status_class = 'badge bg-warning'; break;
                                                case 'Paid': $status_class = 'badge bg-success'; break;
                                                default: $status_class = 'badge bg-secondary';
                                            }
                                            ?>
                                            <span class="<?php echo $status_class; ?>"><?php echo $invoice['payment_status']; ?></span>
                                        </td>
                                        <td>
                                            <a href="purchase_invoice_detail.php?id=<?php echo $invoice['id']; ?>" class="btn btn-info btn-sm">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No invoices found for this supplier.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Goods Receiving Notes Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Goods Receiving Notes</h3>
                    </div>
                    <div class="card-body">
                        <?php if (count($grns) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>GRN Number</th>
                                        <th>PO Number</th>
                                        <th>Received Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grns as $grn): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($grn['grn_number']); ?></td>
                                        <td><?php echo htmlspecialchars($grn['order_number']); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($grn['received_date'])); ?></td>
                                        <td><?php echo formatNumber($conn, $grn['total_amount']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $grn['status'] == 'completed' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($grn['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($grn['created_by_name']); ?></td>
                                        <td>
                                            <a href="grn_detail.php?id=<?php echo $grn['id']; ?>" class="btn btn-info btn-sm">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No GRNs found for this supplier.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Purchase Returns Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Purchase Returns</h3>
                    </div>
                    <div class="card-body">
                        <?php if (count($returns) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Return Number</th>
                                        <th>Invoice Number</th>
                                        <th>Return Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Type</th>
                                        <th>Created By</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($returns as $return): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($return['return_number']); ?></td>
                                        <td><?php echo htmlspecialchars($return['invoice_number']); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($return['return_date'])); ?></td>
                                        <td><?php echo formatNumber($conn, $return['total_amount']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $return['status'] == 'approved' ? 'success' : ($return['status'] == 'draft' ? 'warning' : 'danger'); ?>">
                                                <?php echo ucfirst($return['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?php echo ucfirst($return['return_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($return['created_by_name']); ?></td>
                                        <td>
                                            <a href="purchase_return_detail.php?id=<?php echo $return['id']; ?>" class="btn btn-info btn-sm">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No returns found for this supplier.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Supplier Refunds Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Supplier Refunds</h3>
                    </div>
                    <div class="card-body">
                        <?php if (count($refunds) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Reference Number</th>
                                        <th>Invoice Number</th>
                                        <th>Refund Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($refunds as $refund): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($refund['reference_number']); ?></td>
                                        <td><?php echo htmlspecialchars($refund['invoice_number']); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($refund['refund_date'])); ?></td>
                                        <td><?php echo formatNumber($conn, $refund['amount']); ?></td>
                                        <td><?php echo ucfirst($refund['payment_method']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $refund['status'] == 'completed' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($refund['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($refund['created_by_name']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No refunds or adjustments found for this supplier.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal fade" id="supplierModal" tabindex="-1" role="dialog" aria-labelledby="supplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="supplierModalLabel">Edit Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="supplierForm" action="actions/save_supplier.php" method="post">
                <div class="modal-body">
                    <input type="hidden" name="supplier_id" id="supplier_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="supplier_code">Supplier Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="supplier_code" name="supplier_code" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="supplier_name">Supplier Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="supplier_name" name="supplier_name" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="contact_person">Contact Person</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone">Phone</label>
                                <input type="text" class="form-control" id="phone" name="phone">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="website">Website</label>
                                <input type="text" class="form-control" id="website" name="website">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="city">City</label>
                                <input type="text" class="form-control" id="city" name="city">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="state">State/Province</label>
                                <input type="text" class="form-control" id="state" name="state">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="postal_code">Postal Code</label>
                                <input type="text" class="form-control" id="postal_code" name="postal_code">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="country">Country</label>
                                <input type="text" class="form-control" id="country" name="country">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="tax_number">Tax Number</label>
                                <input type="text" class="form-control" id="tax_number" name="tax_number">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="payment_terms">Payment Terms (Days)</label>
                                <input type="number" class="form-control" id="payment_terms" name="payment_terms" value="30">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="credit_limit">Credit Limit</label>
                                <input type="number" step="0.01" class="form-control" id="credit_limit" name="credit_limit" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="account_id">Supplier Account</label>
                                <select class="form-select" id="account_id" name="account_id">
                                    <option value="">-- Select Account --</option>
                                    <?php
                                    // Get chart of accounts for supplier account dropdown
                                    $accounts_query = "SELECT coa.id, coa.account_code, coa.account_name 
                                                       FROM chart_of_accounts coa 
                                                       JOIN account_categories ac ON coa.category_id = ac.id 
                                                       WHERE ac.type = 'liability' AND coa.status = 1 
                                                       ORDER BY coa.account_code";
                                    $accounts_stmt = $conn->prepare($accounts_query);
                                    $accounts_stmt->execute();
                                    $accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    foreach ($accounts as $account):
                                    ?>
                                    <option value="<?php echo $account['id']; ?>">
                                        <?php echo $account['account_code'] . ' - ' . $account['account_name']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Edit supplier
    $('.edit-supplier').click(function() {
        var id = $(this).data('id');
        $('#supplier_id').val(id);
        $('#supplier_code').val($(this).data('code'));
        $('#supplier_name').val($(this).data('name'));
        $('#contact_person').val($(this).data('contact'));
        $('#phone').val($(this).data('phone'));
        $('#email').val($(this).data('email'));
        $('#website').val($(this).data('website'));
        $('#address').val($(this).data('address'));
        $('#city').val($(this).data('city'));
        $('#state').val($(this).data('state'));
        $('#postal_code').val($(this).data('postal'));
        $('#country').val($(this).data('country'));
        $('#tax_number').val($(this).data('tax'));
        $('#payment_terms').val($(this).data('terms'));
        $('#credit_limit').val($(this).data('credit'));
        $('#account_id').val($(this).data('account'));
        $('#notes').val($(this).data('notes'));
        
        $('#supplierModal').modal('show');
    });
});
</script>
