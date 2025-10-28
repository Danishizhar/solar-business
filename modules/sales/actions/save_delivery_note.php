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
    $logFile = $logDir . '/dn_debug.log';
    $timestamp = date('[Y-m-d H:i:s]');
    $logMessage = $timestamp . ' ' . $message . "\n";
    
    // Write to log file
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Log the start of the process
debug_log("Starting DN save process");
debug_log("POST data: " . print_r($_POST, true));

try {
    // Create database connection
    $db = new Database();
    $conn = $db->getConnection();
    
    // Extract form data
    $delivery_note_id = isset($_POST['delivery_note_id']) ? $_POST['delivery_note_id'] : null;
    $delivery_note_number = isset($_POST['delivery_note_number']) ? $_POST['delivery_note_number'] : '';
    $sales_order_id = isset($_POST['sales_order_id']) ? $_POST['sales_order_id'] : 0;
    $received_date = isset($_POST['received_date']) ? $_POST['received_date'] : date('Y-m-d');
    $location_id = isset($_POST['location_id']) ? $_POST['location_id'] : 0;
    $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
    $items = isset($_POST['items']) ? $_POST['items'] : array();
    
    debug_log("Form data: delivery_note_number=$delivery_note_number, so_id=$sales_order_id, date=$received_date, location=$location_id");
    
    // Validate required fields
    if (empty($delivery_note_number) || empty($sales_order_id) || empty($received_date) || empty($location_id)) {
        debug_log("Validation failed: Missing required fields");
        header('Location: ../delivery_note_create.php?so_id=' . $sales_order_id . '&error=Missing required fields');
        exit;
    }
    
    // Check if items array is empty
    if (empty($items)) {
        debug_log("Validation failed: No items to receive");
        header('Location: ../delivery_note_create.php?so_id=' . $sales_order_id . '&error=No items to receive');
        exit;
    }
    
    // Check if DN number already exists
    $checkDN = "SELECT id FROM delivery_notes WHERE delivery_note_number = :delivery_note_number AND id != :delivery_note_id LIMIT 1";
    $stmtCheckDN = $conn->prepare($checkDN);
    $stmtCheckDN->bindValue(':delivery_note_number', $delivery_note_number);
    $stmtCheckDN->bindValue(':delivery_note_id', $delivery_note_id ?: 0);
    $stmtCheckDN->execute();
    
    if ($stmtCheckDN->fetch(PDO::FETCH_ASSOC)) {
        debug_log("Validation failed: DN number already exists");
        header('Location: ../delivery_note_create.php?so_id=' . $sales_order_id . '&error=DN number already exists');
        exit;
    }
    
    // Begin transaction
    $conn->beginTransaction();
    debug_log("Transaction started");
    
    // Get sales order details and items for per-item calculation
    $soQuery = "SELECT so.*, 
                (SELECT COUNT(*) FROM sales_order_items WHERE sales_order_id = so.id) as total_items 
                FROM sales_orders so 
                WHERE so.id = :so_id LIMIT 1";
    $soStmt = $conn->prepare($soQuery);
    $soStmt->bindValue(':so_id', $sales_order_id);
    $soStmt->execute();
    $soData = $soStmt->fetch(PDO::FETCH_ASSOC);
    
    debug_log("Sales order details: " . print_r($soData, true));
    
    // Get all sales order items to match with DN items
    $soItemsQuery = "SELECT * FROM sales_order_items WHERE sales_order_id = :so_id";
    $soItemsStmt = $conn->prepare($soItemsQuery);
    $soItemsStmt->bindValue(':so_id', $sales_order_id);
    $soItemsStmt->execute();
    $soItems = $soItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create a lookup array for SO items
    $soItemsLookup = [];
    foreach ($soItems as $soItem) {
        $soItemsLookup[$soItem['id']] = $soItem;
    }
    
    // Calculate financial values per item
    $subtotal = 0;
    $taxAmount = 0;
    $discountAmount = 0;
    $deliveryCharges = 0;
    
    // Calculate total value of all SO items for proportional delivery charges
    $soTotalValue = 0;
    $dnTotalValue = 0;
    
    foreach ($soItemsLookup as $soItem) {
        $soTotalValue += floatval($soItem['subtotal']);
    }
    
    foreach ($items as $item) {
        $soItemId = $item['sales_order_item_id'];
        $quantityOrdered = floatval($item['quantity_ordered']);
        $quantityReceived = floatval($item['quantity_received']);
        
        if (isset($soItemsLookup[$soItemId]) && $quantityOrdered > 0) {
            $soItem = $soItemsLookup[$soItemId];
            $ratio = $quantityReceived / $quantityOrdered;
            
            // Calculate per item values
            $itemSubtotal = floatval($soItem['subtotal']) * $ratio;
            $itemTax = floatval($soItem['tax_amount']) * $ratio;
            $itemDiscount = floatval($soItem['discount_amount']) * $ratio;
            
            $dnTotalValue += $itemSubtotal;
            $subtotal += $itemSubtotal;
            $taxAmount += $itemTax;
            $discountAmount += $itemDiscount;
            
            debug_log("Item ID: $soItemId, Ordered: $quantityOrdered, Received: $quantityReceived, Ratio: $ratio");
            debug_log("Item calculations: subtotal=$itemSubtotal, tax=$itemTax, discount=$itemDiscount");
        }
    }
    
    // Calculate delivery charges proportional to the value of items in this DN
    if ($soTotalValue > 0) {
        $deliveryCharges = $soData['delivery_charges'] * ($dnTotalValue / $soTotalValue);
    }
    
    debug_log("Proportional delivery charges calculation: SO total value=$soTotalValue, DN total value=$dnTotalValue");
    debug_log("Delivery charges: " . $soData['delivery_charges'] . " * (" . $dnTotalValue . "/" . $soTotalValue . ") = $deliveryCharges");
    
    $totalAmount = $subtotal + $taxAmount - $discountAmount + $deliveryCharges;
    
    debug_log("Calculated financial values: subtotal=$subtotal, tax=$taxAmount, discount=$discountAmount, delivery=$deliveryCharges, total=$totalAmount");
    
    // Create or update DN
    if ($delivery_note_id) {
        // Update existing DN
        $updateDN = "UPDATE delivery_notes SET 
                      delivery_note_number = :delivery_note_number,
                      sales_order_id = :sales_order_id,
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
                      WHERE id = :delivery_note_id";
                      
        $stmtUpdateDN = $conn->prepare($updateDN);
        $stmtUpdateDN->bindValue(':delivery_note_number', $delivery_note_number);
        $stmtUpdateDN->bindValue(':sales_order_id', $sales_order_id);
        $stmtUpdateDN->bindValue(':received_date', $received_date);
        $stmtUpdateDN->bindValue(':location_id', $location_id);
        $stmtUpdateDN->bindValue(':notes', $notes);
        $stmtUpdateDN->bindValue(':subtotal', $subtotal);
        $stmtUpdateDN->bindValue(':tax_amount', $taxAmount);
        $stmtUpdateDN->bindValue(':discount_amount', $discountAmount);
        $stmtUpdateDN->bindValue(':delivery_charges', $deliveryCharges);
        $stmtUpdateDN->bindValue(':total_amount', $totalAmount);
        $stmtUpdateDN->bindValue(':updated_by', $_SESSION['user_id']);
        $stmtUpdateDN->bindValue(':delivery_note_id', $delivery_note_id);
        
        try {
            $stmtUpdateDN->execute();
            debug_log("DN updated successfully with ID: $delivery_note_id");
        } catch (PDOException $e) {
            debug_log("Error updating DN: " . $e->getMessage());
            throw $e;
        }
    } else {
        // Create new DN
        $insertDN = "INSERT INTO delivery_notes 
                      (delivery_note_number, sales_order_id, received_date, location_id, notes, 
                       subtotal, tax_amount, discount_amount, delivery_charges, total_amount, 
                       status, created_by, created_at) 
                      VALUES 
                      (:delivery_note_number, :sales_order_id, :received_date, :location_id, :notes, 
                       :subtotal, :tax_amount, :discount_amount, :delivery_charges, :total_amount, 
                       'completed', :created_by, NOW())";
                       
        $stmtInsertDN = $conn->prepare($insertDN);
        $stmtInsertDN->bindValue(':delivery_note_number', $delivery_note_number);
        $stmtInsertDN->bindValue(':sales_order_id', $sales_order_id);
        $stmtInsertDN->bindValue(':received_date', $received_date);
        $stmtInsertDN->bindValue(':location_id', $location_id);
        $stmtInsertDN->bindValue(':notes', $notes);
        $stmtInsertDN->bindValue(':subtotal', $subtotal);
        $stmtInsertDN->bindValue(':tax_amount', $taxAmount);
        $stmtInsertDN->bindValue(':discount_amount', $discountAmount);
        $stmtInsertDN->bindValue(':delivery_charges', $deliveryCharges);
        $stmtInsertDN->bindValue(':total_amount', $totalAmount);
        $stmtInsertDN->bindValue(':created_by', $_SESSION['user_id']);
        
        try {
            $stmtInsertDN->execute();
            $delivery_note_id = $conn->lastInsertId();
            debug_log("New DN created with ID: $delivery_note_id");
        } catch (PDOException $e) {
            debug_log("Error creating DN: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Delete existing DN items if updating
    if ($delivery_note_id) {
        $deleteItems = "DELETE FROM delivery_note_items WHERE delivery_note_id = :delivery_note_id";
        $stmtDeleteItems = $conn->prepare($deleteItems);
        $stmtDeleteItems->bindValue(':delivery_note_id', $delivery_note_id);
        
        try {
            $stmtDeleteItems->execute();
            debug_log("Deleted existing DN items for DN ID: $delivery_note_id");
        } catch (PDOException $e) {
            debug_log("Error deleting DN items: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Create inventory transaction for this DN
    $txnNumber = 'TXN-' . date('Ymd') . '-' . rand(100, 999);
    
    // Define the transaction insert SQL with explicit parameter binding for each field
    $insertTxn = "INSERT INTO inventory_transactions 
                  (transaction_number, transaction_date, transaction_type_id, reference_type, reference_id, notes, created_by) 
                  VALUES 
                  (:transaction_number, NOW(), :transaction_type_id, :reference_type, :reference_id, :notes, :created_by)";
    
    debug_log("Transaction SQL: $insertTxn");
    debug_log("Transaction parameters: transaction_number=$txnNumber, transaction_type_id=1, reference_type=DN, reference_id=$delivery_note_id");
    
    // Create a fresh prepared statement
    $stmtInsertTxn = $conn->prepare($insertTxn);
    $stmtInsertTxn->bindValue(':transaction_number', $txnNumber);
    $stmtInsertTxn->bindValue(':transaction_type_id', 1); // 1 = DN receipt
    $stmtInsertTxn->bindValue(':reference_type', 'DN');
    $stmtInsertTxn->bindValue(':reference_id', $delivery_note_id);
    $stmtInsertTxn->bindValue(':notes', "Inventory receipt from DN #$delivery_note_number");
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
    $insertDNItem = "INSERT INTO delivery_note_items 
                      (delivery_note_id, sales_order_item_id, product_id, quantity_ordered, quantity_received, 
                       unit_price, tax_rate, tax_amount, discount_percent, discount_amount, subtotal, total, 
                       batch_number, expiry_date, notes, uom_id, created_at) 
                      VALUES 
                      (:delivery_note_id, :sales_order_item_id, :product_id, :quantity_ordered, :quantity_received, 
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
        $soItemId = isset($item['sales_order_item_id']) ? $item['sales_order_item_id'] : 0;
        $productId = isset($item['product_id']) ? $item['product_id'] : 0;
        $quantityOrdered = isset($item['quantity_ordered']) ? floatval($item['quantity_ordered']) : 0;
        $quantityReceived = isset($item['quantity_received']) ? floatval($item['quantity_received']) : 0;
        $unitPrice = isset($item['unit_price']) ? floatval($item['unit_price']) : 0;
        $batchNumber = isset($item['batch_number']) && !empty($item['batch_number']) ? $item['batch_number'] : 'DEFAULT';
        $expiryDate = isset($item['expiry_date']) && !empty($item['expiry_date']) ? $item['expiry_date'] : null;
        $itemNotes = isset($item['notes']) ? $item['notes'] : '';
        
        // Get the corresponding SO item for financial calculations
        if (isset($soItemsLookup[$soItemId]) && $quantityOrdered > 0 && $quantityReceived > 0) {
            $soItem = $soItemsLookup[$soItemId];
            $ratio = $quantityReceived / $quantityOrdered;
            
            // Get tax and discount values from SO item
            $taxRate = floatval($soItem['tax_rate'] ?? 0);
            $discountPercent = floatval($soItem['discount_percent'] ?? 0);
            
            // Calculate financial values for this item based on the SO item
            $itemSubtotal = floatval($soItem['subtotal']) * $ratio;
            $itemTax = floatval($soItem['tax_amount']) * $ratio;
            $itemDiscount = floatval($soItem['discount_amount']) * $ratio;
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
        
        debug_log("Processing item: product_id=$productId, so_item_id=$soItemId, qty_received=$quantityReceived, batch=$batchNumber");
        
        // Skip items with zero quantity
        if ($quantityReceived <= 0) {
            debug_log("Skipping item with zero or negative quantity");
            continue;
        }
        
        // Insert DN item
        debug_log("SQL for DN item insertion: $insertDNItem");
        debug_log("Binding parameters: delivery_note_id=$delivery_note_id, sales_order_item_id=$soItemId, product_id=$productId, quantity_ordered=$quantityOrdered, quantity_received=$quantityReceived, unit_price=$unitPrice, total=$total, batch_number=$batchNumber");
        
        $stmtInsertDNItem = $conn->prepare($insertDNItem);
        $stmtInsertDNItem->bindValue(':delivery_note_id', $delivery_note_id);
        $stmtInsertDNItem->bindValue(':sales_order_item_id', $soItemId);
        $stmtInsertDNItem->bindValue(':product_id', $productId);
        $stmtInsertDNItem->bindValue(':quantity_ordered', $quantityOrdered);
        $stmtInsertDNItem->bindValue(':quantity_received', $quantityReceived);
        $stmtInsertDNItem->bindValue(':unit_price', $unitPrice);
        $stmtInsertDNItem->bindValue(':tax_rate', $taxRate);
        $stmtInsertDNItem->bindValue(':tax_amount', $itemTax);
        $stmtInsertDNItem->bindValue(':discount_percent', $discountPercent);
        $stmtInsertDNItem->bindValue(':discount_amount', $itemDiscount);
        $stmtInsertDNItem->bindValue(':subtotal', $itemSubtotal);
        $stmtInsertDNItem->bindValue(':total', $total);
        $stmtInsertDNItem->bindValue(':batch_number', $batchNumber);
        $stmtInsertDNItem->bindValue(':expiry_date', $expiryDate);
        $stmtInsertDNItem->bindValue(':notes', $itemNotes);
        
        // Get the UOM ID - first check if provided in the form input
        $formUomId = isset($item['uom_id']) && !empty($item['uom_id']) ? $item['uom_id'] : null;
        
        // Use form UOM ID if available, otherwise get from product
        if (!$formUomId) {
            // Get UOM information for this product
            $uomQuery = "SELECT COALESCE(sales_uom_id, base_uom_id, 1) AS uom_id FROM products WHERE id = :product_id LIMIT 1";
            $stmtUOM = $conn->prepare($uomQuery);
            $stmtUOM->bindValue(':product_id', $productId);
            $stmtUOM->execute();
            $uomData = $stmtUOM->fetch(PDO::FETCH_ASSOC);
            $formUomId = $uomData['uom_id'];
        }
        
        $stmtInsertDNItem->bindValue(':uom_id', $formUomId);
        
        try {
            $stmtInsertDNItem->execute();
            debug_log("DN item inserted successfully");
        
        // Update the received_quantity in sales_order_items
        if ($soItemId > 0) {
            $updateSoItem = "UPDATE sales_order_items SET 
                            received_quantity = received_quantity + :quantity_received
                            WHERE id = :so_item_id";
            $stmtUpdateSoItem = $conn->prepare($updateSoItem);
            $stmtUpdateSoItem->bindValue(':quantity_received', $quantityReceived);
            $stmtUpdateSoItem->bindValue(':so_item_id', $soItemId);
            $stmtUpdateSoItem->execute();
            
            debug_log("Updated received_quantity for SO item ID: $soItemId");
        }
        } catch (PDOException $e) {
            debug_log("Error inserting DN item: " . $e->getMessage());
            throw $e;
        }
        
        // Get UOM information for this product
        $uomQuery = "SELECT base_uom_id, sales_uom_id FROM products WHERE id = :product_id LIMIT 1";
        $stmtUOM = $conn->prepare($uomQuery);
        $stmtUOM->bindValue(':product_id', $productId);
        $stmtUOM->execute();
        $uomData = $stmtUOM->fetch(PDO::FETCH_ASSOC);
        
        // Use sales_uom_id if available, otherwise fall back to base_uom_id
        $uomId = !empty($uomData['sales_uom_id']) ? $uomData['sales_uom_id'] : $uomData['base_uom_id'];
        
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
            // Fall back to product's sales_uom_id or base_uom_id
            $uomQuery = "SELECT COALESCE(sales_uom_id, base_uom_id) AS uom_id FROM products WHERE id = :product_id";
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
        
        // Note: We're not updating sales_order_items as the table doesn't have received_quantity column
        // Instead, we'll track received quantities through the delivery_note_items table
        debug_log("Skipping sales order item update as received_quantity column doesn't exist");
    }
    
    // Update sales order status if all items are fully received
    // First, get the total ordered quantity from sales_order_items
    $checkSOItems = "SELECT SUM(quantity) as total_ordered
                    FROM sales_order_items 
                    WHERE sales_order_id = :so_id";
    
    $stmtCheckSO = $conn->prepare($checkSOItems);
    $stmtCheckSO->bindValue(':so_id', $sales_order_id);
    $stmtCheckSO->execute();
    $soItemData = $stmtCheckSO->fetch(PDO::FETCH_ASSOC);
    $totalOrdered = floatval($soItemData['total_ordered'] ?? 0);
    
    // Then, get the total received quantity from delivery_note_items (only from completed DNs)
    $checkDNItems = "SELECT SUM(dni.quantity_received) as total_received 
                     FROM delivery_note_items dni 
                     JOIN delivery_notes dn ON dni.delivery_note_id = dn.id 
                     WHERE dn.sales_order_id = :so_id AND dn.status = 'completed'";
    
    $stmtCheckDN = $conn->prepare($checkDNItems);
    $stmtCheckDN->bindValue(':so_id', $sales_order_id);
    $stmtCheckDN->execute();
    $dnItemData = $stmtCheckDN->fetch(PDO::FETCH_ASSOC);
    $totalReceived = floatval($dnItemData['total_received'] ?? 0);
    
    debug_log("SO total ordered: $totalOrdered, total received: $totalReceived");
    
    // Update SO status if all items are received
    if ($totalOrdered > 0 && $totalReceived >= $totalOrdered) {
        $updateSO = "UPDATE sales_orders SET status = 'received' WHERE id = :so_id";
        $stmtUpdateSO = $conn->prepare($updateSO);
        $stmtUpdateSO->bindValue(':so_id', $sales_order_id);
        $stmtUpdateSO->execute();
        debug_log("Sales order status updated to 'received'");
    } else {
        $updateSO = "UPDATE sales_orders SET status = 'partial' WHERE id = :so_id AND status != 'received'";
        $stmtUpdateSO = $conn->prepare($updateSO);
        $stmtUpdateSO->bindValue(':so_id', $sales_order_id);
        $stmtUpdateSO->execute();
        debug_log("Sales order status updated to 'partial'");
    }
    
    // Commit the transaction
    $conn->commit();
    debug_log("Transaction committed successfully");
    
    // Redirect to DN detail page
    header("Location: ../delivery_note_detail.php?id=$delivery_note_id&success=DN saved successfully");
    exit;
    
} catch (Exception $e) {
    // Rollback the transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
        debug_log("Transaction rolled back due to error");
    }
    
    debug_log("DN Save Error: " . $e->getMessage());
    debug_log("Error trace: " . $e->getTraceAsString());
    
    // Redirect with error message
    header("Location: ../delivery_note_create.php?so_id=$sales_order_id&error=" . urlencode("Error saving DN: " . $e->getMessage()));
    exit;
}
