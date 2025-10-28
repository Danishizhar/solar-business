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

        // Debug logging
        error_log("Starting account deletion for ID: " . $_POST['id']);

        // Validate required fields
        if (empty($_POST['id'])) {
            throw new Exception("Account ID is required");
        }

        // Check if account exists
        $check_query = "
            SELECT a.id, a.account_code, a.account_name, a.is_bank_account 
            FROM chart_of_accounts a 
            WHERE a.id = :id 
            AND a.status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $check_stmt->execute();
        
        $account = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            throw new Exception("Account not found");
        }

        error_log("Account found: " . json_encode($account));

        // Check for child accounts
        $child_query = "
            SELECT COUNT(*) as child_count 
            FROM chart_of_accounts 
            WHERE parent_id = :parent_id 
            AND status = 1 
        ";
        $child_stmt = $conn->prepare($child_query);
        $child_stmt->bindParam(':parent_id', $_POST['id'], PDO::PARAM_INT);
        $child_stmt->execute();
        
        $result = $child_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['child_count'] > 0) {
            throw new Exception("Cannot delete account: It has {$result['child_count']} child accounts. Please delete the child accounts first.");
        }
        error_log("No child accounts found");

        // Check for journal entries
        $entry_query = "
            SELECT COUNT(*) as entry_count 
            FROM journal_entry_details 
            WHERE account_id = :account_id
        ";
        $entry_stmt = $conn->prepare($entry_query);
        $entry_stmt->bindParam(':account_id', $_POST['id'], PDO::PARAM_INT);
        $entry_stmt->execute();
        
        $result = $entry_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['entry_count'] > 0) {
            throw new Exception("Cannot delete account: It has {$result['entry_count']} associated journal entries. Please delete the journal entries first.");
        }
        error_log("No journal entries found");

        // Check for budgets
        $budget_query = "
            SELECT COUNT(*) as budget_count 
            FROM budgets 
            WHERE account_id = :account_id 
            AND status = 1
        ";
        $budget_stmt = $conn->prepare($budget_query);
        $budget_stmt->bindParam(':account_id', $_POST['id'], PDO::PARAM_INT);
        $budget_stmt->execute();
        
        $result = $budget_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['budget_count'] > 0) {
            throw new Exception("Cannot delete account: It has {$result['budget_count']} associated budgets. Please delete the budgets first.");
        }
        error_log("No budgets found");

        // Update account status
        $update_query = "
            UPDATE chart_of_accounts 
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
            error_log("Error executing update: " . json_encode($error));
            throw new Exception("Error deactivating account: " . $error[2]);
        }

        if ($update_stmt->rowCount() === 0) {
            throw new Exception("Account not found or already deactivated");
        }

        error_log("Account status updated successfully");

        // If it's a bank account, also deactivate the bank_accounts entry
        if ($account['is_bank_account']) {
            error_log("Account is a bank account, updating...");
            
            $bank_query = "
                UPDATE bank_accounts 
                SET 
                    status = 0,
                    updated_by = :user_id,
                    updated_at = NOW()
                WHERE account_id = :account_id 
                AND status = 1
            ";

            $bank_stmt = $conn->prepare($bank_query);
            $bank_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $bank_stmt->bindParam(':account_id', $_POST['id'], PDO::PARAM_INT);
            
            if (!$bank_stmt->execute()) {
                $error = $bank_stmt->errorInfo();
                error_log("Error executing bank update: " . json_encode($error));
                throw new Exception("Error deactivating bank account details: " . $error[2]);
            }
            error_log("Bank account status updated successfully");
        }

        // Commit transaction
        $conn->commit();
        error_log("Transaction committed successfully");
        
        echo json_encode([
            'success' => true,
            'message' => 'Account deactivated successfully',
            'data' => [
                'id' => $account['id'],
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'is_bank_account' => $account['is_bank_account']
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
