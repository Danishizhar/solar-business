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

// Include helper functions
require_once '../includes/inventory_helpers.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    if (empty($_POST['category_id'])) {
        $_SESSION['error_message'] = "Category ID is required";
        header("Location: ../categories.php");
        exit();
    }
    
    $category_id = (int)$_POST['category_id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        
        // Check if category exists
        $check_query = "SELECT id FROM product_categories WHERE id = :id AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $category_id, PDO::PARAM_INT);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() === 0) {
            throw new Exception("Category does not exist");
        }
        
        // Check if there are child categories
        $child_query = "SELECT COUNT(*) as count FROM product_categories WHERE parent_id = :parent_id AND status = 1";
        $child_stmt = $db->prepare($child_query);
        $child_stmt->bindParam(':parent_id', $category_id, PDO::PARAM_INT);
        $child_stmt->execute();
        $child_result = $child_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($child_result['count'] > 0) {
            // Update child categories to have no parent
            $update_children_query = "UPDATE product_categories SET 
                                    parent_id = NULL,
                                    updated_by = :updated_by,
                                    updated_at = NOW()
                                    WHERE parent_id = :parent_id";
            
            $update_children_stmt = $db->prepare($update_children_query);
            $update_children_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
            $update_children_stmt->bindParam(':parent_id', $category_id, PDO::PARAM_INT);
            $update_children_stmt->execute();
        }
        
        // Update products that use this category to have no category
        $update_products_query = "UPDATE products SET 
                                category_id = NULL,
                                updated_by = :updated_by,
                                updated_at = NOW()
                                WHERE category_id = :category_id";
        
        $update_products_stmt = $db->prepare($update_products_query);
        $update_products_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        $update_products_stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
        $update_products_stmt->execute();
        
        // Hard delete the category
        $query = "DELETE FROM product_categories WHERE id = :category_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Category permanently deleted successfully";
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Database error in delete_category.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in delete_category.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../categories.php");
    exit();
} else {
    // If not a POST request, redirect to categories page
    header("Location: ../categories.php");
    exit();
}
