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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    try {
        // Start transaction
        $conn->beginTransaction();

        // Check if entry exists and is in draft status
        $stmt = $conn->prepare("SELECT id, entry_number, status FROM journal_entries WHERE id = :entry_id");
        $stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
        $stmt->execute();
        
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$entry) {
            throw new Exception("Journal entry not found");
        }

        if ($entry['status'] !== 'draft') {
            throw new Exception("Only draft entries can be deleted");
        }

        // Delete entry details first (due to foreign key constraint)
        $stmt = $conn->prepare("DELETE FROM journal_entry_details WHERE journal_entry_id = :entry_id");
        $stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error deleting entry details");
        }

        // Delete any attachments if they exist
        $stmt = $conn->prepare("DELETE FROM journal_entry_attachments WHERE journal_entry_id = :entry_id");
        $stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
        $stmt->execute();

        // Delete entry header
        $stmt = $conn->prepare("DELETE FROM journal_entries WHERE id = :entry_id");
        $stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error deleting journal entry");
        }

        // Log the activity
        $log_query = "INSERT INTO activity_log (module, action, record_id, description, created_by, created_at) 
                      VALUES ('journal_entries', 'delete', :record_id, :description, :user_id, NOW())";
        $log_stmt = $conn->prepare($log_query);
        $description = "Deleted journal entry: {$entry['entry_number']}";
        $log_stmt->bindParam(':record_id', $_POST['id'], PDO::PARAM_INT);
        $log_stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $log_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $log_stmt->execute();

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Journal entry deleted successfully'
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in delete_entry.php: " . $e->getMessage());
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
