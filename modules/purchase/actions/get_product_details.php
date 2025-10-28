<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'product' => null
];

try {
    // Check if product ID is provided
    if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
        throw new Exception('Product ID is required');
    }
    
    $product_id = $_POST['product_id'];
    $supplier_id = isset($_POST['supplier_id']) ? $_POST['supplier_id'] : null;
    
    // Get product details
    $query = "SELECT p.id, p.product_code, p.product_name, p.barcode, p.cost_price, p.tax_rate,
              p.purchase_uom_id, p.base_uom_id
              FROM products p
              WHERE p.id = :product_id AND p.status = 1 AND p.is_active = 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':product_id', $product_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        $unit_price = $product['cost_price']; // Default to cost_price
        
        // Check if there's a special price for this supplier
        if (!empty($supplier_id)) {
            // Check if product_suppliers table exists
            $tableCheckQuery = "SHOW TABLES LIKE 'product_suppliers'";
            $tableCheckStmt = $conn->prepare($tableCheckQuery);
            $tableCheckStmt->execute();
            
            if ($tableCheckStmt->rowCount() > 0) {
                // Only execute this query if both product_id and supplier_id are available
                if (!empty($product_id) && !empty($supplier_id)) {
                    $supplier_price_query = "SELECT price 
                                            FROM product_suppliers 
                                            WHERE product_id = :product_id 
                                            AND supplier_id = :supplier_id";
                    
                    $supplier_price_stmt = $conn->prepare($supplier_price_query);
                    $supplier_price_stmt->bindParam(':product_id', $product_id);
                    $supplier_price_stmt->bindParam(':supplier_id', $supplier_id);
                    $supplier_price_stmt->execute();
                    
                    if ($supplier_price_stmt->rowCount() > 0) {
                        $supplier_price = $supplier_price_stmt->fetch(PDO::FETCH_ASSOC);
                        if (!empty($supplier_price['price'])) {
                            $unit_price = $supplier_price['price'];
                        }
                    }
                }
            }
        }
        
        $response['success'] = true;
        $response['product'] = [
            'id' => $product['id'],
            'text' => "{$product['product_name']} ({$product['product_code']})",
            'unit_price' => $unit_price,
            'tax_rate' => $product['tax_rate'],
            'barcode' => $product['barcode'],
            'purchase_uom_id' => $product['purchase_uom_id'],
            'base_uom_id' => $product['base_uom_id']
        ];
    } else {
        throw new Exception('Product not found');
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    
    // Log error
    error_log("Error fetching product details: " . $e->getMessage());
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
exit();
