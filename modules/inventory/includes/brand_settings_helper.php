<?php
/**
 * Brand Settings Helper Functions
 * This file contains helper functions for managing brand settings
 */

/**
 * Get a specific brand setting from the database
 * 
 * @param PDO $db Database connection
 * @param string $setting_key The setting key to retrieve
 * @param mixed $default Default value if setting is not found
 * @return mixed The setting value or default if not found
 */
function getBrandSetting($db, $setting_key, $default = null) {
    try {
        $query = "SELECT setting_value FROM inventory_settings 
                 WHERE setting_group = 'brands' AND setting_key = :setting_key";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':setting_key', $setting_key, PDO::PARAM_STR);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['setting_value'];
        }
        
        return $default;
    } catch (PDOException $e) {
        error_log("Error getting brand setting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Check if auto-generation of brand codes is enabled
 * 
 * @param PDO $db Database connection
 * @return bool True if auto-generation is enabled, false otherwise
 */
function isAutoGenerateBrandCodeEnabled($db) {
    return getBrandSetting($db, 'auto_generate_brand_code', '1') === '1';
}

/**
 * Get the brand code prefix from settings
 * 
 * @param PDO $db Database connection
 * @return string The brand code prefix
 */
function getBrandCodePrefix($db) {
    return getBrandSetting($db, 'brand_code_prefix', 'BRD-');
}

/**
 * Get the brand types from settings
 * 
 * @param PDO $db Database connection
 * @return array Array of brand types
 */
function getBrandTypes($db) {
    $types_str = getBrandSetting($db, 'brand_types', 'Standard,Premium,Economy');
    return array_map('trim', explode(',', $types_str));
}

/**
 * Get the default brand type from settings
 * 
 * @param PDO $db Database connection
 * @return string The default brand type
 */
function getDefaultBrandType($db) {
    return getBrandSetting($db, 'default_brand_type', 'Standard');
}

/**
 * Check if brand logo is required
 * 
 * @param PDO $db Database connection
 * @return bool True if brand logo is required, false otherwise
 */
function isBrandLogoRequired($db) {
    return getBrandSetting($db, 'require_brand_logo', '0') === '1';
}

/**
 * Get maximum allowed logo file size in MB
 * 
 * @param PDO $db Database connection
 * @return int Maximum file size in MB
 */
function getMaxLogoFileSize($db) {
    return (int)getBrandSetting($db, 'max_logo_file_size', '2');
}
