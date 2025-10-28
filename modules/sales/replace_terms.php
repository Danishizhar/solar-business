<?php
/**
 * Term Replacement Script for Sales Module
 * 
 * This script systematically replaces purchase-related terms with sales-related terms
 * in all PHP files within the sales module directory and its subdirectories.
 */

// Create log file
$logFile = __DIR__ . '/term_replacement_log.txt';
file_put_contents($logFile, "Term Replacement Log - " . date('Y-m-d H:i:s') . "\n\n");

function logMessage($message) {
    global $logFile;
    file_put_contents($logFile, $message . "\n", FILE_APPEND);
    echo $message . "\n";
}

logMessage("Starting term replacement in sales module files...");

// Define the base directory
$baseDir = __DIR__;

// Define term replacements (search => replace)
$replacements = [
    // Database table names
    'sales_orders' => 'sales_orders',
    'sales_order_items' => 'sales_order_items',
    'sales_invoices' => 'sales_invoices',
    'sales_invoice_items' => 'sales_invoice_items',
    'sales_payments' => 'sales_payments',
    'supplier_refunds' => 'customer_refunds',
    'sales_returns' => 'sales_returns',
    'sales_return_items' => 'sales_return_items',
    'goods_receiving_notes' => 'delivery_notes',
    'goods_receiving_note_items' => 'delivery_note_items',
    'suppliers' => 'customers',
    
    // File paths and includes
    'purchase_order_detail.php' => 'sales_order_detail.php',
    'sales_orders.php' => 'sales_orders.php',
    'sales_invoice_detail.php' => 'sales_invoice_detail.php',
    'sales_invoices.php' => 'sales_invoices.php',
    'purchase_return_detail.php' => 'sales_return_detail.php',
    'sales_returns.php' => 'sales_returns.php',
    'supplier_detail.php' => 'customer_detail.php',
    'suppliers.php' => 'customers.php',
    'grn_detail.php' => 'delivery_note_detail.php',
    'goods_receiving_notes.php' => 'delivery_notes.php',
    'purchase_order_create.php' => 'sales_order_create.php',
    'sales_invoice_create.php' => 'sales_invoice_create.php',
    'purchase_return_create.php' => 'sales_return_create.php',
    'print_purchase_return.php' => 'print_sales_return.php',
    'deletePurchaseOrderModal' => 'deleteSalesOrderModal',
    'deletePurchaseOrderModalLabel' => 'deleteSalesOrderModalLabel',
    'purchaseOrdersTable' => 'salesOrdersTable',
    'purchaseInvoicesTable' => 'salesInvoicesTable',
    'purchaseReturnsTable' => 'salesReturnsTable',
    'goodsReceivingNotesTable' => 'deliveryNotesTable',
    'supplierRefundsTable' => 'customerRefundsTable',
    
    // Action files
    'save_purchase_order.php' => 'save_sales_order.php',
    'save_sales_invoice.php' => 'save_sales_invoice.php',
    'save_purchase_return.php' => 'save_sales_return.php',
    'save_supplier.php' => 'save_customer.php',
    'save_grn.php' => 'save_delivery_note.php',
    'delete_purchase_order.php' => 'delete_sales_order.php',
    'delete_purchase_return.php' => 'delete_sales_return.php',
    'delete_supplier.php' => 'delete_customer.php',
    'cancel_sales_invoice.php' => 'cancel_sales_invoice.php',
    'cancel_purchase_return.php' => 'cancel_sales_return.php',
    'approve_purchase_return.php' => 'approve_sales_return.php',
    'get_purchase_order_details.php' => 'get_sales_order_details.php',
    'get_sales_invoice_details.php' => 'get_sales_invoice_details.php',
    'get_purchase_return_details.php' => 'get_sales_return_details.php',
    'get_supplier_details.php' => 'get_customer_details.php',
    'process_supplier_refund.php' => 'process_customer_refund.php',
    
    // Common terms
    'Purchase Order' => 'Sales Order',
    'Purchase Orders' => 'Sales Orders',
    'Purchase Invoice' => 'Sales Invoice',
    'Purchase Invoices' => 'Sales Invoices',
    'Purchase Return' => 'Sales Return',
    'Purchase Returns' => 'Sales Returns',
    'Goods Receiving Note' => 'Delivery Note',
    'Goods Receiving Notes' => 'Delivery Notes',
    'GRN' => 'DN',
    'Supplier' => 'Customer',
    'Suppliers' => 'Customers',
    'supplier' => 'customer',
    'suppliers' => 'customers',
    'purchase_add' => 'sales_add',
    
    // Database column names and variables
    'supplier_id' => 'customer_id',
    'supplier_name' => 'customer_name',
    'supplier_code' => 'customer_code',
    'sales_order_id' => 'sales_order_id',
    'sales_invoice_id' => 'sales_invoice_id',
    'grn_id' => 'delivery_note_id',
    'grn_item_id' => 'delivery_note_item_id',
    'grn_number' => 'delivery_note_number',
    'supplier_statement.php' => 'customer_statement.php',
    
    // Permission keys
    'purchase_view' => 'sales_view',
    'purchase_create' => 'sales_create',
    'purchase_edit' => 'sales_edit',
    'purchase_delete' => 'sales_delete',
    
    // UI elements
    'fa-shopping-cart' => 'fa-shopping-bag',
    'Purchase Module' => 'Sales Module',
    'Purchase Management' => 'Sales Management',
    'Purchase Order Management' => 'Sales Order Management',
    'Purchase Invoice Management' => 'Sales Invoice Management',
    'Purchase Return Management' => 'Sales Return Management',
    
    // Comment references
    '// Check if purchase' => '// Check if sales',
    '// Get purchase' => '// Get sales',
    '// Process purchase' => '// Process sales',
    '// Save purchase' => '// Save sales',
    '// Delete purchase' => '// Delete sales',
    '// Cancel purchase' => '// Cancel sales',
    '// Approve purchase' => '// Approve sales',
    '// Validate purchase' => '// Validate sales',
    '// Update purchase' => '// Update sales',
    '// Create purchase' => '// Create sales',
    '// Get supplier' => '// Get customer',
    '// Save supplier' => '// Save customer',
    '// Update supplier' => '// Update customer',
    '// Delete supplier' => '// Delete customer',
    
    // Error messages
    'Purchase order ID is required' => 'Sales order ID is required',
    'Purchase invoice ID is required' => 'Sales invoice ID is required',
    
    // Comments and variable names in SQL queries
    '// Check if this PO has any' => '// Check if this SO has any',
    'po.order_number' => 'po.order_number',
    'po.customer_id' => 'po.customer_id',
    'po.order_date' => 'po.order_date',
    'po.id = ordered.sales_order_id' => 'po.id = ordered.sales_order_id',
    'po.id = received.sales_order_id' => 'po.id = received.sales_order_id',
    'WHERE po.customer_id' => 'WHERE po.customer_id',
    'ORDER BY po.order_date' => 'ORDER BY po.order_date',
    'LEFT JOIN sales_orders po ON' => 'LEFT JOIN sales_orders po ON',
    'INNER JOIN sales_orders po ON' => 'INNER JOIN sales_orders po ON',
    'FROM sales_orders po' => 'FROM sales_orders po',
    
    // HTML and JavaScript references
    'Header with Company and PO Info' => 'Header with Company and SO Info',
    'Get the current PO item' => 'Get the current SO item',
    'when creating invoice from PO' => 'when creating invoice from SO',
    'Cannot add new items to an invoice created from PO' => 'Cannot add new items to an invoice created from SO',
    'const poQuantity' => 'const soQuantity',
    'If this is from a PO' => 'If this is from a SO',
    'match PO structure' => 'match SO structure',
    'Add event handler for quantity changes when from PO' => 'Add event handler for quantity changes when from SO',
    'Ensure quantity doesn\'t exceed PO quantity' => 'Ensure quantity doesn\'t exceed SO quantity',
    'If from PO, use PO values' => 'If from SO, use SO values',
    'data-po-count' => 'data-so-count',
    'data-po-quantity' => 'data-so-quantity',
    '<strong>PO #:</strong>' => '<strong>SO #:</strong>',
    'Purchase return ID is required' => 'Sales return ID is required',
    'Supplier ID is required' => 'Customer ID is required',
    'GRN ID is required' => 'Delivery Note ID is required',
    'Purchase order not found' => 'Sales order not found',
    'Purchase invoice not found' => 'Sales invoice not found',
    'Purchase return not found' => 'Sales return not found',
    'Supplier not found' => 'Customer not found',
    'GRN not found' => 'Delivery Note not found',
    
    // Special cases with case preservation
    'po.supplier_id' => 'po.customer_id',
    'pi.supplier_id' => 'pi.customer_id',
    'pr.supplier_id' => 'pr.customer_id',
    's.supplier_name' => 's.customer_name',
    's.supplier_code' => 's.customer_code',
    'sr.supplier_id' => 'sr.customer_id',
    
    // SQL aliases for sales_orders table
    'sales_orders po' => 'sales_orders so',
    'sales_orders as po' => 'sales_orders as so',
    'FROM sales_orders po' => 'FROM sales_orders so',
    'LEFT JOIN sales_orders po ON' => 'LEFT JOIN sales_orders so ON',
    'INNER JOIN sales_orders po ON' => 'INNER JOIN sales_orders so ON',
    'po.id' => 'so.id',
    'po.order_number' => 'so.order_number',
    'po.order_date' => 'so.order_date',
    'po.customer_id' => 'so.customer_id',
    'po.status' => 'so.status',
    'po.total_amount' => 'so.total_amount',
    'WHERE po.customer_id' => 'WHERE so.customer_id',
    'ORDER BY po.order_date' => 'ORDER BY so.order_date',
    'po.id = ordered.sales_order_id' => 'so.id = ordered.sales_order_id',
    'po.id = received.sales_order_id' => 'so.id = received.sales_order_id',
    
    // PHP foreach variables
    'foreach ($sales_orders as $po)' => 'foreach ($sales_orders as $so)',
    '$po["id"]' => '$so["id"]',
    '$po["so_number"]' => '$so["so_number"]',
    '$po["so_date"]' => '$so["so_date"]',
    '$po["total_amount"]' => '$so["total_amount"]',
    '$po["status"]' => '$so["status"]',
    '$po["total_items"]' => '$so["total_items"]',
    '$po["received_items"]' => '$so["received_items"]',
    '$po["order_number"]' => '$so["order_number"]',
    '$po["customer_name"]' => '$so["customer_name"]',
    '$po["customer_code"]' => '$so["customer_code"]',
    
    // Additional variable and parameter replacements
    'po_id' => 'so_id',
    ':po_id' => ':so_id',
    'po.id as po_id' => 'so.id as so_id',
    'po.status as po_status' => 'so.status as so_status',
    '$po_query' => '$so_query',
    '$po_stmt' => '$so_stmt',
    '$po_invoice_stmt' => '$so_invoice_stmt',
    '$update_po_status' => '$update_so_status',
    '$update_po_stmt' => '$update_so_stmt',
    '$updatePo' => '$updateSo',
    '$stmtUpdatePo' => '$stmtUpdateSo',
    '$updatePoItem' => '$updateSoItem',
    '$stmtUpdatePoItem' => '$stmtUpdateSoItem',
    ':po_item_id' => ':so_item_id',
    '$poItemId' => '$soItemId',
    
    // Additional variable names with po_ prefix
    '$po_items' => '$so_items',
    '$original_po_items' => '$original_so_items',
    '$po_item' => '$so_item',
    '$po_quantity' => '$so_quantity',
    '$po_item_id' => '$so_item_id',
    '$po_items_query' => '$so_items_query',
    '$po_items_stmt' => '$so_items_stmt',
    '$po_items_map' => '$so_items_map',
    '$new_po_status' => '$new_so_status',
    '$po_invoice_query' => '$so_invoice_query',
    '$po_invoice_result' => '$so_invoice_result',
    
    // Form field names and data attributes
    'po_quantity' => 'so_quantity',
    'data-po-quantity' => 'data-so-quantity',
    'original_po_delivery_charges' => 'original_so_delivery_charges',
    'total_po_quantity' => 'total_so_quantity',
    
    // Debug log messages
    'po_item_id=' => 'so_item_id=',
    'po_notes' => 'so_notes',
    
    // Customer-related variables
    'po_count' => 'so_count',
    
    // Additional specific variable replacements in cancel_sales_invoice.php
    '$po_status' => '$so_status',
    '$update_po_query' => '$update_so_query',
    'dnData[\'po_status\']' => 'dnData[\'so_status\']',
    
    // SQL query fixes in customer_detail.php
    'SELECT po.*' => 'SELECT so.*',
    'LEFT JOIN users u ON po.created_by = u.id' => 'LEFT JOIN users u ON so.created_by = u.id',
    'po.expected_delivery_date' => 'so.expected_delivery_date',
    'po.notes as so_notes' => 'so.notes as so_notes',
    '// Initialize array to track PO items' => '// Initialize array to track SO items',
    '// Store original PO quantities' => '// Store original SO quantities',
    'invoice was created from a PO' => 'invoice was created from a SO',
    'get all items from the original PO' => 'get all items from the original SO',
    'the PO is not complete' => 'the SO is not complete',
    'the PO status is managed through' => 'the SO status is managed through',
    'Reverse inventory transactions for PO-based invoices' => 'Reverse inventory transactions for SO-based invoices',
    'Update sales order status for PO-based invoices' => 'Update sales order status for SO-based invoices',
    'Check if the current PO status is' => 'Check if the current SO status is',
    'Compare the invoice items with the PO items' => 'Compare the invoice items with the SO items',
    'Check if all PO items are fully covered' => 'Check if all SO items are fully covered',
    'Create a map of PO items for easy lookup' => 'Create a map of SO items for easy lookup',
    'Check if invoice items cover all PO items' => 'Check if invoice items cover all SO items',
    'This was a full invoice for the entire PO' => 'This was a full invoice for the entire SO',
    "row.data('po-quantity')" => "row.data('so-quantity')",
    'Determine the source of the invoice (DN, PO, or direct)' => 'Determine the source of the invoice (DN, SO, or direct)'
];

// Function to process a file
function processFile($filePath, $replacements) {
    // Skip this script itself
    if (basename($filePath) == 'replace_terms.php') {
        return;
    }
    
    // Skip backup files
    if (strpos(basename($filePath), '.bak') !== false) {
        logMessage("Skipping backup file: " . basename($filePath));
        return;
    }
    
    // Read file content
    $content = file_get_contents($filePath);
    if ($content === false) {
        logMessage("Error reading file: " . basename($filePath));
        return;
    }
    
    // Original content for comparison
    $originalContent = $content;
    $replacementCount = 0;
    $replacementDetails = [];
    
    // Apply replacements
    foreach ($replacements as $search => $replace) {
        $count = 0;
        $newContent = str_replace($search, $replace, $content, $count);
        if ($count > 0) {
            $replacementCount += $count;
            $replacementDetails[] = "'$search' → '$replace': $count occurrences";
        }
        $content = $newContent;
    }
    
    // Additional specific replacements for error messages and debug info
    $count1 = 0;
    $content = str_replace("purchase orders", "sales orders", $content, $count1);
    if ($count1 > 0) {
        $replacementCount += $count1;
        $replacementDetails[] = "'purchase orders' → 'sales orders': $count1 occurrences";
    }
    
    $count2 = 0;
    $content = str_replace("Purchase orders", "Sales orders", $content, $count2);
    if ($count2 > 0) {
        $replacementCount += $count2;
        $replacementDetails[] = "'Purchase orders' → 'Sales orders': $count2 occurrences";
    }
    
    $count3 = 0;
    $content = str_replace("Error fetching purchase", "Error fetching sales", $content, $count3);
    if ($count3 > 0) {
        $replacementCount += $count3;
        $replacementDetails[] = "'Error fetching purchase' → 'Error fetching sales': $count3 occurrences";
    }
    
    $count4 = 0;
    $content = str_replace("No purchase orders found", "No sales orders found", $content, $count4);
    if ($count4 > 0) {
        $replacementCount += $count4;
        $replacementDetails[] = "'No purchase orders found' → 'No sales orders found': $count4 occurrences";
    }
    
    // Debug log message replacements
    $count5 = 0;
    $content = str_replace("Updated PO status", "Updated SO status", $content, $count5);
    if ($count5 > 0) {
        $replacementCount += $count5;
        $replacementDetails[] = "'Updated PO status' → 'Updated SO status': $count5 occurrences";
    }
    
    $count6 = 0;
    $content = str_replace("Setting PO status", "Setting SO status", $content, $count6);
    if ($count6 > 0) {
        $replacementCount += $count6;
        $replacementDetails[] = "'Setting PO status' → 'Setting SO status': $count6 occurrences";
    }
    
    $count7 = 0;
    $content = str_replace("for this PO", "for this SO", $content, $count7);
    if ($count7 > 0) {
        $replacementCount += $count7;
        $replacementDetails[] = "'for this PO' → 'for this SO': $count7 occurrences";
    }
    
    $count8 = 0;
    $content = str_replace("PO items:", "SO items:", $content, $count8);
    if ($count8 > 0) {
        $replacementCount += $count8;
        $replacementDetails[] = "'PO items:' → 'SO items:': $count8 occurrences";
    }
    
    $count9 = 0;
    $content = str_replace("for PO item ID", "for SO item ID", $content, $count9);
    if ($count9 > 0) {
        $replacementCount += $count9;
        $replacementDetails[] = "'for PO item ID' → 'for SO item ID': $count9 occurrences";
    }
    
    $count10 = 0;
    $content = str_replace("(no DN, no PO)", "(no DN, no SO)", $content, $count10);
    if ($count10 > 0) {
        $replacementCount += $count10;
        $replacementDetails[] = "'(no DN, no PO)' → '(no DN, no SO)': $count10 occurrences";
    }
    
    // Only write if content changed
    if ($content !== $originalContent) {
        if (file_put_contents($filePath, $content) === false) {
            logMessage("Error writing file: " . basename($filePath));
        } else {
            logMessage("Updated: " . basename($filePath) . " ($replacementCount replacements)");
            foreach ($replacementDetails as $detail) {
                logMessage("  - $detail");
            }
        }
    } else {
        logMessage("No changes: " . basename($filePath));
    }
}

// Function to recursively process directories
function processDirectory($dir, $replacements) {
    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') {
            continue;
        }
        
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            // Skip database directory as we've already updated those files
            if ($file == 'database') {
                continue;
            }
            processDirectory($path, $replacements);
        } else {
            // Only process PHP files
            if (pathinfo($path, PATHINFO_EXTENSION) == 'php') {
                processFile($path, $replacements);
            }
        }
    }
}

// Start processing
logMessage("Starting term replacement in sales module files...");
processDirectory($baseDir, $replacements);
logMessage("Term replacement completed.");
logMessage("Log file created at: $logFile");
