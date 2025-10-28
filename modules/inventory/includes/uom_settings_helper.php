<?php
/**
 * UOM Settings Helper Functions
 * This file contains helper functions for managing UOM (Units of Measurement) settings
 */

/**
 * Get a specific UOM setting from the database
 * 
 * @param PDO $db Database connection
 * @param string $setting_key The setting key to retrieve
 * @param mixed $default Default value if setting is not found
 * @return mixed The setting value or default if not found
 */
function getUOMSetting($db, $setting_key, $default = null) {
    try {
        $query = "SELECT setting_value FROM inventory_settings 
                 WHERE setting_group = 'uom' AND setting_key = :setting_key";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':setting_key', $setting_key, PDO::PARAM_STR);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['setting_value'];
        }
        
        return $default;
    } catch (PDOException $e) {
        error_log("Error getting UOM setting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Check if auto-generation of UOM codes is enabled
 * 
 * @param PDO $db Database connection
 * @return bool True if auto-generation is enabled, false otherwise
 */
function isAutoGenerateUOMCodeEnabled($db) {
    return getUOMSetting($db, 'auto_generate_uom_code', '1') === '1';
}

/**
 * Get the UOM code prefix from settings
 * 
 * @param PDO $db Database connection
 * @return string The UOM code prefix
 */
function getUOMCodePrefix($db) {
    return getUOMSetting($db, 'uom_code_prefix', 'UOM_');
}

/**
 * Get the allowed UOM types from settings
 * 
 * @param PDO $db Database connection
 * @return array Array of allowed UOM types
 */
function getAllowedUOMTypes($db) {
    $types_str = getUOMSetting($db, 'uom_types', 'Weight,Volume,Length,Area,Time,Quantity,Other');
    return array_map('trim', explode(',', $types_str));
}

/**
 * Get the default UOM type from settings
 * 
 * @param PDO $db Database connection
 * @return string The default UOM type
 */
function getDefaultUOMType($db) {
    return getUOMSetting($db, 'default_uom_type', 'Quantity');
}

/**
 * Check if base unit validation is required
 * 
 * @param PDO $db Database connection
 * @return bool True if base unit validation is required, false otherwise
 */
function isBaseUnitValidationRequired($db) {
    return getUOMSetting($db, 'require_base_unit_validation', '1') === '1';
}

/**
 * Check if conversion rate is required
 * 
 * @param PDO $db Database connection
 * @return bool True if conversion rate is required, false otherwise
 */
function isConversionRateRequired($db) {
    return getUOMSetting($db, 'require_conversion_rate', '1') === '1';
}

/**
 * Get the maximum decimal places for conversion rates
 * 
 * @param PDO $db Database connection
 * @return int Maximum decimal places
 */
function getMaxDecimalPlaces($db) {
    return (int)getUOMSetting($db, 'max_decimal_places', '6');
}

/**
 * Check if UOM can be used for purchase
 * 
 * @param PDO $db Database connection
 * @return bool True if UOM can be used for purchase, false otherwise
 */
function canUOMBeUsedForPurchase($db) {
    return getUOMSetting($db, 'can_be_used_for_purchase', '1') === '1';
}

/**
 * Check if UOM can be used for sales
 * 
 * @param PDO $db Database connection
 * @return bool True if UOM can be used for sales, false otherwise
 */
function canUOMBeUsedForSales($db) {
    return getUOMSetting($db, 'can_be_used_for_sales', '1') === '1';
}

/**
 * Check if UOM can be used for inventory
 * 
 * @param PDO $db Database connection
 * @return bool True if UOM can be used for inventory, false otherwise
 */
function canUOMBeUsedForInventory($db) {
    return getUOMSetting($db, 'can_be_used_for_inventory', '1') === '1';
}
