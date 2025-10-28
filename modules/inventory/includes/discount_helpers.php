<?php
/**
 * Discount Helper Functions
 * Contains functions for discount management and application
 */

/**
 * Get a discount setting value
 * 
 * @param PDO $db Database connection
 * @param string $key Setting key
 * @param mixed $default Default value if setting not found
 * @return mixed Setting value or default
 */
function getDiscountSetting($db, $key, $default = null) {
    try {
        $query = "SELECT setting_value FROM inventory_settings WHERE setting_key = :key AND setting_group = 'discount' AND status = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':key', $key, PDO::PARAM_STR);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        error_log("Error getting discount setting: " . $e->getMessage());
        return $default;
    }
}

// The getDiscountById function is defined later in this file

/**
 * Generate a unique discount code
 * 
 * @param PDO $db Database connection
 * @return string Generated discount code
 */
function generateDiscountCode($db) {
    $prefix = getDiscountSetting($db, 'discount_code_prefix', 'DISC-');
    
    try {
        // Find the highest numbered discount code
        $query = "SELECT MAX(CAST(SUBSTRING(discount_code, ?) AS UNSIGNED)) as last_num 
                  FROM discounts 
                  WHERE discount_code LIKE ?";
        $prefixLength = strlen($prefix);
        $prefixLengthPlusOne = $prefixLength + 1; // Calculate this in a separate variable
        $likePattern = $prefix . '%';
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $prefixLengthPlusOne, PDO::PARAM_INT);
        $stmt->bindParam(2, $likePattern, PDO::PARAM_STR);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $lastNum = $result['last_num'] ? (int)$result['last_num'] : 0;
        $newNum = $lastNum + 1;
        
        // Format with leading zeros (e.g., DISC-0001)
        return $prefix . str_pad($newNum, 4, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        error_log("Error generating discount code: " . $e->getMessage());
        // Fallback to timestamp-based code
        return $prefix . date('YmdHis');
    }
}

/**
 * Get discount by ID
 * 
 * @param PDO $db Database connection
 * @param int $discount_id Discount ID
 * @return array|false Discount data or false if not found
 */
function getDiscountById($db, $discount_id) {
    try {
        $query = "SELECT * FROM discounts WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $discount_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $discount = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($discount) {
            // Get conditions for this discount
            $conditions_query = "SELECT * FROM discount_conditions WHERE discount_id = :discount_id";
            $conditions_stmt = $db->prepare($conditions_query);
            $conditions_stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
            $conditions_stmt->execute();
            
            $discount['conditions'] = $conditions_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $discount;
    } catch (PDOException $e) {
        error_log("Error getting discount: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a discount is applicable based on conditions
 * 
 * @param PDO $db Database connection
 * @param int $discount_id Discount ID
 * @param array $context Context data (product_id, category_id, etc.)
 * @return bool True if discount is applicable, false otherwise
 */
function isDiscountApplicable($db, $discount_id, $context = []) {
    try {
        // Get the discount
        $discount = getDiscountById($db, $discount_id);
        
        if (!$discount) {
            return false;
        }
        
        // Check if discount is active
        if (!$discount['is_active']) {
            return false;
        }
        
        // Check date range if set
        $today = date('Y-m-d');
        if ($discount['start_date'] && $discount['start_date'] > $today) {
            return false;
        }
        if ($discount['end_date'] && $discount['end_date'] < $today) {
            return false;
        }
        
        // Check minimum purchase amount if set
        if ($discount['min_purchase_amount'] > 0 && 
            (!isset($context['purchase_amount']) || $context['purchase_amount'] < $discount['min_purchase_amount'])) {
            return false;
        }
        
        // Check minimum quantity if set
        if ($discount['min_quantity'] > 0 && 
            (!isset($context['quantity']) || $context['quantity'] < $discount['min_quantity'])) {
            return false;
        }
        
        // If no conditions, discount is applicable
        if (empty($discount['conditions'])) {
            return true;
        }
        
        // Check conditions
        foreach ($discount['conditions'] as $condition) {
            switch ($condition['condition_type']) {
                case 'product':
                    if (!isset($context['product_id']) || $context['product_id'] != $condition['condition_value']) {
                        return false;
                    }
                    break;
                case 'brand':
                    if (!isset($context['brand_id']) || $context['brand_id'] != $condition['condition_value']) {
                        return false;
                    }
                    break;
                case 'category':
                    if (!isset($context['category_id']) || $context['category_id'] != $condition['condition_value']) {
                        return false;
                    }
                    break;
                case 'attribute':
                    // Check if product has this attribute value
                    if (!isset($context['product_id'])) {
                        return false;
                    }
                    
                    $attr_query = "SELECT COUNT(*) FROM product_attributes 
                                  WHERE product_id = :product_id 
                                  AND attribute_id = :attribute_id";
                    $attr_stmt = $db->prepare($attr_query);
                    $attr_stmt->bindParam(':product_id', $context['product_id'], PDO::PARAM_INT);
                    $attr_stmt->bindParam(':attribute_id', $condition['condition_value'], PDO::PARAM_INT);
                    $attr_stmt->execute();
                    
                    if ($attr_stmt->fetchColumn() == 0) {
                        return false;
                    }
                    break;
                case 'location':
                    if (!isset($context['location_id']) || $context['location_id'] != $condition['condition_value']) {
                        return false;
                    }
                    break;
                case 'supplier':
                    if (!isset($context['supplier_id']) || $context['supplier_id'] != $condition['condition_value']) {
                        return false;
                    }
                    break;
                case 'customer':
                    if (!isset($context['customer_id']) || $context['customer_id'] != $condition['condition_value']) {
                        return false;
                    }
                    break;
            }
        }
        
        // All conditions passed
        return true;
    } catch (PDOException $e) {
        error_log("Error checking discount applicability: " . $e->getMessage());
        return false;
    }
}

/**
 * Calculate discount amount
 * 
 * @param array $discount Discount data
 * @param float $amount Amount to apply discount to
 * @return float Discount amount
 */
function calculateDiscountAmount($discount, $amount) {
    if ($discount['discount_type'] == 'percentage') {
        return ($amount * $discount['discount_value']) / 100;
    } else { // fixed_amount
        return min($discount['discount_value'], $amount); // Don't exceed the amount
    }
}

/**
 * Get applicable discounts for a context
 * 
 * @param PDO $db Database connection
 * @param array $context Context data (product_id, category_id, etc.)
 * @return array List of applicable discounts
 */
function getApplicableDiscounts($db, $context = []) {
    try {
        $query = "SELECT * FROM discounts WHERE is_active = 1 AND status = 1";
        
        // Add date range conditions if needed
        $today = date('Y-m-d');
        $query .= " AND (start_date IS NULL OR start_date <= :today)";
        $query .= " AND (end_date IS NULL OR end_date >= :today)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':today', $today, PDO::PARAM_STR);
        $stmt->execute();
        
        $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $applicable_discounts = [];
        
        foreach ($discounts as $discount) {
            if (isDiscountApplicable($db, $discount['id'], $context)) {
                $applicable_discounts[] = $discount;
            }
        }
        
        return $applicable_discounts;
    } catch (PDOException $e) {
        error_log("Error getting applicable discounts: " . $e->getMessage());
        return [];
    }
}

/**
 * Record discount usage
 * 
 * @param PDO $db Database connection
 * @param int $discount_id Discount ID
 * @param string $reference_type Reference type (e.g., 'sales_order')
 * @param int $reference_id Reference ID
 * @param float $discount_amount Discount amount
 * @param int|null $customer_id Customer ID (optional)
 * @param int|null $supplier_id Supplier ID (optional)
 * @return bool True on success, false on failure
 */
function recordDiscountUsage($db, $discount_id, $reference_type, $reference_id, $discount_amount, $customer_id = null, $supplier_id = null) {
    try {
        $query = "INSERT INTO discount_usage (
                    discount_id, reference_type, reference_id, 
                    customer_id, supplier_id, discount_amount, created_by
                  ) VALUES (
                    :discount_id, :reference_type, :reference_id,
                    :customer_id, :supplier_id, :discount_amount, :created_by
                  )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
        $stmt->bindParam(':reference_type', $reference_type, PDO::PARAM_STR);
        $stmt->bindParam(':reference_id', $reference_id, PDO::PARAM_INT);
        $stmt->bindParam(':customer_id', $customer_id, $customer_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':supplier_id', $supplier_id, $supplier_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindParam(':discount_amount', $discount_amount, PDO::PARAM_STR);
        $user_id = $_SESSION['user_id'];
        $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Error recording discount usage: " . $e->getMessage());
        return false;
    }
}
?>
