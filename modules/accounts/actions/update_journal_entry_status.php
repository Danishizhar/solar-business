<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['id']) || empty($_POST['action'])) {
            throw new Exception("ID and action are required");
        }

        $id = $_POST['id'];
        $action = $_POST['action'];
        $allowed_actions = ['delete', 'post', 'approve', 'reject'];

        if (!in_array($action, $allowed_actions)) {
            throw new Exception("Invalid action");
        }

        // Check permissions based on action
        if ($action === 'delete' && $_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_delete'])) {
            throw new Exception("Permission denied");
        } else if (($action === 'approve' || $action === 'reject') && $_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_approve'])) {
            throw new Exception("Permission denied");
        } else if ($action === 'post' && $_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_edit'])) {
            throw new Exception("Permission denied");
        }

        // Check if journal entry exists
        $check_query = "SELECT id, status FROM journal_entries WHERE id = :id";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $check_stmt->execute();
        
        $journal_entry = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$journal_entry) {
            throw new Exception("Journal entry not found");
        }

        // Validate status transitions
        if ($action === 'delete' && ($journal_entry['status'] !== 'draft' && $journal_entry['status'] !== 'posted')) {
            throw new Exception("Only draft or posted journal entries can be deleted");
        } else if ($action === 'delete' && $journal_entry['status'] === 'approved') {
            throw new Exception("Approved journal entries cannot be deleted");
        } else if ($action === 'post' && $journal_entry['status'] !== 'draft') {
            throw new Exception("Only draft journal entries can be posted");
        } else if (($action === 'approve' || $action === 'reject') && $journal_entry['status'] !== 'posted') {
            throw new Exception("Only posted journal entries can be approved or rejected");
        }

        // Start transaction
        $conn->beginTransaction();

        if ($action === 'delete') {
            // Check if it's a posted entry (hard delete) or draft entry (soft delete)
            $check_status_query = "SELECT status FROM journal_entries WHERE id = :id";
            $check_status_stmt = $conn->prepare($check_status_query);
            $check_status_stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $check_status_stmt->execute();
            $entry_status = $check_status_stmt->fetchColumn();
            
            if ($entry_status === 'posted') {
                // Hard delete for posted entries
                // First delete the journal entry details
                $delete_details_query = "DELETE FROM journal_entry_details WHERE journal_entry_id = :id";
                $delete_details_stmt = $conn->prepare($delete_details_query);
                $delete_details_stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $delete_details_stmt->execute();
                
                // Then delete the journal entry
                $update_query = "DELETE FROM journal_entries WHERE id = :id";
            } else {
                // Soft delete for draft entries
                $update_query = "
                    UPDATE journal_entries 
                    SET status = 'deleted', updated_by = :user_id, updated_at = NOW()
                    WHERE id = :id
                ";
            }
        } else if ($action === 'post') {
            // Post the journal entry
            $update_query = "
                UPDATE journal_entries 
                SET status = 'posted', updated_by = :user_id, updated_at = NOW()
                WHERE id = :id
            ";
        } else if ($action === 'approve') {
            // Approve the journal entry
            $update_query = "
                UPDATE journal_entries 
                SET status = 'approved', approved_by = :user_id, approved_at = NOW(), 
                    updated_by = :user_id, updated_at = NOW()
                WHERE id = :id
            ";
        } else if ($action === 'reject') {
            // Reject the journal entry
            $update_query = "
                UPDATE journal_entries 
                SET status = 'rejected', approved_by = :user_id, approved_at = NOW(), 
                    updated_by = :user_id, updated_at = NOW()
                WHERE id = :id
            ";
        }

        $stmt = $conn->prepare($update_query);
        
        // Only bind user_id for soft deletes and other actions, not for hard deletes
        if ($action === 'delete' && $entry_status === 'posted') {
            // For hard delete, only bind the ID
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        } else {
            // For soft delete and other actions, bind both user_id and id
            $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        }

        if (!$stmt->execute()) {
            throw new Exception("Error updating journal entry status");
        }

        // Commit transaction
        $conn->commit();
        
        $action_messages = [
            'delete' => 'deleted',
            'post' => 'posted',
            'approve' => 'approved',
            'reject' => 'rejected'
        ];
        
        echo json_encode([
            'success' => true, 
            'message' => 'Journal entry ' . $action_messages[$action] . ' successfully'
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("PDOException in update_journal_entry_status.php: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.']);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
