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
require_once '../includes/category_settings_helper.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    if (empty($_POST['category_id']) || empty($_POST['category_name'])) {
        $_SESSION['error_message'] = "Category ID and name are required";
        header("Location: ../categories.php");
        exit();
    }
    
    $category_id = (int)$_POST['category_id'];
    $category_name = trim($_POST['category_name']);
    $category_code = !empty($_POST['category_code']) ? trim($_POST['category_code']) : null;
    $category_type = !empty($_POST['category_type']) ? trim($_POST['category_type']) : null;
    $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] > 0 ? (int)$_POST['parent_id'] : null;
    $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
    $user_id = $_SESSION['user_id'];
    
    // Get category settings
    $hierarchical_allowed = areHierarchicalCategoriesAllowed($db);
    $max_hierarchy_depth = getCategoryMaxHierarchyDepth($db);
    $auto_generate_code = isAutoGenerateCategoryCodeEnabled($db);
    $category_code_prefix = getCategoryCodePrefix($db);
    $category_types = getCategoryTypes($db);
    
    // Validate category type
    if (empty($category_type) || !in_array($category_type, $category_types)) {
        $_SESSION['error_message'] = "Invalid category type. Allowed types are: " . implode(', ', $category_types);
        header("Location: ../categories.php");
        exit();
    }
    
    // Check if hierarchical categories are allowed
    if (!$hierarchical_allowed && $parent_id !== null) {
        $_SESSION['error_message'] = "Hierarchical categories are not allowed in the current settings.";
        header("Location: ../categories.php");
        exit();
    }
    
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
        
        // Check if parent category exists if provided
        if ($parent_id) {
            // Prevent setting a category as its own parent
            if ($parent_id == $category_id) {
                throw new Exception("A category cannot be its own parent");
            }
            
            $check_query = "SELECT id FROM product_categories WHERE id = :id AND status = 1";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':id', $parent_id, PDO::PARAM_INT);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() === 0) {
                throw new Exception("Parent category does not exist");
            }
            
            // Check hierarchy depth if maximum is set
            if ($max_hierarchy_depth > 0) {
                // Count the depth of the parent category
                $depth_query = "WITH RECURSIVE category_tree AS (
                    SELECT id, parent_id, 0 AS depth
                    FROM product_categories
                    WHERE id = ? AND status = 1
                    UNION ALL
                    SELECT c.id, c.parent_id, ct.depth + 1
                    FROM product_categories c
                    JOIN category_tree ct ON c.id = ct.parent_id
                    WHERE c.status = 1
                )
                SELECT MAX(depth) AS max_depth FROM category_tree";
                
                $depth_stmt = $db->prepare($depth_query);
                $depth_stmt->execute([$parent_id]);
                $depth_result = $depth_stmt->fetch(PDO::FETCH_ASSOC);
                
                $parent_depth = (int)($depth_result['max_depth'] ?? 0);
                
                // If adding this category would exceed the maximum depth
                if ($parent_depth + 1 >= $max_hierarchy_depth) {
                    throw new Exception("Maximum hierarchy depth of {$max_hierarchy_depth} would be exceeded. Current parent depth is {$parent_depth}.");
                }
            }
            
            // Check for circular references
            $current_parent = $parent_id;
            while ($current_parent) {
                $parent_query = "SELECT parent_id FROM product_categories WHERE id = :id AND status = 1";
                $parent_stmt = $db->prepare($parent_query);
                $parent_stmt->bindParam(':id', $current_parent, PDO::PARAM_INT);
                $parent_stmt->execute();
                $parent_result = $parent_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$parent_result) {
                    break;
                }
                
                $current_parent = $parent_result['parent_id'];
                
                if ($current_parent == $category_id) {
                    throw new Exception("Circular reference detected. Cannot set this parent category.");
                }
            }
        }
        
        // Update category
        $query = "UPDATE product_categories SET 
                 category_name = :category_name,
                 category_code = :category_code,
                 category_type = :category_type,
                 parent_id = :parent_id,
                 description = :description,
                 updated_by = :updated_by,
                 updated_at = NOW()
                 WHERE id = :category_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':category_name', $category_name, PDO::PARAM_STR);
        $stmt->bindParam(':category_code', $category_code, PDO::PARAM_STR);
        $stmt->bindParam(':category_type', $category_type, PDO::PARAM_STR);
        $stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Category updated successfully";
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Database error in update_category.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in update_category.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../categories.php");
    exit();
} else {
    // If not a POST request, redirect to categories page
    header("Location: ../categories.php");
    exit();
}
