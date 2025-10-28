<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to log debug information
function debug_log($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[PURCHASE_RETURN_DEBUG][$timestamp] $message";
    
    if ($data !== null) {
        $log_message .= ": " . print_r($data, true);
    }
    
    error_log($log_message);
}

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
if ($_SESSION['role_id'] != 1 && (!isset($_SESSION['permissions']['purchase_returns']) || !in_array('approve', $_SESSION['permissions']['purchase_returns']))) {
    echo json_encode(['success' => false, 'message' => "You don't have permission to approve purchase returns"]);
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
    debug_log("Starting approval process for return ID", $return_id);
    
    // Start transaction
    $conn->beginTransaction();
    debug_log("Transaction started");
    
    // Get return details
    $return_query = "SELECT pr.*, pi.invoice_number,
                    (SELECT COALESCE(SUM(pp.amount), 0) 
                     FROM purchase_payments pp 
                     WHERE pp.invoice_id = pr.invoice_id 
                     AND pp.status = 'approved') as paid_amount,
                    pi.total_amount as invoice_total
                    FROM purchase_returns pr
                    JOIN purchase_invoices pi ON pr.invoice_id = pi.id
                    WHERE pr.id = :return_id AND pr.status = 'draft'";
    
    debug_log("Return query", $return_query);
    $return_stmt = $conn->prepare($return_query);
    $return_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    debug_log("Executing return query with ID", $return_id);
    $return_stmt->execute();
    $return = $return_stmt->fetch(PDO::FETCH_ASSOC);
    debug_log("Return data fetched", $return);
    
    if (!$return) {
        throw new Exception('Purchase return not found or already processed');
    }
    
    // Get return items with UOM information
    $items_query = "SELECT pri.*, pii.batch_number, pii.expiry_date, pii.uom_id,
                   p.purchase_uom_id, p.base_uom_id, u.uom_name, u.uom_symbol
                   FROM purchase_return_items pri
                   JOIN purchase_invoice_items pii ON pri.invoice_item_id = pii.id
                   JOIN products p ON pri.product_id = p.id
                   LEFT JOIN uom u ON COALESCE(pii.uom_id, p.purchase_uom_id, p.base_uom_id, 1) = u.id
                   WHERE pri.return_id = :return_id";
    
    debug_log("Items query", $items_query);
    $items_stmt = $conn->prepare($items_query);
    $items_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    debug_log("Executing items query with return_id", $return_id);
    $items_stmt->execute();
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    debug_log("Items found", count($items));
    
    if (count($items) == 0) {
        throw new Exception('No items found for this return');
    }
    
    // 1. Create inventory transactions
    debug_log("Starting inventory transactions creation");
    createReturnInventoryTransactions($conn, $return, $items);
    debug_log("Inventory transactions created successfully");
    
    // 2. Create accounting entries
    $is_paid = ($return['paid_amount'] > 0);
    debug_log("Starting accounting entries creation", ["is_paid" => $is_paid]);
    createReturnAccountingEntries($conn, $return, $is_paid);
    debug_log("Accounting entries created successfully");
    
    // 3. Update return status
    $update_query = "UPDATE purchase_returns 
                    SET status = 'approved', updated_by = :updated_by, updated_at = NOW() 
                    WHERE id = :return_id";
    
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
    $update_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $update_stmt->execute();
    
    // 4. Check if this is a full or partial return
    debug_log("Checking if this is a full or partial return");
    $check_query = "SELECT 
                    pi.total_amount as invoice_total,
                    (SELECT COALESCE(SUM(pri.total_amount), 0) 
                     FROM purchase_returns pr 
                     JOIN purchase_return_items pri ON pr.id = pri.return_id 
                     WHERE pr.invoice_id = :subquery_invoice_id AND pr.status = 'approved' AND pr.id != :current_return_id) as total_returned
                    FROM purchase_invoices pi
                    WHERE pi.id = :invoice_id";
    
    debug_log("Check query", $check_query);
    $check_stmt = $conn->prepare($check_query);
    debug_log("Binding parameters for check query", ["invoice_id" => $return['invoice_id'], "return_id" => $return_id]);
    $check_stmt->bindParam(':invoice_id', $return['invoice_id'], PDO::PARAM_INT);
    $check_stmt->bindParam(':subquery_invoice_id', $return['invoice_id'], PDO::PARAM_INT);
    $check_stmt->bindParam(':current_return_id', $return_id, PDO::PARAM_INT);
    debug_log("Executing check query");
    $check_stmt->execute();
    $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    debug_log("Check result", $check_result);
    
    // Determine if this is a full or partial return
    // Include the current return amount in the calculation
    $total_returned_with_current = $check_result['total_returned'] + $return['total_amount'];
    $is_full_return = ($total_returned_with_current >= $check_result['invoice_total']);
    $invoice_status = $is_full_return ? 'returned' : 'partially_returned';
    
    debug_log("Return type determination", [
        "invoice_total" => $check_result['invoice_total'],
        "previously_returned" => $check_result['total_returned'],
        "current_return" => $return['total_amount'],
        "total_returned_with_current" => $total_returned_with_current,
        "is_full_return" => $is_full_return,
        "invoice_status" => $invoice_status
    ]);
    
    // 5. Update invoice status and balance amount
    // Get the current balance amount and paid amount of the invoice
    $get_invoice_query = "SELECT balance_amount, total_amount, paid_amount FROM purchase_invoices WHERE id = :invoice_id";
    $get_invoice_stmt = $conn->prepare($get_invoice_query);
    $get_invoice_stmt->bindParam(':invoice_id', $return['invoice_id'], PDO::PARAM_INT);
    $get_invoice_stmt->execute();
    $invoice_data = $get_invoice_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice_data) {
        throw new Exception('Invoice data not found');
    }
    
    // Calculate new balance amount by subtracting the return amount
    $current_balance = $invoice_data['balance_amount'];
    $return_amount = $return['total_amount'];
    $new_balance = max(0, $current_balance - $return_amount); // Ensure balance doesn't go negative
    
    debug_log("Updating invoice balance", [
        "invoice_id" => $return['invoice_id'],
        "current_balance" => $current_balance,
        "return_amount" => $return_amount,
        "new_balance" => $new_balance
    ]);
    
    $invoice_update_query = "UPDATE purchase_invoices 
                           SET status = :status, 
                               balance_amount = :balance_amount,
                               updated_by = :updated_by, 
                               updated_at = NOW() 
                           WHERE id = :invoice_id";
    
    $invoice_update_stmt = $conn->prepare($invoice_update_query);
    $invoice_update_stmt->bindParam(':status', $invoice_status, PDO::PARAM_STR);
    $invoice_update_stmt->bindParam(':balance_amount', $new_balance, PDO::PARAM_STR);
    $invoice_update_stmt->bindParam(':invoice_id', $return['invoice_id'], PDO::PARAM_INT);
    $invoice_update_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $invoice_update_stmt->execute();
    
    debug_log("Invoice updated", [
        "invoice_id" => $return['invoice_id'],
        "status" => $invoice_status,
        "balance_amount" => $new_balance
    ]);
    
    // 6. Create credit note only if the paid amount exceeds what's needed after the return
    // Calculate if there's an overpayment after the return
    $total_after_return = $invoice_data['total_amount'] - $return_amount;
    $overpayment = $invoice_data['paid_amount'] - $total_after_return;
    
    debug_log("Checking if credit note is needed", [
        "invoice_id" => $return['invoice_id'],
        "return_id" => $return_id,
        "paid_amount" => $invoice_data['paid_amount'],
        "return_amount" => $return_amount,
        "total_after_return" => $total_after_return,
        "overpayment" => $overpayment
    ]);
    
    // Only create credit note if there's an overpayment (paid more than needed after return)
    if ($overpayment > 0) {
        // For full returns, the journal entry should reflect the full invoice amount
        // But the credit note should only be for the overpaid amount
        $is_full_return = $is_full_return || ($return_amount >= $invoice_data['total_amount']);
        
        // Credit note amount is the overpayment amount
        $credit_note_amount = $overpayment;
        
        debug_log("Creating credit note for overpaid invoice", [
            "invoice_id" => $return['invoice_id'],
            "return_id" => $return_id,
            "paid_amount" => $invoice_data['paid_amount'],
            "return_amount" => $return_amount,
            "is_full_return" => $is_full_return,
            "overpayment" => $overpayment,
            "credit_note_amount" => $credit_note_amount
        ]);
        
        // Generate credit note number
        $note_number = 'CN-' . date('Ymd') . '-' . $return_id;
        
        // Insert credit note
        $credit_note_query = "INSERT INTO credit_notes 
            (note_number, supplier_id, return_id, amount, status, created_by, created_at) 
            VALUES (:note_number, :supplier_id, :return_id, :amount, 'active', :created_by, NOW())";
        
        $credit_note_stmt = $conn->prepare($credit_note_query);
        $credit_note_stmt->bindParam(':note_number', $note_number, PDO::PARAM_STR);
        $credit_note_stmt->bindParam(':supplier_id', $return['supplier_id'], PDO::PARAM_INT);
        $credit_note_stmt->bindParam(':return_id', $return_id, PDO::PARAM_INT);
        $credit_note_stmt->bindParam(':amount', $credit_note_amount, PDO::PARAM_STR);
        $credit_note_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        
        try {
            $credit_note_stmt->execute();
            $credit_note_id = $conn->lastInsertId();
            debug_log("Credit note created", ["credit_note_id" => $credit_note_id]);
        } catch (PDOException $e) {
            debug_log("Error creating credit note", ["error" => $e->getMessage()]);
            // Don't throw exception here, just log the error
            // We don't want to roll back the entire transaction if just the credit note fails
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Purchase return approved successfully']);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
        debug_log("Transaction rolled back due to error");
    }
    $error_message = $e->getMessage();
    $error_trace = $e->getTraceAsString();
    debug_log("ERROR: " . $error_message);
    debug_log("ERROR TRACE: " . $error_trace);
    echo json_encode(['success' => false, 'message' => $error_message]);
    error_log('Error in approve_purchase_return.php: ' . $error_message);
}

/**
 * Create inventory transactions for purchase return
 * 
 * @param PDO $conn Database connection
 * @param array $return Return details
 * @param array $items Return items
 * @return void
 */
function createReturnInventoryTransactions($conn, $return, $items) {
    // Generate transaction number
    $trans_number = "PR-" . $return['return_number'];
    
    // Use transaction type ID 2 for inventory return/reversal (same as in cancel_purchase_invoice.php)
    $transaction_type_id = 2;
    $reference_type = 'purchase_return';
    
    // Insert inventory transaction header
    $trans_query = "INSERT INTO inventory_transactions (
                  transaction_number, transaction_date, transaction_type_id,
                  reference_type, reference_id, notes, created_by, created_at
              ) VALUES (
                  :transaction_number, :transaction_date, :transaction_type_id,
                  :reference_type, :reference_id, :notes, :created_by, NOW()
              )";
    
    $trans_stmt = $conn->prepare($trans_query);
    $trans_stmt->bindParam(':transaction_number', $trans_number, PDO::PARAM_STR);
    $trans_stmt->bindParam(':transaction_date', $return['return_date'], PDO::PARAM_STR);
    $trans_stmt->bindParam(':transaction_type_id', $transaction_type_id, PDO::PARAM_INT);
    $trans_stmt->bindParam(':reference_type', $reference_type, PDO::PARAM_STR);
    $trans_stmt->bindParam(':reference_id', $return['id'], PDO::PARAM_INT);
    $trans_stmt->bindParam(':notes', $return['notes'], PDO::PARAM_STR);
    $trans_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $trans_stmt->execute();
    
    $transaction_id = $conn->lastInsertId();
    
    // For each item, create inventory transaction detail and update stock
    foreach ($items as $item) {
        // For purchase returns, we don't need a supplier location
        // Just use null for the to_location_id as it's going out of the system
        $supplier_location_id = null;
        
        // For purchase returns, we swap the locations compared to purchase
        // From: warehouse location, To: supplier location
        $from_location_id = $return['location_id'];
        $to_location_id = $supplier_location_id;
        
        // Get appropriate UOM ID using fallback mechanism
        $uom_id = get_uom_id_with_fallback($conn, $item['product_id'], $item['uom_id']);
        
        // Insert inventory transaction detail
        $detail_query = "INSERT INTO inventory_transaction_details (
                        transaction_id, product_id, quantity, uom_id,
                        from_location_id, to_location_id, batch_number, expiry_date
                    ) VALUES (
                        :transaction_id, :product_id, :quantity, :uom_id,
                        :from_location_id, :to_location_id, :batch_number, :expiry_date
                    )";
        
        $detail_stmt = $conn->prepare($detail_query);
        $detail_stmt->bindParam(':transaction_id', $transaction_id, PDO::PARAM_INT);
        $detail_stmt->bindParam(':product_id', $item['product_id'], PDO::PARAM_INT);
        $detail_stmt->bindParam(':quantity', $item['quantity'], PDO::PARAM_STR);
        $detail_stmt->bindParam(':uom_id', $uom_id, PDO::PARAM_INT);
        $detail_stmt->bindParam(':from_location_id', $from_location_id, PDO::PARAM_INT);
        
        if ($to_location_id) {
            $detail_stmt->bindParam(':to_location_id', $to_location_id, PDO::PARAM_INT);
        } else {
            $detail_stmt->bindValue(':to_location_id', null, PDO::PARAM_NULL);
        }
        
        // Handle batch number and expiry date
        if (!empty($item['batch_number'])) {
            $detail_stmt->bindParam(':batch_number', $item['batch_number'], PDO::PARAM_STR);
        } else {
            $detail_stmt->bindValue(':batch_number', null, PDO::PARAM_NULL);
        }
        
        if (!empty($item['expiry_date'])) {
            $detail_stmt->bindParam(':expiry_date', $item['expiry_date'], PDO::PARAM_STR);
        } else {
            $detail_stmt->bindValue(':expiry_date', null, PDO::PARAM_NULL);
        }
        
        $detail_stmt->execute();
        
        // Update inventory stock (reduce stock in warehouse)
        reverseInventoryStock($conn, $item['product_id'], $from_location_id, $item['quantity'], $item['batch_number']);
    }
}

/**
 * Create accounting entries for purchase return
 * 
 * @param PDO $conn Database connection
 * @param array $return Return details
 * @param bool $is_paid Whether the invoice was paid
 * @return void
 */
function createReturnAccountingEntries($conn, $return, $is_paid) {
    debug_log("Starting createReturnAccountingEntries", ["return_id" => $return['id'], "is_paid" => $is_paid]);
    
    // Get active financial year
    $fy_query = "SELECT id, year_code FROM financial_years WHERE status = 1 AND is_closed = 0 LIMIT 1";
    debug_log("Financial year query", $fy_query);
    $fy_stmt = $conn->prepare($fy_query);
    $fy_stmt->execute();
    $fy = $fy_stmt->fetch(PDO::FETCH_ASSOC);
    debug_log("Financial year data", $fy);
    
    if (!$fy) {
        throw new Exception("No active financial year found");
    }
    
    // Get required account codes
    $supplier_query = "SELECT account_id FROM suppliers WHERE id = :supplier_id";
    $supplier_stmt = $conn->prepare($supplier_query);
    $supplier_stmt->bindParam(':supplier_id', $return['supplier_id'], PDO::PARAM_INT);
    $supplier_stmt->execute();
    $supplier_data = $supplier_stmt->fetch(PDO::FETCH_ASSOC);
    debug_log("Supplier data", $supplier_data);
    
    if (!$supplier_data || empty($supplier_data['account_id'])) {
        throw new Exception("Supplier account ID not found. Please make sure the supplier has an associated account.");
    }
    
    // Make sure account_id is an integer
    $supplier_data['account_id'] = (int)$supplier_data['account_id'];
    
    // Get all relevant accounts from settings
    debug_log("Getting accounting settings");
    $settings_query = "SELECT 
        (SELECT setting_value FROM inventory_settings WHERE setting_key = 'inventory_asset_account_id' AND setting_group = 'accounting') as inventory_account_id,
        (SELECT setting_value FROM purchase_settings WHERE setting_key = 'purchase_account_id' AND setting_group = 'accounting') as purchase_account_id,
        (SELECT setting_value FROM purchase_settings WHERE setting_key = 'purchase_tax_account_id' AND setting_group = 'accounting') as purchase_tax_account_id,
        (SELECT setting_value FROM purchase_settings WHERE setting_key = 'purchase_discount_account_id' AND setting_group = 'accounting') as purchase_discount_account_id,
        (SELECT setting_value FROM purchase_settings WHERE setting_key = 'delivery_expense_account_id' AND setting_group = 'accounting') as delivery_charges_account_id";
    debug_log("Settings query", $settings_query);
    $settings_stmt = $conn->prepare($settings_query);
    $settings_stmt->execute();
    $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
    debug_log("Settings data", $settings);
    
    // Validate that we have the required account IDs
    if (empty($settings['inventory_account_id'])) {
        throw new Exception("Inventory asset account ID not configured in settings. Please configure it in the inventory module settings under the Accounting tab.");
    }
    
    // Make sure account IDs are integers
    $settings['inventory_account_id'] = (int)$settings['inventory_account_id'];
    $settings['purchase_account_id'] = !empty($settings['purchase_account_id']) ? (int)$settings['purchase_account_id'] : null;
    $settings['purchase_tax_account_id'] = !empty($settings['purchase_tax_account_id']) ? (int)$settings['purchase_tax_account_id'] : null;
    $settings['purchase_discount_account_id'] = !empty($settings['purchase_discount_account_id']) ? (int)$settings['purchase_discount_account_id'] : null;
    $settings['delivery_charges_account_id'] = !empty($settings['delivery_charges_account_id']) ? (int)$settings['delivery_charges_account_id'] : null;
    
    // Generate journal entry number
    $year_code = $fy['year_code'];
    $je_query = "SELECT MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)) as last_number 
                FROM journal_entries 
                WHERE entry_number LIKE :entry_pattern";
    $je_stmt = $conn->prepare($je_query);
    $entry_pattern = "JE-$year_code-%";
    $je_stmt->bindParam(':entry_pattern', $entry_pattern, PDO::PARAM_STR);
    $je_stmt->execute();
    $je_row = $je_stmt->fetch(PDO::FETCH_ASSOC);
    $next_number = str_pad(($je_row['last_number'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
    $entry_number = "JE-$year_code-$next_number";
    
    // Create journal entry description
    $description = "Purchase return: " . $return['return_number'];
    
    // Insert journal entry header with approved status
    $je_insert = "INSERT INTO journal_entries (
        entry_number, entry_date, description, 
        entry_type, reference_number, financial_year_id,
        status, created_by, created_at
    ) VALUES (
        :entry_number, :entry_date, :description,
        'purchase', :reference_number, :financial_year_id,
        'approved', :created_by, NOW()
    )";
    
    $je_insert_stmt = $conn->prepare($je_insert);
    $je_insert_stmt->bindParam(':entry_number', $entry_number, PDO::PARAM_STR);
    $je_insert_stmt->bindParam(':entry_date', $return['return_date'], PDO::PARAM_STR);
    $je_insert_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $je_insert_stmt->bindParam(':reference_number', $return['return_number'], PDO::PARAM_STR);
    $je_insert_stmt->bindParam(':financial_year_id', $fy['id'], PDO::PARAM_INT);
    $je_insert_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $je_insert_stmt->execute();
    debug_log("Journal entry header inserted successfully");
    
    $journal_entry_id = $conn->lastInsertId();
    debug_log("Journal entry ID", $journal_entry_id);
    
    // Get return details with tax, discount, and delivery charges
    $return_details_query = "SELECT 
                           subtotal, discount_amount, tax_amount, delivery_charges,
                           include_delivery_charges, return_type, total_amount
                           FROM purchase_returns 
                           WHERE id = :return_id";
    $return_details_stmt = $conn->prepare($return_details_query);
    $return_details_stmt->bindParam(':return_id', $return['id'], PDO::PARAM_INT);
    $return_details_stmt->execute();
    $return_details = $return_details_stmt->fetch(PDO::FETCH_ASSOC);
    debug_log("Return details", $return_details);
    
    // Get original invoice details for comparison
    $invoice_details_query = "SELECT 
                            subtotal, discount_amount, tax_amount, delivery_charges, total_amount,
                            (SELECT COALESCE(SUM(pr.total_amount), 0) 
                             FROM purchase_returns pr 
                             WHERE pr.invoice_id = pi.id AND pr.status = 'approved' AND pr.id != :return_id) as previously_returned_amount
                            FROM purchase_invoices pi 
                            WHERE pi.id = :invoice_id";
    $invoice_details_stmt = $conn->prepare($invoice_details_query);
    $invoice_details_stmt->bindParam(':invoice_id', $return['invoice_id'], PDO::PARAM_INT);
    $invoice_details_stmt->bindParam(':return_id', $return['id'], PDO::PARAM_INT);
    $invoice_details_stmt->execute();
    $invoice_details = $invoice_details_stmt->fetch(PDO::FETCH_ASSOC);
    debug_log("Original invoice details", $invoice_details);
    
    // Calculate if this is effectively a full return (this return + previous returns = invoice total)
    $total_returned = $invoice_details['previously_returned_amount'] + $return_details['total_amount'];
    $is_full_return = (abs($total_returned - $invoice_details['total_amount']) < 0.01);
    debug_log("Is full return calculation", [
        "total_returned" => $total_returned,
        "invoice_total" => $invoice_details['total_amount'],
        "is_full_return" => $is_full_return,
        "return_type" => $return_details['return_type']
    ]);
    
    // Override return_type if needed (for safety)
    if ($is_full_return && $return_details['return_type'] !== 'full') {
        debug_log("Overriding return_type to 'full' based on amounts");
        $return_details['return_type'] = 'full';
    }
    
    // Prepare journal entry details insert statement
    $jed_insert = "INSERT INTO journal_entry_details (
        journal_entry_id, account_id, description, debit_amount, credit_amount
    ) VALUES (
        :journal_entry_id, :account_id, :description, :debit_amount, :credit_amount
    )";
    
    // Calculate if there's an overpayment after the return
    $total_after_return = $invoice_details['total_amount'] - $total_returned;
    $overpayment = ($return['paid_amount'] ?? 0) - $total_after_return;
    
    // Journal entry amount should always reflect the full return for accounting purposes
    $journal_entry_amount = $return['total_amount'];
    
    debug_log("Journal entry calculation", [
        "paid_amount" => $return['paid_amount'] ?? 'Not set',
        "return_amount" => $return['total_amount'],
        "total_after_return" => $total_after_return,
        "overpayment" => $overpayment,
        "is_full_return" => $is_full_return,
        "journal_entry_amount" => $journal_entry_amount
    ]);
    
    // For paid or partially paid invoices, create credit note entries ONLY if there's an overpayment
    if ($is_paid && $overpayment > 0) {
        // Generate a credit note for the supplier
        $credit_note_number = generate_document_number($conn, 'credit_notes', 'note_number', 'CN');
        
        // Credit note amount is the overpayment amount
        $credit_note_amount = $overpayment;
        
        debug_log("Creating credit note", [
            "paid_amount" => $return['paid_amount'] ?? 'Not set',
            "return_amount" => $return['total_amount'],
            "total_after_return" => $total_after_return,
            "overpayment" => $overpayment,
            "credit_note_amount" => $credit_note_amount
        ]);
        
        // Insert credit note record
        $credit_note_insert = "INSERT INTO credit_notes (
            note_number, supplier_id, amount, return_id, status, 
            created_by, created_at
        ) VALUES (
            :note_number, :supplier_id, :amount, :return_id, 'active',
            :created_by, NOW()
        )";
        
        $cn_stmt = $conn->prepare($credit_note_insert);
        $cn_stmt->bindParam(':note_number', $credit_note_number, PDO::PARAM_STR);
        $cn_stmt->bindParam(':supplier_id', $return['supplier_id'], PDO::PARAM_INT);
        $cn_stmt->bindParam(':amount', $credit_note_amount, PDO::PARAM_STR);
        $cn_stmt->bindParam(':return_id', $return['id'], PDO::PARAM_INT);
        $cn_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $cn_stmt->execute();
        
        $credit_note_id = $conn->lastInsertId();
        
        debug_log("Credit note created successfully", ["id" => $credit_note_id, "number" => $credit_note_number]);
        $supplier_description = $description . ' - Credit Note #' . $credit_note_number;
    } else {
        // No credit note created, but we still need to create journal entries
        $supplier_description = $description . ' - Return Adjustment';
    }
    
    // 1. Debit Supplier Account (for the full return amount)
    // For accounting purposes, we debit the supplier for the full return amount
    // This includes both any credit note (for overpaid amount) and the reduction in liability (for unpaid amount)
    $jed_stmt = $conn->prepare($jed_insert);
    $jed_stmt->bindParam(':journal_entry_id', $journal_entry_id, PDO::PARAM_INT);
    $jed_stmt->bindParam(':account_id', $supplier_data['account_id'], PDO::PARAM_INT);
    $jed_stmt->bindValue(':description', $supplier_description, PDO::PARAM_STR);
    $jed_stmt->bindValue(':debit_amount', $journal_entry_amount, PDO::PARAM_STR);
    $jed_stmt->bindValue(':credit_amount', 0, PDO::PARAM_STR);
    $jed_stmt->execute();
    debug_log("Journal entry detail inserted successfully", [
        "debit_amount" => $journal_entry_amount,
        "credit_note_amount" => $overpayment > 0 ? $credit_note_amount : 0,
        "unpaid_amount" => $overpayment > 0 ? ($journal_entry_amount - $credit_note_amount) : $journal_entry_amount
    ]);
    
    // 2. Credit Inventory Account for the subtotal amount
    if (!empty($return_details['subtotal']) && $return_details['subtotal'] > 0) {
        $jed_stmt = $conn->prepare($jed_insert);
        $jed_stmt->bindParam(':journal_entry_id', $journal_entry_id, PDO::PARAM_INT);
        $jed_stmt->bindParam(':account_id', $settings['inventory_account_id'], PDO::PARAM_INT);
        $jed_stmt->bindValue(':description', $description . ' - Inventory', PDO::PARAM_STR);
        $jed_stmt->bindValue(':debit_amount', 0, PDO::PARAM_STR);
        $jed_stmt->bindValue(':credit_amount', $return_details['subtotal'], PDO::PARAM_STR);
        $jed_stmt->execute();
        debug_log("Journal entry detail inserted successfully");
    }
    
    // 3. Credit Tax Account if applicable
    if (!empty($settings['purchase_tax_account_id']) && !empty($return_details['tax_amount']) && $return_details['tax_amount'] > 0) {
        $jed_stmt = $conn->prepare($jed_insert);
        $jed_stmt->bindParam(':journal_entry_id', $journal_entry_id, PDO::PARAM_INT);
        $jed_stmt->bindParam(':account_id', $settings['purchase_tax_account_id'], PDO::PARAM_INT);
        $jed_stmt->bindValue(':description', $description . ' - Tax', PDO::PARAM_STR);
        $jed_stmt->bindValue(':debit_amount', 0, PDO::PARAM_STR);
        $jed_stmt->bindValue(':credit_amount', $return_details['tax_amount'], PDO::PARAM_STR);
        $jed_stmt->execute();
        debug_log("Journal entry detail inserted successfully");
    }
    
    // 4. Credit Discount Account if applicable
    if (!empty($settings['purchase_discount_account_id']) && !empty($return_details['discount_amount']) && $return_details['discount_amount'] > 0) {
        $jed_stmt = $conn->prepare($jed_insert);
        $jed_stmt->bindParam(':journal_entry_id', $journal_entry_id, PDO::PARAM_INT);
        $jed_stmt->bindParam(':account_id', $settings['purchase_discount_account_id'], PDO::PARAM_INT);
        $jed_stmt->bindValue(':description', $description . ' - Discount', PDO::PARAM_STR);
        $jed_stmt->bindValue(':debit_amount', $return_details['discount_amount'], PDO::PARAM_STR);
        $jed_stmt->bindValue(':credit_amount', 0, PDO::PARAM_STR);
        $jed_stmt->execute();
        debug_log("Journal entry detail inserted successfully");
    }
    
    // 5. Credit Delivery Charges Account if applicable and if include_delivery_charges flag is set
    // For full returns, always include delivery charges if they exist and flag is set
    // For partial returns, only include if explicitly requested via flag
    $should_include_delivery = false;
    
    if (!empty($settings['delivery_charges_account_id']) && 
        !empty($return_details['delivery_charges']) && 
        $return_details['delivery_charges'] > 0) {
        
        if ($return_details['include_delivery_charges'] == 1) {
            $should_include_delivery = true;
            debug_log("Including delivery charges because include_delivery_charges flag is set");
        } else if ($return_details['return_type'] == 'full' || $is_full_return) {
            // For full returns, we might want to include delivery charges automatically
            // But we'll respect the user's choice via the checkbox
            debug_log("This is a full return, but include_delivery_charges flag is not set, respecting user choice");
        }
    }
    
    if ($should_include_delivery) {
        $jed_stmt = $conn->prepare($jed_insert);
        $jed_stmt->bindParam(':journal_entry_id', $journal_entry_id, PDO::PARAM_INT);
        $jed_stmt->bindParam(':account_id', $settings['delivery_charges_account_id'], PDO::PARAM_INT);
        $jed_stmt->bindValue(':description', $description . ' - Delivery Charges', PDO::PARAM_STR);
        $jed_stmt->bindValue(':debit_amount', 0, PDO::PARAM_STR);
        $jed_stmt->bindValue(':credit_amount', $return_details['delivery_charges'], PDO::PARAM_STR);
        $jed_stmt->execute();
        debug_log("Journal entry detail inserted successfully - Delivery charges credited");
        debug_log("Delivery charges amount: " . $return_details['delivery_charges']);
    } else {
        debug_log("Skipping delivery charges journal entry - conditions not met");
        debug_log("Delivery charges account ID: " . ($settings['delivery_charges_account_id'] ?? 'not set'));
        debug_log("Delivery charges amount: " . ($return_details['delivery_charges'] ?? 0));
        debug_log("Include delivery charges flag: " . ($return_details['include_delivery_charges'] ?? 'not set'));
        debug_log("Return type: " . ($return_details['return_type'] ?? 'not set'));
        debug_log("Is full return: " . ($is_full_return ? 'Yes' : 'No'));
    }
    // We've already created a credit note for paid invoices with overpayment earlier in the function
    // No need to create another credit note here
    
    debug_log("Completed creating accounting entries for purchase return");
}

/**
 * Get appropriate UOM ID using fallback mechanism
 * 
 * @param PDO $conn Database connection
 * @param int $product_id Product ID
 * @param int|null $uom_id UOM ID if available
 * @return int UOM ID to use
 */
function get_uom_id_with_fallback($conn, $product_id, $uom_id = null) {
    debug_log("Getting UOM ID with fallback", ["product_id" => $product_id, "provided_uom_id" => $uom_id]);
    
    if (!empty($uom_id)) {
        debug_log("Using provided UOM ID", $uom_id);
        return $uom_id;
    }
    
    // Try to get purchase UOM ID from product
    $query = "SELECT purchase_uom_id, base_uom_id FROM products WHERE id = :product_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stmt->execute();
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    debug_log("Product UOM data", $product);
    
    if ($product && !empty($product['purchase_uom_id'])) {
        debug_log("Using purchase_uom_id", $product['purchase_uom_id']);
        return $product['purchase_uom_id'];
    } else if ($product && !empty($product['base_uom_id'])) {
        debug_log("Using base_uom_id", $product['base_uom_id']);
        return $product['base_uom_id'];
    }
    
    // Default to UOM ID 1 if nothing else is available
    debug_log("Using default UOM ID", 1);
    return 1;
}

/**
 * Generate a unique document number for various document types
 * 
 * @param PDO $conn Database connection
 * @param string $table Table name to check for existing numbers
 * @param string $column Column name that contains the document number
 * @param string $prefix Prefix for the document number (e.g., 'CN' for credit notes)
 * @return string Generated document number
 */
function generate_document_number($conn, $table, $column, $prefix = '') {
    // Get current date components for the number
    $year = date('Y');
    $month = date('m');
    
    // Format: PREFIX-YYYYMM-XXXX where XXXX is a sequential number
    $base_number = $prefix . '-' . $year . $month . '-';
    
    // Find the highest existing number with this prefix and date
    $query = "SELECT MAX($column) as max_number FROM $table WHERE $column LIKE :pattern";
    $stmt = $conn->prepare($query);
    $pattern = $base_number . '%';
    $stmt->bindParam(':pattern', $pattern, PDO::PARAM_STR);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $sequence = 1;
    if ($result && !empty($result['max_number'])) {
        // Extract the sequence number from the existing highest number
        $parts = explode('-', $result['max_number']);
        if (count($parts) >= 3) {
            $sequence = (int)$parts[2] + 1;
        }
    }
    
    // Format the sequence with leading zeros
    $formatted_sequence = str_pad($sequence, 4, '0', STR_PAD_LEFT);
    
    // Combine all parts to create the final document number
    $document_number = $base_number . $formatted_sequence;
    
    return $document_number;
}

/**
 * Reverse inventory stock for a product
 * 
 * @param PDO $conn Database connection
 * @param int $product_id Product ID
 * @param int $location_id Location ID
 * @param float $quantity Quantity to reverse
 * @param string|null $batch_number Batch number if applicable
 * @return bool Success status
 */
function reverseInventoryStock($conn, $product_id, $location_id, $quantity, $batch_number = null) {
    debug_log("Reversing inventory stock", [
        "product_id" => $product_id,
        "location_id" => $location_id,
        "quantity" => $quantity,
        "batch_number" => $batch_number
    ]);
    
    try {
        // Simplest possible query to avoid parameter binding issues
        if (!empty($batch_number)) {
            debug_log("Using batch-specific update");
            $update_query = "UPDATE inventory_stock 
                          SET quantity = quantity - ?, 
                              available_quantity = available_quantity - ? 
                          WHERE product_id = ? 
                          AND location_id = ? 
                          AND batch_number = ?";
            
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->execute([$quantity, $quantity, $product_id, $location_id, $batch_number]);
        } else {
            debug_log("Using non-batch update");
            $update_query = "UPDATE inventory_stock 
                          SET quantity = quantity - ?, 
                              available_quantity = available_quantity - ? 
                          WHERE product_id = ? 
                          AND location_id = ? 
                          AND (batch_number IS NULL OR batch_number = '')";
            
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->execute([$quantity, $quantity, $product_id, $location_id]);
        }
        
        $affected_rows = $update_stmt->rowCount();
        debug_log("Affected rows", $affected_rows);
        
        if ($affected_rows == 0) {
            // If no rows affected, insert a new record with negative quantity
            debug_log("No existing stock found, creating inventory record with negative quantity");
            
            $insert_query = "INSERT INTO inventory_stock 
                           (product_id, location_id, batch_number, quantity, available_quantity, created_at, created_by) 
                           VALUES (?, ?, ?, ?, ?, NOW(), ?)";
            
            $batch_value = !empty($batch_number) ? $batch_number : null;
            $negative_quantity = -1 * abs($quantity); // Ensure it's negative
            
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->execute([$product_id, $location_id, $batch_value, $negative_quantity, $negative_quantity, $_SESSION['user_id']]);
            
            debug_log("Created new inventory record with negative quantity");
        }
        
        debug_log("Stock reversed successfully");
        return true;
    } catch (Exception $e) {
        debug_log("Error in reverseInventoryStock: " . $e->getMessage());
        throw $e;
    }
}
?>
