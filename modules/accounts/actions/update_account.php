<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set up custom error logging
$logFile = '../../../logs/error.log';
function logError($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    try {
        logError("Received POST data: " . print_r($_POST, true));
        
        // Validate required fields
        $required_fields = ['id', 'account_code', 'account_name', 'category_id', 'balance_type', 'opening_balance'];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
                throw new Exception("Field {$field} is required");
            }
        }
        
        $conn->beginTransaction();

        // Get old opening balance
        $old_balance_query = "SELECT opening_balance, balance_type FROM chart_of_accounts WHERE id = ?";
        $old_balance_stmt = $conn->prepare($old_balance_query);
        $old_balance_stmt->bindValue(1, $_POST['id'], PDO::PARAM_INT);
        $old_balance_stmt->execute();
        $old_data = $old_balance_stmt->fetch(PDO::FETCH_ASSOC);
        $old_opening_balance = floatval($old_data['opening_balance']);
        $new_opening_balance = floatval($_POST['opening_balance']);

        // If opening balance has changed, create adjustment entry
        if ($new_opening_balance != $old_opening_balance) {
            $difference = $new_opening_balance - $old_opening_balance;
            $abs_difference = abs($difference);
            
            // Get next entry number
            $entry_number_query = "SELECT COALESCE(MAX(CAST(SUBSTRING(entry_number, 3) AS UNSIGNED)), 0) + 1 as next_num 
                                 FROM journal_entries 
                                 WHERE entry_number LIKE 'JE%'";
            $entry_number_stmt = $conn->prepare($entry_number_query);
            $entry_number_stmt->execute();
            $next_num = $entry_number_stmt->fetch(PDO::FETCH_ASSOC)['next_num'];
            $entry_number = 'JE' . str_pad($next_num, 6, '0', STR_PAD_LEFT);

            // Create adjustment journal entry
            $je_query = "INSERT INTO journal_entries (
                entry_number, entry_date, entry_type, description,
                total_debit, total_credit, status, created_by
            ) VALUES (
                ?, NOW(), 'adjustment', 
                'Opening Balance Adjustment for Account: " . $_POST['account_name'] . "',
                ?, ?, 'posted', ?
            )";
            $je_stmt = $conn->prepare($je_query);
            $je_stmt->bindValue(1, $entry_number);
            $je_stmt->bindValue(2, $abs_difference);
            $je_stmt->bindValue(3, $abs_difference);
            $je_stmt->bindValue(4, $_SESSION['user_id']);
            $je_stmt->execute();
            
            $journal_entry_id = $conn->lastInsertId();

            // Get opening balance equity account
            $equity_query = "SELECT id FROM chart_of_accounts WHERE account_code = '3001'"; // Opening Balance Equity
            $equity_stmt = $conn->prepare($equity_query);
            $equity_stmt->execute();
            $equity_account_id = $equity_stmt->fetch(PDO::FETCH_ASSOC)['id'];

            // Create journal entry details
            if ($difference > 0) {
                // If new balance is higher: Debit bank, Credit equity
                $jed_query = "INSERT INTO journal_entry_details 
                    (journal_entry_id, account_id, debit_amount, credit_amount, created_by) VALUES 
                    (?, ?, ?, 0, ?),
                    (?, ?, 0, ?, ?)";
                $jed_stmt = $conn->prepare($jed_query);
                $jed_stmt->bindValue(1, $journal_entry_id);
                $jed_stmt->bindValue(2, $_POST['id']); // Bank account
                $jed_stmt->bindValue(3, $abs_difference);
                $jed_stmt->bindValue(4, $_SESSION['user_id']);
                $jed_stmt->bindValue(5, $journal_entry_id);
                $jed_stmt->bindValue(6, $equity_account_id);
                $jed_stmt->bindValue(7, $abs_difference);
                $jed_stmt->bindValue(8, $_SESSION['user_id']);
            } else {
                // If new balance is lower: Credit bank, Debit equity
                $jed_query = "INSERT INTO journal_entry_details 
                    (journal_entry_id, account_id, debit_amount, credit_amount, created_by) VALUES 
                    (?, ?, ?, 0, ?),
                    (?, ?, 0, ?, ?)";
                $jed_stmt = $conn->prepare($jed_query);
                $jed_stmt->bindValue(1, $journal_entry_id);
                $jed_stmt->bindValue(2, $equity_account_id);
                $jed_stmt->bindValue(3, $abs_difference);
                $jed_stmt->bindValue(4, $_SESSION['user_id']);
                $jed_stmt->bindValue(5, $journal_entry_id);
                $jed_stmt->bindValue(6, $_POST['id']); // Bank account
                $jed_stmt->bindValue(7, $abs_difference);
                $jed_stmt->bindValue(8, $_SESSION['user_id']);
            }
            $jed_stmt->execute();
        }

        // Update chart_of_accounts
        $query = "UPDATE chart_of_accounts SET 
            account_code = ?,
            account_name = ?,
            category_id = ?,
            parent_id = ?,
            description = ?,
            balance_type = ?,
            opening_balance = ?,
            current_balance = ?,
            is_group = ?,
            is_bank_account = ?,
            updated_by = ?,
            updated_at = NOW()
            WHERE id = ? AND status = 1";

        logError("Account update query: " . $query);
        
        $stmt = $conn->prepare($query);
        
        // Convert checkbox values
        $is_group = !empty($_POST['is_group']) ? 1 : 0;
        $is_bank = !empty($_POST['is_bank_account']) ? 1 : 0;
        $parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
        
        // Bind parameters in order
        $stmt->bindValue(1, $_POST['account_code'], PDO::PARAM_STR);
        $stmt->bindValue(2, $_POST['account_name'], PDO::PARAM_STR);
        $stmt->bindValue(3, $_POST['category_id'], PDO::PARAM_INT);
        $stmt->bindValue(4, $parent_id, $parent_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(5, $_POST['description'], PDO::PARAM_STR);
        $stmt->bindValue(6, $_POST['balance_type'], PDO::PARAM_STR);
        $stmt->bindValue(7, $_POST['opening_balance'], PDO::PARAM_STR);
        $stmt->bindValue(8, $_POST['opening_balance'], PDO::PARAM_STR);
        $stmt->bindValue(9, $is_group, PDO::PARAM_INT);
        $stmt->bindValue(10, $is_bank, PDO::PARAM_INT);
        $stmt->bindValue(11, $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(12, $_POST['id'], PDO::PARAM_INT);

        if (!$stmt->execute()) {
            $error = $stmt->errorInfo();
            logError("Error updating account: " . print_r($error, true));
            throw new PDOException('Error updating account: ' . $error[2]);
        }

        // If this is a bank account, update bank details
        if ($is_bank) {
            // Validate bank fields
            if (empty($_POST['bank_name']) || empty($_POST['account_number'])) {
                throw new Exception('Bank name and account number are required for bank accounts');
            }
            
            // Check if bank details exist
            $check = $conn->prepare("SELECT account_id FROM bank_accounts WHERE account_id = ? AND status = 1 LIMIT 1");
            $check->bindValue(1, $_POST['id'], PDO::PARAM_INT);
            $check->execute();
            
            if ($check->fetch()) {
                // Update existing bank details
                $bank_query = "UPDATE bank_accounts SET 
                    bank_name = ?,
                    branch_name = ?,
                    account_number = ?,
                    swift_code = ?,
                    iban = ?,
                    account_title = ?,
                    opening_balance = ?,
                    current_balance = ?,
                    updated_by = ?,
                    updated_at = NOW()
                    WHERE account_id = ? AND status = 1";
                    
                logError("Bank update query: " . $bank_query);
                
                $bank_stmt = $conn->prepare($bank_query);
                
                // Bind parameters in order
                $bank_stmt->bindValue(1, $_POST['bank_name'], PDO::PARAM_STR);
                $bank_stmt->bindValue(2, !empty($_POST['branch_name']) ? $_POST['branch_name'] : null, PDO::PARAM_STR);
                $bank_stmt->bindValue(3, $_POST['account_number'], PDO::PARAM_STR);
                $bank_stmt->bindValue(4, !empty($_POST['swift_code']) ? $_POST['swift_code'] : null, PDO::PARAM_STR);
                $bank_stmt->bindValue(5, !empty($_POST['iban']) ? $_POST['iban'] : null, PDO::PARAM_STR);
                $bank_stmt->bindValue(6, $_POST['account_name'], PDO::PARAM_STR);
                $bank_stmt->bindValue(7, $_POST['opening_balance'], PDO::PARAM_STR);
                $bank_stmt->bindValue(8, $_POST['opening_balance'], PDO::PARAM_STR);
                $bank_stmt->bindValue(9, $_SESSION['user_id'], PDO::PARAM_INT);
                $bank_stmt->bindValue(10, $_POST['id'], PDO::PARAM_INT);
            } else {
                // Insert new bank details
                $bank_query = "INSERT INTO bank_accounts 
                    (account_id, bank_name, branch_name, account_number, swift_code, iban, 
                    account_title, opening_balance, current_balance, status, created_by, updated_by, 
                    created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())";
                    
                logError("Bank insert query: " . $bank_query);
                
                $bank_stmt = $conn->prepare($bank_query);
                
                // Bind parameters in order
                $bank_stmt->bindValue(1, $_POST['id'], PDO::PARAM_INT);
                $bank_stmt->bindValue(2, $_POST['bank_name'], PDO::PARAM_STR);
                $bank_stmt->bindValue(3, !empty($_POST['branch_name']) ? $_POST['branch_name'] : null, PDO::PARAM_STR);
                $bank_stmt->bindValue(4, $_POST['account_number'], PDO::PARAM_STR);
                $bank_stmt->bindValue(5, !empty($_POST['swift_code']) ? $_POST['swift_code'] : null, PDO::PARAM_STR);
                $bank_stmt->bindValue(6, !empty($_POST['iban']) ? $_POST['iban'] : null, PDO::PARAM_STR);
                $bank_stmt->bindValue(7, $_POST['account_name'], PDO::PARAM_STR);
                $bank_stmt->bindValue(8, $_POST['opening_balance'], PDO::PARAM_STR);
                $bank_stmt->bindValue(9, $_POST['opening_balance'], PDO::PARAM_STR);
                $bank_stmt->bindValue(10, $_SESSION['user_id'], PDO::PARAM_INT);
                $bank_stmt->bindValue(11, $_SESSION['user_id'], PDO::PARAM_INT);
            }

            if (!$bank_stmt->execute()) {
                $error = $bank_stmt->errorInfo();
                logError("Error updating bank details: " . print_r($error, true));
                throw new PDOException('Error updating bank details: ' . $error[2]);
            }
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Account updated successfully']);
        
    } catch (PDOException $e) {
        $conn->rollBack();
        logError("PDOException in update_account.php: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        logError("Exception in update_account.php: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request'
    ]);
}
