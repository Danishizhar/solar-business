<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once '../../../config/database.php';
require_once '../includes/attribute_settings_helper.php';
$database = new Database();
$db = $database->getConnection();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    if (empty($_POST['attribute_id']) || empty($_POST['attribute_name']) || empty($_POST['attribute_code']) || empty($_POST['input_type'])) {
        $_SESSION['error_message'] = "Attribute ID, name, code, and input type are required";
        header("Location: ../attributes.php");
        exit();
    }
    
    // Check if validation rules are required
    $require_validation = isValidationRulesRequired($db);
    if ($require_validation && empty($_POST['validation_rules'])) {
        $_SESSION['error_message'] = "Validation rules are required";
        header("Location: ../attributes.php");
        exit();
    }
    
    $attribute_id = (int)$_POST['attribute_id'];
    $attribute_name = trim($_POST['attribute_name']);
    $attribute_code = trim($_POST['attribute_code']);
    $input_type = trim($_POST['input_type']);
    $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
    $is_required = isset($_POST['is_required']) ? 1 : 0;

    $validation_rules = !empty($_POST['validation_rules']) ? trim($_POST['validation_rules']) : null;
    $default_value = !empty($_POST['default_value']) ? trim($_POST['default_value']) : null;
    $options = !empty($_POST['options']) ? trim($_POST['options']) : null;
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        
        // Check if attribute exists
        $check_query = "SELECT id, attribute_code FROM attribute_definitions WHERE id = :id AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $attribute_id, PDO::PARAM_INT);
        $check_stmt->execute();
        $attribute = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$attribute) {
            throw new Exception("Attribute does not exist");
        }
        
        // Ensure attribute code hasn't changed
        if ($attribute['attribute_code'] !== $attribute_code) {
            throw new Exception("Attribute code cannot be changed");
        }
        
        // Validate input type against allowed types
        $allowed_input_types = getAllowedInputTypes($db);
        if (!in_array($input_type, $allowed_input_types)) {
            throw new Exception("Invalid input type selected");
        }
        
        // Format options for storage (JSON)
        $options_json = null;
        if ($input_type === 'select' || $input_type === 'multiselect') {
            if (!empty($options)) {
                $options_array = array_map('trim', explode("\n", $options));
                $options_array = array_filter($options_array, function($value) { return $value !== ''; });
                
                // Check maximum options count
                $max_options_count = getMaxOptionsCount($db);
                if (count($options_array) > $max_options_count) {
                    throw new Exception("Maximum of {$max_options_count} options are allowed");
                }
                
                // Convert to value/label pairs format for better compatibility with frontend
                $formatted_options = [];
                foreach ($options_array as $option) {
                    $formatted_options[] = [
                        'value' => strtolower(str_replace(' ', '_', $option)),
                        'label' => $option
                    ];
                }
                
                $options_json = json_encode($formatted_options);
            }
        }
        
        // Update attribute
        $query = "UPDATE attribute_definitions SET 
                 attribute_name = :attribute_name,
                 description = :description,
                 input_type = :input_type,
                 is_required = :is_required,
                 validation_rules = :validation_rules,
                 default_value = :default_value,
                 options = :options,
                 updated_by = :updated_by,
                 updated_at = NOW()
                 WHERE id = :attribute_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':attribute_name', $attribute_name, PDO::PARAM_STR);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':input_type', $input_type, PDO::PARAM_STR);
        $stmt->bindParam(':is_required', $is_required, PDO::PARAM_INT);
        $stmt->bindParam(':validation_rules', $validation_rules, PDO::PARAM_STR);
        $stmt->bindParam(':default_value', $default_value, PDO::PARAM_STR);
        $stmt->bindParam(':options', $options_json, PDO::PARAM_STR);
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Attribute updated successfully";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in update_attribute.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Error in update_attribute.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../attributes.php");
    exit();
} else {
    // If not a POST request, redirect to attributes page
    header("Location: ../attributes.php");
    exit();
}
