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
    $logFile = $logDir . '/grn_cancel_debug.log';
    $timestamp = date('[Y-m-d H:i:s]');
    $logMessage = $timestamp . ' ' . $message . "\n";
    
    // Write to log file
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Log the start of the process
debug_log("Starting GRN cancellation process");
debug_log("POST data: " . print_r($_POST, true));

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if GRN ID is provided
if (!isset($_POST['grn_id']) || empty($_POST['grn_id'])) {
    $_SESSION['error_message'] = "GRN ID is required";
    debug_log("Error: GRN ID is missing");
    header('Location: ../goods_receiving_notes.php');
    exit;
}

$grn_id = $_POST['grn_id'];
$cancellation_reason = isset($_POST['cancellation_reason']) ? $_POST['cancellation_reason'] : '';

debug_log("Cancelling GRN ID: $grn_id with reason: $cancellation_reason");

try {
    // Create database connection
    $db = new Database();
    $conn = $db->getConnection();
    
    // Start transaction
    $conn->beginTransaction();
    
    // 1. Get GRN details
    $grnQuery = "SELECT g.*, po.id as po_id, po.status as po_status 
                FROM goods_receiving_notes g
                LEFT JOIN purchase_orders po ON g.purchase_order_id = po.id
                WHERE g.id = :grn_id";
    $stmtGrn = $conn->prepare($grnQuery);
    $stmtGrn->bindValue(':grn_id', $grn_id);
    $stmtGrn->execute();
    $grnData = $stmtGrn->fetch(PDO::FETCH_ASSOC);
    
    if (!$grnData) {
        throw new Exception("GRN not found");
    }
    
    debug_log("GRN data: " . print_r($grnData, true));
    
    // Check if GRN is already cancelled
    if ($grnData['status'] === 'cancelled') {
        throw new Exception("This GRN has already been cancelled");
    }
    
    // 2. Get all GRN items
    $itemsQuery = "SELECT * FROM goods_receiving_note_items WHERE grn_id = :grn_id";
    $stmtItems = $conn->prepare($itemsQuery);
    $stmtItems->bindValue(':grn_id', $grn_id);
    $stmtItems->execute();
    $grnItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    
    debug_log("Found " . count($grnItems) . " GRN items to cancel");
    
    // 3. Update GRN status to cancelled
    $updateGrn = "UPDATE goods_receiving_notes SET 
                  status = 'cancelled', 
                  notes = CONCAT(IFNULL(notes, ''), ' | CANCELLED: ', :cancellation_reason),
                  updated_by = :updated_by, 
                  updated_at = NOW() 
                  WHERE id = :grn_id";
    $stmtUpdateGrn = $conn->prepare($updateGrn);
    $stmtUpdateGrn->bindValue(':grn_id', $grn_id);
    $stmtUpdateGrn->bindValue(':cancellation_reason', $cancellation_reason);
    $stmtUpdateGrn->bindValue(':updated_by', $_SESSION['user_id']);
    $stmtUpdateGrn->execute();
    
    debug_log("Updated GRN status to cancelled");
    
    // 4. Create inventory transaction for cancellation
    $txnNumber = 'CANCEL-' . date('Ymd') . '-' . rand(100, 999);
    $insertTxn = "INSERT INTO inventory_transactions 
                 (transaction_number, transaction_date, transaction_type_id, reference_type, reference_id, notes, created_by) 
                 VALUES 
                 (:transaction_number, NOW(), :transaction_type_id, :reference_type, :reference_id, :notes, :created_by)";
    
    $stmtInsertTxn = $conn->prepare($insertTxn);
    $stmtInsertTxn->bindValue(':transaction_number', $txnNumber);
    $stmtInsertTxn->bindValue(':transaction_type_id', 11); // 11 = GRN_CANCEL transaction type
    $stmtInsertTxn->bindValue(':reference_type', 'GRN_CANCEL');
    $stmtInsertTxn->bindValue(':reference_id', $grn_id);
    $stmtInsertTxn->bindValue(':notes', "Cancellation of GRN #{$grnData['grn_number']}: $cancellation_reason");
    $stmtInsertTxn->bindValue(':created_by', $_SESSION['user_id']);
    $stmtInsertTxn->execute();
    $txnId = $conn->lastInsertId();
    
    debug_log("Created cancellation transaction with ID: $txnId");
    
    // 5. Process each GRN item to reverse stock
    foreach ($grnItems as $item) {
        $productId = $item['product_id'];
        $quantityReceived = $item['quantity_received'];
        $batchNumber = $item['batch_number'] ?: 'DEFAULT';
        $expiryDate = $item['expiry_date'];
        $location_id = $grnData['location_id'];
        
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
        $stmtInsertTxnDetail->bindValue(':notes', "Cancellation of GRN item");
        $stmtInsertTxnDetail->bindValue(':created_by', $_SESSION['user_id']);
        $stmtInsertTxnDetail->execute();
        
        debug_log("Created transaction detail for product ID: $productId");
    }
    
    // 6. Check if we need to update the purchase order status
    if ($grnData['purchase_order_id']) {
        // Check if there are any other active GRNs for this PO
        $checkOtherGrns = "SELECT COUNT(*) FROM goods_receiving_notes 
                          WHERE purchase_order_id = :po_id 
                          AND id != :grn_id 
                          AND status = 'completed'";
        $stmtCheckGrns = $conn->prepare($checkOtherGrns);
        $stmtCheckGrns->bindValue(':po_id', $grnData['purchase_order_id']);
        $stmtCheckGrns->bindValue(':grn_id', $grn_id);
        $stmtCheckGrns->execute();
        $otherGrnsCount = $stmtCheckGrns->fetchColumn();
        
        debug_log("Found $otherGrnsCount other active GRNs for this PO");
        
        // If this was the only GRN, update PO status back to approved
        if ($otherGrnsCount == 0 && $grnData['po_status'] == 'completed') {
            $updatePo = "UPDATE purchase_orders SET 
                        status = 'approved', 
                        updated_by = :updated_by, 
                        updated_at = NOW() 
                        WHERE id = :po_id";
            $stmtUpdatePo = $conn->prepare($updatePo);
            $stmtUpdatePo->bindValue(':po_id', $grnData['purchase_order_id']);
            $stmtUpdatePo->bindValue(':updated_by', $_SESSION['user_id']);
            $stmtUpdatePo->execute();
            
            debug_log("Updated PO status back to approved");
        }
        
        // Update received quantities in purchase_order_items
        foreach ($grnItems as $item) {
            if (isset($item['purchase_order_item_id']) && $item['purchase_order_item_id'] > 0) {
                $updatePoItem = "UPDATE purchase_order_items SET 
                                quantity_received = quantity_received - :quantity_received,
                                updated_by = :updated_by,
                                updated_at = NOW()
                                WHERE id = :po_item_id";
                $stmtUpdatePoItem = $conn->prepare($updatePoItem);
                $stmtUpdatePoItem->bindValue(':quantity_received', $item['quantity_received']);
                $stmtUpdatePoItem->bindValue(':updated_by', $_SESSION['user_id']);
                $stmtUpdatePoItem->bindValue(':po_item_id', $item['purchase_order_item_id']);
                $stmtUpdatePoItem->execute();
                
                debug_log("Updated received quantity for PO item ID: {$item['purchase_order_item_id']}");
            }
        }
    }
    
    // Commit the transaction
    $conn->commit();
    
    $_SESSION['success_message'] = "GRN #{$grnData['grn_number']} has been cancelled successfully";
    debug_log("GRN cancellation completed successfully");
    
    // Redirect back to GRN detail
    header('Location: ../grn_detail.php?id=' . $grn_id);
    exit;
    
} catch (Exception $e) {
    // Rollback the transaction
    if (isset($conn)) {
        $conn->rollBack();
    }
    
    $_SESSION['error_message'] = "Error cancelling GRN: " . $e->getMessage();
    debug_log("Error cancelling GRN: " . $e->getMessage());
    
    // Redirect back to GRN detail
    header('Location: ../grn_detail.php?id=' . $grn_id);
    exit;
}
