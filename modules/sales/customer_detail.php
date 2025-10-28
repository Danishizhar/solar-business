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

// Check if customer ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: customers.php");
    exit();
}

$customer_id = $_GET['id'];

// Get customer details
$query = "SELECT s.*, coa.account_code, coa.account_name 
          FROM customers s
          LEFT JOIN chart_of_accounts coa ON s.account_id = coa.id
          WHERE s.id = :id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':id', $customer_id);
$stmt->execute();

if ($stmt->rowCount() == 0) {
    header("Location: customers.php");
    exit();
}

$customer = $stmt->fetch(PDO::FETCH_ASSOC);

// Get sales orders for this customer
$so_query = "SELECT so.*, u.username as created_by_name, 
            COALESCE(received.received_count, 0) as received_items,
            COALESCE(ordered.ordered_count, 0) as total_items
            FROM sales_orders so
            LEFT JOIN users u ON so.created_by = u.id
            LEFT JOIN (
                SELECT sales_order_id, COUNT(*) as ordered_count 
                FROM sales_order_items 
                GROUP BY sales_order_id
            ) ordered ON so.id = ordered.sales_order_id
            LEFT JOIN (
                SELECT sales_order_id, COUNT(*) as received_count 
                FROM sales_order_items 
                WHERE received_quantity > 0
                GROUP BY sales_order_id
            ) received ON so.id = received.sales_order_id
            WHERE so.customer_id = :customer_id
            ORDER BY so.order_date DESC
            LIMIT 10";
$so_stmt = $conn->prepare($so_query);
$so_stmt->bindParam(':customer_id', $customer_id);
$so_stmt->execute();
$sales_orders = $so_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get sales invoices for this customer
$invoice_query = "SELECT pi.*, u.username as created_by_name
                FROM sales_invoices pi
                LEFT JOIN users u ON pi.created_by = u.id
                WHERE pi.customer_id = :customer_id
                ORDER BY pi.invoice_date DESC
                LIMIT 10";
$invoice_stmt = $conn->prepare($invoice_query);
$invoice_stmt->bindParam(':customer_id', $customer_id);
$invoice_stmt->execute();
$invoices = $invoice_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get delivery notes for this customer
$dn_query = "SELECT dn.*, u.username as created_by_name, so.order_number
                FROM delivery_notes dn
                LEFT JOIN users u ON dn.created_by = u.id
                LEFT JOIN sales_orders so ON dn.sales_order_id = so.id
                WHERE so.customer_id = :customer_id
                ORDER BY dn.received_date DESC
                LIMIT 10";
$dn_stmt = $conn->prepare($dn_query);
$dn_stmt->bindParam(':customer_id', $customer_id);
$dn_stmt->execute();
$dns = $dn_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get sales returns for this customer
$return_query = "SELECT pr.*, u.username as created_by_name, pi.invoice_number
                FROM sales_returns pr
                LEFT JOIN users u ON pr.created_by = u.id
                LEFT JOIN sales_invoices pi ON pr.invoice_id = pi.id
                WHERE pr.customer_id = :customer_id
                ORDER BY pr.return_date DESC
                LIMIT 10";
$return_stmt = $conn->prepare($return_query);
$return_stmt->bindParam(':customer_id', $customer_id);
$return_stmt->execute();
$returns = $return_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get customer refunds for this customer
$refund_query = "SELECT sr.*, u.username as created_by_name, pi.invoice_number
                FROM customer_refunds sr
                LEFT JOIN users u ON sr.created_by = u.id
                LEFT JOIN sales_invoices pi ON sr.invoice_id = pi.id
                WHERE sr.customer_id = :customer_id
                ORDER BY sr.refund_date DESC
                LIMIT 10";
$refund_stmt = $conn->prepare($refund_query);
$refund_stmt->bindParam(':customer_id', $customer_id);
$refund_stmt->execute();
$refunds = $refund_stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = "Customer Details: " . $customer['customer_name'];

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
                    <h2><i class="fas fa-truck"></i> Customer Details</h2>
                </div>
                <div class="col-md-6 text-end">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-end">
                            <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="customers.php">Customers</a></li>
                            <li class="breadcrumb-item active"><?php echo htmlspecialchars($customer['customer_name']); ?></li>
                        </ol>
                    </nav>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4">
                    <!-- Customer Information Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Customer Information</h3>
                            <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_edit'])): ?>
                            <div class="card-tools">
                                <a href="customer_statement.php?customer_id=<?php echo $customer['id']; ?>" class="btn btn-success btn-sm me-2">
                                    <i class="fas fa-file-invoice-dollar"></i> Statement
                                </a>
                                <button type="button" class="btn btn-primary btn-sm edit-customer" 
                                        data-id="<?php echo $customer['id']; ?>"
                                        data-code="<?php echo htmlspecialchars($customer['customer_code']); ?>"
                                        data-name="<?php echo htmlspecialchars($customer['customer_name']); ?>"
                                        data-contact="<?php echo htmlspecialchars($customer['contact_person']); ?>"
                                        data-phone="<?php echo htmlspecialchars($customer['phone']); ?>"
                                        data-email="<?php echo htmlspecialchars($customer['email']); ?>"
                                        data-website="<?php echo htmlspecialchars($customer['website']); ?>"
                                        data-address="<?php echo htmlspecialchars($customer['address']); ?>"
                                        data-city="<?php echo htmlspecialchars($customer['city']); ?>"
                                        data-state="<?php echo htmlspecialchars($customer['state']); ?>"
                                        data-postal="<?php echo htmlspecialchars($customer['postal_code']); ?>"
                                        data-country="<?php echo htmlspecialchars($customer['country']); ?>"
                                        data-tax="<?php echo htmlspecialchars($customer['tax_number']); ?>"
                                        data-terms="<?php echo $customer['payment_terms']; ?>"
                                        data-credit="<?php echo $customer['credit_limit']; ?>"
                                        data-account="<?php echo $customer['account_id']; ?>"
                                        data-notes="<?php echo htmlspecialchars($customer['notes']); ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </div>
                            <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <i class="fas fa-building fa-5x text-primary"></i>
                            <h4 class="mt-2"><?php echo htmlspecialchars($customer['customer_name']); ?></h4>
                            <p class="text-muted"><?php echo htmlspecialchars($customer['customer_code']); ?></p>
                        </div>
                        
                        <strong><i class="fas fa-user mr-1"></i> Contact Person</strong>
                        <p class="text-muted"><?php echo htmlspecialchars($customer['contact_person'] ?: 'Not specified'); ?></p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-phone mr-1"></i> Phone</strong>
                        <p class="text-muted"><?php echo htmlspecialchars($customer['phone'] ?: 'Not specified'); ?></p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-envelope mr-1"></i> Email</strong>
                        <p class="text-muted">
                            <?php if ($customer['email']): ?>
                                <a href="mailto:<?php echo htmlspecialchars($customer['email']); ?>"><?php echo htmlspecialchars($customer['email']); ?></a>
                            <?php else: ?>
                                Not specified
                            <?php endif; ?>
                        </p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-globe mr-1"></i> Website</strong>
                        <p class="text-muted">
                            <?php if ($customer['website']): ?>
                                <a href="<?php echo htmlspecialchars($customer['website']); ?>" target="_blank"><?php echo htmlspecialchars($customer['website']); ?></a>
                            <?php else: ?>
                                Not specified
                            <?php endif; ?>
                        </p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-map-marker-alt mr-1"></i> Address</strong>
                        <p class="text-muted">
                            <?php if ($customer['address']): ?>
                                <?php echo nl2br(htmlspecialchars($customer['address'])); ?><br>
                                <?php echo htmlspecialchars(implode(', ', array_filter([
                                    $customer['city'],
                                    $customer['state'],
                                    $customer['postal_code'],
                                    $customer['country']
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
                        <p class="text-muted"><?php echo htmlspecialchars($customer['tax_number'] ?: 'Not specified'); ?></p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-calendar-alt mr-1"></i> Payment Terms</strong>
                        <p class="text-muted"><?php echo $customer['payment_terms']; ?> days</p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-credit-card mr-1"></i> Credit Limit</strong>
                        <p class="text-muted"><?php echo formatNumber($conn, $customer['credit_limit']); ?></p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-book mr-1"></i> Account</strong>
                        <p class="text-muted">
                            <?php if ($customer['account_id']): ?>
                                <?php echo htmlspecialchars($customer['account_code'] . ' - ' . $customer['account_name']); ?>
                            <?php else: ?>
                                Not assigned
                            <?php endif; ?>
                        </p>
                        
                        <hr>
                        
                        <strong><i class="fas fa-sticky-note mr-1"></i> Notes</strong>
                        <p class="text-muted"><?php echo nl2br(htmlspecialchars($customer['notes'] ?: 'No notes')); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <!-- Sales Orders Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Sales Orders</h3>
                        <div class="card-tools">
                            <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_add'])): ?>
                            <a href="sales_order_create.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> New Order
                            </a>
                            <?php endif; ?>
                            <a href="sales_orders.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-info btn-sm">
                                <i class="fas fa-list"></i> View All
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($sales_orders) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>SO Number</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Items</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sales_orders as $so): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($so['so_number']); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($so['so_date'])); ?></td>
                                        <td><?php echo formatNumber($conn, $so['total_amount']); ?></td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            switch ($so['status']) {
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
                                            <span class="<?php echo $status_class; ?>"><?php echo $so['status']; ?></span>
                                        </td>
                                        <td>
                                            <?php echo $so['received_items']; ?> / <?php echo $so['total_items']; ?>
                                        </td>
                                        <td>
                                            <a href="sales_order_detail.php?id=<?php echo $so['id']; ?>" class="btn btn-info btn-sm">
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
                            <i class="fas fa-info-circle"></i> No sales orders found for this customer.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Sales Invoices Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Invoices</h3>
                        <div class="card-tools">
                            <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_add'])): ?>
                            <a href="sales_invoice_create.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> New Invoice
                            </a>
                            <?php endif; ?>
                            <a href="sales_invoices.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-info btn-sm">
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
                                            <a href="sales_invoice_detail.php?id=<?php echo $invoice['id']; ?>" class="btn btn-info btn-sm">
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
                            <i class="fas fa-info-circle"></i> No invoices found for this customer.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Delivery Notes Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Delivery Notes</h3>
                    </div>
                    <div class="card-body">
                        <?php if (count($dns) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>DN Number</th>
                                        <th>SO Number</th>
                                        <th>Received Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dns as $dn): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($dn['delivery_note_number']); ?></td>
                                        <td><?php echo htmlspecialchars($dn['order_number']); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($dn['received_date'])); ?></td>
                                        <td><?php echo formatNumber($conn, $dn['total_amount']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $dn['status'] == 'completed' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($dn['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($dn['created_by_name']); ?></td>
                                        <td>
                                            <a href="delivery_note_detail.php?id=<?php echo $dn['id']; ?>" class="btn btn-info btn-sm">
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
                            <i class="fas fa-info-circle"></i> No DNs found for this customer.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Sales Returns Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Sales Returns</h3>
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
                                            <a href="sales_return_detail.php?id=<?php echo $return['id']; ?>" class="btn btn-info btn-sm">
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
                            <i class="fas fa-info-circle"></i> No returns found for this customer.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Customer Refunds Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Customer Refunds</h3>
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
                            <i class="fas fa-info-circle"></i> No refunds or adjustments found for this customer.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="customerModal" tabindex="-1" role="dialog" aria-labelledby="customerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customerModalLabel">Edit Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="customerForm" action="actions/save_customer.php" method="post">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="customer_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="customer_code">Customer Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="customer_code" name="customer_code" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="customer_name">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="customer_name" name="customer_name" required>
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
                                <label for="account_id">Customer Account</label>
                                <select class="form-select" id="account_id" name="account_id">
                                    <option value="">-- Select Account --</option>
                                    <?php
                                    // Get chart of accounts for customer account dropdown
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
    // Edit customer
    $('.edit-customer').click(function() {
        var id = $(this).data('id');
        $('#customer_id').val(id);
        $('#customer_code').val($(this).data('code'));
        $('#customer_name').val($(this).data('name'));
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
        
        $('#customerModal').modal('show');
    });
});
</script>
