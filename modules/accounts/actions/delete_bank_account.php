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
        error_log("Transaction started");

        // Validate required fields
        if (empty($_POST['id'])) {
            throw new Exception("Bank account ID is required");
        }

        $bank_account_id = $_POST['id'];

        error_log("Attempting to delete bank account ID: " . $bank_account_id);

        // Check if bank account exists and get account_id
        $check_query = "SELECT ba.*, coa.account_name, coa.id as account_id 
                        FROM bank_accounts ba 
                        JOIN chart_of_accounts coa ON ba.account_id = coa.id 
                        WHERE ba.id = :bank_account_id AND ba.status = 1";
        $stmt = $conn->prepare($check_query);
        $stmt->bindParam(':bank_account_id', $bank_account_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $bank_account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$bank_account) {
            throw new Exception("Bank account not found with ID: " . $bank_account_id);
        }

        error_log("Found bank account: " . json_encode($bank_account));

        $account_id = $bank_account['account_id'];
        $account_name = $bank_account['account_name'];

        // Check for journal entries
        $transaction_check_query = "
            SELECT COUNT(*) as count 
            FROM journal_entries je
            INNER JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
            WHERE jed.account_id = :account_id 
            AND je.status = 'posted'";
        
        $stmt = $conn->prepare($transaction_check_query);
        $stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
        $stmt->execute();
        $transaction_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Check for budget entries
        $budget_check_query = "
            SELECT COUNT(*) as count 
            FROM budgets 
            WHERE account_id = :account_id 
            AND status = 1";
            
        $stmt = $conn->prepare($budget_check_query);
        $stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
        $stmt->execute();
        $budget_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Check for bank reconciliation entries
        $reconciliation_check_query = "
            SELECT COUNT(*) as count 
            FROM bank_reconciliation 
            WHERE bank_account_id = :account_id";
            
        $stmt = $conn->prepare($reconciliation_check_query);
        $stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
        $stmt->execute();
        $reconciliation_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $total_references = $transaction_count + $budget_count + $reconciliation_count;
        error_log("References found - Transactions: $transaction_count, Budgets: $budget_count, Reconciliations: $reconciliation_count, Total: $total_references");

        // For debugging, get actual records
        if ($budget_count > 0) {
            $budget_details_query = "
                SELECT id, financial_year, amount, status
                FROM budgets 
                WHERE account_id = :account_id 
                AND status = 1";
            $stmt = $conn->prepare($budget_details_query);
            $stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
            $stmt->execute();
            $budget_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Budget details: " . json_encode($budget_details));
        }

        if ($transaction_count > 0) {
            $transaction_details_query = "
                SELECT je.id, je.entry_number, je.entry_date, je.status, jed.debit_amount, jed.credit_amount
                FROM journal_entries je
                INNER JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                WHERE jed.account_id = :account_id 
                AND je.status = 'posted'
                LIMIT 5";
            $stmt = $conn->prepare($transaction_details_query);
            $stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
            $stmt->execute();
            $transaction_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Transaction details (first 5): " . json_encode($transaction_details));
        }

        if ($total_references > 0) {
            // Has transactions or budget entries - throw error instead of soft delete
            error_log("Cannot delete bank account with references. Transaction count: $transaction_count, Budget count: $budget_count");
            
            // Rollback transaction
            $conn->rollBack();
            
            // Return error message
            echo json_encode([
                'success' => false, 
                'message' => 'Cannot delete bank account "' . $account_name . '" because it has ' . 
                             ($transaction_count > 0 ? $transaction_count . ' transaction(s)' : '') . 
                             (($transaction_count > 0 && ($budget_count > 0 || $reconciliation_count > 0)) ? ', ' : '') .
                             ($budget_count > 0 ? $budget_count . ' budget(s)' : '') . 
                             ((($transaction_count > 0 || $budget_count > 0) && $reconciliation_count > 0) ? ' and ' : '') .
                             ($reconciliation_count > 0 ? $reconciliation_count . ' reconciliation record(s)' : '') . 
                             ' associated with it.'
            ]);
            exit();
        } else {
            // No transactions or budget entries - do hard delete
            error_log("Performing hard delete for bank account without references");
            
            // First delete from budgets
            $delete_budget_query = "DELETE FROM budgets WHERE account_id = :id";
            $stmt = $conn->prepare($delete_budget_query);
            $stmt->bindParam(':id', $account_id, PDO::PARAM_INT);
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete from budgets. Error: " . json_encode($stmt->errorInfo()));
            }
            
            // Delete from bank_reconciliation_items
            $delete_recon_items_query = "DELETE bri FROM bank_reconciliation_items bri 
                                        JOIN bank_reconciliation br ON bri.reconciliation_id = br.id 
                                        WHERE br.bank_account_id = :id";
            $stmt = $conn->prepare($delete_recon_items_query);
            $stmt->bindParam(':id', $account_id, PDO::PARAM_INT);
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete from bank_reconciliation_items. Error: " . json_encode($stmt->errorInfo()));
            }
            
            // Delete from bank_reconciliation
            $delete_recon_query = "DELETE FROM bank_reconciliation WHERE bank_account_id = :id";
            $stmt = $conn->prepare($delete_recon_query);
            $stmt->bindParam(':id', $account_id, PDO::PARAM_INT);
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete from bank_reconciliation. Error: " . json_encode($stmt->errorInfo()));
            }

            // Then delete bank account
            $delete_bank_query = "DELETE FROM bank_accounts WHERE id = :id";
            $stmt = $conn->prepare($delete_bank_query);
            $stmt->bindParam(':id', $bank_account_id, PDO::PARAM_INT);
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete from bank_accounts. Error: " . json_encode($stmt->errorInfo()));
            }

            // Finally delete from chart of accounts
            $delete_coa_query = "DELETE FROM chart_of_accounts WHERE id = :id";
            $stmt = $conn->prepare($delete_coa_query);
            $stmt->bindParam(':id', $account_id, PDO::PARAM_INT);
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete from chart_of_accounts. Error: " . json_encode($stmt->errorInfo()));
            }
        }

        // Commit transaction
        $conn->commit();
        error_log("Successfully committed transaction");

        // Log the activity
        error_log("User " . $_SESSION['user_id'] . " permanently deleted bank account " . $account_name);

        echo json_encode([
            'success' => true, 
            'message' => 'Bank account deleted successfully'
        ]);

    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
            error_log("Transaction rolled back");
        }
        error_log("Error in delete_bank_account.php: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode([
            'success' => false, 
            'message' => 'Error occurred while deleting bank account: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
