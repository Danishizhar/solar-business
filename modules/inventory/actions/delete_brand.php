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
    if (empty($_POST['brand_id'])) {
        $_SESSION['error_message'] = "Brand ID is required";
        header("Location: ../brands.php");
        exit();
    }
    
    $brand_id = (int)$_POST['brand_id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        
        // Check if brand exists
        $check_query = "SELECT id, brand_name, logo_path FROM brands WHERE id = :id AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $brand_id, PDO::PARAM_INT);
        $check_stmt->execute();
        $brand = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$brand) {
            throw new Exception("Brand does not exist");
        }
        
        // Check if products table has brand_id column before trying to update
        $check_column_query = "SHOW COLUMNS FROM products LIKE 'brand_id'";
        $check_column_stmt = $db->prepare($check_column_query);
        $check_column_stmt->execute();
        
        if ($check_column_stmt->rowCount() > 0) {
            // Update products to remove brand association only if brand_id column exists
            $update_products_query = "UPDATE products SET brand_id = NULL, updated_by = :user_id, updated_at = NOW() WHERE brand_id = :brand_id";
            $update_products_stmt = $db->prepare($update_products_query);
            $update_products_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $update_products_stmt->bindParam(':brand_id', $brand_id, PDO::PARAM_INT);
            $update_products_stmt->execute();
        }
        
        // Hard delete the brand
        $query = "DELETE FROM brands WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $brand_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Brand '{$brand['brand_name']}' deleted successfully";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in delete_brand.php: " . $e->getMessage());
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
        
        error_log("Error in delete_brand.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../brands.php");
    exit();
} else {
    // If not a POST request, redirect to brands page
    header("Location: ../brands.php");
    exit();
}
