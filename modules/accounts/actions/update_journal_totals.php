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

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Get all journal entries
    $entries_query = "SELECT id FROM journal_entries";
    $entries_stmt = $conn->prepare($entries_query);
    $entries_stmt->execute();
    $entries = $entries_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updated_count = 0;
    
    foreach ($entries as $entry) {
        // Calculate totals from details
        $totals_query = "
            SELECT 
                SUM(debit_amount) as total_debit,
                SUM(credit_amount) as total_credit
            FROM journal_entry_details
            WHERE journal_entry_id = :entry_id
        ";
        
        $totals_stmt = $conn->prepare($totals_query);
        $totals_stmt->bindParam(':entry_id', $entry['id'], PDO::PARAM_INT);
        $totals_stmt->execute();
        $totals = $totals_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Update journal entry with correct totals
        $update_query = "
            UPDATE journal_entries
            SET 
                total_debit = :total_debit,
                total_credit = :total_credit,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :entry_id
        ";
        
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':total_debit', $totals['total_debit'], PDO::PARAM_STR);
        $update_stmt->bindParam(':total_credit', $totals['total_credit'], PDO::PARAM_STR);
        $update_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_stmt->bindParam(':entry_id', $entry['id'], PDO::PARAM_INT);
        
        if ($update_stmt->execute()) {
            $updated_count++;
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => "Successfully updated totals for {$updated_count} journal entries"
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $conn->rollBack();
    error_log("PDOException in update_journal_totals.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    error_log("Exception in update_journal_totals.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
