<?php
/**
 * Helper functions for category settings
 */

/**
 * Get a specific category setting from the database
 * 
 * @param PDO $db Database connection
 * @param string $setting_key The setting key to retrieve
 * @param mixed $default Default value if setting not found
 * @return mixed The setting value or default if not found
 */
function getCategorySetting($db, $setting_key, $default = null) {
    try {
        $query = "SELECT setting_value FROM inventory_settings 
                 WHERE setting_key = ? AND status = 1";
        $stmt = $db->prepare($query);
        $stmt->execute([$setting_key]);
        
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['setting_value'];
        }
        
        return $default;
    } catch (PDOException $e) {
        error_log("Error getting category setting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Check if hierarchical categories are enabled
 * 
 * @param PDO $db Database connection
 * @return bool True if hierarchical categories are enabled
 */
function areHierarchicalCategoriesAllowed($db) {
    return getCategorySetting($db, 'allow_hierarchical_categories', '1') === '1';
}

/**
 * Get the maximum hierarchy depth for categories
 * 
 * @param PDO $db Database connection
 * @return int The maximum hierarchy depth
 */
function getCategoryMaxHierarchyDepth($db) {
    return (int)getCategorySetting($db, 'category_max_hierarchy_depth', '3');
}

/**
 * Check if auto-generation of category codes is enabled
 * 
 * @param PDO $db Database connection
 * @return bool True if auto-generation is enabled
 */
function isAutoGenerateCategoryCodeEnabled($db) {
    return getCategorySetting($db, 'auto_generate_category_code', '1') === '1';
}

/**
 * Get the category code prefix
 * 
 * @param PDO $db Database connection
 * @return string The category code prefix
 */
function getCategoryCodePrefix($db) {
    return getCategorySetting($db, 'category_code_prefix', 'CAT');
}

/**
 * Get the category types
 * 
 * @param PDO $db Database connection
 * @return array The category types
 */
function getCategoryTypes($db) {
    $types_string = getCategorySetting($db, 'category_types', 'Product,Service,Material,Equipment,Other');
    return array_map('trim', explode(',', $types_string));
}

/**
 * Get the default category type
 * 
 * @param PDO $db Database connection
 * @return string The default category type
 */
function getDefaultCategoryType($db) {
    return getCategorySetting($db, 'default_category_type', 'Product');
}
