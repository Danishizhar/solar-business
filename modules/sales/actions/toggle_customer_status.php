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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['sales_edit'])) {
    header("Location: ../../../index.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if customer ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Customer ID is required.";
    header("Location: ../customers.php");
    exit();
}

$customer_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    // Begin transaction
    $conn->beginTransaction();
    
    // Get current customer details
    $customer_query = "SELECT customer_name, status FROM customers WHERE id = ?";
    $customer_stmt = $conn->prepare($customer_query);
    $customer_stmt->execute([$customer_id]);
    $customer_details = $customer_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer_details) {
        throw new Exception("Customer not found with ID: $customer_id");
    }
    
    $customer_name = $customer_details['customer_name'];
    $current_status = $customer_details['status'];
    $new_status = $current_status == 1 ? 0 : 1;
    $status_text = $new_status == 1 ? 'activated' : 'deactivated';
    
    // Update customer status
    $update_query = "UPDATE customers SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->execute([$new_status, $user_id, $customer_id]);
    
    if ($update_stmt->rowCount() == 0) {
        throw new Exception("Failed to update customer status. No rows affected.");
    }
    
    // Log activity
    $log_query = "INSERT INTO activity_logs (user_id, action, module, record_id, description, created_at) 
                  VALUES (?, ?, 'sales', ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->execute([$user_id, 'update', $customer_id, "Customer '$customer_name' $status_text"]);
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success'] = "Customer '$customer_name' has been $status_text successfully.";
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    $error_message = "Error updating customer status: " . $e->getMessage();
    $_SESSION['error'] = $error_message;
}

// Redirect back to customers page
header("Location: ../customers.php");
exit();
?>
