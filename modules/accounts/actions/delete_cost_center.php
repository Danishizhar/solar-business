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
            throw new Exception("Cost center ID is required");
        }

        $cost_center_id = $_POST['id'];

        // Check if cost center exists
        $check_query = "SELECT * FROM cost_centers WHERE id = :id";
        $stmt = $conn->prepare($check_query);
        $stmt->bindParam(':id', $cost_center_id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Cost center not found");
        }

        $cost_center = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check if cost center is used as a parent
        $check_children = "SELECT COUNT(*) as count FROM cost_centers WHERE parent_id = :parent_id";
        $stmt = $conn->prepare($check_children);
        $stmt->bindParam(':parent_id', $cost_center_id, PDO::PARAM_INT);
        $stmt->execute();
        $children_result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($children_result['count'] > 0) {
            throw new Exception("Cannot delete cost center that has child cost centers");
        }

        // Check if cost center is used in journal entries
        $check_entries = "SELECT COUNT(*) as count FROM journal_entries WHERE cost_center_id = :cost_center_id";
        $stmt = $conn->prepare($check_entries);
        $stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
        $stmt->execute();
        $entries_result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($entries_result['count'] > 0) {
            throw new Exception("Cannot delete cost center that is used in journal entries");
        }

        // Check if cost center is used in journal entry details
        $check_journal_details = "SELECT COUNT(*) as count FROM journal_entry_details WHERE cost_center_id = :cost_center_id";
        $stmt = $conn->prepare($check_journal_details);
        $stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
        $stmt->execute();
        $journal_details_result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($journal_details_result['count'] > 0) {
            throw new Exception("Cannot delete cost center that is used in journal entry details");
        }

        // Check if cost center is used in budgets
        $check_budgets = "SELECT COUNT(*) as count FROM budgets WHERE cost_center_id = :cost_center_id";
        $stmt = $conn->prepare($check_budgets);
        $stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
        $stmt->execute();
        $budgets_result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($budgets_result['count'] > 0) {
            throw new Exception("Cannot delete cost center that is used in budgets");
        }

        // Delete cost center
        $delete_query = "DELETE FROM cost_centers WHERE id = :id";
        $stmt = $conn->prepare($delete_query);
        $stmt->bindParam(':id', $cost_center_id, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error deleting cost center");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Cost center deleted successfully']);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in delete_cost_center.php: " . $e->getMessage());
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
