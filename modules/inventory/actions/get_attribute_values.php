<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Include database connection
require_once '../../../config/database.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

// Check if attribute_id is provided
if (!isset($_POST['attribute_id']) || empty($_POST['attribute_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Attribute ID is required']);
    exit();
}

$attribute_id = intval($_POST['attribute_id']);

try {
    // First, get the attribute details to determine its type
    $attr_query = "SELECT * FROM attribute_definitions WHERE id = :attribute_id";
    $attr_stmt = $db->prepare($attr_query);
    $attr_stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
    $attr_stmt->execute();
    
    $attribute = $attr_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Debug: Log attribute details
    error_log("Attribute details for ID $attribute_id: " . json_encode($attribute));
    
    if (!$attribute) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Attribute not found']);
        exit();
    }
    
    $values = [];
    
    // If the attribute has predefined options (select, multiselect)
    if (in_array($attribute['input_type'], ['select', 'multiselect'])) {
        // Debug: Log options string if available
        if (!empty($attribute['options'])) {
            error_log("Raw options for attribute $attribute_id: " . $attribute['options']);
            
            // Try to parse the JSON options
            $options = json_decode($attribute['options'], true);
            
            // Debug: Log parsed options
            error_log("Parsed options type: " . gettype($options));
            if (is_array($options)) {
                error_log("Parsed options: " . json_encode($options));
            }
            
            // Handle different option formats that might exist in the database
            if (is_array($options)) {
                // Check if it's already in our preferred format (array of objects with value/label)
                if (!empty($options) && isset($options[0]) && is_array($options[0]) && isset($options[0]['value']) && isset($options[0]['label'])) {
                    // Already in the correct format - just use it directly
                    error_log("Options already in correct format");
                    $values = $options;
                } 
                // Check if it's a simple array of strings
                else if (!empty($options) && isset($options[0]) && is_string($options[0])) {
                    error_log("Options is a simple array of strings");
                    foreach ($options as $option) {
                        $values[] = [
                            'value' => strtolower(str_replace(' ', '_', $option)),
                            'label' => $option
                        ];
                    }
                }
                // Check if it's an associative array
                else if (!empty($options)) {
                    error_log("Options appears to be an associative array");
                    foreach ($options as $key => $value) {
                        if (is_string($value)) {
                            $values[] = [
                                'value' => is_string($key) ? $key : $value,
                                'label' => $value
                            ];
                        } else if (is_array($value)) {
                            // Try to handle nested arrays or objects
                            $label = isset($value['label']) ? $value['label'] : (isset($value[0]) ? $value[0] : 'Option ' . $key);
                            $val = isset($value['value']) ? $value['value'] : (is_string($key) ? $key : $label);
                            $values[] = [
                                'value' => $val,
                                'label' => $label
                            ];
                        }
                    }
                }
            } 
            // If options is a string (not JSON)
            else if (is_string($attribute['options']) && trim($attribute['options']) !== '') {
                error_log("Options is a string, not JSON");
                // Try to handle as a comma or newline separated list
                $optionList = preg_split('/[,\n]+/', trim($attribute['options']));
                foreach ($optionList as $option) {
                    $option = trim($option);
                    if (!empty($option)) {
                        $values[] = [
                            'value' => strtolower(str_replace(' ', '_', $option)),
                            'label' => $option
                        ];
                    }
                }
            }
        }
        
        // If no options found or parsing failed, create some sample options
        if (empty($values)) {
            error_log("No valid options found, creating sample options");
            // Create sample options for testing
            $values = [
                ['value' => 'option1', 'label' => 'Option 1'],
                ['value' => 'option2', 'label' => 'Option 2'],
                ['value' => 'option3', 'label' => 'Option 3']
            ];
        }
    } else {
        // For other input types, get unique values from existing products
        $values_query = "SELECT DISTINCT attribute_value FROM product_attributes 
                         WHERE attribute_id = :attribute_id AND attribute_value IS NOT NULL AND attribute_value != ''
                         ORDER BY attribute_value ASC";
        $values_stmt = $db->prepare($values_query);
        $values_stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
        $values_stmt->execute();
        
        while ($row = $values_stmt->fetch(PDO::FETCH_ASSOC)) {
            $values[] = $row['attribute_value'];
        }
    }
    
    // Ensure values are in a consistent format
    $formatted_values = [];
    
    // Convert all values to simple strings for consistency
    if (in_array($attribute['input_type'], ['select', 'multiselect'])) {
        // For select/multiselect, we should have value/label pairs already
        foreach ($values as $value) {
            if (is_array($value) && isset($value['value']) && isset($value['label'])) {
                // Already in correct format
                $formatted_values[] = [
                    'value' => (string)$value['value'],
                    'label' => (string)$value['label']
                ];
            } else if (is_string($value)) {
                // Convert simple string to value/label pair
                $formatted_values[] = [
                    'value' => $value,
                    'label' => $value
                ];
            }
        }
    } else {
        // For other types, convert all values to simple strings
        foreach ($values as $value) {
            if (is_string($value)) {
                $formatted_values[] = $value;
            } else {
                $formatted_values[] = (string)$value;
            }
        }
    }
    
    // Return the values as JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'attribute_id' => $attribute_id,
        'attribute_name' => $attribute['attribute_name'],
        'input_type' => $attribute['input_type'],
        'values' => $formatted_values
    ], JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    error_log("Error fetching attribute values: " . $e->getMessage());
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
