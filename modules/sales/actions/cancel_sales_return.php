<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to perform this action']);
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && (!isset($_SESSION['permissions']['sales_returns']) || !in_array('cancel', $_SESSION['permissions']['sales_returns']))) {
    echo json_encode(['success' => false, 'message' => "You don't have permission to cancel sales returns"]);
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Check if id is provided
if (!isset($_POST['id']) || empty($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid return ID']);
    exit();
}

$return_id = $_POST['id'];

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Get return details
    $return_query = "SELECT * FROM sales_returns WHERE id = :return_id AND status = 'approved'";
    $return_stmt = $conn->prepare($return_query);
    $return_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $return_stmt->execute();
    $return = $return_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$return) {
        throw new Exception('Sales return not found or not in approved status');
    }
    
    // Check if there's a credit note and if it's been used (partially or fully)
    $credit_note_query = "SELECT * FROM credit_notes WHERE return_id = :return_id";
    $credit_note_stmt = $conn->prepare($credit_note_query);
    $credit_note_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $credit_note_stmt->execute();
    $credit_note = $credit_note_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($credit_note) {
        // Check if credit note has been used (partially or fully)
        if ($credit_note['status'] === 'partially_used' || $credit_note['status'] === 'used') {
            throw new Exception('Cannot cancel this return because the associated credit note has been used for customer refunds. Credit Note ID: ' . $credit_note['id']);
        }
        
        // Additional check: if remaining_amount is less than original amount, it means it's been used
        if ($credit_note['remaining_amount'] !== null && $credit_note['remaining_amount'] < $credit_note['amount']) {
            throw new Exception('Cannot cancel this return because the associated credit note has been partially used for customer refunds. Credit Note ID: ' . $credit_note['id']);
        }
    }
    
    // 1. Reverse inventory transactions
    reverseReturnInventoryTransactions($conn, $return_id, $return);
    
    // 2. Cancel credit note if exists
    if ($credit_note) {
        $update_cn_query = "UPDATE credit_notes SET status = 'cancelled', updated_by = :updated_by, updated_at = NOW() WHERE id = :id";
        $update_cn_stmt = $conn->prepare($update_cn_query);
        $update_cn_stmt->bindParam(':id', $credit_note['id'], PDO::PARAM_INT);
        $update_cn_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_cn_stmt->execute();
    }
    
    // 3. Find and mark journal entries as rejected
    // Use the return number as the reference number to find related journal entries
    $je_query = "UPDATE journal_entries 
                SET status = 'rejected', 
                    updated_by = :updated_by, 
                    updated_at = NOW() 
                WHERE reference_number = :reference_number 
                AND entry_type = 'sales' 
                AND description LIKE :description";
    $je_stmt = $conn->prepare($je_query);
    $je_stmt->bindParam(':reference_number', $return['return_number'], PDO::PARAM_STR);
    $description_pattern = 'Sales return: ' . $return['return_number'] . '%';
    $je_stmt->bindParam(':description', $description_pattern, PDO::PARAM_STR);
    $je_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $je_stmt->execute();
    
    // 4. Update return status
    $update_query = "UPDATE sales_returns 
                    SET status = 'cancelled', updated_by = :updated_by, updated_at = NOW() 
                    WHERE id = :return_id";
    
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $update_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $update_stmt->execute();
    
    // 5. Restore invoice status and balance amount
    // First, check if there are any other active returns for this invoice
    $other_returns_query = "SELECT 
                        COUNT(*) as return_count,
                        COALESCE(SUM(total_amount), 0) as total_returned
                        FROM sales_returns 
                        WHERE invoice_id = :invoice_id 
                        AND status = 'approved' 
                        AND id != :return_id";

    $other_returns_stmt = $conn->prepare($other_returns_query);
    $other_returns_stmt->bindParam(':invoice_id', $return['invoice_id'], PDO::PARAM_INT);
    $other_returns_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $other_returns_stmt->execute();
    $other_returns = $other_returns_stmt->fetch(PDO::FETCH_ASSOC);

    // Get the original invoice details
    $invoice_query = "SELECT total_amount, paid_amount, balance_amount FROM sales_invoices WHERE id = :invoice_id";
    $invoice_stmt = $conn->prepare($invoice_query);
    $invoice_stmt->bindParam(':invoice_id', $return['invoice_id'], PDO::PARAM_INT);
    $invoice_stmt->execute();
    $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new Exception('Invoice data not found');
    }
    
    // Calculate the correct balance amount
    // The balance should be: original total - paid amount - sum of active returns (excluding this one)
    $new_balance = $invoice['total_amount'] - $invoice['paid_amount'] - $other_returns['total_returned'];
    
    // Ensure balance is not negative
    if ($new_balance < 0) {
        $new_balance = 0;
    }

    // Determine the appropriate invoice status based on other returns
    $invoice_status = 'approved'; // Default status
    if ($other_returns['return_count'] > 0) {
        // There are other active returns
        $is_full_return = ($other_returns['total_returned'] >= $invoice['total_amount']);
        $invoice_status = $is_full_return ? 'returned' : 'partially_returned';
    }

    // Payment status update code removed as requested

    // Update the invoice
    $update_invoice_query = "UPDATE sales_invoices 
                        SET status = :status, 
                            balance_amount = :balance_amount,
                            updated_by = :updated_by, 
                            updated_at = NOW() 
                        WHERE id = :invoice_id";

    $update_invoice_stmt = $conn->prepare($update_invoice_query);
    $update_invoice_stmt->bindParam(':status', $invoice_status, PDO::PARAM_STR);
    $update_invoice_stmt->bindParam(':balance_amount', $new_balance, PDO::PARAM_STR);
    $update_invoice_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $update_invoice_stmt->bindParam(':invoice_id', $return['invoice_id'], PDO::PARAM_INT);
    $update_invoice_stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Sales return cancelled successfully']);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    error_log('Error in cancel_sales_return.php: ' . $e->getMessage());
}

/**
 * Reverse inventory transactions for a sales return
 * 
 * @param PDO $conn Database connection
 * @param int $return_id Return ID
 * @param array $return Return data
 * @return void
 */
function reverseReturnInventoryTransactions($conn, $return_id, $return) {
    // Make sure we have a location_id
    if (empty($return['location_id'])) {
        throw new Exception('Location ID not found in return data');
    }
    
    $location_id = $return['location_id'];
    $customer_id = $return['customer_id'];
    
    // Get return items with UOM information
    $items_query = "SELECT pri.*, pii.uom_id,
                   p.sales_uom_id, p.base_uom_id, u.uom_name, u.uom_symbol
                   FROM sales_return_items pri
                   JOIN sales_invoice_items pii ON pri.invoice_item_id = pii.id
                   JOIN products p ON pri.product_id = p.id
                   LEFT JOIN uom u ON COALESCE(pii.uom_id, p.sales_uom_id, p.base_uom_id, 1) = u.id
                   WHERE pri.return_id = :return_id";
    
    $items_stmt = $conn->prepare($items_query);
    $items_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $items_stmt->execute();
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($items) == 0) {
        throw new Exception('No items found for this return');
    }
    
    // We already have the customer_id from the return data
    // No need to query it again
    
    // For each item, restore inventory stock
    foreach ($items as $item) {
        // Restore inventory stock (add back to warehouse)
        restoreInventoryStock($conn, $item['product_id'], $location_id, $item['quantity'], $item['batch_number']);
    }
    
    // Mark inventory transactions as cancelled
    $trans_query = "UPDATE inventory_transactions 
                   SET status = 'cancelled', updated_by = :updated_by, updated_at = NOW() 
                   WHERE reference_type = 'sales_return' AND reference_id = :reference_id";
    
    $trans_stmt = $conn->prepare($trans_query);
    $trans_stmt->bindParam(':reference_id', $return_id, PDO::PARAM_INT);
    $trans_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $trans_stmt->execute();
}

/**
 * Restore inventory stock for a product
 * 
 * @param PDO $conn Database connection
 * @param int $product_id Product ID
 * @param int $location_id Location ID
 * @param float $quantity Quantity to restore
 * @param string|null $batch_number Batch number
 * @return void
 */
function restoreInventoryStock($conn, $product_id, $location_id, $quantity, $batch_number = null) {
    // Check if batch tracking is enabled for this product
    $batch_query = "SELECT track_batches FROM products WHERE id = :product_id";
    $batch_stmt = $conn->prepare($batch_query);
    $batch_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $batch_stmt->execute();
    $product = $batch_stmt->fetch(PDO::FETCH_ASSOC);
    
    $batch_tracking = $product['track_batches'] ?? 0;
    
    if ($batch_tracking && !empty($batch_number)) {
        // Check if batch exists in inventory
        $check_query = "SELECT COUNT(*) as count FROM inventory_stock 
                       WHERE product_id = :product_id 
                       AND location_id = :location_id 
                       AND batch_number = :batch_number";
        
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $check_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
        $check_stmt->bindParam(':batch_number', $batch_number, PDO::PARAM_STR);
        $check_stmt->execute();
        $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($check_result['count'] > 0) {
            // Update existing batch
            $stock_query = "UPDATE inventory_stock 
                           SET quantity = quantity + :quantity 
                           WHERE product_id = :product_id 
                           AND location_id = :location_id 
                           AND batch_number = :batch_number";
            
            $stock_stmt = $conn->prepare($stock_query);
            $stock_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $stock_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
            $stock_stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
            $stock_stmt->bindParam(':batch_number', $batch_number, PDO::PARAM_STR);
            $stock_stmt->execute();
        } else {
            // Insert new batch record
            $stock_query = "INSERT INTO inventory_stock (product_id, location_id, quantity, batch_number) 
                           VALUES (:product_id, :location_id, :quantity, :batch_number)";
            
            $stock_stmt = $conn->prepare($stock_query);
            $stock_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $stock_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
            $stock_stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
            $stock_stmt->bindParam(':batch_number', $batch_number, PDO::PARAM_STR);
            $stock_stmt->execute();
        }
    } else {
        // Check if product exists in inventory without batch
        $check_query = "SELECT COUNT(*) as count FROM inventory_stock 
                       WHERE product_id = :product_id 
                       AND location_id = :location_id 
                       AND (batch_number IS NULL OR batch_number = '')";
        
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $check_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
        $check_stmt->execute();
        $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($check_result['count'] > 0) {
            // Update existing stock
            $stock_query = "UPDATE inventory_stock 
                           SET quantity = quantity + :quantity 
                           WHERE product_id = :product_id 
                           AND location_id = :location_id 
                           AND (batch_number IS NULL OR batch_number = '')";
            
            $stock_stmt = $conn->prepare($stock_query);
            $stock_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $stock_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
            $stock_stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
            $stock_stmt->execute();
        } else {
            // Insert new stock record
            $stock_query = "INSERT INTO inventory_stock (product_id, location_id, quantity) 
                           VALUES (:product_id, :location_id, :quantity)";
            
            $stock_stmt = $conn->prepare($stock_query);
            $stock_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $stock_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
            $stock_stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
            $stock_stmt->execute();
        }
    }
}
?>
