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

$success_message = '';
$error_message = '';

// Get inventory settings
// Removed variant functionality
$enable_batch_tracking = getInventorySetting($db, 'enable_batch_tracking', '0') == '1';
$enable_expiry_tracking = getInventorySetting($db, 'enable_expiry_tracking', '0') == '1';
$enable_serial_tracking = getInventorySetting($db, 'enable_serial_tracking', '0') == '1';

// Get all categories for dropdown
try {
    $query = "SELECT id, category_name, parent_id FROM product_categories WHERE status = 1 ORDER BY category_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching categories: " . $e->getMessage();
    error_log("Error fetching product categories: " . $e->getMessage());
}

// Get all brands for dropdown
try {
    $query = "SELECT id, brand_name FROM brands WHERE status = 1 ORDER BY brand_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching brands: " . $e->getMessage();
    error_log("Error fetching brands: " . $e->getMessage());
}

// Get all UOMs for dropdown
try {
    $query = "SELECT id, uom_name, uom_symbol, uom_type FROM uom WHERE status = 1 ORDER BY uom_type, uom_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $uoms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching UOMs: " . $e->getMessage();
    error_log("Error fetching UOMs: " . $e->getMessage());
}

// Get all attributes for product form
try {
    $query = "SELECT ad.*, 
              (SELECT GROUP_CONCAT(ca.category_id) FROM category_attributes ca WHERE ca.attribute_id = ad.id) as category_ids 
              FROM attribute_definitions ad 
              WHERE ad.status = 1 
              ORDER BY ad.attribute_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process attributes by category
    $attributes_by_category = [];
    foreach ($attributes as $attribute) {
        $category_ids = !empty($attribute['category_ids']) ? explode(',', $attribute['category_ids']) : [];
        foreach ($category_ids as $category_id) {
            if (!isset($attributes_by_category[$category_id])) {
                $attributes_by_category[$category_id] = [];
            }
            $attributes_by_category[$category_id][] = $attribute;
        }
    }
} catch (PDOException $e) {
    $error_message = "Error fetching attributes: " . $e->getMessage();
    error_log("Error fetching attributes: " . $e->getMessage());
}

// Get products with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$brand_filter = isset($_GET['brand']) ? (int)$_GET['brand'] : 0;
$uom_filter = isset($_GET['uom']) ? (int)$_GET['uom'] : 0;
$stock_filter = isset($_GET['stock']) ? $_GET['stock'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

try {
    // Initialize total_pages to prevent undefined variable error
    $total_pages = 1;
    
    // Build the query with filters
    $query = "SELECT p.*, c.category_name, b.brand_name, u.uom_name as base_uom_name 
              FROM products p
              LEFT JOIN product_categories c ON p.category_id = c.id
              LEFT JOIN brands b ON p.brand_id = b.id
              LEFT JOIN uom u ON p.base_uom_id = u.id
              WHERE 1=1";
    
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (p.product_name LIKE :product_name OR p.product_code LIKE :product_code OR p.barcode LIKE :barcode)";
        $params[':product_name'] = "%$search%";
        $params[':product_code'] = "%$search%";
        $params[':barcode'] = "%$search%";
    }
    
    if ($category_filter > 0) {
        $query .= " AND p.category_id = :category_id";
        $params[':category_id'] = $category_filter;
    }
    
    if ($brand_filter > 0) {
        $query .= " AND p.brand_id = :brand_id";
        $params[':brand_id'] = $brand_filter;
    }
    
    if ($uom_filter > 0) {
        $query .= " AND (p.base_uom_id = :base_uom_id OR p.purchase_uom_id = :purchase_uom_id OR p.sales_uom_id = :sales_uom_id)";
        $params[':base_uom_id'] = $uom_filter;
        $params[':purchase_uom_id'] = $uom_filter;
        $params[':sales_uom_id'] = $uom_filter;
    }
    
    if ($stock_filter != 'all') {
        // We need to join with inventory_stock table
        $query = str_replace("FROM products p", "FROM products p LEFT JOIN (SELECT product_id, SUM(quantity) as total_stock FROM inventory_stock WHERE status = 1 GROUP BY product_id) as stock ON p.id = stock.product_id", $query);
        
        if ($stock_filter == 'low') {
            $query .= " AND (stock.total_stock <= p.min_stock_level OR stock.total_stock IS NULL)";
        } else if ($stock_filter == 'out') {
            $query .= " AND (stock.total_stock <= 0 OR stock.total_stock IS NULL)";
        }
    }
    
    if ($status_filter != 'all') {
        $status_value = ($status_filter == 'active') ? 1 : 0;
        $query .= " AND p.is_active = :status";
        $params[':status'] = $status_value;
    }
    
    // Count total records for pagination
    $count_query = str_replace("SELECT p.*, c.category_name", "SELECT COUNT(*) as total", $query);
    $count_stmt = $db->prepare($count_query);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $result = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $total_records = $result ? $result['total'] : 0;
    $total_pages = ceil($total_records / $limit);
    
    // Add pagination to the main query
    $query .= " ORDER BY p.product_name LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        if ($key == ':limit' || $key == ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching products: " . $e->getMessage();
    error_log("Error fetching products: " . $e->getMessage());
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
                    <h2>Products</h2>
                </div>
                <div class="col-md-6 text-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                        <i class="fas fa-plus"></i> Add Product
                    </button>
                </div>
            </div>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <div class="input-group">
                                <input type="text" class="form-control" placeholder="Search products..." name="search" value="<?php echo htmlspecialchars($search); ?>">
                                <button class="btn btn-outline-secondary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" name="category" onchange="this.form.submit()">
                                <option value="0">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo ($category_filter == $category['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" name="brand" onchange="this.form.submit()">
                                <option value="0">All Brands</option>
                                <?php foreach ($brands as $brand): ?>
                                <option value="<?php echo $brand['id']; ?>" <?php echo ($brand_filter == $brand['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($brand['brand_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" name="uom" onchange="this.form.submit()">
                                <option value="0">All UOMs</option>
                                <?php foreach ($uoms as $uom): ?>
                                <option value="<?php echo $uom['id']; ?>" <?php echo (isset($_GET['uom']) && $_GET['uom'] == $uom['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($uom['uom_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <select class="form-select" name="stock" onchange="this.form.submit()">
                                <option value="all" <?php echo (!isset($_GET['stock']) || $_GET['stock'] == 'all') ? 'selected' : ''; ?>>All Stock</option>
                                <option value="low" <?php echo (isset($_GET['stock']) && $_GET['stock'] == 'low') ? 'selected' : ''; ?>>Low Stock</option>
                                <option value="out" <?php echo (isset($_GET['stock']) && $_GET['stock'] == 'out') ? 'selected' : ''; ?>>Out of Stock</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <select class="form-select" name="status" onchange="this.form.submit()">
                                <option value="all" <?php echo ($status_filter == 'all') ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo ($status_filter == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($status_filter == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Products Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th>Product Code</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Brand</th>
                                    <th>Base UOM</th>
                                    <th>Cost Price</th>
                                    <th>Selling Price</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="8" class="text-center">No products found</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($products as $product): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($product['image_path'])): ?>
                                            <?php 
                                            // Check if the file actually exists
                                            $image_full_path = dirname(dirname(__DIR__)) . '/' . $product['image_path'];
                                            if (file_exists($image_full_path)): 
                                            ?>
                                            <img src="<?php echo '../../' . htmlspecialchars($product['image_path']); ?>" alt="Product Image" class="img-thumbnail" style="max-width: 50px; max-height: 50px;">
                                            <?php else: ?>
                                            <div class="text-center text-muted"><i class="fas fa-image fa-2x"></i></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="text-center text-muted"><i class="fas fa-image fa-2x"></i></div>
                                        <?php endif; ?>
                                        <!-- Debug image path: <?php echo !empty($product['image_path']) ? $product['image_path'] . ' (Exists: ' . (file_exists(dirname(dirname(__DIR__)) . '/' . $product['image_path']) ? 'Yes' : 'No') . ')' : 'No image'; ?> -->
                                    </td>
                                    <td><?php echo htmlspecialchars($product['product_code']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($product['product_name']); ?>
                                        <?php if (isset($product['has_variants']) && $product['has_variants'] == 1): ?>
                                        <span class="badge bg-info">Has Variants</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($product['brand_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($product['base_uom_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo isset($product['cost_price']) ? formatNumber($db, $product['cost_price']) : formatNumber($db, 0); ?></td>
                                    <td><?php echo isset($product['selling_price']) ? formatNumber($db, $product['selling_price']) : formatNumber($db, 0); ?></td>
                                    <td>
                                        <?php 
                                        // Get current stock level
                                        $stock_query = "SELECT SUM(quantity) as total_stock FROM inventory_stock WHERE product_id = :product_id AND status = 1";
                                        $stock_stmt = $db->prepare($stock_query);
                                        $stock_stmt->bindValue(':product_id', $product['id'], PDO::PARAM_INT);
                                        $stock_stmt->execute();
                                        $stock_result = $stock_stmt->fetch(PDO::FETCH_ASSOC);
                                        $current_stock = $stock_result ? $stock_result['total_stock'] : 0;
                                        
                                        $stock_class = 'bg-success';
                                        if ($current_stock <= $product['reorder_point']) {
                                            $stock_class = 'bg-danger';
                                        } else if ($current_stock <= $product['min_stock_level']) {
                                            $stock_class = 'bg-warning';
                                        }
                                        ?>
                                        <span class="badge <?php echo $stock_class; ?>"><?php echo $current_stock; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($product['is_active'] == 1): ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="product_detail.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-info" data-bs-toggle="tooltip" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-primary edit-product" 
                                                    data-id="<?php echo $product['id']; ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#addProductModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger delete-product" 
                                                    data-id="<?php echo $product['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteProductModal">
                                                <i class="fas fa-trash"></i>
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
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&brand=<?php echo $brand_filter; ?>&status=<?php echo $status_filter; ?>">Previous</a>
                            </li>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&brand=<?php echo $brand_filter; ?>&status=<?php echo $status_filter; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo $category_filter; ?>&brand=<?php echo $brand_filter; ?>&status=<?php echo $status_filter; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<?php include 'modals/add_product_modal.php'; ?>

<!-- Delete Product Modal -->
<?php include 'modals/delete_product_modal.php'; ?>

<!-- Barcode Scanner Modal -->
<?php include 'modals/barcode_scanner_modal.php'; ?>

<?php include '../../includes/footer.php'; ?>

<!-- Select2 CSS from CDN -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

<!-- Select2 from CDN -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Barcode Libraries -->
<script src="../../assets/js/jsbarcode.min.js"></script>
<script src="../../assets/js/quagga.min.js"></script>

<!-- App Scripts -->
<script src="js/products.js"></script>
<script src="js/product-attributes.js"></script>
<script src="js/product-variants.js"></script>
<script src="js/barcode-manager.js"></script>
