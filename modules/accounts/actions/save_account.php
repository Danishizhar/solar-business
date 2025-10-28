<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable display errors
ini_set('log_errors', 1);
ini_set('error_log', 'c:/xampp/htdocs/Solar Business/logs/php_error.log');

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

        // Debug log
        error_log("Received POST data: " . print_r($_POST, true));

        // Validate required fields
        $required_fields = ['account_code', 'account_name', 'category_id', 'balance_type'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field {$field} is required");
            }
        }

        // Check if account code already exists
        $check_query = "SELECT id FROM chart_of_accounts WHERE account_code = :code AND status = 1";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':code', $_POST['account_code'], PDO::PARAM_STR);
        $check_stmt->execute();
        
        if ($check_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Account code already exists");
        }

        // Validate category exists
        $category_query = "SELECT id FROM account_categories WHERE id = :id AND status = 1";
        $category_stmt = $conn->prepare($category_query);
        $category_stmt->bindParam(':id', $_POST['category_id'], PDO::PARAM_INT);
        $category_stmt->execute();
        if (!$category_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Invalid category selected");
        }

        // Calculate level based on parent
        $level = 0;
        if (!empty($_POST['parent_id'])) {
            $parent_query = "SELECT level FROM chart_of_accounts WHERE id = :parent_id AND status = 1";
            $parent_stmt = $conn->prepare($parent_query);
            $parent_stmt->bindParam(':parent_id', $_POST['parent_id'], PDO::PARAM_INT);
            $parent_stmt->execute();
            $parent = $parent_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                throw new Exception("Invalid parent account selected");
            }
            $level = $parent['level'] + 1;
        }

        // Validate user exists
        $user_query = "SELECT id FROM users WHERE id = :id";
        $user_stmt = $conn->prepare($user_query);
        $user_stmt->bindParam(':id', $_SESSION['user_id'], PDO::PARAM_INT);
        $user_stmt->execute();
        if (!$user_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Invalid user session");
        }

        // Check if this is a bank account
        $is_bank = isset($_POST['is_bank_account']) && $_POST['is_bank_account'] === 'on';
        
        // Validate bank details if this is a bank account
        if ($is_bank) {
            if (empty($_POST['bank_name'])) {
                throw new Exception("Bank Name is required for bank accounts");
            }
            if (empty($_POST['account_number'])) {
                throw new Exception("Account Number is required for bank accounts");
            }
        }

        // Insert into chart_of_accounts
        $account_query = "
            INSERT INTO chart_of_accounts 
            SET account_code = :account_code,
                account_name = :account_name,
                category_id = :category_id,
                description = :description,
                is_group = :is_group,
                parent_id = :parent_id,
                level = :level,
                opening_balance = :opening_balance,
                current_balance = :current_balance,
                balance_type = :balance_type,
                is_bank_account = :is_bank_account,
                status = 1,
                created_by = :created_by,
                updated_by = :updated_by";

        $account_stmt = $conn->prepare($account_query);

        // Bind parameters
        $account_stmt->bindParam(':account_code', $_POST['account_code'], PDO::PARAM_STR);
        $account_stmt->bindParam(':account_name', $_POST['account_name'], PDO::PARAM_STR);
        $account_stmt->bindParam(':category_id', $_POST['category_id'], PDO::PARAM_INT);
        $description = !empty($_POST['description']) ? $_POST['description'] : null;
        $account_stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $is_group = !empty($_POST['is_group']) ? 1 : 0;
        $account_stmt->bindParam(':is_group', $is_group, PDO::PARAM_INT);
        $parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
        $account_stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
        $account_stmt->bindParam(':level', $level, PDO::PARAM_INT);
        $opening_balance = !empty($_POST['opening_balance']) ? $_POST['opening_balance'] : 0;
        $account_stmt->bindParam(':opening_balance', $opening_balance, PDO::PARAM_STR);
        $account_stmt->bindParam(':current_balance', $opening_balance, PDO::PARAM_STR);
        $account_stmt->bindParam(':balance_type', $_POST['balance_type'], PDO::PARAM_STR);
        $account_stmt->bindParam(':is_bank_account', $is_bank, PDO::PARAM_INT);
        $account_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $account_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

        // Execute the query
        if (!$account_stmt->execute()) {
            error_log("Error executing account insert: " . print_r($account_stmt->errorInfo(), true));
            throw new Exception("Error creating account");
        }

        // Get the new account ID
        $account_id = $conn->lastInsertId();

        // If this is a bank account, add bank details
        if ($is_bank) {
            error_log("Adding bank details for account ID: " . $account_id);
            
            $bank_query = "
                INSERT INTO bank_accounts 
                SET account_id = :account_id,
                    bank_name = :bank_name,
                    branch_name = :branch_name,
                    account_number = :account_number,
                    swift_code = :swift_code,
                    iban = :iban,
                    account_title = :account_title,
                    opening_balance = :opening_balance,
                    current_balance = :current_balance,
                    status = 1,
                    created_by = :created_by,
                    updated_by = :updated_by";

            error_log("Bank query: " . $bank_query);
            $bank_stmt = $conn->prepare($bank_query);

            // Bind bank parameters
            $bank_stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
            $bank_stmt->bindParam(':bank_name', $_POST['bank_name'], PDO::PARAM_STR);
            $branch_name = !empty($_POST['branch_name']) ? $_POST['branch_name'] : null;
            $bank_stmt->bindParam(':branch_name', $branch_name, PDO::PARAM_STR);
            $bank_stmt->bindParam(':account_number', $_POST['account_number'], PDO::PARAM_STR);
            $swift_code = !empty($_POST['swift_code']) ? $_POST['swift_code'] : null;
            $bank_stmt->bindParam(':swift_code', $swift_code, PDO::PARAM_STR);
            $iban = !empty($_POST['iban']) ? $_POST['iban'] : null;
            $bank_stmt->bindParam(':iban', $iban, PDO::PARAM_STR);
            $bank_stmt->bindParam(':account_title', $_POST['account_name'], PDO::PARAM_STR);
            $bank_stmt->bindParam(':opening_balance', $opening_balance, PDO::PARAM_STR);
            $bank_stmt->bindParam(':current_balance', $opening_balance, PDO::PARAM_STR);
            $bank_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
            $bank_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

            // Execute bank query
            if (!$bank_stmt->execute()) {
                $error = $bank_stmt->errorInfo();
                error_log("Error executing bank insert: " . print_r($error, true));
                throw new PDOException($error[2], $error[1]);
            }
        }

        // If there's an opening balance, create an opening balance entry
        if ($opening_balance != 0) {
            // Get the retained earnings account
            $retained_query = "SELECT id FROM chart_of_accounts WHERE account_code = '3002' AND status = 1 LIMIT 1";
            $retained_stmt = $conn->prepare($retained_query);
            $retained_stmt->execute();
            $retained = $retained_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$retained) {
                throw new Exception("Retained earnings account not found");
            }

            // Create opening balance journal entry
            $journal_query = "
                INSERT INTO journal_entries 
                SET entry_number = :entry_number,
                    entry_date = CURDATE(),
                    description = :description,
                    entry_type = 'manual',
                    total_debit = :total_debit,
                    total_credit = :total_credit,
                    status = 'posted',
                    created_by = :created_by,
                    updated_by = :updated_by";

            $journal_stmt = $conn->prepare($journal_query);
            
            // Set amounts based on balance type
            $total_debit = $_POST['balance_type'] == 'debit' ? $opening_balance : 0;
            $total_credit = $_POST['balance_type'] == 'credit' ? $opening_balance : 0;
            
            $entry_number = 'OB-' . date('Ymd') . '-' . str_pad($account_id, 4, '0', STR_PAD_LEFT);
            $description = "Opening balance for " . $_POST['account_name'];
            
            $journal_stmt->bindParam(':entry_number', $entry_number, PDO::PARAM_STR);
            $journal_stmt->bindParam(':description', $description, PDO::PARAM_STR);
            $journal_stmt->bindParam(':total_debit', $total_debit, PDO::PARAM_STR);
            $journal_stmt->bindParam(':total_credit', $total_credit, PDO::PARAM_STR);
            $journal_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
            $journal_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

            if (!$journal_stmt->execute()) {
                error_log("Error executing journal entry: " . print_r($journal_stmt->errorInfo(), true));
                throw new Exception("Error creating opening balance entry");
            }

            $journal_id = $conn->lastInsertId();

            // Create journal details
            $details_query = "
                INSERT INTO journal_entry_details 
                SET journal_entry_id = :journal_id,
                    account_id = :account_id1,
                    description = :desc1,
                    debit_amount = :debit1,
                    credit_amount = :credit1,
                    created_by = :created_by,
                    updated_by = :updated_by";

            $details_stmt = $conn->prepare($details_query);

            // Set amounts based on balance type
            $debit1 = $_POST['balance_type'] == 'debit' ? $opening_balance : 0;
            $credit1 = $_POST['balance_type'] == 'credit' ? $opening_balance : 0;
            $debit2 = $credit1;  // Opposite for retained earnings
            $credit2 = $debit1;

            // Bind parameters for both entries
            $details_stmt->bindParam(':journal_id', $journal_id, PDO::PARAM_INT);
            $details_stmt->bindParam(':account_id1', $account_id, PDO::PARAM_INT);
            $details_stmt->bindParam(':desc1', $description, PDO::PARAM_STR);
            $details_stmt->bindParam(':debit1', $debit1, PDO::PARAM_STR);
            $details_stmt->bindParam(':credit1', $credit1, PDO::PARAM_STR);
            $details_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
            $details_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

            if (!$details_stmt->execute()) {
                error_log("Error executing journal details: " . print_r($details_stmt->errorInfo(), true));
                throw new Exception("Error creating opening balance details");
            }

            // Create journal details for retained earnings
            $details_query = "
                INSERT INTO journal_entry_details 
                SET journal_entry_id = :journal_id,
                    account_id = :account_id2,
                    description = :desc2,
                    debit_amount = :debit2,
                    credit_amount = :credit2,
                    created_by = :created_by,
                    updated_by = :updated_by";

            $details_stmt = $conn->prepare($details_query);

            // Bind parameters for retained earnings entry
            $details_stmt->bindParam(':journal_id', $journal_id, PDO::PARAM_INT);
            $details_stmt->bindParam(':account_id2', $retained['id'], PDO::PARAM_INT);
            $details_stmt->bindParam(':desc2', $description, PDO::PARAM_STR);
            $details_stmt->bindParam(':debit2', $debit2, PDO::PARAM_STR);
            $details_stmt->bindParam(':credit2', $credit2, PDO::PARAM_STR);
            $details_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
            $details_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

            if (!$details_stmt->execute()) {
                error_log("Error executing journal details: " . print_r($details_stmt->errorInfo(), true));
                throw new Exception("Error creating opening balance details");
            }
        }

        // Commit transaction
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Account created successfully']);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("PDOException in save_account.php: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        // Get the error info safely
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = isset($errorInfo[0]) ? $errorInfo[0] : null;
        $errorCode = isset($errorInfo[1]) ? $errorInfo[1] : null;
        $errorMsg = isset($errorInfo[2]) ? $errorInfo[2] : null;
        
        error_log("SQL State: " . $sqlState);
        error_log("Error code: " . $errorCode);
        error_log("Error message: " . $errorMsg);
        
        // Check for foreign key constraint violations
        if ($e->getCode() == '23000') {
            echo json_encode(['success' => false, 'message' => 'Database constraint error. Please ensure all referenced records exist.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error occurred: ' . $e->getMessage()]);
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("Exception in save_account.php: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
