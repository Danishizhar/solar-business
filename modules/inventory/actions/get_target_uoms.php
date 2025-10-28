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
    // Get all UOMs except the current one and those that already have a conversion
    $query = "SELECT u.id, u.uom_name, u.uom_symbol, u.uom_type 
             FROM uom u
             WHERE u.id != ? 
             AND u.status = 1
             AND NOT EXISTS (
                SELECT 1 FROM uom_conversion c 
                WHERE c.from_uom_id = ? AND c.to_uom_id = u.id
             )
             ORDER BY u.uom_type, u.uom_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$uom_id, $uom_id]);
    $uoms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'uoms' => $uoms]);
} catch (PDOException $e) {
    error_log("Database error in get_target_uoms.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in get_target_uoms.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
