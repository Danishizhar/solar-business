<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to perform this action']);
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && (!isset($_SESSION['permissions']['sales_returns']) || !in_array('delete', $_SESSION['permissions']['sales_returns']))) {
    echo json_encode(['success' => false, 'message' => "You don't have permission to delete sales returns"]);
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Check if id is provided
if (!isset($_POST['id']) || empty($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid return ID']);
    exit();
}

$return_id = $_POST['id'];

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Get return details
    $return_query = "SELECT pr.*, pi.invoice_number 
                    FROM sales_returns pr 
                    JOIN sales_invoices pi ON pr.invoice_id = pi.id 
                    WHERE pr.id = :return_id AND pr.status = 'draft'";
    $return_stmt = $conn->prepare($return_query);
    $return_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $return_stmt->execute();
    $return = $return_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$return) {
        throw new Exception('Sales return not found or not in draft status');
    }
    
    // Check if this return has generated a credit note that has been used
    $credit_note_check_query = "SELECT cn.id, cn.status, cn.remaining_amount, cn.amount 
                                FROM credit_notes cn 
                                WHERE cn.return_id = :return_id";
    $credit_note_check_stmt = $conn->prepare($credit_note_check_query);
    $credit_note_check_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $credit_note_check_stmt->execute();
    $credit_note = $credit_note_check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($credit_note) {
        // Check if credit note has been used (partially or fully)
        if ($credit_note['status'] === 'partially_used' || $credit_note['status'] === 'used') {
            throw new Exception('Cannot delete this return because the associated credit note has been used for customer refunds. Credit Note ID: ' . $credit_note['id']);
        }
        
        // Additional check: if remaining_amount is less than original amount, it means it's been used
        if ($credit_note['remaining_amount'] !== null && $credit_note['remaining_amount'] < $credit_note['amount']) {
            throw new Exception('Cannot delete this return because the associated credit note has been partially used for customer refunds. Credit Note ID: ' . $credit_note['id']);
        }
        
        // If credit note exists but is still 'active' and unused, we need to delete it first
        if ($credit_note['status'] === 'active') {
            // Delete the credit note as well since it hasn't been used
            $delete_credit_note_query = "DELETE FROM credit_notes WHERE id = :credit_note_id";
            $delete_credit_note_stmt = $conn->prepare($delete_credit_note_query);
            $delete_credit_note_stmt->bindParam(':credit_note_id', $credit_note['id'], PDO::PARAM_INT);
            $delete_credit_note_stmt->execute();
        }
    }
    
    // Delete return items
    $delete_items_query = "DELETE FROM sales_return_items WHERE return_id = :return_id";
    $delete_items_stmt = $conn->prepare($delete_items_query);
    $delete_items_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $delete_items_stmt->execute();
    
    // Delete return
    $delete_query = "DELETE FROM sales_returns WHERE id = :return_id";
    $delete_stmt = $conn->prepare($delete_query);
    $delete_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $delete_stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Sales return deleted successfully']);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    error_log('Error in delete_sales_return.php: ' . $e->getMessage());
}
?>
