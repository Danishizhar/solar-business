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
require_once 'includes/attribute_settings_helper.php';

$success_message = '';
$error_message = '';

// Get all attribute settings
$auto_generate_code = isAutoGenerateAttributeCodeEnabled($db);
$attribute_code_prefix = getAttributeCodePrefix($db);
$allowed_input_types = getAllowedInputTypes($db);
$default_input_type = getDefaultInputType($db);
$require_validation = isValidationRulesRequired($db);
$max_options_count = getMaxOptionsCount($db);
$allow_multiple_categories = allowMultipleCategoryAssignments($db);

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Get all attributes
try {
    $query = "SELECT a.*, 
             u1.username as created_by_name,
             u2.username as updated_by_name
             FROM attribute_definitions a
             LEFT JOIN users u1 ON a.created_by = u1.id
             LEFT JOIN users u2 ON a.updated_by = u2.id
             WHERE a.status = 1
             ORDER BY a.attribute_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching attributes: " . $e->getMessage();
    error_log("Error fetching attributes: " . $e->getMessage());
}

// Get all categories for filter
try {
    $query = "SELECT id, category_name FROM product_categories WHERE status = 1 ORDER BY category_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching categories: " . $e->getMessage();
    error_log("Error fetching categories: " . $e->getMessage());
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
                    <h2>Product Attributes</h2>
                </div>
                <div class="col-md-6 text-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-add-attribute">
                        <i class="fas fa-plus"></i> Add New Attribute
                    </button>
                </div>
            </div>

            <ol class="breadcrumb mb-4">
                <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
                <li class="breadcrumb-item">Inventory</li>
                <li class="breadcrumb-item active">Attributes</li>
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

                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Product Attributes</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-add-attribute">
                                        <i class="fas fa-plus"></i> Add Attribute
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-striped attribute-datatable">
                                    <thead>
                                        <tr>
                                            <th>Attribute Name</th>
                                            <th>Attribute Code</th>
                                            <th>Input Type</th>
                                            <th>Is Required</th>


                                            <th>Description</th>
                                            <th>Created By</th>
                                            <th>Created At</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($attributes as $attribute): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($attribute['attribute_name']); ?></td>
                                            <td><?php echo htmlspecialchars($attribute['attribute_code']); ?></td>
                                            <td><?php echo htmlspecialchars($attribute['input_type']); ?></td>
                                            <td>
                                                <?php if ($attribute['is_required']): ?>
                                                <span class="badge bg-success">Yes</span>
                                                <?php else: ?>
                                                <span class="badge bg-secondary">No</span>
                                                <?php endif; ?>
                                            </td>


                                            <td><?php echo htmlspecialchars($attribute['description'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($attribute['created_by_name'] ?? ''); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($attribute['created_at'])); ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-info edit-attribute-btn" 
                                                            data-id="<?php echo $attribute['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($attribute['attribute_name']); ?>"
                                                            data-code="<?php echo htmlspecialchars($attribute['attribute_code']); ?>"
                                                            data-type="<?php echo htmlspecialchars($attribute['input_type']); ?>"
                                                            data-required="<?php echo $attribute['is_required']; ?>"


                                                            data-description="<?php echo htmlspecialchars($attribute['description'] ?? ''); ?>"
                                                            data-validation="<?php echo htmlspecialchars($attribute['validation_rules'] ?? ''); ?>"
                                                            data-default="<?php echo htmlspecialchars($attribute['default_value'] ?? ''); ?>"
                                                            data-options="<?php echo htmlspecialchars($attribute['options'] ?? ''); ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-success category-attribute-btn"
                                                            data-id="<?php echo $attribute['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($attribute['attribute_name']); ?>">
                                                        <i class="fas fa-link"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger delete-attribute-btn" 
                                                            data-id="<?php echo $attribute['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($attribute['attribute_name']); ?>">
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
        </section>
    </div>

    <!-- Footer -->
    <?php include '../../includes/footer.php'; ?>
</div>

<!-- Add Attribute Modal -->
<div class="modal fade" id="modal-add-attribute">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Add Attribute</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/save_attribute.php" method="post">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="attribute_name">Attribute Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="attribute_name" name="attribute_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="attribute_code">Attribute Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="attribute_code" name="attribute_code" <?php echo $auto_generate_code ? 'readonly' : 'required'; ?> <?php echo $auto_generate_code ? 'placeholder="Will be auto-generated"' : ''; ?>>
                                <small class="form-text text-muted">
                                    <?php if ($auto_generate_code): ?>
                                        Code will be auto-generated with prefix: <?php echo htmlspecialchars($attribute_code_prefix); ?>
                                    <?php else: ?>
                                        Use only letters, numbers, and underscores. No spaces.
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="input_type">Input Type <span class="text-danger">*</span></label>
                                <select class="form-control" id="input_type" name="input_type" required>
                                    <?php foreach ($allowed_input_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($type === $default_input_type) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="default_value">Default Value</label>
                                <input type="text" class="form-control" id="default_value" name="default_value">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group" id="options_container" style="display: none;">
                                <label for="options">Options (one per line)</label>
                                <textarea class="form-control" id="options" name="options" rows="4" placeholder="Red&#10;Green&#10;Blue"></textarea>
                                <small class="form-text text-muted">For select and multiselect types. Enter one option per line. Maximum <?php echo $max_options_count; ?> options allowed.</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="validation_rules">Validation Rules <?php echo $require_validation ? '<span class="text-danger">*</span>' : ''; ?></label>
                                <input type="text" class="form-control" id="validation_rules" name="validation_rules" placeholder="min:1|max:100" <?php echo $require_validation ? 'required' : ''; ?>>
                                <small class="form-text text-muted">Format: rule1:value1|rule2:value2 (e.g., min:1|max:100)</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="is_required" name="is_required" value="1">
                                    <label class="custom-control-label" for="is_required">Is Required</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">

                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">

                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Attribute</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Attribute Modal -->
<div class="modal fade" id="modal-edit-attribute">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Edit Attribute</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/update_attribute.php" method="post">
                <input type="hidden" name="attribute_id" id="edit_attribute_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_attribute_name">Attribute Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_attribute_name" name="attribute_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_attribute_code">Attribute Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_attribute_code" name="attribute_code" required readonly>
                                <small class="form-text text-muted">Attribute code cannot be changed after creation.</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_input_type">Input Type <span class="text-danger">*</span></label>
                                <select class="form-control" id="edit_input_type" name="input_type" required>
                                    <?php foreach ($allowed_input_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_default_value">Default Value</label>
                                <input type="text" class="form-control" id="edit_default_value" name="default_value">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="edit_description">Description</label>
                                <textarea class="form-control" id="edit_description" name="description" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group" id="edit_options_container">
                                <label for="edit_options">Options (one per line)</label>
                                <textarea class="form-control" id="edit_options" name="options" rows="4" placeholder="Red&#10;Green&#10;Blue"></textarea>
                                <small class="form-text text-muted">For select and multiselect types. Enter one option per line. Maximum <?php echo $max_options_count; ?> options allowed.</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="edit_validation_rules">Validation Rules <?php echo $require_validation ? '<span class="text-danger">*</span>' : ''; ?></label>
                                <input type="text" class="form-control" id="edit_validation_rules" name="validation_rules" placeholder="min:1|max:100" <?php echo $require_validation ? 'required' : ''; ?>>
                                <small class="form-text text-muted">Format: rule1:value1|rule2:value2 (e.g., min:1|max:100)</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="edit_is_required" name="is_required" value="1">
                                    <label class="custom-control-label" for="edit_is_required">Is Required</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">

                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">

                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Attribute</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Category Attribute Modal -->
<div class="modal fade" id="modal-category-attribute">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h4 class="modal-title"><i class="fas fa-link me-2"></i>Assign Attribute to Categories</h4>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/save_category_attribute.php" method="post">
                <input type="hidden" name="attribute_id" id="category_attribute_id">
                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        You are assigning <strong id="category_attribute_name" class="text-primary"></strong> to product categories.
                        This will make the attribute available for products in the selected categories.
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <i class="fas fa-tags me-2"></i>Select Categories
                        </div>
                        <div class="card-body">
                            <select class="form-select" name="category_ids[]" id="category_ids" multiple data-placeholder="Select categories">
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text mt-2">
                                <?php if ($allow_multiple_categories): ?>
                                <i class="fas fa-keyboard me-1"></i> You can select multiple categories by holding Ctrl/Cmd while clicking
                                <?php else: ?>
                                <i class="fas fa-exclamation-triangle text-warning me-1"></i> <strong>Note:</strong> Multiple category assignments are disabled in settings. Only the first selected category will be used.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header bg-light">
                            <i class="fas fa-cog me-2"></i>Options
                        </div>
                        <div class="card-body">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="category_is_required" name="is_required" value="1">
                                <label class="form-check-label" for="category_is_required">
                                    <strong>Required attribute</strong> - Products in these categories must have this attribute
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Attribute Modal -->
<div class="modal fade" id="modal-delete-attribute">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Delete Attribute</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/delete_attribute.php" method="post">
                <input type="hidden" name="attribute_id" id="delete_attribute_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete the attribute: <strong id="delete_attribute_name"></strong>?</p>
                    <div class="alert alert-warning">
                        <i class="icon fas fa-exclamation-triangle"></i> This will remove the attribute from all products and categories.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Attribute</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables is already loaded in the header -->
<!-- Add Select2 from CDN with Bootstrap 5 theme -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(function () {
    // Use a unique selector to avoid conflicts with global initialization
    if ($.fn.DataTable.isDataTable('.attribute-datatable')) {
        $('.attribute-datatable').DataTable().destroy();
    }
    
    $('.attribute-datatable').DataTable({
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "responsive": true
    });
    
    // Fix for ESC key to close all modals
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            // Check which modal is open and close it
            const modals = [
                '#modal-add-attribute',
                '#modal-edit-attribute',
                '#modal-category-attribute',
                '#modal-delete-attribute'
            ];
            
            modals.forEach(function(modalId) {
                if ($(modalId).hasClass('show')) {
                    const modalInstance = bootstrap.Modal.getInstance(document.querySelector(modalId));
                    if (modalInstance) {
                        modalInstance.hide();
                    }
                }
            });
        }
    });
    
    // Initialize Select2 elements
    $('.select2').select2({
        theme: 'bootstrap-5'
    });
    
    // Initialize category select in modal with proper Bootstrap 5 styling
    $('#category_ids').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('#modal-category-attribute .modal-body'),
        placeholder: 'Select categories',
        allowClear: true
    });
    
    // Reinitialize Select2 when category attribute modal is shown
    $('#modal-category-attribute').on('shown.bs.modal', function() {
        $('#category_ids').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#modal-category-attribute .modal-body'),
            placeholder: 'Select categories',
            allowClear: true
        });
    });
    
    // Show/hide options based on input type
    function toggleOptionsField(inputType, isEdit) {
        var prefix = isEdit ? 'edit_' : '';
        var optionsContainer = $('#' + prefix + 'options_container');
        
        if (inputType === 'select' || inputType === 'multiselect') {
            optionsContainer.show();
        } else {
            optionsContainer.hide();
        }
        
        // Update default value field based on input type
        var defaultValueField = $('#' + prefix + 'default_value');
        
        if (inputType === 'number') {
            defaultValueField.attr('type', 'number');
            defaultValueField.attr('step', 'any');
        } else if (inputType === 'date') {
            defaultValueField.attr('type', 'date');
        } else if (inputType === 'boolean') {
            // For boolean, use two radio buttons for Yes/No
            var currentValue = defaultValueField.val();
            var radioHtml = '<div class="form-check form-check-inline mt-2">' +
                            '<input type="radio" class="form-check-input" id="' + prefix + 'default_value_yes" ' + 
                            'name="' + prefix + 'default_radio" ' + (currentValue === '1' ? 'checked' : '') + '>' +
                            '<label class="form-check-label" for="' + prefix + 'default_value_yes">Yes</label>' +
                            '</div>' +
                            '<div class="form-check form-check-inline">' +
                            '<input type="radio" class="form-check-input" id="' + prefix + 'default_value_no" ' + 
                            'name="' + prefix + 'default_radio" ' + (currentValue === '0' ? 'checked' : '') + '>' +
                            '<label class="form-check-label" for="' + prefix + 'default_value_no">No</label>' +
                            '</div>' +
                            '<input type="hidden" id="' + prefix + 'default_value" name="default_value" value="' + (currentValue || '0') + '">';
            defaultValueField.replaceWith(radioHtml);
            
            // Add event listeners to update the hidden field
            $('#' + prefix + 'default_value_yes').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#' + prefix + 'default_value').val('1');
                }
            });
            
            $('#' + prefix + 'default_value_no').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#' + prefix + 'default_value').val('0');
                }
            });
        } else {
            // Reset to text input if it was changed
            if (defaultValueField.prop('tagName').toLowerCase() !== 'input' || 
                defaultValueField.attr('type') !== 'text') {
                var currentValue = defaultValueField.val() || '';
                var textInput = '<input type="text" class="form-control" id="' + prefix + 'default_value" name="default_value" value="' + currentValue + '">';
                defaultValueField.replaceWith(textInput);
            }
        }
    }
    
    // Input type change handler
    $('#input_type').change(function() {
        toggleOptionsField($(this).val(), false);
    });
    
    $('#edit_input_type').change(function() {
        toggleOptionsField($(this).val(), true);
    });
    
    // Initialize the options field based on the current input type
    $(document).ready(function() {
        toggleOptionsField($('#input_type').val(), false);
        
        // Only initialize edit fields if we're editing
        if ($('#edit_input_type').length) {
            toggleOptionsField($('#edit_input_type').val(), true);
        }
        
        // Add validation for number fields
        $(document).on('input', 'input[type="number"]', function() {
            // Ensure the value is a valid number
            if ($(this).val() && isNaN(parseFloat($(this).val()))) {
                $(this).val('');
            }
        });
    });
    
    // Edit attribute button click
    $('.edit-attribute-btn').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var code = $(this).data('code');
        var type = $(this).data('type');
        var required = $(this).data('required');


        var description = $(this).data('description');
        var validation = $(this).data('validation');
        var defaultValue = $(this).data('default');
        var options = $(this).data('options');
        
        $('#edit_attribute_id').val(id);
        $('#edit_attribute_name').val(name);
        $('#edit_attribute_code').val(code);
        $('#edit_input_type').val(type);
        $('#edit_is_required').prop('checked', required == 1);


        $('#edit_description').val(description);
        $('#edit_validation_rules').val(validation);
        
        // First set the input type, which will update the default value field type
        toggleOptionsField(type, true);
        
        // Then set the default value after the field has been properly configured
        setTimeout(function() {
            if (type === 'boolean') {
                // For boolean, select the appropriate radio button
                if (defaultValue === '1') {
                    $('#edit_default_value_yes').prop('checked', true);
                } else {
                    $('#edit_default_value_no').prop('checked', true);
                }
                $('#edit_default_value').val(defaultValue === '1' ? '1' : '0');
            } else {
                $('#edit_default_value').val(defaultValue);
            }
        }, 100);
        
        // Set options - convert from JSON format to line-by-line format
        try {
            if (options) {
                var optionsObj;
                
                // Check if options is already an object (jQuery data() automatically parses JSON)
                if (typeof options === 'object') {
                    optionsObj = options;
                } else if (typeof options === 'string') {
                    optionsObj = JSON.parse(options);
                } else {
                    // Unexpected type, use empty array as fallback
                    optionsObj = [];
                }
                
                var optionsText = '';
                
                // Check if options is in the new format (array of objects with value/label)
                if (Array.isArray(optionsObj) && optionsObj.length > 0 && optionsObj[0] && optionsObj[0].hasOwnProperty('label')) {
                    // New format - extract labels only
                    optionsText = optionsObj.map(function(opt) {
                        return opt.label;
                    }).join('\n');
                } else if (Array.isArray(optionsObj)) {
                    // Old format - simple array of strings
                    optionsText = optionsObj.join('\n');
                }
                
                $('#edit_options').val(optionsText);
            } else {
                $('#edit_options').val('');
            }
        } catch (e) {
            console.error('Error processing options:', e);
            // Fallback to original value if processing fails
            $('#edit_options').val(typeof options === 'string' ? options : '');
        }
        
        var editModal = new bootstrap.Modal(document.getElementById('modal-edit-attribute'));
        editModal.show();
    });
    
    // Category attribute button click
    $('.category-attribute-btn').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        
        $('#category_attribute_id').val(id);
        $('#category_attribute_name').text(name);
        
        // Load existing category assignments
        $.ajax({
            url: 'actions/get_attribute_categories.php',
            type: 'GET',
            data: { attribute_id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Clear previous selections
                    $('#category_ids').val(null).trigger('change');
                    
                    // Set selected categories
                    if (response.categories.length > 0) {
                        var categoryIds = response.categories.map(function(item) {
                            return item.category_id;
                        });
                        $('#category_ids').val(categoryIds).trigger('change');
                        
                        // Check if required for any category
                        var isRequired = response.categories.some(function(item) {
                            return item.is_required == 1;
                        });
                        $('#category_is_required').prop('checked', isRequired);
                    }
                }
            }
        });
        
        var categoryModal = new bootstrap.Modal(document.getElementById('modal-category-attribute'));
        categoryModal.show();
    });
    
    // Delete attribute button click
    $('.delete-attribute-btn').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        
        $('#delete_attribute_id').val(id);
        $('#delete_attribute_name').text(name);
        
        var deleteModal = new bootstrap.Modal(document.getElementById('modal-delete-attribute'));
        deleteModal.show();
    });
    
    // Initial toggle for options field
    toggleOptionsField($('#input_type').val(), false);
});
</script>

        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#attributes-table').DataTable({
        responsive: true
    });
});
</script>
