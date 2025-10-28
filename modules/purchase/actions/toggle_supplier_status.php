<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['purchase_edit'])) {
    header("Location: ../../../index.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if supplier ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Supplier ID is required.";
    header("Location: ../suppliers.php");
    exit();
}

$supplier_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    // Begin transaction
    $conn->beginTransaction();
    
    // Get current supplier details
    $supplier_query = "SELECT supplier_name, status FROM suppliers WHERE id = ?";
    $supplier_stmt = $conn->prepare($supplier_query);
    $supplier_stmt->execute([$supplier_id]);
    $supplier_details = $supplier_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier_details) {
        throw new Exception("Supplier not found with ID: $supplier_id");
    }
    
    $supplier_name = $supplier_details['supplier_name'];
    $current_status = $supplier_details['status'];
    $new_status = $current_status == 1 ? 0 : 1;
    $status_text = $new_status == 1 ? 'activated' : 'deactivated';
    
    // Update supplier status
    $update_query = "UPDATE suppliers SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->execute([$new_status, $user_id, $supplier_id]);
    
    if ($update_stmt->rowCount() == 0) {
        throw new Exception("Failed to update supplier status. No rows affected.");
    }
    
    // Log activity
    $log_query = "INSERT INTO activity_logs (user_id, action, module, record_id, description, created_at) 
                  VALUES (?, ?, 'purchase', ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->execute([$user_id, 'update', $supplier_id, "Supplier '$supplier_name' $status_text"]);
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success'] = "Supplier '$supplier_name' has been $status_text successfully.";
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    $error_message = "Error updating supplier status: " . $e->getMessage();
    $_SESSION['error'] = $error_message;
}

// Redirect back to suppliers page
header("Location: ../suppliers.php");
exit();
?>
