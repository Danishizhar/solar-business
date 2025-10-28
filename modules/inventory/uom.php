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
require_once 'includes/uom_settings_helper.php';
$database = new Database();
$db = $database->getConnection();

// Get all UOMs
try {
    // Get all UOMs without the product count for now
    $query = "SELECT u.*,
              0 AS products_count
              FROM uom u
              WHERE u.status = 1
              ORDER BY u.uom_type, u.uom_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $uoms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error in uom.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
}

// Get UOM types from settings
$allowed_types = getAllowedUOMTypes($db);
$uom_types = [];
foreach ($allowed_types as $type) {
    // Use the exact case as provided in settings for both key and value
    $uom_types[$type] = $type;
}

// Check if UOM settings are installed
$uom_settings_installed = false;
try {
    $query = "SELECT COUNT(*) as count FROM inventory_settings WHERE setting_group = 'uom'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $uom_settings_installed = ($result['count'] > 0);
} catch (PDOException $e) {
    error_log("Error checking UOM settings: " . $e->getMessage());
}

// Get settings values if installed
$auto_generate_uom_code = false;
$uom_code_prefix = 'UOM_';
$default_uom_type = '';
$can_be_used_for_purchase = false;
$can_be_used_for_sales = false;
$can_be_used_for_inventory = false;

if ($uom_settings_installed) {
    $auto_generate_uom_code = isAutoGenerateUOMCodeEnabled($db);
    $uom_code_prefix = getUOMCodePrefix($db);
    $default_uom_type = getDefaultUOMType($db);
    $can_be_used_for_purchase = canUOMBeUsedForPurchase($db);
    $can_be_used_for_sales = canUOMBeUsedForSales($db);
    $can_be_used_for_inventory = canUOMBeUsedForInventory($db);
}

// Include header
include_once '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
    <h1 class="mt-4">Units of Measurement</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Units of Measurement</li>
    </ol>
    
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <?php 
                echo $_SESSION['success_message']; 
                unset($_SESSION['success_message']);
            ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger">
            <?php 
                echo $_SESSION['error_message']; 
                unset($_SESSION['error_message']);
            ?>
        </div>
    <?php endif; ?>
    
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-ruler me-1"></i>
                Units of Measurement
            </div>
            <div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUomModal">
                    <i class="fas fa-plus"></i> Add New UOM
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="uomTable" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Symbol</th>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Description</th>
                            <?php if ($can_be_used_for_purchase || $can_be_used_for_sales || $can_be_used_for_inventory): ?>
                            <th>Usage</th>
                            <?php endif; ?>
                            <th>Products</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uoms as $uom): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($uom['uom_name']); ?></td>
                            <td><?php echo htmlspecialchars($uom['uom_symbol']); ?></td>
                            <td><?php echo htmlspecialchars($uom['uom_code'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($uom['uom_type'])); ?></td>
                            <td><?php echo htmlspecialchars($uom['description'] ?? ''); ?></td>
                            <?php if ($can_be_used_for_purchase || $can_be_used_for_sales || $can_be_used_for_inventory): ?>
                            <td>
                                <?php if ($can_be_used_for_purchase && isset($uom['can_be_used_for_purchase']) && $uom['can_be_used_for_purchase']): ?>
                                    <span class="badge bg-primary">Purchase</span>
                                <?php endif; ?>
                                <?php if ($can_be_used_for_sales && isset($uom['can_be_used_for_sales']) && $uom['can_be_used_for_sales']): ?>
                                    <span class="badge bg-success">Sales</span>
                                <?php endif; ?>
                                <?php if ($can_be_used_for_inventory && isset($uom['can_be_used_for_inventory']) && $uom['can_be_used_for_inventory']): ?>
                                    <span class="badge bg-info">Inventory</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><?php echo $uom['products_count']; ?></td>
                            <td>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-primary edit-uom-btn" 
                                            data-id="<?php echo $uom['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($uom['uom_name']); ?>"
                                            data-symbol="<?php echo htmlspecialchars($uom['uom_symbol']); ?>"
                                            data-code="<?php echo htmlspecialchars($uom['uom_code'] ?? ''); ?>"
                                            data-type="<?php echo htmlspecialchars($uom['uom_type']); ?>"
                                            data-description="<?php echo htmlspecialchars($uom['description'] ?? ''); ?>"
                                            data-purchase="<?php echo isset($uom['can_be_used_for_purchase']) ? $uom['can_be_used_for_purchase'] : '0'; ?>"
                                            data-sales="<?php echo isset($uom['can_be_used_for_sales']) ? $uom['can_be_used_for_sales'] : '0'; ?>"
                                            data-inventory="<?php echo isset($uom['can_be_used_for_inventory']) ? $uom['can_be_used_for_inventory'] : '0'; ?>"
                                            data-bs-toggle="modal" data-bs-target="#editUomModal">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-success conversion-btn"
                                            data-id="<?php echo $uom['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($uom['uom_name']); ?>"
                                            data-symbol="<?php echo htmlspecialchars($uom['uom_symbol']); ?>"
                                            data-bs-toggle="modal" data-bs-target="#conversionModal">
                                        <i class="fas fa-exchange-alt"></i>
                                    </button>
                                    <?php if ($uom['products_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-danger delete-uom-btn"
                                            data-id="<?php echo $uom['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($uom['uom_name']); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-danger" disabled title="Cannot delete UOM in use">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
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

<!-- Add UOM Modal -->
<div class="modal fade" id="addUomModal" tabindex="-1" aria-labelledby="addUomModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addUomModalLabel">Add New Unit of Measurement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/save_uom.php" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="uom_name" class="form-label">UOM Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="uom_name" name="uom_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="uom_symbol" class="form-label">UOM Symbol <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="uom_symbol" name="uom_symbol" required>
                        <div class="form-text">Short symbol (e.g., kg, m, pcs)</div>
                    </div>
                    <?php if (!$auto_generate_uom_code): ?>
                    <div class="mb-3">
                        <label for="uom_code" class="form-label">UOM Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="uom_code" name="uom_code" required>
                        <div class="form-text">Unique code for this UOM</div>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="uom_type" class="form-label">UOM Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="uom_type" name="uom_type" required>
                            <option value="">Select Type</option>
                            <?php foreach ($uom_types as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo ($value == strtolower($default_uom_type)) ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <?php if ($can_be_used_for_purchase || $can_be_used_for_sales || $can_be_used_for_inventory): ?>
                    <div class="mb-3">
                        <label class="form-label">Usage Options</label>
                        <div class="form-check">
                            <?php if ($can_be_used_for_purchase): ?>
                            <div class="mb-2">
                                <input class="form-check-input" type="checkbox" id="can_be_used_for_purchase" name="can_be_used_for_purchase" value="1" checked>
                                <label class="form-check-label" for="can_be_used_for_purchase">Can be used for purchase</label>
                            </div>
                            <?php endif; ?>
                            <?php if ($can_be_used_for_sales): ?>
                            <div class="mb-2">
                                <input class="form-check-input" type="checkbox" id="can_be_used_for_sales" name="can_be_used_for_sales" value="1" checked>
                                <label class="form-check-label" for="can_be_used_for_sales">Can be used for sales</label>
                            </div>
                            <?php endif; ?>
                            <?php if ($can_be_used_for_inventory): ?>
                            <div class="mb-2">
                                <input class="form-check-input" type="checkbox" id="can_be_used_for_inventory" name="can_be_used_for_inventory" value="1" checked>
                                <label class="form-check-label" for="can_be_used_for_inventory">Can be used for inventory</label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save UOM</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit UOM Modal -->
<div class="modal fade" id="editUomModal" tabindex="-1" aria-labelledby="editUomModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editUomModalLabel">Edit Unit of Measurement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/update_uom.php" method="POST">
                <input type="hidden" id="edit_uom_id" name="uom_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_uom_name" class="form-label">UOM Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_uom_name" name="uom_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_uom_symbol" class="form-label">UOM Symbol <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_uom_symbol" name="uom_symbol" required>
                        <div class="form-text">Short symbol (e.g., kg, m, pcs)</div>
                    </div>
                    <?php if (!$auto_generate_uom_code): ?>
                    <div class="mb-3">
                        <label for="edit_uom_code" class="form-label">UOM Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_uom_code" name="uom_code" required>
                        <div class="form-text">Unique code for this UOM</div>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="edit_uom_type" class="form-label">UOM Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_uom_type" name="uom_type" required>
                            <option value="">Select Type</option>
                            <?php foreach ($uom_types as $value => $label): ?>
                            <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <?php if ($can_be_used_for_purchase || $can_be_used_for_sales || $can_be_used_for_inventory): ?>
                    <div class="mb-3">
                        <label class="form-label">Usage Options</label>
                        <div class="form-check">
                            <?php if ($can_be_used_for_purchase): ?>
                            <div class="mb-2">
                                <input class="form-check-input" type="checkbox" id="edit_can_be_used_for_purchase" name="can_be_used_for_purchase" value="1">
                                <label class="form-check-label" for="edit_can_be_used_for_purchase">Can be used for purchase</label>
                            </div>
                            <?php endif; ?>
                            <?php if ($can_be_used_for_sales): ?>
                            <div class="mb-2">
                                <input class="form-check-input" type="checkbox" id="edit_can_be_used_for_sales" name="can_be_used_for_sales" value="1">
                                <label class="form-check-label" for="edit_can_be_used_for_sales">Can be used for sales</label>
                            </div>
                            <?php endif; ?>
                            <?php if ($can_be_used_for_inventory): ?>
                            <div class="mb-2">
                                <input class="form-check-input" type="checkbox" id="edit_can_be_used_for_inventory" name="can_be_used_for_inventory" value="1">
                                <label class="form-check-label" for="edit_can_be_used_for_inventory">Can be used for inventory</label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update UOM</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- UOM Conversion Modal -->
<div class="modal fade" id="conversionModal" tabindex="-1" aria-labelledby="conversionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="conversionModalLabel">UOM Conversions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="conversion_uom_id">
                <h6 id="conversion_uom_name" class="mb-3"></h6>
                
                <ul class="nav nav-tabs" id="conversionTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="existing-tab" data-bs-toggle="tab" data-bs-target="#existing" type="button" role="tab" aria-controls="existing" aria-selected="true">Existing Conversions</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="add-tab" data-bs-toggle="tab" data-bs-target="#add" type="button" role="tab" aria-controls="add" aria-selected="false">Add Conversion</button>
                    </li>
                </ul>
                
                <div class="tab-content pt-3" id="conversionTabContent">
                    <div class="tab-pane fade show active" id="existing" role="tabpanel" aria-labelledby="existing-tab">
                        <div id="existing_conversions_container">
                            <div class="text-center py-3">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="add" role="tabpanel" aria-labelledby="add-tab">
                        <form id="addConversionForm">
                            <input type="hidden" id="from_uom_id" name="from_uom_id">
                            
                            <div class="row mb-3">
                                <div class="col-md-5">
                                    <label for="conversion_factor" class="form-label">Conversion Factor <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">1 <span id="from_uom_symbol"></span> =</span>
                                        <input type="number" class="form-control" id="conversion_factor" name="conversion_factor" step="0.00001" min="0.00001" required>
                                    </div>
                                </div>
                                <div class="col-md-7">
                                    <label for="to_uom_id" class="form-label">Target UOM <span class="text-danger">*</span></label>
                                    <select class="form-select" id="to_uom_id" name="to_uom_id" required>
                                        <option value="">Select Target UOM</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">Add Conversion</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteUomModal" tabindex="-1" aria-labelledby="deleteUomModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteUomModalLabel">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the unit of measurement: <span id="delete_uom_name" class="fw-bold"></span>?</p>
                <p class="text-danger">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form action="actions/delete_uom.php" method="POST">
                    <input type="hidden" id="delete_uom_id" name="uom_id">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize DataTable
    $('#uomTable').DataTable({
        responsive: true,
        order: [[3, 'asc'], [0, 'asc']] // Updated to account for the new Code column
    });
    
    // Edit UOM button click
    document.querySelectorAll('.edit-uom-btn').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const symbol = this.getAttribute('data-symbol');
            const type = this.getAttribute('data-type');
            const description = this.getAttribute('data-description');
            
            // Get additional attributes for UOM code and usage options
            const code = this.getAttribute('data-code');
            const canPurchase = this.getAttribute('data-purchase') === '1';
            const canSales = this.getAttribute('data-sales') === '1';
            const canInventory = this.getAttribute('data-inventory') === '1';
            
            document.getElementById('edit_uom_id').value = id;
            document.getElementById('edit_uom_name').value = name;
            document.getElementById('edit_uom_symbol').value = symbol;
            document.getElementById('edit_uom_type').value = type;
            document.getElementById('edit_description').value = description;
            
            // Set UOM code if the field exists
            const codeField = document.getElementById('edit_uom_code');
            if (codeField && code) {
                codeField.value = code;
            }
            
            // Set usage checkboxes if they exist
            const purchaseCheckbox = document.getElementById('edit_can_be_used_for_purchase');
            if (purchaseCheckbox) {
                purchaseCheckbox.checked = canPurchase;
            }
            
            const salesCheckbox = document.getElementById('edit_can_be_used_for_sales');
            if (salesCheckbox) {
                salesCheckbox.checked = canSales;
            }
            
            const inventoryCheckbox = document.getElementById('edit_can_be_used_for_inventory');
            if (inventoryCheckbox) {
                inventoryCheckbox.checked = canInventory;
            }
        });
    });
    
    // Delete UOM button click
    document.querySelectorAll('.delete-uom-btn').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            
            document.getElementById('delete_uom_id').value = id;
            document.getElementById('delete_uom_name').textContent = name;
            
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteUomModal'));
            deleteModal.show();
        });
    });
    
    // Conversion button click
    document.querySelectorAll('.conversion-btn').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const symbol = this.getAttribute('data-symbol');
            
            document.getElementById('conversion_uom_id').value = id;
            document.getElementById('from_uom_id').value = id;
            document.getElementById('conversion_uom_name').textContent = name + ' (' + symbol + ')';
            document.getElementById('from_uom_symbol').textContent = symbol;
            
            // Load existing conversions
            loadExistingConversions(id);
            
            // Load target UOMs for conversion
            loadTargetUoms(id);
        });
    });
    
    // Function to load existing conversions
    function loadExistingConversions(uomId) {
        const container = document.getElementById('existing_conversions_container');
        
        fetch('actions/get_uom_conversions.php?uom_id=' + uomId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.conversions.length > 0) {
                        let html = '<div class="table-responsive">';
                        html += '<table class="table table-bordered table-striped">';
                        html += '<thead><tr><th>From</th><th>To</th><th>Conversion Factor</th><th>Actions</th></tr></thead>';
                        html += '<tbody>';
                        
                        data.conversions.forEach(conv => {
                            html += '<tr>';
                            html += '<td>' + conv.from_uom_name + ' (' + conv.from_uom_symbol + ')</td>';
                            html += '<td>' + conv.to_uom_name + ' (' + conv.to_uom_symbol + ')</td>';
                            html += '<td>1 ' + conv.from_uom_symbol + ' = ' + conv.conversion_factor + ' ' + conv.to_uom_symbol + '</td>';
                            html += '<td>';
                            html += '<button type="button" class="btn btn-sm btn-danger delete-conversion-btn" data-id="' + conv.id + '">';
                            html += '<i class="fas fa-trash"></i></button>';
                            html += '</td>';
                            html += '</tr>';
                        });
                        
                        html += '</tbody></table></div>';
                        container.innerHTML = html;
                        
                        // Add event listeners to delete buttons
                        document.querySelectorAll('.delete-conversion-btn').forEach(btn => {
                            btn.addEventListener('click', function() {
                                if (confirm('Are you sure you want to delete this conversion?')) {
                                    const convId = this.getAttribute('data-id');
                                    deleteConversion(convId, uomId);
                                }
                            });
                        });
                    } else {
                        container.innerHTML = '<div class="alert alert-info">No conversions found for this UOM.</div>';
                    }
                } else {
                    container.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = '<div class="alert alert-danger">Error loading conversions. Please try again.</div>';
            });
    }
    
    // Function to load target UOMs for conversion
    function loadTargetUoms(uomId) {
        const select = document.getElementById('to_uom_id');
        
        fetch('actions/get_target_uoms.php?uom_id=' + uomId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Clear existing options
                    select.innerHTML = '<option value="">Select Target UOM</option>';
                    
                    // Add new options
                    data.uoms.forEach(uom => {
                        const option = document.createElement('option');
                        option.value = uom.id;
                        option.textContent = uom.uom_name + ' (' + uom.uom_symbol + ')';
                        select.appendChild(option);
                    });
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading target UOMs. Please try again.');
            });
    }
    
    // Handle conversion form submission
    document.getElementById('addConversionForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const fromUomId = document.getElementById('from_uom_id').value;
        const toUomId = document.getElementById('to_uom_id').value;
        const conversionFactor = document.getElementById('conversion_factor').value;
        
        if (!fromUomId || !toUomId || !conversionFactor) {
            alert('Please fill all required fields');
            return;
        }
        
        const formData = new FormData();
        formData.append('from_uom_id', fromUomId);
        formData.append('to_uom_id', toUomId);
        formData.append('conversion_factor', conversionFactor);
        
        fetch('actions/save_uom_conversion.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success alert-dismissible fade show mt-3';
                alertDiv.innerHTML = data.message + 
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                
                document.getElementById('addConversionForm').prepend(alertDiv);
                
                // Reset form
                document.getElementById('conversion_factor').value = '';
                document.getElementById('to_uom_id').value = '';
                
                // Reload conversions
                loadExistingConversions(fromUomId);
                
                // Reload target UOMs
                loadTargetUoms(fromUomId);
            } else {
                // Show error message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show mt-3';
                alertDiv.innerHTML = data.message + 
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                
                document.getElementById('addConversionForm').prepend(alertDiv);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error saving conversion. Please try again.');
        });
    });
    
    // Function to delete a conversion
    function deleteConversion(conversionId, uomId) {
        fetch('actions/delete_uom_conversion.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'conversion_id=' + conversionId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reload conversions
                loadExistingConversions(uomId);
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting conversion. Please try again.');
        });
    }
});
</script>

        </div>
    </div>
</div>

<?php include_once '../../includes/footer.php'; ?>
