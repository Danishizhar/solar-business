<?php
/**
 * Attribute Settings Helper Functions
 * This file contains helper functions for managing attribute settings
 */

/**
 * Get a specific attribute setting from the database
 * 
 * @param PDO $db Database connection
 * @param string $setting_key The setting key to retrieve
 * @param mixed $default Default value if setting is not found
 * @return mixed The setting value or default if not found
 */
function getAttributeSetting($db, $setting_key, $default = null) {
    try {
        $query = "SELECT setting_value FROM inventory_settings 
                 WHERE setting_group = 'attributes' AND setting_key = :setting_key";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':setting_key', $setting_key, PDO::PARAM_STR);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['setting_value'];
        }
        
        return $default;
    } catch (PDOException $e) {
        error_log("Error getting attribute setting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Check if auto-generation of attribute codes is enabled
 * 
 * @param PDO $db Database connection
 * @return bool True if auto-generation is enabled, false otherwise
 */
function isAutoGenerateAttributeCodeEnabled($db) {
    return getAttributeSetting($db, 'auto_generate_attribute_code', '1') === '1';
}

/**
 * Get the attribute code prefix from settings
 * 
 * @param PDO $db Database connection
 * @return string The attribute code prefix
 */
function getAttributeCodePrefix($db) {
    return getAttributeSetting($db, 'attribute_code_prefix', 'ATTR_');
}

/**
 * Get the allowed input types from settings
 * 
 * @param PDO $db Database connection
 * @return array Array of allowed input types
 */
function getAllowedInputTypes($db) {
    $types_str = getAttributeSetting($db, 'allowed_input_types', 'text,textarea,select,multiselect,number,date,boolean,color,file');
    return array_map('trim', explode(',', $types_str));
}

/**
 * Get the default input type from settings
 * 
 * @param PDO $db Database connection
 * @return string The default input type
 */
function getDefaultInputType($db) {
    return getAttributeSetting($db, 'default_input_type', 'text');
}

/**
 * Check if validation rules are required
 * 
 * @param PDO $db Database connection
 * @return bool True if validation rules are required, false otherwise
 */
function isValidationRulesRequired($db) {
    return getAttributeSetting($db, 'require_validation_rules', '0') === '1';
}

/**
 * Get the maximum number of options for select/multiselect types
 * 
 * @param PDO $db Database connection
 * @return int Maximum number of options
 */
function getMaxOptionsCount($db) {
    return (int)getAttributeSetting($db, 'max_options_count', '50');
}

/**
 * Check if attributes can be assigned to multiple categories
 * 
 * @param PDO $db Database connection
 * @return bool True if multiple category assignments are allowed
 */
function allowMultipleCategoryAssignments($db) {
    return getAttributeSetting($db, 'allow_multiple_category_assignments', '1') === '1';
}


