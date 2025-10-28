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
        $required_fields = ['id', 'financial_year_id', 'entry_date', 'entry_type', 'description', 'accounts'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field {$field} is required");
            }
        }
        
        // Validate financial year
        $financial_year_id = $_POST['financial_year_id'];
        $entry_date = $_POST['entry_date'];
        
        // Check if financial year exists and is active
        $year_query = "SELECT id, year_code, start_date, end_date, is_closed FROM financial_years WHERE id = :id AND status = 1";
        $stmt = $conn->prepare($year_query);
        $stmt->bindParam(':id', $financial_year_id, PDO::PARAM_INT);
        $stmt->execute();
        $financial_year = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$financial_year) {
            throw new Exception("Invalid financial year selected");
        }
        
        if ($financial_year['is_closed'] == 1) {
            throw new Exception("Cannot update entries in a closed financial year");
        }
        
        // Check if entry date is within financial year
        if ($entry_date < $financial_year['start_date'] || $entry_date > $financial_year['end_date']) {
            throw new Exception("Entry date must be within the selected financial year ({$financial_year['start_date']} to {$financial_year['end_date']})");
        }

        // Check if journal entry exists and is in draft status
        $check_query = "
            SELECT id, status 
            FROM journal_entries 
            WHERE id = :id
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $check_stmt->execute();
        
        $journal_entry = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$journal_entry) {
            throw new Exception("Journal entry not found");
        }
        
        if ($journal_entry['status'] !== 'posted') {
            throw new Exception("Only posted journal entries that haven't been approved yet can be edited");
        }

        // Validate accounts, debits, and credits
        $accounts = $_POST['accounts'];
        $debits = $_POST['debits'];
        $credits = $_POST['credits'];
        $line_descriptions = $_POST['line_descriptions'];

        if (count($accounts) < 2) {
            throw new Exception("At least two accounts are required for a journal entry");
        }

        // Calculate totals
        $total_debit = 0;
        $total_credit = 0;
        foreach ($accounts as $index => $account_id) {
            $debit = floatval($debits[$index]);
            $credit = floatval($credits[$index]);
            $total_debit += $debit;
            $total_credit += $credit;
        }

        // Validate debit and credit balance
        if (abs($total_debit - $total_credit) > 0.01) {
            throw new Exception("Debits and credits must be equal");
        }

        // Update journal_entries
        $update_query = "
            UPDATE journal_entries 
            SET
                entry_date = :entry_date,
                reference_number = :reference_number,
                description = :description,
                entry_type = :entry_type,
                cost_center_id = :cost_center_id,
                financial_year_id = :financial_year_id,
                total_debit = :total_debit,
                total_credit = :total_credit,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :id
        ";

        $stmt = $conn->prepare($update_query);
        
        $reference_number = !empty($_POST['reference_number']) ? $_POST['reference_number'] : null;
        $cost_center_id = !empty($_POST['cost_center_id']) ? $_POST['cost_center_id'] : null;
        
        $stmt->bindParam(':entry_date', $_POST['entry_date'], PDO::PARAM_STR);
        $stmt->bindParam(':reference_number', $reference_number, PDO::PARAM_STR);
        $stmt->bindParam(':description', $_POST['description'], PDO::PARAM_STR);
        $stmt->bindParam(':entry_type', $_POST['entry_type'], PDO::PARAM_STR);
        $stmt->bindParam(':cost_center_id', $cost_center_id, $cost_center_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':financial_year_id', $financial_year_id, PDO::PARAM_INT);
        $stmt->bindParam(':total_debit', $total_debit, PDO::PARAM_STR);
        $stmt->bindParam(':total_credit', $total_credit, PDO::PARAM_STR);
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);

        if (!$stmt->execute()) {
            throw new Exception("Error updating journal entry");
        }

        // Delete existing journal entry details
        $delete_query = "DELETE FROM journal_entry_details WHERE journal_entry_id = :journal_entry_id";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bindParam(':journal_entry_id', $_POST['id'], PDO::PARAM_INT);
        
        if (!$delete_stmt->execute()) {
            throw new Exception("Error deleting existing journal entry details");
        }

        // Insert updated journal entry details
        foreach ($accounts as $index => $account_id) {
            $debit = floatval($debits[$index]);
            $credit = floatval($credits[$index]);
            $line_description = $line_descriptions[$index] ?? null;

            if ($debit == 0 && $credit == 0) {
                continue; // Skip empty lines
            }

            $detail_query = "
                INSERT INTO journal_entry_details (
                    journal_entry_id,
                    account_id,
                    description,
                    debit_amount,
                    credit_amount,
                    created_by,
                    updated_by
                ) VALUES (
                    :journal_entry_id,
                    :account_id,
                    :description,
                    :debit_amount,
                    :credit_amount,
                    :user_id,
                    :user_id2
                )
            ";

            $detail_stmt = $conn->prepare($detail_query);
            $detail_stmt->bindParam(':journal_entry_id', $_POST['id'], PDO::PARAM_INT);
            $detail_stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
            $detail_stmt->bindParam(':description', $line_description, PDO::PARAM_STR);
            $detail_stmt->bindParam(':debit_amount', $debit, PDO::PARAM_STR);
            $detail_stmt->bindParam(':credit_amount', $credit, PDO::PARAM_STR);
            $detail_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $detail_stmt->bindParam(':user_id2', $_SESSION['user_id'], PDO::PARAM_INT);

            if (!$detail_stmt->execute()) {
                throw new Exception("Error saving journal entry details");
            }
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Journal entry updated successfully'
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("PDOException in update_journal_entry.php: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.']);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
