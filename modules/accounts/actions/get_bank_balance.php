<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

try {
    // Validate bank account
    if (!isset($_POST['bank_account']) || empty($_POST['bank_account'])) {
        throw new Exception("Bank account is required.");
    }

    // Get current balance with proper calculation based on account type
    $balance_query = "
        SELECT 
            coa.account_code,
            coa.account_name,
            ac.type as account_type,
            coa.opening_balance + 
            CASE 
                WHEN ac.type IN ('asset', 'expense') THEN 
                    COALESCE((
                        SELECT SUM(COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0))
                        FROM journal_entry_details jed
                        JOIN journal_entries je ON je.id = jed.journal_entry_id
                        WHERE jed.account_id = coa.id
                        AND je.status = 'posted'
                    ), 0)
                ELSE 
                    COALESCE((
                        SELECT SUM(COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0))
                        FROM journal_entry_details jed
                        JOIN journal_entries je ON je.id = jed.journal_entry_id
                        WHERE jed.account_id = coa.id
                        AND je.status = 'posted'
                    ), 0)
            END as current_balance
        FROM chart_of_accounts coa
        JOIN account_categories ac ON coa.category_id = ac.id
        WHERE coa.id = ?
        AND coa.status = 1";

    $balance_stmt = $conn->prepare($balance_query);
    $balance_stmt->bindValue(1, $_POST['bank_account'], PDO::PARAM_INT);
    $balance_stmt->execute();
    
    $result = $balance_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        throw new Exception("Account not found or inactive.");
    }

    // For asset/expense accounts, negative means credit balance
    // For liability/equity/revenue accounts, negative means debit balance
    $is_negative = ($result['account_type'] == 'asset' || $result['account_type'] == 'expense') ? 
                   ($result['current_balance'] < 0) : ($result['current_balance'] > 0);
    
    echo json_encode([
        'status' => 'success',
        'balance' => $result['current_balance'],
        'formatted_balance' => formatNumber($conn, abs($result['current_balance'])),
        'is_negative' => $is_negative,
        'account_type' => $result['account_type']
    ]);

} catch (Exception $e) {
    error_log("Error in get_bank_balance.php: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
