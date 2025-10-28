<?php
/**
 * Helper functions for location settings
 */

/**
 * Get a location setting value
 * 
 * @param PDO $db Database connection
 * @param string $setting_key The setting key to retrieve
 * @param mixed $default Default value if setting not found
 * @return mixed The setting value or default if not found
 */
function getLocationSetting($db, $setting_key, $default = null) {
    try {
        $query = "SELECT setting_value FROM inventory_settings WHERE setting_key = ? LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute([$setting_key]);
        
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['setting_value'];
        }
        
        return $default;
    } catch (PDOException $e) {
        error_log("Error getting location setting {$setting_key}: " . $e->getMessage());
        return $default;
    }
}

/**
 * Check if multi-locations is enabled
 * 
 * @param PDO $db Database connection
 * @return bool True if multi-locations is enabled, false otherwise
 */
function isMultiLocationsEnabled($db) {
    return getLocationSetting($db, 'enable_multi_locations', '0') === '1';
}

/**
 * Get available location types from settings
 * 
 * @param PDO $db Database connection
 * @return array Array of location types
 */
function getLocationTypes($db) {
    $types_string = getLocationSetting($db, 'location_types', 'Warehouse,Store,Shelf,Bin,Zone,Section,Other');
    return explode(',', $types_string);
}

/**
 * Get default location type
 * 
 * @param PDO $db Database connection
 * @return string Default location type
 */
function getDefaultLocationType($db) {
    return getLocationSetting($db, 'default_location_type', 'Warehouse');
}

/**
 * Check if branch is required for locations
 * 
 * @param PDO $db Database connection
 * @return bool True if branch is required, false otherwise
 */
function isBranchRequiredForLocations($db) {
    return getLocationSetting($db, 'require_branch_for_locations', '0') === '1';
}

/**
 * Check if hierarchical locations are allowed
 * 
 * @param PDO $db Database connection
 * @return bool True if hierarchical locations are allowed, false otherwise
 */
function areHierarchicalLocationsAllowed($db) {
    return getLocationSetting($db, 'allow_hierarchical_locations', '1') === '1';
}

/**
 * Get maximum hierarchy depth
 * 
 * @param PDO $db Database connection
 * @return int Maximum hierarchy depth (0 for unlimited)
 */
function getMaxHierarchyDepth($db) {
    return (int)getLocationSetting($db, 'max_location_hierarchy_depth', '3');
}

/**
 * Check if auto-generate location code is enabled
 * 
 * @param PDO $db Database connection
 * @return bool True if auto-generate location code is enabled, false otherwise
 */
function isAutoGenerateLocationCodeEnabled($db) {
    return getLocationSetting($db, 'auto_generate_location_code', '1') === '1';
}

/**
 * Get location code prefix
 * 
 * @param PDO $db Database connection
 * @return string Location code prefix
 */
function getLocationCodePrefix($db) {
    return getLocationSetting($db, 'location_code_prefix', 'LOC');
}
?>
