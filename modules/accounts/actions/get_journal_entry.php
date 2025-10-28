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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Validate required fields
        if (empty($_GET['id'])) {
            throw new Exception("Journal entry ID is required");
        }

        $journal_entry_id = $_GET['id'];

        // Get journal entry without rejected_by column which might not exist
        $entry_query = "
            SELECT 
                je.*,
                c.username as created_by_name,
                u.username as updated_by_name,
                p.username as posted_by_name,
                a.username as approved_by_name,
                cc.name as cost_center_name,
                fy.year_code as financial_year_code,
                fy.start_date as financial_year_start,
                fy.end_date as financial_year_end,
                fy.is_closed as financial_year_closed
            FROM journal_entries je
            LEFT JOIN users c ON je.created_by = c.id
            LEFT JOIN users u ON je.updated_by = u.id
            LEFT JOIN users p ON je.posted_by = p.id
            LEFT JOIN users a ON je.approved_by = a.id
            LEFT JOIN cost_centers cc ON je.cost_center_id = cc.id
            LEFT JOIN financial_years fy ON je.financial_year_id = fy.id
            WHERE je.id = :id
        ";
        
        $entry_stmt = $conn->prepare($entry_query);
        $entry_stmt->bindParam(':id', $journal_entry_id, PDO::PARAM_INT);
        $entry_stmt->execute();
        
        $journal_entry = $entry_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$journal_entry) {
            throw new Exception("Journal entry not found");
        }
        
        // Get journal entry details
        $details_query = "
            SELECT 
                jed.*,
                coa.account_code,
                coa.account_name
            FROM journal_entry_details jed
            LEFT JOIN chart_of_accounts coa ON jed.account_id = coa.id AND coa.status = 1
            WHERE jed.journal_entry_id = :journal_entry_id
            ORDER BY jed.id
        ";
        
        $details_stmt = $conn->prepare($details_query);
        $details_stmt->bindParam(':journal_entry_id', $journal_entry_id, PDO::PARAM_INT);
        $details_stmt->execute();
        
        $journal_entry_details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'entry' => $journal_entry,
                'details' => $journal_entry_details
            ]
        ]);

    } catch (PDOException $e) {
        error_log("PDOException in get_journal_entry.php: " . $e->getMessage());
        error_log("PDOException Code: " . $e->getCode());
        error_log("PDOException Trace: " . $e->getTraceAsString());
        
        // Following PDO standards from memory - log errors but return user-friendly messages
        echo json_encode([
            'success' => false, 
            'message' => 'Database error occurred. Please try again later.',
            'debug' => [
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage()
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
