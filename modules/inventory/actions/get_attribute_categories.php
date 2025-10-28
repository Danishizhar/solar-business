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

// Check if attribute ID is provided
if (!isset($_GET['attribute_id']) || empty($_GET['attribute_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Attribute ID is required']);
    exit();
}

$attribute_id = (int)$_GET['attribute_id'];

try {
    // Get categories assigned to this attribute
    $query = "SELECT ca.*, c.category_name 
             FROM category_attributes ca
             JOIN product_categories c ON ca.category_id = c.id
             WHERE ca.attribute_id = :attribute_id AND ca.status = 1 AND c.status = 1
             ORDER BY ca.display_order";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':attribute_id', $attribute_id, PDO::PARAM_INT);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'categories' => $categories]);
} catch (PDOException $e) {
    error_log("Database error in get_attribute_categories.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in get_attribute_categories.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
