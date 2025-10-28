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

// Check if product ID is provided
if (!isset($_GET['product_id']) || empty($_GET['product_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Product ID is required']);
    exit();
}

$product_id = intval($_GET['product_id']);

try {
    // Get product data
    $query = "SELECT * FROM products WHERE id = :product_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }
    
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get product attributes with their input types
    $attributes = [];
    $attr_query = "SELECT pa.*, ad.input_type 
                  FROM product_attributes pa 
                  LEFT JOIN attribute_definitions ad ON pa.attribute_id = ad.id 
                  WHERE pa.product_id = :product_id";
    $attr_stmt = $db->prepare($attr_query);
    $attr_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $attr_stmt->execute();
    
    while ($row = $attr_stmt->fetch(PDO::FETCH_ASSOC)) {
        // For multiselect attributes, ensure they're in JSON format
        if ($row['input_type'] === 'multiselect' && !empty($row['attribute_value'])) {
            // Check if it's already valid JSON
            if (json_decode($row['attribute_value']) === null && json_last_error() !== JSON_ERROR_NONE) {
                // If it's not valid JSON, convert it to JSON
                if (is_string($row['attribute_value']) && strpos($row['attribute_value'], 'Array') === 0) {
                    // This is a PHP array string representation, not useful for frontend
                    // Set an empty array as fallback
                    $attributes[$row['attribute_id']] = json_encode([]);
                } else {
                    // Try to convert to JSON
                    $attributes[$row['attribute_id']] = json_encode([$row['attribute_value']]);
                }
            } else {
                // It's already valid JSON, use as is
                $attributes[$row['attribute_id']] = $row['attribute_value'];
            }
        } else {
            // For non-multiselect attributes, use the value as is
            $attributes[$row['attribute_id']] = $row['attribute_value'];
        }
    }
    
    // Return the product data and attributes
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'product' => $product,
        'attributes' => $attributes
    ]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}
?>
