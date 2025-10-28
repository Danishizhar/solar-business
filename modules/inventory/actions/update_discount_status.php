<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

// Get POST data
$discount_id = isset($_POST['discount_id']) ? intval($_POST['discount_id']) : 0;
$is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 0;

// Validate input
if ($discount_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid discount ID.']);
    exit();
}

try {
    // Update discount status
    $query = "UPDATE discounts SET 
              is_active = :is_active, 
              updated_by = :updated_by,
              updated_at = CURRENT_TIMESTAMP
              WHERE id = :discount_id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);
    $user_id = $_SESSION['user_id'];
    $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
    
    $stmt->execute();
    
    // Get discount name for activity log
    $name_query = "SELECT discount_name FROM discounts WHERE id = :discount_id";
    $name_stmt = $db->prepare($name_query);
    $name_stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
    $name_stmt->execute();
    $discount_name = $name_stmt->fetchColumn();
    
    // Log activity
    $action = $is_active ? "activate" : "deactivate";
    $description = $is_active ? "Activated discount: $discount_name" : "Deactivated discount: $discount_name";
    
    $log_query = "INSERT INTO activity_logs (
                    user_id, action, module, record_id, description
                ) VALUES (
                    :user_id, :action, 'inventory', :record_id, :description
                )";
    
    $log_stmt = $db->prepare($log_query);
    $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $log_stmt->bindParam(':action', $action, PDO::PARAM_STR);
    $log_stmt->bindParam(':record_id', $discount_id, PDO::PARAM_INT);
    $log_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $log_stmt->execute();
    
    echo json_encode([
        'success' => true, 
        'message' => $is_active ? 'Discount activated successfully.' : 'Discount deactivated successfully.'
    ]);
} catch (PDOException $e) {
    error_log("Error updating discount status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error updating discount status: ' . $e->getMessage()]);
}
?>
