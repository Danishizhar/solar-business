<?php
/**
 * Company Helper Functions
 * Provides centralized access to company, branch, and settings information
 */

/**
 * Get company information
 * 
 * @param PDO $db Database connection
 * @param string $fields Fields to retrieve
 * @return array Company information
 */
function getCompanyInfo($db, $fields = '*') {
    try {
        $query = "SELECT $fields FROM company LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getCompanyInfo: " . $e->getMessage());
        return null;
    }
}



/**
 * Get branch information
 * 
 * @param PDO $db Database connection
 * @param int $branch_id Branch ID
 * @param string $fields Fields to retrieve
 * @return array Branch information
 */
function getBranchInfo($db, $branch_id, $fields = '*') {
    try {
        $query = "SELECT $fields FROM branches WHERE id = :branch_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getBranchInfo: " . $e->getMessage());
        return null;
    }
}



/**
 * Get all active branches
 * 
 * @param PDO $db Database connection
 * @param string $fields Fields to retrieve
 * @return array List of branches
 */
function getAllBranches($db, $fields = '*') {
    try {
        $query = "SELECT $fields FROM branches WHERE status = 1 ORDER BY is_main_branch DESC, branch_name ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getAllBranches: " . $e->getMessage());
        return [];
    }
}



/**
 * Get system setting
 * 
 * @param PDO $db Database connection
 * @param string $key Setting key
 * @param mixed $default Default value if setting not found
 * @return mixed Setting value
 */
function getSystemSetting($db, $key, $default = null) {
    try {
        $query = "SELECT setting_value FROM settings WHERE setting_key = :key";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':key', $key, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        error_log("Error in getSystemSetting: " . $e->getMessage());
        return $default;
    }
}



/**
 * Get all system settings by category
 * 
 * @param PDO $db Database connection
 * @param string $category Setting category
 * @return array Settings in the specified category
 */
function getSettingsByCategory($db, $category = null) {
    try {
        $query = "SELECT * FROM settings";
        $params = [];
        
        if ($category) {
            $query .= " WHERE setting_group = :category";
            $params[':category'] = $category;
        }
        
        $query .= " ORDER BY setting_group, setting_key";
        
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getSettingsByCategory: " . $e->getMessage());
        return [];
    }
}

/**
 * Update a system setting in the settings table
 * 
 * @param PDO $db Database connection
 * @param string $key Setting key to update
 * @param mixed $value New value for the setting
 * @param int $user_id ID of the user making the update
 * @return bool True on success, false on failure
 */
function updateSystemSetting($db, $key, $value, $user_id) {
    try {
        $query = "UPDATE settings SET 
                 setting_value = :value,
                 updated_at = NOW(),
                 updated_by = :user_id
                 WHERE setting_key = :key";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':value', $value, PDO::PARAM_STR);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':key', $key, PDO::PARAM_STR);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Error in updateSystemSetting: " . $e->getMessage());
        return false;
    }
}

/**
 * Format a number according to system settings
 * 
 * @param PDO $db Database connection
 * @param float $number The number to format
 * @param bool $includeDecimal Whether to include decimal places
 * @return string Formatted number
 */
function formatNumber($db, $number) {
    // Get system settings for number formatting
    $decimalPlaces = (int)getSystemSetting($db, 'decimal_places', 2);
    $thousandSeparator = getSystemSetting($db, 'thousand_separator', ',');
    $decimalSeparator = getSystemSetting($db, 'decimal_separator', '.');
    
    // Format the number
    return number_format($number, $decimalPlaces, $decimalSeparator, $thousandSeparator);
}

/**
 * Format a currency amount according to system settings
 * 
 * @param PDO $db Database connection
 * @param float $amount The amount to format
 * @param bool $includeSymbol Whether to include the currency symbol
 * @return string Formatted currency amount
 */
if (!function_exists('formatCurrency')) {
    function formatCurrency($db, $amount, $includeSymbol = true) {
        // Format the number first
        $formattedAmount = formatNumber($db, $amount);
        
        // Add currency symbol if requested
        if ($includeSymbol) {
            $currencySymbol = getSystemSetting($db, 'default_currency_symbol', 'Rs');
            return $currencySymbol . ' ' . $formattedAmount;
        }
        
        return $formattedAmount;
    }
}

/**
 * Format a date according to system settings
 * 
 * @param PDO $db Database connection
 * @param string $date The date to format (can be any format recognized by strtotime)
 * @return string Formatted date
 */
function formatDate($db, $date) {
    if (empty($date)) return '';
    
    $dateFormat = getSystemSetting($db, 'date_format', 'Y-m-d');
    $timestamp = strtotime($date);
    
    if ($timestamp === false) return $date; // Return original if parsing fails
    
    return date($dateFormat, $timestamp);
}

/**
 * Format a time according to system settings
 * 
 * @param PDO $db Database connection
 * @param string $time The time to format (can be any format recognized by strtotime)
 * @return string Formatted time
 */
function formatTime($db, $time) {
    if (empty($time)) return '';
    
    $timeFormat = getSystemSetting($db, 'time_format', 'H:i:s');
    $timestamp = strtotime($time);
    
    if ($timestamp === false) return $time; // Return original if parsing fails
    
    return date($timeFormat, $timestamp);
}

/**
 * Format a datetime according to system settings
 * 
 * @param PDO $db Database connection
 * @param string $datetime The datetime to format (can be any format recognized by strtotime)
 * @return string Formatted datetime
 */
function formatDateTime($db, $datetime) {
    if (empty($datetime)) return '';
    
    $dateFormat = getSystemSetting($db, 'date_format', 'Y-m-d');
    $timeFormat = getSystemSetting($db, 'time_format', 'H:i:s');
    $timestamp = strtotime($datetime);
    
    if ($timestamp === false) return $datetime; // Return original if parsing fails
    
    return date($dateFormat . ' ' . $timeFormat, $timestamp);
}


