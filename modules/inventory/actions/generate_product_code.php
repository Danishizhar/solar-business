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

// Check if product name is provided
if (!isset($_POST['product_name']) || empty($_POST['product_name'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Product name is required']);
    exit();
}

$product_name = trim($_POST['product_name']);
$prefix = isset($_POST['prefix']) && !empty($_POST['prefix']) ? trim($_POST['prefix']) : 'P';

try {
    // Generate a unique product code
    $product_code = generateProductCode($db, $prefix);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'product_code' => $product_code]);
} catch (PDOException $e) {
    error_log("Database error in generate_product_code.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in generate_product_code.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
