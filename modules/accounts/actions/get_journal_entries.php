<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    echo json_encode([]);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

try {
    // Check if journal_entries table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'journal_entries'");
    if ($table_check->rowCount() == 0) {
        // Return empty array if table doesn't exist
        echo json_encode([]);
        exit();
    }
    
    // First check if financial_year_id column exists in journal_entries table
    $column_check = $conn->query("SHOW COLUMNS FROM journal_entries LIKE 'financial_year_id'");
    $has_financial_year = ($column_check->rowCount() > 0);
    
    // Get all journal entries with their details
    $entries_query = "
        SELECT 
            je.*,
            c.username as created_by_name,
            u.username as updated_by_name,
            a.username as approved_by_name,
            cc.name as cost_center_name";
    
    // Only include financial year fields if the column exists
    if ($has_financial_year) {
        $entries_query .= ",
            COALESCE(fy.year_code, 'N/A') as financial_year_code,
            fy.start_date as financial_year_start,
            fy.end_date as financial_year_end,
            COALESCE(fy.is_closed, 0) as financial_year_closed";
    } else {
        $entries_query .= ",
            'N/A' as financial_year_code,
            NULL as financial_year_start,
            NULL as financial_year_end,
            0 as financial_year_closed";
    }
    
    $entries_query .= "
        FROM journal_entries je
        LEFT JOIN users c ON je.created_by = c.id
        LEFT JOIN users u ON je.updated_by = u.id
        LEFT JOIN users a ON je.approved_by = a.id
        LEFT JOIN cost_centers cc ON je.cost_center_id = cc.id";
    
    // Only join financial_years if the column exists
    if ($has_financial_year) {
        $entries_query .= "
        LEFT JOIN financial_years fy ON je.financial_year_id = fy.id";
    }
    
    $entries_query .= "
        ORDER BY je.id DESC
    ";
    $stmt = $conn->prepare($entries_query);
    $stmt->execute();
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add permission flags to each entry
    foreach ($entries as &$entry) {
        // Add permission flags
        $entry['can_view'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_view']);
        $entry['can_edit'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_edit']);
        $entry['can_delete'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_delete']);
        $entry['can_approve'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_approve']);
        
        // Add is_approved flag for UI logic
        $entry['is_approved'] = ($entry['status'] === 'approved');
    }
    
    // Return empty array if no entries found
    // Sample entry code removed as requested

    // Return entries for DataTables
    echo json_encode($entries);

} catch (PDOException $e) {
    error_log("Error in get_journal_entries.php: " . $e->getMessage());
    // Return error information for debugging
    echo json_encode([
        [
            'id' => 999,
            'entry_number' => 'ERROR',
            'entry_date' => date('Y-m-d'),
            'entry_type' => 'error',
            'description' => 'Error: ' . $e->getMessage(),
            'cost_center_name' => 'Error',
            'financial_year_code' => 'Error',
            'financial_year_closed' => 0,
            'total_debit' => '0.00',
            'total_credit' => '0.00',
            'status' => 'error',
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'can_approve' => false
        ]
    ]);
}
