<?php
/**
 * Get Current Stock Quantity
 * 
 * This script returns the current stock quantity for a specific product at a specific location.
 * It is used by the Quick Adjust Stock modal to show the current quantity when a product and location are selected.
 */

// Include necessary files
require_once '../../../config/database.php';

// Include the helper files that do exist
require_once '../includes/inventory_helpers.php';
require_once '../includes/stock_settings_helper.php';

// For debugging
error_log('get_current_stock.php called with product_id=' . ($_GET['product_id'] ?? 'none') . ' and location_id=' . ($_GET['location_id'] ?? 'none'));

// Initialize database connection
$db = new Database();

// Set response header
header('Content-Type: application/json');

// Check if product_id and location_id are provided
if (!isset($_GET['product_id']) || !isset($_GET['location_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Product ID and Location ID are required'
    ]);
    exit;
}

// Get product_id and location_id
$product_id = intval($_GET['product_id']);
$location_id = intval($_GET['location_id']);

// Validate product_id and location_id
if ($product_id <= 0 || $location_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid Product ID or Location ID'
    ]);
    exit;
}

try {
    // Get the database connection
    $conn = $db->getConnection();
    
    // Get current stock quantity
    $query = "SELECT id, quantity FROM inventory_stock 
              WHERE product_id = :product_id AND location_id = :location_id";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stock) {
        // Stock record exists
        echo json_encode([
            'success' => true,
            'stock_id' => $stock['id'],
            'quantity' => $stock['quantity']
        ]);
    } else {
        // Stock record doesn't exist (quantity is 0)
        echo json_encode([
            'success' => true,
            'stock_id' => null,
            'quantity' => 0
        ]);
    }
} catch (PDOException $e) {
    // Log error
    error_log('Database error in get_current_stock.php: ' . $e->getMessage());
    
    // Return error response
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
