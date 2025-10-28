<?php
/**
 * Helper functions for stock settings
 */

/**
 * Get a stock setting value
 * 
 * @param PDO $db Database connection
 * @param string $setting_key The setting key to retrieve
 * @param mixed $default Default value if setting not found
 * @return mixed The setting value or default if not found
 */
function getStockSetting($db, $setting_key, $default = null) {
    try {
        $query = "SELECT setting_value FROM inventory_settings WHERE setting_key = ? AND setting_group = 'stock' LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute([$setting_key]);
        
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            error_log("Retrieved stock setting {$setting_key}: " . $result['setting_value']);
            return $result['setting_value'];
        }
        
        error_log("Stock setting {$setting_key} not found, using default: " . (is_array($default) ? json_encode($default) : $default));
        return $default;
    } catch (PDOException $e) {
        error_log("Error getting stock setting {$setting_key}: " . $e->getMessage());
        return $default;
    }
}

/**
 * Check if approval is required for stock adjustments
 * 
 * @param PDO $db Database connection
 * @return bool True if approval is required, false otherwise
 */
function isApprovalRequiredForAdjustments($db) {
    return getStockSetting($db, 'require_approval_for_adjustments', '0') === '1';
}

/**
 * Check if negative stock adjustments are allowed
 * 
 * @param PDO $db Database connection
 * @return bool True if negative adjustments are allowed, false otherwise
 */
function areNegativeStockAdjustmentsAllowed($db) {
    return getStockSetting($db, 'allow_negative_stock_adjustments', '0') === '1';
}

/**
 * Get default adjustment reasons
 * 
 * @param PDO $db Database connection
 * @return array Array of default adjustment reasons
 */
function getDefaultAdjustmentReasons($db) {
    try {
        $reasons_string = getStockSetting($db, 'default_adjustment_reasons', 'Damaged,Expired,Inventory Count,Lost,Found,Theft,Return,Quality Issue');
        $reasons = explode(',', $reasons_string);
        
        // Trim whitespace from each reason
        $reasons = array_map('trim', $reasons);
        
        // Remove empty reasons
        $reasons = array_filter($reasons, function($reason) {
            return !empty($reason);
        });
        
        error_log("Retrieved " . count($reasons) . " adjustment reasons from settings");
        return $reasons;
    } catch (Exception $e) {
        error_log("Error getting default adjustment reasons: " . $e->getMessage());
        return ['Damaged', 'Expired', 'Inventory Count', 'Lost', 'Found', 'Theft', 'Return', 'Quality Issue'];
    }
}

/**
 * Check if transaction numbers should be auto-generated
 * 
 * @param PDO $db Database connection
 * @return bool True if transaction numbers should be auto-generated, false otherwise
 */
function shouldAutoGenerateTransactionNumbers($db) {
    return getStockSetting($db, 'auto_generate_transaction_numbers', '1') === '1';
}

/**
 * Get transaction number prefix
 * 
 * @param PDO $db Database connection
 * @return string Transaction number prefix
 */
function getTransactionNumberPrefix($db) {
    return getStockSetting($db, 'transaction_number_prefix', 'STKTX-');
}

/**
 * Get default transaction type
 * 
 * @param PDO $db Database connection
 * @return string Default transaction type
 */
function getDefaultTransactionType($db) {
    return getStockSetting($db, 'default_transaction_type', 'Adjustment');
}

/**
 * Check if notes are required for transactions
 * 
 * @param PDO $db Database connection
 * @return bool True if notes are required, false otherwise
 */
function areNotesRequiredForTransactions($db) {
    return getStockSetting($db, 'require_notes_for_transactions', '0') === '1';
}

/**
 * Get stock count frequency
 * 
 * @param PDO $db Database connection
 * @return string Stock count frequency
 */
function getStockCountFrequency($db) {
    return getStockSetting($db, 'stock_count_frequency', 'Monthly');
}

/**
 * Get stock count threshold
 * 
 * @param PDO $db Database connection
 * @return float Stock count threshold
 */
function getStockCountThreshold($db) {
    return (float)getStockSetting($db, 'stock_count_threshold', '1000');
}

/**
 * Check if count sheets should be auto-generated
 * 
 * @param PDO $db Database connection
 * @return bool True if count sheets should be auto-generated, false otherwise
 */
function shouldAutoGenerateCountSheets($db) {
    return getStockSetting($db, 'auto_generate_count_sheets', '1') === '1';
}

/**
 * Get low stock alert threshold
 * 
 * @param PDO $db Database connection
 * @return float Low stock alert threshold percentage
 */
function getLowStockAlertThreshold($db) {
    return (float)getStockSetting($db, 'low_stock_alert_threshold', '10');
}

/**
 * Get overstock alert threshold
 * 
 * @param PDO $db Database connection
 * @return float Overstock alert threshold percentage
 */
function getOverstockAlertThreshold($db) {
    return (float)getStockSetting($db, 'overstock_alert_threshold', '20');
}

/**
 * Get stock alert recipients
 * 
 * @param PDO $db Database connection
 * @return array Array of stock alert recipients
 */
function getStockAlertRecipients($db) {
    $recipients_string = getStockSetting($db, 'stock_alert_recipients', 'inventory_manager,warehouse_manager');
    return explode(',', $recipients_string);
}

/**
 * Get alert notification method
 * 
 * @param PDO $db Database connection
 * @return string Alert notification method
 */
function getAlertNotificationMethod($db) {
    return getStockSetting($db, 'alert_notification_method', 'System');
}

/**
 * Get default batch naming format
 * 
 * @param PDO $db Database connection
 * @return string Default batch naming format
 */
function getDefaultBatchNamingFormat($db) {
    return getStockSetting($db, 'default_batch_naming_format', 'BATCH-{PRODUCT_CODE}-{DATE}');
}

/**
 * Get inventory flow method
 * 
 * @param PDO $db Database connection
 * @return string Inventory flow method (FIFO, FEFO, etc.)
 */
function getInventoryFlowMethod($db) {
    return getStockSetting($db, 'inventory_flow_method', 'FIFO');
}

/**
 * Get expiry warning period
 * 
 * @param PDO $db Database connection
 * @return int Expiry warning period in days
 */
function getExpiryWarningPeriod($db) {
    return (int)getStockSetting($db, 'expiry_warning_period', '30');
}

/**
 * Check if expired items should be hidden
 * 
 * @param PDO $db Database connection
 * @return bool True if expired items should be hidden, false otherwise
 */
function shouldHideExpiredItems($db) {
    return getStockSetting($db, 'hide_expired_items', '0') === '1';
}

/**
 * Generate a unique transaction number
 * 
 * @param PDO $db Database connection
 * @param string $transaction_type Type of transaction (optional)
 * @return string Unique transaction number
 */
function generateTransactionNumber($db, $transaction_type = '') {
    try {
        // Get the prefix from settings
        $prefix = getTransactionNumberPrefix($db);
        
        // Add transaction type code if provided
        if (!empty($transaction_type)) {
            $type_code = substr(strtoupper($transaction_type), 0, 2);
            $prefix .= $type_code . '-';
        }
        
        // Get the current date
        $date_code = date('Ymd');
        
        // Get the last transaction number with this prefix and date
        $query = "SELECT transaction_number FROM inventory_transactions 
                 WHERE transaction_number LIKE :prefix 
                 ORDER BY id DESC LIMIT 1";
        $stmt = $db->prepare($query);
        $search_prefix = $prefix . $date_code . '%';
        $stmt->bindParam(':prefix', $search_prefix, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Extract the numeric part and increment
            $code = $result['transaction_number'];
            $numeric_part = substr($code, strlen($prefix) + strlen($date_code));
            $next_number = intval($numeric_part) + 1;
            return $prefix . $date_code . str_pad($next_number, 4, '0', STR_PAD_LEFT);
        } else {
            // First transaction with this prefix and date
            return $prefix . $date_code . '0001';
        }
    } catch (PDOException $e) {
        error_log("Error in generateTransactionNumber: " . $e->getMessage());
        // Fallback to timestamp-based code
        return $prefix . date('YmdHis');
    }
}
