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

// Validate input
if ($discount_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid discount ID.']);
    exit();
}

try {
    // Start transaction
    $db->beginTransaction();
    
    // Get discount name for activity log
    $name_query = "SELECT discount_name FROM discounts WHERE id = :discount_id";
    $name_stmt = $db->prepare($name_query);
    $name_stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
    $name_stmt->execute();
    $discount_name = $name_stmt->fetchColumn();
    
    // Check if discount has been used
    $usage_query = "SELECT COUNT(*) FROM discount_usage WHERE discount_id = :discount_id";
    $usage_stmt = $db->prepare($usage_query);
    $usage_stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
    $usage_stmt->execute();
    
    $has_usage = ($usage_stmt->fetchColumn() > 0);
    
    if ($has_usage) {
        // Soft delete - just update status to 0
        $query = "UPDATE discounts SET 
                  status = 0, 
                  updated_by = :updated_by,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :discount_id";
        
        $stmt = $db->prepare($query);
        $user_id = $_SESSION['user_id'];
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
        
        $stmt->execute();
    } else {
        // Hard delete - remove conditions first
        $delete_conditions_query = "DELETE FROM discount_conditions WHERE discount_id = :discount_id";
        $delete_conditions_stmt = $db->prepare($delete_conditions_query);
        $delete_conditions_stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
        $delete_conditions_stmt->execute();
        
        // Then delete the discount
        $delete_query = "DELETE FROM discounts WHERE id = :discount_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':discount_id', $discount_id, PDO::PARAM_INT);
        $delete_stmt->execute();
    }
    
    // Log activity
    $user_id = $_SESSION['user_id'];
    $action = "delete";
    $description = "Deleted discount: $discount_name";
    
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
    
    // Commit transaction
    $db->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Discount deleted successfully.'
    ]);
} catch (PDOException $e) {
    // Rollback transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Error deleting discount: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error deleting discount: ' . $e->getMessage()]);
}
?>
