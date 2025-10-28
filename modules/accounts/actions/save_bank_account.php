<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login to continue']);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_add'])) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to add bank accounts']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $conn->beginTransaction();

        // Get account details from POST
        $account_id = $_POST['account_id'] ?? '';
        $bank_name = $_POST['bank_name'] ?? '';
        $branch_name = $_POST['branch_name'] ?? '';
        $account_number = $_POST['account_number'] ?? '';
        $account_title = $_POST['account_title'] ?? '';
        $swift_code = $_POST['swift_code'] ?? '';
        $iban = $_POST['iban'] ?? '';

        // Validate required fields
        $required_fields = [
            'account_id' => 'Account',
            'bank_name' => 'Bank Name',
            'account_number' => 'Account Number',
            'account_title' => 'Account Title'
        ];

        $missing_fields = [];
        foreach ($required_fields as $field => $label) {
            if (empty($_POST[$field])) {
                $missing_fields[] = $label;
            }
        }

        if (!empty($missing_fields)) {
            throw new Exception('Required fields missing: ' . implode(', ', $missing_fields));
        }

        // Check if account exists and is not already a bank account
        $check_query = "SELECT id, account_code, account_name FROM chart_of_accounts 
                       WHERE id = :account_id AND status = 1 AND is_bank_account = 0";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':account_id', $account_id);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() == 0) {
            throw new Exception('Selected account does not exist or is already a bank account');
        }

        $account = $check_stmt->fetch(PDO::FETCH_ASSOC);

        // Create bank account entry
        $bank_query = "INSERT INTO bank_accounts (
            account_id,
            bank_name,
            branch_name,
            account_number,
            swift_code,
            iban,
            account_title,
            opening_balance,
            current_balance,
            status,
            created_by,
            created_at
        ) VALUES (
            :account_id,
            :bank_name,
            :branch_name,
            :account_number,
            :swift_code,
            :iban,
            :account_title,
            0,
            0,
            1,
            :created_by,
            NOW()
        )";

        $bank_stmt = $conn->prepare($bank_query);
        $bank_stmt->bindParam(':account_id', $account_id);
        $bank_stmt->bindParam(':bank_name', $bank_name);
        $bank_stmt->bindParam(':branch_name', $branch_name);
        $bank_stmt->bindParam(':account_number', $account_number);
        $bank_stmt->bindParam(':swift_code', $swift_code);
        $bank_stmt->bindParam(':iban', $iban);
        $bank_stmt->bindParam(':account_title', $account_title);
        $bank_stmt->bindParam(':created_by', $_SESSION['user_id']);
        $bank_stmt->execute();

        // Update chart of accounts to mark as bank account
        $update_query = "UPDATE chart_of_accounts 
                        SET is_bank_account = 1,
                            updated_by = :updated_by,
                            updated_at = NOW()
                        WHERE id = :account_id";
        
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':account_id', $account_id);
        $update_stmt->bindParam(':updated_by', $_SESSION['user_id']);
        $update_stmt->execute();

        // Log the activity
        $log_query = "INSERT INTO activity_log (
            module,
            record_id,
            description,
            created_by,
            created_at
        ) VALUES (
            'bank_accounts',
            :record_id,
            :description,
            :created_by,
            NOW()
        )";

        $log_stmt = $conn->prepare($log_query);
        $log_description = "Added bank details for account: {$account['account_code']} - {$account['account_name']}";
        $log_stmt->bindParam(':record_id', $account_id);
        $log_stmt->bindParam(':description', $log_description);
        $log_stmt->bindParam(':created_by', $_SESSION['user_id']);
        $log_stmt->execute();

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Bank details added successfully',
            'data' => [
                'id' => $account_id,
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name']
            ]
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Database error in save_bank_account.php: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred. Please try again.'
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
