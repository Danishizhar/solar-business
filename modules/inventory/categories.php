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
require_once 'includes/category_settings_helper.php';

// Get messages from session
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';

// Clear session messages after retrieving them
if (isset($_SESSION['success_message'])) {
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    unset($_SESSION['error_message']);
}

// Get all category settings
$hierarchical_allowed = areHierarchicalCategoriesAllowed($db);
$max_hierarchy_depth = getCategoryMaxHierarchyDepth($db);
$auto_generate_code = isAutoGenerateCategoryCodeEnabled($db);
$category_code_prefix = getCategoryCodePrefix($db);
$category_types = getCategoryTypes($db);
$default_category_type = getDefaultCategoryType($db);

// Get all categories with hierarchical structure
try {
    $query = "SELECT c.*, 
             p.category_name as parent_category_name,
             (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.id) as product_count,
             u1.username as created_by_name,
             u2.username as updated_by_name
             FROM product_categories c
             LEFT JOIN product_categories p ON c.parent_id = p.id
             LEFT JOIN users u1 ON c.created_by = u1.id
             LEFT JOIN users u2 ON c.updated_by = u2.id
             WHERE c.status = 1
             ORDER BY c.category_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format categories for display
    $categories = formatCategoryHierarchy($all_categories);
} catch (PDOException $e) {
    $error_message = "Error fetching categories: " . $e->getMessage();
    error_log("Error fetching product categories: " . $e->getMessage());
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
                    <h2>Product Categories</h2>
                </div>
                <div class="col-md-6 text-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </div>
            </div>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <!-- Categories Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Category Name</th>
                                    <th>Code</th>
                                    <th>Type</th>
                                    <th>Parent Category</th>
                                    <th>Products</th>
                                    <th>Created By</th>
                                    <th>Created Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($categories)): ?>
                                <tr>
                                    <td colspan="7" class="text-center">No categories found</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($category['formatted_name'] ?? $category['category_name']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($category['category_code'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($category['category_type'] ?? 'Product'); ?></td>
                                    <td><?php echo htmlspecialchars($category['parent_category_name'] ?? 'None'); ?></td>
                                    <td><?php echo (int)$category['product_count']; ?></td>
                                    <td><?php echo htmlspecialchars($category['created_by_name'] ?? ''); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($category['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-primary edit-category" 
                                                    data-id="<?php echo $category['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($category['category_name']); ?>"
                                                    data-code="<?php echo htmlspecialchars($category['category_code'] ?? ''); ?>"
                                                    data-type="<?php echo htmlspecialchars($category['category_type'] ?? 'Product'); ?>"
                                                    data-parent="<?php echo $category['parent_id'] ?? 0; ?>"
                                                    data-description="<?php echo htmlspecialchars($category['description'] ?? ''); ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editCategoryModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger delete-category" 
                                                    data-id="<?php echo $category['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($category['category_name']); ?>"
                                                    data-product-count="<?php echo (int)$category['product_count']; ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteCategoryModal">
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
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/save_category.php" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="category_name" class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="category_name" name="category_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="category_code" class="form-label">Category Code <?php echo !$auto_generate_code ? '<span class="text-danger">*</span>' : ''; ?></label>
                        <input type="text" class="form-control" id="category_code" name="category_code" <?php echo !$auto_generate_code ? 'required' : ''; ?>>
                        <div class="form-text">
                            <?php if ($auto_generate_code): ?>
                            Leave empty for auto-generation with prefix: <?php echo htmlspecialchars($category_code_prefix); ?>
                            <?php else: ?>
                            Auto-generation is disabled. You must enter a category code.
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($hierarchical_allowed): ?>
                    <div class="mb-3">
                        <label for="parent_id" class="form-label">Parent Category</label>
                        <select class="form-select" id="parent_id" name="parent_id">
                            <option value="0">None (Top Level)</option>
                            <?php foreach ($all_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($max_hierarchy_depth > 0): ?>
                        <div class="form-text">Maximum hierarchy depth: <?php echo $max_hierarchy_depth; ?> levels</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="category_type" class="form-label">Category Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="category_type" name="category_type" required>
                            <?php foreach ($category_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($type == $default_category_type) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/update_category.php" method="POST">
                <input type="hidden" name="category_id" id="edit_category_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_category_name" class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_category_name" name="category_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_category_code" class="form-label">Category Code <?php echo !$auto_generate_code ? '<span class="text-danger">*</span>' : ''; ?></label>
                        <input type="text" class="form-control" id="edit_category_code" name="category_code" <?php echo !$auto_generate_code ? 'required' : ''; ?>>
                        <div class="form-text">
                            <?php if ($auto_generate_code): ?>
                            Auto-generated with prefix: <?php echo htmlspecialchars($category_code_prefix); ?>
                            <?php else: ?>
                            Auto-generation is disabled. You must enter a category code.
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($hierarchical_allowed): ?>
                    <div class="mb-3">
                        <label for="edit_parent_id" class="form-label">Parent Category</label>
                        <select class="form-select" id="edit_parent_id" name="parent_id">
                            <option value="0">None (Top Level)</option>
                            <?php foreach ($all_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($max_hierarchy_depth > 0): ?>
                        <div class="form-text">Maximum hierarchy depth: <?php echo $max_hierarchy_depth; ?> levels</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="edit_category_type" class="form-label">Category Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_category_type" name="category_type" required>
                            <?php foreach ($category_types as $type): ?>
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Category Modal -->
<div class="modal fade" id="deleteCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/delete_category.php" method="POST">
                <input type="hidden" name="category_id" id="delete_category_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete the category: <strong id="delete_category_name"></strong>?</p>
                    <div id="category_has_products" class="alert alert-warning d-none">
                        This category has <span id="product_count"></span> products associated with it. 
                        Deleting this category will remove the category association from these products.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
    $(document).ready(function() {
        // Edit category button click
        $('.edit-category').click(function() {
            var id = $(this).data('id');
            var name = $(this).data('name');
            var code = $(this).data('code');
            var type = $(this).data('type');
            var parent = $(this).data('parent');
            var description = $(this).data('description');
            
            $('#edit_category_id').val(id);
            $('#edit_category_name').val(name);
            $('#edit_category_code').val(code);
            $('#edit_category_type').val(type);
            $('#edit_parent_id').val(parent);
            $('#edit_description').val(description);
            
            // Disable selecting self as parent
            $('#edit_parent_id option').prop('disabled', false);
            $('#edit_parent_id option[value="' + id + '"]').prop('disabled', true);
        });
        
        // Delete category button click
        $('.delete-category').click(function() {
            var id = $(this).data('id');
            var name = $(this).data('name');
            var productCount = $(this).data('product-count');
            
            $('#delete_category_id').val(id);
            $('#delete_category_name').text(name);
            
            if (productCount > 0) {
                $('#category_has_products').removeClass('d-none');
                $('#product_count').text(productCount);
            } else {
                $('#category_has_products').addClass('d-none');
            }
        });
    });
</script>
