<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

try {
    if (!isset($_POST['bank_account']) || empty($_POST['bank_account'])) {
        throw new Exception("Bank account is required.");
    }

    // Check if financial_year_id column exists in journal_entries table
    $column_check = $conn->query("SHOW COLUMNS FROM journal_entries LIKE 'financial_year_id'");
    $has_financial_year = ($column_check->rowCount() > 0);

    // Get transactions with running balance and reconciliation status
    $query = "
        WITH RECURSIVE running_balance AS (
            SELECT 
                je.id,
                je.entry_number,
                je.entry_date,
                je.entry_type,
                je.description,
                je.reference_number,
                je.status,";
                
    if ($has_financial_year) {
        $query .= "
                je.financial_year_id,
                COALESCE(fy.year_code, 'N/A') as financial_year_code,
                COALESCE(fy.is_closed, 0) as financial_year_closed,";
    } else {
        $query .= "
                NULL as financial_year_id,
                'N/A' as financial_year_code,
                0 as financial_year_closed,";
    }
    
    $query .= "
                COALESCE(jed.debit_amount, 0) as deposit_amount,
                COALESCE(jed.credit_amount, 0) as withdrawal_amount,
                CASE 
                    WHEN je.status = 'approved' THEN
                        SUM(COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0)) 
                        OVER (ORDER BY je.entry_date, je.id)
                    ELSE 0
                END as running_balance,
                CASE 
                    WHEN bri.is_reconciled = 1 THEN true 
                    ELSE false 
                END as is_reconciled
            FROM journal_entries je
            JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
            LEFT JOIN (
                SELECT DISTINCT bri.journal_entry_id, bri.is_reconciled
                FROM bank_reconciliation_items bri
                JOIN bank_reconciliation br ON bri.reconciliation_id = br.id
                WHERE br.status = 'completed'
            ) bri ON je.id = bri.journal_entry_id";
            
    if ($has_financial_year) {
        $query .= "
            LEFT JOIN financial_years fy ON je.financial_year_id = fy.id";
    }
    
    $query .= "
            WHERE jed.account_id = ? 
            AND je.status IN ('posted', 'approved')
            ORDER BY je.entry_date DESC, je.id DESC
        )
        SELECT * FROM running_balance";

    $stmt = $conn->prepare($query);
    $stmt->bindValue(1, $_POST['bank_account'], PDO::PARAM_INT);
    $stmt->execute();

    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $transactions
    ]);

} catch (PDOException $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
