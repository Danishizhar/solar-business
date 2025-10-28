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
            throw new Exception("Category ID is required");
        }

        // Check if category exists
        $check_query = "
            SELECT c.* 
            FROM account_categories c 
            WHERE c.id = :id 
            AND c.status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $check_stmt->execute();
        
        $category = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$category) {
            throw new Exception("Category not found");
        }

        error_log("Category found: " . json_encode($category));

        // Check for child categories
        $child_query = "
            SELECT COUNT(*) as child_count 
            FROM account_categories 
            WHERE parent_id = :parent_id 
            AND status = 1
        ";
        $child_stmt = $conn->prepare($child_query);
        $child_stmt->bindParam(':parent_id', $_POST['id'], PDO::PARAM_INT);
        $child_stmt->execute();
        
        $result = $child_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['child_count'] > 0) {
            throw new Exception("Cannot delete category: It has {$result['child_count']} child categories. Please delete the child categories first.");
        }

        error_log("No child categories found");

        // Check for accounts using this category
        $account_query = "
            SELECT COUNT(*) as account_count 
            FROM chart_of_accounts 
            WHERE category_id = :category_id 
            AND status = 1
        ";
        $account_stmt = $conn->prepare($account_query);
        $account_stmt->bindParam(':category_id', $_POST['id'], PDO::PARAM_INT);
        $account_stmt->execute();
        
        $result = $account_stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['account_count'] > 0) {
            throw new Exception("Cannot delete category: It has {$result['account_count']} associated accounts. Please delete or reassign the accounts first.");
        }

        error_log("No associated accounts found");

        // Hard delete the category
        $delete_query = "DELETE FROM account_categories WHERE id = :id";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);

        if (!$delete_stmt->execute()) {
            $error = $delete_stmt->errorInfo();
            error_log("Error executing delete: " . json_encode($error));
            throw new Exception("Error deleting category: " . $error[2]);
        }

        if ($delete_stmt->rowCount() === 0) {
            throw new Exception("Category not found");
        }

        error_log("Category deleted successfully");

        // Commit transaction
        $conn->commit();
        error_log("Transaction committed successfully");
        
        echo json_encode([
            'success' => true,
            'message' => 'Category deleted successfully',
            'data' => [
                'id' => $category['id'],
                'code' => $category['code'],
                'name' => $category['name']
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
