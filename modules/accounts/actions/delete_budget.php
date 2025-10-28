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
            throw new Exception("Budget ID is required");
        }

        $budget_id = $_POST['id'];

        // Check if budget exists and get its details
        $check_query = "
            SELECT 
                b.id,
                b.fiscal_year,
                b.period,
                fy.year_code,
                coa.account_code,
                coa.account_name,
                cc.code as cost_center_code
            FROM budgets b 
            JOIN chart_of_accounts coa ON b.account_id = coa.id AND coa.status = 1
            JOIN financial_years fy ON b.fiscal_year = fy.id AND fy.status = 1
            LEFT JOIN cost_centers cc ON b.cost_center_id = cc.id AND cc.status = 1
            WHERE b.id = :budget_id 
            AND b.status = 1
            FOR UPDATE";
        
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);
        $check_stmt->execute();

        $budget = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$budget) {
            throw new Exception("Budget not found or already deleted");
        }

        // Delete budget details first (if any)
        if ($budget['period'] !== 'yearly') {
            $delete_details = "DELETE FROM budget_details WHERE budget_id = :budget_id";
            $detail_stmt = $conn->prepare($delete_details);
            $detail_stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);
            
            if (!$detail_stmt->execute()) {
                throw new Exception("Error deleting budget details");
            }
        }

        // Soft delete the budget by updating status
        $delete_query = "
            UPDATE budgets 
            SET 
                status = 0, 
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :budget_id 
            AND status = 1";
            
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $delete_stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);
        
        if (!$delete_stmt->execute()) {
            throw new Exception("Error deleting budget");
        }

        if ($delete_stmt->rowCount() === 0) {
            throw new Exception("Budget not found or already deleted");
        }

        // Log the deletion
        $log_query = "
            INSERT INTO activity_logs (
                user_id, 
                action, 
                module,
                record_id, 
                description
            ) VALUES (
                :user_id,
                'delete',
                'budgets',
                :budget_id,
                :description
            )";
            
        $log_stmt = $conn->prepare($log_query);
        $description = sprintf(
            "Deleted budget for %s (%s) - FY%s%s",
            $budget['account_name'],
            $budget['account_code'],
            $budget['year_code'],
            $budget['cost_center_code'] ? " - CC: " . $budget['cost_center_code'] : ""
        );
        
        $log_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $log_stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':description', $description, PDO::PARAM_STR);
        
        if (!$log_stmt->execute()) {
            throw new Exception("Error logging budget deletion");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Budget deleted successfully'
        ]);

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in delete_budget.php: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Database error occurred. Please check the error logs.'
        ]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request method'
    ]);
}
