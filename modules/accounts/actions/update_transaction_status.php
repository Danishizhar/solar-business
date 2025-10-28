<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_delete'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $conn->beginTransaction();

        // Validate required fields
        if (empty($_POST['id'])) {
            throw new Exception("Transaction ID is required");
        }

        // Check if transaction exists
        $check_query = "
            SELECT je.*, 
                   coa.account_code, coa.account_name,
                   ba.bank_name, ba.account_number
            FROM journal_entries je
            INNER JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
            INNER JOIN chart_of_accounts coa ON coa.id = jed.account_id
            INNER JOIN bank_accounts ba ON ba.account_id = coa.id
            WHERE je.id = :id 
            AND je.status IN ('posted', 'approved')
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $check_stmt->execute();
        
        $transaction = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transaction) {
            throw new Exception("Transaction not found or cannot be deleted");
        }

        error_log("Transaction found: " . json_encode($transaction));

        // Check if transaction is reconciled
        $reconciled_query = "
            SELECT COUNT(*) as reconciled_count
            FROM bank_reconciliation_details brd
            INNER JOIN bank_reconciliation br ON br.id = brd.reconciliation_id
            WHERE brd.journal_entry_id = :id
            AND br.status = 'completed'
        ";
        $reconciled_stmt = $conn->prepare($reconciled_query);
        $reconciled_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $reconciled_stmt->execute();
        
        $result = $reconciled_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['reconciled_count'] > 0) {
            throw new Exception("Cannot delete a reconciled transaction");
        }

        error_log("Transaction is not reconciled");

        // Check for newer transactions
        $newer_query = "
            SELECT COUNT(*) as newer_count 
            FROM journal_entries 
            WHERE entry_date > :entry_date
            AND status IN ('posted', 'approved')
        ";
        $newer_stmt = $conn->prepare($newer_query);
        $newer_stmt->bindParam(':entry_date', $transaction['entry_date']);
        $newer_stmt->execute();
        
        $result = $newer_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['newer_count'] > 0) {
            throw new Exception("Cannot delete transaction: There are {$result['newer_count']} newer transactions. Please delete them first.");
        }

        error_log("No newer transactions found");

        // Update transaction status
        $update_query = "
            UPDATE journal_entries 
            SET 
                status = 'cancelled',
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :id 
            AND status IN ('posted', 'approved')
        ";

        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);

        if (!$update_stmt->execute()) {
            $error = $update_stmt->errorInfo();
            error_log("Error executing update: " . json_encode($error));
            throw new Exception("Error cancelling transaction: " . $error[2]);
        }

        if ($update_stmt->rowCount() === 0) {
            throw new Exception("Transaction not found or already cancelled");
        }

        error_log("Transaction status updated successfully");

        // Update bank account balance
        $balance_query = "
            UPDATE bank_accounts ba
            SET 
                current_balance = (
                    SELECT COALESCE(SUM(
                        CASE 
                            WHEN jed.account_id = ba.account_id THEN jed.debit_amount - jed.credit_amount
                            ELSE 0
                        END
                    ), 0)
                    FROM journal_entries je
                    JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                    WHERE je.status IN ('posted', 'approved')
                ),
                updated_by = :user_id,
                updated_at = NOW()
            WHERE ba.status = 1
            AND EXISTS (
                SELECT 1 
                FROM journal_entry_details jed2 
                WHERE jed2.journal_entry_id = :id 
                AND jed2.account_id = ba.account_id
            )
        ";

        $balance_stmt = $conn->prepare($balance_query);
        $balance_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $balance_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);

        if (!$balance_stmt->execute()) {
            $error = $balance_stmt->errorInfo();
            error_log("Error executing balance update: " . json_encode($error));
            throw new Exception("Error updating bank account balance: " . $error[2]);
        }

        error_log("Bank account balance updated successfully");

        // Commit transaction
        $conn->commit();
        error_log("Transaction committed successfully");
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction cancelled successfully',
            'data' => [
                'id' => $transaction['id'],
                'entry_number' => $transaction['entry_number'],
                'entry_date' => $transaction['entry_date'],
                'bank_account' => [
                    'account_code' => $transaction['account_code'],
                    'account_name' => $transaction['account_name'],
                    'bank_name' => $transaction['bank_name'],
                    'account_number' => $transaction['account_number']
                ]
            ]
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("PDOException: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("Exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
