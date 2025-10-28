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
            throw new Exception("Reconciliation ID is required");
        }

        // Check if reconciliation exists
        $check_query = "
            SELECT r.*, 
                   ba.bank_name, ba.account_number,
                   coa.account_code, coa.account_name
            FROM bank_reconciliation r
            INNER JOIN bank_accounts ba ON ba.id = r.bank_account_id
            INNER JOIN chart_of_accounts coa ON coa.id = ba.account_id
            WHERE r.id = :id 
            AND r.status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $check_stmt->execute();
        
        $reconciliation = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reconciliation) {
            throw new Exception("Reconciliation not found");
        }

        error_log("Reconciliation found: " . json_encode($reconciliation));

        // Check if reconciliation is already completed
        if ($reconciliation['status'] === 'completed') {
            throw new Exception("Cannot delete a completed reconciliation");
        }

        error_log("Reconciliation is not completed");

        // Check for newer reconciliations
        $newer_query = "
            SELECT COUNT(*) as newer_count 
            FROM bank_reconciliation 
            WHERE bank_account_id = :bank_account_id 
            AND statement_date > :statement_date
            AND status = 1
        ";
        $newer_stmt = $conn->prepare($newer_query);
        $newer_stmt->bindParam(':bank_account_id', $reconciliation['bank_account_id'], PDO::PARAM_INT);
        $newer_stmt->bindParam(':statement_date', $reconciliation['statement_date']);
        $newer_stmt->execute();
        
        $result = $newer_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['newer_count'] > 0) {
            throw new Exception("Cannot delete reconciliation: There are {$result['newer_count']} newer reconciliations. Please delete them first.");
        }

        error_log("No newer reconciliations found");

        // Update reconciliation status
        $update_query = "
            UPDATE bank_reconciliation 
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
            throw new Exception("Error deactivating reconciliation: " . $error[2]);
        }

        if ($update_stmt->rowCount() === 0) {
            throw new Exception("Reconciliation not found or already deactivated");
        }

        error_log("Reconciliation status updated successfully");

        // Update bank account's last reconciliation date if this was the latest reconciliation
        $latest_query = "
            SELECT MAX(statement_date) as latest_date 
            FROM bank_reconciliation 
            WHERE bank_account_id = :bank_account_id 
            AND status = 1
        ";
        $latest_stmt = $conn->prepare($latest_query);
        $latest_stmt->bindParam(':bank_account_id', $reconciliation['bank_account_id'], PDO::PARAM_INT);
        $latest_stmt->execute();
        
        $latest = $latest_stmt->fetch(PDO::FETCH_ASSOC);
        
        $account_query = "
            UPDATE bank_accounts 
            SET 
                last_reconciliation_date = :last_date,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :bank_account_id 
            AND status = 1
        ";

        $account_stmt = $conn->prepare($account_query);
        $account_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $account_stmt->bindParam(':bank_account_id', $reconciliation['bank_account_id'], PDO::PARAM_INT);
        $account_stmt->bindParam(':last_date', $latest['latest_date']);

        if (!$account_stmt->execute()) {
            $error = $account_stmt->errorInfo();
            error_log("Error executing bank account update: " . json_encode($error));
            throw new Exception("Error updating bank account: " . $error[2]);
        }

        error_log("Bank account updated successfully");

        // Commit transaction
        $conn->commit();
        error_log("Transaction committed successfully");
        
        echo json_encode([
            'success' => true,
            'message' => 'Reconciliation deactivated successfully',
            'data' => [
                'id' => $reconciliation['id'],
                'statement_date' => $reconciliation['statement_date'],
                'bank_account' => [
                    'id' => $reconciliation['bank_account_id'],
                    'bank_name' => $reconciliation['bank_name'],
                    'account_number' => $reconciliation['account_number'],
                    'account_code' => $reconciliation['account_code'],
                    'account_name' => $reconciliation['account_name']
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
