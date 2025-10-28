<?php
session_start();
require_once "../../../config/database.php";
require_once "../includes/inventory_helpers.php";

// Clear debug log and start fresh
file_put_contents("../../../debug_log.txt", "Debug log started at " . date("Y-m-d H:i:s") . "\n");

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

try {
    // First try to fix the foreign key constraint issue before starting the transaction
    try {
        // Check if the foreign key constraint exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                            WHERE CONSTRAINT_SCHEMA = 'sbm' 
                            AND TABLE_NAME = 'products' 
                            AND CONSTRAINT_NAME = 'products_ibfk_1'");
        $stmt->execute();
        $constraintExists = $stmt->fetchColumn() > 0;
        
        if ($constraintExists) {
            // Drop the foreign key constraint
            file_put_contents("../../../debug_log.txt", "Attempting to drop foreign key constraint products_ibfk_1\n", FILE_APPEND);
            try {
                $stmt = $db->prepare("ALTER TABLE products DROP FOREIGN KEY products_ibfk_1");
                $stmt->execute();
                file_put_contents("../../../debug_log.txt", "Successfully dropped foreign key constraint\n", FILE_APPEND);
                
                // Add a new foreign key constraint that references product_categories instead of categories
                $stmt = $db->prepare("ALTER TABLE products ADD CONSTRAINT products_category_fk 
                                    FOREIGN KEY (category_id) REFERENCES product_categories(id)");
                $stmt->execute();
                file_put_contents("../../../debug_log.txt", "Successfully added new foreign key constraint to product_categories\n", FILE_APPEND);
            } catch (PDOException $e) {
                file_put_contents("../../../debug_log.txt", "Error modifying foreign key constraint: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        } else {
            file_put_contents("../../../debug_log.txt", "Foreign key constraint products_ibfk_1 does not exist\n", FILE_APPEND);
        }
    } catch (PDOException $e) {
        file_put_contents("../../../debug_log.txt", "Error checking foreign key constraints: " . $e->getMessage() . "\n", FILE_APPEND);
    }
    
    // Now start the transaction for the product insert
    file_put_contents("../../../debug_log.txt", "Starting transaction\n", FILE_APPEND);
    $db->beginTransaction();
    
    // Get user ID for tracking
    $user_id = isset($_SESSION["user_id"]) ? $_SESSION["user_id"] : null;
    
    // Get inventory settings
    $auto_generate_product_code = isset($_POST['auto_generate_product_code']) ? $_POST['auto_generate_product_code'] == '1' : (getInventorySetting($db, 'auto_generate_product_code', '0') == '1');
    $product_code_prefix = isset($_POST['product_code_prefix']) ? $_POST['product_code_prefix'] : getInventorySetting($db, 'product_code_prefix', 'P');
    $require_base_uom = getInventorySetting($db, 'require_base_uom', '0') == '1';
    
    file_put_contents("../../../debug_log.txt", "Auto generate product code: " . ($auto_generate_product_code ? 'Yes' : 'No') . "\n", FILE_APPEND);
    file_put_contents("../../../debug_log.txt", "Product code prefix: " . $product_code_prefix . "\n", FILE_APPEND);
    
    // Get form data
    $product_name = isset($_POST["product_name"]) ? trim($_POST["product_name"]) : null;
    $product_code = isset($_POST["product_code"]) ? trim($_POST["product_code"]) : null;
    $barcode = isset($_POST["barcode"]) ? trim($_POST["barcode"]) : null;
    $category_id = isset($_POST["category_id"]) && !empty($_POST["category_id"]) ? (int)$_POST["category_id"] : null;
    $brand_id = isset($_POST["brand_id"]) && !empty($_POST["brand_id"]) ? (int)$_POST["brand_id"] : null;
    $description = isset($_POST["description"]) ? trim($_POST["description"]) : null;
    $short_description = isset($_POST["short_description"]) ? trim($_POST["short_description"]) : null;
    $base_uom_id = isset($_POST["base_uom_id"]) && !empty($_POST["base_uom_id"]) ? (int)$_POST["base_uom_id"] : null;
    $purchase_uom_id = isset($_POST["purchase_uom_id"]) && !empty($_POST["purchase_uom_id"]) ? (int)$_POST["purchase_uom_id"] : null;
    $sales_uom_id = isset($_POST["sales_uom_id"]) && !empty($_POST["sales_uom_id"]) ? (int)$_POST["sales_uom_id"] : null;
    
    // Auto-generate product code if enabled and product code is empty
    if ($auto_generate_product_code && empty($product_code)) {
        try {
            $product_code = generateProductCode($db, $product_code_prefix);
            file_put_contents("../../../debug_log.txt", "Auto-generated product code using helper: $product_code\n", FILE_APPEND);
        } catch (Exception $e) {
            // Fallback method if helper function fails
            file_put_contents("../../../debug_log.txt", "Helper function failed, using fallback method: " . $e->getMessage() . "\n", FILE_APPEND);
            
            // Get the last product code with the same prefix
            $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING(product_code, ?) AS UNSIGNED)) as last_num FROM products WHERE product_code LIKE ?");
            $prefix_length = strlen($product_code_prefix) + 1;
            $stmt->bindParam(1, $prefix_length, PDO::PARAM_INT);
            $search_pattern = $product_code_prefix . '%';
            $stmt->bindParam(2, $search_pattern, PDO::PARAM_STR);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $next_num = 1;
            if ($result && $result['last_num']) {
                $next_num = (int)$result['last_num'] + 1;
            }
            
            $product_code = $product_code_prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
            file_put_contents("../../../debug_log.txt", "Auto-generated product code fallback: $product_code\n", FILE_APPEND);
        }
    }
    $cost_price = isset($_POST["cost_price"]) && !empty($_POST["cost_price"]) ? (float)$_POST["cost_price"] : 0;
    $selling_price = isset($_POST["selling_price"]) && !empty($_POST["selling_price"]) ? (float)$_POST["selling_price"] : 0;
    $min_selling_price = isset($_POST["min_selling_price"]) && !empty($_POST["min_selling_price"]) ? (float)$_POST["min_selling_price"] : 0;
    $wholesale_price = isset($_POST["wholesale_price"]) && !empty($_POST["wholesale_price"]) ? (float)$_POST["wholesale_price"] : 0;
    $min_wholesale_qty = isset($_POST["min_wholesale_qty"]) && !empty($_POST["min_wholesale_qty"]) ? (int)$_POST["min_wholesale_qty"] : 0;
    $tax_rate = isset($_POST["tax_rate"]) && !empty($_POST["tax_rate"]) ? (float)$_POST["tax_rate"] : 0;
    // Discount percent field removed as per request - will be handled separately
    $is_taxable = isset($_POST["is_taxable"]) ? 1 : 0;
    $track_batches = isset($_POST["track_batches"]) ? 1 : 0;
    $track_expiry = isset($_POST["track_expiry"]) ? 1 : 0;
    $track_serial = isset($_POST["track_serial"]) ? 1 : 0;
    $enable_stock_alerts = isset($_POST["enable_stock_alerts"]) ? 1 : 0;
    $min_stock_level = isset($_POST["min_stock_level"]) && !empty($_POST["min_stock_level"]) ? (int)$_POST["min_stock_level"] : 0;
    $reorder_point = isset($_POST["reorder_point"]) && !empty($_POST["reorder_point"]) ? (int)$_POST["reorder_point"] : 0;
    $max_stock_level = isset($_POST["max_stock_level"]) && !empty($_POST["max_stock_level"]) ? (int)$_POST["max_stock_level"] : 0;
    $is_service = isset($_POST["is_service"]) ? 1 : 0;
    $is_active = isset($_POST["is_active"]) ? 1 : 0;
    // Define variant-related variables
    $has_variants = isset($_POST["has_variants"]) ? 1 : 0;
    
    // Product attributes handling
    $weight = isset($_POST["weight"]) && !empty($_POST["weight"]) ? (float)$_POST["weight"] : null;
    $length = isset($_POST["length"]) && !empty($_POST["length"]) ? (float)$_POST["length"] : null;
    $width = isset($_POST["width"]) && !empty($_POST["width"]) ? (float)$_POST["width"] : null;
    $height = isset($_POST["height"]) && !empty($_POST["height"]) ? (float)$_POST["height"] : null;
    
    // Log all form data for debugging
    file_put_contents("../../../debug_log.txt", "Form data: " . json_encode($_POST) . "\n", FILE_APPEND);
    file_put_contents("../../../debug_log.txt", "Processed form data: " . json_encode([
        'product_name' => $product_name,
        'product_code' => $product_code,
        'barcode' => $barcode,
        'category_id' => $category_id,
        'brand_id' => $brand_id,
        'base_uom_id' => $base_uom_id,
        'cost_price' => $cost_price,
        'selling_price' => $selling_price,
        'has_variants' => $has_variants
    ]) . "\n", FILE_APPEND);
    
    // Validate required fields
    if (empty($product_name)) {
        throw new Exception("Product name is required");
    }
    
    if (empty($product_code)) {
        throw new Exception("Product code is required");
    }
    
    if ($require_base_uom && empty($base_uom_id)) {
        throw new Exception("Base UOM is required");
    }
    
    // UOM conversion factor validation has been removed
    // If purchase_uom_id is empty, use base_uom_id
    if (empty($purchase_uom_id) && !empty($base_uom_id)) {
        $purchase_uom_id = $base_uom_id;
    }
    
    // If sales_uom_id is empty, use base_uom_id
    if (empty($sales_uom_id) && !empty($base_uom_id)) {
        $sales_uom_id = $base_uom_id;
    }
    
    // Check if product code exists
    if (!empty($product_code)) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE product_code = :product_code");
        $stmt->bindParam(":product_code", $product_code, PDO::PARAM_STR);
        $stmt->execute();
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Product code already exists. Please use a different code.");
        }
    }
    
    // Handle image upload
    $image_path = null;
    if (isset($_FILES["product_image"]) && $_FILES["product_image"]["error"] == 0 && !empty($_FILES["product_image"]["name"])) {
        file_put_contents("../../../debug_log.txt", "Image upload check: yes\n", FILE_APPEND);
        file_put_contents("../../../debug_log.txt", "Image error: " . $_FILES["product_image"]["error"] . "\n", FILE_APPEND);
        file_put_contents("../../../debug_log.txt", "Image name: " . $_FILES["product_image"]["name"] . "\n", FILE_APPEND);
        
        // Use absolute path for upload directory
        $upload_dir = dirname(dirname(dirname(__DIR__))) . '/uploads/products/';
        file_put_contents("../../../debug_log.txt", "Upload directory (absolute): " . $upload_dir . "\n", FILE_APPEND);
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
            file_put_contents("../../../debug_log.txt", "Created upload directory\n", FILE_APPEND);
        }
        
        $allowed_types = ["image/jpeg", "image/jpg", "image/png", "image/gif", "image/webp"];
        $file_type = $_FILES["product_image"]["type"];
        file_put_contents("../../../debug_log.txt", "Image type: " . $file_type . "\n", FILE_APPEND);
        
        if (in_array($file_type, $allowed_types)) {
            // Clean the filename to remove any potential issues
            $original_filename = basename($_FILES["product_image"]["name"]);
            // Remove any non-alphanumeric characters except dots, dashes and underscores
            $clean_filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $original_filename);
            // Ensure the filename isn't too long
            $clean_filename = substr($clean_filename, 0, 50);
            
            $file_name = time() . "_" . $clean_filename;
            $target_file = $upload_dir . $file_name;
            
            file_put_contents("../../../debug_log.txt", "Attempting to move file to: " . $target_file . "\n", FILE_APPEND);
            
            if (move_uploaded_file($_FILES["product_image"]["tmp_name"], $target_file)) {
                // Store relative path in database
                $image_path = "uploads/products/" . $file_name;
                file_put_contents("../../../debug_log.txt", "Image path saved to DB: " . $image_path . "\n", FILE_APPEND);
                file_put_contents("../../../debug_log.txt", "Image uploaded successfully. Path: " . $image_path . "\n", FILE_APPEND);
            } else {
                $error = error_get_last();
                file_put_contents("../../../debug_log.txt", "Failed to move uploaded file. Error: " . ($error ? $error['message'] : 'Unknown') . "\n", FILE_APPEND);
                throw new Exception("Failed to upload image. Please check file permissions.");
            }
        } else {
            throw new Exception("Only JPG, PNG, GIF, and WebP files are allowed.");
        }
    }
    
    // Check if base UOM is required but not provided
    if ($require_base_uom && $base_uom_id === null) {
        throw new Exception("Base Unit of Measure is required");
    }
    
    // Check if UOMs exist in the database
    if ($base_uom_id !== null) {
        $stmt = $db->prepare("SELECT id FROM uom WHERE id = :uom_id AND status = 1");
        $stmt->bindParam(':uom_id', $base_uom_id, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetch()) {
            throw new Exception("Selected Base UOM does not exist or is inactive. Please select a valid UOM.");
        }
    }
    
    // If purchase_uom_id is empty, use base_uom_id
    if (empty($purchase_uom_id) && !empty($base_uom_id)) {
        $purchase_uom_id = $base_uom_id;
        file_put_contents("../../../debug_log.txt", "Setting purchase_uom_id to base_uom_id: $base_uom_id\n", FILE_APPEND);
    } else if ($purchase_uom_id !== null) {
        $stmt = $db->prepare("SELECT id FROM uom WHERE id = :uom_id AND status = 1");
        $stmt->bindParam(':uom_id', $purchase_uom_id, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetch()) {
            throw new Exception("Selected Purchase UOM does not exist or is inactive. Please select a valid UOM.");
        }
    }
    
    // If sales_uom_id is empty, use base_uom_id
    if (empty($sales_uom_id) && !empty($base_uom_id)) {
        $sales_uom_id = $base_uom_id;
        file_put_contents("../../../debug_log.txt", "Setting sales_uom_id to base_uom_id: $base_uom_id\n", FILE_APPEND);
    } else if ($sales_uom_id !== null) {
        $stmt = $db->prepare("SELECT id FROM uom WHERE id = :uom_id AND status = 1");
        $stmt->bindParam(':uom_id', $sales_uom_id, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetch()) {
            throw new Exception("Selected Sales UOM does not exist or is inactive. Please select a valid UOM.");
        }
    }
    
    // Check if the category exists in the product_categories table
    if ($category_id !== null) {
        $stmt = $db->prepare("SELECT id FROM product_categories WHERE id = :category_id AND status = 1");
        $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetch()) {
            throw new Exception("Selected category does not exist or is inactive. Please select a valid category.");
        }
    }
    
    // Build SQL query
    $sql = "INSERT INTO products (
        product_name, product_code, barcode, category_id, brand_id, description, short_description,
        base_uom_id, purchase_uom_id, sales_uom_id, cost_price, selling_price, min_selling_price,
        wholesale_price, min_wholesale_qty, tax_rate, is_taxable, min_stock_level, reorder_point, max_stock_level,
        track_batches, track_expiry, track_serial, enable_stock_alerts,
        is_service, is_active, image_path,
        weight, length, width, height, status, created_by, updated_by
    ) VALUES (
        :product_name, :product_code, :barcode, :category_id, :brand_id, :description, :short_description,
        :base_uom_id, :purchase_uom_id, :sales_uom_id, :cost_price, :selling_price, :min_selling_price,
        :wholesale_price, :min_wholesale_qty, :tax_rate, :is_taxable, :min_stock_level, :reorder_point, :max_stock_level,
        :track_batches, :track_expiry, :track_serial, :enable_stock_alerts,
        :is_service, :is_active, :image_path,
        :weight, :length, :width, :height, :status, :created_by, :updated_by
    )";
    
    file_put_contents("../../../debug_log.txt", "SQL Query: " . $sql . "\n", FILE_APPEND);
    
    // Prepare statement
    $stmt = $db->prepare($sql);
    
    // Bind parameters
    $stmt->bindParam(':product_name', $product_name, PDO::PARAM_STR);
    $stmt->bindParam(':product_code', $product_code, PDO::PARAM_STR);
    $stmt->bindParam(':barcode', $barcode, PDO::PARAM_STR);
    $stmt->bindParam(':category_id', $category_id, $category_id !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindParam(':brand_id', $brand_id, $brand_id !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->bindParam(':short_description', $short_description, PDO::PARAM_STR);
    $stmt->bindParam(':base_uom_id', $base_uom_id, $base_uom_id !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindParam(':purchase_uom_id', $purchase_uom_id, $purchase_uom_id !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindParam(':sales_uom_id', $sales_uom_id, $sales_uom_id !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindParam(':cost_price', $cost_price, PDO::PARAM_STR);
    $stmt->bindParam(':selling_price', $selling_price, PDO::PARAM_STR);
    $stmt->bindParam(':min_selling_price', $min_selling_price, PDO::PARAM_STR);
    $stmt->bindParam(':wholesale_price', $wholesale_price, PDO::PARAM_STR);
    $stmt->bindParam(':min_wholesale_qty', $min_wholesale_qty, PDO::PARAM_INT);
    $stmt->bindParam(':tax_rate', $tax_rate, PDO::PARAM_STR);
    // Discount percent field removed as per request - will be handled separately
    $stmt->bindParam(':is_taxable', $is_taxable, PDO::PARAM_INT);
    $stmt->bindParam(':min_stock_level', $min_stock_level, PDO::PARAM_INT);
    $stmt->bindParam(':reorder_point', $reorder_point, PDO::PARAM_INT);
    $stmt->bindParam(':max_stock_level', $max_stock_level, PDO::PARAM_INT);
    $stmt->bindParam(':track_batches', $track_batches, PDO::PARAM_INT);
    $stmt->bindParam(':track_expiry', $track_expiry, PDO::PARAM_INT);
    $stmt->bindParam(':track_serial', $track_serial, PDO::PARAM_INT);
    $stmt->bindParam(':enable_stock_alerts', $enable_stock_alerts, PDO::PARAM_INT);
    $stmt->bindParam(':is_service', $is_service, PDO::PARAM_INT);
    $stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);
    $stmt->bindParam(':image_path', $image_path, PDO::PARAM_STR);
    $stmt->bindParam(':weight', $weight, PDO::PARAM_STR);
    $stmt->bindParam(':length', $length, PDO::PARAM_STR);
    $stmt->bindParam(':width', $width, PDO::PARAM_STR);
    $stmt->bindParam(':height', $height, PDO::PARAM_STR);
    $status = 1;
    $stmt->bindParam(':status', $status, PDO::PARAM_INT);
    $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
    
    // Log parameters for debugging
    $params = [
        ':product_name' => $product_name,
        ':product_code' => $product_code,
        ':barcode' => $barcode,
        ':category_id' => $category_id,
        ':brand_id' => $brand_id,
        ':description' => $description,
        ':short_description' => $short_description,
        ':base_uom_id' => $base_uom_id,
        ':purchase_uom_id' => $purchase_uom_id,
        ':sales_uom_id' => $sales_uom_id,
        ':cost_price' => $cost_price,
        ':selling_price' => $selling_price,
        ':min_selling_price' => $min_selling_price,
        ':wholesale_price' => $wholesale_price,
        ':min_wholesale_qty' => $min_wholesale_qty,
        ':tax_rate' => $tax_rate,
        ':discount_percent' => $discount_percent,
        ':is_taxable' => $is_taxable,
        ':min_stock_level' => $min_stock_level,
        ':reorder_point' => $reorder_point,
        ':max_stock_level' => $max_stock_level,
        ':track_batches' => $track_batches,
        ':track_expiry' => $track_expiry,
        ':track_serial' => $track_serial,
        ':enable_stock_alerts' => $enable_stock_alerts,
        ':is_service' => $is_service,
        ':is_active' => $is_active,
        ':image_path' => $image_path,
        ':weight' => $weight,
        ':length' => $length,
        ':width' => $width,
        ':height' => $height,
        ':status' => 1,
        ':created_by' => $user_id,
        ':updated_by' => $user_id
    ];
    
    file_put_contents("../../../debug_log.txt", "Parameters: " . json_encode($params) . "\n", FILE_APPEND);
    
    // Execute query
    try {
        $stmt->execute();
        file_put_contents("../../../debug_log.txt", "SQL executed successfully\n", FILE_APPEND);
        
        // Get the new product ID
        $product_id = $db->lastInsertId();
        file_put_contents("../../../debug_log.txt", "New product ID: " . $product_id . "\n", FILE_APPEND);
        
        // Verify that we have a valid product ID
        if (!$product_id || $product_id == 0) {
            // Try to get the ID directly from the database
            $id_query = "SELECT id FROM products WHERE product_code = :product_code LIMIT 1";
            $id_stmt = $db->prepare($id_query);
            $id_stmt->bindParam(':product_code', $product_code, PDO::PARAM_STR);
            $id_stmt->execute();
            $id_result = $id_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($id_result && isset($id_result['id'])) {
                $product_id = $id_result['id'];
                file_put_contents("../../../debug_log.txt", "Retrieved product ID from database: " . $product_id . "\n", FILE_APPEND);
            } else {
                throw new Exception("Failed to get product ID after insert");
            }
        }
        
        // Save product attributes if any
        if (isset($_POST['attributes']) && is_array($_POST['attributes'])) {
            file_put_contents("../../../debug_log.txt", "Processing attributes: " . json_encode($_POST['attributes']) . "\n", FILE_APPEND);
            
            foreach ($_POST['attributes'] as $attribute_id => $attribute_value) {
                // Skip empty values unless it's a boolean attribute
                if (empty($attribute_value) && $attribute_value !== '0') {
                    continue;
                }
                
                // Check if attribute exists and get its input type
                $attr_check_query = "SELECT id, input_type FROM attribute_definitions WHERE id = :id AND status = 1";
                $attr_check_stmt = $db->prepare($attr_check_query);
                $attr_check_stmt->bindParam(':id', $attribute_id, PDO::PARAM_INT);
                $attr_check_stmt->execute();
                $attribute = $attr_check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$attribute) {
                    file_put_contents("../../../debug_log.txt", "Attribute not found: $attribute_id\n", FILE_APPEND);
                    continue; // Skip if attribute doesn't exist
                }
                
                // Format value based on input type
                if ($attribute['input_type'] === 'multiselect' && is_array($attribute_value)) {
                    $original_value = $attribute_value;
                    $attribute_value = json_encode($attribute_value);
                    file_put_contents("../../../debug_log.txt", "Converted multiselect array to JSON: " . print_r($original_value, true) . " -> $attribute_value\n", FILE_APPEND);
                }
                
                file_put_contents("../../../debug_log.txt", "Saving attribute: $attribute_id (type: {$attribute['input_type']}) with value: $attribute_value\n", FILE_APPEND);
                
                $attr_sql = "INSERT INTO product_attributes (product_id, attribute_id, attribute_value, created_by, updated_by) 
                            VALUES (:product_id, :attribute_id, :attribute_value, :created_by, :updated_by)";
                $attr_stmt = $db->prepare($attr_sql);
                $attr_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
                $attr_stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
                $attr_stmt->bindParam(':attribute_value', $attribute_value, PDO::PARAM_STR);
                $attr_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
                $attr_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
                
                try {
                    $attr_stmt->execute();
                    file_put_contents("../../../debug_log.txt", "Attribute saved successfully: $attribute_id\n", FILE_APPEND);
                } catch (PDOException $e) {
                    file_put_contents("../../../debug_log.txt", "Error saving attribute: " . $e->getMessage() . "\n", FILE_APPEND);
                }
            }
        }
        
        // Variant functionality removed
        
        // Commit transaction
        $db->commit();
        file_put_contents("../../../debug_log.txt", "Transaction committed successfully\n", FILE_APPEND);
        
        // Redirect with success message
        $_SESSION["success_message"] = "Product added successfully";
        header("Location: ../products.php");
        exit;
    } catch (PDOException $e) {
        // Get detailed error information
        $errorInfo = $stmt->errorInfo();
        file_put_contents("../../../debug_log.txt", "SQL execution error: " . $e->getMessage() . "\n", FILE_APPEND);
        file_put_contents("../../../debug_log.txt", "SQL State: " . $errorInfo[0] . "\n", FILE_APPEND);
        file_put_contents("../../../debug_log.txt", "Error Code: " . $errorInfo[1] . "\n", FILE_APPEND);
        file_put_contents("../../../debug_log.txt", "Error Message: " . $errorInfo[2] . "\n", FILE_APPEND);
        throw $e; // Re-throw to be caught by the outer try-catch
    }
} catch (Exception $e) {
    // Rollback transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    file_put_contents("../../../debug_log.txt", "Error in save_product.php: " . $e->getMessage() . "\n", FILE_APPEND);
    
    // Get detailed error information for debugging
    $trace = $e->getTraceAsString();
    file_put_contents("../../../debug_log.txt", "Stack trace: " . $trace . "\n", FILE_APPEND);
    
    // Display error on screen for debugging
    echo "<h1>Error</h1>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "<h2>Stack Trace</h2>";
    echo "<pre>" . $trace . "</pre>";
    echo "<p><a href='../products.php'>Back to Products</a></p>";
    
    // Don't redirect during debugging
    // $_SESSION["error_message"] = "Error adding product: " . $e->getMessage();
    // header("Location: ../products.php");
    exit;
}
?>