<?php
/**
 * General helper functions for the Solar Business application
 */

/**
 * Check if user has a specific permission
 * 
 * @param string $permission The permission to check
 * @return bool True if user has permission, false otherwise
 */
function userHasPermission($permission) {
    // For now, return true to bypass permission checks during development
    return true;
    
    // In production, implement proper permission checking
    /*
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    if ($_SESSION['role_id'] == 1) { // Admin role
        return true;
    }
    
    return isset($_SESSION['permissions'][$permission]) && $_SESSION['permissions'][$permission] === true;
    */
}

/**
 * Format a number as currency
 * 
 * @param float $amount The amount to format
 * @param string $currency The currency symbol (default: $)
 * @return string Formatted currency string
 */
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount, $currency = '$') {
        global $conn;
        // Use the centralized formatNumber helper with the database connection
        // The currency symbol will be handled by formatNumber based on company settings
        return formatNumber($conn, $amount);
    }
}

/**
 * Generate a unique reference number
 * 
 * @param string $prefix Prefix for the reference number
 * @return string Unique reference number
 */
function generateReferenceNumber($prefix = '') {
    $timestamp = time();
    $random = mt_rand(1000, 9999);
    return $prefix . $timestamp . $random;
}

/**
 * Sanitize input data
 * 
 * @param string $data Data to sanitize
 * @return string Sanitized data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Check if a table exists in the database
 * 
 * @param PDO $conn Database connection
 * @param string $tableName Name of the table to check
 * @return bool True if table exists, false otherwise
 */
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}
?>
