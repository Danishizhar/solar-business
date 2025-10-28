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
    $user_id = $_SESSION['user_id'];
    
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
        
        // Delete attribute values for products
        $delete_values_query = "DELETE FROM product_attributes WHERE attribute_id = :attribute_id";
        $delete_values_stmt = $db->prepare($delete_values_query);
        $delete_values_stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
        $delete_values_stmt->execute();
        
        // Delete category attribute mappings
        $delete_mappings_query = "DELETE FROM category_attributes WHERE attribute_id = :attribute_id";
        $delete_mappings_stmt = $db->prepare($delete_mappings_query);
        $delete_mappings_stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
        $delete_mappings_stmt->execute();
        
        // Hard delete the attribute
        $query = "DELETE FROM attribute_definitions WHERE id = :attribute_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Attribute '{$attribute['attribute_name']}' deleted successfully";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in delete_attribute.php: " . $e->getMessage());
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
        
        error_log("Error in delete_attribute.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../attributes.php");
    exit();
} else {
    // If not a POST request, redirect to attributes page
    header("Location: ../attributes.php");
    exit();
}
