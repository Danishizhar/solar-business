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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_edit'])) {
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
        if (empty($_POST['id']) || empty($_POST['bank_name']) || empty($_POST['account_number']) || empty($_POST['account_title'])) {
            throw new Exception("Please fill all required fields");
        }

        // Get bank account details
        $check_query = "
            SELECT ba.*, coa.account_name 
            FROM bank_accounts ba
            JOIN chart_of_accounts coa ON ba.account_id = coa.id
            WHERE ba.id = :id 
            AND ba.status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $check_stmt->execute();
        
        $bank_account = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bank_account) {
            throw new Exception("Bank account not found");
        }

        // Parse values
        $id = $_POST['id'];
        $bank_name = $_POST['bank_name'];
        $branch_name = $_POST['branch_name'] ?? null;
        $account_number = $_POST['account_number'];
        $iban = $_POST['iban'] ?? null;
        $swift_code = $_POST['swift_code'] ?? null;
        $account_title = $_POST['account_title'];
        $status = isset($_POST['status']) ? 1 : 0;

        // Check for duplicate account number
        $duplicate_query = "
            SELECT id 
            FROM bank_accounts 
            WHERE account_number = :account_number 
            AND id != :id 
            AND status = 1
        ";
        $duplicate_stmt = $conn->prepare($duplicate_query);
        $duplicate_stmt->bindParam(':account_number', $account_number, PDO::PARAM_STR);
        $duplicate_stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $duplicate_stmt->execute();
        
        if ($duplicate_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Account number already exists");
        }

        // Update bank account record
        $update_query = "
            UPDATE bank_accounts 
            SET
                bank_name = :bank_name,
                branch_name = :branch_name,
                account_number = :account_number,
                iban = :iban,
                swift_code = :swift_code,
                account_title = :account_title,
                status = :status,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :id
            AND status = 1
        ";

        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':bank_name', $bank_name, PDO::PARAM_STR);
        $update_stmt->bindParam(':branch_name', $branch_name, PDO::PARAM_STR);
        $update_stmt->bindParam(':account_number', $account_number, PDO::PARAM_STR);
        $update_stmt->bindParam(':iban', $iban, PDO::PARAM_STR);
        $update_stmt->bindParam(':swift_code', $swift_code, PDO::PARAM_STR);
        $update_stmt->bindParam(':account_title', $account_title, PDO::PARAM_STR);
        $update_stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $update_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_stmt->bindParam(':id', $id, PDO::PARAM_INT);

        if (!$update_stmt->execute()) {
            throw new Exception("Error updating bank account");
        }

        if ($update_stmt->rowCount() === 0) {
            throw new Exception("Bank account not found or already deleted");
        }

        // Log the update
        $log_query = "
            INSERT INTO activity_log (
                module, action, record_id, description, 
                created_by, created_at
            ) VALUES (
                'bank_accounts', 'update', :record_id, 
                :description, :created_by, NOW()
            )";
        $stmt = $conn->prepare($log_query);
        $description = "Updated bank account details for " . $bank_account['account_name'];
        $stmt->bindParam(':record_id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Bank account updated successfully',
            'data' => [
                'id' => $id,
                'bank_name' => $bank_name,
                'account_number' => $account_number,
                'account_title' => $account_title,
                'account_name' => $bank_account['account_name'],
                'status' => $status
            ]
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in update_bank_account.php: " . $e->getMessage());
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
