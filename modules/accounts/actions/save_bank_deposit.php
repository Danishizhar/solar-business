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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_add'])) {
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to perform this action.']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

try {
    // Debug
    error_log("POST data: " . print_r($_POST, true));

    // Validate required fields
    $required_fields = ['entry_date', 'amount', 'from_account', 'description', 'bank_account'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            throw new Exception("Please fill in all required fields. Missing: " . $field);
        }
    }

    // Validate amount
    if (!is_numeric($_POST['amount']) || $_POST['amount'] <= 0) {
        throw new Exception("Please enter a valid amount.");
    }

    // Validate accounts exist
    $account_check_query = "SELECT id FROM chart_of_accounts WHERE id = ? AND status = 1";
    
    // Check bank account
    $bank_stmt = $conn->prepare($account_check_query);
    $bank_stmt->bindValue(1, $_POST['bank_account'], PDO::PARAM_INT);
    $bank_stmt->execute();
    if (!$bank_stmt->fetch()) {
        throw new Exception("Selected bank account does not exist.");
    }

    // Check from account
    $from_stmt = $conn->prepare($account_check_query);
    $from_stmt->bindValue(1, $_POST['from_account'], PDO::PARAM_INT);
    $from_stmt->execute();
    if (!$from_stmt->fetch()) {
        throw new Exception("Selected from account does not exist.");
    }

    $conn->beginTransaction();

    // Get next entry number
    $entry_number_query = "SELECT COALESCE(MAX(CAST(SUBSTRING(entry_number, 3) AS UNSIGNED)), 0) + 1 as next_num 
                          FROM journal_entries 
                          WHERE entry_number LIKE 'JE%'";
    $entry_number_stmt = $conn->prepare($entry_number_query);
    $entry_number_stmt->execute();
    $next_num = $entry_number_stmt->fetch(PDO::FETCH_ASSOC)['next_num'];
    $entry_number = 'JE' . str_pad($next_num, 6, '0', STR_PAD_LEFT);

    // Check if financial_year_id column exists in journal_entries table
    $column_check = $conn->query("SHOW COLUMNS FROM journal_entries LIKE 'financial_year_id'");
    $has_financial_year = ($column_check->rowCount() > 0);
    
    // Insert journal entry
    if ($has_financial_year && isset($_POST['financial_year_id']) && !empty($_POST['financial_year_id'])) {
        $je_query = "INSERT INTO journal_entries (
            entry_number, entry_date, entry_type, description,
            reference_number, notes, total_debit, total_credit,
            status, created_by, created_at, financial_year_id
        ) VALUES (
            ?, ?, 'receipt', ?,
            ?, ?, ?, ?,
            'posted', ?, NOW(), ?
        )";
        $je_stmt = $conn->prepare($je_query);
        $je_stmt->bindValue(1, $entry_number);
        $je_stmt->bindValue(2, $_POST['entry_date']);
        $je_stmt->bindValue(3, $_POST['description']);
        $je_stmt->bindValue(4, $_POST['reference_number'] ?? null);
        $je_stmt->bindValue(5, $_POST['notes'] ?? null);
        $je_stmt->bindValue(6, $_POST['amount']);
        $je_stmt->bindValue(7, $_POST['amount']);
        $je_stmt->bindValue(8, $_SESSION['user_id']);
        $je_stmt->bindValue(9, $_POST['financial_year_id'], PDO::PARAM_INT);
    } else {
        $je_query = "INSERT INTO journal_entries (
            entry_number, entry_date, entry_type, description,
            reference_number, notes, total_debit, total_credit,
            status, created_by, created_at
        ) VALUES (
            ?, ?, 'receipt', ?,
            ?, ?, ?, ?,
            'posted', ?, NOW()
        )";
        $je_stmt = $conn->prepare($je_query);
        $je_stmt->bindValue(1, $entry_number);
        $je_stmt->bindValue(2, $_POST['entry_date']);
        $je_stmt->bindValue(3, $_POST['description']);
        $je_stmt->bindValue(4, $_POST['reference_number'] ?? null);
        $je_stmt->bindValue(5, $_POST['notes'] ?? null);
        $je_stmt->bindValue(6, $_POST['amount']);
        $je_stmt->bindValue(7, $_POST['amount']);
        $je_stmt->bindValue(8, $_SESSION['user_id']);
    }
    $je_stmt->execute();

    $journal_entry_id = $conn->lastInsertId();

    // Insert journal entry details
    $jed_query = "INSERT INTO journal_entry_details 
        (journal_entry_id, account_id, debit_amount, credit_amount, created_by) VALUES 
        (?, ?, ?, 0, ?),
        (?, ?, 0, ?, ?)";
    $jed_stmt = $conn->prepare($jed_query);
    $jed_stmt->bindValue(1, $journal_entry_id);
    $jed_stmt->bindValue(2, $_POST['bank_account']); // Bank account (Debit)
    $jed_stmt->bindValue(3, $_POST['amount']);
    $jed_stmt->bindValue(4, $_SESSION['user_id']);
    $jed_stmt->bindValue(5, $journal_entry_id);
    $jed_stmt->bindValue(6, $_POST['from_account']); // From account (Credit)
    $jed_stmt->bindValue(7, $_POST['amount']);
    $jed_stmt->bindValue(8, $_SESSION['user_id']);
    $jed_stmt->execute();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Deposit saved successfully',
        'data' => [
            'entry_number' => $entry_number,
            'amount' => $_POST['amount']
        ]
    ]);

} catch (PDOException $e) {
    if ($conn) {
        $conn->rollBack();
    }
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    if ($conn) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
