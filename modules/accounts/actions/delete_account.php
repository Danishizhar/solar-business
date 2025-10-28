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

        if (empty($_POST['id'])) {
            throw new Exception("Account ID is required");
        }

        // Check if account has any transactions
        $trans_query = "
            SELECT COUNT(*) as count 
            FROM journal_entry_details 
            WHERE account_id = :account_id
            AND status = 1
        ";
        $trans_stmt = $conn->prepare($trans_query);
        $trans_stmt->bindParam(':account_id', $_POST['id'], PDO::PARAM_INT);
        $trans_stmt->execute();
        $result = $trans_stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            throw new Exception("Cannot delete account: It has associated transactions");
        }

        // Check if account has child accounts
        $children_query = "
            SELECT COUNT(*) as count 
            FROM chart_of_accounts 
            WHERE parent_id = :parent_id 
            AND status = 1
        ";
        $children_stmt = $conn->prepare($children_query);
        $children_stmt->bindParam(':parent_id', $_POST['id'], PDO::PARAM_INT);
        $children_stmt->execute();
        $result = $children_stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            throw new Exception("Cannot delete account: It has child accounts");
        }

        // Get account details to check if it's a bank account
        $account_query = "
            SELECT is_bank_account 
            FROM chart_of_accounts 
            WHERE id = :account_id 
            AND status = 1
        ";
        $account_stmt = $conn->prepare($account_query);
        $account_stmt->bindParam(':account_id', $_POST['id'], PDO::PARAM_INT);
        $account_stmt->execute();
        $account = $account_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new Exception("Account not found");
        }

        // If it's a bank account, delete from bank_accounts first
        if ($account['is_bank_account']) {
            $bank_query = "
                UPDATE bank_accounts 
                SET status = 0,
                    updated_by = :user_id,
                    updated_at = NOW()
                WHERE account_id = :account_id
                AND status = 1
            ";
            $bank_stmt = $conn->prepare($bank_query);
            $bank_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $bank_stmt->bindParam(':account_id', $_POST['id'], PDO::PARAM_INT);
            
            if (!$bank_stmt->execute()) {
                throw new Exception("Error deleting bank account details");
            }
        }

        // Soft delete the account
        $delete_query = "
            UPDATE chart_of_accounts 
            SET status = 0,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :account_id
            AND status = 1
        ";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $delete_stmt->bindParam(':account_id', $_POST['id'], PDO::PARAM_INT);

        if (!$delete_stmt->execute()) {
            throw new Exception("Error deleting account");
        }

        if ($delete_stmt->rowCount() === 0) {
            throw new Exception("Account not found or already deleted");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Account deleted successfully']);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
