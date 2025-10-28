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
if ($_SESSION['role_id'] != 1 && (!isset($_SESSION['permissions']['purchase_returns']) || !in_array('view', $_SESSION['permissions']['purchase_returns']))) {
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
$page_title = "Purchase Returns";

// Include header
include '../../includes/header.php';

// Define status colors for badges
$status_colors = [
    'draft' => 'bg-secondary',
    'approved' => 'bg-success',
    'cancelled' => 'bg-danger'
];
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-undo-alt"></i> <?php echo $page_title; ?></h2>
                </div>
                <div class="col-md-6 text-end">
                    <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_create'])): ?>
                    <a href="purchase_return_create.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Purchase Return
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Purchase Returns List</h5>
                </div>
                        <div class="card-body">
                    <div class="table-responsive">
                        <table id="purchaseReturnsTable" class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th width="10%">Return #</th>
                                    <th width="10%">Date</th>
                                    <th width="10%">Invoice #</th>
                                    <th width="15%">Supplier</th>
                                    <th width="10%">Amount</th>
                                    <th width="10%">Status</th>
                                    <th width="15%">Actions</th>
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
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Initialize DataTable with try/catch to handle missing extensions
    try {
        var table = $('#purchaseReturnsTable').DataTable({
            "processing": true,
            "serverSide": true,
            "ajax": {
                "url": "actions/get_purchase_returns.php",
                "type": "POST",
                "error": function(xhr, error, thrown) {
                    console.error("DataTables AJAX error:", xhr, error, thrown);
                    $('.card-body').prepend('<div class="alert alert-danger">Error loading data. Please refresh the page or contact support.</div>');
                }
            },
            "columns": [
                { "data": "return_number" },
                { "data": "return_date" },
                { "data": "invoice_number" },
                { 
                    "data": "supplier_name",
                    "render": function(data, type, row) {
                        return data + '<small class="d-block text-muted">' + (row.supplier_code || '') + '</small>';
                    }
                },
                { 
                    "data": "total_amount",
                    "render": function(data, type, row) {
                        return '<span class="text-right d-block"><?php echo formatNumber($conn, "' + parseFloat(data) + '"); ?></span>';
                    }
                },
                { 
                    "data": "status",
                    "render": function(data, type, row) {
                        if (data === 'draft') {
                            return '<span class="badge <?php echo $status_colors['draft']; ?>">Draft</span>';
                        } else if (data === 'approved') {
                            return '<span class="badge <?php echo $status_colors['approved']; ?>">Approved</span>';
                        } else if (data === 'cancelled') {
                            return '<span class="badge <?php echo $status_colors['cancelled']; ?>">Cancelled</span>';
                        }
                        return data;
                    }
                },
                {
                    "data": null,
                    "render": function(data, type, row) {
                        var actions = '<div class="btn-group btn-group-sm">';
                        actions += '<a href="purchase_return_detail.php?id=' + row.id + '" class="btn btn-info" title="View"><i class="fas fa-eye"></i></a>';
                        
                        if (row.status === 'draft') {
                            <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_approve'])): ?>
                            actions += '<button class="btn btn-success approve-return" data-id="' + row.id + '" title="Approve"><i class="fas fa-check"></i></button>';
                            <?php endif; ?>
                            
                            <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_delete'])): ?>
                            actions += '<button class="btn btn-danger delete-return" data-id="' + row.id + '" title="Delete"><i class="fas fa-trash"></i></button>';
                            <?php endif; ?>
                        } else if (row.status === 'approved') {
                            <?php if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['purchase_cancel'])): ?>
                            actions += '<button class="btn btn-danger cancel-return" data-id="' + row.id + '" title="Cancel"><i class="fas fa-times"></i></button>';
                            <?php endif; ?>
                        }
                        
                        actions += '</div>';
                        return actions;
                    }
                }
            ],
            "order": [[0, "desc"]],
            "responsive": true,
            "lengthChange": true,
            "autoWidth": false,
            "buttons": ["copy", "csv", "excel", "pdf", "print"],
            "language": {
                "processing": "<div class='spinner-border text-primary' role='status'><span class='sr-only'>Loading...</span></div>",
                "emptyTable": "No purchase returns found",
                "zeroRecords": "No matching purchase returns found"
            }
        });
        
        table.buttons().container().appendTo('#purchaseReturnsTable_wrapper .col-md-6:eq(0)');
    } catch (e) {
        console.error("DataTables error:", e);
        $('#purchaseReturnsTable').DataTable({
            "responsive": true,
            "lengthChange": false
        });
        
        // Show error message
        $('.card-body').prepend('<div class="alert alert-warning">Some table features are not available. Error: ' + e.message + '</div>');
    }

    // Approve return
    $(document).on('click', '.approve-return', function() {
        var returnId = $(this).data('id');
        
        Swal.fire({
            title: 'Approve Purchase Return?',
            text: "This will process the return and update inventory and accounting records.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, approve it!'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                Swal.fire({
                    title: 'Processing...',
                    text: 'Please wait while we process the return.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.ajax({
                    url: 'actions/approve_purchase_return.php',
                    type: 'POST',
                    data: { id: returnId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Approved!',
                                text: 'Purchase return has been approved successfully.',
                                confirmButtonColor: '#3085d6'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message || 'Unknown error occurred',
                                confirmButtonColor: '#3085d6'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error(xhr, status, error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Server Error!',
                            text: 'An error occurred while approving the return. Please try again or contact support.',
                            confirmButtonColor: '#3085d6'
                        });
                    }
                });
            }
        });
    });
    
    // Delete return
    $(document).on('click', '.delete-return', function() {
        var returnId = $(this).data('id');
        
        Swal.fire({
            title: 'Delete Purchase Return?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                Swal.fire({
                    title: 'Processing...',
                    text: 'Please wait while we delete the return.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.ajax({
                    url: 'actions/delete_purchase_return.php',
                    type: 'POST',
                    data: { id: returnId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: 'Purchase return has been deleted successfully.',
                                confirmButtonColor: '#3085d6'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message || 'Unknown error occurred',
                                confirmButtonColor: '#3085d6'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error(xhr, status, error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Server Error!',
                            text: 'An error occurred while deleting the return. Please try again or contact support.',
                            confirmButtonColor: '#3085d6'
                        });
                    }
                });
            }
        });
    });
    
    // Cancel return
    $(document).on('click', '.cancel-return', function() {
        var returnId = $(this).data('id');
        
        Swal.fire({
            title: 'Cancel Purchase Return?',
            text: "This will reverse all inventory and accounting transactions.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, cancel it!'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                Swal.fire({
                    title: 'Processing...',
                    text: 'Please wait while we cancel the return.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.ajax({
                    url: 'actions/cancel_purchase_return.php',
                    type: 'POST',
                    data: { id: returnId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Cancelled!',
                                text: 'Purchase return has been cancelled successfully.',
                                confirmButtonColor: '#3085d6'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message || 'Unknown error occurred',
                                confirmButtonColor: '#3085d6'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error(xhr, status, error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Server Error!',
                            text: 'An error occurred while cancelling the return. Please try again or contact support.',
                            confirmButtonColor: '#3085d6'
                        });
                    }
                });
            }
        });
    });
    
    // Add print functionality if needed
    $(document).on('click', '.print-return', function() {
        var returnId = $(this).data('id');
        window.open('print_purchase_return.php?id=' + returnId, '_blank');
    });
    
    // Add event listener for the "New Purchase Return" button
    $('.btn-primary').on('click', function(e) {
        // Check if there are any unsaved changes before navigating away
        if (window.hasUnsavedChanges) {
            e.preventDefault();
            Swal.fire({
                title: 'Unsaved Changes',
                text: 'You have unsaved changes. Do you want to leave this page?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, leave page',
                cancelButtonText: 'No, stay here'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.hasUnsavedChanges = false;
                    window.location.href = $(this).attr('href');
                }
            });
        }
    });
});
</script>
