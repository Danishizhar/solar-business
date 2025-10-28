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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['purchase_delete'])) {
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

// Check if supplier ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Supplier ID is required.";
    header("Location: ../suppliers.php");
    exit();
}

$supplier_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Log the delete request
file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Delete request for supplier ID: $supplier_id\n", FILE_APPEND);

try {
    // Begin transaction
    $conn->beginTransaction();
    
    // Get supplier details including account_id
    $supplier_query = "SELECT supplier_name, account_id FROM suppliers WHERE id = ?";
    $supplier_stmt = $conn->prepare($supplier_query);
    $supplier_stmt->execute([$supplier_id]);
    $supplier_details = $supplier_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier_details) {
        throw new Exception("Supplier not found with ID: $supplier_id");
    }
    
    $supplier_name = $supplier_details['supplier_name'];
    $account_id = $supplier_details['account_id'];
    
    file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Found supplier: $supplier_name with account_id: $account_id\n", FILE_APPEND);
    
    // Check if supplier has associated transactions in all relevant tables
    $check_query = "SELECT 
                    (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?) as po_count,
                    (SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?) as invoice_count,
                    (SELECT COUNT(*) FROM purchase_returns WHERE supplier_id = ?) as return_count,
                    (SELECT COUNT(*) FROM supplier_refunds WHERE supplier_id = ?) as refund_count,
                    (SELECT COUNT(*) FROM goods_receiving_notes grn 
                     JOIN purchase_orders po ON grn.purchase_order_id = po.id 
                     WHERE po.supplier_id = ?) as grn_count,
                    (SELECT COUNT(*) FROM purchase_payments pp 
                     JOIN purchase_invoices pi ON pp.invoice_id = pi.id 
                     WHERE pi.supplier_id = ?) as payment_count";
    
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->execute([$supplier_id, $supplier_id, $supplier_id, $supplier_id, $supplier_id, $supplier_id]);
    $counts = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log the transaction counts
    file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Transaction counts: " . print_r($counts, true) . "\n", FILE_APPEND);
    
    $has_transactions = ($counts['po_count'] > 0 || $counts['invoice_count'] > 0 || 
                         $counts['return_count'] > 0 || $counts['refund_count'] > 0 ||
                         $counts['grn_count'] > 0 || $counts['payment_count'] > 0);
    
    if ($has_transactions) {
        // Cannot delete - has transactions
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Cannot delete supplier: Has transactions\n", FILE_APPEND);
        
        // Build detailed error message
        $transaction_details = [];
        if ($counts['po_count'] > 0) $transaction_details[] = "{$counts['po_count']} Purchase Orders";
        if ($counts['invoice_count'] > 0) $transaction_details[] = "{$counts['invoice_count']} Purchase Invoices";
        if ($counts['return_count'] > 0) $transaction_details[] = "{$counts['return_count']} Purchase Returns";
        if ($counts['refund_count'] > 0) $transaction_details[] = "{$counts['refund_count']} Supplier Refunds";
        if ($counts['grn_count'] > 0) $transaction_details[] = "{$counts['grn_count']} Goods Receiving Notes";
        if ($counts['payment_count'] > 0) $transaction_details[] = "{$counts['payment_count']} Purchase Payments";
        
        $details_text = implode(', ', $transaction_details);
        
        $_SESSION['error'] = "Cannot delete supplier '$supplier_name' because it has associated transactions: $details_text. You may deactivate the supplier instead.";
        $conn->commit(); // Commit the transaction since we're just reading data
        header("Location: ../suppliers.php");
        exit();
    }
    
    // No transactions found, proceed with hard delete
    file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - No transactions found, proceeding with hard delete\n", FILE_APPEND);
    
    // Delete supplier contacts if they exist
    $delete_contacts = "DELETE FROM supplier_contacts WHERE supplier_id = ?";
    $contacts_stmt = $conn->prepare($delete_contacts);
    $contacts_stmt->execute([$supplier_id]);
    $contacts_deleted = $contacts_stmt->rowCount();
    file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Deleted $contacts_deleted supplier contacts\n", FILE_APPEND);
    
    // Delete supplier
    $delete_supplier = "DELETE FROM suppliers WHERE id = ?";
    $supplier_stmt = $conn->prepare($delete_supplier);
    $supplier_stmt->execute([$supplier_id]);
    $supplier_deleted = $supplier_stmt->rowCount();
    file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Deleted supplier: $supplier_deleted row(s)\n", FILE_APPEND);
    
    if ($supplier_deleted == 0) {
        throw new Exception("Failed to delete supplier. No rows affected.");
    }
    
    // Delete the supplier's ledger account if it exists
    if (!empty($account_id)) {
        // Since we don't have financial_transactions table, we'll just delete the ledger account
        $delete_account = "DELETE FROM chart_of_accounts WHERE id = ?";
        $account_stmt = $conn->prepare($delete_account);
        $account_stmt->execute([$account_id]);
        $account_deleted = $account_stmt->rowCount();
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Deleted ledger account: $account_deleted row(s)\n", FILE_APPEND);
    }
    
    // Log activity
    $log_query = "INSERT INTO activity_logs (user_id, action, module, record_id, description, created_at) 
                  VALUES (?, ?, 'purchase', ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->execute([$user_id, 'delete', $supplier_id, "Deleted supplier: $supplier_name"]);
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success'] = "Supplier '$supplier_name' has been deleted successfully.";
    file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Delete operation completed successfully\n", FILE_APPEND);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    $error_message = "Error deleting supplier: " . $e->getMessage();
    file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - ERROR: $error_message\n", FILE_APPEND);
    $_SESSION['error'] = $error_message;
}

// Redirect back to suppliers page
header("Location: ../suppliers.php");
exit();
?>
