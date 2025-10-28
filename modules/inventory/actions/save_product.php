<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create a debug log file
file_put_contents('../../../debug_log.txt', 'Debug log started at ' . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once '../../../config/database.php';
require_once '../includes/inventory_helpers.php';
$database = new Database();
$db = $database->getConnection();

// Get product settings
$auto_generate_product_code = getInventorySetting($db, 'auto_generate_product_code', '0') == '1';
$default_min_stock_level = getInventorySetting($db, 'default_minimum_stock_level', '5');
$enable_batch_tracking = getInventorySetting($db, 'enable_batch_tracking', '0') == '1';
$enable_expiry_tracking = getInventorySetting($db, 'enable_expiry_tracking', '0') == '1';
$enable_serial_tracking = getInventorySetting($db, 'enable_serial_tracking', '0') == '1';
$enable_variants = getInventorySetting($db, 'enable_product_variants', '0') == '1';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    if (empty($_POST['product_name'])) {
        $_SESSION['error_message'] = "Product name is required";
        header("Location: ../products.php");
        exit();
    }
    
    // Check if this is an update or a new product
    $is_update = !empty($_POST['product_id']);
    $product_id = $is_update ? intval($_POST['product_id']) : null;
    
    try {
        file_put_contents('../../../debug_log.txt', 'Starting transaction' . "\n", FILE_APPEND);
        $db->beginTransaction();
        
        // Basic product information
        $product_name = trim($_POST['product_name']);
        $product_code = !empty($_POST['product_code']) ? trim($_POST['product_code']) : null;
        $barcode = !empty($_POST['barcode']) ? trim($_POST['barcode']) : null;
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $brand_id = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
        $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
        $short_description = !empty($_POST['short_description']) ? trim($_POST['short_description']) : null;
        
        // UOM information
        $base_uom_id = !empty($_POST['base_uom_id']) ? (int)$_POST['base_uom_id'] : null;
        $purchase_uom_id = !empty($_POST['purchase_uom_id']) ? (int)$_POST['purchase_uom_id'] : null;
        $sales_uom_id = !empty($_POST['sales_uom_id']) ? (int)$_POST['sales_uom_id'] : null;
        
        // Pricing information
        $cost_price = !empty($_POST['cost_price']) ? (float)$_POST['cost_price'] : 0;
        $selling_price = !empty($_POST['selling_price']) ? (float)$_POST['selling_price'] : 0;
        $min_selling_price = !empty($_POST['min_selling_price']) ? (float)$_POST['min_selling_price'] : 0;
        $wholesale_price = !empty($_POST['wholesale_price']) ? (float)$_POST['wholesale_price'] : 0;
        $min_wholesale_qty = !empty($_POST['min_wholesale_qty']) ? (float)$_POST['min_wholesale_qty'] : 0;
        $tax_rate = !empty($_POST['tax_rate']) ? (float)$_POST['tax_rate'] : 0;
        $is_taxable = isset($_POST['is_taxable']) ? 1 : 0;
        
        // Stock information
        $min_stock_level = !empty($_POST['min_stock_level']) ? (float)$_POST['min_stock_level'] : (float)$default_min_stock_level;
        $reorder_point = !empty($_POST['reorder_point']) ? (float)$_POST['reorder_point'] : 0;
        $max_stock_level = !empty($_POST['max_stock_level']) ? (float)$_POST['max_stock_level'] : 0;
        
        // Product type
        $is_service = isset($_POST['is_service']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Variant information - only if variants are enabled in settings
        $has_variants = $enable_variants && isset($_POST['has_variants']) ? 1 : 0;
        $is_variant = $enable_variants && isset($_POST['is_variant']) ? 1 : 0;
        $parent_product_id = ($enable_variants && !empty($_POST['parent_product_id'])) ? (int)$_POST['parent_product_id'] : null;
        
        // Physical attributes
        $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
        $length = !empty($_POST['length']) ? (float)$_POST['length'] : null;
        $width = !empty($_POST['width']) ? (float)$_POST['width'] : null;
        $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;
        
        // Auto-generate product code if enabled and not provided
        if (empty($product_code) && $auto_generate_product_code) {
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
            $product_code = $prefix . str_pad($max_code + 1, 5, '0', STR_PAD_LEFT);
            file_put_contents('../../../debug_log.txt', 'Auto-generated product code: ' . $product_code . "\n", FILE_APPEND);
        } else if (empty($product_code) && !$auto_generate_product_code) {
            // If auto-generation is disabled and no code is provided, use a timestamp-based code
            $product_code = 'PROD_' . time();
            file_put_contents('../../../debug_log.txt', 'Timestamp-based product code: ' . $product_code . "\n", FILE_APPEND);
        }
        
        // Check if product code already exists (only for new products)
        if (!$is_update) {
            $check_query = "SELECT id FROM products WHERE product_code = :product_code";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':product_code', $product_code, PDO::PARAM_STR);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Product code already exists. Please use a different code.");
            }
        } else {
            // For updates, check if the product code exists but belongs to a different product
            $check_query = "SELECT id FROM products WHERE product_code = :product_code AND id != :product_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':product_code', $product_code, PDO::PARAM_STR);
            $check_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Product code already exists for another product. Please use a different code.");
            }
        }
        
        // Handle image upload
        $image_path = null;
        file_put_contents('../../../debug_log.txt', 'Image upload check: ' . (isset($_FILES['product_image']) ? 'yes' : 'no') . "\n", FILE_APPEND);
        if (isset($_FILES['product_image'])) {
            file_put_contents('../../../debug_log.txt', 'Image error: ' . $_FILES['product_image']['error'] . "\n", FILE_APPEND);
            file_put_contents('../../../debug_log.txt', 'Image name: ' . $_FILES['product_image']['name'] . "\n", FILE_APPEND);
        }
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0 && !empty($_FILES['product_image']['name'])) {
            // Create absolute path for uploads
            $upload_dir = dirname(dirname(dirname(__DIR__))) . '/uploads/products/';
            file_put_contents('../../../debug_log.txt', 'Upload directory: ' . $upload_dir . "\n", FILE_APPEND);
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0777, true)) {
                    throw new Exception("Failed to create upload directory.");
                }
            }
            
            $file_name = time() . '_' . basename($_FILES['product_image']['name']);
            $target_file = $upload_dir . $file_name;
            
            // Check if image file is a valid image
            $check = getimagesize($_FILES['product_image']['tmp_name']);
            if ($check === false) {
                throw new Exception("File is not a valid image.");
            }
            
            // Check file size (max 5MB)
            if ($_FILES['product_image']['size'] > 5000000) {
                throw new Exception("File is too large. Maximum size is 5MB.");
            }
            
            // Allow only certain file formats
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            file_put_contents('../../../debug_log.txt', 'Image type: ' . $_FILES['product_image']['type'] . "\n", FILE_APPEND);
            if (!in_array($_FILES['product_image']['type'], $allowed_types)) {
                throw new Exception("Only JPG, PNG, GIF, and WebP files are allowed.");
            }
            
            file_put_contents('../../../debug_log.txt', 'Attempting to move file to: ' . $target_file . "\n", FILE_APPEND);
            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $target_file)) {
                $image_path = 'uploads/products/' . $file_name;
                file_put_contents('../../../debug_log.txt', 'Image path saved to DB: ' . $image_path . "\n", FILE_APPEND);
                file_put_contents('../../../debug_log.txt', 'Image uploaded successfully. Path: ' . $image_path . "\n", FILE_APPEND);
            } else {
                file_put_contents('../../../debug_log.txt', 'Failed to upload image. Error: ' . error_get_last()['message'] . "\n", FILE_APPEND);
                throw new Exception("Failed to upload image.");
            }
        }
        
        // Try to add image_path column if it doesn't exist
        try {
            $check_column = $db->query("SHOW COLUMNS FROM products LIKE 'image_path'");
            if ($check_column->rowCount() == 0) {
                $alter_query = "ALTER TABLE products ADD COLUMN image_path VARCHAR(255) DEFAULT NULL";
                $db->exec($alter_query);
            }
        } catch (PDOException $e) {
            error_log("Error checking/adding image_path column: " . $e->getMessage());
        }
        
        // Debug the values before insertion
        file_put_contents('../../../debug_log.txt', 'Product values: ' . json_encode([
            'product_name' => $product_name,
            'product_code' => $product_code,
            'barcode' => $barcode,
            'category_id' => $category_id,
            'brand_id' => $brand_id,
            'description' => $description,
            'short_description' => $short_description,
            'base_uom_id' => $base_uom_id,
            'purchase_uom_id' => $purchase_uom_id,
            'sales_uom_id' => $sales_uom_id,
            'cost_price' => $cost_price,
            'selling_price' => $selling_price,
            'min_selling_price' => $min_selling_price,
            'tax_rate' => $tax_rate,
            'is_taxable' => $is_taxable,
            'min_stock_level' => $min_stock_level,
            'reorder_point' => $reorder_point,
            'max_stock_level' => $max_stock_level,
            'is_service' => $is_service,
            'is_active' => $is_active,
            'has_variants' => $has_variants,
            'is_variant' => $is_variant,
            'parent_product_id' => $parent_product_id,
            'image_path' => $image_path,
            'weight' => $weight,
            'length' => $length,
            'width' => $width,
            'height' => $height
        ]) . "\n", FILE_APPEND);
        
        // Clear the debug log and start fresh
        file_put_contents('../../../debug_log.txt', 'Starting product save at ' . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        
        // Get user ID for tracking
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        
        // Get tracking options
        $track_batches = isset($_POST['track_batches']) ? 1 : 0;
        $track_expiry = isset($_POST['track_expiry']) ? 1 : 0;
        $track_serial = isset($_POST['track_serial']) ? 1 : 0;
        $enable_stock_alerts = isset($_POST['enable_stock_alerts']) ? 1 : 0;
        
        file_put_contents('../../../debug_log.txt', 'Tracking options: ' . json_encode([
            'track_batches' => $track_batches,
            'track_expiry' => $track_expiry,
            'track_serial' => $track_serial,
            'enable_stock_alerts' => $enable_stock_alerts
        ]) . "\n", FILE_APPEND);
        
        if ($is_update) {
            // Update existing product
            $query = "UPDATE products SET 
                     product_name = :product_name, 
                     product_code = :product_code, 
                     barcode = :barcode, 
                     category_id = :category_id, 
                     brand_id = :brand_id, 
                     description = :description, 
                     short_description = :short_description,
                     base_uom_id = :base_uom_id, 
                     purchase_uom_id = :purchase_uom_id, 
                     sales_uom_id = :sales_uom_id, 
                     cost_price = :cost_price, 
                     selling_price = :selling_price, 
                     min_selling_price = :min_selling_price,
                     wholesale_price = :wholesale_price,
                     min_wholesale_qty = :min_wholesale_qty,
                     tax_rate = :tax_rate, 
                     is_taxable = :is_taxable, 
                     min_stock_level = :min_stock_level, 
                     reorder_point = :reorder_point, 
                     max_stock_level = :max_stock_level, 
                     is_service = :is_service,
                     is_active = :is_active, 
                     has_variants = :has_variants, 
                     is_variant = :is_variant, 
                     parent_product_id = :parent_product_id, 
                     weight = :weight, 
                     length = :length,
                     width = :width, 
                     height = :height, 
                     track_batches = :track_batches,
                     track_expiry = :track_expiry,
                     track_serial = :track_serial,
                     enable_stock_alerts = :enable_stock_alerts,
                     updated_at = NOW(), 
                     updated_by = :updated_by"
                     . ($image_path ? ", image_path = :image_path" : "") . 
                     " WHERE id = :product_id";
            
            file_put_contents('../../../debug_log.txt', 'Update SQL Query: ' . $query . "\n", FILE_APPEND);
            $stmt = $db->prepare($query);
            
            // Bind parameters for update
            $params = [
                ':product_name' => $product_name ?? '',
                ':product_code' => $product_code ?? '',
                ':barcode' => $barcode ?? null,
                ':category_id' => $category_id ?? null,
                ':brand_id' => $brand_id ?? null,
                ':description' => $description ?? null,
                ':short_description' => $short_description ?? null,
                ':base_uom_id' => $base_uom_id ?? null,
                ':purchase_uom_id' => $purchase_uom_id ?? null,
                ':sales_uom_id' => $sales_uom_id ?? null,
                ':cost_price' => $cost_price ?? 0,
                ':selling_price' => $selling_price ?? 0,
                ':min_selling_price' => $min_selling_price ?? 0,
                ':wholesale_price' => $wholesale_price ?? 0,
                ':min_wholesale_qty' => $min_wholesale_qty ?? 0,
                ':tax_rate' => $tax_rate ?? 0,
                ':is_taxable' => $is_taxable ?? 0,
                ':min_stock_level' => $min_stock_level ?? 0,
                ':reorder_point' => $reorder_point ?? 0,
                ':max_stock_level' => $max_stock_level ?? 0,
                ':is_service' => $is_service ?? 0,
                ':is_active' => $is_active ?? 1,
                ':has_variants' => $has_variants ?? 0,
                ':is_variant' => $is_variant ?? 0,
                ':parent_product_id' => $parent_product_id ?? null,
                ':weight' => $weight ?? null,
                ':length' => $length ?? null,
                ':width' => $width ?? null,
                ':height' => $height ?? null,
                ':track_batches' => $track_batches,
                ':track_expiry' => $track_expiry,
                ':track_serial' => $track_serial,
                ':enable_stock_alerts' => $enable_stock_alerts,
                ':updated_by' => $user_id,
                ':product_id' => $product_id
            ];
            
            // Only add image_path if it's set
            if ($image_path) {
                $params[':image_path'] = $image_path;
            }
        } else {
            // Insert new product
            $query = "INSERT INTO products (
                     product_name, product_code, barcode, category_id, brand_id, description, short_description,
                     base_uom_id, purchase_uom_id, sales_uom_id, cost_price, selling_price, min_selling_price,
                     tax_rate, is_taxable, min_stock_level, reorder_point, max_stock_level, is_service,
                     is_active, has_variants, is_variant, parent_product_id, image_path, weight, length,
                     width, height, status, created_by, updated_by
                     ) VALUES (
                     :product_name, :product_code, :barcode, :category_id, :brand_id, :description, :short_description,
                     :base_uom_id, :purchase_uom_id, :sales_uom_id, :cost_price, :selling_price, :min_selling_price,
                     :tax_rate, :is_taxable, :min_stock_level, :reorder_point, :max_stock_level, :is_service,
                     :is_active, :has_variants, :is_variant, :parent_product_id, :image_path, :weight, :length,
                     :width, :height, :status, :created_by, :updated_by
                     )";
            
            file_put_contents('../../../debug_log.txt', 'Insert SQL Query: ' . $query . "\n", FILE_APPEND);
            $stmt = $db->prepare($query);
            
            // Bind parameters for insert
            $params = [
                ':product_name' => $product_name ?? '',
                ':product_code' => $product_code ?? '',
                ':barcode' => $barcode ?? null,
                ':category_id' => $category_id ?? null,
                ':brand_id' => $brand_id ?? null,
                ':description' => $description ?? null,
                ':short_description' => $short_description ?? null,
                ':base_uom_id' => $base_uom_id ?? null,
                ':purchase_uom_id' => $purchase_uom_id ?? null,
                ':sales_uom_id' => $sales_uom_id ?? null,
                ':cost_price' => $cost_price ?? 0,
                ':selling_price' => $selling_price ?? 0,
                ':min_selling_price' => $min_selling_price ?? 0,
                ':tax_rate' => $tax_rate ?? 0,
                ':is_taxable' => $is_taxable ?? 0,
                ':min_stock_level' => $min_stock_level ?? 0,
                ':reorder_point' => $reorder_point ?? 0,
                ':max_stock_level' => $max_stock_level ?? 0,
                ':is_service' => $is_service ?? 0,
                ':is_active' => $is_active ?? 1,
                ':has_variants' => $has_variants ?? 0,
                ':is_variant' => $is_variant ?? 0,
                ':parent_product_id' => $parent_product_id ?? null,
                ':image_path' => $image_path ?? null,
                ':weight' => $weight ?? null,
                ':length' => $length ?? null,
                ':width' => $width ?? null,
                ':height' => $height ?? null,
                ':status' => 1,
                ':created_by' => $user_id,
                ':updated_by' => $user_id
            ];
        }
        
        // Log the parameters for debugging
        file_put_contents('../../../debug_log.txt', 'Parameters: ' . json_encode($params) . "\n", FILE_APPEND);
        
        // Bind all parameters at once
        foreach ($params as $param => $value) {
            if ($value === null) {
                $stmt->bindValue($param, $value, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
        }
        
        // Log all parameters for debugging
        file_put_contents('../../../debug_log.txt', 'All parameters bound successfully' . "\n", FILE_APPEND);
        
        try {
            $stmt->execute();
            file_put_contents('../../../debug_log.txt', 'SQL executed successfully' . "\n", FILE_APPEND);
            
            if (!$is_update) {
                // Get the new product ID for new products
                $product_id = $db->lastInsertId();
                file_put_contents('../../../debug_log.txt', 'New product ID: ' . $product_id . "\n", FILE_APPEND);
            }
            
        } catch (PDOException $e) {
            file_put_contents('../../../debug_log.txt', 'SQL execution error: ' . $e->getMessage() . "\n", FILE_APPEND);
            throw $e; // Re-throw to be caught by the outer try-catch
        }
        
        // Save product attributes if any
        if (isset($_POST['attributes']) && is_array($_POST['attributes'])) {
            file_put_contents('../../../debug_log.txt', 'Processing attributes: ' . count($_POST['attributes']) . "\n", FILE_APPEND);
            
            // For updates, delete existing attributes first
            if ($is_update) {
                $delete_attr_query = "DELETE FROM product_attributes WHERE product_id = :product_id";
                $delete_attr_stmt = $db->prepare($delete_attr_query);
                $delete_attr_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
                $delete_attr_stmt->execute();
                file_put_contents('../../../debug_log.txt', 'Deleted existing attributes for product ID: ' . $product_id . "\n", FILE_APPEND);
            }
            
            foreach ($_POST['attributes'] as $attribute_id => $attribute_value) {
                // Skip empty values unless it's a boolean attribute
                if (empty($attribute_value) && $attribute_value !== '0') {
                    continue;
                }
                
                // Check if attribute exists
                $attr_check_query = "SELECT id, input_type FROM attribute_definitions WHERE id = :id AND status = 1";
                $attr_check_stmt = $db->prepare($attr_check_query);
                $attr_check_stmt->bindParam(':id', $attribute_id, PDO::PARAM_INT);
                $attr_check_stmt->execute();
                $attribute = $attr_check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$attribute) {
                    continue; // Skip if attribute doesn't exist
                }
                
                // Format value based on input type
                if ($attribute['input_type'] === 'multiselect' && is_array($attribute_value)) {
                    $attribute_value = json_encode($attribute_value);
                }
                
                file_put_contents('../../../debug_log.txt', 'Saving attribute: ' . $attribute_id . ' with value: ' . $attribute_value . "\n", FILE_APPEND);
                
                // Insert attribute value
                $attr_query = "INSERT INTO product_attributes (
                             product_id, attribute_id, attribute_value,
                             created_at, updated_at, created_by, updated_by
                             ) VALUES (
                             :product_id, :attribute_id, :attribute_value,
                             NOW(), NOW(), :created_by, :updated_by
                             )";
                
                $attr_stmt = $db->prepare($attr_query);
                $attr_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
                $attr_stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
                $attr_stmt->bindParam(':attribute_value', $attribute_value, PDO::PARAM_STR);
                $attr_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
                $attr_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
                
                $attr_stmt->execute();
            }
        }
        
        $db->commit();
        file_put_contents('../../../debug_log.txt', 'Transaction committed' . "\n", FILE_APPEND);
        $_SESSION['success_message'] = "Product added successfully";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        $error_msg = "Database error in save_product.php: " . $e->getMessage();
        error_log($error_msg);
        file_put_contents('../../../debug_log.txt', $error_msg . "\n", FILE_APPEND);
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        $error_msg = "Error in save_product.php: " . $e->getMessage();
        error_log($error_msg);
        file_put_contents('../../../debug_log.txt', $error_msg . "\n", FILE_APPEND);
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../products.php");
    exit();
} else {
    // If not a POST request, redirect to products page
    header("Location: ../products.php");
    exit();
}
