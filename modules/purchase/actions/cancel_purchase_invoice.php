<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['purchase_cancel'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

// Helper function for debug logging
function debug_log($title, $data) {
    error_log("DEBUG [$title]: " . (is_array($data) || is_object($data) ? json_encode($data) : $data));
}

// Helper function to get the appropriate UOM ID with fallback mechanism
function get_uom_id_with_fallback($conn, $product_id, $default_uom_id = null) {
    // If a valid UOM ID is provided, use it
    if (!empty($default_uom_id)) {
        return $default_uom_id;
    }
    
    // Otherwise, implement the fallback mechanism
    $product_query = "SELECT purchase_uom_id, base_uom_id FROM products WHERE id = :product_id";
    $product_stmt = $conn->prepare($product_query);
    $product_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $product_stmt->execute();
    $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Implement UOM fallback mechanism
    if (!empty($product['purchase_uom_id'])) {
        return $product['purchase_uom_id'];
    } else if (!empty($product['base_uom_id'])) {
        return $product['base_uom_id'];
    } else {
        return 1; // Default UOM ID as last resort
    }
}

// Helper function to reverse inventory stock updates
function reverseInventoryStock($conn, $product_id, $location_id, $quantity, $batch_number = null) {
    // Check if stock exists for this product, location and batch
    $check_sql = "SELECT id, quantity, available_quantity, unit_cost, total_value 
                 FROM inventory_stock 
                 WHERE product_id = :product_id 
                 AND location_id = :location_id";
    
    if ($batch_number) {
        $check_sql .= " AND batch_number = :batch_number";
    } else {
        $check_sql .= " AND batch_number IS NULL";
    }
    
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $check_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
    
    if ($batch_number) {
        $check_stmt->bindParam(':batch_number', $batch_number, PDO::PARAM_STR);
    }
    
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        // Update existing stock - SUBTRACT the quantity
        $stock = $check_stmt->fetch(PDO::FETCH_ASSOC);
        $new_quantity = $stock['quantity'] - $quantity;
        $new_available = $stock['available_quantity'] - $quantity;
        
        // Calculate new total value
        $new_total_value = $new_quantity * $stock['unit_cost'];
        
        $update_sql = "UPDATE inventory_stock 
                      SET quantity = :quantity, 
                          available_quantity = :available_quantity, 
                          total_value = :total_value, 
                          updated_at = NOW(), 
                          updated_by = :updated_by 
                      WHERE id = :id";
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bindParam(':quantity', $new_quantity, PDO::PARAM_STR);
        $update_stmt->bindParam(':available_quantity', $new_available, PDO::PARAM_STR);
        $update_stmt->bindParam(':total_value', $new_total_value, PDO::PARAM_STR);
        $update_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_stmt->bindParam(':id', $stock['id'], PDO::PARAM_INT);
        
        $update_stmt->execute();
        
        return true;
    }
    
    return false; // No stock found to update
}

// Function to handle complete inventory reversal process
function reverseInventoryTransactions($conn, $invoice_id, $invoice_number, $today) {
    // Find inventory transactions related to this invoice
    $inv_trans_query = "SELECT * FROM inventory_transactions 
                      WHERE reference_type = 'purchase_invoice' 
                      AND reference_id = :invoice_id";
    $inv_trans_stmt = $conn->prepare($inv_trans_query);
    $inv_trans_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $inv_trans_stmt->execute();
    $inventory_transactions = $inv_trans_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    debug_log("Found inventory transactions to reverse", count($inventory_transactions));
    
    foreach ($inventory_transactions as $transaction) {
        // Get transaction details
        $trans_detail_query = "SELECT * FROM inventory_transaction_details 
                             WHERE transaction_id = :transaction_id";
        $trans_detail_stmt = $conn->prepare($trans_detail_query);
        $trans_detail_stmt->bindParam(':transaction_id', $transaction['id'], PDO::PARAM_INT);
        $trans_detail_stmt->execute();
        $transaction_details = $trans_detail_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create reversal inventory transaction
        $rev_trans_number = "REV-" . $transaction['transaction_number'];
        $rev_trans_query = "INSERT INTO inventory_transactions (
                           transaction_number, transaction_date, transaction_type_id,
                           reference_type, reference_id, notes, created_by, created_at
                       ) VALUES (
                           :transaction_number, :transaction_date, :transaction_type_id,
                           :reference_type, :reference_id, :notes, :created_by, NOW()
                       )";
        
        $rev_trans_stmt = $conn->prepare($rev_trans_query);
        $rev_trans_stmt->bindParam(':transaction_number', $rev_trans_number, PDO::PARAM_STR);
        $rev_trans_stmt->bindParam(':transaction_date', $today, PDO::PARAM_STR);
        // Use transaction type ID 2 for inventory return/reversal
        $transaction_type_id = 2; 
        $rev_trans_stmt->bindParam(':transaction_type_id', $transaction_type_id, PDO::PARAM_INT);
        $reference_type = 'purchase_invoice_cancel';
        $rev_trans_stmt->bindParam(':reference_type', $reference_type, PDO::PARAM_STR);
        $rev_trans_stmt->bindParam(':reference_id', $invoice_id, PDO::PARAM_INT);
        $notes = "Reversal of inventory transaction for cancelled invoice: " . $invoice_number;
        $rev_trans_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
        $rev_trans_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        
        try {
            $rev_trans_stmt->execute();
            $rev_trans_id = $conn->lastInsertId();
            
            // Create reversal transaction details and update inventory stock
            foreach ($transaction_details as $detail) {
                // 1. Create reversal transaction detail record
                $rev_detail_query = "INSERT INTO inventory_transaction_details (
                                    transaction_id, product_id, from_location_id, to_location_id,
                                    quantity, uom_id, unit_cost, total_cost, batch_number,
                                    expiry_date, notes, created_by, created_at
                                ) VALUES (
                                    :transaction_id, :product_id, :from_location_id, :to_location_id,
                                    :quantity, :uom_id, :unit_cost, :total_cost, :batch_number,
                                    :expiry_date, :notes, :created_by, NOW()
                                )";
                
                $rev_detail_stmt = $conn->prepare($rev_detail_query);
                $rev_detail_stmt->bindParam(':transaction_id', $rev_trans_id, PDO::PARAM_INT);
                $rev_detail_stmt->bindParam(':product_id', $detail['product_id'], PDO::PARAM_INT);
                
                // Swap from_location_id and to_location_id to reverse the movement
                $rev_detail_stmt->bindParam(':from_location_id', $detail['to_location_id'], PDO::PARAM_INT);
                $rev_detail_stmt->bindParam(':to_location_id', $detail['from_location_id'], PDO::PARAM_INT);
                
                $rev_detail_stmt->bindParam(':quantity', $detail['quantity'], PDO::PARAM_STR);
                
                // Get appropriate UOM ID using fallback mechanism
                $uom_id = get_uom_id_with_fallback($conn, $detail['product_id'], $detail['uom_id']);
                $rev_detail_stmt->bindParam(':uom_id', $uom_id, PDO::PARAM_INT);
                
                $rev_detail_stmt->bindParam(':unit_cost', $detail['unit_cost'], PDO::PARAM_STR);
                $rev_detail_stmt->bindParam(':total_cost', $detail['total_cost'], PDO::PARAM_STR);
                $rev_detail_stmt->bindParam(':batch_number', $detail['batch_number'], PDO::PARAM_STR);
                $rev_detail_stmt->bindParam(':expiry_date', $detail['expiry_date'], PDO::PARAM_STR);
                $detail_notes = "Reversal of inventory transaction detail for cancelled invoice: " . $invoice_number;
                $rev_detail_stmt->bindParam(':notes', $detail_notes, PDO::PARAM_STR);
                $rev_detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
                
                $rev_detail_stmt->execute();
                
                // 2. Update inventory stock quantities
                // For reversal, we need to subtract from the warehouse that received the goods
                $warehouse_id = $detail['to_location_id'];
                $reversal_result = reverseInventoryStock(
                    $conn,
                    $detail['product_id'],
                    $warehouse_id,
                    $detail['quantity'],
                    $detail['batch_number']
                );
                
                debug_log("Reversed inventory stock for product", [
                    'product_id' => $detail['product_id'],
                    'warehouse_id' => $warehouse_id,
                    'quantity' => $detail['quantity'],
                    'batch' => $detail['batch_number'],
                    'success' => $reversal_result
                ]);
            }
            
            debug_log("Created reversal inventory transaction", $rev_trans_id);
            
        } catch (PDOException $e) {
            throw new Exception("Failed to create reversal inventory transaction: " . $e->getMessage());
        }
    }
    
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['invoice_id'])) {
            throw new Exception("Invoice ID is required");
        }
        
        $invoice_id = $_POST['invoice_id'];
        $cancellation_notes = $_POST['notes'] ?? 'Invoice cancelled by user';
        
        // Start transaction
        $conn->beginTransaction();
        
        // 1. Check if invoice exists
        $invoice_query = "SELECT * FROM purchase_invoices WHERE id = :invoice_id";
        $invoice_stmt = $conn->prepare($invoice_query);
        $invoice_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
        $invoice_stmt->execute();
        $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            throw new Exception("Invoice not found");
        }
        
        debug_log("Invoice found", $invoice);
        
        // 2. Check if invoice is already cancelled
        if ($invoice['status'] === 'cancelled') {
            throw new Exception("Invoice is already cancelled");
        }
        
        // 3. Check if there are any payments made against this invoice
        $payment_query = "SELECT COUNT(*) as payment_count FROM purchase_payments 
                          WHERE invoice_id = :invoice_id AND status = 'completed'";
        $payment_stmt = $conn->prepare($payment_query);
        $payment_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
        $payment_stmt->execute();
        $payment_result = $payment_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($payment_result['payment_count'] > 0) {
            throw new Exception("Cannot cancel invoice with existing payments. Please cancel all payments first.");
        }
        
        debug_log("No payments found for invoice", $invoice_id);
        
        // 3.1 Check if there are any purchase returns for this invoice
        $return_query = "SELECT COUNT(*) as return_count FROM purchase_returns 
                        WHERE invoice_id = :invoice_id AND status != 'draft'"; 
        $return_stmt = $conn->prepare($return_query);
        $return_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
        $return_stmt->execute();
        $return_result = $return_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($return_result['return_count'] > 0) {
            throw new Exception("Cannot cancel invoice with existing purchase returns. The invoice has already been processed for returns.");
        }
        
        debug_log("No returns found for invoice", $invoice_id);
        
        // 4. Get current financial year for reversal entries
        $today = date('Y-m-d');
        $financial_year_query = "SELECT * FROM financial_years 
                                WHERE :today BETWEEN start_date AND end_date 
                                AND is_closed = 0";
        $fy_stmt = $conn->prepare($financial_year_query);
        $fy_stmt->bindParam(':today', $today, PDO::PARAM_STR);
        $fy_stmt->execute();
        $financial_year = $fy_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$financial_year) {
            throw new Exception("No active financial year found for today's date");
        }
        
        $reversal_financial_year_id = $financial_year['id'];
        $year_code = $financial_year['year_code'];
        
        debug_log("Financial year for reversal", $financial_year);
        
        // 5. Find original journal entry for this invoice
        $journal_query = "SELECT * FROM journal_entries 
                         WHERE reference_number = :invoice_number 
                         AND entry_type IN ('purchase_invoice', 'purchase') 
                         ORDER BY id DESC LIMIT 1";
        $journal_stmt = $conn->prepare($journal_query);
        $journal_stmt->bindParam(':invoice_number', $invoice['invoice_number'], PDO::PARAM_STR);
        $journal_stmt->execute();
        $original_journal = $journal_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$original_journal) {
            debug_log("No journal entry found for invoice", $invoice['invoice_number']);
            
            // Try with just the reference number in case entry_type is different
            $journal_query = "SELECT * FROM journal_entries 
                             WHERE reference_number = :invoice_number 
                             ORDER BY id DESC LIMIT 1";
            $journal_stmt = $conn->prepare($journal_query);
            $journal_stmt->bindParam(':invoice_number', $invoice['invoice_number'], PDO::PARAM_STR);
            $journal_stmt->execute();
            $original_journal = $journal_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$original_journal) {
                throw new Exception("No journal entry found for this invoice");
            }
            
            debug_log("Found journal entry with different entry_type", [
                'entry_id' => $original_journal['id'],
                'entry_type' => $original_journal['entry_type']
            ]);
        }
        
        debug_log("Original journal entry found", $original_journal);
        
        // 6. Create reversal journal entry
        // Generate reversal entry number (Format: REV-FY-XXXX)
        $number_query = "SELECT MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)) as last_number 
                        FROM journal_entries 
                        WHERE entry_number LIKE :entry_pattern 
                        AND financial_year_id = :financial_year_id";
        $stmt = $conn->prepare($number_query);
        $entry_pattern = "REV-$year_code-%";
        $stmt->bindParam(':entry_pattern', $entry_pattern, PDO::PARAM_STR);
        $stmt->bindParam(':financial_year_id', $reversal_financial_year_id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_number = str_pad(($row['last_number'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
        $reversal_entry_number = "REV-$year_code-$next_number";
        
        debug_log("Generated reversal entry number", $reversal_entry_number);
        
        $reversal_description = "Reversal of purchase invoice: " . $invoice['invoice_number'];
        
        // Insert journal entry header
        $journal_query = "INSERT INTO journal_entries (
                            entry_number, entry_date, description, reference_number,
                            entry_type, financial_year_id, status, total_debit, total_credit,
                            created_by, created_at, reversal_of
                        ) VALUES (
                            :entry_number, :entry_date, :description, :reference_number,
                            'Purchase', :financial_year_id, 'approved', :total_debit, :total_credit,
                            :created_by, NOW(), :reversal_of
                        )";
        
        $journal_stmt = $conn->prepare($journal_query);
        $journal_stmt->bindParam(':entry_number', $reversal_entry_number);
        $journal_stmt->bindParam(':entry_date', $today);
        $journal_stmt->bindParam(':description', $reversal_description);
        $journal_stmt->bindParam(':reference_number', $invoice['invoice_number']);
        $journal_stmt->bindParam(':financial_year_id', $reversal_financial_year_id);
        $journal_stmt->bindParam(':total_debit', $invoice['total_amount']);
        $journal_stmt->bindParam(':total_credit', $invoice['total_amount']);
        $journal_stmt->bindParam(':created_by', $_SESSION['user_id']);
        $journal_stmt->bindParam(':reversal_of', $original_journal['id']);
        
        try {
            $journal_stmt->execute();
            $reversal_entry_id = $conn->lastInsertId();
            debug_log("Reversal journal entry created", $reversal_entry_id);
        } catch (PDOException $e) {
            throw new Exception("Failed to create reversal journal entry: " . $e->getMessage());
        }
        
        // 7. Get original journal entry details
        $detail_query = "SELECT * FROM journal_entry_details WHERE journal_entry_id = :entry_id";
        $detail_stmt = $conn->prepare($detail_query);
        $detail_stmt->bindParam(':entry_id', $original_journal['id'], PDO::PARAM_INT);
        $detail_stmt->execute();
        $original_details = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        debug_log("Original journal entry details found", count($original_details));
        
        // 8. Create reversal journal entry details (swap debit and credit)
        foreach ($original_details as $detail) {
            $reversal_detail_query = "INSERT INTO journal_entry_details (
                                        journal_entry_id, account_id, description,
                                        debit_amount, credit_amount, created_by, created_at
                                    ) VALUES (
                                        :journal_entry_id, :account_id, :description,
                                        :debit_amount, :credit_amount, :created_by, NOW()
                                    )";
            
            $reversal_detail_stmt = $conn->prepare($reversal_detail_query);
            $reversal_detail_stmt->bindParam(':journal_entry_id', $reversal_entry_id, PDO::PARAM_INT);
            $reversal_detail_stmt->bindParam(':account_id', $detail['account_id'], PDO::PARAM_INT);
            $reversal_description = "Reversal: " . $detail['description'];
            $reversal_detail_stmt->bindParam(':description', $reversal_description, PDO::PARAM_STR);
            
            // Swap debit and credit
            $reversal_detail_stmt->bindParam(':debit_amount', $detail['credit_amount'], PDO::PARAM_STR);
            $reversal_detail_stmt->bindParam(':credit_amount', $detail['debit_amount'], PDO::PARAM_STR);
            $reversal_detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
            
            try {
                $reversal_detail_stmt->execute();
            } catch (PDOException $e) {
                throw new Exception("Failed to create reversal journal entry detail: " . $e->getMessage());
            }
        }
        
        debug_log("Reversal journal entry details created", $reversal_entry_id);
        
        // 9. Determine the source of the invoice (GRN, PO, or direct) and handle accordingly
        if ($invoice['grn_id']) {
            // CASE 1: Invoice was created from a GRN
            debug_log("Invoice was created from GRN", $invoice['grn_id']);
            
            // For GRN-based invoices, we don't reverse inventory transactions
            // because inventory was already recorded at the GRN level
            debug_log("Skipping inventory transaction reversal for GRN-based invoice", null);
            
            // 11. Update GRN status if invoice was created from a GRN
            // First, get the GRN details to check its original status
            $grn_query = "SELECT * FROM goods_receiving_notes WHERE id = :grn_id";
            $grn_stmt = $conn->prepare($grn_query);
            $grn_stmt->bindParam(':grn_id', $invoice['grn_id'], PDO::PARAM_INT);
            $grn_stmt->execute();
            $grn = $grn_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$grn) {
                throw new Exception("GRN not found for this invoice");
            }
            
            debug_log("Found GRN details", $grn);
            
            // Get the invoice items to update GRN item invoiced quantities
            $invoice_items_query = "SELECT pii.*, grni.id as grn_item_id, grni.invoiced_quantity as current_invoiced_quantity 
                                  FROM purchase_invoice_items pii 
                                  LEFT JOIN goods_receiving_note_items grni ON pii.grn_item_id = grni.id 
                                  WHERE pii.purchase_invoice_id = :invoice_id";
            $invoice_items_stmt = $conn->prepare($invoice_items_query);
            $invoice_items_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
            $invoice_items_stmt->execute();
            $invoice_items = $invoice_items_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            debug_log("Found invoice items for GRN", count($invoice_items));
            
            // Update the invoiced quantities for each GRN item
            foreach ($invoice_items as $item) {
                if (!empty($item['grn_item_id'])) {
                    $grn_item_id = $item['grn_item_id'];
                    $current_invoiced_quantity = floatval($item['current_invoiced_quantity'] ?? 0);
                    $item_quantity = floatval($item['quantity'] ?? 0);
                    
                    // Subtract this invoice's quantity from the GRN item's invoiced quantity
                    $new_invoiced_quantity = max(0, $current_invoiced_quantity - $item_quantity);
                    
                    debug_log("Updating GRN item invoiced quantity", [
                        'grn_item_id' => $grn_item_id,
                        'current_invoiced_quantity' => $current_invoiced_quantity,
                        'invoice_item_quantity' => $item_quantity,
                        'new_invoiced_quantity' => $new_invoiced_quantity
                    ]);
                    
                    // Update the GRN item's invoiced quantity
                    $update_grn_item = $conn->prepare("UPDATE goods_receiving_note_items SET invoiced_quantity = :invoiced_quantity WHERE id = :id");
                    $update_grn_item->bindParam(':invoiced_quantity', $new_invoiced_quantity, PDO::PARAM_STR);
                    $update_grn_item->bindParam(':id', $grn_item_id, PDO::PARAM_INT);
                    $update_grn_item->execute();
                }
            }
            
            // Check if there are other invoices for this GRN
            $grn_invoice_query = "SELECT COUNT(*) as invoice_count FROM purchase_invoices 
                                 WHERE grn_id = :grn_id 
                                 AND id != :invoice_id 
                                 AND status != 'cancelled'";
            $grn_invoice_stmt = $conn->prepare($grn_invoice_query);
            $grn_invoice_stmt->bindParam(':grn_id', $invoice['grn_id'], PDO::PARAM_INT);
            $grn_invoice_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
            $grn_invoice_stmt->execute();
            $grn_invoice_result = $grn_invoice_stmt->fetch(PDO::FETCH_ASSOC);
            
            // If no other invoices exist, update GRN invoice_status to 'pending'
            // If other invoices exist, set invoice_status to 'partially_invoiced'
            $grn_invoice_status = 'pending'; // Default to pending when cancelling
            
            if ($grn_invoice_result['invoice_count'] > 0) {
                $grn_invoice_status = 'partially_invoiced';
            }
            
            $update_grn_query = "UPDATE goods_receiving_notes 
                                SET invoice_status = :invoice_status, 
                                    updated_by = :updated_by, 
                                    updated_at = NOW() 
                                WHERE id = :grn_id";
            $update_grn_stmt = $conn->prepare($update_grn_query);
            $update_grn_stmt->bindParam(':invoice_status', $grn_invoice_status, PDO::PARAM_STR);
            $update_grn_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
            $update_grn_stmt->bindParam(':grn_id', $invoice['grn_id'], PDO::PARAM_INT);
            $update_grn_stmt->execute();
            
            debug_log("Updated GRN invoice status", [
                'grn_id' => $invoice['grn_id'],
                'invoice_status' => $grn_invoice_status
            ]);
            
            // For GRN-based invoices, we DO NOT update the purchase order status directly
            // because the PO status is managed through the GRN, not the invoice
            debug_log("Skipping purchase order status update for GRN-based invoice", null);
            
        } else if ($invoice['purchase_order_id']) {
            // CASE 2: Invoice was created directly from a Purchase Order (no GRN)
            debug_log("Invoice was created from Purchase Order", $invoice['purchase_order_id']);
            
            // Reverse inventory transactions for PO-based invoices and update inventory stock quantities
            try {
                reverseInventoryTransactions($conn, $invoice_id, $invoice['invoice_number'], $today);
                debug_log("Successfully reversed inventory transactions and updated stock quantities", null);
            } catch (Exception $e) {
                throw new Exception("Failed to reverse inventory transactions: " . $e->getMessage());
            }
            
            // Update purchase order status for PO-based invoices
            // First, get the PO details to check its original status
            $po_query = "SELECT * FROM purchase_orders WHERE id = :po_id";
            $po_stmt = $conn->prepare($po_query);
            $po_stmt->bindParam(':po_id', $invoice['purchase_order_id'], PDO::PARAM_INT);
            $po_stmt->execute();
            $po = $po_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                throw new Exception("Purchase order not found for this invoice");
            }
            
            debug_log("Found purchase order details", $po);
            
            // Check if there are other invoices for this PO
            $po_invoice_query = "SELECT COUNT(*) as invoice_count FROM purchase_invoices 
                                WHERE purchase_order_id = :po_id 
                                AND id != :invoice_id 
                                AND status != 'cancelled'";
            $po_invoice_stmt = $conn->prepare($po_invoice_query);
            $po_invoice_stmt->bindParam(':po_id', $invoice['purchase_order_id'], PDO::PARAM_INT);
            $po_invoice_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
            $po_invoice_stmt->execute();
            $po_invoice_result = $po_invoice_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get more details about other invoices to make better decisions
            $other_invoices_exist = $po_invoice_result['invoice_count'] > 0;
            
            debug_log("Other invoices exist for this PO", [
                'po_id' => $invoice['purchase_order_id'],
                'other_invoices_count' => $po_invoice_result['invoice_count']
            ]);
            
            // Check if there are any GRNs for this PO
            $grn_query = "SELECT COUNT(*) as grn_count FROM goods_receiving_notes 
                          WHERE purchase_order_id = :po_id 
                          AND status != 'cancelled'"; 
            $grn_stmt = $conn->prepare($grn_query);
            $grn_stmt->bindParam(':po_id', $invoice['purchase_order_id'], PDO::PARAM_INT);
            $grn_stmt->execute();
            $grn_result = $grn_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if this was a full invoice for the entire purchase order
            $was_complete_invoice = false;
            
            // Check if the current PO status is 'completed' which indicates it was a full invoice
            if ($po['status'] === 'completed') {
                $was_complete_invoice = true;
                debug_log("This was a complete invoice for the entire purchase order", null);
            } else {
                // Alternative check: Compare the invoice items with the PO items to see if all items were fully invoiced
                $po_items_query = "SELECT poi.product_id, poi.quantity 
                                   FROM purchase_order_items poi 
                                   WHERE poi.purchase_order_id = :po_id";
                $po_items_stmt = $conn->prepare($po_items_query);
                $po_items_stmt->bindParam(':po_id', $invoice['purchase_order_id'], PDO::PARAM_INT);
                $po_items_stmt->execute();
                $po_items = $po_items_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get invoice items
                $invoice_items_query = "SELECT pii.product_id, pii.quantity 
                                        FROM purchase_invoice_items pii 
                                        WHERE pii.purchase_invoice_id = :invoice_id";
                $invoice_items_stmt = $conn->prepare($invoice_items_query);
                $invoice_items_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
                $invoice_items_stmt->execute();
                $invoice_items = $invoice_items_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Check if all PO items are fully covered by this invoice
                $all_items_covered = true;
                $po_items_map = [];
                
                // Create a map of PO items for easy lookup
                foreach ($po_items as $po_item) {
                    $po_items_map[$po_item['product_id']] = floatval($po_item['quantity']);
                }
                
                // Check if invoice items cover all PO items
                foreach ($po_items_map as $product_id => $po_quantity) {
                    $invoice_quantity = 0;
                    foreach ($invoice_items as $invoice_item) {
                        if ($invoice_item['product_id'] == $product_id) {
                            $invoice_quantity += floatval($invoice_item['quantity']);
                        }
                    }
                    
                    // If any item is not fully covered, it's not a complete invoice
                    if ($invoice_quantity < $po_quantity) {
                        $all_items_covered = false;
                        break;
                    }
                }
                
                if ($all_items_covered) {
                    $was_complete_invoice = true;
                    debug_log("This was a complete invoice for the entire purchase order (based on item quantities)", null);
                }
            }
            
            // IMPORTANT: If there are other active invoices, always set status to 'partial'
            // regardless of GRN status, since we have partial invoicing
            if ($other_invoices_exist) {
                $po_status = 'partial';
                debug_log("Setting PO status to partial because other invoices exist", null);
            } else if ($grn_result['grn_count'] > 0) {
                // No other invoices but GRNs exist
                $po_status = 'received';
                debug_log("Setting PO status to received because GRNs exist but no other invoices", null);
            } else if ($was_complete_invoice) {
                // This was a full invoice for the entire PO, so revert to 'confirmed' state
                $po_status = 'confirmed';
                debug_log("Setting PO status to confirmed because this was a full invoice with no other invoices", null);
            } else {
                // No GRNs, no other invoices, not a complete invoice
                $po_status = 'confirmed';
                debug_log("Setting PO status to confirmed because no GRNs or other invoices exist", null);
            }
            
            $update_po_query = "UPDATE purchase_orders 
                               SET status = :status, 
                                   updated_by = :updated_by, 
                                   updated_at = NOW() 
                               WHERE id = :po_id";
            $update_po_stmt = $conn->prepare($update_po_query);
            $update_po_stmt->bindParam(':status', $po_status, PDO::PARAM_STR);
            $update_po_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
            $update_po_stmt->bindParam(':po_id', $invoice['purchase_order_id'], PDO::PARAM_INT);
            $update_po_stmt->execute();
            
            debug_log("Updated purchase order status", [
                'po_id' => $invoice['purchase_order_id'],
                'status' => $po_status
            ]);
            
        } else {
            // CASE 3: Invoice was created directly (no GRN, no PO)
            debug_log("Invoice was created directly (no GRN, no PO)", null);
            
            // Reverse inventory transactions for direct invoices and update inventory stock quantities
            try {
                reverseInventoryTransactions($conn, $invoice_id, $invoice['invoice_number'], $today);
                debug_log("Successfully reversed inventory transactions and updated stock quantities for direct invoice", null);
            } catch (Exception $e) {
                throw new Exception("Failed to reverse inventory transactions for direct invoice: " . $e->getMessage());
            }
        }
        
        // GRN status update is now handled in the invoice source determination section above
        
        // 12. Update invoice status to cancelled
        $update_invoice_query = "UPDATE purchase_invoices 
                                SET status = 'cancelled', 
                                    updated_by = :updated_by, 
                                    updated_at = NOW(), 
                                    notes = CONCAT(IFNULL(notes, ''), '\n', :cancellation_notes) 
                                WHERE id = :invoice_id";
        $update_invoice_stmt = $conn->prepare($update_invoice_query);
        $update_invoice_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_invoice_stmt->bindParam(':cancellation_notes', $cancellation_notes, PDO::PARAM_STR);
        $update_invoice_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
        $update_invoice_stmt->execute();
        
        debug_log("Updated invoice status to cancelled", $invoice_id);
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase invoice cancelled successfully',
            'invoice_id' => $invoice_id
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        
        debug_log("Error cancelling purchase invoice", $e->getMessage());
        
        echo json_encode([
            'success' => false,
            'message' => 'Error cancelling purchase invoice: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
