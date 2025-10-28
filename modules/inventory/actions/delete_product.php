<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Clear debug log and start fresh
file_put_contents("../../../debug_log.txt", "Delete product debug log started at " . date("Y-m-d H:i:s") . "\n");

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
    if (empty($_POST['product_id'])) {
        $_SESSION['error_message'] = "Product ID is required";
        header("Location: ../products.php");
        exit();
    }
    
    $product_id = (int)$_POST['product_id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        file_put_contents("../../../debug_log.txt", "Started transaction\n", FILE_APPEND);
        file_put_contents("../../../debug_log.txt", "Product ID to delete: " . $product_id . "\n", FILE_APPEND);
        
        // Check if product exists
        $check_query = "SELECT id, has_variants, product_name FROM products WHERE id = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $product_id, PDO::PARAM_INT);
        $check_stmt->execute();
        $product = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            file_put_contents("../../../debug_log.txt", "Product not found\n", FILE_APPEND);
            throw new Exception("Product does not exist");
        }
        
        // If product has variants, delete them first
        if ($product['has_variants'] == 1) {
            // First delete variant attributes and values
            $delete_variant_values_query = "DELETE pv FROM product_variant_values pv 
                                         JOIN product_variant_attributes pa ON pv.attribute_id = pa.id 
                                         WHERE pa.product_id IN (SELECT id FROM products WHERE parent_product_id = :parent_id)";
            $delete_variant_values_stmt = $db->prepare($delete_variant_values_query);
            $delete_variant_values_stmt->bindParam(':parent_id', $product_id, PDO::PARAM_INT);
            $delete_variant_values_stmt->execute();
            
            // Delete variant attributes
            $delete_variant_attr_query = "DELETE FROM product_variant_attributes 
                                       WHERE product_id IN (SELECT id FROM products WHERE parent_product_id = :parent_id)";
            $delete_variant_attr_stmt = $db->prepare($delete_variant_attr_query);
            $delete_variant_attr_stmt->bindParam(':parent_id', $product_id, PDO::PARAM_INT);
            $delete_variant_attr_stmt->execute();
            
            // Delete variant product attributes
            $delete_var_prod_attr_query = "DELETE FROM product_attributes 
                                        WHERE product_id IN (SELECT id FROM products WHERE parent_product_id = :parent_id)";
            $delete_var_prod_attr_stmt = $db->prepare($delete_var_prod_attr_query);
            $delete_var_prod_attr_stmt->bindParam(':parent_id', $product_id, PDO::PARAM_INT);
            $delete_var_prod_attr_stmt->execute();
            
            // Hard delete variant products
            $variant_query = "DELETE FROM products WHERE parent_product_id = :parent_id AND is_variant = 1";
            $variant_stmt = $db->prepare($variant_query);
            $variant_stmt->bindParam(':parent_id', $product_id, PDO::PARAM_INT);
            $variant_stmt->execute();
        }
        
        // Delete product stock records
        $stock_query = "DELETE FROM inventory_stock WHERE product_id = :product_id";
        $stock_stmt = $db->prepare($stock_query);
        $stock_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stock_stmt->execute();
        
        // Delete product attributes
        $delete_attr_query = "DELETE FROM product_attributes WHERE product_id = :product_id";
        $delete_attr_stmt = $db->prepare($delete_attr_query);
        $delete_attr_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $delete_attr_stmt->execute();
        
        // Delete product variant attributes
        $delete_var_attr_query = "DELETE FROM product_variant_attributes WHERE product_id = :product_id";
        $delete_var_attr_stmt = $db->prepare($delete_var_attr_query);
        $delete_var_attr_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $delete_var_attr_stmt->execute();
        
        // Get product image path before deletion
        $image_query = "SELECT image_path FROM products WHERE id = :product_id";
        $image_stmt = $db->prepare($image_query);
        $image_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $image_stmt->execute();
        $product_image = $image_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Hard delete the product
        $query = "DELETE FROM products WHERE id = :product_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        
        // Delete the product image file if it exists
        if ($product_image && !empty($product_image['image_path'])) {
            $image_path = dirname(dirname(dirname(__DIR__))) . '/' . $product_image['image_path'];
            if (file_exists($image_path) && is_file($image_path)) {
                @unlink($image_path);
            }
        }
        
        $stmt->execute();
        
        $db->commit();
        file_put_contents("../../../debug_log.txt", "Transaction committed successfully\n", FILE_APPEND);
        $_SESSION['success_message'] = "Product deleted successfully";
    } catch (PDOException $e) {
        $db->rollBack();
        file_put_contents("../../../debug_log.txt", "Database error: " . $e->getMessage() . "\n", FILE_APPEND);
        file_put_contents("../../../debug_log.txt", "SQL State: " . $e->getCode() . "\n", FILE_APPEND);
        error_log("Database error in delete_product.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in delete_product.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../products.php");
    exit();
} else {
    // If not a POST request, redirect to products page
    header("Location: ../products.php");
    exit();
}
