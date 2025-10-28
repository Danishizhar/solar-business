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
        $conn->begin_transaction();

        if (empty($_POST['id'])) {
            throw new Exception("Category ID is required");
        }

        // Check if category has any child categories
        $check_children = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM account_categories 
            WHERE parent_id = ? AND status = 1
        ");
        $check_children->bind_param('i', $_POST['id']);
        $check_children->execute();
        $result = $check_children->get_result()->fetch_assoc();

        if ($result['count'] > 0) {
            throw new Exception("Cannot delete category: It has child categories");
        }

        // Check if category has any accounts
        $check_accounts = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM chart_of_accounts 
            WHERE category_id = ? AND status = 1
        ");
        $check_accounts->bind_param('i', $_POST['id']);
        $check_accounts->execute();
        $result = $check_accounts->get_result()->fetch_assoc();

        if ($result['count'] > 0) {
            throw new Exception("Cannot delete category: It has associated accounts");
        }

        // Soft delete the category
        $stmt = $conn->prepare("
            UPDATE account_categories 
            SET status = 0,
                updated_by = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmt->bind_param('ii', $_SESSION['user_id'], $_POST['id']);

        if (!$stmt->execute()) {
            throw new Exception("Error deleting category");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Category deleted successfully']);

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
