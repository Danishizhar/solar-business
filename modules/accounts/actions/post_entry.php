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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    try {
        // Start transaction
        $conn->beginTransaction();

        // First get the entry header to check status and get entry number
        $header_stmt = $conn->prepare("SELECT id, entry_number, status FROM journal_entries WHERE id = :entry_id");
        $header_stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
        $header_stmt->execute();
        $entry = $header_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry) {
            throw new Exception("Journal entry not found");
        }

        if ($entry['status'] !== 'draft') {
            throw new Exception("Only draft entries can be posted");
        }

        // Get all details for this entry
        $detail_stmt = $conn->prepare("
            SELECT account_id, debit_amount, credit_amount
            FROM journal_entry_details
            WHERE journal_entry_id = :entry_id
        ");
        $detail_stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
        $detail_stmt->execute();
        $details = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Update account balances for each detail line
        foreach ($details as $detail) {
            // Get current account balance
            $balance_stmt = $conn->prepare("
                SELECT current_balance, balance_type 
                FROM chart_of_accounts 
                WHERE id = :account_id
            ");
            $balance_stmt->bindParam(':account_id', $detail['account_id'], PDO::PARAM_INT);
            $balance_stmt->execute();
            $account = $balance_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                throw new Exception("Account not found: ID " . $detail['account_id']);
            }

            // Calculate new balance based on account type and transaction
            $new_balance = $account['current_balance'];
            if ($account['balance_type'] === 'debit') {
                $new_balance += floatval($detail['debit_amount']) - floatval($detail['credit_amount']);
            } else {
                $new_balance += floatval($detail['credit_amount']) - floatval($detail['debit_amount']);
            }

            // Update account balance
            $update_stmt = $conn->prepare("
                UPDATE chart_of_accounts 
                SET current_balance = :new_balance,
                    updated_by = :updated_by,
                    updated_at = NOW() 
                WHERE id = :account_id
            ");
            $update_stmt->bindParam(':new_balance', $new_balance, PDO::PARAM_STR);
            $update_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
            $update_stmt->bindParam(':account_id', $detail['account_id'], PDO::PARAM_INT);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Error updating account balance");
            }
        }

        // Update entry status to posted
        $stmt = $conn->prepare("
            UPDATE journal_entries 
            SET status = 'posted', 
                posting_date = NOW(),
                updated_by = :updated_by,
                updated_at = NOW() 
            WHERE id = :entry_id
        ");
        $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error posting journal entry");
        }

        // Log the activity
        $log_query = "INSERT INTO activity_log (module, action, record_id, description, created_by, created_at) 
                      VALUES ('journal_entries', 'post', :record_id, :description, :user_id, NOW())";
        $log_stmt = $conn->prepare($log_query);
        $description = "Posted journal entry: {$entry['entry_number']}";
        $log_stmt->bindParam(':record_id', $_POST['id'], PDO::PARAM_INT);
        $log_stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $log_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $log_stmt->execute();

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Journal entry posted successfully'
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in post_entry.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
