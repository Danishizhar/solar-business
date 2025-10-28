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

// Check if product ID is provided
if (!isset($_GET['product_id']) || empty($_GET['product_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Product ID is required']);
    exit();
}

$product_id = (int)$_GET['product_id'];

try {
    // Get variant attributes for the product
    $query = "SELECT va.*, 
             GROUP_CONCAT(DISTINCT vv.attribute_value SEPARATOR ',') as options
             FROM product_variant_attributes va
             LEFT JOIN product_variant_values vv ON va.id = vv.attribute_id
             WHERE va.product_id = :product_id
             GROUP BY va.id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stmt->execute();
    $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'attributes' => $attributes]);
} catch (PDOException $e) {
    error_log("Database error in get_variant_attributes.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in get_variant_attributes.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
