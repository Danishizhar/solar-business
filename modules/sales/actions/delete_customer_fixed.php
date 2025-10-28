<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['sales_delete'])) {
    header("Location: ../../../index.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Check if customer ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Customer ID is required.";
    header("Location: ../customers.php");
    exit();
}

$customer_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Log the delete request
file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - Delete request for customer ID: $customer_id\n", FILE_APPEND);

try {
    // Begin transaction
    $conn->beginTransaction();
    
    // Get customer details including account_id
    $customer_query = "SELECT customer_name, account_id FROM customers WHERE id = ?";
    $customer_stmt = $conn->prepare($customer_query);
    $customer_stmt->execute([$customer_id]);
    $customer_details = $customer_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer_details) {
        throw new Exception("Customer not found with ID: $customer_id");
    }
    
    $customer_name = $customer_details['customer_name'];
    $account_id = $customer_details['account_id'];
    
    file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - Found customer: $customer_name with account_id: $account_id\n", FILE_APPEND);
    
    // Check if customer has associated transactions
    $check_query = "SELECT 
                    (SELECT COUNT(*) FROM sales_orders WHERE customer_id = ?) as so_count,
                    (SELECT COUNT(*) FROM sales_invoices WHERE customer_id = ?) as invoice_count,
                    (SELECT COUNT(*) FROM sales_receipts WHERE customer_id = ?) as receipt_count,
                    (SELECT COUNT(*) FROM sales_returns WHERE customer_id = ?) as return_count,
                    (SELECT COUNT(*) FROM financial_transactions WHERE related_to = 'customer' AND related_id = ?) as transaction_count";
    
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->execute([$customer_id, $customer_id, $customer_id, $customer_id, $customer_id]);
    $counts = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log the transaction counts
    file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - Transaction counts: " . print_r($counts, true) . "\n", FILE_APPEND);
    
    $has_transactions = ($counts['so_count'] > 0 || $counts['invoice_count'] > 0 || 
                         $counts['receipt_count'] > 0 || $counts['return_count'] > 0 || 
                         $counts['transaction_count'] > 0);
    
    if ($has_transactions) {
        // Cannot delete - has transactions
        file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - Cannot delete customer: Has transactions\n", FILE_APPEND);
        
        $_SESSION['error'] = "Cannot delete customer '$customer_name' because it has associated transactions. You may deactivate it instead.";
        $conn->commit(); // Commit the transaction since we're just reading data
        header("Location: ../customers.php");
        exit();
    }
    
    // No transactions found, proceed with hard delete
    file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - No transactions found, proceeding with hard delete\n", FILE_APPEND);
    
    // Delete customer contacts if they exist
    $delete_contacts = "DELETE FROM customer_contacts WHERE customer_id = ?";
    $contacts_stmt = $conn->prepare($delete_contacts);
    $contacts_stmt->execute([$customer_id]);
    $contacts_deleted = $contacts_stmt->rowCount();
    file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - Deleted $contacts_deleted customer contacts\n", FILE_APPEND);
    
    // Delete customer
    $delete_customer = "DELETE FROM customers WHERE id = ?";
    $customer_stmt = $conn->prepare($delete_customer);
    $customer_stmt->execute([$customer_id]);
    $customer_deleted = $customer_stmt->rowCount();
    file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - Deleted customer: $customer_deleted row(s)\n", FILE_APPEND);
    
    if ($customer_deleted == 0) {
        throw new Exception("Failed to delete customer. No rows affected.");
    }
    
    // Delete the customer's ledger account if it exists
    if (!empty($account_id)) {
        // Check if this account has any transactions
        $check_account_query = "SELECT COUNT(*) as count FROM financial_transactions WHERE account_id = ?";
        $check_account_stmt = $conn->prepare($check_account_query);
        $check_account_stmt->execute([$account_id]);
        $account_has_transactions = $check_account_stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        
        if ($account_has_transactions) {
            file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - Cannot delete ledger account: Has financial transactions\n", FILE_APPEND);
        } else {
            // Delete the ledger account
            $delete_account = "DELETE FROM chart_of_accounts WHERE id = ?";
            $account_stmt = $conn->prepare($delete_account);
            $account_stmt->execute([$account_id]);
            $account_deleted = $account_stmt->rowCount();
            file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - Deleted ledger account: $account_deleted row(s)\n", FILE_APPEND);
        }
    }
    
    // Log activity
    $log_query = "INSERT INTO activity_logs (user_id, action, module, record_id, description, created_at) 
                  VALUES (?, ?, 'sales', ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->execute([$user_id, 'delete', $customer_id, "Deleted customer: $customer_name"]);
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success'] = "Customer '$customer_name' has been deleted successfully.";
    file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - Delete operation completed successfully\n", FILE_APPEND);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    $error_message = "Error deleting customer: " . $e->getMessage();
    file_put_contents('../customer_debug.log', date('Y-m-d H:i:s') . " - ERROR: $error_message\n", FILE_APPEND);
    $_SESSION['error'] = $error_message;
}

// Redirect back to customers page
header("Location: ../customers.php");
exit();
?>
