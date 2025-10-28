<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_delete'])) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

try {
    // Validate required fields
    if (!isset($_POST['id']) || empty($_POST['id'])) {
        throw new Exception("Transaction ID is required.");
    }
    
    $transaction_id = $_POST['id'];
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Check if transaction exists and is in 'posted' status
    $check_query = "SELECT je.id, je.status 
                    FROM journal_entries je 
                    WHERE je.id = :id";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
    $check_stmt->execute();
    $transaction = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        throw new Exception("Transaction not found.");
    }
    
    if ($transaction['status'] !== 'posted') {
        throw new Exception("Only transactions with 'posted' status can be deleted.");
    }
    
    // Delete journal entry details
    $delete_details_query = "DELETE FROM journal_entry_details WHERE journal_entry_id = :id";
    $delete_details_stmt = $conn->prepare($delete_details_query);
    $delete_details_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
    $delete_details_stmt->execute();
    
    // Delete journal entry
    $delete_entry_query = "DELETE FROM journal_entries WHERE id = :id";
    $delete_entry_stmt = $conn->prepare($delete_entry_query);
    $delete_entry_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
    $delete_entry_stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Transaction deleted successfully'
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($conn) {
        $conn->rollBack();
    }
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again.']);
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
