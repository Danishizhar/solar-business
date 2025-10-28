<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Get parent product ID
        $parent_product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : null;
        
        if (!$parent_product_id) {
            throw new Exception("Parent product ID is required");
        }
        
        // Check if parent product exists
        $check_query = "SELECT id, product_name, product_code, has_variants FROM products WHERE id = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $parent_product_id, PDO::PARAM_INT);
        $check_stmt->execute();
        $parent_product = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$parent_product) {
            throw new Exception("Parent product not found");
        }
        
        // Update parent product to have variants
        if (!$parent_product['has_variants']) {
            $update_query = "UPDATE products SET has_variants = 1 WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':id', $parent_product_id, PDO::PARAM_INT);
            $update_stmt->execute();
        }
        
        // Process variants
        $variant_attributes = isset($_POST['variant_attributes']) ? $_POST['variant_attributes'] : [];
        $variant_values = isset($_POST['variant_values']) ? $_POST['variant_values'] : [];
        $variant_combinations = isset($_POST['variant_combinations']) ? $_POST['variant_combinations'] : [];
        $variant_skus = isset($_POST['variant_sku']) ? $_POST['variant_sku'] : [];
        $variant_price_adjs = isset($_POST['variant_price_adj']) ? $_POST['variant_price_adj'] : [];
        $variant_stocks = isset($_POST['variant_stock']) ? $_POST['variant_stock'] : [];
        
        // Check if variant images are enabled
        $variant_images_enabled = isset($_POST['variant_images']) ? (int)$_POST['variant_images'] : 0;
        
        // Create upload directory if it doesn't exist
        $upload_dir = dirname(dirname(dirname(__DIR__))) . '/uploads/products/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Get user ID for tracking
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        
        // Get parent product details to copy to variants
        $parent_query = "SELECT * FROM products WHERE id = :id";
        $parent_stmt = $db->prepare($parent_query);
        $parent_stmt->bindParam(':id', $parent_product_id, PDO::PARAM_INT);
        $parent_stmt->execute();
        $parent_details = $parent_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Create variants
        $created_variants = [];
        
        foreach ($variant_combinations as $index => $combination) {
            // Generate variant name
            $variant_name = $parent_details['product_name'] . ' - ' . $combination;
            
            // Generate SKU if not provided
            $sku = !empty($variant_skus[$index]) ? $variant_skus[$index] : null;
            if (empty($sku)) {
                // Get product code prefix from settings
                $settings_query = "SELECT setting_value FROM inventory_settings WHERE setting_key = 'product_code_prefix'";
                $settings_stmt = $db->prepare($settings_query);
                $settings_stmt->execute();
                $prefix = $settings_stmt->fetchColumn() ?: 'P';
                
                // Get the highest product code
                $max_code_query = "SELECT MAX(CAST(SUBSTRING(product_code, LENGTH(:prefix) + 1) AS UNSIGNED)) as max_code 
                                  FROM products WHERE product_code LIKE CONCAT(:prefix, '%')";
                $max_code_stmt = $db->prepare($max_code_query);
                $max_code_stmt->bindParam(':prefix', $prefix, PDO::PARAM_STR);
                $max_code_stmt->execute();
                $max_code = $max_code_stmt->fetchColumn() ?: 0;
                
                // Generate new product code
                $sku = $prefix . str_pad($max_code + 1, 5, '0', STR_PAD_LEFT) . '-' . ($index + 1);
            }
            
            // Calculate price
            $price_adj = !empty($variant_price_adjs[$index]) ? (float)$variant_price_adjs[$index] : 0;
            $selling_price = $parent_details['selling_price'] + $price_adj;
            
            // Insert variant
            $query = "INSERT INTO products (
                     product_name, product_code, category_id, brand_id, description, 
                     base_uom_id, purchase_uom_id, sales_uom_id, cost_price, selling_price,
                     min_selling_price, tax_rate, is_taxable, min_stock_level, reorder_point,
                     max_stock_level, is_service, is_active, has_variants, is_variant,
                     parent_product_id, status, created_by, updated_by, created_at, updated_at
                     ) VALUES (
                     :product_name, :product_code, :category_id, :brand_id, :description,
                     :base_uom_id, :purchase_uom_id, :sales_uom_id, :cost_price, :selling_price,
                     :min_selling_price, :tax_rate, :is_taxable, :min_stock_level, :reorder_point,
                     :max_stock_level, :is_service, :is_active, 0, 1,
                     :parent_product_id, 1, :created_by, :updated_by, NOW(), NOW()
                     )";
            
            $stmt = $db->prepare($query);
            
            // Bind parameters
            $stmt->bindParam(':product_name', $variant_name, PDO::PARAM_STR);
            $stmt->bindParam(':product_code', $sku, PDO::PARAM_STR);
            $stmt->bindParam(':category_id', $parent_details['category_id'], PDO::PARAM_INT);
            $stmt->bindParam(':brand_id', $parent_details['brand_id'], PDO::PARAM_INT);
            $stmt->bindParam(':description', $parent_details['description'], PDO::PARAM_STR);
            $stmt->bindParam(':base_uom_id', $parent_details['base_uom_id'], PDO::PARAM_INT);
            $stmt->bindParam(':purchase_uom_id', $parent_details['purchase_uom_id'], PDO::PARAM_INT);
            $stmt->bindParam(':sales_uom_id', $parent_details['sales_uom_id'], PDO::PARAM_INT);
            $stmt->bindParam(':cost_price', $parent_details['cost_price'], PDO::PARAM_STR);
            $stmt->bindParam(':selling_price', $selling_price, PDO::PARAM_STR);
            $stmt->bindParam(':min_selling_price', $parent_details['min_selling_price'], PDO::PARAM_STR);
            $stmt->bindParam(':tax_rate', $parent_details['tax_rate'], PDO::PARAM_STR);
            $stmt->bindParam(':is_taxable', $parent_details['is_taxable'], PDO::PARAM_INT);
            $stmt->bindParam(':min_stock_level', $parent_details['min_stock_level'], PDO::PARAM_STR);
            $stmt->bindParam(':reorder_point', $parent_details['reorder_point'], PDO::PARAM_STR);
            $stmt->bindParam(':max_stock_level', $parent_details['max_stock_level'], PDO::PARAM_STR);
            $stmt->bindParam(':is_service', $parent_details['is_service'], PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $parent_details['is_active'], PDO::PARAM_INT);
            $stmt->bindParam(':parent_product_id', $parent_product_id, PDO::PARAM_INT);
            $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
            
            $stmt->execute();
            
            // Get the new variant ID
            $variant_id = $db->lastInsertId();
            $created_variants[] = $variant_id;
            
            // Process variant image if uploaded
            $image_path = null;
            if (isset($_FILES['variant_image']) && isset($_FILES['variant_image']['name'][$index]) && $_FILES['variant_image']['error'][$index] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['variant_image']['tmp_name'][$index];
                $file_name = time() . '_' . basename($_FILES['variant_image']['name'][$index]);
                $target_file = $upload_dir . $file_name;
                
                // Check if image file is a valid image
                $check = getimagesize($file_tmp);
                if ($check !== false) {
                    // Upload file
                    if (move_uploaded_file($file_tmp, $target_file)) {
                        $image_path = 'uploads/products/' . $file_name;
                        
                        // Update variant with image path
                        $update_image_query = "UPDATE products SET image_path = :image_path WHERE id = :id";
                        $update_image_stmt = $db->prepare($update_image_query);
                        $update_image_stmt->bindParam(':image_path', $image_path, PDO::PARAM_STR);
                        $update_image_stmt->bindParam(':id', $variant_id, PDO::PARAM_INT);
                        $update_image_stmt->execute();
                        
                        error_log("Uploaded variant image: $image_path for variant ID: $variant_id");
                    } else {
                        error_log("Failed to upload variant image for variant ID: $variant_id");
                    }
                } else {
                    error_log("Invalid image file for variant ID: $variant_id");
                }
            }
            
            // Save variant attributes
            $variant_parts = explode(' | ', $combination);
            $attr_names = $variant_attributes;
            
            for ($i = 0; $i < count($attr_names); $i++) {
                if (isset($variant_parts[$i])) {
                    // Find attribute ID by name
                    $attr_query = "SELECT id FROM attribute_definitions WHERE attribute_name = :name AND is_variant = 1 AND status = 1";
                    $attr_stmt = $db->prepare($attr_query);
                    $attr_stmt->bindParam(':name', $attr_names[$i], PDO::PARAM_STR);
                    $attr_stmt->execute();
                    $attr_id = $attr_stmt->fetchColumn();
                    
                    if ($attr_id) {
                        // Save attribute value
                        $attr_val_query = "INSERT INTO product_attributes (
                                         product_id, attribute_id, attribute_value,
                                         created_at, updated_at, created_by, updated_by
                                         ) VALUES (
                                         :product_id, :attribute_id, :attribute_value,
                                         NOW(), NOW(), :created_by, :updated_by
                                         )";
                        
                        $attr_val_stmt = $db->prepare($attr_val_query);
                        $attr_val_stmt->bindParam(':product_id', $variant_id, PDO::PARAM_INT);
                        $attr_val_stmt->bindParam(':attribute_id', $attr_id, PDO::PARAM_INT);
                        $attr_val_stmt->bindParam(':attribute_value', $variant_parts[$i], PDO::PARAM_STR);
                        $attr_val_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
                        $attr_val_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
                        
                        $attr_val_stmt->execute();
                    }
                }
            }
            
            // Add initial stock if provided
            $initial_stock = !empty($variant_stocks[$index]) ? (float)$variant_stocks[$index] : 0;
            
            if ($initial_stock > 0) {
                // Get default location
                $loc_query = "SELECT id FROM inventory_locations WHERE is_default = 1 AND status = 1 LIMIT 1";
                $loc_stmt = $db->prepare($loc_query);
                $loc_stmt->execute();
                $location_id = $loc_stmt->fetchColumn() ?: 1; // Default to ID 1 if no default location
                
                // Add stock
                $stock_query = "INSERT INTO inventory_stock (
                              product_id, location_id, quantity_on_hand, status,
                              created_at, updated_at, created_by, updated_by
                              ) VALUES (
                              :product_id, :location_id, :quantity, 1,
                              NOW(), NOW(), :created_by, :updated_by
                              )";
                
                $stock_stmt = $db->prepare($stock_query);
                $stock_stmt->bindParam(':product_id', $variant_id, PDO::PARAM_INT);
                $stock_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
                $stock_stmt->bindParam(':quantity', $initial_stock, PDO::PARAM_STR);
                $stock_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
                $stock_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
                
                $stock_stmt->execute();
                
                // Record stock transaction
                $trans_query = "INSERT INTO inventory_transactions (
                              transaction_type, product_id, location_id, quantity,
                              reference_type, reference_id, notes, status,
                              created_at, updated_at, created_by, updated_by
                              ) VALUES (
                              'initial', :product_id, :location_id, :quantity,
                              'system', 0, 'Initial variant stock', 1,
                              NOW(), NOW(), :created_by, :updated_by
                              )";
                
                $trans_stmt = $db->prepare($trans_query);
                $trans_stmt->bindParam(':product_id', $variant_id, PDO::PARAM_INT);
                $trans_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
                $trans_stmt->bindParam(':quantity', $initial_stock, PDO::PARAM_STR);
                $trans_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
                $trans_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
                
                $trans_stmt->execute();
            }
        }
        
        $db->commit();
        
        // Return success response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Variants created successfully',
            'count' => count($created_variants),
            'variants' => $created_variants
        ]);
        
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        
        error_log("Database error in save_variants.php: " . $e->getMessage());
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        
    } catch (Exception $e) {
        // Only roll back if there's an active transaction
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        
        error_log("Error in save_variants.php: " . $e->getMessage());
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
} else {
    // If not a POST request, return error
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request method'
    ]);
}
