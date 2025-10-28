<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login.php');
    exit;
}

// Include database connection
require_once '../../../config/database.php';

// Create a log file for detailed debugging
function debug_log($message) {
    $logDir = '../../../logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/dn_cancel_debug.log';
    $timestamp = date('[Y-m-d H:i:s]');
    $logMessage = $timestamp . ' ' . $message . "\n";
    
    // Write to log file
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Log the start of the process
debug_log("Starting DN cancellation process");
debug_log("POST data: " . print_r($_POST, true));

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if DN ID is provided
if (!isset($_POST['delivery_note_id']) || empty($_POST['delivery_note_id'])) {
    $_SESSION['error_message'] = "DN ID is required";
    debug_log("Error: DN ID is missing");
    header('Location: ../delivery_notes.php');
    exit;
}

$delivery_note_id = $_POST['delivery_note_id'];
$cancellation_reason = isset($_POST['cancellation_reason']) ? $_POST['cancellation_reason'] : '';

debug_log("Cancelling DN ID: $delivery_note_id with reason: $cancellation_reason");

try {
    // Create database connection
    $db = new Database();
    $conn = $db->getConnection();
    
    // Start transaction
    $conn->beginTransaction();
    
    // 1. Get DN details
    $dnQuery = "SELECT g.*, so.id as so_id, so.status as so_status 
                FROM delivery_notes g
                LEFT JOIN sales_orders so ON g.sales_order_id = so.id
                WHERE g.id = :delivery_note_id";
    $stmtDn = $conn->prepare($dnQuery);
    $stmtDn->bindValue(':delivery_note_id', $delivery_note_id);
    $stmtDn->execute();
    $dnData = $stmtDn->fetch(PDO::FETCH_ASSOC);
    
    if (!$dnData) {
        throw new Exception("DN not found");
    }
    
    debug_log("DN data: " . print_r($dnData, true));
    
    // Check if DN is already cancelled
    if ($dnData['status'] === 'cancelled') {
        throw new Exception("This DN has already been cancelled");
    }
    
    // 2. Get all DN items
    $itemsQuery = "SELECT * FROM delivery_note_items WHERE delivery_note_id = :delivery_note_id";
    $stmtItems = $conn->prepare($itemsQuery);
    $stmtItems->bindValue(':delivery_note_id', $delivery_note_id);
    $stmtItems->execute();
    $dnItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    
    debug_log("Found " . count($dnItems) . " DN items to cancel");
    
    // 3. Update DN status to cancelled
    $updateDn = "UPDATE delivery_notes SET 
                  status = 'cancelled', 
                  notes = CONCAT(IFNULL(notes, ''), ' | CANCELLED: ', :cancellation_reason),
                  updated_by = :updated_by, 
                  updated_at = NOW() 
                  WHERE id = :delivery_note_id";
    $stmtUpdateDn = $conn->prepare($updateDn);
    $stmtUpdateDn->bindValue(':delivery_note_id', $delivery_note_id);
    $stmtUpdateDn->bindValue(':cancellation_reason', $cancellation_reason);
    $stmtUpdateDn->bindValue(':updated_by', $_SESSION['user_id']);
    $stmtUpdateDn->execute();
    
    debug_log("Updated DN status to cancelled");
    
    // 4. Create inventory transaction for cancellation
    $txnNumber = 'CANCEL-' . date('Ymd') . '-' . rand(100, 999);
    $insertTxn = "INSERT INTO inventory_transactions 
                 (transaction_number, transaction_date, transaction_type_id, reference_type, reference_id, notes, created_by) 
                 VALUES 
                 (:transaction_number, NOW(), :transaction_type_id, :reference_type, :reference_id, :notes, :created_by)";
    
    $stmtInsertTxn = $conn->prepare($insertTxn);
    $stmtInsertTxn->bindValue(':transaction_number', $txnNumber);
    $stmtInsertTxn->bindValue(':transaction_type_id', 11); // 11 = DN_CANCEL transaction type
    $stmtInsertTxn->bindValue(':reference_type', 'DN_CANCEL');
    $stmtInsertTxn->bindValue(':reference_id', $delivery_note_id);
    $stmtInsertTxn->bindValue(':notes', "Cancellation of DN #{$dnData['delivery_note_number']}: $cancellation_reason");
    $stmtInsertTxn->bindValue(':created_by', $_SESSION['user_id']);
    $stmtInsertTxn->execute();
    $txnId = $conn->lastInsertId();
    
    debug_log("Created cancellation transaction with ID: $txnId");
    
    // 5. Process each DN item to reverse stock
    foreach ($dnItems as $item) {
        $productId = $item['product_id'];
        $quantityReceived = $item['quantity_received'];
        $batchNumber = $item['batch_number'] ?: 'DEFAULT';
        $expiryDate = $item['expiry_date'];
        $location_id = $dnData['location_id'];
        
        debug_log("Processing cancellation for item: product_id=$productId, qty=$quantityReceived, batch=$batchNumber");
        
        // Skip items with zero quantity
        if ($quantityReceived <= 0) {
            debug_log("Skipping item with zero quantity");
            continue;
        }
        
        // 5.1 Update inventory stock (subtract quantities)
        $updateStock = "UPDATE inventory_stock SET 
                       quantity = quantity - :quantity, 
                       available_quantity = available_quantity - :available_quantity, 
                       updated_by = :updated_by, 
                       updated_at = NOW() 
                       WHERE product_id = :product_id AND location_id = :location_id";
                       
        if ($batchNumber !== 'DEFAULT' && !empty($batchNumber)) {
            $updateStock .= " AND batch_number = :batch_number";
        } else {
            $updateStock .= " AND (batch_number IS NULL OR batch_number = 'DEFAULT')";
        }
        
        $stmtUpdateStock = $conn->prepare($updateStock);
        $stmtUpdateStock->bindValue(':quantity', $quantityReceived);
        $stmtUpdateStock->bindValue(':available_quantity', $quantityReceived);
        $stmtUpdateStock->bindValue(':updated_by', $_SESSION['user_id']);
        $stmtUpdateStock->bindValue(':product_id', $productId);
        $stmtUpdateStock->bindValue(':location_id', $location_id);
        
        if ($batchNumber !== 'DEFAULT' && !empty($batchNumber)) {
            $stmtUpdateStock->bindValue(':batch_number', $batchNumber);
        }
        
        $stmtUpdateStock->execute();
        $rowsAffected = $stmtUpdateStock->rowCount();
        
        debug_log("Updated stock record: $rowsAffected rows affected");
        
        // 5.2 Insert transaction detail for cancellation
        // Get UOM information for this product
        $uomQuery = "SELECT base_uom_id FROM products WHERE id = :product_id LIMIT 1";
        $stmtUOM = $conn->prepare($uomQuery);
        $stmtUOM->bindValue(':product_id', $productId);
        $stmtUOM->execute();
        $uomData = $stmtUOM->fetch(PDO::FETCH_ASSOC);
        $uomId = !empty($uomData['base_uom_id']) ? $uomData['base_uom_id'] : 1;
        
        $insertTxnDetail = "INSERT INTO inventory_transaction_details 
                           (transaction_id, product_id, from_location_id, quantity, uom_id, 
                            batch_number, expiry_date, notes, created_by) 
                           VALUES 
                           (:txn_id, :product_id, :from_location_id, :quantity, :uom_id, 
                            :batch_number, :expiry_date, :notes, :created_by)";
        
        $stmtInsertTxnDetail = $conn->prepare($insertTxnDetail);
        $stmtInsertTxnDetail->bindValue(':txn_id', $txnId);
        $stmtInsertTxnDetail->bindValue(':product_id', $productId);
        $stmtInsertTxnDetail->bindValue(':from_location_id', $location_id);
        $stmtInsertTxnDetail->bindValue(':quantity', $quantityReceived);
        $stmtInsertTxnDetail->bindValue(':uom_id', $uomId);
        $stmtInsertTxnDetail->bindValue(':batch_number', $batchNumber);
        $stmtInsertTxnDetail->bindValue(':expiry_date', $expiryDate);
        $stmtInsertTxnDetail->bindValue(':notes', "Cancellation of DN item");
        $stmtInsertTxnDetail->bindValue(':created_by', $_SESSION['user_id']);
        $stmtInsertTxnDetail->execute();
        
        debug_log("Created transaction detail for product ID: $productId");
    }
    
    // 6. Check if we need to update the sales order status
    if ($dnData['sales_order_id']) {
        // Check if there are any other active DNs for this SO
        $checkOtherDns = "SELECT COUNT(*) FROM delivery_notes 
                          WHERE sales_order_id = :so_id 
                          AND id != :delivery_note_id 
                          AND status = 'completed'";
        $stmtCheckDns = $conn->prepare($checkOtherDns);
        $stmtCheckDns->bindValue(':so_id', $dnData['sales_order_id']);
        $stmtCheckDns->bindValue(':delivery_note_id', $delivery_note_id);
        $stmtCheckDns->execute();
        $otherDnsCount = $stmtCheckDns->fetchColumn();
        
        debug_log("Found $otherDnsCount other active DNs for this SO");
        
        // If this was the only DN, update PO status back to approved
        if ($otherDnsCount == 0 && $dnData['so_status'] == 'completed') {
            $updateSo = "UPDATE sales_orders SET 
                        status = 'approved', 
                        updated_by = :updated_by, 
                        updated_at = NOW() 
                        WHERE id = :so_id";
            $stmtUpdateSo = $conn->prepare($updateSo);
            $stmtUpdateSo->bindValue(':so_id', $dnData['sales_order_id']);
            $stmtUpdateSo->bindValue(':updated_by', $_SESSION['user_id']);
            $stmtUpdateSo->execute();
            
            debug_log("Updated SO status back to approved");
        }
        
        // Update received quantities in sales_order_items
        foreach ($dnItems as $item) {
            if (isset($item['sales_order_item_id']) && $item['sales_order_item_id'] > 0) {
                $updateSoItem = "UPDATE sales_order_items SET 
                                quantity_received = quantity_received - :quantity_received,
                                updated_by = :updated_by,
                                updated_at = NOW()
                                WHERE id = :so_item_id";
                $stmtUpdateSoItem = $conn->prepare($updateSoItem);
                $stmtUpdateSoItem->bindValue(':quantity_received', $item['quantity_received']);
                $stmtUpdateSoItem->bindValue(':updated_by', $_SESSION['user_id']);
                $stmtUpdateSoItem->bindValue(':so_item_id', $item['sales_order_item_id']);
                $stmtUpdateSoItem->execute();
                
                debug_log("Updated received quantity for SO item ID: {$item['sales_order_item_id']}");
            }
        }
    }
    
    // Commit the transaction
    $conn->commit();
    
    $_SESSION['success_message'] = "DN #{$dnData['delivery_note_number']} has been cancelled successfully";
    debug_log("DN cancellation completed successfully");
    
    // Redirect back to DN detail
    header('Location: ../delivery_note_detail.php?id=' . $delivery_note_id);
    exit;
    
} catch (Exception $e) {
    // Rollback the transaction
    if (isset($conn)) {
        $conn->rollBack();
    }
    
    $_SESSION['error_message'] = "Error cancelling DN: " . $e->getMessage();
    debug_log("Error cancelling DN: " . $e->getMessage());
    
    // Redirect back to DN detail
    header('Location: ../delivery_note_detail.php?id=' . $delivery_note_id);
    exit;
}
