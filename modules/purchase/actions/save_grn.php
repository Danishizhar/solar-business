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
    $logFile = $logDir . '/grn_debug.log';
    $timestamp = date('[Y-m-d H:i:s]');
    $logMessage = $timestamp . ' ' . $message . "\n";
    
    // Write to log file
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Log the start of the process
debug_log("Starting GRN save process");
debug_log("POST data: " . print_r($_POST, true));

try {
    // Create database connection
    $db = new Database();
    $conn = $db->getConnection();
    
    // Extract form data
    $grn_id = isset($_POST['grn_id']) ? $_POST['grn_id'] : null;
    $grn_number = isset($_POST['grn_number']) ? $_POST['grn_number'] : '';
    $purchase_order_id = isset($_POST['purchase_order_id']) ? $_POST['purchase_order_id'] : 0;
    $received_date = isset($_POST['received_date']) ? $_POST['received_date'] : date('Y-m-d');
    $location_id = isset($_POST['location_id']) ? $_POST['location_id'] : 0;
    $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
    $items = isset($_POST['items']) ? $_POST['items'] : array();
    
    debug_log("Form data: grn_number=$grn_number, po_id=$purchase_order_id, date=$received_date, location=$location_id");
    
    // Validate required fields
    if (empty($grn_number) || empty($purchase_order_id) || empty($received_date) || empty($location_id)) {
        debug_log("Validation failed: Missing required fields");
        header('Location: ../grn_create.php?po_id=' . $purchase_order_id . '&error=Missing required fields');
        exit;
    }
    
    // Check if items array is empty
    if (empty($items)) {
        debug_log("Validation failed: No items to receive");
        header('Location: ../grn_create.php?po_id=' . $purchase_order_id . '&error=No items to receive');
        exit;
    }
    
    // Check if GRN number already exists
    $checkGRN = "SELECT id FROM goods_receiving_notes WHERE grn_number = :grn_number AND id != :grn_id LIMIT 1";
    $stmtCheckGRN = $conn->prepare($checkGRN);
    $stmtCheckGRN->bindValue(':grn_number', $grn_number);
    $stmtCheckGRN->bindValue(':grn_id', $grn_id ?: 0);
    $stmtCheckGRN->execute();
    
    if ($stmtCheckGRN->fetch(PDO::FETCH_ASSOC)) {
        debug_log("Validation failed: GRN number already exists");
        header('Location: ../grn_create.php?po_id=' . $purchase_order_id . '&error=GRN number already exists');
        exit;
    }
    
    // Begin transaction
    $conn->beginTransaction();
    debug_log("Transaction started");
    
    // Get purchase order details and items for per-item calculation
    $poQuery = "SELECT po.*, 
                (SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = po.id) as total_items 
                FROM purchase_orders po 
                WHERE po.id = :po_id LIMIT 1";
    $poStmt = $conn->prepare($poQuery);
    $poStmt->bindValue(':po_id', $purchase_order_id);
    $poStmt->execute();
    $poData = $poStmt->fetch(PDO::FETCH_ASSOC);
    
    debug_log("Purchase order details: " . print_r($poData, true));
    
    // Get all purchase order items to match with GRN items
    $poItemsQuery = "SELECT * FROM purchase_order_items WHERE purchase_order_id = :po_id";
    $poItemsStmt = $conn->prepare($poItemsQuery);
    $poItemsStmt->bindValue(':po_id', $purchase_order_id);
    $poItemsStmt->execute();
    $poItems = $poItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create a lookup array for PO items
    $poItemsLookup = [];
    foreach ($poItems as $poItem) {
        $poItemsLookup[$poItem['id']] = $poItem;
    }
    
    // Calculate financial values per item
    $subtotal = 0;
    $taxAmount = 0;
    $discountAmount = 0;
    $deliveryCharges = 0;
    
    // Calculate total value of all PO items for proportional delivery charges
    $poTotalValue = 0;
    $grnTotalValue = 0;
    
    foreach ($poItemsLookup as $poItem) {
        $poTotalValue += floatval($poItem['subtotal']);
    }
    
    foreach ($items as $item) {
        $poItemId = $item['purchase_order_item_id'];
        $quantityOrdered = floatval($item['quantity_ordered']);
        $quantityReceived = floatval($item['quantity_received']);
        
        if (isset($poItemsLookup[$poItemId]) && $quantityOrdered > 0) {
            $poItem = $poItemsLookup[$poItemId];
            $ratio = $quantityReceived / $quantityOrdered;
            
            // Calculate per item values
            $itemSubtotal = floatval($poItem['subtotal']) * $ratio;
            $itemTax = floatval($poItem['tax_amount']) * $ratio;
            $itemDiscount = floatval($poItem['discount_amount']) * $ratio;
            
            $grnTotalValue += $itemSubtotal;
            $subtotal += $itemSubtotal;
            $taxAmount += $itemTax;
            $discountAmount += $itemDiscount;
            
            debug_log("Item ID: $poItemId, Ordered: $quantityOrdered, Received: $quantityReceived, Ratio: $ratio");
            debug_log("Item calculations: subtotal=$itemSubtotal, tax=$itemTax, discount=$itemDiscount");
        }
    }
    
    // Calculate delivery charges proportional to the value of items in this GRN
    if ($poTotalValue > 0) {
        $deliveryCharges = $poData['delivery_charges'] * ($grnTotalValue / $poTotalValue);
    }
    
    debug_log("Proportional delivery charges calculation: PO total value=$poTotalValue, GRN total value=$grnTotalValue");
    debug_log("Delivery charges: " . $poData['delivery_charges'] . " * (" . $grnTotalValue . "/" . $poTotalValue . ") = $deliveryCharges");
    
    $totalAmount = $subtotal + $taxAmount - $discountAmount + $deliveryCharges;
    
    debug_log("Calculated financial values: subtotal=$subtotal, tax=$taxAmount, discount=$discountAmount, delivery=$deliveryCharges, total=$totalAmount");
    
    // Create or update GRN
    if ($grn_id) {
        // Update existing GRN
        $updateGRN = "UPDATE goods_receiving_notes SET 
                      grn_number = :grn_number,
                      purchase_order_id = :purchase_order_id,
                      received_date = :received_date,
                      location_id = :location_id,
                      notes = :notes,
                      subtotal = :subtotal,
                      tax_amount = :tax_amount,
                      discount_amount = :discount_amount,
                      delivery_charges = :delivery_charges,
                      total_amount = :total_amount,
                      updated_by = :updated_by,
                      updated_at = NOW()
                      WHERE id = :grn_id";
                      
        $stmtUpdateGRN = $conn->prepare($updateGRN);
        $stmtUpdateGRN->bindValue(':grn_number', $grn_number);
        $stmtUpdateGRN->bindValue(':purchase_order_id', $purchase_order_id);
        $stmtUpdateGRN->bindValue(':received_date', $received_date);
        $stmtUpdateGRN->bindValue(':location_id', $location_id);
        $stmtUpdateGRN->bindValue(':notes', $notes);
        $stmtUpdateGRN->bindValue(':subtotal', $subtotal);
        $stmtUpdateGRN->bindValue(':tax_amount', $taxAmount);
        $stmtUpdateGRN->bindValue(':discount_amount', $discountAmount);
        $stmtUpdateGRN->bindValue(':delivery_charges', $deliveryCharges);
        $stmtUpdateGRN->bindValue(':total_amount', $totalAmount);
        $stmtUpdateGRN->bindValue(':updated_by', $_SESSION['user_id']);
        $stmtUpdateGRN->bindValue(':grn_id', $grn_id);
        
        try {
            $stmtUpdateGRN->execute();
            debug_log("GRN updated successfully with ID: $grn_id");
        } catch (PDOException $e) {
            debug_log("Error updating GRN: " . $e->getMessage());
            throw $e;
        }
    } else {
        // Create new GRN
        $insertGRN = "INSERT INTO goods_receiving_notes 
                      (grn_number, purchase_order_id, received_date, location_id, notes, 
                       subtotal, tax_amount, discount_amount, delivery_charges, total_amount, 
                       status, created_by, created_at) 
                      VALUES 
                      (:grn_number, :purchase_order_id, :received_date, :location_id, :notes, 
                       :subtotal, :tax_amount, :discount_amount, :delivery_charges, :total_amount, 
                       'completed', :created_by, NOW())";
                       
        $stmtInsertGRN = $conn->prepare($insertGRN);
        $stmtInsertGRN->bindValue(':grn_number', $grn_number);
        $stmtInsertGRN->bindValue(':purchase_order_id', $purchase_order_id);
        $stmtInsertGRN->bindValue(':received_date', $received_date);
        $stmtInsertGRN->bindValue(':location_id', $location_id);
        $stmtInsertGRN->bindValue(':notes', $notes);
        $stmtInsertGRN->bindValue(':subtotal', $subtotal);
        $stmtInsertGRN->bindValue(':tax_amount', $taxAmount);
        $stmtInsertGRN->bindValue(':discount_amount', $discountAmount);
        $stmtInsertGRN->bindValue(':delivery_charges', $deliveryCharges);
        $stmtInsertGRN->bindValue(':total_amount', $totalAmount);
        $stmtInsertGRN->bindValue(':created_by', $_SESSION['user_id']);
        
        try {
            $stmtInsertGRN->execute();
            $grn_id = $conn->lastInsertId();
            debug_log("New GRN created with ID: $grn_id");
        } catch (PDOException $e) {
            debug_log("Error creating GRN: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Delete existing GRN items if updating
    if ($grn_id) {
        $deleteItems = "DELETE FROM goods_receiving_note_items WHERE grn_id = :grn_id";
        $stmtDeleteItems = $conn->prepare($deleteItems);
        $stmtDeleteItems->bindValue(':grn_id', $grn_id);
        
        try {
            $stmtDeleteItems->execute();
            debug_log("Deleted existing GRN items for GRN ID: $grn_id");
        } catch (PDOException $e) {
            debug_log("Error deleting GRN items: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Create inventory transaction for this GRN
    $txnNumber = 'TXN-' . date('Ymd') . '-' . rand(100, 999);
    
    // Define the transaction insert SQL with explicit parameter binding for each field
    $insertTxn = "INSERT INTO inventory_transactions 
                  (transaction_number, transaction_date, transaction_type_id, reference_type, reference_id, notes, created_by) 
                  VALUES 
                  (:transaction_number, NOW(), :transaction_type_id, :reference_type, :reference_id, :notes, :created_by)";
    
    debug_log("Transaction SQL: $insertTxn");
    debug_log("Transaction parameters: transaction_number=$txnNumber, transaction_type_id=1, reference_type=GRN, reference_id=$grn_id");
    
    // Create a fresh prepared statement
    $stmtInsertTxn = $conn->prepare($insertTxn);
    $stmtInsertTxn->bindValue(':transaction_number', $txnNumber);
    $stmtInsertTxn->bindValue(':transaction_type_id', 1); // 1 = GRN receipt
    $stmtInsertTxn->bindValue(':reference_type', 'GRN');
    $stmtInsertTxn->bindValue(':reference_id', $grn_id);
    $stmtInsertTxn->bindValue(':notes', "Inventory receipt from GRN #$grn_number");
    $stmtInsertTxn->bindValue(':created_by', $_SESSION['user_id']);
    
    try {
        $stmtInsertTxn->execute();
        $txnId = $conn->lastInsertId();
        debug_log("Inventory transaction created with ID: $txnId");
    } catch (PDOException $e) {
        debug_log("Error creating inventory transaction: " . $e->getMessage());
        throw $e;
    }
    
    // Define SQL statements
    $insertGRNItem = "INSERT INTO goods_receiving_note_items 
                      (grn_id, purchase_order_item_id, product_id, quantity_ordered, quantity_received, 
                       unit_price, tax_rate, tax_amount, discount_percent, discount_amount, subtotal, total, 
                       batch_number, expiry_date, notes, uom_id, created_at) 
                      VALUES 
                      (:grn_id, :purchase_order_item_id, :product_id, :quantity_ordered, :quantity_received, 
                       :unit_price, :tax_rate, :tax_amount, :discount_percent, :discount_amount, :subtotal, :total, 
                       :batch_number, :expiry_date, :notes, :uom_id, NOW())";
    
    $insertStock = "INSERT INTO inventory_stock 
                    (product_id, location_id, batch_number, expiry_date, quantity, available_quantity, 
                     unit_cost, total_value, created_by, created_at) 
                    VALUES 
                    (:product_id, :location_id, :batch_number, :expiry_date, :quantity, :available_quantity, 
                     :unit_cost, :total_value, :created_by, NOW())";
    
    $insertTxnDetail = "INSERT INTO inventory_transaction_details 
                        (transaction_id, product_id, to_location_id, quantity, uom_id, 
                         unit_cost, total_cost, batch_number, expiry_date, notes, created_by) 
                        VALUES 
                        (:txn_id, :product_id, :to_location_id, :quantity, :uom_id, 
                         :unit_cost, :total_cost, :batch_number, :expiry_date, :notes, :created_by)";
    
    debug_log("Transaction detail SQL: $insertTxnDetail");
    
    // Process each item
    foreach ($items as $item) {
        $poItemId = isset($item['purchase_order_item_id']) ? $item['purchase_order_item_id'] : 0;
        $productId = isset($item['product_id']) ? $item['product_id'] : 0;
        $quantityOrdered = isset($item['quantity_ordered']) ? floatval($item['quantity_ordered']) : 0;
        $quantityReceived = isset($item['quantity_received']) ? floatval($item['quantity_received']) : 0;
        $unitPrice = isset($item['unit_price']) ? floatval($item['unit_price']) : 0;
        $batchNumber = isset($item['batch_number']) && !empty($item['batch_number']) ? $item['batch_number'] : 'DEFAULT';
        $expiryDate = isset($item['expiry_date']) && !empty($item['expiry_date']) ? $item['expiry_date'] : null;
        $itemNotes = isset($item['notes']) ? $item['notes'] : '';
        
        // Get the corresponding PO item for financial calculations
        if (isset($poItemsLookup[$poItemId]) && $quantityOrdered > 0 && $quantityReceived > 0) {
            $poItem = $poItemsLookup[$poItemId];
            $ratio = $quantityReceived / $quantityOrdered;
            
            // Get tax and discount values from PO item
            $taxRate = floatval($poItem['tax_rate'] ?? 0);
            $discountPercent = floatval($poItem['discount_percent'] ?? 0);
            
            // Calculate financial values for this item based on the PO item
            $itemSubtotal = floatval($poItem['subtotal']) * $ratio;
            $itemTax = floatval($poItem['tax_amount']) * $ratio;
            $itemDiscount = floatval($poItem['discount_amount']) * $ratio;
            $itemDelivery = $deliveryChargesPerItem * $ratio;
            
            // Calculate the total for this item
            $total = $itemSubtotal + $itemTax - $itemDiscount;
            
            debug_log("Item financial calculations: subtotal=$itemSubtotal, tax_rate=$taxRate, tax=$itemTax, discount_percent=$discountPercent, discount=$itemDiscount, delivery=$itemDelivery, total=$total");
        } else {
            // Fallback calculation if PO item not found
            $itemSubtotal = $quantityReceived * $unitPrice;
            $taxRate = 0;
            $itemTax = 0;
            $discountPercent = 0;
            $itemDiscount = 0;
            $total = $itemSubtotal;
            debug_log("Using fallback calculation for item: $total = $quantityReceived * $unitPrice");
        }
        
        debug_log("Processing item: product_id=$productId, po_item_id=$poItemId, qty_received=$quantityReceived, batch=$batchNumber");
        
        // Skip items with zero quantity
        if ($quantityReceived <= 0) {
            debug_log("Skipping item with zero or negative quantity");
            continue;
        }
        
        // Insert GRN item
        debug_log("SQL for GRN item insertion: $insertGRNItem");
        debug_log("Binding parameters: grn_id=$grn_id, purchase_order_item_id=$poItemId, product_id=$productId, quantity_ordered=$quantityOrdered, quantity_received=$quantityReceived, unit_price=$unitPrice, total=$total, batch_number=$batchNumber");
        
        $stmtInsertGRNItem = $conn->prepare($insertGRNItem);
        $stmtInsertGRNItem->bindValue(':grn_id', $grn_id);
        $stmtInsertGRNItem->bindValue(':purchase_order_item_id', $poItemId);
        $stmtInsertGRNItem->bindValue(':product_id', $productId);
        $stmtInsertGRNItem->bindValue(':quantity_ordered', $quantityOrdered);
        $stmtInsertGRNItem->bindValue(':quantity_received', $quantityReceived);
        $stmtInsertGRNItem->bindValue(':unit_price', $unitPrice);
        $stmtInsertGRNItem->bindValue(':tax_rate', $taxRate);
        $stmtInsertGRNItem->bindValue(':tax_amount', $itemTax);
        $stmtInsertGRNItem->bindValue(':discount_percent', $discountPercent);
        $stmtInsertGRNItem->bindValue(':discount_amount', $itemDiscount);
        $stmtInsertGRNItem->bindValue(':subtotal', $itemSubtotal);
        $stmtInsertGRNItem->bindValue(':total', $total);
        $stmtInsertGRNItem->bindValue(':batch_number', $batchNumber);
        $stmtInsertGRNItem->bindValue(':expiry_date', $expiryDate);
        $stmtInsertGRNItem->bindValue(':notes', $itemNotes);
        
        // Get the UOM ID - first check if provided in the form input
        $formUomId = isset($item['uom_id']) && !empty($item['uom_id']) ? $item['uom_id'] : null;
        
        // Use form UOM ID if available, otherwise get from product
        if (!$formUomId) {
            // Get UOM information for this product
            $uomQuery = "SELECT COALESCE(purchase_uom_id, base_uom_id, 1) AS uom_id FROM products WHERE id = :product_id LIMIT 1";
            $stmtUOM = $conn->prepare($uomQuery);
            $stmtUOM->bindValue(':product_id', $productId);
            $stmtUOM->execute();
            $uomData = $stmtUOM->fetch(PDO::FETCH_ASSOC);
            $formUomId = $uomData['uom_id'];
        }
        
        $stmtInsertGRNItem->bindValue(':uom_id', $formUomId);
        
        try {
            $stmtInsertGRNItem->execute();
            debug_log("GRN item inserted successfully");
        
        // Update the received_quantity in purchase_order_items
        if ($poItemId > 0) {
            $updatePoItem = "UPDATE purchase_order_items SET 
                            received_quantity = received_quantity + :quantity_received
                            WHERE id = :po_item_id";
            $stmtUpdatePoItem = $conn->prepare($updatePoItem);
            $stmtUpdatePoItem->bindValue(':quantity_received', $quantityReceived);
            $stmtUpdatePoItem->bindValue(':po_item_id', $poItemId);
            $stmtUpdatePoItem->execute();
            
            debug_log("Updated received_quantity for PO item ID: $poItemId");
        }
        } catch (PDOException $e) {
            debug_log("Error inserting GRN item: " . $e->getMessage());
            throw $e;
        }
        
        // Get UOM information for this product
        $uomQuery = "SELECT base_uom_id, purchase_uom_id FROM products WHERE id = :product_id LIMIT 1";
        $stmtUOM = $conn->prepare($uomQuery);
        $stmtUOM->bindValue(':product_id', $productId);
        $stmtUOM->execute();
        $uomData = $stmtUOM->fetch(PDO::FETCH_ASSOC);
        
        // Use purchase_uom_id if available, otherwise fall back to base_uom_id
        $uomId = !empty($uomData['purchase_uom_id']) ? $uomData['purchase_uom_id'] : $uomData['base_uom_id'];
        
        if (empty($uomId)) {
            debug_log("Warning: No UOM found for product ID: $productId, using default UOM ID 1");
            $uomId = 1; // Default UOM ID as fallback
        }
        
        debug_log("Using UOM ID: $uomId for product ID: $productId");
        
        // Check if stock record exists with this batch
        $checkStock = "SELECT id FROM inventory_stock WHERE product_id = :product_id AND location_id = :location_id";
        if ($batchNumber !== 'DEFAULT' && !empty($batchNumber)) {
            $checkStock .= " AND batch_number = :batch_number";
        } else {
            $checkStock .= " AND (batch_number IS NULL OR batch_number = 'DEFAULT')";
        }
        
        debug_log("Check stock SQL: $checkStock with product_id=$productId, location_id=$location_id" . 
                 ($batchNumber !== 'DEFAULT' && !empty($batchNumber) ? ", batch_number=$batchNumber" : ""));
        
        $stmtCheckStock = $conn->prepare($checkStock);
        $stmtCheckStock->bindValue(':product_id', $productId);
        $stmtCheckStock->bindValue(':location_id', $location_id);
        if ($batchNumber !== 'DEFAULT' && !empty($batchNumber)) {
            $stmtCheckStock->bindValue(':batch_number', $batchNumber);
        }
        
        debug_log("Checking if stock exists with SQL: $checkStock");
        $stmtCheckStock->execute();
        $stockExists = $stmtCheckStock->fetch(PDO::FETCH_ASSOC);
        
        // Update or insert stock record
        if ($stockExists) {
            debug_log("Stock exists, updating existing record");
            
            // Update existing stock - create a fresh prepared statement
            $updateStock = "UPDATE inventory_stock SET 
                           quantity = quantity + :quantity, 
                           available_quantity = available_quantity + :available_quantity, 
                           total_value = total_value + :total_value, 
                           updated_by = :updated_by, 
                           updated_at = NOW() 
                           WHERE product_id = :product_id AND location_id = :location_id";
                           
            if ($batchNumber !== 'DEFAULT' && !empty($batchNumber)) {
                $updateStock .= " AND batch_number = :batch_number";
            } else {
                $updateStock .= " AND (batch_number IS NULL OR batch_number = 'DEFAULT')";
            }
            
            debug_log("Stock update SQL: $updateStock with quantity=$quantityReceived, product_id=$productId, location_id=$location_id" . 
                     ($batchNumber !== 'DEFAULT' && !empty($batchNumber) ? ", batch_number=$batchNumber" : ""));
            
            $stmtUpdateStock = $conn->prepare($updateStock);
            $stmtUpdateStock->bindValue(':quantity', $quantityReceived);
            $stmtUpdateStock->bindValue(':available_quantity', $quantityReceived);
            $stmtUpdateStock->bindValue(':total_value', $total);
            $stmtUpdateStock->bindValue(':updated_by', $_SESSION['user_id']);
            $stmtUpdateStock->bindValue(':product_id', $productId);
            $stmtUpdateStock->bindValue(':location_id', $location_id);
            
            if ($batchNumber !== 'DEFAULT' && !empty($batchNumber)) {
                $stmtUpdateStock->bindValue(':batch_number', $batchNumber);
            }
            
            try {
                debug_log("Executing stock update with parameters: quantity=$quantityReceived, total_value=$total, product_id=$productId, location_id=$location_id, batch_number=$batchNumber");
                $stmtUpdateStock->execute();
                debug_log("Stock updated successfully");
            } catch (PDOException $stockEx) {
                debug_log("Error updating stock: " . $stockEx->getMessage());
                debug_log("Error Code: " . $stockEx->getCode());
                debug_log("Full SQL: $updateStock");
                throw $stockEx;
            }
        } else {
            debug_log("Stock doesn't exist, creating new record");
            
            // Insert new stock record - create a fresh prepared statement
            $insertStock = "INSERT INTO inventory_stock 
                           (product_id, location_id, batch_number, expiry_date, quantity, available_quantity, 
                            unit_cost, total_value, created_by, created_at) 
                           VALUES 
                           (:product_id, :location_id, :batch_number, :expiry_date, :quantity, :available_quantity, 
                            :unit_cost, :total_value, :created_by, NOW())";
                            
            debug_log("Stock insert SQL: $insertStock with product_id=$productId, location_id=$location_id, batch_number=" . 
                     ($batchNumber === 'DEFAULT' || empty($batchNumber) ? 'NULL' : $batchNumber) . 
                     ", quantity=$quantityReceived, unit_cost=$unitPrice, total_value=$total");
            
            $stmtInsertStock = $conn->prepare($insertStock);
            $stmtInsertStock->bindValue(':product_id', $productId);
            $stmtInsertStock->bindValue(':location_id', $location_id);
            $stmtInsertStock->bindValue(':batch_number', $batchNumber === 'DEFAULT' || empty($batchNumber) ? null : $batchNumber);
            $stmtInsertStock->bindValue(':expiry_date', $expiryDate ?: null);
            $stmtInsertStock->bindValue(':quantity', $quantityReceived);
            $stmtInsertStock->bindValue(':available_quantity', $quantityReceived);
            $stmtInsertStock->bindValue(':unit_cost', $unitPrice);
            $stmtInsertStock->bindValue(':total_value', $total);
            $stmtInsertStock->bindValue(':created_by', $_SESSION['user_id']);
            
            try {
                debug_log("Executing stock insertion with parameters: product_id=$productId, location_id=$location_id, batch_number=$batchNumber, expiry_date=" . ($expiryDate ?: 'NULL'));
                $stmtInsertStock->execute();
                debug_log("New stock record created successfully");
            } catch (PDOException $stockEx) {
                debug_log("Error inserting stock: " . $stockEx->getMessage());
                debug_log("Error Code: " . $stockEx->getCode());
                debug_log("Full SQL: $insertStock");
                throw $stockEx;
            }
        }
        
        // Get the UOM ID - first check if provided in the form input
        $formUomId = isset($item['uom_id']) && !empty($item['uom_id']) ? $item['uom_id'] : null;
        
        if ($formUomId) {
            // Use the UOM ID provided in the form
            $uomId = $formUomId;
            debug_log("Using UOM ID from form input: $uomId for product ID: $productId");
        } else {
            // Fall back to product's purchase_uom_id or base_uom_id
            $uomQuery = "SELECT COALESCE(purchase_uom_id, base_uom_id) AS uom_id FROM products WHERE id = :product_id";
            $stmtUom = $conn->prepare($uomQuery);
            $stmtUom->bindValue(':product_id', $productId);
            $stmtUom->execute();
            $uomId = $stmtUom->fetchColumn();
            
            if (!$uomId) {
                debug_log("WARNING: No UOM found for product ID: $productId, using default UOM ID 1");
                $uomId = 1; // Default UOM ID if not found
            }
            debug_log("Using UOM ID from product: $uomId for product ID: $productId");
        }
        
        debug_log("Using UOM ID: $uomId for product ID: $productId");
        
        // Record transaction detail
        debug_log("Transaction detail binding parameters: txn_id=$txnId, product_id=$productId, to_location_id=$location_id, quantity=$quantityReceived, uom_id=$uomId, unit_cost=$unitPrice, total_cost=$total, batch_number=$batchNumber, expiry_date=" . ($expiryDate ?: 'NULL') . ", notes=" . ($itemNotes ?: '\'\'')); 
        
        // Create a fresh prepared statement for each item to avoid parameter binding issues
        $insertTxnDetail = "INSERT INTO inventory_transaction_details 
                        (transaction_id, product_id, to_location_id, quantity, uom_id, 
                         unit_cost, total_cost, batch_number, expiry_date, notes, created_by) 
                        VALUES 
                        (:txn_id, :product_id, :to_location_id, :quantity, :uom_id, 
                         :unit_cost, :total_cost, :batch_number, :expiry_date, :notes, :created_by)";
        
        $stmtTxnDetail = $conn->prepare($insertTxnDetail);
        $stmtTxnDetail->bindValue(':txn_id', $txnId);
        $stmtTxnDetail->bindValue(':product_id', $productId);
        $stmtTxnDetail->bindValue(':to_location_id', $location_id); // Correctly binding to_location_id parameter
        $stmtTxnDetail->bindValue(':quantity', $quantityReceived);
        $stmtTxnDetail->bindValue(':uom_id', $uomId);
        $stmtTxnDetail->bindValue(':unit_cost', $unitPrice);
        $stmtTxnDetail->bindValue(':total_cost', $total);
        $stmtTxnDetail->bindValue(':batch_number', $batchNumber);
        $stmtTxnDetail->bindValue(':expiry_date', $expiryDate ?: null);
        $stmtTxnDetail->bindValue(':notes', $itemNotes ?: '');
        $stmtTxnDetail->bindValue(':created_by', $_SESSION['user_id']);
        
        try {
            debug_log("Executing transaction detail insert with parameters: txn_id=$txnId, product_id=$productId, location_id=$location_id, quantity=$quantityReceived, uom_id=$uomId");
            $stmtTxnDetail->execute();
            debug_log("Transaction detail inserted successfully");
        } catch (PDOException $txnEx) {
            debug_log("Error inserting transaction detail: " . $txnEx->getMessage());
            debug_log("Error Code: " . $txnEx->getCode());
            debug_log("Full SQL: $insertTxnDetail");
            throw $txnEx;
        }
        
        // Note: We're not updating purchase_order_items as the table doesn't have received_quantity column
        // Instead, we'll track received quantities through the goods_receiving_note_items table
        debug_log("Skipping purchase order item update as received_quantity column doesn't exist");
    }
    
    // Update purchase order status if all items are fully received
    // First, get the total ordered quantity from purchase_order_items
    $checkPOItems = "SELECT SUM(quantity) as total_ordered
                    FROM purchase_order_items 
                    WHERE purchase_order_id = :po_id";
    
    $stmtCheckPO = $conn->prepare($checkPOItems);
    $stmtCheckPO->bindValue(':po_id', $purchase_order_id);
    $stmtCheckPO->execute();
    $poItemData = $stmtCheckPO->fetch(PDO::FETCH_ASSOC);
    $totalOrdered = floatval($poItemData['total_ordered'] ?? 0);
    
    // Then, get the total received quantity from goods_receiving_note_items (only from completed GRNs)
    $checkGRNItems = "SELECT SUM(gri.quantity_received) as total_received 
                     FROM goods_receiving_note_items gri 
                     JOIN goods_receiving_notes grn ON gri.grn_id = grn.id 
                     WHERE grn.purchase_order_id = :po_id AND grn.status = 'completed'";
    
    $stmtCheckGRN = $conn->prepare($checkGRNItems);
    $stmtCheckGRN->bindValue(':po_id', $purchase_order_id);
    $stmtCheckGRN->execute();
    $grnItemData = $stmtCheckGRN->fetch(PDO::FETCH_ASSOC);
    $totalReceived = floatval($grnItemData['total_received'] ?? 0);
    
    debug_log("PO total ordered: $totalOrdered, total received: $totalReceived");
    
    // Update PO status if all items are received
    if ($totalOrdered > 0 && $totalReceived >= $totalOrdered) {
        $updatePO = "UPDATE purchase_orders SET status = 'received' WHERE id = :po_id";
        $stmtUpdatePO = $conn->prepare($updatePO);
        $stmtUpdatePO->bindValue(':po_id', $purchase_order_id);
        $stmtUpdatePO->execute();
        debug_log("Purchase order status updated to 'received'");
    } else {
        $updatePO = "UPDATE purchase_orders SET status = 'partial' WHERE id = :po_id AND status != 'received'";
        $stmtUpdatePO = $conn->prepare($updatePO);
        $stmtUpdatePO->bindValue(':po_id', $purchase_order_id);
        $stmtUpdatePO->execute();
        debug_log("Purchase order status updated to 'partial'");
    }
    
    // Commit the transaction
    $conn->commit();
    debug_log("Transaction committed successfully");
    
    // Redirect to GRN detail page
    header("Location: ../grn_detail.php?id=$grn_id&success=GRN saved successfully");
    exit;
    
} catch (Exception $e) {
    // Rollback the transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
        debug_log("Transaction rolled back due to error");
    }
    
    debug_log("GRN Save Error: " . $e->getMessage());
    debug_log("Error trace: " . $e->getTraceAsString());
    
    // Redirect with error message
    header("Location: ../grn_create.php?po_id=$purchase_order_id&error=" . urlencode("Error saving GRN: " . $e->getMessage()));
    exit;
}
