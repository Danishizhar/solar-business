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
require_once 'includes/location_settings_helper.php';

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

// Check if multi-locations is enabled
$multi_locations_enabled = isMultiLocationsEnabled($db);

// Get all location settings
$multi_locations_enabled = isMultiLocationsEnabled($db);
$auto_generate_code = isAutoGenerateLocationCodeEnabled($db);
$location_code_prefix = getLocationCodePrefix($db);
$default_location_type = getDefaultLocationType($db);
$branch_required = isBranchRequiredForLocations($db);
$hierarchical_allowed = areHierarchicalLocationsAllowed($db);
$max_hierarchy_depth = getMaxHierarchyDepth($db);
$location_types = getLocationTypes($db);

// Get all branches for dropdown
try {
    $query = "SELECT id, branch_name FROM branches WHERE status = 1 ORDER BY branch_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching branches: " . $e->getMessage();
    error_log("Error fetching branches: " . $e->getMessage());
}

// Get all locations
try {
    $query = "SELECT l.*, 
             p.location_name as parent_location_name,
             b.branch_name,
             (SELECT COUNT(*) FROM inventory_stock WHERE location_id = l.id AND status = 1) as stock_count,
             u1.username as created_by_name,
             u2.username as updated_by_name
             FROM inventory_locations l
             LEFT JOIN inventory_locations p ON l.parent_id = p.id
             LEFT JOIN branches b ON l.branch_id = b.id
             LEFT JOIN users u1 ON l.created_by = u1.id
             LEFT JOIN users u2 ON l.updated_by = u2.id
             WHERE l.status = 1
             ORDER BY l.is_default DESC, l.location_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching locations: " . $e->getMessage();
    error_log("Error fetching inventory locations: " . $e->getMessage());
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
                    <h2>Inventory Locations</h2>
                </div>
                <div class="col-md-6 text-end">
                    <?php if ($multi_locations_enabled || empty($locations)): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLocationModal">
                        <i class="fas fa-plus"></i> Add Location
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary" disabled title="Multi-locations is disabled in settings">
                        <i class="fas fa-plus"></i> Add Location
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!$multi_locations_enabled): ?>
                <?php if (!empty($locations)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Multi-locations is disabled!</strong> You cannot add new locations until you enable multi-locations in 
                    <a href="settings.php" class="alert-link">Inventory Settings</a> under the Locations tab.
                    <?php if (count($locations) > 1): ?>
                    Existing locations will continue to work, but you cannot add new ones.
                    <?php else: ?>
                    You can only use one location while multi-locations is disabled.
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Multi-locations is disabled.</strong> You can add one default location. To add more locations, enable multi-locations in 
                    <a href="settings.php" class="alert-link">Inventory Settings</a> under the Locations tab.
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <!-- Locations Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Location Name</th>
                                    <th>Location Code</th>
                                    <th>Branch</th>
                                    <th>Parent Location</th>
                                    <th>Type</th>
                                    <th>Default</th>
                                    <th>Stock Items</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($locations)): ?>
                                <tr>
                                    <td colspan="8" class="text-center">No locations found</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($locations as $location): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($location['location_name']); ?></td>
                                    <td><?php echo htmlspecialchars($location['location_code']); ?></td>
                                    <td><?php echo htmlspecialchars($location['branch_name'] ?? 'None'); ?></td>
                                    <td><?php echo htmlspecialchars($location['parent_location_name'] ?? 'None'); ?></td>
                                    <td><?php echo htmlspecialchars($location['location_type']); ?></td>
                                    <td>
                                        <?php if ($location['is_default'] == 1): ?>
                                        <span class="badge bg-success">Default</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int)$location['stock_count']; ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-primary edit-location" 
                                                    data-id="<?php echo $location['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($location['location_name']); ?>"
                                                    data-code="<?php echo htmlspecialchars($location['location_code']); ?>"
                                                    data-branch="<?php echo $location['branch_id'] ?? ''; ?>"
                                                    data-parent="<?php echo $location['parent_id'] ?? ''; ?>"
                                                    data-type="<?php echo htmlspecialchars($location['location_type']); ?>"
                                                    data-description="<?php echo htmlspecialchars($location['description'] ?? ''); ?>"
                                                    data-default="<?php echo $location['is_default']; ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editLocationModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger delete-location" 
                                                    data-id="<?php echo $location['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($location['location_name']); ?>"
                                                    data-stock-count="<?php echo (int)$location['stock_count']; ?>"
                                                    data-is-default="<?php echo $location['is_default']; ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteLocationModal">
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

<!-- Add Location Modal -->
<div class="modal fade" id="addLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Location</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/save_location.php" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="location_name" class="form-label">Location Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="location_name" name="location_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="location_code" class="form-label">Location Code <?php echo !$auto_generate_code ? '<span class="text-danger">*</span>' : ''; ?></label>
                        <input type="text" class="form-control" id="location_code" name="location_code" <?php echo !$auto_generate_code ? 'required' : ''; ?>>
                        <div class="form-text">
                            <?php if ($auto_generate_code): ?>
                            Leave empty for auto-generation with prefix: <?php echo htmlspecialchars($location_code_prefix); ?>
                            <?php else: ?>
                            Auto-generation is disabled. You must enter a location code.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="branch_id" class="form-label">Branch <?php echo $branch_required ? '<span class="text-danger">*</span>' : ''; ?></label>
                        <select class="form-select" id="branch_id" name="branch_id" <?php echo $branch_required ? 'required' : ''; ?>>
                            <option value="">Select Branch</option>
                            <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <?php if ($branch_required): ?>
                            Branch is required for all locations as per settings
                            <?php else: ?>
                            Select a branch to associate with this location
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($hierarchical_allowed): ?>
                    <div class="mb-3">
                        <label for="parent_id" class="form-label">Parent Location</label>
                        <select class="form-select" id="parent_id" name="parent_id">
                            <option value="">None (Top Level)</option>
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['location_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($max_hierarchy_depth > 0): ?>
                        <div class="form-text">Maximum hierarchy depth: <?php echo $max_hierarchy_depth; ?> levels</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="location_type" class="form-label">Location Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="location_type" name="location_type" required>
                            <?php foreach ($location_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($type == $default_location_type) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1">
                        <label class="form-check-label" for="is_default">Set as Default Location</label>
                        <div class="form-text">If checked, this will become the default location for inventory operations</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Location Modal -->
<div class="modal fade" id="editLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Location</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/update_location.php" method="POST">
                <input type="hidden" name="location_id" id="edit_location_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_location_name" class="form-label">Location Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_location_name" name="location_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_location_code" class="form-label">Location Code</label>
                        <input type="text" class="form-control" id="edit_location_code" name="location_code" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="edit_branch_id" class="form-label">Branch <?php echo $branch_required ? '<span class="text-danger">*</span>' : ''; ?></label>
                        <select class="form-select" id="edit_branch_id" name="branch_id" <?php echo $branch_required ? 'required' : ''; ?>>
                            <option value="">Select Branch</option>
                            <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <?php if ($branch_required): ?>
                            Branch is required for all locations as per settings
                            <?php else: ?>
                            Select a branch to associate with this location
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($hierarchical_allowed): ?>
                    <div class="mb-3">
                        <label for="edit_parent_id" class="form-label">Parent Location</label>
                        <select class="form-select" id="edit_parent_id" name="parent_id">
                            <option value="">None (Top Level)</option>
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['location_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($max_hierarchy_depth > 0): ?>
                        <div class="form-text">Maximum hierarchy depth: <?php echo $max_hierarchy_depth; ?> levels</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="edit_location_type" class="form-label">Location Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_location_type" name="location_type" required>
                            <?php foreach ($location_types as $type): ?>
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
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="edit_is_default" name="is_default" value="1">
                        <label class="form-check-label" for="edit_is_default">Set as Default Location</label>
                        <div class="form-text">If checked, this will become the default location for inventory operations</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Location Modal -->
<div class="modal fade" id="deleteLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Location</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/delete_location.php" method="POST">
                <input type="hidden" name="location_id" id="delete_location_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete the location: <strong id="delete_location_name"></strong>?</p>
                    <div id="location_has_stock" class="alert alert-warning d-none">
                        This location has <span id="stock_count"></span> stock items associated with it. 
                        Deleting this location will remove the location association from these items.
                    </div>
                    <div id="location_is_default" class="alert alert-danger d-none">
                        This is the default location. You cannot delete the default location.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="delete_location_btn">Delete Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
    $(document).ready(function() {
        // Edit location button click
        $('.edit-location').click(function() {
            var id = $(this).data('id');
            var name = $(this).data('name');
            var code = $(this).data('code');
            var branch = $(this).data('branch');
            var parent = $(this).data('parent');
            var type = $(this).data('type');
            var description = $(this).data('description');
            var isDefault = $(this).data('default') == 1;
            
            $('#edit_location_id').val(id);
            $('#edit_location_name').val(name);
            $('#edit_location_code').val(code);
            $('#edit_branch_id').val(branch);
            $('#edit_location_type').val(type);
            $('#edit_description').val(description);
            $('#edit_is_default').prop('checked', isDefault);
            
            // Disable selecting self as parent
            $('#edit_parent_id option').prop('disabled', false);
            $('#edit_parent_id option[value="' + id + '"]').prop('disabled', true);
            $('#edit_parent_id').val(parent);
        });
        
        // Delete location button click
        $('.delete-location').click(function() {
            var id = $(this).data('id');
            var name = $(this).data('name');
            var stockCount = $(this).data('stock-count');
            var isDefault = $(this).data('is-default') == 1;
            
            $('#delete_location_id').val(id);
            $('#delete_location_name').text(name);
            
            if (stockCount > 0) {
                $('#location_has_stock').removeClass('d-none');
                $('#stock_count').text(stockCount);
            } else {
                $('#location_has_stock').addClass('d-none');
            }
            
            if (isDefault) {
                $('#location_is_default').removeClass('d-none');
                $('#delete_location_btn').prop('disabled', true);
            } else {
                $('#location_is_default').addClass('d-none');
                $('#delete_location_btn').prop('disabled', false);
            }
        });
    });
</script>
