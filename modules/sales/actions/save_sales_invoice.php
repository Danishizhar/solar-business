<?php
session_start();
// Disable error reporting for production
error_reporting(0);
ini_set('display_errors', 0);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['sales_add'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        
        // Check for warehouse_id vs location_id mismatch
        if (empty($_POST['warehouse_id']) && !empty($_POST['location_id'])) {
            $_POST['warehouse_id'] = $_POST['location_id'];
        }
        
        // Start transaction
        $conn->beginTransaction();
        
        // Validate required fields with detailed error
        $missing_fields = [];
        if (empty($_POST['customer_id'])) $missing_fields[] = 'customer_id';
        if (empty($_POST['invoice_date'])) $missing_fields[] = 'invoice_date';
        if (empty($_POST['warehouse_id'])) $missing_fields[] = 'warehouse_id/location_id';
        if (empty($_POST['financial_year_id'])) $missing_fields[] = 'financial_year_id';
        
        if (!empty($missing_fields)) {
            throw new Exception("Please fill all required fields: " . implode(', ', $missing_fields));
        }
        
        // Validate items
        if (!isset($_POST['items']) || !is_array($_POST['items']) || count($_POST['items']) == 0) {
            throw new Exception("Please add at least one item");
        }
        
        // Check if we're updating an existing invoice or creating a new one
        $invoice_id = null;
        $invoice_number = null;
        
        if (!empty($_POST['invoice_id'])) {
            // Updating existing invoice
            $invoice_id = $_POST['invoice_id'];
            
            // Get the existing invoice number
            $get_invoice = $conn->prepare("SELECT invoice_number, status FROM sales_invoices WHERE id = :id");
            $get_invoice->bindParam(':id', $invoice_id, PDO::PARAM_INT);
            $get_invoice->execute();
            $existing_invoice = $get_invoice->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing_invoice) {
                throw new Exception("Invoice not found");
            }
            
            $invoice_number = $existing_invoice['invoice_number'];
            $old_status = $existing_invoice['status'];
            
            // Update sales invoice header
            $stmt = $conn->prepare("
                UPDATE sales_invoices SET
                    customer_id = :customer_id,
                    invoice_date = :invoice_date,
                    due_date = :due_date,
                    location_id = :location_id,
                    sales_order_id = :sales_order_id,
                    delivery_note_id = :delivery_note_id,
                    subtotal = :subtotal,
                    discount_amount = :discount_amount,
                    tax_amount = :tax_amount,
                    delivery_charges = :delivery_charges,
                    total_amount = :total_amount,
                    balance_amount = :balance_amount,
                    payment_terms = :payment_terms,
                    notes = :notes,
                    status = :status,
                    updated_by = :updated_by,
                    updated_at = NOW(),
                    financial_year_id = :financial_year_id
                WHERE id = :invoice_id
            ");
            
            // Delete existing invoice items
            $delete_items = $conn->prepare("DELETE FROM sales_invoice_items WHERE sales_invoice_id = :invoice_id");
            $delete_items->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
            $delete_items->execute();
        } else {
            // Creating new invoice
            // Generate invoice number
            $year_month = date('Ym', strtotime($_POST['invoice_date']));
            $number_query = "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) as last_number 
                            FROM sales_invoices 
                            WHERE invoice_number LIKE :invoice_pattern";
            $stmt = $conn->prepare($number_query);
            $invoice_pattern = "SI-$year_month-%";
            $stmt->bindParam(':invoice_pattern', $invoice_pattern, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $next_number = str_pad(($row['last_number'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
            $invoice_number = "SI-$year_month-$next_number";
            
            // Insert sales invoice header
            $stmt = $conn->prepare("
                INSERT INTO sales_invoices (
                    invoice_number, customer_id, invoice_date, due_date, 
                    location_id, sales_order_id, delivery_note_id,
                    subtotal, discount_amount, tax_amount, delivery_charges,
                    total_amount, paid_amount, balance_amount, payment_status,
                    payment_terms, notes, status, created_by, created_at,
                    financial_year_id
                ) VALUES (
                    :invoice_number, :customer_id, :invoice_date, :due_date, 
                    :location_id, :sales_order_id, :delivery_note_id,
                    :subtotal, :discount_amount, :tax_amount, :delivery_charges,
                    :total_amount, :paid_amount, :balance_amount, :payment_status,
                    :payment_terms, :notes, :status, :created_by, NOW(),
                    :financial_year_id
                )
            ");
        }
        
        // Common parameters for both insert and update
        $stmt->bindParam(':customer_id', $_POST['customer_id'], PDO::PARAM_INT);
        $stmt->bindParam(':invoice_date', $_POST['invoice_date'], PDO::PARAM_STR);
        $stmt->bindParam(':due_date', $_POST['due_date'], PDO::PARAM_STR);
        $stmt->bindParam(':location_id', $_POST['location_id'], PDO::PARAM_INT);
        $sales_order_id = $_POST['sales_order_id'] ?? null;
        $stmt->bindParam(':sales_order_id', $sales_order_id, PDO::PARAM_INT);
        $delivery_note_id = $_POST['delivery_note_id'] ?? null;
        $stmt->bindParam(':delivery_note_id', $delivery_note_id, PDO::PARAM_INT);
        $stmt->bindParam(':subtotal', $_POST['subtotal'], PDO::PARAM_STR);
        $stmt->bindParam(':discount_amount', $_POST['discount_amount'], PDO::PARAM_STR);
        $stmt->bindParam(':tax_amount', $_POST['tax_amount'], PDO::PARAM_STR);
        $delivery_charges = $_POST['delivery_charges'] ?? 0;
        $stmt->bindParam(':delivery_charges', $delivery_charges, PDO::PARAM_STR);
        $stmt->bindParam(':total_amount', $_POST['total_amount'], PDO::PARAM_STR);
        $stmt->bindParam(':balance_amount', $_POST['total_amount'], PDO::PARAM_STR);
        $payment_terms = $_POST['payment_terms'] ?? null;
        $stmt->bindParam(':payment_terms', $payment_terms, PDO::PARAM_STR);
        $notes = $_POST['notes'] ?? null;
        $stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
        // Get status from POST or default to 'approved'
        $status = isset($_POST['status']) && !empty($_POST['status']) ? $_POST['status'] : 'approved';
        
        // Explicitly set status as string to avoid type issues
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindParam(':financial_year_id', $_POST['financial_year_id'], PDO::PARAM_INT);
        
        if (!empty($_POST['invoice_id'])) {
            // For update query
            $stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
            $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        } else {
            // For insert query
            $stmt->bindParam(':invoice_number', $invoice_number, PDO::PARAM_STR);
            $paid_amount = 0;
            $stmt->bindParam(':paid_amount', $paid_amount, PDO::PARAM_STR);
            $payment_status = 'unpaid';
            $stmt->bindParam(':payment_status', $payment_status, PDO::PARAM_STR);
            $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        }
        
        try {
            // Debug SQL statement before execution
            $debug_sql = $stmt->queryString;
            error_log("About to execute SQL: " . $debug_sql);
            
            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo();
                error_log("SQL execution failed: " . print_r($errorInfo, true));
                throw new PDOException("Error creating sales invoice: " . $errorInfo[2]);
            } else {
                error_log("SQL executed successfully");
            }
        } catch (PDOException $e) {
            error_log("PDO Exception: " . $e->getMessage());
            throw $e;
        }
        
        // If this is a new invoice, get the new invoice_id
        if (empty($_POST['invoice_id'])) {
            $invoice_id = $conn->lastInsertId();
        }
        
        // Insert sales invoice items
        $item_stmt = $conn->prepare("
            INSERT INTO sales_invoice_items (
                sales_invoice_id, product_id, quantity, unit_price, 
                discount_percent, discount_amount, tax_rate, tax_amount,
                total, batch_number, expiry_date, uom_id,
                created_by, created_at
            ) VALUES (
                :sales_invoice_id, :product_id, :quantity, :unit_price, 
                :discount_percent, :discount_amount, :tax_rate, :tax_amount,
                :total, :batch_number, :expiry_date, :uom_id,
                :created_by, NOW()
            )
        ");
        
        // Process each item
        foreach ($_POST['items'] as $item) {
            if (empty($item['product_id']) || empty($item['quantity']) || empty($item['unit_price'])) {
                continue;
            }
            
            $item_stmt->bindParam(':sales_invoice_id', $invoice_id, PDO::PARAM_INT);
            $item_stmt->bindParam(':product_id', $item['product_id'], PDO::PARAM_INT);
            $item_stmt->bindParam(':quantity', $item['quantity'], PDO::PARAM_STR);
            $item_stmt->bindParam(':unit_price', $item['unit_price'], PDO::PARAM_STR);
            $discount_percent = $item['discount_percent'] ?? 0;
            $item_stmt->bindParam(':discount_percent', $discount_percent, PDO::PARAM_STR);
            $discount_amount = ($item['quantity'] * $item['unit_price']) * ($discount_percent / 100);
            $item_stmt->bindParam(':discount_amount', $discount_amount, PDO::PARAM_STR);
            $tax_rate = $item['tax_rate'] ?? 0;
            $item_stmt->bindParam(':tax_rate', $tax_rate, PDO::PARAM_STR);
            $tax_amount = (($item['quantity'] * $item['unit_price']) - $discount_amount) * ($tax_rate / 100);
            $item_stmt->bindParam(':tax_amount', $tax_amount, PDO::PARAM_STR);
            $total_amount = ($item['quantity'] * $item['unit_price']) - $discount_amount + $tax_amount;
            $item_stmt->bindParam(':total', $total_amount, PDO::PARAM_STR);
            $batch_number = $item['batch_number'] ?? null;
            $item_stmt->bindParam(':batch_number', $batch_number, PDO::PARAM_STR);
            $expiry_date = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
            $item_stmt->bindParam(':expiry_date', $expiry_date, PDO::PARAM_STR);
            $uom_id = $item['uom_id'] ?? 1;
            $item_stmt->bindParam(':uom_id', $uom_id, PDO::PARAM_INT);
            $item_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
            
            if (!$item_stmt->execute()) {
                throw new PDOException("Error saving invoice item");
            }
            
            // Only update inventory and create transactions if status is approved
            // For existing invoices, only create transactions if status changed from draft to approved
            $should_create_transactions = false;
            
            if (!empty($_POST['invoice_id'])) {
                // For existing invoices, check if status changed from draft to approved
                if (($status == 'approved') && $old_status == 'draft') {
                    $should_create_transactions = true;
                }
            } else if ($status == 'approved') {
                // For new invoices with approved status
                $should_create_transactions = true;
            }
            
            if ($should_create_transactions) {
                // Update inventory stock
                updateInventoryStock(
                    $conn, 
                    $item['product_id'], 
                    $_POST['warehouse_id'], 
                    $item['quantity'], 
                    $item['unit_price'],
                    $batch_number,
                    $expiry_date,
                    $uom_id
                );
                
                // Create inventory transaction
                createInventoryTransaction(
                    $conn,
                    $invoice_id,
                    $invoice_number,
                    $_POST['invoice_date'],
                    $item['product_id'],
                    $_POST['warehouse_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $batch_number,
                    $expiry_date,
                    $uom_id
                );
            }
        }
        
        // Only create accounting entries if status is approved and either:
        // 1. It's a new invoice, or
        // 2. Status changed from draft to approved
        $should_create_accounting = false;
        
        if (!empty($_POST['invoice_id'])) {
            // For existing invoices, check if status changed from draft to approved
            if ($status == 'approved' && $old_status == 'draft') {
                $should_create_accounting = true;
            }
        } else if ($status == 'approved') {
            // For new invoices with approved status
            $should_create_accounting = true;
        }
        
        if ($should_create_accounting) {
            // Create accounting entries
            createAccountingEntries(
                $conn,
                $invoice_id,
                $invoice_number,
                $_POST['invoice_date'],
                $_POST['customer_id'],
                $_POST['total_amount'],
                "Sales Invoice: $invoice_number"
            );
        }
        
        // Commit transaction
        $conn->commit();
        
        // Redirect to sales invoice detail page
        header("Location: ../sales_invoice_detail.php?id=" . $invoice_id);
        exit();
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in save_sales_invoice.php: " . $e->getMessage());
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

// Helper function to update inventory stock
function updateInventoryStock($conn, $product_id, $location_id, $quantity, $unit_cost, $batch_number = null, $expiry_date = null, $uom_id = null) {
    // Check if stock exists for this product, location and batch
    $check_sql = "SELECT id, quantity, available_quantity, unit_cost, total_value 
                 FROM inventory_stock 
                 WHERE product_id = :product_id 
                 AND location_id = :location_id";
    
    // Handle empty batch numbers properly
    if (!empty($batch_number)) {
        $check_sql .= " AND batch_number = :batch_number";
    } else {
        $check_sql .= " AND (batch_number IS NULL OR batch_number = '')";
    }
    
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $check_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
    
    if (!empty($batch_number)) {
        $check_stmt->bindParam(':batch_number', $batch_number, PDO::PARAM_STR);
    }
    
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        // Update existing stock
        $stock = $check_stmt->fetch(PDO::FETCH_ASSOC);
        $new_quantity = $stock['quantity'] + $quantity;
        $new_available = $stock['available_quantity'] + $quantity;
        
        // Calculate weighted average cost
        $total_old_value = $stock['quantity'] * $stock['unit_cost'];
        $total_new_value = $quantity * $unit_cost;
        $new_total_value = $total_old_value + $total_new_value;
        $new_unit_cost = $new_quantity > 0 ? $new_total_value / $new_quantity : $unit_cost;
        
        $update_sql = "UPDATE inventory_stock 
                      SET quantity = :quantity, 
                          available_quantity = :available_quantity, 
                          unit_cost = :unit_cost, 
                          total_value = :total_value, 
                          updated_at = NOW(), 
                          updated_by = :updated_by";
        
        if ($expiry_date && !$stock['expiry_date']) {
            $update_sql .= ", expiry_date = :expiry_date";
        }
        
        $update_sql .= " WHERE id = :id";
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bindParam(':quantity', $new_quantity, PDO::PARAM_STR);
        $update_stmt->bindParam(':available_quantity', $new_available, PDO::PARAM_STR);
        $update_stmt->bindParam(':unit_cost', $new_unit_cost, PDO::PARAM_STR);
        $update_stmt->bindParam(':total_value', $new_total_value, PDO::PARAM_STR);
        $update_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_stmt->bindParam(':id', $stock['id'], PDO::PARAM_INT);
        
        if ($expiry_date && !$stock['expiry_date']) {
            $update_stmt->bindParam(':expiry_date', $expiry_date, PDO::PARAM_STR);
        }
        
        $update_stmt->execute();
    } else {
        // Insert new stock
        $insert_sql = "INSERT INTO inventory_stock 
                      (product_id, location_id, batch_number, expiry_date, 
                       quantity, available_quantity, unit_cost, total_value, 
                       created_by, created_at) 
                      VALUES 
                      (:product_id, :location_id, :batch_number, :expiry_date, 
                       :quantity, :available_quantity, :unit_cost, :total_value, 
                       :created_by, NOW())";
        
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $insert_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
        
        // Set batch_number to NULL if it's empty
        if (empty($batch_number)) {
            $insert_stmt->bindValue(':batch_number', null, PDO::PARAM_NULL);
        } else {
            $insert_stmt->bindParam(':batch_number', $batch_number, PDO::PARAM_STR);
        }
        
        $insert_stmt->bindParam(':expiry_date', $expiry_date, PDO::PARAM_STR);
        $insert_stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
        $insert_stmt->bindParam(':available_quantity', $quantity, PDO::PARAM_STR);
        $insert_stmt->bindParam(':unit_cost', $unit_cost, PDO::PARAM_STR);
        $total_value = $quantity * $unit_cost;
        $insert_stmt->bindParam(':total_value', $total_value, PDO::PARAM_STR);
        $insert_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        
        $insert_stmt->execute();
    }
}

// Helper function to create inventory transaction
function createInventoryTransaction($conn, $invoice_id, $invoice_number, $date, $product_id, $location_id, $quantity, $unit_cost, $batch_number = null, $expiry_date = null, $uom_id = null) {
    // If no UOM ID is provided, get the product's sales UOM ID or fall back to base UOM ID
    if (empty($uom_id)) {
        $product_query = "SELECT sales_uom_id, base_uom_id FROM products WHERE id = :product_id";
        $product_stmt = $conn->prepare($product_query);
        $product_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $product_stmt->execute();
        $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product && !empty($product['sales_uom_id'])) {
            $uom_id = $product['sales_uom_id'];
        } elseif ($product && !empty($product['base_uom_id'])) {
            $uom_id = $product['base_uom_id'];
        } else {
            // Default to 1 if no UOM is found
            $uom_id = 1;
        }
    }
    // Insert inventory transaction
    $transaction_sql = "INSERT INTO inventory_transactions 
                       (transaction_number, transaction_date, transaction_type_id, 
                        reference_type, reference_id, notes, created_by, created_at) 
                       VALUES 
                       (:transaction_number, :transaction_date, :transaction_type_id, 
                        :reference_type, :reference_id, :notes, :created_by, NOW())";
    
    $transaction_stmt = $conn->prepare($transaction_sql);
    $transaction_number = "IT-" . date('YmdHis') . "-" . rand(1000, 9999);
    $transaction_stmt->bindParam(':transaction_number', $transaction_number, PDO::PARAM_STR);
    $transaction_stmt->bindParam(':transaction_date', $date, PDO::PARAM_STR);
    $transaction_type_id = 1; // Assuming 1 is for Sales Invoice
    $transaction_stmt->bindParam(':transaction_type_id', $transaction_type_id, PDO::PARAM_INT);
    $reference_type = 'sales_invoice';
    $transaction_stmt->bindParam(':reference_type', $reference_type, PDO::PARAM_STR);
    $transaction_stmt->bindParam(':reference_id', $invoice_id, PDO::PARAM_INT);
    $notes = "Sales Invoice: $invoice_number";
    $transaction_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
    $transaction_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    $transaction_stmt->execute();
    $transaction_id = $conn->lastInsertId();
    
    // Insert transaction details
    $detail_sql = "INSERT INTO inventory_transaction_details 
                  (transaction_id, product_id, to_location_id, quantity, 
                   uom_id, unit_cost, total_cost, batch_number, expiry_date, 
                   created_by, created_at) 
                  VALUES 
                  (:transaction_id, :product_id, :to_location_id, :quantity, 
                   :uom_id, :unit_cost, :total_cost, :batch_number, :expiry_date, 
                   :created_by, NOW())";
    
    $detail_stmt = $conn->prepare($detail_sql);
    $detail_stmt->bindParam(':transaction_id', $transaction_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':to_location_id', $location_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
    $detail_stmt->bindParam(':uom_id', $uom_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':unit_cost', $unit_cost, PDO::PARAM_STR);
    $total_cost = $quantity * $unit_cost;
    $detail_stmt->bindParam(':total_cost', $total_cost, PDO::PARAM_STR);
    $detail_stmt->bindParam(':batch_number', $batch_number, PDO::PARAM_STR);
    $detail_stmt->bindParam(':expiry_date', $expiry_date, PDO::PARAM_STR);
    $detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    $detail_stmt->execute();
}

// Helper function to create accounting entries
function createAccountingEntries($conn, $invoice_id, $invoice_number, $date, $customer_id, $total_amount, $description) {
    // Always ensure description includes the invoice number
    if (empty($description) || !strstr($description, $invoice_number)) {
        $description = "Sales Invoice: $invoice_number";
    }
    // Get sales invoice details to get tax, discount and delivery amounts
    $invoice_query = "SELECT subtotal, discount_amount, tax_amount, delivery_charges FROM sales_invoices WHERE id = :invoice_id";
    $invoice_stmt = $conn->prepare($invoice_query);
    $invoice_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $invoice_stmt->execute();
    $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotal = $invoice['subtotal'];
    $discount_amount = $invoice['discount_amount'];
    $tax_amount = $invoice['tax_amount'];
    $delivery_charges = $invoice['delivery_charges'];
    
    // Get the financial year ID from the form submission
    $financial_year_id = $_POST['financial_year_id'] ?? null;
    
    if (empty($financial_year_id)) {
        throw new Exception("Financial year must be selected.");
    }
    
    // Get the financial year details
    $financial_year_query = "SELECT id, year_code, start_date, end_date, is_closed 
                           FROM financial_years 
                           WHERE id = :financial_year_id";
    $fy_stmt = $conn->prepare($financial_year_query);
    $fy_stmt->bindParam(':financial_year_id', $financial_year_id, PDO::PARAM_INT);
    $fy_stmt->execute();
    $financial_year = $fy_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$financial_year) {
        throw new Exception("Selected financial year not found.");
    }
    
    // Validate that the invoice date falls within the selected financial year
    $invoice_date = new DateTime($date);
    $start_date = new DateTime($financial_year['start_date']);
    $end_date = new DateTime($financial_year['end_date']);
    
    if ($invoice_date < $start_date || $invoice_date > $end_date) {
        throw new Exception("Invoice date must be within the selected financial year's date range: " . 
                          date('d M Y', strtotime($financial_year['start_date'])) . ' to ' . 
                          date('d M Y', strtotime($financial_year['end_date'])));
    }
    
    if ($financial_year['is_closed']) {
        throw new Exception("Cannot create accounting entries in closed financial year {$financial_year['year_code']}.");
    }
    
    $financial_year_id = $financial_year['id'];
    $year_code = $financial_year['year_code'];
    
    // Generate entry number (Format: SI-FY-XXXX) where FY is the financial year code
    $number_query = "SELECT MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)) as last_number 
                    FROM journal_entries 
                    WHERE entry_number LIKE :entry_pattern 
                    AND financial_year_id = :financial_year_id";
    $stmt = $conn->prepare($number_query);
    $entry_pattern = "SI-$year_code-%";
    $stmt->bindParam(':entry_pattern', $entry_pattern, PDO::PARAM_STR);
    $stmt->bindParam(':financial_year_id', $financial_year_id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_number = str_pad(($row['last_number'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
    $entry_number = "SI-$year_code-$next_number";
    
    // Get customer account ID
    $customer_query = "SELECT account_id FROM customers WHERE id = :customer_id";
    $customer_stmt = $conn->prepare($customer_query);
    $customer_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
    $customer_stmt->execute();
    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
    $customer_account_id = $customer['account_id'];
    
    // Get inventory account ID from inventory settings
    $inventory_settings = [];
    $inventory_query = "SELECT setting_key, setting_value FROM inventory_settings WHERE setting_group = 'accounting'";
    $inventory_stmt = $conn->prepare($inventory_query);
    $inventory_stmt->execute();
    while ($row = $inventory_stmt->fetch(PDO::FETCH_ASSOC)) {
        $inventory_settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Get inventory asset account ID
    $inventory_account_id = $inventory_settings['inventory_asset_account_id'] ?? null;
    if (empty($inventory_account_id)) {
        throw new Exception("Inventory Asset Account is not configured in Inventory Settings");
    }
    
    // Get sales settings for accounts receivable, tax, discount and delivery expense accounts
    $sales_settings = [];
    $settings_query = "SELECT setting_key, setting_value FROM sales_settings WHERE setting_group = 'accounting'";
    $settings_stmt = $conn->prepare($settings_query);
    $settings_stmt->execute();
    while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
        $sales_settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Get account IDs from settings or use defaults
    // First check if customer has an account_id, if not then use accounts_receivable from settings
    $receivable_account_id = !empty($customer_account_id) ? $customer_account_id : ($sales_settings['accounts_receivable_account_id'] ?? null);
    if (empty($receivable_account_id)) {
        throw new Exception("Accounts Receivable Account is not configured. Either set an account for the customer or configure a default in Sales Settings");
    }
    
    $tax_account_id = $sales_settings['sales_tax_account_id'] ?? null;
    if ($tax_amount > 0 && empty($tax_account_id)) {
        throw new Exception("Sales Tax Account is not configured in Sales Settings");
    }
    
    $discount_account_id = $sales_settings['sales_discount_account_id'] ?? null;
    if ($discount_amount > 0 && empty($discount_account_id)) {
        throw new Exception("Sales Discount Account is not configured in Sales Settings");
    }
    
    $delivery_income_account_id = $sales_settings['delivery_income_account_id'] ?? null;
    if ($delivery_charges > 0 && empty($delivery_income_account_id)) {
        throw new Exception("Delivery Income Account is not configured in Sales Settings");
    }
    
    // Insert journal entry header
    $stmt = $conn->prepare("
        INSERT INTO journal_entries (
            entry_number, entry_date, entry_type, reference_number, 
            description, total_debit, total_credit,
            status, financial_year_id, created_by, created_at
        ) VALUES (
            :entry_number, :entry_date, 'Sales', :reference_number, 
            :description, :total_debit, :total_credit,
            'approved', :financial_year_id, :created_by, NOW()
        )
    ");
    
    $stmt->bindParam(':entry_number', $entry_number, PDO::PARAM_STR);
    $stmt->bindParam(':entry_date', $date, PDO::PARAM_STR);
    $stmt->bindParam(':reference_number', $invoice_number, PDO::PARAM_STR);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->bindParam(':total_debit', $total_amount, PDO::PARAM_STR);
    $stmt->bindParam(':total_credit', $total_amount, PDO::PARAM_STR);
    $stmt->bindParam(':financial_year_id', $financial_year_id, PDO::PARAM_INT);
    $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    $stmt->execute();
    $entry_id = $conn->lastInsertId();
    
    // Insert journal entry details
    $detail_stmt = $conn->prepare("
        INSERT INTO journal_entry_details (
            journal_entry_id, account_id, description, 
            debit_amount, credit_amount, created_by,
            created_at
        ) VALUES (
            :journal_entry_id, :account_id, :description,
            :debit_amount, :credit_amount, :created_by,
            NOW()
        )
    ");
    
    // Common parameters for all entries
    $detail_stmt->bindParam(':journal_entry_id', $entry_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    // 1. Inventory Account (Debit) - Always present
    $inventory_description = "Inventory sale: $invoice_number";
    // Net inventory value - we debit the inventory account for the subtotal amount
    // Discount is handled separately as a credit to the discount account
    $inventory_debit = $subtotal;
    $inventory_credit = 0;
    
    $detail_stmt->bindParam(':account_id', $inventory_account_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':description', $inventory_description, PDO::PARAM_STR);
    $detail_stmt->bindParam(':debit_amount', $inventory_debit, PDO::PARAM_STR);
    $detail_stmt->bindParam(':credit_amount', $inventory_credit, PDO::PARAM_STR);
    $detail_stmt->execute();
    
    // 2. Tax Account (Debit) - If tax amount exists and tax account is configured
    if ($tax_amount > 0 && $tax_account_id) {
        $tax_description = "Sales tax: $invoice_number";
        $tax_debit = $tax_amount;
        $tax_credit = 0;
        
        $detail_stmt->bindParam(':account_id', $tax_account_id, PDO::PARAM_INT);
        $detail_stmt->bindParam(':description', $tax_description, PDO::PARAM_STR);
        $detail_stmt->bindParam(':debit_amount', $tax_debit, PDO::PARAM_STR);
        $detail_stmt->bindParam(':credit_amount', $tax_credit, PDO::PARAM_STR);
        $detail_stmt->execute();
    }
    
    // 3. Delivery Income Account (Debit) - If delivery charge exists and account is configured
    if ($delivery_charges > 0 && $delivery_income_account_id) {
        $delivery_description = "Delivery charge: $invoice_number";
        $delivery_debit = $delivery_charges;
        $delivery_credit = 0;
        
        $detail_stmt->bindParam(':account_id', $delivery_income_account_id, PDO::PARAM_INT);
        $detail_stmt->bindParam(':description', $delivery_description, PDO::PARAM_STR);
        $detail_stmt->bindParam(':debit_amount', $delivery_debit, PDO::PARAM_STR);
        $detail_stmt->bindParam(':credit_amount', $delivery_credit, PDO::PARAM_STR);
        $detail_stmt->execute();
    }
    
    // 4. Accounts Receivable Account (Credit) - Always present
    $receivable_description = "Amount receivable to customer: $invoice_number";
    $receivable_debit = 0;
    $receivable_credit = $total_amount; // Total invoice amount including tax and delivery
    
    $detail_stmt->bindParam(':account_id', $receivable_account_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':description', $receivable_description, PDO::PARAM_STR);
    $detail_stmt->bindParam(':debit_amount', $receivable_debit, PDO::PARAM_STR);
    $detail_stmt->bindParam(':credit_amount', $receivable_credit, PDO::PARAM_STR);
    $detail_stmt->execute();
    
    // 5. Discount Account (Credit) - If discount amount exists and discount account is configured
    if ($discount_amount > 0 && $discount_account_id) {
        $discount_description = "Sales discount: $invoice_number";
        $discount_debit = 0;
        $discount_credit = $discount_amount;
        
        $detail_stmt->bindParam(':account_id', $discount_account_id, PDO::PARAM_INT);
        $detail_stmt->bindParam(':description', $discount_description, PDO::PARAM_STR);
        $detail_stmt->bindParam(':debit_amount', $discount_debit, PDO::PARAM_STR);
        $detail_stmt->bindParam(':credit_amount', $discount_credit, PDO::PARAM_STR);
        $detail_stmt->execute();
    }
    
    return $entry_id;
}

// Fallback function for creating accounting entries when financial year system is not available
function createAccountingEntriesWithoutFinancialYear($conn, $invoice_id, $invoice_number, $date, $customer_id, $total_amount, $description) {
    // Get sales invoice details to get tax, discount and delivery amounts
    $invoice_query = "SELECT subtotal, discount_amount, tax_amount, delivery_charges FROM sales_invoices WHERE id = :invoice_id";
    $invoice_stmt = $conn->prepare($invoice_query);
    $invoice_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $invoice_stmt->execute();
    $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotal = $invoice['subtotal'];
    $discount_amount = $invoice['discount_amount'];
    $tax_amount = $invoice['tax_amount'];
    $delivery_charges = $invoice['delivery_charges'];
    
    // Generate entry number (Format: SI-YYYYMM-XXXX) - original format without financial year
    $year_month = date('Ym', strtotime($date));
    $number_query = "SELECT MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)) as last_number 
                    FROM journal_entries 
                    WHERE entry_number LIKE :entry_pattern";
    $stmt = $conn->prepare($number_query);
    $entry_pattern = "SI-$year_month-%";
    $stmt->bindParam(':entry_pattern', $entry_pattern, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_number = str_pad(($row['last_number'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
    $entry_number = "SI-$year_month-$next_number";
    
    // Get customer account ID
    $customer_query = "SELECT account_id FROM customers WHERE id = :customer_id";
    $customer_stmt = $conn->prepare($customer_query);
    $customer_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
    $customer_stmt->execute();
    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
    $customer_account_id = $customer['account_id'];
    
    // Get inventory account ID from inventory settings
    $inventory_settings = [];
    $inventory_query = "SELECT setting_key, setting_value FROM inventory_settings WHERE setting_group = 'accounting'";
    $inventory_stmt = $conn->prepare($inventory_query);
    $inventory_stmt->execute();
    while ($row = $inventory_stmt->fetch(PDO::FETCH_ASSOC)) {
        $inventory_settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Get inventory asset account ID
    $inventory_account_id = $inventory_settings['inventory_asset_account_id'] ?? null;
    if (empty($inventory_account_id)) {
        throw new Exception("Inventory Asset Account is not configured in Inventory Settings");
    }
    
    // Get sales settings for accounts receivable, tax, discount and delivery expense accounts
    $sales_settings = [];
    $settings_query = "SELECT setting_key, setting_value FROM sales_settings WHERE setting_group = 'accounting'";
    $settings_stmt = $conn->prepare($settings_query);
    $settings_stmt->execute();
    while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
        $sales_settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Get account IDs from settings or use defaults
    // First check if customer has an account_id, if not then use accounts_receivable from settings
    $receivable_account_id = !empty($customer_account_id) ? $customer_account_id : ($sales_settings['accounts_receivable_account_id'] ?? null);
    if (empty($receivable_account_id)) {
        throw new Exception("Accounts Receivable Account is not configured. Either set an account for the customer or configure a default in Sales Settings");
    }
    
    $tax_account_id = $sales_settings['sales_tax_account_id'] ?? null;
    if ($tax_amount > 0 && empty($tax_account_id)) {
        throw new Exception("Sales Tax Account is not configured in Sales Settings");
    }
    
    $discount_account_id = $sales_settings['sales_discount_account_id'] ?? null;
    if ($discount_amount > 0 && empty($discount_account_id)) {
        throw new Exception("Sales Discount Account is not configured in Sales Settings");
    }
    
    $delivery_income_account_id = $sales_settings['delivery_income_account_id'] ?? null;
    if ($delivery_charges > 0 && empty($delivery_income_account_id)) {
        throw new Exception("Delivery Income Account is not configured in Sales Settings");
    }
    
    // Check if financial_year_id column exists in journal_entries table
    $column_check = $conn->query("SHOW COLUMNS FROM journal_entries LIKE 'financial_year_id'");
    $has_financial_year_column = ($column_check->rowCount() > 0);
    
    // Insert journal entry header - with or without financial_year_id based on column existence
    if ($has_financial_year_column) {
        $stmt = $conn->prepare("
            INSERT INTO journal_entries (
                entry_number, entry_date, entry_type, reference_number, 
                description, total_debit, total_credit,
                status, created_by, created_at
            ) VALUES (
                :entry_number, :entry_date, 'sales_invoice', :reference_number, 
                :description, :total_debit, :total_credit,
                'approved', :created_by, NOW()
            )
        ");
    } else {
        $stmt = $conn->prepare("
            INSERT INTO journal_entries (
                entry_number, entry_date, entry_type, reference_number, 
                description, total_debit, total_credit,
                status, created_by, created_at
            ) VALUES (
                :entry_number, :entry_date, 'sales_invoice', :reference_number, 
                :description, :total_debit, :total_credit,
                'approved', :created_by, NOW()
            )
        ");
    }
    
    $stmt->bindParam(':entry_number', $entry_number, PDO::PARAM_STR);
    $stmt->bindParam(':entry_date', $date, PDO::PARAM_STR);
    $stmt->bindParam(':reference_number', $invoice_number, PDO::PARAM_STR);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->bindParam(':total_debit', $total_amount, PDO::PARAM_STR);
    $stmt->bindParam(':total_credit', $total_amount, PDO::PARAM_STR);
    $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    $stmt->execute();
    $entry_id = $conn->lastInsertId();
    
    // Insert journal entry details
    $detail_stmt = $conn->prepare("
        INSERT INTO journal_entry_details (
            journal_entry_id, account_id, description, 
            debit_amount, credit_amount, created_by,
            created_at
        ) VALUES (
            :journal_entry_id, :account_id, :description,
            :debit_amount, :credit_amount, :created_by,
            NOW()
        )
    ");
    
    // Common parameters for all entries
    $detail_stmt->bindParam(':journal_entry_id', $entry_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    // 1. Inventory Account (Debit) - Always present
    $inventory_description = "Inventory sale: $invoice_number";
    // Net inventory value - we debit the inventory account for the subtotal amount
    // Discount is handled separately as a credit to the discount account
    $inventory_debit = $subtotal;
    $inventory_credit = 0;
    
    $detail_stmt->bindParam(':account_id', $inventory_account_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':description', $inventory_description, PDO::PARAM_STR);
    $detail_stmt->bindParam(':debit_amount', $inventory_debit, PDO::PARAM_STR);
    $detail_stmt->bindParam(':credit_amount', $inventory_credit, PDO::PARAM_STR);
    $detail_stmt->execute();
    
    // 2. Tax Account (Debit) - If tax amount exists and tax account is configured
    if ($tax_amount > 0 && $tax_account_id) {
        $tax_description = "Sales tax: $invoice_number";
        $tax_debit = $tax_amount;
        $tax_credit = 0;
        
        $detail_stmt->bindParam(':account_id', $tax_account_id, PDO::PARAM_INT);
        $detail_stmt->bindParam(':description', $tax_description, PDO::PARAM_STR);
        $detail_stmt->bindParam(':debit_amount', $tax_debit, PDO::PARAM_STR);
        $detail_stmt->bindParam(':credit_amount', $tax_credit, PDO::PARAM_STR);
        $detail_stmt->execute();
    }
    
    // 3. Delivery Income Account (Debit) - If delivery charge exists and account is configured
    if ($delivery_charges > 0 && $delivery_income_account_id) {
        $delivery_description = "Delivery charge: $invoice_number";
        $delivery_debit = $delivery_charges;
        $delivery_credit = 0;
        
        $detail_stmt->bindParam(':account_id', $delivery_income_account_id, PDO::PARAM_INT);
        $detail_stmt->bindParam(':description', $delivery_description, PDO::PARAM_STR);
        $detail_stmt->bindParam(':debit_amount', $delivery_debit, PDO::PARAM_STR);
        $detail_stmt->bindParam(':credit_amount', $delivery_credit, PDO::PARAM_STR);
        $detail_stmt->execute();
    }
    
    // 4. Accounts Receivable Account (Credit) - Always present
    $receivable_description = "Amount receivable to customer: $invoice_number";
    $receivable_debit = 0;
    $receivable_credit = $total_amount; // Total invoice amount including tax and delivery
    
    $detail_stmt->bindParam(':account_id', $receivable_account_id, PDO::PARAM_INT);
    $detail_stmt->bindParam(':description', $receivable_description, PDO::PARAM_STR);
    $detail_stmt->bindParam(':debit_amount', $receivable_debit, PDO::PARAM_STR);
    $detail_stmt->bindParam(':credit_amount', $receivable_credit, PDO::PARAM_STR);
    $detail_stmt->execute();
    
    // 5. Discount Account (Credit) - If discount amount exists and discount account is configured
    if ($discount_amount > 0 && $discount_account_id) {
        $discount_description = "Sales discount: $invoice_number";
        $discount_debit = 0;
        $discount_credit = $discount_amount;
        
        $detail_stmt->bindParam(':account_id', $discount_account_id, PDO::PARAM_INT);
        $detail_stmt->bindParam(':description', $discount_description, PDO::PARAM_STR);
        $detail_stmt->bindParam(':debit_amount', $discount_debit, PDO::PARAM_STR);
        $detail_stmt->bindParam(':credit_amount', $discount_credit, PDO::PARAM_STR);
        $detail_stmt->execute();
    }
    
    return $entry_id;
}