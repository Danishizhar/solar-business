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
$page_title = "Customers";

// Include header
include '../../includes/header.php';

// Get all customers with comprehensive statistics
$query = "SELECT s.*, 
          COALESCE(so_count, 0) as so_count, 
          COALESCE(invoice_count, 0) as invoice_count,
          COALESCE(dn_count, 0) as dn_count,
          COALESCE(return_count, 0) as return_count
          FROM customers s
          LEFT JOIN (
              SELECT customer_id, COUNT(*) as so_count 
              FROM sales_orders 
              GROUP BY customer_id
          ) po ON s.id = so.customer_id
          LEFT JOIN (
              SELECT customer_id, COUNT(*) as invoice_count 
              FROM sales_invoices 
              GROUP BY customer_id
          ) pi ON s.id = pi.customer_id
          LEFT JOIN (
              SELECT so.customer_id, COUNT(dn.id) as dn_count
              FROM delivery_notes dn
              INNER JOIN sales_orders so ON dn.sales_order_id = so.id
              GROUP BY so.customer_id
          ) dn ON s.id = dn.customer_id
          LEFT JOIN (
              SELECT customer_id, COUNT(*) as return_count 
              FROM sales_returns 
              GROUP BY customer_id
          ) pr ON s.id = pr.customer_id
          WHERE s.status = 1
          ORDER BY s.customer_name";

$stmt = $conn->prepare($query);
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get chart of accounts for customer account dropdown
$accounts_query = "SELECT coa.id, coa.account_code, coa.account_name 
                  FROM chart_of_accounts coa 
                  JOIN account_categories ac ON coa.category_id = ac.id 
                  WHERE ac.type = 'liability' AND coa.status = 1 
                  ORDER BY coa.account_code";
$accounts_stmt = $conn->prepare($accounts_query);
$accounts_stmt->execute();
$accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-truck"></i> Customer Management</h2>
                </div>
                <div class="col-md-6 text-end">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-end">
                            <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                            <li class="breadcrumb-item active">Customers</li>
                        </ol>
                    </nav>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Customers List</h5>
                    <div>
                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_add'])): ?>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#customerModal">
                            <i class="fas fa-plus"></i> Add New Customer
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="customersTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Name</th>
                                        <th>Contact Person</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>City</th>
                                        <th>Orders</th>
                                        <th>Invoices</th>
                                        <th>DNs</th>
                                        <th>Returns</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($customer['customer_code']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['contact_person']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['city']); ?></td>
                                        <td><span class="badge bg-primary"><?php echo $customer['so_count']; ?></span></td>
                                        <td><span class="badge bg-success"><?php echo $customer['invoice_count']; ?></span></td>
                                        <td><span class="badge bg-info"><?php echo $customer['dn_count']; ?></span></td>
                                        <td><span class="badge bg-warning"><?php echo $customer['return_count']; ?></span></td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="customer_detail.php?id=<?php echo $customer['id']; ?>" class="btn btn-info btn-sm" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="customer_statement.php?customer_id=<?php echo $customer['id']; ?>" class="btn btn-success btn-sm" title="View Statement">
                                                    <i class="fas fa-file-invoice-dollar"></i>
                                                </a>
                                                <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_edit'])): ?>
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
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['sales_delete'])): ?>
                                                <button type="button" class="btn btn-danger btn-sm delete-customer" 
                                                        data-id="<?php echo $customer['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($customer['customer_name']); ?>"
                                                        data-so-count="<?php echo $customer['so_count']; ?>"
                                                        data-invoice-count="<?php echo $customer['invoice_count']; ?>">
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
</div>

<!-- Customer Modal -->
<div class="modal fade" id="customerModal" tabindex="-1" role="dialog" aria-labelledby="customerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customerModalLabel">Add New Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="customerForm" action="actions/save_customer.php" method="post" enctype="multipart/form-data">
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
                                    <?php foreach ($accounts as $account): ?>
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
                    <button type="submit" class="btn btn-primary" id="saveBtn">Save Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Customer Modal -->
<div class="modal fade" id="deleteCustomerModal" tabindex="-1" role="dialog" aria-labelledby="deleteCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteCustomerModalLabel">Delete Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the customer: <span id="delete-customer-name"></span>?</p>
                <div id="customer-has-transactions" class="alert alert-warning d-none">
                    <i class="fas fa-exclamation-triangle"></i> This customer has associated transactions. Deleting it may affect data integrity.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirm-delete" class="btn btn-danger">Delete</a>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<!-- DataTables Buttons Extension -->
<script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.colVis.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable with try/catch to handle missing extensions
    try {
        var table = $('#customersTable').DataTable({
            "responsive": true,
            "lengthChange": true,
            "autoWidth": false,
            "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
        });
        
        // Only try to use buttons if the extension is available
        if (typeof table.buttons === 'function') {
            table.buttons().container().appendTo('#customersTable_wrapper .col-md-6:eq(0)');
        }
    } catch (e) {
        // Fallback to basic DataTable if buttons extension fails
        console.log("DataTables Buttons extension not available: " + e.message);
        $('#customersTable').DataTable({
            "responsive": true,
            "lengthChange": true,
            "autoWidth": false
        });
    }
    
    // Auto-generate customer code
    $('#customerModal').on('show.bs.modal', function (e) {
        if (!$('#customer_id').val()) {
            $.ajax({
                url: 'actions/get_next_customer_code.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    $('#customer_code').val(data.next_code);
                }
            });
        }
    });
    
    // Reset form when modal is closed
    $('#customerModal').on('hidden.bs.modal', function () {
        $('#customerForm')[0].reset();
        $('#customer_id').val('');
        $('#customerModalLabel').text('Add New Customer');
    });
    
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
        
        $('#customerModalLabel').text('Edit Customer');
        $('#customerModal').modal('show');
    });
    
    // Form validation and submission
    $('#customerForm').on('submit', function(e) {
        // For debugging
        console.log('Form submitted');
        console.log('Form data:', $(this).serialize());
        
        // Basic validation
        if($('#customer_code').val() === '' || $('#customer_name').val() === '') {
            e.preventDefault();
            alert('Customer code and name are required fields.');
            return false;
        }
        
        // Show loading message
        $('#saveBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        
        // Allow form submission
        return true;
    });
    
    // Delete customer
    $('.delete-customer').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var poCount = $(this).data('po-count');
        var invoiceCount = $(this).data('invoice-count');
        
        $('#delete-customer-name').text(name);
        $('#confirm-delete').attr('href', 'actions/delete_customer.php?id=' + id);
        
        if (poCount > 0 || invoiceCount > 0) {
            $('#customer-has-transactions').removeClass('d-none');
        } else {
            $('#customer-has-transactions').addClass('d-none');
        }
        
        $('#deleteCustomerModal').modal('show');
    });
});
</script>
