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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_edit'])) {
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
    
    // Check if transaction exists and is in 'posted' status
    $check_query = "SELECT je.id, je.status, je.entry_type 
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
        throw new Exception("Only transactions with 'posted' status can be edited.");
    }
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Update journal entry
    $update_query = "UPDATE journal_entries SET 
                    entry_date = :entry_date,
                    description = :description,
                    reference_number = :reference_number,
                    notes = :notes,
                    updated_by = :updated_by,
                    updated_at = NOW()";
    
    // Add financial year if provided
    if (isset($_POST['financial_year_id']) && !empty($_POST['financial_year_id'])) {
        $update_query .= ", financial_year_id = :financial_year_id";
    }
    
    $update_query .= " WHERE id = :id";
    
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bindParam(':entry_date', $_POST['entry_date'], PDO::PARAM_STR);
    $update_stmt->bindParam(':description', $_POST['description'], PDO::PARAM_STR);
    $update_stmt->bindParam(':reference_number', $_POST['reference_number'], PDO::PARAM_STR);
    $update_stmt->bindParam(':notes', $_POST['notes'], PDO::PARAM_STR);
    $update_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $update_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
    
    if (isset($_POST['financial_year_id']) && !empty($_POST['financial_year_id'])) {
        $update_stmt->bindParam(':financial_year_id', $_POST['financial_year_id'], PDO::PARAM_INT);
    }
    
    $update_stmt->execute();
    
    // Update journal entry details based on transaction type
    if ($transaction['entry_type'] === 'receipt' || $transaction['entry_type'] === 'payment') {
        // Update amount in journal entry details
        $update_details_query = "UPDATE journal_entry_details 
                                SET debit_amount = CASE WHEN is_debit = 1 THEN :amount ELSE 0 END,
                                    credit_amount = CASE WHEN is_debit = 0 THEN :amount ELSE 0 END 
                                WHERE journal_entry_id = :id";
        $update_details_stmt = $conn->prepare($update_details_query);
        $update_details_stmt->bindParam(':amount', $_POST['amount'], PDO::PARAM_STR);
        $update_details_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
        $update_details_stmt->execute();
        
        // Update total debit and credit in journal entry
        $update_totals_query = "UPDATE journal_entries 
                               SET total_debit = :amount, 
                                   total_credit = :amount 
                               WHERE id = :id";
        $update_totals_stmt = $conn->prepare($update_totals_query);
        $update_totals_stmt->bindParam(':amount', $_POST['amount'], PDO::PARAM_STR);
        $update_totals_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
        $update_totals_stmt->execute();
        
        // For receipts, update from_account
        if ($transaction['entry_type'] === 'receipt' && isset($_POST['from_account'])) {
            $update_from_query = "UPDATE journal_entry_details 
                                 SET account_id = :from_account 
                                 WHERE journal_entry_id = :id 
                                 AND is_debit = 0";
            $update_from_stmt = $conn->prepare($update_from_query);
            $update_from_stmt->bindParam(':from_account', $_POST['from_account'], PDO::PARAM_INT);
            $update_from_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
            $update_from_stmt->execute();
        }
        
        // For payments, update to_account
        if ($transaction['entry_type'] === 'payment' && isset($_POST['to_account'])) {
            $update_to_query = "UPDATE journal_entry_details 
                               SET account_id = :to_account 
                               WHERE journal_entry_id = :id 
                               AND is_debit = 1";
            $update_to_stmt = $conn->prepare($update_to_query);
            $update_to_stmt->bindParam(':to_account', $_POST['to_account'], PDO::PARAM_INT);
            $update_to_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
            $update_to_stmt->execute();
        }
    } else if ($transaction['entry_type'] === 'transfer' && isset($_POST['to_bank_account'])) {
        // Update amount in journal entry details
        $update_details_query = "UPDATE journal_entry_details 
                                SET debit_amount = CASE WHEN is_debit = 1 THEN :amount ELSE 0 END,
                                    credit_amount = CASE WHEN is_debit = 0 THEN :amount ELSE 0 END 
                                WHERE journal_entry_id = :id";
        $update_details_stmt = $conn->prepare($update_details_query);
        $update_details_stmt->bindParam(':amount', $_POST['amount'], PDO::PARAM_STR);
        $update_details_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
        $update_details_stmt->execute();
        
        // Update total debit and credit in journal entry
        $update_totals_query = "UPDATE journal_entries 
                               SET total_debit = :amount, 
                                   total_credit = :amount 
                               WHERE id = :id";
        $update_totals_stmt = $conn->prepare($update_totals_query);
        $update_totals_stmt->bindParam(':amount', $_POST['amount'], PDO::PARAM_STR);
        $update_totals_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
        $update_totals_stmt->execute();
        
        // Update to_bank_account
        $update_to_query = "UPDATE journal_entry_details 
                           SET account_id = :to_bank_account 
                           WHERE journal_entry_id = :id 
                           AND is_debit = 1";
        $update_to_stmt = $conn->prepare($update_to_query);
        $update_to_stmt->bindParam(':to_bank_account', $_POST['to_bank_account'], PDO::PARAM_INT);
        $update_to_stmt->bindParam(':id', $transaction_id, PDO::PARAM_INT);
        $update_to_stmt->execute();
    }
    
    // Commit transaction
    $conn->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Transaction updated successfully'
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
