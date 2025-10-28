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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_edit'])) {
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
            throw new Exception("Bank account ID is required");
        }

        // Check if bank account exists and get related account info
        $check_query = "
            SELECT b.*, 
                   a.id as account_id, a.account_code, a.account_name 
            FROM bank_accounts b 
            INNER JOIN chart_of_accounts a ON a.id = b.account_id 
            WHERE b.id = :id 
            AND b.status = 1 
            AND a.status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $check_stmt->execute();
        
        $bank_account = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bank_account) {
            throw new Exception("Bank account not found");
        }

        error_log("Bank account found: " . json_encode($bank_account));

        // Check for journal entries using this bank account
        $entry_query = "
            SELECT COUNT(*) as entry_count 
            FROM journal_entry_details 
            WHERE account_id = :account_id 
            AND status = 1
        ";
        $entry_stmt = $conn->prepare($entry_query);
        $entry_stmt->bindParam(':account_id', $bank_account['account_id'], PDO::PARAM_INT);
        $entry_stmt->execute();
        
        $result = $entry_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['entry_count'] > 0) {
            throw new Exception("Cannot delete bank account: It has {$result['entry_count']} associated journal entries. Please delete or reassign the journal entries first.");
        }

        error_log("No associated journal entries found");

        // Check for reconciliations
        $reconciliation_query = "
            SELECT COUNT(*) as reconciliation_count 
            FROM bank_reconciliations 
            WHERE bank_account_id = :bank_account_id 
            AND status = 1
        ";
        $reconciliation_stmt = $conn->prepare($reconciliation_query);
        $reconciliation_stmt->bindParam(':bank_account_id', $bank_account['id'], PDO::PARAM_INT);
        $reconciliation_stmt->execute();
        
        $result = $reconciliation_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['reconciliation_count'] > 0) {
            throw new Exception("Cannot delete bank account: It has {$result['reconciliation_count']} bank reconciliations. Please delete the reconciliations first.");
        }

        error_log("No bank reconciliations found");

        // Check for bank transactions
        $transaction_query = "
            SELECT COUNT(*) as transaction_count 
            FROM bank_transactions 
            WHERE bank_account_id = :bank_account_id 
            AND status = 1
        ";
        $transaction_stmt = $conn->prepare($transaction_query);
        $transaction_stmt->bindParam(':bank_account_id', $bank_account['id'], PDO::PARAM_INT);
        $transaction_stmt->execute();
        
        $result = $transaction_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['transaction_count'] > 0) {
            throw new Exception("Cannot delete bank account: It has {$result['transaction_count']} bank transactions. Please delete the transactions first.");
        }

        error_log("No bank transactions found");

        // Update bank account status
        $update_query = "
            UPDATE bank_accounts 
            SET 
                status = 0,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :id 
            AND status = 1
        ";

        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);

        if (!$update_stmt->execute()) {
            $error = $update_stmt->errorInfo();
            error_log("Error executing bank account update: " . json_encode($error));
            throw new Exception("Error deactivating bank account: " . $error[2]);
        }

        if ($update_stmt->rowCount() === 0) {
            throw new Exception("Bank account not found or already deactivated");
        }

        error_log("Bank account status updated successfully");

        // Update chart of accounts to remove bank account flag
        $account_query = "
            UPDATE chart_of_accounts 
            SET 
                is_bank_account = 0,
                bank_details = NULL,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :account_id 
            AND status = 1
        ";

        $account_stmt = $conn->prepare($account_query);
        $account_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $account_stmt->bindParam(':account_id', $bank_account['account_id'], PDO::PARAM_INT);

        if (!$account_stmt->execute()) {
            $error = $account_stmt->errorInfo();
            error_log("Error executing account update: " . json_encode($error));
            throw new Exception("Error updating account bank details: " . $error[2]);
        }

        error_log("Chart of accounts updated successfully");

        // Commit transaction
        $conn->commit();
        error_log("Transaction committed successfully");
        
        echo json_encode([
            'success' => true,
            'message' => 'Bank account deactivated successfully',
            'data' => [
                'id' => $bank_account['id'],
                'bank_name' => $bank_account['bank_name'],
                'account_number' => $bank_account['account_number'],
                'account' => [
                    'id' => $bank_account['account_id'],
                    'code' => $bank_account['account_code'],
                    'name' => $bank_account['account_name']
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
