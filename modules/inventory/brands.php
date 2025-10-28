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
require_once 'includes/brand_settings_helper.php';

$success_message = '';
$error_message = '';

// Get all brand settings
$auto_generate_code = isAutoGenerateBrandCodeEnabled($db);
$brand_code_prefix = getBrandCodePrefix($db);
$brand_types = getBrandTypes($db);
$default_brand_type = getDefaultBrandType($db);
$require_logo = isBrandLogoRequired($db);
$max_logo_file_size = getMaxLogoFileSize($db);

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Initialize brands as an empty array
$brands = [];

// Get all brands
try {
    // First, check if the products table has a brand_id column
    $checkColumnQuery = "SHOW COLUMNS FROM products LIKE 'brand_id'";
    $checkStmt = $db->prepare($checkColumnQuery);
    $checkStmt->execute();
    $columnExists = $checkStmt->rowCount() > 0;
    
    if ($columnExists) {
        $query = "SELECT b.*, 
                 u1.username as created_by_name,
                 u2.username as updated_by_name,
                 (SELECT COUNT(*) FROM products WHERE brand_id = b.id) as product_count
                 FROM brands b
                 LEFT JOIN users u1 ON b.created_by = u1.id
                 LEFT JOIN users u2 ON b.updated_by = u2.id
                 WHERE b.status = 1
                 ORDER BY b.brand_name";
    } else {
        // If brand_id doesn't exist, don't try to count products
        $query = "SELECT b.*, 
                 u1.username as created_by_name,
                 u2.username as updated_by_name,
                 0 as product_count
                 FROM brands b
                 LEFT JOIN users u1 ON b.created_by = u1.id
                 LEFT JOIN users u2 ON b.updated_by = u2.id
                 WHERE b.status = 1
                 ORDER BY b.brand_name";
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching brands: " . $e->getMessage();
    error_log("Error fetching brands: " . $e->getMessage());
    // Initialize brands as empty array in case of error
    $brands = [];
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
                    <h2>Brand Management</h2>
                </div>
                <div class="col-md-6 text-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-add-brand">
                        <i class="fas fa-plus"></i> Add Brand
                    </button>
                </div>
            </div>

            <ol class="breadcrumb mb-4">
                <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
                <li class="breadcrumb-item">Inventory</li>
                <li class="breadcrumb-item active">Brands</li>
            </ol>
            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-copyright me-1"></i>
                    Brands
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="brands-table" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>Logo</th>
                                    <th>Brand Name</th>
                                    <th>Brand Code</th>
                                    <th>Brand Type</th>
                                    <th>Description</th>
                                    <th>Website</th>
                                    <th>Products</th>
                                    <th>Created By</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($brands as $brand): ?>
                                <tr>
                                    <td class="text-center">
                                        <?php if (!empty($brand['logo_path'])): ?>
                                        <img src="<?php echo '../../' . htmlspecialchars($brand['logo_path']); ?>" alt="Brand Logo" class="img-thumbnail" style="max-width: 50px; max-height: 50px;">
                                        <?php else: ?>
                                        <i class="fas fa-building fa-2x text-muted"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($brand['brand_name']); ?></td>
                                    <td><?php echo htmlspecialchars($brand['brand_code'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($brand['brand_type'] ?? 'Standard'); ?></td>
                                    <td><?php echo htmlspecialchars($brand['description'] ?? ''); ?></td>
                                    <td>
                                        <?php if (!empty($brand['website'])): ?>
                                        <a href="<?php echo htmlspecialchars($brand['website']); ?>" target="_blank">
                                            <?php echo htmlspecialchars($brand['website']); ?>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $brand['product_count']; ?></td>
                                    <td><?php echo htmlspecialchars($brand['created_by_name'] ?? ''); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($brand['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-info edit-brand-btn" 
                                                    data-id="<?php echo $brand['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($brand['brand_name']); ?>"
                                                    data-code="<?php echo htmlspecialchars($brand['brand_code'] ?? ''); ?>"
                                                    data-type="<?php echo htmlspecialchars($brand['brand_type'] ?? 'Standard'); ?>"
                                                    data-description="<?php echo htmlspecialchars($brand['description'] ?? ''); ?>"
                                                    data-website="<?php echo htmlspecialchars($brand['website'] ?? ''); ?>"
                                                    data-logo="<?php echo htmlspecialchars($brand['logo_path'] ?? ''); ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger delete-brand-btn" 
                                                    data-id="<?php echo $brand['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($brand['brand_name']); ?>"
                                                    data-product-count="<?php echo $brand['product_count']; ?>">
                                                <i class="fas fa-trash"></i>
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

    <!-- Footer -->
    <?php include '../../includes/footer.php'; ?>
</div>

<!-- Add Brand Modal -->
<div class="modal fade" id="modal-add-brand" tabindex="-1" aria-labelledby="addBrandModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addBrandModalLabel">Add Brand</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/save_brand.php" method="post" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="brand_name" class="form-label">Brand Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="brand_name" name="brand_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="brand_code" class="form-label">Brand Code <?php if (!$auto_generate_code): ?><span class="text-danger">*</span><?php endif; ?></label>
                        <input type="text" class="form-control" id="brand_code" name="brand_code" <?php if (!$auto_generate_code): ?>required<?php endif; ?> <?php if ($auto_generate_code): ?>placeholder="Will be auto-generated"<?php endif; ?>>
                        <?php if ($auto_generate_code): ?>
                        <div class="form-text">Leave blank to auto-generate using prefix: <?php echo htmlspecialchars($brand_code_prefix); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="brand_type" class="form-label">Brand Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="brand_type" name="brand_type" required>
                            <?php foreach ($brand_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php if ($type === $default_brand_type): ?>selected<?php endif; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="website" class="form-label">Website</label>
                        <input type="url" class="form-control" id="website" name="website" placeholder="https://example.com">
                    </div>
                    <div class="mb-3">
                        <label for="brand_logo" class="form-label">Brand Logo <?php if ($require_logo): ?><span class="text-danger">*</span><?php endif; ?></label>
                        <input type="file" class="form-control" id="brand_logo" name="brand_logo" accept="image/*" <?php if ($require_logo): ?>required<?php endif; ?>>
                        <div class="form-text">Recommended size: 200x200 pixels. Max file size: <?php echo $max_logo_file_size; ?>MB.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Brand</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Brand Modal -->
<div class="modal fade" id="modal-edit-brand" tabindex="-1" aria-labelledby="editBrandModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editBrandModalLabel">Edit Brand</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/update_brand.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="brand_id" id="edit_brand_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_brand_name" class="form-label">Brand Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_brand_name" name="brand_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_brand_code" class="form-label">Brand Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_brand_code" name="brand_code" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_brand_type" class="form-label">Brand Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_brand_type" name="brand_type" required>
                            <?php foreach ($brand_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>">
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_website" class="form-label">Website</label>
                        <input type="url" class="form-control" id="edit_website" name="website" placeholder="https://example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Current Logo</label>
                        <div id="current_logo_container" class="text-center mb-2"></div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_brand_logo" class="form-label">Change Logo <?php if ($require_logo): ?><span class="text-danger">*</span><?php endif; ?></label>
                        <input type="file" class="form-control" id="edit_brand_logo" name="brand_logo" accept="image/*">
                        <div class="form-text">Recommended size: 200x200 pixels. Max file size: <?php echo $max_logo_file_size; ?>MB.</div>
                        <div class="form-text text-muted">Leave empty to keep the current logo.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Brand</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Brand Modal -->
<div class="modal fade" id="modal-delete-brand" tabindex="-1" aria-labelledby="deleteBrandModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteBrandModalLabel">Delete Brand</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/delete_brand.php" method="post">
                <input type="hidden" name="brand_id" id="delete_brand_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete the brand: <strong id="delete_brand_name"></strong>?</p>
                    <div id="brand_has_products" class="alert alert-warning d-none">
                        <i class="fas fa-exclamation-triangle me-2"></i> This brand has <span id="product_count"></span> associated products. 
                        Deleting it will remove the brand association from these products.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Brand</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables is already loaded in the header -->
<script>
// Initialize bs-custom-file-input for file inputs
$(document).ready(function () {
    // Handle custom file input
    $('input[type="file"]').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').html(fileName || 'Choose file');
    });
});
</script>

<script>
$(function () {
    // Initialize DataTable
    $('#brands-table').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true,
    });

    // Edit brand button click
    $('.edit-brand-btn').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var code = $(this).data('code');
        var type = $(this).data('type');
        var description = $(this).data('description');
        var website = $(this).data('website');
        var logo = $(this).data('logo');

        $('#edit_brand_id').val(id);
        $('#edit_brand_name').val(name);
        $('#edit_brand_code').val(code);
        $('#edit_brand_type').val(type);
        $('#edit_description').val(description);
        $('#edit_website').val(website);

        // Display current logo if exists
        var logoContainer = $('#current_logo_container');
        logoContainer.empty();
        if (logo) {
            logoContainer.html('<img src="../../' + logo + '" alt="Current Logo" class="img-thumbnail" style="max-height: 100px;">');
        } else {
            logoContainer.html('<div class="text-muted"><i class="fas fa-building fa-3x"></i><p>No logo</p></div>');
        }

        var editBrandModal = new bootstrap.Modal(document.getElementById('modal-edit-brand'));
        editBrandModal.show();
    });

    // Delete brand button click
    $('.delete-brand-btn').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var productCount = $(this).data('product-count');

        $('#delete_brand_id').val(id);
        $('#delete_brand_name').text(name);

        // Show warning if brand has products
        if (productCount > 0) {
            $('#product_count').text(productCount);
            $('#brand_has_products').removeClass('d-none');
        } else {
            $('#brand_has_products').addClass('d-none');
        }

        var deleteBrandModal = new bootstrap.Modal(document.getElementById('modal-delete-brand'));
        deleteBrandModal.show();
    });
    
    // Sidebar functionality is now handled by the global sidebar.js script
});
</script>

<!-- Scripts are already included in the footer -->
