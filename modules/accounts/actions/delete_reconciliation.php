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

        $reconciliation_id = $_POST['id'];

        // Check if reconciliation exists and is in draft status
        $check_query = "
            SELECT br.*, ba.account_name 
            FROM bank_reconciliation br
            JOIN bank_accounts ba ON br.bank_account_id = ba.id
            WHERE br.id = :reconciliation_id 
            AND br.status = 1";
        $stmt = $conn->prepare($check_query);
        $stmt->bindParam(':reconciliation_id', $reconciliation_id, PDO::PARAM_INT);
        $stmt->execute();
        $reconciliation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reconciliation) {
            throw new Exception("Reconciliation not found");
        }

        if ($reconciliation['status'] != 'draft') {
            throw new Exception("Only draft reconciliations can be deleted");
        }

        // Delete reconciliation items first
        $delete_items_query = "DELETE FROM bank_reconciliation_items WHERE reconciliation_id = :reconciliation_id";
        $stmt = $conn->prepare($delete_items_query);
        $stmt->bindParam(':reconciliation_id', $reconciliation_id, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error deleting reconciliation items");
        }

        // Delete reconciliation record
        $delete_query = "DELETE FROM bank_reconciliation WHERE id = :reconciliation_id";
        $stmt = $conn->prepare($delete_query);
        $stmt->bindParam(':reconciliation_id', $reconciliation_id, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error deleting reconciliation");
        }

        // Log the deletion
        $log_query = "
            INSERT INTO activity_log (
                module, action, record_id, description, 
                created_by, created_at
            ) VALUES (
                'bank_reconciliation', 'delete', :record_id, 
                :description, :created_by, NOW()
            )";
        $stmt = $conn->prepare($log_query);
        $description = "Deleted bank reconciliation for " . $reconciliation['account_name'];
        $stmt->bindParam(':record_id', $reconciliation_id, PDO::PARAM_INT);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Bank reconciliation deleted successfully'
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in delete_reconciliation.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']);
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
