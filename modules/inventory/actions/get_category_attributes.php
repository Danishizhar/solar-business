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

require_once '../../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check if category ID is provided
if (!isset($_GET['category_id']) || empty($_GET['category_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Category ID is required']);
    exit();
}

$category_id = (int)$_GET['category_id'];
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

try {
    // Determine if this is for edit modal
    $is_edit = isset($_GET['prefix']) && $_GET['prefix'] === 'edit';
    $prefix = $is_edit ? 'edit_' : '';
    
    // Get attributes assigned to this category
    $query = "SELECT ad.*, ca.is_required, ca.display_order 
             FROM category_attributes ca
             JOIN attribute_definitions ad ON ca.attribute_id = ad.id
             WHERE ca.category_id = :category_id AND ca.status = 1 AND ad.status = 1
             ORDER BY ca.display_order, ad.attribute_name";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
    $stmt->execute();
    $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure $attributes is always an array
    if ($attributes === false) {
        $attributes = [];  // Convert false to empty array
    }
    
    // If product ID is provided, get attribute values for this product
    $attribute_values = [];
    if ($product_id) {
        $values_query = "SELECT attribute_id, attribute_value 
                        FROM product_attributes 
                        WHERE product_id = :product_id";
        $values_stmt = $db->prepare($values_query);
        $values_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $values_stmt->execute();
        
        while ($row = $values_stmt->fetch(PDO::FETCH_ASSOC)) {
            $attribute_values[$row['attribute_id']] = $row['attribute_value'];
        }
    }
    
    // Format attributes for display
    foreach ($attributes as &$attribute) {
        // Parse options if present
        if (!empty($attribute['options'])) {
            $attribute['options_array'] = json_decode($attribute['options'], true);
        } else {
            $attribute['options_array'] = [];
        }
        
        // Add current value if product ID was provided
        if ($product_id) {
            $attribute['current_value'] = isset($attribute_values[$attribute['id']]) ? $attribute_values[$attribute['id']] : '';
        }
    }
    
    // Make sure we have a valid array of attributes
    if (!is_array($attributes)) {
        $attributes = [];  // Ensure it's an array
    }
    
    // Build HTML output
    $html = '';
    
    if (!empty($attributes)) {
        $html .= '<h6 class="mb-3">Category-specific Attributes</h6>';
        
        foreach ($attributes as $attribute) {
            $required = $attribute['is_required'] ? '<span class="text-danger category-required-indicator">*</span>' : '<span class="category-required-indicator" style="display:none;"><span class="text-danger">*</span></span>';
            $required_attr = $attribute['is_required'] ? 'required' : '';
            
            $html .= '<div class="mb-3">';
            $html .= '<label for="' . $prefix . 'attribute_' . $attribute['id'] . '" class="form-label">' . 
                     htmlspecialchars($attribute['attribute_name']) . $required . '</label>';
            
            // Get attribute value if product ID is provided
            $value = '';
            if ($product_id && isset($attribute_values[$attribute['id']])) {
                $value = $attribute_values[$attribute['id']];
            }
            
            switch ($attribute['input_type']) {
                case 'select':
                    $html .= '<select class="form-select category-specific-attribute" id="' . $prefix . 'attribute_' . $attribute['id'] . '" ' . 
                             'name="attributes[' . $attribute['id'] . ']" ' . $required_attr . '>';
                    $html .= '<option value="">Select ' . htmlspecialchars($attribute['attribute_name']) . '</option>';
                    
                    // Try to parse as JSON first (new format)
                    $options_array = json_decode($attribute['options'], true);
                    
                    // If not valid JSON or not an array, fall back to comma-separated format
                    if (!is_array($options_array)) {
                        $options_array = explode(',', $attribute['options']);
                        // Convert to simple array of strings
                        $options_array = array_map('trim', $options_array);
                    }
                    
                    foreach ($options_array as $option) {
                        // Handle both formats: value/label objects and simple strings
                        if (is_array($option) && isset($option['value']) && isset($option['label'])) {
                            $optionValue = $option['value'];
                            $optionLabel = $option['label'];
                        } else {
                            $optionValue = $option;
                            $optionLabel = $option;
                        }
                        
                        $selected = ($value == $optionValue) ? 'selected' : '';
                        $html .= '<option value="' . htmlspecialchars($optionValue) . '" ' . $selected . '>' . 
                                 htmlspecialchars($optionLabel) . '</option>';
                    }
                    
                    $html .= '</select>';
                    break;
                    
                case 'checkbox':
                    $checked = ($value == '1') ? 'checked' : '';
                    $html .= '<div class="form-check">';
                    $html .= '<input class="form-check-input category-specific-attribute" type="checkbox" id="' . $prefix . 'attribute_' . $attribute['id'] . '" ' . 
                             'name="attributes[' . $attribute['id'] . ']" value="1" ' . $checked . '>';
                    $html .= '<label class="form-check-label" for="' . $prefix . 'attribute_' . $attribute['id'] . '">Yes</label>';
                    $html .= '</div>';
                    break;
                    
                case 'multiselect':
                    // Parse the value if it's JSON
                    $selected_values = [];
                    if (!empty($value)) {
                        if (is_string($value) && (substr($value, 0, 1) === '[' || substr($value, 0, 1) === '{')) {
                            $selected_values = json_decode($value, true);
                            if (!is_array($selected_values)) {
                                $selected_values = [$value]; // Fallback to single value if JSON decode fails
                            }
                        } else {
                            $selected_values = [$value]; // Single value
                        }
                    }
                    
                    // Try to parse options as JSON first (new format)
                    $options_array = json_decode($attribute['options'], true);
                    
                    // If not valid JSON or not an array, fall back to comma-separated format
                    if (!is_array($options_array)) {
                        $options_array = explode(',', $attribute['options']);
                        // Convert to simple array of strings
                        $options_array = array_map('trim', $options_array);
                    }
                    
                    $html .= '<div class="checkbox-group" id="' . $prefix . 'attribute_' . $attribute['id'] . '_group"' .
                             ($required_attr ? ' data-required="true"' : '') . '>';
                    
                    foreach ($options_array as $index => $option) {
                        // Handle both formats: value/label objects and simple strings
                        if (is_array($option) && isset($option['value']) && isset($option['label'])) {
                            $optionValue = $option['value'];
                            $optionLabel = $option['label'];
                        } else {
                            $optionValue = $option;
                            $optionLabel = $option;
                        }
                        
                        $checked = in_array($optionValue, $selected_values) ? 'checked' : '';
                        
                        $html .= '<div class="form-check">';
                        $html .= '<input class="form-check-input category-specific-attribute" type="checkbox" ' .
                                 'id="' . $prefix . 'attribute_' . $attribute['id'] . '_' . $index . '" ' .
                                 'name="attributes[' . $attribute['id'] . '][]" ' .
                                 'value="' . htmlspecialchars($optionValue) . '" ' . $checked . '>';
                        $html .= '<label class="form-check-label" for="' . $prefix . 'attribute_' . $attribute['id'] . '_' . $index . '">'.
                                 htmlspecialchars($optionLabel) . '</label>';
                        $html .= '</div>';
                    }
                    
                    $html .= '</div>';
                    break;
                    
                case 'date':
                    $html .= '<input type="date" class="form-control category-specific-attribute" id="' . $prefix . 'attribute_' . $attribute['id'] . '" ' . 
                             'name="attributes[' . $attribute['id'] . ']" value="' . htmlspecialchars($value) . '" ' . 
                             $required_attr . '>';
                    break;
                    
                case 'textarea':
                    $html .= '<textarea class="form-control category-specific-attribute" id="' . $prefix . 'attribute_' . $attribute['id'] . '" ' . 
                             'name="attributes[' . $attribute['id'] . ']" rows="3" ' . $required_attr . '>' . 
                             htmlspecialchars($value) . '</textarea>';
                    break;
                    
                default: // text input
                    $html .= '<input type="text" class="form-control category-specific-attribute" id="' . $prefix . 'attribute_' . $attribute['id'] . '" ' . 
                             'name="attributes[' . $attribute['id'] . ']" value="' . htmlspecialchars($value) . '" ' . 
                             $required_attr . '>';
                    break;
            }
            
            $html .= '</div>';
        }
    } else {
        $html .= '<p class="text-muted">No attributes defined for this category.</p>';
    }
    
    // Debug
    error_log("Attributes data: " . json_encode($attributes));
    error_log("Response: " . json_encode(['success' => true, 'attributes' => $attributes, 'html' => $html]));
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'attributes' => $attributes, 'html' => $html]);
} catch (PDOException $e) {
    error_log("Database error in get_category_attributes.php: " . $e->getMessage());
    error_log("SQL State: " . $e->getCode());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in get_category_attributes.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
