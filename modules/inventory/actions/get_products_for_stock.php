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

// Include helper functions
require_once '../includes/inventory_helpers.php';

try {
    // Get all active products
    $query = "SELECT id, product_name, product_code, barcode, has_variants, is_variant 
             FROM products 
             WHERE status = 1 
             ORDER BY product_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log the number of products found
    error_log("Found " . count($products) . " products for stock dropdown");
    
    // If no products found, check if the products table exists and has data
    if (count($products) === 0) {
        $check_query = "SHOW TABLES LIKE 'products'";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute();
        $table_exists = $check_stmt->rowCount() > 0;
        
        if (!$table_exists) {
            error_log("Products table does not exist");
        } else {
            // Check if there are any products regardless of status
            $count_query = "SELECT COUNT(*) FROM products";
            $count_stmt = $db->prepare($count_query);
            $count_stmt->execute();
            $total_products = $count_stmt->fetchColumn();
            
            error_log("Total products in database: " . $total_products);
            error_log("Active products (status = 1): " . count($products));
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'products' => $products, 'count' => count($products)]);
} catch (PDOException $e) {
    error_log("Database error in get_products_for_stock.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in get_products_for_stock.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
