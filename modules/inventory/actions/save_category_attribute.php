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
    if (empty($_POST['attribute_id'])) {
        $_SESSION['error_message'] = "Attribute ID is required";
        header("Location: ../attributes.php");
        exit();
    }
    
    $attribute_id = (int)$_POST['attribute_id'];
    $category_ids = isset($_POST['category_ids']) ? $_POST['category_ids'] : [];
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    $user_id = $_SESSION['user_id'];
    
    // Check if multiple category assignments are allowed
    $allow_multiple_categories = allowMultipleCategoryAssignments($db);
    if (!$allow_multiple_categories && count($category_ids) > 1) {
        // If multiple assignments are not allowed, only use the first category
        $category_ids = array(reset($category_ids));
    }
    
    try {
        $db->beginTransaction();
        
        // Check if attribute exists
        $check_query = "SELECT id, attribute_name FROM attribute_definitions WHERE id = :id AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $attribute_id, PDO::PARAM_INT);
        $check_stmt->execute();
        $attribute = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$attribute) {
            throw new Exception("Attribute does not exist");
        }
        
        // Delete existing category mappings for this attribute
        $delete_query = "DELETE FROM category_attributes WHERE attribute_id = :attribute_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
        $delete_stmt->execute();
        
        // Insert new category mappings
        if (!empty($category_ids)) {
            $insert_query = "INSERT INTO category_attributes (
                           category_id, attribute_id, is_required, display_order, status,
                           created_by, updated_by, created_at, updated_at
                           ) VALUES (
                           :category_id, :attribute_id, :is_required, :display_order, 1,
                           :created_by, :updated_by, NOW(), NOW()
                           )";
            
            $insert_stmt = $db->prepare($insert_query);
            
            foreach ($category_ids as $index => $category_id) {
                $display_order = $index + 1;
                
                $insert_stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
                $insert_stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
                $insert_stmt->bindParam(':is_required', $is_required, PDO::PARAM_INT);
                $insert_stmt->bindParam(':display_order', $display_order, PDO::PARAM_INT);
                $insert_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
                $insert_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
                
                $insert_stmt->execute();
            }
        }
        
        $db->commit();
        $_SESSION['success_message'] = "Attribute '{$attribute['attribute_name']}' assigned to categories successfully";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in save_category_attribute.php: " . $e->getMessage());
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
        
        error_log("Error in save_category_attribute.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../attributes.php");
    exit();
} else {
    // If not a POST request, redirect to attributes page
    header("Location: ../attributes.php");
    exit();
}
