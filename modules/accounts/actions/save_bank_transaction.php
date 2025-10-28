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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_add'])) {
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
        if (empty($_POST['transaction_type']) || empty($_POST['transaction_date']) || empty($_POST['amount']) || empty($_POST['description'])) {
            throw new Exception("Please fill all required fields");
        }

        // Validate amount
        $amount = floatval($_POST['amount']);
        if ($amount <= 0) {
            throw new Exception("Amount must be greater than zero");
        }

        // Process based on transaction type
        switch ($_POST['transaction_type']) {
            case 'deposit':
                if (empty($_POST['bank_account_id']) || empty($_POST['from_account_id'])) {
                    throw new Exception("Bank account and source account are required");
                }
                $result = processDeposit(
                    $conn, 
                    $_POST['bank_account_id'], 
                    $_POST['from_account_id'], 
                    $amount, 
                    $_POST['transaction_date'], 
                    $_POST['description'], 
                    $_POST['reference_number'] ?? null
                );
                break;

            case 'withdrawal':
                if (empty($_POST['bank_account_id']) || empty($_POST['to_account_id'])) {
                    throw new Exception("Bank account and destination account are required");
                }
                $result = processWithdrawal(
                    $conn, 
                    $_POST['bank_account_id'], 
                    $_POST['to_account_id'], 
                    $amount, 
                    $_POST['transaction_date'], 
                    $_POST['description'], 
                    $_POST['reference_number'] ?? null
                );
                break;

            case 'transfer':
                if (empty($_POST['from_bank_account_id']) || empty($_POST['to_bank_account_id'])) {
                    throw new Exception("Source and destination bank accounts are required");
                }
                if ($_POST['from_bank_account_id'] == $_POST['to_bank_account_id']) {
                    throw new Exception("Source and destination bank accounts cannot be the same");
                }
                $result = processTransfer(
                    $conn, 
                    $_POST['from_bank_account_id'], 
                    $_POST['to_bank_account_id'], 
                    $amount, 
                    $_POST['transaction_date'], 
                    $_POST['description'], 
                    $_POST['reference_number'] ?? null
                );
                break;

            default:
                throw new Exception("Invalid transaction type");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => $result['message'],
            'entry_id' => $result['entry_id']
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in save_bank_transaction.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

/**
 * Process a bank deposit
 */
function processDeposit($conn, $bank_account_id, $from_account_id, $amount, $date, $description, $reference = null) {
    // Generate entry number (Format: BD-YYYYMM-XXXX)
    $year_month = date('Ym', strtotime($date));
    $number_query = "SELECT MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)) as last_number 
                    FROM journal_entries 
                    WHERE entry_number LIKE :entry_pattern 
                    AND status = 1";
    $stmt = $conn->prepare($number_query);
    $entry_pattern = "BD-$year_month-%";
    $stmt->bindParam(':entry_pattern', $entry_pattern, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_number = str_pad(($row['last_number'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
    $entry_number = "BD-$year_month-$next_number";

    // Insert journal entry header
    $stmt = $conn->prepare("
        INSERT INTO journal_entries (
            entry_number, entry_date, entry_type, reference_number, 
            description, total_debit, total_credit,
            status, created_by, created_at, updated_by, updated_at
        ) VALUES (
            :entry_number, :entry_date, 'receipt', :reference_number, 
            :description, :total_debit, :total_credit,
            'draft', :created_by, NOW(), :updated_by, NOW()
        )
    ");

    $stmt->bindParam(':entry_number', $entry_number, PDO::PARAM_STR);
    $stmt->bindParam(':entry_date', $date, PDO::PARAM_STR);
    $stmt->bindParam(':reference_number', $reference, PDO::PARAM_STR);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->bindParam(':total_debit', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':total_credit', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
        throw new PDOException("Error creating journal entry");
    }
    
    $entry_id = $conn->lastInsertId();

    // Insert journal entry details
    $detail_stmt = $conn->prepare("
        INSERT INTO journal_entry_details (
            journal_entry_id, account_id, description, 
            debit_amount, credit_amount, created_by, created_at, updated_by, updated_at
        ) VALUES (
            :journal_entry_id, :account_id, :description, 
            :debit_amount, :credit_amount, :created_by, NOW(), :updated_by, NOW()
        )
    ");

    // Bank account receives money (debit)
    $detail_stmt->bindParam(':journal_entry_id', $entry_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':account_id', $bank_account_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $detail_stmt->bindParam(':debit_amount', $amount, PDO::PARAM_STR);
    $zero = 0;
    $detail_stmt->bindParam(':credit_amount', $zero, PDO::PARAM_STR);
    $detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $detail_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

    if (!$detail_stmt->execute()) {
        throw new PDOException("Error saving entry details");
    }

    // Source account gives money (credit)
    $detail_stmt->bindParam(':journal_entry_id', $entry_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':account_id', $from_account_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $detail_stmt->bindParam(':debit_amount', $zero, PDO::PARAM_STR);
    $detail_stmt->bindParam(':credit_amount', $amount, PDO::PARAM_STR);
    $detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $detail_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

    if (!$detail_stmt->execute()) {
        throw new PDOException("Error saving entry details");
    }

    // Log the transaction
    $log_query = "
        INSERT INTO activity_log (
            module, action, record_id, description, 
            created_by, created_at
        ) VALUES (
            'bank_transactions', 'deposit', :record_id, 
            :description, :created_by, NOW()
        )";
    $stmt = $conn->prepare($log_query);
    $log_description = "Created bank deposit of " . formatNumber($conn, $amount);
    $stmt->bindParam(':record_id', $entry_id, PDO::PARAM_INT);
    $stmt->bindParam(':description', $log_description, PDO::PARAM_STR);
    $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();

    return [
        'success' => true,
        'message' => 'Bank deposit created successfully',
        'entry_id' => $entry_id
    ];
}

/**
 * Process a bank withdrawal
 */
function processWithdrawal($conn, $bank_account_id, $to_account_id, $amount, $date, $description, $reference = null) {
    // Generate entry number (Format: BW-YYYYMM-XXXX)
    $year_month = date('Ym', strtotime($date));
    $number_query = "SELECT MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)) as last_number 
                    FROM journal_entries 
                    WHERE entry_number LIKE :entry_pattern 
                    AND status = 1";
    $stmt = $conn->prepare($number_query);
    $entry_pattern = "BW-$year_month-%";
    $stmt->bindParam(':entry_pattern', $entry_pattern, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_number = str_pad(($row['last_number'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
    $entry_number = "BW-$year_month-$next_number";

    // Insert journal entry header
    $stmt = $conn->prepare("
        INSERT INTO journal_entries (
            entry_number, entry_date, entry_type, reference_number, 
            description, total_debit, total_credit,
            status, created_by, created_at, updated_by, updated_at
        ) VALUES (
            :entry_number, :entry_date, 'payment', :reference_number, 
            :description, :total_debit, :total_credit,
            'draft', :created_by, NOW(), :updated_by, NOW()
        )
    ");

    $stmt->bindParam(':entry_number', $entry_number, PDO::PARAM_STR);
    $stmt->bindParam(':entry_date', $date, PDO::PARAM_STR);
    $stmt->bindParam(':reference_number', $reference, PDO::PARAM_STR);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->bindParam(':total_debit', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':total_credit', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
        throw new PDOException("Error creating journal entry");
    }
    
    $entry_id = $conn->lastInsertId();

    // Insert journal entry details
    $detail_stmt = $conn->prepare("
        INSERT INTO journal_entry_details (
            journal_entry_id, account_id, description, 
            debit_amount, credit_amount, created_by, created_at, updated_by, updated_at
        ) VALUES (
            :journal_entry_id, :account_id, :description, 
            :debit_amount, :credit_amount, :created_by, NOW(), :updated_by, NOW()
        )
    ");

    // Destination account receives money (debit)
    $detail_stmt->bindParam(':journal_entry_id', $entry_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':account_id', $to_account_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $detail_stmt->bindParam(':debit_amount', $amount, PDO::PARAM_STR);
    $zero = 0;
    $detail_stmt->bindParam(':credit_amount', $zero, PDO::PARAM_STR);
    $detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $detail_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

    if (!$detail_stmt->execute()) {
        throw new PDOException("Error saving entry details");
    }

    // Bank account gives money (credit)
    $detail_stmt->bindParam(':journal_entry_id', $entry_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':account_id', $bank_account_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $detail_stmt->bindParam(':debit_amount', $zero, PDO::PARAM_STR);
    $detail_stmt->bindParam(':credit_amount', $amount, PDO::PARAM_STR);
    $detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $detail_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

    if (!$detail_stmt->execute()) {
        throw new PDOException("Error saving entry details");
    }

    // Log the transaction
    $log_query = "
        INSERT INTO activity_log (
            module, action, record_id, description, 
            created_by, created_at
        ) VALUES (
            'bank_transactions', 'withdrawal', :record_id, 
            :description, :created_by, NOW()
        )";
    $stmt = $conn->prepare($log_query);
    $log_description = "Created bank withdrawal of " . formatNumber($conn, $amount);
    $stmt->bindParam(':record_id', $entry_id, PDO::PARAM_INT);
    $stmt->bindParam(':description', $log_description, PDO::PARAM_STR);
    $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();

    return [
        'success' => true,
        'message' => 'Bank withdrawal created successfully',
        'entry_id' => $entry_id
    ];
}

/**
 * Process a bank transfer
 */
function processTransfer($conn, $from_bank_account_id, $to_bank_account_id, $amount, $date, $description, $reference = null) {
    // Generate entry number (Format: BT-YYYYMM-XXXX)
    $year_month = date('Ym', strtotime($date));
    $number_query = "SELECT MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)) as last_number 
                    FROM journal_entries 
                    WHERE entry_number LIKE :entry_pattern 
                    AND status = 1";
    $stmt = $conn->prepare($number_query);
    $entry_pattern = "BT-$year_month-%";
    $stmt->bindParam(':entry_pattern', $entry_pattern, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_number = str_pad(($row['last_number'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
    $entry_number = "BT-$year_month-$next_number";

    // Insert journal entry header
    $stmt = $conn->prepare("
        INSERT INTO journal_entries (
            entry_number, entry_date, entry_type, reference_number, 
            description, total_debit, total_credit,
            status, created_by, created_at, updated_by, updated_at
        ) VALUES (
            :entry_number, :entry_date, 'transfer', :reference_number, 
            :description, :total_debit, :total_credit,
            'draft', :created_by, NOW(), :updated_by, NOW()
        )
    ");

    $stmt->bindParam(':entry_number', $entry_number, PDO::PARAM_STR);
    $stmt->bindParam(':entry_date', $date, PDO::PARAM_STR);
    $stmt->bindParam(':reference_number', $reference, PDO::PARAM_STR);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->bindParam(':total_debit', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':total_credit', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
        throw new PDOException("Error creating journal entry");
    }
    
    $entry_id = $conn->lastInsertId();

    // Insert journal entry details
    $detail_stmt = $conn->prepare("
        INSERT INTO journal_entry_details (
            journal_entry_id, account_id, description, 
            debit_amount, credit_amount, created_by, created_at, updated_by, updated_at
        ) VALUES (
            :journal_entry_id, :account_id, :description, 
            :debit_amount, :credit_amount, :created_by, NOW(), :updated_by, NOW()
        )
    ");

    // Get account IDs from bank accounts
    $bank_query = "SELECT account_id FROM bank_accounts WHERE id = :bank_id AND status = 1";
    
    // Get source bank account
    $bank_stmt = $conn->prepare($bank_query);
    $bank_stmt->bindParam(':bank_id', $from_bank_account_id, PDO::PARAM_INT);
    $bank_stmt->execute();
    $from_bank = $bank_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$from_bank) {
        throw new Exception("Source bank account not found");
    }
    
    // Get destination bank account
    $bank_stmt = $conn->prepare($bank_query);
    $bank_stmt->bindParam(':bank_id', $to_bank_account_id, PDO::PARAM_INT);
    $bank_stmt->execute();
    $to_bank = $bank_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$to_bank) {
        throw new Exception("Destination bank account not found");
    }

    // Destination bank account receives money (debit)
    $detail_stmt->bindParam(':journal_entry_id', $entry_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':account_id', $to_bank['account_id'], PDO::PARAM_INT);
    $detail_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $detail_stmt->bindParam(':debit_amount', $amount, PDO::PARAM_STR);
    $zero = 0;
    $detail_stmt->bindParam(':credit_amount', $zero, PDO::PARAM_STR);
    $detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $detail_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

    if (!$detail_stmt->execute()) {
        throw new PDOException("Error saving entry details");
    }

    // Source bank account gives money (credit)
    $detail_stmt->bindParam(':journal_entry_id', $entry_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':account_id', $from_bank['account_id'], PDO::PARAM_INT);
    $detail_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $detail_stmt->bindParam(':debit_amount', $zero, PDO::PARAM_STR);
    $detail_stmt->bindParam(':credit_amount', $amount, PDO::PARAM_STR);
    $detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $detail_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

    if (!$detail_stmt->execute()) {
        throw new PDOException("Error saving entry details");
    }

    // Log the transaction
    $log_query = "
        INSERT INTO activity_log (
            module, action, record_id, description, 
            created_by, created_at
        ) VALUES (
            'bank_transactions', 'transfer', :record_id, 
            :description, :created_by, NOW()
        )";
    $stmt = $conn->prepare($log_query);
    $log_description = "Created bank transfer of " . formatNumber($conn, $amount);
    $stmt->bindParam(':record_id', $entry_id, PDO::PARAM_INT);
    $stmt->bindParam(':description', $log_description, PDO::PARAM_STR);
    $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();

    return [
        'success' => true,
        'message' => 'Bank transfer created successfully',
        'entry_id' => $entry_id
    ];
}
