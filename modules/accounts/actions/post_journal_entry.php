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
            throw new Exception("Journal entry ID is required");
        }

        $journal_entry_id = $_POST['id'];

        // Check if journal entry exists and is in draft status
        $check_query = "
            SELECT id, entry_number, status, total_debit, total_credit 
            FROM journal_entries 
            WHERE id = :id
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $journal_entry_id, PDO::PARAM_INT);
        $check_stmt->execute();
        
        $journal_entry = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$journal_entry) {
            throw new Exception("Journal entry not found");
        }
        
        if ($journal_entry['status'] !== 'draft') {
            throw new Exception("Only draft journal entries can be posted");
        }

        // Validate that debits and credits are equal
        if (abs(floatval($journal_entry['total_debit']) - floatval($journal_entry['total_credit'])) > 0.01) {
            throw new Exception("Cannot post journal entry: Debits and credits must be equal");
        }

        // Update journal entry status to posted
        $update_query = "
            UPDATE journal_entries 
            SET
                status = 'posted',
                posted_by = :user_id,
                posted_at = NOW(),
                updated_by = :user_id2,
                updated_at = NOW()
            WHERE id = :id AND status = 'draft'
        ";

        $stmt = $conn->prepare($update_query);
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':user_id2', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':id', $journal_entry_id, PDO::PARAM_INT);

        if (!$stmt->execute()) {
            throw new Exception("Error posting journal entry");
        }

        // Check if any rows were affected
        if ($stmt->rowCount() === 0) {
            throw new Exception("Journal entry could not be posted. It may have been modified by another user.");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Journal entry #' . $journal_entry['entry_number'] . ' has been posted successfully'
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("PDOException in post_journal_entry.php: " . $e->getMessage());
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
