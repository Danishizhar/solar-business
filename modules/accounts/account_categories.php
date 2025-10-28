<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

$pageTitle = "Account Categories";
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <!-- Success Alert -->
        <div class="alert alert-success alert-dismissible fade" role="alert" id="successAlert" style="display: none;">
            <span id="successMessage"></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <!-- Error Alert -->
        <div class="alert alert-danger alert-dismissible fade" role="alert" id="errorAlert" style="display: none;">
            <span id="errorMessage"></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary"><?php echo $pageTitle; ?></h6>
                    <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_add'])): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="fas fa-plus"></i> Add New Category
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="categoriesTable">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Parent Category</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Get all account categories with their parent categories
                                $categories_query = "
                                    SELECT 
                                        ac.*,
                                        p.name as parent_name,
                                        c.username as created_by_name,
                                        u.username as updated_by_name
                                    FROM account_categories ac
                                    LEFT JOIN account_categories p ON ac.parent_id = p.id
                                    LEFT JOIN users c ON ac.created_by = c.id
                                    LEFT JOIN users u ON ac.updated_by = u.id
                                    WHERE ac.status = 1
                                    ORDER BY ac.code";

                                try {
                                    $stmt = $conn->prepare($categories_query);
                                    $stmt->execute();
                                    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    error_log("Error in account_categories.php: " . $e->getMessage());
                                    echo "<div class='alert alert-danger'>An error occurred while loading categories. Please try again later.</div>";
                                    $categories = [];
                                }

                                foreach ($categories as $category): 
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($category['code']); ?></td>
                                    <td><?php echo htmlspecialchars($category['name']); ?></td>
                                    <td><?php echo ucfirst(htmlspecialchars($category['type'])); ?></td>
                                    <td><?php echo htmlspecialchars($category['parent_name'] ?? 'None'); ?></td>
                                    <td><?php echo htmlspecialchars($category['description'] ?? ''); ?></td>
                                    <td>
                                        <?php if ($category['status']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_edit'])): ?>
                                        <button type="button" class="btn btn-sm btn-primary edit-category" 
                                                data-id="<?php echo $category['id']; ?>"
                                                data-code="<?php echo htmlspecialchars($category['code']); ?>"
                                                data-name="<?php echo htmlspecialchars($category['name']); ?>"
                                                data-type="<?php echo htmlspecialchars($category['type']); ?>"
                                                data-parent="<?php echo $category['parent_id'] ?? ''; ?>"
                                                data-description="<?php echo htmlspecialchars($category['description'] ?? ''); ?>"
                                                data-bs-toggle="modal" data-bs-target="#editCategoryModal">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_delete'])): ?>
                                        <button type="button" class="btn btn-sm btn-danger delete-category"
                                                data-id="<?php echo $category['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($category['name']); ?>"
                                                data-bs-toggle="modal" data-bs-target="#deleteCategoryModal">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
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

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addCategoryForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Code *</label>
                        <input type="text" class="form-control" name="code" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type *</label>
                        <select class="form-select" name="type" required>
                            <option value="">Select Type</option>
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                            <option value="equity">Equity</option>
                            <option value="revenue">Revenue</option>
                            <option value="expense">Expense</option>
                            <option value="cogs">Cost of Goods Sold</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Category</label>
                        <select class="form-select" name="parent_id">
                            <option value="">None</option>
                            <?php 
                            // Get parent categories for dropdown
                            $parent_categories_query = "SELECT id, code, name, type FROM account_categories WHERE status = 1 ORDER BY code";
                            $stmt = $conn->prepare($parent_categories_query);
                            $stmt->execute();
                            $parent_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($parent_categories as $parent_category): 
                            ?>
                            <option value="<?php echo $parent_category['id']; ?>" data-type="<?php echo htmlspecialchars($parent_category['type']); ?>">
                                <?php echo htmlspecialchars($parent_category['code'] . ' - ' . $parent_category['name'] . ' (' . ucfirst($parent_category['type']) . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editCategoryForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Code *</label>
                        <input type="text" class="form-control" name="code" id="edit_code" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type *</label>
                        <select class="form-select" name="type" id="edit_type" required>
                            <option value="">Select Type</option>
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                            <option value="equity">Equity</option>
                            <option value="revenue">Revenue</option>
                            <option value="expense">Expense</option>
                            <option value="cogs">Cost of Goods Sold</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Category</label>
                        <select class="form-select" name="parent_id" id="edit_parent_id">
                            <option value="">None</option>
                            <?php foreach ($parent_categories as $parent_category): ?>
                            <option value="<?php echo $parent_category['id']; ?>" data-type="<?php echo htmlspecialchars($parent_category['type']); ?>">
                                <?php echo htmlspecialchars($parent_category['code'] . ' - ' . $parent_category['name'] . ' (' . ucfirst($parent_category['type']) . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="deleteCategoryForm">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete the category "<span id="delete_name"></span>"?</p>
                    <p class="text-danger">This action cannot be undone.</p>
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
    // Handle Add Category Form Submit
    $('#addCategoryForm').submit(function(e) {
        e.preventDefault();
        
        var formData = $(this).serializeArray();
        
        // Debug
        console.log('Submitting add form with data:', formData);
        
        $.ajax({
            url: 'actions/save_category.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    // Hide modal
                    var addCategoryModal = bootstrap.Modal.getInstance(document.getElementById('addCategoryModal'));
                    if (addCategoryModal) addCategoryModal.hide();
                    
                    // Show success message
                    showSuccess('Category created successfully');
                    
                    // Reload the page after a short delay
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showError(response.message || 'Error creating category');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                showError('Error creating category. Please try again.');
            }
        });
    });
    
    // Filter parent categories based on selected type
    function filterParentCategories(selectElement, typeValue) {
        var $select = $(selectElement);
        var $options = $select.find('option');
        
        // Always show the 'None' option
        $options.each(function() {
            var $option = $(this);
            var value = $option.val();
            var type = $option.data('type');
            
            if (value === '') {
                // This is the 'None' option, always show it
                $option.show();
            } else if (type !== typeValue) {
                // Hide options with different type
                $option.hide();
                // If this option was selected, reset to 'None'
                if ($option.is(':selected')) {
                    $select.val('');
                }
            } else {
                // Show options with matching type
                $option.show();
            }
        });
    }
    
    // Add event listeners for type selection
    $('select[name="type"]').on('change', function() {
        var typeValue = $(this).val();
        if (typeValue) {
            filterParentCategories('select[name="parent_id"]', typeValue);
        }
    });
    
    // Initialize filtering when add category modal is shown
    $('#addCategoryModal').on('shown.bs.modal', function() {
        var typeValue = $('select[name="type"]').val();
        if (typeValue) {
            filterParentCategories('select[name="parent_id"]', typeValue);
        }
    });
    
    // Add event listener for edit form type selection
    $('#edit_type').on('change', function() {
        var typeValue = $(this).val();
        if (typeValue) {
            filterParentCategories('#edit_parent_id', typeValue);
        }
    });
    
    // Initialize filtering when edit category modal is shown
    $('#editCategoryModal').on('shown.bs.modal', function() {
        var typeValue = $('#edit_type').val();
        if (typeValue) {
            filterParentCategories('#edit_parent_id', typeValue);
        }
    });
    
    // Initialize DataTable
    var table = $('#categoriesTable').DataTable({
        processing: true,
        serverSide: false,
        responsive: true,
        order: [[0, 'asc']],
        dom: '<"row"<"col-sm-12 col-md-6"B><"col-sm-12 col-md-6"f>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            {
                extend: 'collection',
                text: '<i class="fas fa-download"></i> Export',
                buttons: ['copy', 'excel', 'csv', 'pdf', 'print']
            }
        ]
    });

    // Handle Edit Category
    $(document).on('click', '.edit-category', function() {
        var id = $(this).data('id');
        
        // Debug
        console.log('Edit category clicked, ID:', id);
        
        // Get category data via AJAX
        $.ajax({
            url: 'actions/get_category.php',
            type: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    var category = response.data;
                    
                    // Set form values
                    $('#edit_id').val(category.id);
                    $('#edit_code').val(category.code);
                    $('#edit_name').val(category.name);
                    $('#edit_type').val(category.type);
                    $('#edit_parent_id').val(category.parent_id);
                    $('#edit_description').val(category.description);
                    
                    // Show modal
                    var editCategoryModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
                    editCategoryModal.show();
                } else {
                    showError(response.message || 'Error loading category details');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                showError('Error loading category details');
            }
        });
    });

    // Handle Edit Category Form Submit
    $('#editCategoryForm').submit(function(e) {
        e.preventDefault();
        
        var formData = $(this).serializeArray();
        
        // Debug
        console.log('Submitting edit form with data:', formData);
        
        $.ajax({
            url: 'actions/update_category.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    // Hide modal
                    var editCategoryModal = bootstrap.Modal.getInstance(document.getElementById('editCategoryModal'));
                    if (editCategoryModal) editCategoryModal.hide();
                    
                    // Show success message
                    showSuccess('Category updated successfully');
                    
                    // Reload the page after a short delay
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showError(response.message || 'Error updating category');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                showError('Error updating category. Please try again.');
            }
        });
    });

    // Handle Delete Category
    $(document).on('click', '.delete-category', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        
        // Debug
        console.log('Delete category clicked, ID:', id, 'Name:', name);
        
        // Set modal content
        $('#delete_id').val(id);
        $('#delete_name').text(name);
        
        // Show modal - using Bootstrap 5 method
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteCategoryModal'));
        deleteModal.show();
    });

    // Handle Delete Category Form Submit
    $('#deleteCategoryForm').submit(function(e) {
        e.preventDefault();
        
        var formData = $(this).serializeArray();
        
        // Debug
        console.log('Submitting delete form with data:', formData);
        
        $.ajax({
            url: 'actions/update_category_status.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log('Server response:', response);
                if (response.success) {
                    // Hide modal
                    var deleteCategoryModal = bootstrap.Modal.getInstance(document.getElementById('deleteCategoryModal'));
                    if (deleteCategoryModal) deleteCategoryModal.hide();
                    
                    // Show success message
                    showSuccess('Category permanently deleted successfully');
                    
                    // Reload the page after a short delay
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showError(response.message || 'Error deleting category');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                showError('Error deleting category. Please try again.');
            }
        });
    });
    
    // Function to show success message
    function showSuccess(message) {
        $('#successMessage').text(message);
        $('#successAlert').addClass('show').show();
        setTimeout(function() {
            $('#successAlert').removeClass('show').hide();
        }, 3000);
    }
    
    // Function to show error message
    function showError(message) {
        $('#errorMessage').text(message);
        $('#errorAlert').addClass('show').show();
        setTimeout(function() {
            $('#errorAlert').removeClass('show').hide();
        }, 3000);
    }
    
    // Helper function to clean up modal elements
    function cleanupModalElements() {
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open');
        $('body').css('overflow', '');
        $('body').css('padding-right', '');
    }
    
    // Add event listeners for modal close events to ensure proper cleanup
    $('.modal').on('hidden.bs.modal', function () {
        cleanupModalElements();
    });
    
    // Handle Escape key press to clean up modals
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('.modal.show').length > 0) {
            setTimeout(cleanupModalElements, 200);
        }
    });
    
    // Add click handler for modal close buttons
    $('[data-bs-dismiss="modal"]').on('click', function() {
        setTimeout(cleanupModalElements, 200);
    });
});
</script>
