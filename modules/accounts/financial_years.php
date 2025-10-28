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
    // Get all financial years
    $years_query = "
        SELECT 
            fy.*,
            c.username as created_by_name,
            u.username as updated_by_name,
            cl.username as closed_by_name
        FROM financial_years fy
        LEFT JOIN users c ON fy.created_by = c.id
        LEFT JOIN users u ON fy.updated_by = u.id
        LEFT JOIN users cl ON fy.closed_by = cl.id
        WHERE fy.status = 1
        ORDER BY fy.start_date DESC";

    $stmt = $conn->prepare($years_query);
    $stmt->execute();
    $financial_years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in financial_years.php: " . $e->getMessage());
    echo "<div class='alert alert-danger'>An error occurred while loading financial years. Please try again later.</div>";
    $financial_years = [];
}

$pageTitle = "Financial Years";
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
                    <div>
                        <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_add'])): ?>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newFinancialYearModal">
                            <i class="fas fa-plus"></i> New Financial Year
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="financialYearsTable">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Financial Year Modal -->
<div class="modal fade" id="newFinancialYearModal" tabindex="-1" aria-labelledby="newFinancialYearModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newFinancialYearModalLabel">New Financial Year</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="newFinancialYearForm" action="actions/save_financial_year.php" method="post">
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="newFinancialYearError"></div>
                    <div class="mb-3">
                        <label for="year_code" class="form-label">Year Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="year_code" name="year_code" required>
                        <small class="form-text text-muted">Example: FY2025</small>
                    </div>
                    <div class="mb-3">
                        <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="start_date" name="start_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="end_date" name="end_date" required>
                    </div>
                    <!-- Status option removed as requested -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Financial Year Modal -->
<div class="modal fade" id="viewFinancialYearModal" tabindex="-1" aria-labelledby="viewFinancialYearModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewFinancialYearModalLabel">Financial Year Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <p><strong>Year Code:</strong> <span id="view-year-code"></span></p>
                        <p><strong>Start Date:</strong> <span id="view-start-date"></span></p>
                        <p><strong>End Date:</strong> <span id="view-end-date"></span></p>
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
                    <div class="col-md-6">
                        <p><strong>Closed By:</strong> <span id="view-closed-by"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Closed At:</strong> <span id="view-closed-at"></span></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Financial Year Modal -->
<div class="modal fade" id="editFinancialYearModal" tabindex="-1" aria-labelledby="editFinancialYearModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editFinancialYearModalLabel">Edit Financial Year</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editFinancialYearForm" action="actions/update_financial_year.php" method="post">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="editFinancialYearError"></div>
                    <div class="mb-3">
                        <label for="edit_year_code" class="form-label">Year Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_year_code" name="year_code" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="edit_start_date" name="start_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="edit_end_date" name="end_date" required>
                    </div>
                    <!-- Status option removed as requested -->
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
$(document).ready(function() {
    // Initialize DataTable
    var table = $('#financialYearsTable').DataTable({
        processing: true,
        ajax: {
            url: 'actions/get_financial_years.php',
            type: 'POST',
            dataSrc: 'data'
        },
        columns: [
            { data: 'year_code' },
            { data: 'start_date' },
            { data: 'end_date' },
            {
                data: 'status',
                render: function(data) {
                    var statusClass = {
                        'open': 'bg-success',
                        'closed': 'bg-danger',
                        'pending': 'bg-warning'
                    };
                    return '<span class="badge ' + (statusClass[data] || 'bg-secondary') + '">' + 
                           data.charAt(0).toUpperCase() + data.slice(1) + '</span>';
                }
            },
            {
                data: null,
                render: function(data, type, row) {
                    var buttons = '';
                    
                    // View button
                    if (data.can_view) {
                        buttons += '<button type="button" class="btn btn-sm btn-info view-year me-1" ' +
                                 'data-id="' + data.id + '" data-bs-toggle="modal" data-bs-target="#viewFinancialYearModal">' +
                                 '<i class="fas fa-eye"></i></button>';
                    }
                    
                    // Edit button
                    if (data.can_edit && data.status === 'open') {
                        buttons += '<button type="button" class="btn btn-sm btn-primary edit-year me-1" ' +
                                 'data-id="' + data.id + '" data-bs-toggle="modal" data-bs-target="#editFinancialYearModal">' +
                                 '<i class="fas fa-edit"></i></button>';
                    }
                    
                    // Delete button
                    if (data.can_delete && data.status === 'open') {
                        buttons += '<button type="button" class="btn btn-sm btn-danger delete-year me-1" ' +
                                 'data-id="' + data.id + '" data-code="' + data.year_code + '">' +
                                 '<i class="fas fa-trash"></i></button>';
                    }
                    
                    // Close button
                    if (data.can_close && data.status === 'open') {
                        buttons += '<button type="button" class="btn btn-sm btn-warning close-year" ' +
                                 'data-id="' + data.id + '" data-code="' + data.year_code + '">' +
                                 '<i class="fas fa-lock"></i></button>';
                    }
                    
                    return buttons;
                }
            }
        ],
        order: [[1, 'desc']],
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

    // Handle New Financial Year Form Submit
    $('#newFinancialYearForm').submit(function(e) {
        e.preventDefault();
        
        var formData = $(this).serializeArray();
        
        $.ajax({
            url: 'actions/save_financial_year.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Hide modal
                    var newFinancialYearModal = bootstrap.Modal.getInstance(document.getElementById('newFinancialYearModal'));
                    if (newFinancialYearModal) newFinancialYearModal.hide();
                    
                    // Show success message
                    showSuccess('Financial year created successfully');
                    
                    // Reload table
                    table.ajax.reload();
                } else {
                    showError(response.message || 'Error creating financial year');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                showError('Error creating financial year. Please try again.');
            }
        });
    });

    // Handle Edit Financial Year Form Submit
    $('#editFinancialYearForm').submit(function(e) {
        e.preventDefault();
        
        var formData = $(this).serializeArray();
        
        $.ajax({
            url: 'actions/update_financial_year.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Hide modal
                    var editFinancialYearModal = bootstrap.Modal.getInstance(document.getElementById('editFinancialYearModal'));
                    if (editFinancialYearModal) editFinancialYearModal.hide();
                    
                    // Show success message
                    showSuccess('Financial year updated successfully');
                    
                    // Reload table
                    table.ajax.reload();
                } else {
                    showError(response.message || 'Error updating financial year');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                showError('Error updating financial year. Please try again.');
            }
        });
    });

    // Handle Delete Financial Year
    $(document).on('click', '.delete-year', function() {
        var id = $(this).data('id');
        var code = $(this).data('code');
        
        if (confirm('Are you sure you want to delete financial year ' + code + '?')) {
            $.ajax({
                url: 'actions/update_financial_year_status.php',
                type: 'POST',
                data: { 
                    id: id,
                    action: 'delete'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        showSuccess('Financial year deleted successfully');
                        
                        // Reload table
                        table.ajax.reload();
                    } else {
                        showError(response.message || 'Error deleting financial year');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    showError('Error deleting financial year. Please try again.');
                }
            });
        }
    });

    // Handle Edit Financial Year
    $(document).on('click', '.edit-year', function() {
        var id = $(this).data('id');
        
        $.ajax({
            url: 'actions/get_financial_year.php',
            type: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var year = response.data;
                    
                    // Populate edit form with financial year details
                    $('#edit_id').val(year.id);
                    $('#edit_year_code').val(year.year_code || '');
                    $('#edit_start_date').val(year.start_date || '');
                    $('#edit_end_date').val(year.end_date || '');
                } else {
                    showError(response.message || 'Error retrieving financial year details');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                showError('Error retrieving financial year details. Please try again.');
            }
        });
    });
    
    // Handle View Financial Year
    $(document).on('click', '.view-year', function() {
        var id = $(this).data('id');
        
        $.ajax({
            url: 'actions/get_financial_year.php',
            type: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var year = response.data;
                    
                    // Populate modal with financial year details
                    $('#view-year-code').text(year.year_code || '');
                    $('#view-start-date').text(year.start_date || '');
                    $('#view-end-date').text(year.end_date || '');
                    $('#view-status').text(year.is_closed ? 'Closed' : 'Open');
                    $('#view-created-by').text(year.created_by_name || '');
                    $('#view-created-at').text(year.created_at || '');
                    $('#view-updated-by').text(year.updated_by_name || '');
                    $('#view-updated-at').text(year.updated_at || '');
                    $('#view-closed-by').text(year.closed_by_name || '');
                    $('#view-closed-at').text(year.closed_at || '');
                } else {
                    showError(response.message || 'Error retrieving financial year details');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                showError('Error retrieving financial year details. Please try again.');
            }
        });
    });
    
    // Handle Close Financial Year
    $(document).on('click', '.close-year', function() {
        var id = $(this).data('id');
        var code = $(this).data('code');
        
        if (confirm('Are you sure you want to close financial year ' + code + '? This action cannot be undone.')) {
            $.ajax({
                url: 'actions/close_financial_year.php',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        showSuccess('Financial year closed successfully');
                        
                        // Reload table
                        table.ajax.reload();
                    } else {
                        showError(response.message || 'Error closing financial year');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    showError('Error closing financial year. Please try again.');
                }
            });
        }
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
