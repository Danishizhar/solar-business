<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['data' => []]);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    header('Content-Type: application/json');
    echo json_encode(['data' => []]);
    exit();
}

require_once '../../../config/database.php';
require_once '../../../includes/company_helpers.php';
$database = new Database();
$conn = $database->getConnection();

try {
    // Test database connection
    if (!$conn) {
        throw new PDOException("Database connection is null");
    }

    // Get all bank accounts with proper PDO prepared statement
    $bank_accounts_query = "
        SELECT 
            ba.id,
            coa.account_code,
            coa.account_name,
            'PKR' as currency,
            ba.bank_name,
            ba.branch_name,
            ba.account_number,
            ba.swift_code,
            ba.iban,
            ba.account_title,
            COALESCE(coa.opening_balance, 0) as opening_balance,
            coa.opening_balance + COALESCE(
                (SELECT SUM(
                    CASE 
                        WHEN ac.type IN ('asset', 'expense') THEN 
                            COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0)
                        ELSE 
                            COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0)
                    END
                )
                FROM journal_entries je
                JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                WHERE jed.account_id = coa.id
                AND je.status IN ('posted', 'approved')
                ), 0
            ) as current_balance,
            ba.last_reconciliation_date,
            ba.status as is_active,
            ba.created_at,
            ba.updated_at,
            COALESCE(c.username, '') as created_by_name,
            COALESCE(u.username, '') as updated_by_name
        FROM bank_accounts ba
        INNER JOIN chart_of_accounts coa ON coa.id = ba.account_id
        INNER JOIN account_categories ac ON coa.category_id = ac.id
        LEFT JOIN users c ON ba.created_by = c.id
        LEFT JOIN users u ON ba.updated_by = u.id
        WHERE ba.status = :status
        ORDER BY coa.account_code";

    error_log("Executing query: " . $bank_accounts_query);
    
    $stmt = $conn->prepare($bank_accounts_query);
    $stmt->bindValue(':status', 1, PDO::PARAM_INT);
    $stmt->execute();
    $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Found " . count($bank_accounts) . " bank accounts");
    error_log("Bank accounts data: " . json_encode($bank_accounts));

    // Add permission flags and format numbers for each account
    foreach ($bank_accounts as &$account) {
        $account['can_view'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_view']);
        $account['can_edit'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_edit']);
        $account['can_delete'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_delete']);
        
        // Format numbers with 2 decimal places
        $account['opening_balance'] = formatNumber($conn, (float)$account['opening_balance']);
        $account['current_balance'] = formatNumber($conn, (float)$account['current_balance']);
        
        // Format dates
        $account['created_at'] = !empty($account['created_at']) ? date('Y-m-d H:i:s', strtotime($account['created_at'])) : '';
        $account['updated_at'] = !empty($account['updated_at']) ? date('Y-m-d H:i:s', strtotime($account['updated_at'])) : '';
        $account['last_reconciliation_date'] = !empty($account['last_reconciliation_date']) ? date('Y-m-d', strtotime($account['last_reconciliation_date'])) : '';
    }

    $response = ['data' => $bank_accounts];
    error_log("Sending response: " . json_encode($response));
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();

} catch (PDOException $e) {
    error_log("Error in get_bank_accounts.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    header('Content-Type: application/json');
    echo json_encode([
        'data' => [], 
        'error' => 'Database error occurred. Please check error logs.'
    ]);
    exit();
} catch (Exception $e) {
    error_log("General error in get_bank_accounts.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    header('Content-Type: application/json');
    echo json_encode([
        'data' => [], 
        'error' => 'An error occurred. Please check error logs.'
    ]);
    exit();
}
