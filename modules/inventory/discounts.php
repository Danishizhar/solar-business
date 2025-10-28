<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Include database connection
require_once '../../config/database.php';
require_once './includes/discount_helpers.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

// Check if discounts feature is enabled
$enable_discounts = getDiscountSetting($db, 'enable_discounts', '1');
if ($enable_discounts != '1') {
    $_SESSION['error_message'] = "Discount feature is not enabled. Please enable it in settings.";
    header("Location: ./settings.php?tab=discount");
    exit();
}

// Page title
$page_title = "Discount Management";
?>

<?php
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
                    <h2><?php echo $page_title; ?></h2>
                </div>
                <div class="col-md-6 text-end">
                    <a href="discount_form.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Discount</a>
                    <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
                    <a href="upgrade_discount_system.php" class="btn btn-info ms-2"><i class="fas fa-sync"></i> Upgrade Discount System</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="./index.php">Inventory</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo $page_title; ?></li>
                </ol>
            </nav>
            
            <!-- Custom CSS for DataTables and DateRangePicker -->
            <style>
                .badge-percentage {
                    background-color: #28a745;
                    color: white;
                }
                .badge-fixed {
                    background-color: #007bff;
                    color: white;
                }
                .discount-status-active {
                    color: #28a745;
                }
                .discount-status-inactive {
                    color: #dc3545;
                }
                .discount-expired {
                    color: #6c757d;
                }
                .discount-future {
                    color: #17a2b8;
                }
                .discount-current {
                    color: #28a745;
                }
            </style>

            <!-- Main content -->
                    <!-- Display Messages -->
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['success_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success_message']); ?>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['error_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error_message']); ?>
                    <?php endif; ?>
                    
                    <!-- Discounts Table Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">All Discounts</h3>
                        </div>
                        <div class="card-body">
                            <table id="discountsTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Value</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
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
    
    <!-- DataTables & Plugins from CDN -->
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.2.9/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.print.min.js"></script>
    
    <!-- Moment.js and DateRangePicker from CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    
    <!-- Toastr for notifications -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    
    <!-- Page specific script -->
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            var discountsTable = $('#discountsTable').DataTable({
                processing: true,
                serverSide: false,
                ajax: {
                    url: 'actions/get_discounts.php',
                    type: 'POST',
                    dataSrc: 'data'
                },
                columns: [
                    { data: 'discount_code' },
                    { data: 'discount_name' },
                    { 
                        data: 'discount_type',
                        render: function(data) {
                            if (data === 'percentage') {
                                return '<span class="badge bg-success">Percentage</span>';
                            } else {
                                return '<span class="badge bg-primary">Fixed Amount</span>';
                            }
                        }
                    },
                    { 
                        data: null,
                        render: function(data) {
                            if (data.discount_type === 'percentage') {
                                return data.discount_value + '%';
                            } else {
                                return '₹' + parseFloat(data.discount_value).toFixed(2);
                            }
                        }
                    },
                    { 
                        data: 'start_date',
                        render: function(data) {
                            return data ? moment(data).format('DD-MM-YYYY') : 'No Start Date';
                        }
                    },
                    { 
                        data: 'end_date',
                        render: function(data) {
                            return data ? moment(data).format('DD-MM-YYYY') : 'No End Date';
                        }
                    },
                    { 
                        data: null,
                        render: function(data) {
                            const today = moment().startOf('day');
                            const startDate = data.start_date ? moment(data.start_date) : null;
                            const endDate = data.end_date ? moment(data.end_date) : null;
                            
                            if (!data.is_active) {
                                return '<span class="discount-status-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
                            } else if (endDate && endDate < today) {
                                return '<span class="discount-status-expired"><i class="fas fa-calendar-times"></i> Expired</span>';
                            } else if (startDate && startDate > today) {
                                return '<span class="discount-status-inactive"><i class="fas fa-clock"></i> Scheduled</span>';
                            } else {
                                return '<span class="discount-status-active"><i class="fas fa-check-circle"></i> Active</span>';
                            }
                        }
                    },
                    {
                        data: null,
                        render: function(data) {
                            let buttons = `
                                <div class="btn-group">
                                    <a href="discount_form.php?id=${data.id}" class="btn btn-info btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>`;
                                    
                            if (data.is_active) {
                                buttons += `
                                    <button class="btn btn-warning btn-sm toggle-status" data-id="${data.id}" data-status="0" title="Deactivate">
                                        <i class="fas fa-ban"></i>
                                    </button>`;
                            } else {
                                buttons += `
                                    <button class="btn btn-success btn-sm toggle-status" data-id="${data.id}" data-status="1" title="Activate">
                                        <i class="fas fa-check"></i>
                                    </button>`;
                            }
                            
                            buttons += `
                                    <button class="btn btn-danger btn-sm delete-discount" data-id="${data.id}" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>`;
                                
                            return buttons;
                        },
                        orderable: false
                    }
                ],
                responsive: true,
                lengthChange: true,
                autoWidth: false,
                dom: 'Bfrtip',
                buttons: [
                    'copy', 'csv', 'excel', 'pdf', 'print'
                ]
            });
            
            // Add New Discount Button
            $('#addDiscountBtn').on('click', function() {
                window.location.href = 'discount_form.php';
            });
            
            // Toggle Discount Status
            $(document).on('click', '.toggle-status', function() {
                const discountId = $(this).data('id');
                const newStatus = $(this).data('status');
                
                if (confirm('Are you sure you want to ' + (newStatus == 1 ? 'activate' : 'deactivate') + ' this discount?')) {
                    $.ajax({
                        url: 'actions/update_discount_status.php',
                        type: 'POST',
                        data: {
                            discount_id: discountId,
                            is_active: newStatus
                        },
                        success: function(response) {
                            const result = JSON.parse(response);
                            if (result.success) {
                                toastr.success(result.message);
                                $('#discountsTable').DataTable().ajax.reload();
                            } else {
                                toastr.error(result.message);
                            }
                        },
                        error: function() {
                            toastr.error('An error occurred while updating the status.');
                        }
                    });
                }
            });
            
            // Delete Discount
            $(document).on('click', '.delete-discount', function() {
                const discountId = $(this).data('id');
                
                if (confirm('Are you sure you want to delete this discount? This action cannot be undone.')) {
                    $.ajax({
                        url: 'actions/delete_discount.php',
                        type: 'POST',
                        data: {
                            discount_id: discountId
                        },
                        success: function(response) {
                            const result = JSON.parse(response);
                            if (result.success) {
                                toastr.success(result.message);
                                $('#discountsTable').DataTable().ajax.reload();
                            } else {
                                toastr.error(result.message);
                            }
                        },
                        error: function() {
                            toastr.error('An error occurred while deleting the discount.');
                        }
                    });
                }
            });
        });
    </script>
    
    <!-- Include Toastr JS from CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
</div>
</div>

<?php include '../../includes/footer.php'; ?>
