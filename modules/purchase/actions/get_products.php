<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Get pagination parameters - support both GET and POST
$page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;
$limit = 20; // Number of results per page
$offset = ($page - 1) * $limit;

// Initialize response
$response = [
    'products' => [],
    'more' => false
];

try {
    // Get search term and supplier ID - support both GET and POST
    $search = isset($_REQUEST['search']) ? $_REQUEST['search'] : '';
    $supplier_id = isset($_REQUEST['supplier_id']) ? $_REQUEST['supplier_id'] : '';
    
    // Base query
    $query = "SELECT p.id, p.product_code, p.product_name, p.barcode, p.cost_price, p.tax_rate
              FROM products p
              WHERE p.status = 1 AND p.is_active = 1";
    
    // Add search condition if provided
    if (!empty($search)) {
        $search = "%{$search}%";
        $query .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR p.barcode LIKE :search)";
    }
    
    // Add supplier filter if provided (if you have a product_suppliers table)
    // If not, you can remove this condition or modify it based on your database structure
    $hasSupplierFilter = false;
    if (!empty($supplier_id)) {
        // Check if product_suppliers table exists
        $tableCheckQuery = "SHOW TABLES LIKE 'product_suppliers'";
        $tableCheckStmt = $conn->prepare($tableCheckQuery);
        $tableCheckStmt->execute();
        
        if ($tableCheckStmt->rowCount() > 0) {
            $query .= " AND EXISTS (SELECT 1 FROM product_suppliers ps WHERE ps.product_id = p.id AND ps.supplier_id = :supplier_id)";
            $hasSupplierFilter = true;
        }
    }
    
    // Count total results for pagination
    $countQuery = str_replace("SELECT p.id, p.product_code, p.product_name, p.barcode, p.cost_price, p.tax_rate", "SELECT COUNT(*)", $query);
    $countStmt = $conn->prepare($countQuery);
    
    // Bind parameters for count query
    if (!empty($search)) {
        $countStmt->bindParam(':search', $search);
    }
    
    if ($hasSupplierFilter && !empty($supplier_id)) {
        $countStmt->bindParam(':supplier_id', $supplier_id);
    }
    
    $countStmt->execute();
    $totalCount = $countStmt->fetchColumn();
    
    // Order by product name and add pagination
    $query .= " ORDER BY p.product_name ASC LIMIT :offset, :limit";
    
    $stmt = $conn->prepare($query);
    
    // Bind parameters
    if (!empty($search)) {
        $stmt->bindParam(':search', $search);
    }
    
    if ($hasSupplierFilter && !empty($supplier_id)) {
        $stmt->bindParam(':supplier_id', $supplier_id);
    }
    
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format products for Select2
    foreach ($products as $product) {
        $response['products'][] = [
            'id' => $product['id'],
            'text' => "{$product['product_name']} ({$product['product_code']})",
            'unit_price' => $product['cost_price'],  // Using cost_price as unit_price for purchase orders
            'tax_rate' => $product['tax_rate'],
            'barcode' => $product['barcode']
        ];
    }
    
    // Check if there are more results
    $response['more'] = ($offset + $limit) < $totalCount;
    
} catch (PDOException $e) {
    // Log error
    error_log("Error fetching products: " . $e->getMessage());
    $response['error'] = "Error fetching products: " . $e->getMessage();
}

// Debug information
$debug = [
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'get_params' => $_GET,
    'post_params' => $_POST,
    'request_params' => $_REQUEST,
    'search_term' => $search,
    'supplier_id' => $supplier_id,
    'product_count' => count($response['products']),
    'total_count' => $totalCount,
    'query' => $query
];

// Add debug info to response
$response['debug'] = $debug;

// Return JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');
echo json_encode($response);
exit();
