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
    $customer_id = isset($_POST['customer_id']) ? $_POST['customer_id'] : null;
    
    // Get product details
    $query = "SELECT p.id, p.product_code, p.product_name, p.barcode, p.cost_price, p.tax_rate,
              p.sales_uom_id, p.base_uom_id
              FROM products p
              WHERE p.id = :product_id AND p.status = 1 AND p.is_active = 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':product_id', $product_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        $unit_price = $product['cost_price']; // Default to cost_price
        
        // Check if there's a special price for this customer
        if (!empty($customer_id)) {
            // Check if product_customers table exists
            $tableCheckQuery = "SHOW TABLES LIKE 'product_customers'";
            $tableCheckStmt = $conn->prepare($tableCheckQuery);
            $tableCheckStmt->execute();
            
            if ($tableCheckStmt->rowCount() > 0) {
                // Only execute this query if both product_id and customer_id are available
                if (!empty($product_id) && !empty($customer_id)) {
                    $customer_price_query = "SELECT price 
                                            FROM product_customers 
                                            WHERE product_id = :product_id 
                                            AND customer_id = :customer_id";
                    
                    $customer_price_stmt = $conn->prepare($customer_price_query);
                    $customer_price_stmt->bindParam(':product_id', $product_id);
                    $customer_price_stmt->bindParam(':customer_id', $customer_id);
                    $customer_price_stmt->execute();
                    
                    if ($customer_price_stmt->rowCount() > 0) {
                        $customer_price = $customer_price_stmt->fetch(PDO::FETCH_ASSOC);
                        if (!empty($customer_price['price'])) {
                            $unit_price = $customer_price['price'];
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
            'sales_uom_id' => $product['sales_uom_id'],
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
