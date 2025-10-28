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
            throw new Exception("Financial year ID is required");
        }

        if (empty($_POST['action']) || $_POST['action'] !== 'delete') {
            throw new Exception("Invalid action");
        }

        $id = $_POST['id'];

        // Check if financial year exists and is not closed
        $check_query = "SELECT * FROM financial_years WHERE id = :id";
        $stmt = $conn->prepare($check_query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Financial year not found");
        }

        $financial_year = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($financial_year['is_closed']) {
            throw new Exception("Cannot delete a closed financial year");
        }

        // Check if there are any journal entries associated with this financial year
        $check_journal_query = "
            SELECT COUNT(*) as entry_count 
            FROM journal_entries 
            WHERE financial_year_id = :financial_year_id
        ";
        $stmt = $conn->prepare($check_journal_query);
        $stmt->bindParam(':financial_year_id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['entry_count'] > 0) {
            throw new Exception("Cannot delete financial year because it has associated transactions. Please close it instead.");
        }

        // Perform hard delete since there are no associated transactions
        $delete_query = "DELETE FROM financial_years WHERE id = :id";
        $stmt = $conn->prepare($delete_query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error deleting financial year");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Financial year deleted successfully'
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("Database error in update_financial_year_status.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
