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

        // Validate required fields
        if (empty($_POST['id'])) {
            throw new Exception("Financial year ID is required");
        }

        $year_id = $_POST['id'];

        // Check if financial year exists and is not closed
        $check_query = "SELECT * FROM financial_years WHERE id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param('i', $year_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            throw new Exception("Financial year not found");
        }

        $financial_year = $result->fetch_assoc();

        if ($financial_year['is_closed']) {
            throw new Exception("Cannot delete a closed financial year");
        }

        // Check if there are any journal entries in this financial year
        $journal_check = "
            SELECT COUNT(*) as count FROM journal_entries 
            WHERE entry_date BETWEEN ? AND ?
        ";
        $stmt = $conn->prepare($journal_check);
        $stmt->bind_param('ss', $financial_year['start_date'], $financial_year['end_date']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row['count'] > 0) {
            throw new Exception("Cannot delete financial year because it has journal entries associated with it");
        }

        // Delete financial year
        $delete_query = "DELETE FROM financial_years WHERE id = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param('i', $year_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Error deleting financial year: " . $stmt->error);
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => "Financial year '{$financial_year['year_code']}' deleted successfully"
        ]);

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
