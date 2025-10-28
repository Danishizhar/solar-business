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
    if (empty($_POST['category_name'])) {
        $_SESSION['error_message'] = "Category name is required";
        header("Location: ../categories.php");
        exit();
    }
    
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
        
        // Generate category code if auto-generation is enabled
        if (empty($category_code)) {
            if (!$auto_generate_code) {
                throw new Exception("Category code is required when auto-generation is disabled.");
            }
            
            // Create a code based on the category name with prefix
            $name_part = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $category_name), 0, 5));
            $category_code = $category_code_prefix . $name_part;
            
            // Check if code exists and append numbers if needed
            $check_query = "SELECT COUNT(*) as count FROM product_categories WHERE category_code = :code";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':code', $category_code, PDO::PARAM_STR);
            $check_stmt->execute();
            $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                $category_code .= rand(10, 99);
            }
        }
        
        // Check if parent category exists if provided
        if ($parent_id) {
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
        }
        
        // Insert new category
        $query = "INSERT INTO product_categories (
                 category_name, category_code, category_type, parent_id, description,
                 status, created_by, updated_by, created_at, updated_at
                 ) VALUES (
                 :category_name, :category_code, :category_type, :parent_id, :description,
                 1, :created_by, :updated_by, NOW(), NOW()
                 )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':category_name', $category_name, PDO::PARAM_STR);
        $stmt->bindParam(':category_code', $category_code, PDO::PARAM_STR);
        $stmt->bindParam(':category_type', $category_type, PDO::PARAM_STR);
        $stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Category added successfully";
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Database error in save_category.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in save_category.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../categories.php");
    exit();
} else {
    // If not a POST request, redirect to categories page
    header("Location: ../categories.php");
    exit();
}
