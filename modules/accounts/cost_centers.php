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

try {

    // Get parent cost centers for dropdown
    $parent_centers_query = "SELECT id, code, name FROM cost_centers WHERE status = 1 ORDER BY code";
    $stmt = $conn->prepare($parent_centers_query);
    $stmt->execute();
    $parent_centers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching cost centers: " . $e->getMessage());
    $cost_centers = [];
    $parent_centers = [];
}

$pageTitle = "Cost Centers";
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary"><?php echo $pageTitle; ?></h6>
                    <div>
                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_add'])): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCostCenterModal">
                            <i class="fas fa-plus"></i> New Cost Center
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="costCentersTable" width="100%">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Parent</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Data will be loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Cost Center Modal -->
<div class="modal fade" id="newCostCenterModal" tabindex="-1" aria-labelledby="newCostCenterModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newCostCenterModalLabel">New Cost Center</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="newCostCenterForm" action="actions/save_cost_center.php" method="post">
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="newCostCenterError"></div>
                    <div class="mb-3">
                        <label for="code" class="form-label">Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="code" name="code" required>
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="parent_id" class="form-label">Parent Cost Center</label>
                        <select class="form-select" id="parent_id" name="parent_id">
                            <option value="">None</option>
                            <?php foreach ($parent_centers as $parent): ?>
                            <option value="<?php echo $parent['id']; ?>">
                                <?php echo htmlspecialchars($parent['code'] . ' - ' . $parent['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="status" name="status" value="1" checked>
                            <label class="form-check-label" for="status">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Cost Center Modal -->
<div class="modal fade" id="viewCostCenterModal" tabindex="-1" aria-labelledby="viewCostCenterModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewCostCenterModalLabel">Cost Center Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <p><strong>Code:</strong> <span id="view-code"></span></p>
                        <p><strong>Name:</strong> <span id="view-name"></span></p>
                        <p><strong>Parent:</strong> <span id="view-parent"></span></p>
                        <p><strong>Status:</strong> <span id="view-status"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Created By:</strong> <span id="view-created-by"></span></p>
                        <p><strong>Created At:</strong> <span id="view-created-at"></span></p>
                        <p><strong>Updated By:</strong> <span id="view-updated-by"></span></p>
                        <p><strong>Updated At:</strong> <span id="view-updated-at"></span></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <p><strong>Description:</strong></p>
                        <div class="border p-2 bg-light" id="view-description"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Cost Center Modal -->
<div class="modal fade" id="editCostCenterModal" tabindex="-1" aria-labelledby="editCostCenterModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editCostCenterModalLabel">Edit Cost Center</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editCostCenterForm" action="actions/update_cost_center.php" method="post">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="editCostCenterError"></div>
                    <div class="mb-3">
                        <label for="edit_code" class="form-label">Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_code" name="code" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_parent_id" class="form-label">Parent Cost Center</label>
                        <select class="form-select" id="edit_parent_id" name="parent_id">
                            <option value="">None</option>
                            <?php foreach ($parent_centers as $parent): ?>
                            <option value="<?php echo $parent['id']; ?>">
                                <?php echo htmlspecialchars($parent['code'] . ' - ' . $parent['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="edit_status" name="status" value="1">
                            <label class="form-check-label" for="edit_status">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
// Function to show success message with Bootstrap toast
function showSuccess(message) {
    // Create toast container if it doesn't exist
    if ($('#toastContainer').length === 0) {
        $('body').append(`
            <div id="toastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
                <div id="successToast" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body"></div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            </div>
        `);
    }
    
    // Set message and show toast
    $('#successToast .toast-body').text(message);
    var toast = new bootstrap.Toast(document.getElementById('successToast'), {
        delay: 3000
    });
    toast.show();
}

$(document).ready(function() {
    // Initialize DataTable with proper configuration
    const costCentersTable = $('#costCentersTable').DataTable({
        processing: true,
        serverSide: false,
        ajax: {
            url: 'actions/get_cost_centers.php',
            type: 'GET',
            dataSrc: function(json) {
                if (json.success) {
                    return json.data;
                } else {
                    alert('Error loading cost centers: ' + json.message);
                    return [];
                }
            }
        },
        columns: [
            { data: 'code' },
            { data: 'name' },
            { data: 'parent' },
            { data: 'description' },
            { data: 'status', className: 'text-center' },
            { data: 'actions', className: 'text-center', orderable: false }
        ],
        pageLength: 25,
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            'copy', 'excel', 'csv', 'pdf', 'print'
        ],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search cost centers...",
            lengthMenu: "_MENU_ cost centers per page",
            info: "Showing _START_ to _END_ of _TOTAL_ cost centers",
            infoEmpty: "No cost centers found",
            infoFiltered: "(filtered from _MAX_ total cost centers)"
        },
        order: [[0, 'asc']], // Sort by code asc
        drawCallback: function(settings) {
            // Re-initialize tooltips after table draw
            $('[data-bs-toggle="tooltip"]').tooltip();
        }
    });

    // View Cost Center - use event delegation for dynamically loaded elements
    $(document).on('click', '.view-cost-center', function() {
        const id = $(this).data('id');
        
        $.ajax({
            url: 'actions/get_cost_center.php',
            type: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const center = response.data;
                    
                    $('#view-code').text(center.code);
                    $('#view-name').text(center.name);
                    $('#view-parent').text(center.parent_name || 'None');
                    $('#view-description').text(center.description || 'No description');
                    $('#view-status').html(
                        `<span class="badge bg-${center.status ? 'success' : 'danger'}">
                            ${center.status ? 'Active' : 'Inactive'}
                        </span>`
                    );
                    $('#view-created-by').text(center.created_by_name);
                    $('#view-created-at').text(new Date(center.created_at).toLocaleString());
                    $('#view-updated-by').text(center.updated_by_name || 'N/A');
                    $('#view-updated-at').text(center.updated_at ? new Date(center.updated_at).toLocaleString() : 'N/A');
                    
                    var viewCostCenterModal = new bootstrap.Modal(document.getElementById('viewCostCenterModal'));
                    viewCostCenterModal.show();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function() {
                alert('An error occurred while fetching cost center details');
            }
        });
    });

    // Edit Cost Center - use event delegation for dynamically loaded elements
    $(document).on('click', '.edit-cost-center', function() {
        const id = $(this).data('id');
        
        $.ajax({
            url: 'actions/get_cost_center.php',
            type: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const center = response.data;
                    
                    $('#edit_id').val(center.id);
                    $('#edit_code').val(center.code);
                    $('#edit_name').val(center.name);
                    $('#edit_parent_id').val(center.parent_id || '');
                    $('#edit_description').val(center.description || '');
                    $('#edit_status').prop('checked', center.status == 1);
                    
                    var editCostCenterModal = new bootstrap.Modal(document.getElementById('editCostCenterModal'));
                    editCostCenterModal.show();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function() {
                alert('An error occurred while fetching cost center details');
            }
        });
    });

    // Delete Cost Center - use event delegation for dynamically loaded elements
    $(document).on('click', '.delete-cost-center', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        
        if (confirm(`Are you sure you want to delete the cost center "${name}"?`)) {
            $.ajax({
                url: 'actions/delete_cost_center.php',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        costCentersTable.ajax.reload();
                        showSuccess('Cost center deleted successfully');
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('An error occurred while deleting the cost center');
                }
            });
        }
    });

    // Form Validation and Submission
    $('#newCostCenterForm').submit(function(e) {
        e.preventDefault();
        
        $.ajax({
            url: $(this).attr('action'),
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#newCostCenterModal').modal('hide');
                    $('#newCostCenterForm')[0].reset();
                    $('#newCostCenterError').addClass('d-none').text('');
                    costCentersTable.ajax.reload();
                    showSuccess('Cost center saved successfully');
                } else {
                    $('#newCostCenterError').removeClass('d-none').text(response.message);
                }
            },
            error: function() {
                $('#newCostCenterError').removeClass('d-none').text('An error occurred while saving the cost center');
            }
        });
    });

    $('#editCostCenterForm').submit(function(e) {
        e.preventDefault();
        
        $.ajax({
            url: $(this).attr('action'),
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#editCostCenterModal').modal('hide');
                    $('#editCostCenterError').addClass('d-none').text('');
                    costCentersTable.ajax.reload();
                    showSuccess('Cost center updated successfully');
                } else {
                    $('#editCostCenterError').removeClass('d-none').text(response.message);
                }
            },
            error: function() {
                $('#editCostCenterError').removeClass('d-none').text('An error occurred while updating the cost center');
            }
        });
    });
});
</script>
