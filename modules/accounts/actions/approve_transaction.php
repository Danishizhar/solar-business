<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

function log_debug($message, $data = null) {
    $log = date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $log .= "\n" . print_r($data, true);
    }
    $log .= "\n";
    error_log($log, 3, __DIR__ . '/../../../logs/reconciliation.log');
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_approve'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();

    // Start transaction
    $conn->beginTransaction();

    if (!isset($_POST['id'])) {
        throw new Exception('Transaction ID is required');
    }

    // Get transaction details first
    $check_query = "
        SELECT je.id, je.status
        FROM journal_entries je
        WHERE je.id = :id 
        AND je.status = 'posted'";
    
    $stmt = $conn->prepare($check_query);
    $stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
    $stmt->execute();
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        throw new Exception('Transaction not found or already approved');
    }

    // Update transaction status
    $update_query = "
        UPDATE journal_entries 
        SET status = 'approved',
            updated_by = :user_id,
            updated_at = NOW()
        WHERE id = :id";
    
    $stmt = $conn->prepare($update_query);
    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
    $stmt->execute();

    // Get affected account IDs
    $account_query = "
        SELECT DISTINCT jed.account_id, coa.balance_type 
        FROM journal_entry_details jed
        JOIN chart_of_accounts coa ON jed.account_id = coa.id
        WHERE jed.journal_entry_id = :id";
    
    $stmt = $conn->prepare($account_query);
    $stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
    $stmt->execute();
    $affected_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    log_debug("Affected accounts", $affected_accounts);

    // Update each account's balance
    foreach ($affected_accounts as $account) {
        $account_id = $account['account_id'];
        $balance_type = $account['balance_type'];

        log_debug("Processing account $account_id with balance type: $balance_type");

        // First get the sum of all approved transactions for this account
        $sum_query = "
            SELECT 
                SUM(CASE 
                    WHEN :balance_type = 'debit' THEN 
                        COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0)
                    ELSE 
                        COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0)
                    END
                ) as total_balance
            FROM journal_entry_details jed
            JOIN journal_entries je ON jed.journal_entry_id = je.id
            WHERE jed.account_id = :account_id
            AND je.status = 'approved'";

        $stmt = $conn->prepare($sum_query);
        $stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
        $stmt->bindParam(':balance_type', $balance_type, PDO::PARAM_STR);
        $stmt->execute();
        $balance = $stmt->fetchColumn() ?: 0;

        // Now update the account with the calculated balance
        $update_balance_query = "
            UPDATE chart_of_accounts 
            SET current_balance = :balance,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :account_id";

        $stmt = $conn->prepare($update_balance_query);
        $stmt->bindParam(':balance', $balance, PDO::PARAM_STR);
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error updating balance for account $account_id");
        }

        log_debug("Updated balance for account $account_id to $balance");
    }

    // Commit transaction
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Transaction approved successfully']);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    log_debug("Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error approving transaction: ' . $e->getMessage()]);
}
?>
