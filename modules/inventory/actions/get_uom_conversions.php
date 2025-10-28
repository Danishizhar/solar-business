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

// Check if UOM ID is provided
if (!isset($_GET['uom_id']) || empty($_GET['uom_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'UOM ID is required']);
    exit();
}

$uom_id = (int)$_GET['uom_id'];

try {
    // Get conversions for this UOM
    $query = "SELECT c.id, c.from_uom_id, c.to_uom_id, c.conversion_factor,
             f.uom_name as from_uom_name, f.uom_symbol as from_uom_symbol,
             t.uom_name as to_uom_name, t.uom_symbol as to_uom_symbol
             FROM uom_conversion c
             JOIN uom f ON c.from_uom_id = f.id
             JOIN uom t ON c.to_uom_id = t.id
             WHERE c.from_uom_id = ?
             ORDER BY t.uom_type, t.uom_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$uom_id]);
    $conversions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'conversions' => $conversions]);
} catch (PDOException $e) {
    error_log("Database error in get_uom_conversions.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in get_uom_conversions.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
