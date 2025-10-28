<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Debug - log all POST data
error_log("POST data: " . print_r($_POST, true));

// Check if barcode is provided
if (!isset($_POST['barcode']) || empty($_POST['barcode'])) {
    echo json_encode(['success' => false, 'message' => 'Barcode is required']);
    exit();
}

$barcode = trim($_POST['barcode']);
error_log("Looking for barcode: " . $barcode);

try {
    // Use direct query instead of prepared statement to avoid parameter binding issues
    $barcode_escaped = $conn->quote($barcode);
    $query = "SELECT id, product_code, product_name, barcode, cost_price, tax_rate,
              sales_uom_id, base_uom_id
              FROM products 
              WHERE (barcode = $barcode_escaped OR product_code = $barcode_escaped) 
              AND status = 1 AND is_active = 1 
              LIMIT 1";
    
    error_log("Executing query: " . $query);
    $result = $conn->query($query);
    $product = $result->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        error_log("Product found: " . print_r($product, true));
        // Format response
        $response = [
            'success' => true,
            'product' => [
                'id' => $product['id'],
                'text' => "{$product['product_name']} ({$product['product_code']})",
                'unit_price' => $product['cost_price'],
                'tax_rate' => $product['tax_rate'],
                'barcode' => $product['barcode'],
                'sales_uom_id' => $product['sales_uom_id'],
                'base_uom_id' => $product['base_uom_id']
            ]
        ];
        
        echo json_encode($response);
    } else {
        error_log("No product found with barcode: " . $barcode);
        echo json_encode([
            'success' => false, 
            'message' => 'Product not found with this barcode'
        ]);
    }
} catch (Exception $e) {
    // Log error
    error_log("Error fetching product by barcode: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
