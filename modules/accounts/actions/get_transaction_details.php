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

try {
    $database = new Database();
    $conn = $database->getConnection();

    if (!isset($_POST['id'])) {
        throw new Exception('Transaction ID is required');
    }

    // Get transaction header
    $query = "
        SELECT 
            je.*,
            u.username as created_by_name,
            u2.username as updated_by_name
        FROM journal_entries je
        LEFT JOIN users u ON je.created_by = u.id
        LEFT JOIN users u2 ON je.updated_by = u2.id
        WHERE je.id = :id
        AND je.status IN ('posted', 'approved')";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
    $stmt->execute();
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        throw new Exception('Transaction not found');
    }

    // Get transaction details
    $details_query = "
        SELECT 
            jed.*,
            coa.account_code,
            coa.account_name,
            coa.id as chart_account_id
        FROM journal_entry_details jed
        JOIN chart_of_accounts coa ON jed.account_id = coa.id
        WHERE jed.journal_entry_id = :entry_id
        ORDER BY jed.id";

    $stmt = $conn->prepare($details_query);
    $stmt->bindParam(':entry_id', $_POST['id'], PDO::PARAM_INT);
    $stmt->execute();
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    $total_debit = 0;
    $total_credit = 0;
    foreach ($details as $detail) {
        $total_debit += $detail['debit_amount'];
        $total_credit += $detail['credit_amount'];
    }

    // For transfers, find the to_account_id
    $to_account_id = null;
    if ($transaction['entry_type'] === 'transfer') {
        // In a transfer, the credit entry is the 'from' account and the debit entry is the 'to' account
        foreach ($details as $detail) {
            if ($detail['debit_amount'] > 0) {
                $to_account_id = $detail['chart_account_id'];
                break;
            }
        }
    }

    // Format response
    $response = [
        'success' => true,
        'data' => [
            'id' => $transaction['id'],
            'entry_number' => $transaction['entry_number'],
            'entry_date' => date('Y-m-d', strtotime($transaction['entry_date'])),
            'entry_type' => ucfirst($transaction['entry_type']),
            'reference_number' => $transaction['reference_number'],
            'description' => $transaction['description'],
            'notes' => $transaction['notes'],
            'status' => $transaction['status'],
            'financial_year_id' => $transaction['financial_year_id'],
            'to_account_id' => $to_account_id,
            'created_by' => $transaction['created_by_name'],
            'created_at' => $transaction['created_at'],
            'updated_by' => $transaction['updated_by_name'],
            'updated_at' => $transaction['updated_at'],
            'details' => $details,
            'total_debit' => $total_debit,
            'total_credit' => $total_credit
        ]
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
