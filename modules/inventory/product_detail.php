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

// Check if product ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "Product ID is required";
    header("Location: products.php");
    exit();
}

$product_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    // Get product details
    $query = "SELECT p.*, 
              c.category_name, 
              b.brand_name,
              bu.uom_name as base_uom_name,
              pu.uom_name as purchase_uom_name,
              su.uom_name as sales_uom_name
              FROM products p
              LEFT JOIN product_categories c ON p.category_id = c.id
              LEFT JOIN brands b ON p.brand_id = b.id
              LEFT JOIN uom bu ON p.base_uom_id = bu.id
              LEFT JOIN uom pu ON p.purchase_uom_id = pu.id
              LEFT JOIN uom su ON p.sales_uom_id = su.id
              WHERE p.id = :id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $product_id, PDO::PARAM_INT);
    $stmt->execute();
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        throw new Exception("Product not found");
    }
    
    // Get product attributes
    $attr_query = "SELECT a.*, ad.attribute_name, ad.input_type, ad.is_variant
                  FROM product_attributes a
                  JOIN attribute_definitions ad ON a.attribute_id = ad.id
                  WHERE a.product_id = :product_id
                  ORDER BY ad.attribute_name";
    $attr_stmt = $db->prepare($attr_query);
    $attr_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $attr_stmt->execute();
    $attributes = $attr_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get variant attributes if this product has variants
    $variant_attributes = [];
    if ($product['has_variants'] == 1) {
        $var_attr_query = "SELECT * FROM product_variant_attributes 
                          WHERE product_id = :product_id
                          ORDER BY attribute_name";
        $var_attr_stmt = $db->prepare($var_attr_query);
        $var_attr_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $var_attr_stmt->execute();
        $variant_attributes = $var_attr_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get variant values for each attribute
        foreach ($variant_attributes as &$attr) {
            $values_query = "SELECT * FROM product_variant_values 
                            WHERE attribute_id = :attribute_id
                            ORDER BY attribute_value";
            $values_stmt = $db->prepare($values_query);
            $values_stmt->bindParam(':attribute_id', $attr['id'], PDO::PARAM_INT);
            $values_stmt->execute();
            $attr['values'] = $values_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($attr); // Break the reference
    }
    
    // Get variants if this product has variants
    $variants = [];
    if ($product['has_variants'] == 1) {
        $variants_query = "SELECT * FROM products 
                          WHERE parent_product_id = :parent_id AND is_variant = 1
                          ORDER BY product_name";
        $variants_stmt = $db->prepare($variants_query);
        $variants_stmt->bindParam(':parent_id', $product_id, PDO::PARAM_INT);
        $variants_stmt->execute();
        $variants = $variants_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get current stock levels
    $stock_query = "SELECT s.*, l.location_name
                   FROM inventory_stock s
                   JOIN inventory_locations l ON s.location_id = l.id
                   WHERE s.product_id = :product_id AND s.status = 1";
    $stock_stmt = $db->prepare($stock_query);
    $stock_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stock_stmt->execute();
    $stock = $stock_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total stock
    $total_stock = 0;
    foreach ($stock as $item) {
        $total_stock += $item['quantity'];
    }
    
} catch (Exception $e) {
    $_SESSION['error_message'] = $e->getMessage();
    header("Location: products.php");
    exit();
}

// Include header
$page_title = "Product Details: " . htmlspecialchars($product['product_name']);
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><?php echo htmlspecialchars($product['product_name']); ?></h2>
                </div>
                <div class="col-md-6">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb float-md-end">
                            <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="products.php">Products</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Product Details</li>
                        </ol>
                    </nav>
                </div>
            </div>
            <?php include '../../includes/alerts.php'; ?>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Product Image</h3>
                        </div>
                        <div class="card-body text-center">
                            <?php if (!empty($product['image_path'])): ?>
                                <?php 
                                // Check if the file actually exists
                                $image_full_path = dirname(dirname(__DIR__)) . '/' . $product['image_path'];
                                if (file_exists($image_full_path)): 
                                ?>
                                <img src="<?php echo '../../' . htmlspecialchars($product['image_path']); ?>" alt="Product Image" class="img-fluid" style="max-height: 300px;">
                                <?php else: ?>
                                <div class="text-center text-muted"><i class="fas fa-image fa-5x"></i><p>Image not found</p></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-center text-muted"><i class="fas fa-image fa-5x"></i><p>No image available</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Basic Information</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width: 30%">Product Code</th>
                                    <td><?php echo htmlspecialchars($product['product_code']); ?></td>
                                </tr>
                                <tr>
                                    <th>Barcode</th>
                                    <td><?php echo !empty($product['barcode']) ? htmlspecialchars($product['barcode']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Category</th>
                                    <td><?php echo !empty($product['category_name']) ? htmlspecialchars($product['category_name']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Brand</th>
                                    <td><?php echo !empty($product['brand_name']) ? htmlspecialchars($product['brand_name']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td>
                                        <?php if ($product['is_active'] == 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                            </table>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Description</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($product['description'])): ?>
                                <div class="mb-3">
                                    <h5>Full Description</h5>
                                    <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($product['short_description'])): ?>
                                <div>
                                    <h5>Short Description</h5>
                                    <p><?php echo nl2br(htmlspecialchars($product['short_description'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (empty($product['description']) && empty($product['short_description'])): ?>
                                <p class="text-muted">No description available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Pricing Information</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width: 40%">Cost Price</th>
                                    <td><?php echo formatNumber($conn, $product['cost_price']); ?></td>
                                </tr>
                                <tr>
                                    <th>Selling Price</th>
                                    <td><?php echo formatNumber($conn, $product['selling_price']); ?></td>
                                </tr>
                                <tr>
                                    <th>Minimum Selling Price</th>
                                    <td><?php echo formatNumber($conn, $product['min_selling_price']); ?></td>
                                </tr>
                                <tr>
                                    <th>Wholesale Price</th>
                                    <td><?php echo formatNumber($conn, $product['wholesale_price']); ?></td>
                                </tr>
                                <tr>
                                    <th>Min. Wholesale Quantity</th>
                                    <td><?php echo $product['min_wholesale_qty']; ?></td>
                                </tr>
                                <tr>
                                    <th>Tax Rate</th>
                                    <td><?php echo $product['tax_rate']; ?>%</td>
                                </tr>
                                <tr>
                                    <th>Taxable</th>
                                    <td><?php echo $product['is_taxable'] == 1 ? 'Yes' : 'No'; ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Inventory Information</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width: 40%">Base UOM</th>
                                    <td><?php echo !empty($product['base_uom_name']) ? htmlspecialchars($product['base_uom_name']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Purchase UOM</th>
                                    <td><?php echo !empty($product['purchase_uom_name']) ? htmlspecialchars($product['purchase_uom_name']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Sales UOM</th>
                                    <td><?php echo !empty($product['sales_uom_name']) ? htmlspecialchars($product['sales_uom_name']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Current Stock</th>
                                    <td>
                                        <?php 
                                        $stock_class = 'bg-success';
                                        if ($total_stock <= $product['reorder_point']) {
                                            $stock_class = 'bg-danger';
                                        } else if ($total_stock <= $product['min_stock_level']) {
                                            $stock_class = 'bg-warning';
                                        }
                                        ?>
                                        <span class="badge <?php echo $stock_class; ?>"><?php echo $total_stock; ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Min Stock Level</th>
                                    <td><?php echo $product['min_stock_level']; ?></td>
                                </tr>
                                <tr>
                                    <th>Reorder Point</th>
                                    <td><?php echo $product['reorder_point']; ?></td>
                                </tr>
                                <tr>
                                    <th>Max Stock Level</th>
                                    <td><?php echo $product['max_stock_level']; ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($attributes)): ?>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Product Attributes</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Attribute</th>
                                        <th>Value</th>
                                        <th>Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attributes as $attr): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($attr['attribute_name']); ?></td>
                                        <td>
                                            <?php 
                                            if ($attr['input_type'] == 'boolean') {
                                                echo $attr['attribute_value'] == 1 ? 'Yes' : 'No';
                                            } else {
                                                echo htmlspecialchars($attr['attribute_value']); 
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo ucfirst($attr['input_type']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            

            
            <?php if (!empty($stock)): ?>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Stock by Location</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Location</th>
                                        <th>Quantity</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stock as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['location_name']); ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($item['updated_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="row mb-4">
                <div class="col-md-12">
                    <a href="products.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Products
                    </a>
                    <button type="button" class="btn btn-primary" id="print-product">
                        <i class="fas fa-print"></i> Print Product Details
                    </button>
                </div>
            </div>
            
            <!-- Print-only version -->
            <div id="print-version" style="display: none;">
                <div class="print-header">
                    <h1><?php echo htmlspecialchars($product['product_name']); ?></h1>
                    <p>Product Code: <strong><?php echo htmlspecialchars($product['product_code']); ?></strong></p>
                </div>
                
                <div class="print-layout">
                    <div class="print-column">
                        <!-- Product Image -->
                        <div class="print-section">
                            <?php if (!empty($product['image_path']) && file_exists(dirname(dirname(__DIR__)) . '/' . $product['image_path'])): ?>
                                <img src="<?php echo '../../' . htmlspecialchars($product['image_path']); ?>" alt="Product Image" class="print-image">
                            <?php else: ?>
                                <div class="no-image">No Image Available</div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Basic Info -->
                        <div class="print-section">
                            <h3>Basic Information</h3>
                            <table class="print-table">
                                <tr>
                                    <th>Category</th>
                                    <td><?php echo !empty($product['category_name']) ? htmlspecialchars($product['category_name']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Brand</th>
                                    <td><?php echo !empty($product['brand_name']) ? htmlspecialchars($product['brand_name']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td><?php echo $product['is_active'] == 1 ? 'Active' : 'Inactive'; ?></td>
                                </tr>
                                <tr>
                                    <th>Type</th>
                                    <td>
                                        <?php echo $product['is_service'] == 1 ? 'Service' : 'Physical Product'; ?>
                                        <?php if ($product['has_variants'] == 1): ?> / Has Variants<?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Inventory Info -->
                        <div class="print-section">
                            <h3>Inventory</h3>
                            <table class="print-table">
                                <tr>
                                    <th>Base UOM</th>
                                    <td><?php echo !empty($product['base_uom_name']) ? htmlspecialchars($product['base_uom_name']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Current Stock</th>
                                    <td><?php echo $total_stock; ?></td>
                                </tr>
                                <tr>
                                    <th>Min/Reorder/Max</th>
                                    <td><?php echo $product['min_stock_level']; ?> / <?php echo $product['reorder_point']; ?> / <?php echo $product['max_stock_level']; ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="print-column">
                        <!-- Pricing Info -->
                        <div class="print-section">
                            <h3>Pricing</h3>
                            <table class="print-table">
                                <tr>
                                    <th>Cost Price</th>
                                    <td><?php echo formatNumber($db, $product['cost_price']); ?></td>
                                </tr>
                                <tr>
                                    <th>Selling Price</th>
                                    <td><?php echo formatNumber($db, $product['selling_price']); ?></td>
                                </tr>
                                <tr>
                                    <th>Min Selling Price</th>
                                    <td><?php echo formatNumber($db, $product['min_selling_price']); ?></td>
                                </tr>
                                <tr>
                                    <th>Tax Rate</th>
                                    <td><?php echo $product['tax_rate']; ?>% (<?php echo $product['is_taxable'] == 1 ? 'Taxable' : 'Non-Taxable'; ?>)</td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Description -->
                        <div class="print-section">
                            <h3>Description</h3>
                            <p><?php echo !empty($product['short_description']) ? nl2br(htmlspecialchars($product['short_description'])) : 'No description available'; ?></p>
                        </div>
                        
                        <!-- Attributes -->
                        <?php if (!empty($attributes)): ?>
                        <div class="print-section">
                            <h3>Attributes</h3>
                            <table class="print-table">
                                <?php foreach ($attributes as $attr): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($attr['attribute_name']); ?></th>
                                    <td>
                                        <?php 
                                        if ($attr['input_type'] == 'boolean') {
                                            echo $attr['attribute_value'] == 1 ? 'Yes' : 'No';
                                        } else {
                                            echo htmlspecialchars($attr['attribute_value']); 
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php
                // Get company name from database
                try {
                    $companyQuery = "SELECT company_name FROM company LIMIT 1";
                    $companyStmt = $db->prepare($companyQuery);
                    $companyStmt->execute();
                    $companyInfo = $companyStmt->fetch(PDO::FETCH_ASSOC);
                    $companyName = ($companyInfo && isset($companyInfo['company_name'])) ? $companyInfo['company_name'] : 'Solar Business';
                } catch (PDOException $e) {
                    $companyName = 'Solar Business';
                }
                ?>
                <div class="print-footer">
                    <p>Printed on: <?php echo date('Y-m-d H:i'); ?> | <?php echo htmlspecialchars($companyName); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
// Include footer
include '../../includes/footer.php';
?>

<script>
    // Print functionality with direct approach
    document.getElementById('print-product').addEventListener('click', function() {
        // Create a new window for printing
        var printWindow = window.open('', '_blank', 'width=800,height=600');
        
        // Get the print version content
        var printContent = document.getElementById('print-version').innerHTML;
        
        // Write the content to the new window
        printWindow.document.write('<html><head><title>Product Details</title>');
        printWindow.document.write('<style>');
        printWindow.document.write('body { font-family: Arial, sans-serif; line-height: 1.3; }');
        printWindow.document.write('.print-header { text-align: center; margin-bottom: 15px; border-bottom: 1px solid #000; padding-bottom: 10px; }');
        printWindow.document.write('.print-header h1 { font-size: 18pt; margin: 0 0 5px 0; }');
        printWindow.document.write('.print-header p { font-size: 12pt; margin: 0; }');
        printWindow.document.write('.print-layout { display: flex; width: 100%; }');
        printWindow.document.write('.print-column { width: 50%; padding: 0 10px; box-sizing: border-box; }');
        printWindow.document.write('.print-section { margin-bottom: 15px; }');
        printWindow.document.write('.print-section h3 { font-size: 12pt; margin: 0 0 5px 0; border-bottom: 1px solid #ccc; padding-bottom: 3px; }');
        printWindow.document.write('.print-table { width: 100%; border-collapse: collapse; font-size: 9pt; }');
        printWindow.document.write('.print-table th { width: 40%; text-align: left; padding: 3px; font-weight: bold; vertical-align: top; }');
        printWindow.document.write('.print-table td { padding: 3px; vertical-align: top; }');
        printWindow.document.write('.print-table tr:nth-child(even) { background-color: #f9f9f9; }');
        printWindow.document.write('.print-image { max-width: 200px; max-height: 200px; display: block; margin: 0 auto; }');
        printWindow.document.write('.no-image { text-align: center; border: 1px dashed #ccc; padding: 20px; color: #999; }');
        printWindow.document.write('.print-footer { margin-top: 20px; text-align: center; font-size: 8pt; color: #666; border-top: 1px solid #ccc; padding-top: 5px; }');
        printWindow.document.write('</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write(printContent);
        printWindow.document.write('</body></html>');
        
        // Close the document
        printWindow.document.close();
        
        // Wait for the content to load before printing
        printWindow.onload = function() {
            printWindow.focus();
            printWindow.print();
        };
    });
</script>

<style>
    /* Hide print version in normal view */
    #print-version {
        display: none;
    }
        
        /* Basic page setup */
        body {
            font-size: 10pt !important;
            font-family: Arial, Helvetica, sans-serif !important;
            line-height: 1.3 !important;
            margin: 0 !important;
            padding: 0 !important;
            background: white !important;
            color: black !important;
        }
        
        /* Print header */
        .print-header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #000;
            padding-bottom: 10px;
        }
        
        .print-header h1 {
            font-size: 18pt !important;
            margin: 0 0 5px 0 !important;
        }
        
        .print-header p {
            font-size: 12pt !important;
            margin: 0 !important;
        }
        
        /* Two-column layout */
        .print-layout {
            display: flex !important;
            width: 100% !important;
        }
        
        .print-column {
            width: 50% !important;
            padding: 0 10px !important;
            box-sizing: border-box !important;
        }
        
        /* Section styling */
        .print-section {
            margin-bottom: 15px !important;
        }
        
        .print-section h3 {
            font-size: 12pt !important;
            margin: 0 0 5px 0 !important;
            border-bottom: 1px solid #ccc !important;
            padding-bottom: 3px !important;
        }
        
        /* Table styling */
        .print-table {
            width: 100% !important;
            border-collapse: collapse !important;
            font-size: 9pt !important;
        }
        
        .print-table th {
            width: 40% !important;
            text-align: left !important;
            padding: 3px !important;
            font-weight: bold !important;
            vertical-align: top !important;
        }
        
        .print-table td {
            padding: 3px !important;
            vertical-align: top !important;
        }
        
        .print-table tr:nth-child(even) {
            background-color: #f9f9f9 !important;
        }
        
        /* Image styling */
        .print-image {
            max-width: 200px !important;
            max-height: 200px !important;
            display: block !important;
            margin: 0 auto !important;
        }
        
        .no-image {
            text-align: center !important;
            border: 1px dashed #ccc !important;
            padding: 20px !important;
            color: #999 !important;
        }
        
        /* Footer */
        .print-footer {
            margin-top: 20px !important;
            text-align: center !important;
            font-size: 8pt !important;
            color: #666 !important;
            border-top: 1px solid #ccc !important;
            padding-top: 5px !important;
        }
        
        /* Set page size to portrait for better fit */
        @page {
            size: A4 portrait;
            margin: 1cm;
        }
    }
</style>
