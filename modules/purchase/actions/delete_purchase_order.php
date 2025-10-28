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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['purchase_delete'])) {
    header("Location: ../../../index.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if purchase order ID is provided
if (!isset($_POST['purchase_order_id']) || empty($_POST['purchase_order_id'])) {
    header("Location: ../purchase_orders.php?error=" . urlencode("Purchase order ID is required"));
    exit();
}

$purchase_order_id = $_POST['purchase_order_id'];

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Check if purchase order exists and is in draft status
    $check_query = "SELECT status FROM purchase_orders WHERE id = :purchase_order_id";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bindParam(':purchase_order_id', $purchase_order_id);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() == 0) {
        throw new Exception("Purchase order not found");
    }
    
    $purchase_order = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($purchase_order['status'] != 'draft') {
        throw new Exception("Only draft purchase orders can be deleted");
    }
    
    // Delete purchase order items first (due to foreign key constraint)
    $delete_items_query = "DELETE FROM purchase_order_items WHERE purchase_order_id = :purchase_order_id";
    $delete_items_stmt = $conn->prepare($delete_items_query);
    $delete_items_stmt->bindParam(':purchase_order_id', $purchase_order_id);
    $delete_items_stmt->execute();
    
    // Delete purchase order
    $delete_query = "DELETE FROM purchase_orders WHERE id = :purchase_order_id";
    $delete_stmt = $conn->prepare($delete_query);
    $delete_stmt->bindParam(':purchase_order_id', $purchase_order_id);
    $delete_stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    // Redirect with success message
    header("Location: ../purchase_orders.php?success=" . urlencode("Purchase order deleted successfully"));
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    // Redirect with error message
    header("Location: ../purchase_orders.php?error=" . urlencode("Error: " . $e->getMessage()));
    exit();
}
