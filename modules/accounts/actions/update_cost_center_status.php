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
            throw new Exception("Cost center ID is required");
        }

        // Check if cost center exists
        $check_query = "
            SELECT id, name 
            FROM cost_centers 
            WHERE id = :id 
            AND status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $check_stmt->execute();
        
        $cost_center = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cost_center) {
            throw new Exception("Cost center not found");
        }

        // Check for dependent records
        $dependent_query = "
            SELECT id 
            FROM chart_of_accounts 
            WHERE cost_center_id = :cost_center_id 
            AND status = 1 
            LIMIT 1
        ";
        $dependent_stmt = $conn->prepare($dependent_query);
        $dependent_stmt->bindParam(':cost_center_id', $_POST['id'], PDO::PARAM_INT);
        $dependent_stmt->execute();
        
        if ($dependent_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Cannot delete cost center: It is being used in chart of accounts");
        }

        // Check for dependent budgets
        $budget_query = "
            SELECT id 
            FROM budgets 
            WHERE cost_center_id = :cost_center_id 
            AND status = 1 
            LIMIT 1
        ";
        $budget_stmt = $conn->prepare($budget_query);
        $budget_stmt->bindParam(':cost_center_id', $_POST['id'], PDO::PARAM_INT);
        $budget_stmt->execute();
        
        if ($budget_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Cannot delete cost center: It has associated budgets");
        }

        // Check for dependent journal entries
        $entry_query = "
            SELECT id 
            FROM journal_entry_details 
            WHERE cost_center_id = :cost_center_id 
            AND status = 1 
            LIMIT 1
        ";
        $entry_stmt = $conn->prepare($entry_query);
        $entry_stmt->bindParam(':cost_center_id', $_POST['id'], PDO::PARAM_INT);
        $entry_stmt->execute();
        
        if ($entry_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Cannot delete cost center: It has associated journal entries");
        }

        // Update cost center status
        $update_query = "
            UPDATE cost_centers 
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
            throw new Exception("Error deactivating cost center");
        }

        if ($update_stmt->rowCount() === 0) {
            throw new Exception("Cost center not found or already deactivated");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Cost center deactivated successfully',
            'data' => [
                'id' => $_POST['id'],
                'name' => $cost_center['name']
            ]
        ]);

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
