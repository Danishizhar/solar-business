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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_approve'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id']) && isset($_POST['action'])) {
    try {
        // Start transaction
        $conn->beginTransaction();

        // Check if entry exists and is in posted status
        $stmt = $conn->prepare("SELECT status, entry_number FROM journal_entries WHERE id = :entry_id");
        $stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
        $stmt->execute();
        
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$entry) {
            throw new Exception("Journal entry not found");
        }

        if ($entry['status'] !== 'posted') {
            throw new Exception("Only posted entries can be approved or rejected");
        }

        $new_status = ($_POST['action'] === 'approve') ? 'approved' : 'rejected';

        // If rejecting, reverse the account balances
        if ($new_status === 'rejected') {
            // Get entry details
            $detail_stmt = $conn->prepare("
                SELECT account_id, debit_amount, credit_amount
                FROM journal_entry_details
                WHERE journal_entry_id = :entry_id
            ");
            $detail_stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
            $detail_stmt->execute();
            $details = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);

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

                // Calculate new balance based on account type and transaction (reverse the effect)
                $new_balance = $account['current_balance'];
                if ($account['balance_type'] === 'debit') {
                    $new_balance -= $detail['debit_amount'] - $detail['credit_amount'];
                } else {
                    $new_balance -= $detail['credit_amount'] - $detail['debit_amount'];
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
        }

        // Update entry status
        $stmt = $conn->prepare("
            UPDATE journal_entries 
            SET status = :new_status,
                approved_by = :approved_by,
                approved_at = NOW(),
                updated_by = :updated_by,
                updated_at = NOW(),
                notes = CONCAT(IFNULL(notes, ''), :notes)
            WHERE id = :entry_id
        ");

        $notes = "\n" . date('Y-m-d H:i:s') . " - " . 
                ($new_status === 'approved' ? 'Approved' : 'Rejected') . 
                " by " . $_SESSION['username'];
        if (!empty($_POST['notes'])) {
            $notes .= "\nNotes: " . $_POST['notes'];
        }

        $stmt->bindParam(':new_status', $new_status, PDO::PARAM_STR);
        $stmt->bindParam(':approved_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
        $stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error updating journal entry status");
        }

        // Log the activity
        $log_query = "INSERT INTO activity_log (module, action, record_id, description, created_by, created_at) 
                      VALUES ('journal_entries', :action, :record_id, :description, :user_id, NOW())";
        $log_stmt = $conn->prepare($log_query);
        $action = $new_status === 'approved' ? 'approve' : 'reject';
        $description = ucfirst($action) . "ed journal entry: {$entry['entry_number']}";
        $log_stmt->bindParam(':action', $action, PDO::PARAM_STR);
        $log_stmt->bindParam(':record_id', $_POST['id'], PDO::PARAM_INT);
        $log_stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $log_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $log_stmt->execute();

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Journal entry ' . ($new_status === 'approved' ? 'approved' : 'rejected') . ' successfully'
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in approve_entry.php: " . $e->getMessage());
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
