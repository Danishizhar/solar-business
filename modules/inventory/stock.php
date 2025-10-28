<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Include helper functions
require_once '../../includes/company_helpers.php';
require_once 'includes/inventory_helpers.php';
require_once 'includes/stock_settings_helper.php';

$success_message = '';
$error_message = '';

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Get all locations for filter
try {
    $query = "SELECT id, location_name FROM inventory_locations WHERE status = 1 ORDER BY is_default DESC, location_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching locations: " . $e->getMessage();
    error_log("Error fetching inventory locations: " . $e->getMessage());
}

// Get all categories for filter
try {
    $query = "SELECT id, category_name FROM product_categories WHERE status = 1 ORDER BY category_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching categories: " . $e->getMessage();
    error_log("Error fetching product categories: " . $e->getMessage());
}

// Get stock data with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$location_filter = isset($_GET['location']) ? (int)$_GET['location'] : null;
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    // Build query with filters
    $where_conditions = ["s.status = 1"];
    $params = [];
    
    if ($location_filter) {
        $where_conditions[] = "s.location_id = :location_id";
        $params[':location_id'] = $location_filter;
    }
    
    if ($category_filter) {
        $where_conditions[] = "p.category_id = :category_id";
        $params[':category_id'] = $category_filter;
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(p.product_name LIKE :search OR p.product_code LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Count total records for pagination
    $count_query = "SELECT COUNT(DISTINCT s.product_id, s.location_id) as total FROM inventory_stock s 
                   JOIN products p ON s.product_id = p.id 
                   $where_clause";
    
    $count_stmt = $db->prepare($count_query);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $limit);
    
    // Get stock data
    $query = "SELECT 
             s.product_id, s.location_id, MAX(s.updated_at) as updated_at,
             SUM(s.quantity) as quantity, SUM(s.available_quantity) as available_quantity, 
             SUM(s.reserved_quantity) as reserved_quantity,
             p.product_name, p.product_code as sku, p.has_variants, p.is_variant,
             l.location_name
             FROM inventory_stock s
             JOIN products p ON s.product_id = p.id
             LEFT JOIN inventory_locations l ON s.location_id = l.id
             $where_clause
             GROUP BY s.product_id, s.location_id, p.product_name, p.product_code, p.has_variants, p.is_variant, l.location_name
             ORDER BY p.product_name, l.location_name
             LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching stock data: " . $e->getMessage();
    error_log("Error fetching inventory stock: " . $e->getMessage());
}

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
                    <h2>Inventory Stock</h2>
                </div>
                <div class="col-md-6 text-end">
                    <div class="btn-group">
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addStockModal">
                            <i class="fas fa-plus"></i> Add Stock
                        </button>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quickAdjustModal">
                            <i class="fas fa-edit"></i> Adjust Stock
                        </button>
                        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#transferStockModal">
                            <i class="fas fa-exchange-alt"></i> Transfer Stock
                        </button>
                    </div>
                </div>
            </div>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <!-- Filter Form -->
            <div class="card mb-4">
                <div class="card-body">
                    <form action="" method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="location" class="form-label">Location</label>
                            <select class="form-select" id="location" name="location">
                                <option value="">All Locations</option>
                                <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>" <?php echo ($location_filter == $location['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($location['location_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo ($category_filter == $category['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Product name, SKU, barcode" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">Filter</button>
                            <a href="stock.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stock Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Location</th>
                                    <th>On Hand</th>
                                    <th>Available</th>
                                    <th>Reserved</th>
                                    <th>Last Updated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stock_items)): ?>
                                <tr>
                                    <td colspan="9" class="text-center">No stock items found</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($stock_items as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['sku']); ?></td>
                                    <td><?php echo htmlspecialchars($item['location_name'] ?? 'Unassigned'); ?></td>
                                    <td><?php echo formatNumber($db, $item['quantity']); ?></td>
                                    <td><?php echo formatNumber($db, $item['available_quantity']); ?></td>
                                    <td><?php echo formatNumber($db, $item['reserved_quantity']); ?></td>
                                    <td><?php echo !empty($item['updated_at']) ? date('Y-m-d H:i', strtotime($item['updated_at'])) : '-'; ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-info view-history" 
                                                    data-product-id="<?php echo $item['product_id']; ?>"
                                                    data-location-id="<?php echo $item['location_id']; ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#stockHistoryModal">
                                                <i class="fas fa-history"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center mt-4">
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&location=<?php echo $location_filter; ?>&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search); ?>">Previous</a>
                            </li>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&location=<?php echo $location_filter; ?>&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&location=<?php echo $location_filter; ?>&category=<?php echo $category_filter; ?>&search=<?php echo urlencode($search); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Stock Modal -->
<div class="modal fade" id="addStockModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/add_stock.php" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="add_product_id" class="form-label">Product <span class="text-danger">*</span></label>
                        <select class="form-select" id="add_product_id" name="product_id" required>
                            <option value="">Select Product</option>
                            <!-- Will be populated via AJAX -->
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="add_location_id" class="form-label">Location <span class="text-danger">*</span></label>
                        <select class="form-select" id="add_location_id" name="location_id" required>
                            <option value="">Select Location</option>
                            <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>"><?php echo htmlspecialchars($location['location_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="add_quantity" class="form-label">Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="add_quantity" name="quantity" step="0.01" min="0" required>
                    </div>
                    <!-- Reorder Level field removed as it's not in the database schema -->
                    <div class="mb-3">
                        <label for="add_notes" class="form-label">Notes <?php echo areNotesRequiredForTransactions($db) ? '<span class="text-danger">*</span>' : ''; ?></label>
                        <textarea class="form-control" id="add_notes" name="notes" rows="3" <?php echo areNotesRequiredForTransactions($db) ? 'required' : ''; ?>></textarea>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Adding stock will increase the available quantity at the selected location.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Adjust Modal -->
<div class="modal fade" id="quickAdjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Adjust Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/quick_adjust_stock.php" method="POST">
                <input type="hidden" id="quick_stock_id" name="stock_id">
                <input type="hidden" id="quick_product_id" name="product_id">
                <input type="hidden" id="quick_location_id" name="location_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="quick_product_select" class="form-label">Product <span class="text-danger">*</span></label>
                        <select class="form-select" id="quick_product_select" name="product_id" required>
                            <option value="">Select Product</option>
                            <!-- Will be populated via AJAX -->
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="quick_location_select" class="form-label">Location <span class="text-danger">*</span></label>
                        <select class="form-select" id="quick_location_select" name="location_id" required>
                            <option value="">Select Location</option>
                            <?php foreach ($locations as $location): ?>
                            <option value="<?php echo $location['id']; ?>"><?php echo htmlspecialchars($location['location_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Current Quantity</label>
                        <p id="quick_current_quantity" class="form-control-static">Will be loaded when product and location are selected</p>
                    </div>
                    <div class="mb-3">
                        <label for="quick_new_quantity" class="form-label">New Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="quick_new_quantity" name="new_quantity" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label for="quick_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                        <select class="form-select" id="quick_reason" name="reason" required>
                            <option value="">Select Reason</option>
                            <?php 
                            $default_reasons = getDefaultAdjustmentReasons($db);
                            foreach ($default_reasons as $reason): 
                            ?>
                            <option value="<?php echo htmlspecialchars($reason); ?>"><?php echo htmlspecialchars($reason); ?></option>
                            <?php endforeach; ?>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="quick_notes" class="form-label">Notes <?php echo areNotesRequiredForTransactions($db) ? '<span class="text-danger">*</span>' : ''; ?></label>
                        <textarea class="form-control" id="quick_notes" name="notes" rows="3" <?php echo areNotesRequiredForTransactions($db) ? 'required' : ''; ?>></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Stock History Modal -->
<div class="modal fade" id="stockHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Stock Transaction History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="stockHistoryContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Stock Modal -->
<div class="modal fade" id="transferStockModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Transfer Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/transfer_stock.php" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="transfer_product_id" class="form-label">Product <span class="text-danger">*</span></label>
                        <select class="form-select" id="transfer_product_id" name="product_id" required>
                            <option value="">Select Product</option>
                            <!-- Will be populated via AJAX -->
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="transfer_from_location_id" class="form-label">From Location <span class="text-danger">*</span></label>
                                <select class="form-select" id="transfer_from_location_id" name="from_location_id" required>
                                    <option value="">Select Source Location</option>
                                    <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo htmlspecialchars($location['location_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="transfer_to_location_id" class="form-label">To Location <span class="text-danger">*</span></label>
                                <select class="form-select" id="transfer_to_location_id" name="to_location_id" required>
                                    <option value="">Select Destination Location</option>
                                    <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['id']; ?>"><?php echo htmlspecialchars($location['location_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Current Quantity in Source Location</label>
                        <p id="transfer_current_quantity" class="form-control-static">Will be loaded when product and source location are selected</p>
                    </div>
                    <div class="mb-3">
                        <label for="transfer_quantity" class="form-label">Quantity to Transfer <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="transfer_quantity" name="quantity" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label for="transfer_notes" class="form-label">Notes <?php echo areNotesRequiredForTransactions($db) ? '<span class="text-danger">*</span>' : ''; ?></label>
                        <textarea class="form-control" id="transfer_notes" name="notes" rows="3" <?php echo areNotesRequiredForTransactions($db) ? 'required' : ''; ?>></textarea>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Stock will be transferred from the source location to the destination location. Make sure there is enough available stock in the source location.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Transfer Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
    $(document).ready(function() {
        // Display loading message
        $('#add_product_id').html('<option value="">Loading products...</option>');
        $('#transfer_product_id').html('<option value="">Loading products...</option>');
        
        // Load products for dropdown
        $.ajax({
            url: 'actions/get_products_for_stock.php',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                console.log('Products data:', data);
                if (data.success) {
                    if (data.products && data.products.length > 0) {
                        var options = '<option value="">Select Product</option>';
                        $.each(data.products, function(index, product) {
                            options += '<option value="' + product.id + '">' + product.product_name + ' (' + (product.product_code || 'No Code') + ')</option>';
                        });
                        $('#add_product_id').html(options);
                        $('#transfer_product_id').html(options);
                        console.log('Populated dropdowns with ' + data.products.length + ' products');
                    } else {
                        $('#add_product_id').html('<option value="">No products available</option>');
                        $('#transfer_product_id').html('<option value="">No products available</option>');
                        console.log('No products found in database');
                        alert('No products found in the database. Please add products first.');
                    }
                } else {
                    $('#add_product_id').html('<option value="">Error loading products</option>');
                    $('#transfer_product_id').html('<option value="">Error loading products</option>');
                    console.error('Error loading products:', data.message);
                }
            },
            error: function(xhr, status, error) {
                $('#add_product_id').html('<option value="">Error loading products</option>');
                $('#transfer_product_id').html('<option value="">Error loading products</option>');
                console.error('AJAX error:', status, error);
                alert('Error loading products. Please check the console for details.');
            }
        });
        
        // Quick adjust button click
        $(document).on('click', '.quick-adjust', function(e) {
            e.preventDefault();
            console.log('Quick adjust button clicked');
            
            var stockId = $(this).data('id');
            var productId = $(this).data('product-id');
            var productName = $(this).data('product-name');
            var locationId = $(this).data('location-id');
            var locationName = $(this).data('location-name');
            var quantity = $(this).data('quantity');
            
            console.log('Stock data:', {
                stockId: stockId,
                productId: productId,
                productName: productName,
                locationId: locationId,
                locationName: locationName,
                quantity: quantity
            });
            
            // Set the values in the modal
            $('#quick_stock_id').val(stockId);
            $('#quick_product_id').val(productId);
            $('#quick_location_id').val(locationId);
            $('#quick_product_name').text(productName);
            $('#quick_location_name').text(locationName);
            $('#quick_current_quantity').text(quantity);
            $('#quick_new_quantity').val(quantity);
            
            // Manually show the modal
            var modal = new bootstrap.Modal(document.getElementById('quickAdjustModal'));
            modal.show();
        });
        
        // Load products for quick adjust modal
        function loadProductsForQuickAdjust() {
            $.ajax({
                url: 'actions/get_products_for_stock.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        if (data.products && data.products.length > 0) {
                            var options = '<option value="">Select Product</option>';
                            $.each(data.products, function(index, product) {
                                options += '<option value="' + product.id + '">' + product.product_name + ' (' + (product.product_code || 'No Code') + ')</option>';
                            });
                            $('#quick_product_select').html(options);
                            console.log('Populated quick adjust product dropdown with ' + data.products.length + ' products');
                        } else {
                            $('#quick_product_select').html('<option value="">No products available</option>');
                            console.log('No products found for quick adjust dropdown');
                        }
                    } else {
                        $('#quick_product_select').html('<option value="">Error loading products</option>');
                        console.error('Error loading products:', data.message);
                    }
                },
                error: function(xhr, status, error) {
                    $('#quick_product_select').html('<option value="">Error loading products</option>');
                    console.error('AJAX error loading products:', status, error);
                }
            });
        }
        
        // Load current quantity when product and location are selected
        function loadCurrentQuantity() {
            var productId = $('#quick_product_select').val();
            var locationId = $('#quick_location_select').val();
            
            // Reset quantity display
            $('#quick_current_quantity').text('Loading...');
            
            if (productId && locationId) {
                console.log('Loading quantity for product ID: ' + productId + ' and location ID: ' + locationId);
                
                $.ajax({
                    url: 'actions/get_current_stock.php',
                    type: 'GET',
                    data: {
                        product_id: productId,
                        location_id: locationId
                    },
                    dataType: 'json',
                    success: function(data) {
                        console.log('AJAX response:', data);
                        if (data && data.success) {
                            $('#quick_current_quantity').text(data.quantity || '0');
                            $('#quick_new_quantity').val(data.quantity || '0');
                            $('#quick_stock_id').val(data.stock_id || '');
                        } else {
                            $('#quick_current_quantity').text('0');
                            $('#quick_new_quantity').val('0');
                            $('#quick_stock_id').val('');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#quick_current_quantity').text('Error loading quantity');
                        console.error('AJAX error loading quantity:', status, error);
                    }
                });
            } else {
                $('#quick_current_quantity').text('Select product and location first');
                $('#quick_new_quantity').val('');
            }
        }
        
        // Load current quantity for transfer modal when product and source location are selected
        function loadTransferCurrentQuantity() {
            var productId = $('#transfer_product_id').val();
            var fromLocationId = $('#transfer_from_location_id').val();
            
            // Reset quantity display
            $('#transfer_current_quantity').text('Loading...');
            
            if (productId && fromLocationId) {
                console.log('Loading quantity for transfer - product ID: ' + productId + ' and source location ID: ' + fromLocationId);
                
                $.ajax({
                    url: 'actions/get_current_stock.php',
                    type: 'GET',
                    data: {
                        product_id: productId,
                        location_id: fromLocationId
                    },
                    dataType: 'json',
                    success: function(data) {
                        console.log('AJAX response for transfer quantity:', data);
                        if (data && data.success) {
                            $('#transfer_current_quantity').text(data.quantity || '0');
                            // Set max transfer quantity to current quantity
                            $('#transfer_quantity').attr('max', data.quantity || 0);
                        } else {
                            $('#transfer_current_quantity').text('0');
                            $('#transfer_quantity').attr('max', 0);
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#transfer_current_quantity').text('Error loading quantity');
                        console.error('AJAX error loading transfer quantity:', status, error);
                    }
                });
            } else {
                $('#transfer_current_quantity').text('Select product and source location first');
                $('#transfer_quantity').val('');
            }
        }
        
        // Event listeners for the quick adjust modal
        $('#quick_product_select, #quick_location_select').change(function() {
            loadCurrentQuantity();
        });
        
        // Event listeners for the transfer stock modal
        $('#transfer_product_id, #transfer_from_location_id').change(function() {
            loadTransferCurrentQuantity();
        });
        
        // Initialize transfer modal when shown
        $('#transferStockModal').on('shown.bs.modal', function() {
            // Clear previous values
            $('#transfer_current_quantity').text('Will be loaded when product and source location are selected');
            $('#transfer_quantity').val('');
            
            // If product and location are already selected, load the quantity
            if ($('#transfer_product_id').val() && $('#transfer_from_location_id').val()) {
                loadTransferCurrentQuantity();
            }
        });
        
        // Main adjust stock button click
        $('button[data-bs-target="#quickAdjustModal"]').not('.quick-adjust').click(function() {
            // Load products for the dropdown
            loadProductsForQuickAdjust();
            
            // Reset form fields
            $('#quick_stock_id').val('');
            $('#quick_location_select').val('');
            $('#quick_current_quantity').text('Select product and location first');
            $('#quick_new_quantity').val('');
            $('#quick_reason').val('');
            $('#quick_notes').val('');
        });
        
        // Product and location selection change events
        $('#quick_product_select, #quick_location_select').change(function() {
            loadCurrentQuantity();
        });
        
        // View history button click
        $('.view-history').click(function() {
            var productId = $(this).data('product-id');
            var locationId = $(this).data('location-id');
            
            $('#stockHistoryContent').html('<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>');
            
            $.ajax({
                url: 'actions/get_stock_history.php',
                type: 'GET',
                data: {
                    product_id: productId,
                    location_id: locationId
                },
                success: function(response) {
                    $('#stockHistoryContent').html(response);
                },
                error: function() {
                    $('#stockHistoryContent').html('<div class="alert alert-danger">Error loading stock history</div>');
                }
            });
        });
        // Handle stock history modal
        $(document).on('click', '.view-history', function() {
            var productId = $(this).data('product-id');
            var locationId = $(this).data('location-id');
            
            console.log('Loading stock history for product ID: ' + productId + ', location ID: ' + locationId);
            
            // Show loading spinner
            $('#stockHistoryContent').html('<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>');
            
            // Load stock history data
            $.ajax({
                url: 'actions/get_stock_history.php',
                type: 'GET',
                data: {
                    product_id: productId,
                    location_id: locationId
                },
                success: function(response) {
                    $('#stockHistoryContent').html(response);
                },
                error: function(xhr, status, error) {
                    $('#stockHistoryContent').html('<div class="alert alert-danger">Error loading stock history: ' + error + '</div>');
                    console.error('AJAX error loading stock history:', status, error);
                }
            });
        });
    });
</script>
