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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['sales_delete'])) {
    header("Location: ../../../index.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if sales order ID is provided
if (!isset($_POST['sales_order_id']) || empty($_POST['sales_order_id'])) {
    header("Location: ../sales_orders.php?error=" . urlencode("Sales order ID is required"));
    exit();
}

$sales_order_id = $_POST['sales_order_id'];

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Check if sales order exists and is in draft status
    $check_query = "SELECT status FROM sales_orders WHERE id = :sales_order_id";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bindParam(':sales_order_id', $sales_order_id);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() == 0) {
        throw new Exception("Sales order not found");
    }
    
    $sales_order = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sales_order['status'] != 'draft') {
        throw new Exception("Only draft sales orders can be deleted");
    }
    
    // Delete sales order items first (due to foreign key constraint)
    $delete_items_query = "DELETE FROM sales_order_items WHERE sales_order_id = :sales_order_id";
    $delete_items_stmt = $conn->prepare($delete_items_query);
    $delete_items_stmt->bindParam(':sales_order_id', $sales_order_id);
    $delete_items_stmt->execute();
    
    // Delete sales order
    $delete_query = "DELETE FROM sales_orders WHERE id = :sales_order_id";
    $delete_stmt = $conn->prepare($delete_query);
    $delete_stmt->bindParam(':sales_order_id', $sales_order_id);
    $delete_stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    // Redirect with success message
    header("Location: ../sales_orders.php?success=" . urlencode("Sales order deleted successfully"));
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    // Redirect with error message
    header("Location: ../sales_orders.php?error=" . urlencode("Error: " . $e->getMessage()));
    exit();
}
