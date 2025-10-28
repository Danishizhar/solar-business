<?php
/**
 * Inventory Helper Functions
 * Provides centralized access to inventory module functionality
 */

/**
 * Get inventory setting value
 * 
 * @param PDO $db Database connection
 * @param string $key Setting key
 * @param mixed $default Default value if setting not found
 * @return mixed Setting value
 */
function getInventorySetting($db, $key, $default = null) {
    try {
        $query = "SELECT setting_value FROM inventory_settings WHERE setting_key = :key AND status = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':key', $key, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        error_log("Error in getInventorySetting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Get all inventory settings by group
 * 
 * @param PDO $db Database connection
 * @param string $group Setting group
 * @return array Settings in the specified group
 */
function getInventorySettingsByGroup($db, $group = null) {
    try {
        $query = "SELECT * FROM inventory_settings WHERE status = 1";
        $params = [];
        
        if ($group) {
            $query .= " AND setting_group = :group";
            $params[':group'] = $group;
        }
        
        $query .= " ORDER BY display_order, display_name";
        
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getInventorySettingsByGroup: " . $e->getMessage());
        return [];
    }
}

/**
 * Get product details by ID
 * 
 * @param PDO $db Database connection
 * @param int $product_id Product ID
 * @return array|null Product details
 */
function getProductById($db, $product_id) {
    try {
        // Get product details with all fields
        $query = "SELECT p.*, c.category_name, b.brand_name,
                 bu.uom_name as base_uom_name, bu.uom_symbol as base_uom_symbol,
                 pu.uom_name as purchase_uom_name, pu.uom_symbol as purchase_uom_symbol,
                 su.uom_name as sales_uom_name, su.uom_symbol as sales_uom_symbol
                 FROM products p
                 LEFT JOIN product_categories c ON p.category_id = c.id
                 LEFT JOIN brands b ON p.brand_id = b.id
                 LEFT JOIN uom bu ON p.base_uom_id = bu.id
                 LEFT JOIN uom pu ON p.purchase_uom_id = pu.id
                 LEFT JOIN uom su ON p.sales_uom_id = su.id
                 WHERE p.id = :product_id AND p.status = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            return null;
        }
        
        // Format image path for display
        if (!empty($product['image_path'])) {
            $product['image_url'] = '../../' . $product['image_path'];
        }
        
        // Log the product data for debugging
        error_log("Product data retrieved: " . json_encode($product));
        
        // Get product attributes
        $product['attributes'] = getProductAttributes($db, $product_id);
        
        // Get product variants if this is a parent product
        if ($product['has_variants'] == 1 && $product['is_variant'] == 0) {
            $product['variants'] = getProductVariants($db, $product_id);
        }
        
        // Get variant attributes if this product has variants
        if ($product['has_variants'] == 1) {
            $product['variant_attributes'] = getVariantAttributes($db, $product_id);
        }
        
        return $product;
    } catch (PDOException $e) {
        error_log("Error in getProductById: " . $e->getMessage());
        return null;
    }
}

/**
 * Get product variants
 * 
 * @param PDO $db Database connection
 * @param int $parent_product_id Parent product ID
 * @return array Product variants
 */
function getProductVariants($db, $parent_product_id) {
    try {
        $query = "SELECT p.*, 
                 GROUP_CONCAT(CONCAT(ad.attribute_name, ': ', pa.attribute_value) SEPARATOR ', ') as variant_attributes
                 FROM products p
                 LEFT JOIN product_attributes pa ON p.id = pa.product_id
                 LEFT JOIN attribute_definitions ad ON pa.attribute_id = ad.id
                 WHERE p.parent_product_id = :parent_id 
                 AND p.is_variant = 1
                 AND p.status = 1
                 GROUP BY p.id
                 ORDER BY p.product_name";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':parent_id', $parent_product_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getProductVariants: " . $e->getMessage());
        return [];
    }
}

/**
 * Get product attributes
 * 
 * @param PDO $db Database connection
 * @param int $product_id Product ID
 * @return array Product attributes
 */
function getProductAttributes($db, $product_id) {
    try {
        $query = "SELECT pa.*, ad.attribute_name, ad.input_type, ad.options
                 FROM product_attributes pa
                 JOIN attribute_definitions ad ON pa.attribute_id = ad.id
                 WHERE pa.product_id = :product_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getProductAttributes: " . $e->getMessage());
        return [];
    }
}



/**
 * Get variant attributes for a product
 * 
 * @param PDO $db Database connection
 * @param int $product_id Product ID
 * @return array Variant attributes with their values
 */
function getVariantAttributes($db, $product_id) {
    try {
        // First check if the product exists and has variants
        $product_query = "SELECT has_variants FROM products WHERE id = :product_id AND status = 1";
        $product_stmt = $db->prepare($product_query);
        $product_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $product_stmt->execute();
        $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product || $product['has_variants'] != 1) {
            return [];
        }
        
        // Get variant attributes
        $query = "SELECT * FROM product_variant_attributes 
                 WHERE product_id = :product_id
                 ORDER BY id ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Found " . count($attributes) . " variant attributes for product ID $product_id");
        
        // Get variant values for each attribute
        foreach ($attributes as &$attr) {
            $query = "SELECT DISTINCT attribute_value FROM product_variant_values 
                     WHERE attribute_id = :attribute_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':attribute_id', $attr['id'], PDO::PARAM_INT);
            $stmt->execute();
            $values = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $attr['values'] = implode(',', $values);
            
            error_log("Attribute ID " . $attr['id'] . " (" . $attr['attribute_name'] . ") has values: " . $attr['values']);
        }
        
        return $attributes;
    } catch (PDOException $e) {
        error_log("Error in getVariantAttributes: " . $e->getMessage());
        return [];
    }
}

/**
 * Get product images
 * 
 * @param PDO $db Database connection
 * @param int $product_id Product ID
 * @return array Product images
 */
function getProductImages($db, $product_id) {
    try {
        $query = "SELECT * FROM product_images 
                 WHERE product_id = :product_id AND status = 1
                 ORDER BY is_primary DESC, display_order ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getProductImages: " . $e->getMessage());
        return [];
    }
}

/**
 * Get product stock levels
 * 
 * @param PDO $db Database connection
 * @param int $product_id Product ID
 * @return array Stock levels by location
 */
function getProductStock($db, $product_id) {
    try {
        $query = "SELECT is.*, il.location_name, il.location_code
                 FROM inventory_stock is
                 JOIN inventory_locations il ON is.location_id = il.id
                 WHERE is.product_id = :product_id AND is.status = 1 AND il.status = 1
                 ORDER BY il.is_default DESC, il.location_name ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getProductStock: " . $e->getMessage());
        return [];
    }
}

/**
 * Get total product stock quantity
 * 
 * @param PDO $db Database connection
 * @param int $product_id Product ID
 * @return float Total available quantity
 */
function getTotalProductStock($db, $product_id) {
    try {
        $query = "SELECT SUM(available_quantity) as total_stock
                 FROM inventory_stock
                 WHERE product_id = :product_id AND status = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? floatval($result['total_stock']) : 0;
    } catch (PDOException $e) {
        error_log("Error in getTotalProductStock: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get product transaction history
 * 
 * @param PDO $db Database connection
 * @param int $product_id Product ID
 * @param int $limit Number of records to return
 * @return array Transaction history
 */
function getProductTransactionHistory($db, $product_id, $limit = 10) {
    try {
        $query = "SELECT itd.*, it.transaction_number, it.transaction_date, 
                 itt.transaction_type_name, itt.stock_effect,
                 from_loc.location_name as from_location_name,
                 to_loc.location_name as to_location_name,
                 u.username as created_by_name
                 FROM inventory_transaction_details itd
                 JOIN inventory_transactions it ON itd.transaction_id = it.id
                 JOIN inventory_transaction_types itt ON it.transaction_type_id = itt.id
                 LEFT JOIN inventory_locations from_loc ON itd.from_location_id = from_loc.id
                 LEFT JOIN inventory_locations to_loc ON itd.to_location_id = to_loc.id
                 LEFT JOIN users u ON it.created_by = u.id
                 WHERE itd.product_id = :product_id AND it.status = 1
                 ORDER BY it.transaction_date DESC
                 LIMIT :limit";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getProductTransactionHistory: " . $e->getMessage());
        return [];
    }
}

/**
 * Generate a unique product code
 * 
 * @param PDO $db Database connection
 * @param string $prefix Prefix for the product code
 * @return string Unique product code
 */
function generateProductCode($db, $prefix = 'P') {
    try {
        // Get the prefix from settings if available
        $setting_prefix = getInventorySetting($db, 'product_code_prefix', $prefix);
        $prefix = !empty($setting_prefix) ? $setting_prefix : $prefix;
        
        // Get the last product code with this prefix
        $query = "SELECT product_code FROM products 
                 WHERE product_code LIKE :prefix 
                 ORDER BY id DESC LIMIT 1";
        $stmt = $db->prepare($query);
        $search_prefix = $prefix . '%';
        $stmt->bindParam(':prefix', $search_prefix, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Extract the numeric part and increment
            $code = $result['product_code'];
            $numeric_part = substr($code, strlen($prefix));
            $next_number = intval($numeric_part) + 1;
            return $prefix . str_pad($next_number, 6, '0', STR_PAD_LEFT);
        } else {
            // First product with this prefix
            return $prefix . '000001';
        }
    } catch (PDOException $e) {
        error_log("Error in generateProductCode: " . $e->getMessage());
        // Fallback to timestamp-based code
        return $prefix . date('YmdHis');
    }
}

/**
 * Check if a product code already exists
 * 
 * @param PDO $db Database connection
 * @param string $product_code Product code to check
 * @param int $exclude_id Product ID to exclude from the check (for updates)
 * @return bool True if the product code exists, false otherwise
 */
function productCodeExists($db, $product_code, $exclude_id = null) {
    try {
        $query = "SELECT COUNT(*) as count FROM products WHERE product_code = :product_code";
        $params = [':product_code' => $product_code];
        
        if ($exclude_id) {
            $query .= " AND id != :exclude_id";
            $params[':exclude_id'] = $exclude_id;
        }
        
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            if ($key == ':exclude_id') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    } catch (PDOException $e) {
        error_log("Error in productCodeExists: " . $e->getMessage());
        return false;
    }
}

/**
 * Format a hierarchical category list with indentation
 * 
 * @param array $categories Array of categories
 * @param int $parent_id Parent ID to start from
 * @param int $level Current level of hierarchy
 * @return array Formatted categories
 */
function formatCategoryHierarchy($categories, $parent_id = 0, $level = 0) {
    $result = [];
    
    foreach ($categories as $category) {
        if ($category['parent_id'] == $parent_id) {
            $category['level'] = $level;
            $category['formatted_name'] = str_repeat('— ', $level) . $category['category_name'];
            $result[] = $category;
            
            // Add children
            $children = formatCategoryHierarchy($categories, $category['id'], $level + 1);
            $result = array_merge($result, $children);
        }
    }
    
    return $result;
}

/**
 * Get all inventory locations
 * 
 * @param PDO $db Database connection
 * @return array All active inventory locations
 */
function getAllInventoryLocations($db) {
    try {
        $query = "SELECT * FROM inventory_locations WHERE status = 1 ORDER BY is_default DESC, location_name ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getAllInventoryLocations: " . $e->getMessage());
        return [];
    }
}

/**
 * Get default inventory location
 * 
 * @param PDO $db Database connection
 * @return array|null Default inventory location
 */
function getDefaultInventoryLocation($db) {
    try {
        $query = "SELECT * FROM inventory_locations WHERE is_default = 1 AND status = 1 LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getDefaultInventoryLocation: " . $e->getMessage());
        return null;
    }
}

/**
 * Update product stock levels
 * 
 * @param PDO $db Database connection
 * @param int $product_id Product ID
 * @param int $location_id Location ID
 * @param float $quantity Quantity to add (positive) or subtract (negative)
 * @param string $batch_number Batch number (optional)
 * @param string $expiry_date Expiry date (optional)
 * @param float $unit_cost Unit cost (optional)
 * @param int $user_id User ID making the change
 * @return bool True on success, false on failure
 */
function updateProductStock($db, $product_id, $location_id, $quantity, $batch_number = null, $expiry_date = null, $unit_cost = null, $user_id = null) {
    try {
        $db->beginTransaction();
        
        // Check if stock record exists
        $query = "SELECT * FROM inventory_stock 
                 WHERE product_id = :product_id 
                 AND location_id = :location_id";
        
        if ($batch_number) {
            $query .= " AND batch_number = :batch_number";
        } else {
            $query .= " AND (batch_number IS NULL OR batch_number = '')";
        }
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
        
        if ($batch_number) {
            $stmt->bindParam(':batch_number', $batch_number, PDO::PARAM_STR);
        }
        
        $stmt->execute();
        $stock = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($stock) {
            // Update existing stock
            $new_quantity = $stock['quantity'] + $quantity;
            $new_available = $stock['available_quantity'] + $quantity;
            
            // Calculate new total value
            $new_unit_cost = $unit_cost ?? $stock['unit_cost'];
            $new_total_value = $new_quantity * $new_unit_cost;
            
            $update_query = "UPDATE inventory_stock SET 
                           quantity = :quantity,
                           available_quantity = :available_quantity,
                           unit_cost = :unit_cost,
                           total_value = :total_value,
                           updated_at = NOW(),
                           updated_by = :updated_by
                           WHERE id = :id";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':quantity', $new_quantity, PDO::PARAM_STR);
            $update_stmt->bindParam(':available_quantity', $new_available, PDO::PARAM_STR);
            $update_stmt->bindParam(':unit_cost', $new_unit_cost, PDO::PARAM_STR);
            $update_stmt->bindParam(':total_value', $new_total_value, PDO::PARAM_STR);
            $update_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':id', $stock['id'], PDO::PARAM_INT);
            $update_stmt->execute();
        } else {
            // Insert new stock record
            $new_unit_cost = $unit_cost ?? 0;
            $new_total_value = $quantity * $new_unit_cost;
            
            $insert_query = "INSERT INTO inventory_stock (
                           product_id, location_id, batch_number, expiry_date,
                           quantity, available_quantity, unit_cost, total_value,
                           status, created_by, updated_by
                           ) VALUES (
                           :product_id, :location_id, :batch_number, :expiry_date,
                           :quantity, :available_quantity, :unit_cost, :total_value,
                           1, :created_by, :updated_by
                           )";
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':batch_number', $batch_number, PDO::PARAM_STR);
            $insert_stmt->bindParam(':expiry_date', $expiry_date, PDO::PARAM_STR);
            $insert_stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
            $insert_stmt->bindParam(':available_quantity', $quantity, PDO::PARAM_STR);
            $insert_stmt->bindParam(':unit_cost', $new_unit_cost, PDO::PARAM_STR);
            $insert_stmt->bindParam(':total_value', $new_total_value, PDO::PARAM_STR);
            $insert_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
            $insert_stmt->execute();
        }
        
        $db->commit();
        return true;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error in updateProductStock: " . $e->getMessage());
        return false;
    }
}

/**
 * Record an inventory transaction
 * 
 * @param PDO $db Database connection
 * @param string $transaction_type_code Transaction type code
 * @param array $items Array of items to include in the transaction
 * @param string $reference_type Reference type (e.g., 'PO', 'SO')
 * @param int $reference_id Reference ID
 * @param string $notes Transaction notes
 * @param int $user_id User ID making the transaction
 * @return int|bool Transaction ID on success, false on failure
 */
function recordInventoryTransaction($db, $transaction_type_code, $items, $reference_type = null, $reference_id = null, $notes = null, $user_id = null) {
    try {
        $db->beginTransaction();
        
        // Get transaction type
        $query = "SELECT * FROM inventory_transaction_types WHERE transaction_type_code = :code AND status = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':code', $transaction_type_code, PDO::PARAM_STR);
        $stmt->execute();
        $transaction_type = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction_type) {
            throw new Exception("Invalid transaction type code: $transaction_type_code");
        }
        
        // Generate transaction number
        $transaction_number = $transaction_type_code . date('YmdHis');
        
        // Insert transaction header
        $query = "INSERT INTO inventory_transactions (
                 transaction_number, transaction_date, transaction_type_id,
                 reference_type, reference_id, notes, status, created_by, updated_by
                 ) VALUES (
                 :transaction_number, NOW(), :transaction_type_id,
                 :reference_type, :reference_id, :notes, 1, :created_by, :updated_by
                 )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':transaction_number', $transaction_number, PDO::PARAM_STR);
        $stmt->bindParam(':transaction_type_id', $transaction_type['id'], PDO::PARAM_INT);
        $stmt->bindParam(':reference_type', $reference_type, PDO::PARAM_STR);
        $stmt->bindParam(':reference_id', $reference_id, PDO::PARAM_INT);
        $stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
        $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $transaction_id = $db->lastInsertId();
        
        // Insert transaction details
        foreach ($items as $item) {
            $query = "INSERT INTO inventory_transaction_details (
                     transaction_id, product_id, from_location_id, to_location_id,
                     quantity, uom_id, unit_cost, total_cost, batch_number, expiry_date,
                     notes, status, created_by, updated_by
                     ) VALUES (
                     :transaction_id, :product_id, :from_location_id, :to_location_id,
                     :quantity, :uom_id, :unit_cost, :total_cost, :batch_number, :expiry_date,
                     :notes, 1, :created_by, :updated_by
                     )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':transaction_id', $transaction_id, PDO::PARAM_INT);
            $stmt->bindParam(':product_id', $item['product_id'], PDO::PARAM_INT);
            $stmt->bindParam(':from_location_id', $item['from_location_id'], PDO::PARAM_INT);
            $stmt->bindParam(':to_location_id', $item['to_location_id'], PDO::PARAM_INT);
            $stmt->bindParam(':quantity', $item['quantity'], PDO::PARAM_STR);
            $stmt->bindParam(':uom_id', $item['uom_id'], PDO::PARAM_INT);
            $stmt->bindParam(':unit_cost', $item['unit_cost'], PDO::PARAM_STR);
            
            $total_cost = $item['quantity'] * $item['unit_cost'];
            $stmt->bindParam(':total_cost', $total_cost, PDO::PARAM_STR);
            
            $stmt->bindParam(':batch_number', $item['batch_number'], PDO::PARAM_STR);
            $stmt->bindParam(':expiry_date', $item['expiry_date'], PDO::PARAM_STR);
            $stmt->bindParam(':notes', $item['notes'], PDO::PARAM_STR);
            $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Update stock if this transaction type affects stock
            if ($transaction_type['affects_stock']) {
                $stock_quantity = $item['quantity'] * $transaction_type['stock_effect'];
                
                if ($transaction_type_code == 'TRF') {
                    // For transfers, reduce from source and add to destination
                    if ($item['from_location_id']) {
                        updateProductStock(
                            $db, 
                            $item['product_id'], 
                            $item['from_location_id'], 
                            -$item['quantity'], 
                            $item['batch_number'], 
                            $item['expiry_date'], 
                            $item['unit_cost'], 
                            $user_id
                        );
                    }
                    
                    if ($item['to_location_id']) {
                        updateProductStock(
                            $db, 
                            $item['product_id'], 
                            $item['to_location_id'], 
                            $item['quantity'], 
                            $item['batch_number'], 
                            $item['expiry_date'], 
                            $item['unit_cost'], 
                            $user_id
                        );
                    }
                } else {
                    // For other transactions, use the stock effect direction
                    $location_id = $transaction_type['stock_effect'] > 0 ? 
                                  $item['to_location_id'] : $item['from_location_id'];
                    
                    if ($location_id) {
                        updateProductStock(
                            $db, 
                            $item['product_id'], 
                            $location_id, 
                            $stock_quantity, 
                            $item['batch_number'], 
                            $item['expiry_date'], 
                            $item['unit_cost'], 
                            $user_id
                        );
                    }
                }
            }
        }
        
        $db->commit();
        return $transaction_id;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in recordInventoryTransaction: " . $e->getMessage());
        return false;
    }
}
