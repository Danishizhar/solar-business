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
$page_title = "Suppliers";

// Include header
include '../../includes/header.php';

// Get all suppliers with comprehensive statistics
$query = "SELECT s.*, 
          COALESCE(po_count, 0) as po_count, 
          COALESCE(invoice_count, 0) as invoice_count,
          COALESCE(grn_count, 0) as grn_count,
          COALESCE(return_count, 0) as return_count
          FROM suppliers s
          LEFT JOIN (
              SELECT supplier_id, COUNT(*) as po_count 
              FROM purchase_orders 
              GROUP BY supplier_id
          ) po ON s.id = po.supplier_id
          LEFT JOIN (
              SELECT supplier_id, COUNT(*) as invoice_count 
              FROM purchase_invoices 
              GROUP BY supplier_id
          ) pi ON s.id = pi.supplier_id
          LEFT JOIN (
              SELECT po.supplier_id, COUNT(grn.id) as grn_count
              FROM goods_receiving_notes grn
              INNER JOIN purchase_orders po ON grn.purchase_order_id = po.id
              GROUP BY po.supplier_id
          ) grn ON s.id = grn.supplier_id
          LEFT JOIN (
              SELECT supplier_id, COUNT(*) as return_count 
              FROM purchase_returns 
              GROUP BY supplier_id
          ) pr ON s.id = pr.supplier_id
          WHERE s.status = 1
          ORDER BY s.supplier_name";

$stmt = $conn->prepare($query);
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get chart of accounts for supplier account dropdown
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
                    <h2><i class="fas fa-truck"></i> Supplier Management</h2>
                </div>
                <div class="col-md-6 text-end">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-end">
                            <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                            <li class="breadcrumb-item active">Suppliers</li>
                        </ol>
                    </nav>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Suppliers List</h5>
                    <div>
                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_add'])): ?>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#supplierModal">
                            <i class="fas fa-plus"></i> Add New Supplier
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="suppliersTable" class="table table-bordered table-striped">
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
                                        <th>GRNs</th>
                                        <th>Returns</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($suppliers as $supplier): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($supplier['supplier_code']); ?></td>
                                        <td><?php echo htmlspecialchars($supplier['supplier_name']); ?></td>
                                        <td><?php echo htmlspecialchars($supplier['contact_person']); ?></td>
                                        <td><?php echo htmlspecialchars($supplier['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($supplier['email']); ?></td>
                                        <td><?php echo htmlspecialchars($supplier['city']); ?></td>
                                        <td><span class="badge bg-primary"><?php echo $supplier['po_count']; ?></span></td>
                                        <td><span class="badge bg-success"><?php echo $supplier['invoice_count']; ?></span></td>
                                        <td><span class="badge bg-info"><?php echo $supplier['grn_count']; ?></span></td>
                                        <td><span class="badge bg-warning"><?php echo $supplier['return_count']; ?></span></td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="supplier_detail.php?id=<?php echo $supplier['id']; ?>" class="btn btn-info btn-sm" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="supplier_statement.php?supplier_id=<?php echo $supplier['id']; ?>" class="btn btn-success btn-sm" title="View Statement">
                                                    <i class="fas fa-file-invoice-dollar"></i>
                                                </a>
                                                <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_edit'])): ?>
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
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_delete'])): ?>
                                                <button type="button" class="btn btn-danger btn-sm delete-supplier" 
                                                        data-id="<?php echo $supplier['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($supplier['supplier_name']); ?>"
                                                        data-po-count="<?php echo $supplier['po_count']; ?>"
                                                        data-invoice-count="<?php echo $supplier['invoice_count']; ?>">
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

<!-- Supplier Modal -->
<div class="modal fade" id="supplierModal" tabindex="-1" role="dialog" aria-labelledby="supplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="supplierModalLabel">Add New Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="supplierForm" action="actions/save_supplier.php" method="post" enctype="multipart/form-data">
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
                    <button type="submit" class="btn btn-primary" id="saveBtn">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Supplier Modal -->
<div class="modal fade" id="deleteSupplierModal" tabindex="-1" role="dialog" aria-labelledby="deleteSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteSupplierModalLabel">Delete Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the supplier: <span id="delete-supplier-name"></span>?</p>
                <div id="supplier-has-transactions" class="alert alert-warning d-none">
                    <i class="fas fa-exclamation-triangle"></i> This supplier has associated transactions. Deleting it may affect data integrity.
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
        var table = $('#suppliersTable').DataTable({
            "responsive": true,
            "lengthChange": true,
            "autoWidth": false,
            "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
        });
        
        // Only try to use buttons if the extension is available
        if (typeof table.buttons === 'function') {
            table.buttons().container().appendTo('#suppliersTable_wrapper .col-md-6:eq(0)');
        }
    } catch (e) {
        // Fallback to basic DataTable if buttons extension fails
        console.log("DataTables Buttons extension not available: " + e.message);
        $('#suppliersTable').DataTable({
            "responsive": true,
            "lengthChange": true,
            "autoWidth": false
        });
    }
    
    // Auto-generate supplier code
    $('#supplierModal').on('show.bs.modal', function (e) {
        if (!$('#supplier_id').val()) {
            $.ajax({
                url: 'actions/get_next_supplier_code.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    $('#supplier_code').val(data.next_code);
                }
            });
        }
    });
    
    // Reset form when modal is closed
    $('#supplierModal').on('hidden.bs.modal', function () {
        $('#supplierForm')[0].reset();
        $('#supplier_id').val('');
        $('#supplierModalLabel').text('Add New Supplier');
    });
    
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
        
        $('#supplierModalLabel').text('Edit Supplier');
        $('#supplierModal').modal('show');
    });
    
    // Form validation and submission
    $('#supplierForm').on('submit', function(e) {
        // For debugging
        console.log('Form submitted');
        console.log('Form data:', $(this).serialize());
        
        // Basic validation
        if($('#supplier_code').val() === '' || $('#supplier_name').val() === '') {
            e.preventDefault();
            alert('Supplier code and name are required fields.');
            return false;
        }
        
        // Show loading message
        $('#saveBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        
        // Allow form submission
        return true;
    });
    
    // Delete supplier
    $('.delete-supplier').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var poCount = $(this).data('po-count');
        var invoiceCount = $(this).data('invoice-count');
        
        $('#delete-supplier-name').text(name);
        $('#confirm-delete').attr('href', 'actions/delete_supplier.php?id=' + id);
        
        if (poCount > 0 || invoiceCount > 0) {
            $('#supplier-has-transactions').removeClass('d-none');
        } else {
            $('#supplier-has-transactions').addClass('d-none');
        }
        
        $('#deleteSupplierModal').modal('show');
    });
});
</script>
