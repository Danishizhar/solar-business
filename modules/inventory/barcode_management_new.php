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

$success_message = '';
$error_message = '';

// Get barcode settings
$barcode_settings = [];
try {
    $settings_query = "SELECT * FROM inventory_settings WHERE setting_group = 'barcode'";
    $settings_stmt = $db->prepare($settings_query);
    $settings_stmt->execute();
    $all_settings = $settings_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to key-value array
    foreach ($all_settings as $setting) {
        $barcode_settings[$setting['setting_key']] = $setting;
    }
} catch (PDOException $e) {
    error_log("Error fetching barcode settings: " . $e->getMessage());
    // Set default settings if not found
    $barcode_settings = [
        'default_barcode_format' => ['setting_value' => 'CODE128', 'input_options' => 'CODE128,EAN13,CODE39,UPC,EAN8'],
        'barcode_prefix' => ['setting_value' => ''],
        'auto_generate_barcode' => ['setting_value' => '0'],
        'barcode_label_size' => ['setting_value' => 'medium', 'input_options' => 'small,medium,large,a4-30,a4-24,a4-16'],
        'barcode_label_style' => ['setting_value' => 'basic', 'input_options' => 'basic,compact,detailed,minimal']
    ];
}

// Get default values from settings
$default_format = isset($barcode_settings['default_barcode_format']['setting_value']) ? 
                  $barcode_settings['default_barcode_format']['setting_value'] : 'CODE128';
$format_options = isset($barcode_settings['default_barcode_format']['input_options']) ? 
                 explode(',', $barcode_settings['default_barcode_format']['input_options']) : 
                 ['CODE128', 'EAN13', 'CODE39', 'UPC', 'EAN8'];

$selected_size = isset($barcode_settings['barcode_label_size']['setting_value']) ? 
                $barcode_settings['barcode_label_size']['setting_value'] : 'medium';
$size_options = isset($barcode_settings['barcode_label_size']['input_options']) ? 
               explode(',', $barcode_settings['barcode_label_size']['input_options']) : 
               ['small', 'medium', 'large', 'a4-30', 'a4-24', 'a4-16'];

$selected_style = isset($barcode_settings['barcode_label_style']['setting_value']) ? 
                 $barcode_settings['barcode_label_style']['setting_value'] : 'basic';
$style_options = isset($barcode_settings['barcode_label_style']['input_options']) ? 
                explode(',', $barcode_settings['barcode_label_style']['input_options']) : 
                ['basic', 'compact', 'detailed', 'minimal'];

// Size labels for display
$size_labels = [
    'small' => 'Small (38mm × 25mm)',
    'medium' => 'Medium (50mm × 30mm)',
    'large' => 'Large (100mm × 50mm)',
    'a4-30' => 'A4 - 30 per sheet',
    'a4-24' => 'A4 - 24 per sheet',
    'a4-16' => 'A4 - 16 per sheet'
];

// Style labels for display
$style_labels = [
    'basic' => 'Basic',
    'compact' => 'Compact',
    'detailed' => 'Detailed',
    'minimal' => 'Minimal'
];

// Get all products with barcodes
$products = [];
try {
    // Query adjusted to match actual database structure
    $query = "SELECT p.id, p.product_name, p.product_code, p.barcode, p.selling_price, 
              p.wholesale_price, COALESCE(pc.category_name, 'Uncategorized') as category_name, 
              COALESCE(b.brand_name, 'No Brand') as brand_name, p.category_id, p.brand_id
       FROM products p
       LEFT JOIN product_categories pc ON p.category_id = pc.id
       LEFT JOIN brands b ON p.brand_id = b.id
       ORDER BY p.product_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no products, create a sample product for the template preview
    if (empty($products)) {
        $products = [
            [
                'id' => 0,
                'product_name' => 'Sample Product',
                'product_code' => 'SP001',
                'barcode' => '1234567890128',
                'selling_price' => 1250.00,
                'wholesale_price' => 1100.00,
                'category_name' => 'Sample Category',
                'brand_name' => 'Sample Brand',
                'category_id' => 0,
                'brand_id' => 0
            ]
        ];
        $info_message = "No products found in the database. A sample product has been created for preview purposes.";
    }
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    error_log('Error fetching products: ' . $e->getMessage());
    
    // Create sample product for preview even if there's an error
    $products = [
        [
            'id' => 0,
            'product_name' => 'Sample Product',
            'product_code' => 'SP001',
            'barcode' => '1234567890128',
            'selling_price' => 1250.00,
            'wholesale_price' => 1100.00,
            'category_name' => 'Sample Category',
            'brand_name' => 'Sample Brand',
            'category_id' => 0,
            'brand_id' => 0
        ]
    ];
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
                    <h2>Barcode Management</h2>
                </div>
                <div class="col-md-6 text-end">
                    <button id="print-selected-barcodes" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Selected
                    </button>
                </div>
            </div>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>

            <?php if (!empty($info_message)): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($info_message); ?></div>
            <?php endif; ?>

            <!-- Print Settings Section -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Print Settings</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="template-size">Label Size</label>
                                <select class="form-control" id="template-size">
                                    <?php 
                                    foreach ($size_options as $size) {
                                        $label = isset($size_labels[$size]) ? $size_labels[$size] : $size;
                                        $selected = ($size == $selected_size) ? 'selected' : '';
                                        echo "<option value=\"$size\" $selected>$label</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="template-style">Label Style</label>
                                <select class="form-control" id="template-style">
                                    <?php 
                                    foreach ($style_options as $style) {
                                        $label = isset($style_labels[$style]) ? $style_labels[$style] : $style;
                                        $selected = ($style == $selected_style) ? 'selected' : '';
                                        echo "<option value=\"$style\" $selected>$label</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="barcode-format">Barcode Format</label>
                                <select class="form-control" id="barcode-format">
                                    <?php 
                                    foreach ($format_options as $format) {
                                        $selected = ($format == $default_format) ? 'selected' : '';
                                        echo "<option value=\"$format\" $selected>$format</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="copies-per-product">Copies Per Product</label>
                                <input type="number" class="form-control" id="copies-per-product" min="1" max="100" value="1">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="show-price" checked>
                                <label class="form-check-label" for="show-price">Show Retail Price</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="show-wholesale-price">
                                <label class="form-check-label" for="show-wholesale-price">Show Wholesale Price</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="show-product-code" checked>
                                <label class="form-check-label" for="show-product-code">Show Product Code</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <button id="update-preview" class="btn btn-primary">
                                <i class="fas fa-sync"></i> Update Preview
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Template Preview Section -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Template Preview</h5>
                </div>
                <div class="card-body">
                    <div id="template-preview" class="text-center p-3 border rounded bg-light">
                        <!-- Template preview will be shown here -->
                    </div>
                </div>
            </div>

            <!-- Products Table -->
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Products</h5>
                    <div>
                        <button id="select-all" class="btn btn-sm btn-light">Select All</button>
                        <button id="deselect-all" class="btn btn-sm btn-light">Deselect All</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="products-table">
                            <thead>
                                <tr>
                                    <th width="40px"><input type="checkbox" id="select-all-checkbox"></th>
                                    <th>Product Name</th>
                                    <th>Product Code</th>
                                    <th>Barcode</th>
                                    <th>Category</th>
                                    <th>Retail Price</th>
                                    <th>Wholesale Price</th>
                                    <th width="100px">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="product-checkbox" 
                                            data-id="<?php echo htmlspecialchars($product['id']); ?>"
                                            data-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                            data-code="<?php echo htmlspecialchars($product['product_code']); ?>"
                                            data-barcode="<?php echo htmlspecialchars($product['barcode']); ?>"
                                            data-price="<?php echo htmlspecialchars($product['selling_price']); ?>"
                                            data-wholesale="<?php echo htmlspecialchars($product['wholesale_price'] ?? ''); ?>"
                                            data-category="<?php echo htmlspecialchars($product['category_name']); ?>"
                                            data-brand="<?php echo htmlspecialchars($product['brand_name']); ?>"
                                            <?php echo empty($product['barcode']) ? 'disabled' : ''; ?>>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                    <td><?php echo htmlspecialchars($product['product_code']); ?></td>
                                    <td>
                                        <?php if (!empty($product['barcode'])): ?>
                                            <?php echo htmlspecialchars($product['barcode']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">No barcode</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                    <td><?php echo htmlspecialchars(formatNumber($conn, $product['selling_price'])); ?></td>
                                    <td><?php echo !empty($product['wholesale_price']) ? htmlspecialchars(formatNumber($conn, $product['wholesale_price'])) : '<span class="text-muted">Not set</span>'; ?></td>
                                    <td>
                                        <?php if (!empty($product['barcode'])): ?>
                                        <button class="btn btn-sm btn-primary print-single" 
                                            data-product-id="<?php echo htmlspecialchars($product['id']); ?>"
                                            data-product-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                            data-product-code="<?php echo htmlspecialchars($product['product_code']); ?>"
                                            data-barcode="<?php echo htmlspecialchars($product['barcode']); ?>"
                                            data-price="<?php echo htmlspecialchars($product['selling_price']); ?>"
                                            data-wholesale="<?php echo htmlspecialchars($product['wholesale_price'] ?? ''); ?>"
                                            data-category="<?php echo htmlspecialchars($product['category_name']); ?>"
                                            data-brand="<?php echo htmlspecialchars($product['brand_name']); ?>">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-sm btn-secondary generate-barcode" 
                                            data-product-id="<?php echo htmlspecialchars($product['id']); ?>"
                                            data-product-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                            data-product-code="<?php echo htmlspecialchars($product['product_code']); ?>">
                                            <i class="fas fa-barcode"></i>
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

<!-- Generate Barcode Modal -->
<div class="modal fade" id="generate-barcode-modal" tabindex="-1" aria-labelledby="generateBarcodeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="generateBarcodeModalLabel">Generate Barcode</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modal-product-id">
                
                <div class="mb-3">
                    <label for="modal-product-name" class="form-label">Product Name</label>
                    <input type="text" class="form-control" id="modal-product-name" readonly>
                </div>
                
                <div class="mb-3">
                    <label for="modal-product-code" class="form-label">Product Code</label>
                    <input type="text" class="form-control" id="modal-product-code" readonly>
                </div>
                
                <div class="mb-3">
                    <label for="modal-barcode" class="form-label">Barcode</label>
                    <div class="input-group mb-2">
                        <input type="text" class="form-control" id="modal-barcode" placeholder="Enter or generate barcode">
                        <button class="btn btn-outline-secondary" type="button" id="modal-generate-barcode">
                            <i class="fas fa-barcode"></i>
                        </button>
                    </div>
                    <div class="btn-group w-100 mb-2">
                        <button class="btn btn-sm btn-outline-primary" type="button" id="modal-generate-barcode-btn">
                            <i class="fas fa-barcode"></i> Generate
                        </button>
                        <button class="btn btn-sm btn-outline-primary" type="button" id="modal-scan-barcode">
                            <i class="fas fa-camera"></i> Scan
                        </button>
                    </div>
                    <div id="modal-barcode-preview" class="mt-2 text-center p-2 border rounded" style="display: none;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-barcode">Save Barcode</button>
            </div>
        </div>
    </div>
</div>

<!-- Print Preview Modal -->
<div class="modal fade" id="print-preview-modal" tabindex="-1" aria-labelledby="printPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="printPreviewModalLabel">Print Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="print-preview-content" class="p-3">
                    <!-- Print preview content will be shown here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirm-print">Print</button>
            </div>
        </div>
    </div>
</div>

<!-- Barcode Scanner Modal -->
<div class="modal fade" id="barcode-scanner-modal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="barcodeScannerModalLabel">Scan Barcode</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="scanner-container" class="text-center">
                    <div id="scanner-viewport" style="width: 100%; height: 300px; position: relative; overflow: hidden;">
                        <video id="scanner-video" style="width: 100%; height: 100%; object-fit: cover;"></video>
                        <canvas id="scanner-canvas" style="display: none;"></canvas>
                    </div>
                    <div class="mt-3">
                        <p id="scanner-status" class="mb-2">Initializing camera...</p>
                        <button id="scanner-start" class="btn btn-primary" disabled>Start Scanning</button>
                        <button id="scanner-stop" class="btn btn-secondary" disabled>Stop Scanning</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<!-- Barcode Libraries -->
<script src="../../assets/js/jsbarcode.min.js"></script>
<script src="../../assets/js/quagga.min.js"></script>

<!-- App Scripts -->
<script src="js/barcode-management-new.js"></script>
