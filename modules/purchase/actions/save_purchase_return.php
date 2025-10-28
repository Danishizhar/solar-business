<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to continue']);
    exit();
}

// Check if user has permission to create purchase returns
if ($_SESSION['role_id'] != 1 && (!isset($_SESSION['permissions']['purchase_returns']) || !in_array('create', $_SESSION['permissions']['purchase_returns']))) {
    echo json_encode(['success' => false, 'message' => "You don't have permission to create purchase returns"]);
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Include helper functions
require_once '../../../includes/company_helpers.php';

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Validate required fields
$required_fields = ['supplier_id', 'invoice_id', 'return_date', 'location_id', 'items'];
foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || empty($_POST[$field])) {
        echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
        exit();
    }
}

// Parse items
$items = json_decode($_POST['items'], true);
if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Please add at least one item to return']);
    exit();
}

// Get form data
$supplier_id = $_POST['supplier_id'];
$invoice_id = $_POST['invoice_id'];
$return_date = $_POST['return_date'];
$location_id = $_POST['location_id'];
$notes = $_POST['notes'] ?? '';
$delivery_charges = isset($_POST['delivery_charges']) ? floatval($_POST['delivery_charges']) : 0;
$include_delivery_charges = isset($_POST['include_delivery_charges']) ? 1 : 0;
$subtotal = isset($_POST['subtotal']) ? floatval($_POST['subtotal']) : 0;
$discount_amount = isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : 0;
$tax_amount = isset($_POST['tax_amount']) ? floatval($_POST['tax_amount']) : 0;
$total_amount = isset($_POST['total_amount']) ? floatval($_POST['total_amount']) : 0;
$return_type = isset($_POST['return_type']) ? $_POST['return_type'] : 'partial'; // Default to partial if not specified

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Validate invoice exists and belongs to supplier
    $invoice_query = "SELECT * FROM purchase_invoices WHERE id = :invoice_id AND supplier_id = :supplier_id AND status IN ('approved', 'partially_returned')";
    $invoice_stmt = $conn->prepare($invoice_query);
    $invoice_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $invoice_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $invoice_stmt->execute();
    $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get invoice totals and previously returned amounts
    $invoice_totals_query = "SELECT 
        pi.subtotal as invoice_subtotal,
        pi.discount_amount as invoice_discount,
        pi.tax_amount as invoice_tax,
        pi.delivery_charges as invoice_delivery,
        pi.total_amount as invoice_total,
        COALESCE(SUM(pr.subtotal), 0) as returned_subtotal,
        COALESCE(SUM(pr.discount_amount), 0) as returned_discount,
        COALESCE(SUM(pr.tax_amount), 0) as returned_tax,
        COALESCE(SUM(pr.delivery_charges), 0) as returned_delivery,
        COALESCE(SUM(pr.total_amount), 0) as returned_total
    FROM purchase_invoices pi
    LEFT JOIN purchase_returns pr ON pi.id = pr.invoice_id AND pr.status = 'approved'
    WHERE pi.id = :invoice_id
    GROUP BY pi.id";
    
    $totals_stmt = $conn->prepare($invoice_totals_query);
    $totals_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $totals_stmt->execute();
    $invoice_totals = $totals_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception('Invalid invoice selected');
    }
    
    // Determine if this is a full or partial return based on the amounts
    // Calculate what's left to return after this return
    $remaining_subtotal = $invoice_totals['invoice_subtotal'] - $invoice_totals['returned_subtotal'] - $subtotal;
    $remaining_tax = $invoice_totals['invoice_tax'] - $invoice_totals['returned_tax'] - $tax_amount;
    $remaining_discount = $invoice_totals['invoice_discount'] - $invoice_totals['returned_discount'] - $discount_amount;
    $remaining_delivery = $invoice_totals['invoice_delivery'] - $invoice_totals['returned_delivery'] - $delivery_charges;
    $remaining_total = $invoice_totals['invoice_total'] - $invoice_totals['returned_total'] - $total_amount;
    
    // If all amounts are returned (or very close to it, accounting for rounding errors)
    if (abs($remaining_total) < 0.01 && abs($remaining_subtotal) < 0.01) {
        $return_type = 'full';
    } else {
        $return_type = 'partial';
    }
    
    // Validate that return amounts don't exceed original invoice
    if ($remaining_subtotal < -0.01 || $remaining_tax < -0.01 || $remaining_total < -0.01) {
        throw new Exception('Return amounts cannot exceed the original invoice amounts');
    }
    
    // Validate delivery charges - simplified query to avoid parameter binding issues
    $delivery_query = "SELECT 
                     pi.delivery_charges,
                     COALESCE((SELECT SUM(pr.delivery_charges) 
                              FROM purchase_returns pr 
                              WHERE pr.invoice_id = pi.id 
                              AND pr.status = 'approved'), 0) as returned_delivery_charges
                     FROM purchase_invoices pi
                     WHERE pi.id = :invoice_id";
    $delivery_stmt = $conn->prepare($delivery_query);
    $delivery_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $delivery_stmt->execute();
    $delivery_result = $delivery_stmt->fetch(PDO::FETCH_ASSOC);
    
    $max_delivery_charges = $delivery_result['delivery_charges'] - $delivery_result['returned_delivery_charges'];
    if ($delivery_charges > $max_delivery_charges) {
        throw new Exception('Delivery charges cannot exceed the remaining amount: ' . formatNumber($conn, $max_delivery_charges));
    }
    
    // Get active financial year
    $fy_query = "SELECT id, year_code FROM financial_years WHERE status = 1 AND is_closed = 0 LIMIT 1";
    $fy_stmt = $conn->prepare($fy_query);
    $fy_stmt->execute();
    $financial_year = $fy_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$financial_year) {
        throw new Exception('No active financial year found');
    }
    
    // Generate return number
    $year_month = date('Ym');
    $number_query = "SELECT MAX(CAST(SUBSTRING_INDEX(return_number, '-', -1) AS UNSIGNED)) as last_number 
                    FROM purchase_returns 
                    WHERE return_number LIKE :return_pattern";
    $stmt = $conn->prepare($number_query);
    $return_pattern = "PR-$year_month-%";
    $stmt->bindParam(':return_pattern', $return_pattern, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_number = str_pad(($row['last_number'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
    $return_number = "PR-$year_month-$next_number";
    
    // Insert purchase return header
    $return_query = "INSERT INTO purchase_returns (
                    return_number, invoice_id, supplier_id, return_date,
                    location_id, subtotal, discount_amount, tax_amount, delivery_charges, include_delivery_charges, total_amount,
                    notes, status, created_by, created_at, financial_year_id, return_type
                ) VALUES (
                    :return_number, :invoice_id, :supplier_id, :return_date,
                    :location_id, :subtotal, :discount_amount, :tax_amount, :delivery_charges, :include_delivery_charges, :total_amount,
                    :notes, 'draft', :created_by, NOW(), :financial_year_id, :return_type
                )";
    
    $return_stmt = $conn->prepare($return_query);
    $return_stmt->bindParam(':return_number', $return_number, PDO::PARAM_STR);
    $return_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $return_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $return_stmt->bindParam(':return_date', $return_date, PDO::PARAM_STR);
    $return_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
    $return_stmt->bindParam(':subtotal', $subtotal, PDO::PARAM_STR);
    $return_stmt->bindParam(':discount_amount', $discount_amount, PDO::PARAM_STR);
    $return_stmt->bindParam(':tax_amount', $tax_amount, PDO::PARAM_STR);
    $return_stmt->bindParam(':delivery_charges', $delivery_charges, PDO::PARAM_STR);
    $return_stmt->bindParam(':include_delivery_charges', $include_delivery_charges, PDO::PARAM_INT);
    $return_stmt->bindParam(':total_amount', $total_amount, PDO::PARAM_STR);
    $return_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
    $return_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $return_stmt->bindParam(':financial_year_id', $financial_year['id'], PDO::PARAM_INT);
    $return_stmt->bindParam(':return_type', $return_type, PDO::PARAM_STR);
    $return_stmt->execute();
    
    $return_id = $conn->lastInsertId();
    
    // Validate and insert return items
    foreach ($items as $item) {
        // Validate item data
        if (!isset($item['invoice_item_id']) || !isset($item['product_id']) || !isset($item['quantity']) || 
            empty($item['invoice_item_id']) || empty($item['product_id']) || empty($item['quantity'])) {
            throw new Exception('Invalid item data');
        }
        
        // Validate return quantity and get proper UOM information
        $item_query = "SELECT pii.*, p.purchase_uom_id, p.base_uom_id,
                      (SELECT COALESCE(SUM(pri.quantity), 0) 
                       FROM purchase_return_items pri 
                       JOIN purchase_returns pr ON pri.return_id = pr.id 
                       WHERE pri.invoice_item_id = pii.id 
                       AND pr.status = 'approved') as returned_quantity
                      FROM purchase_invoice_items pii 
                      JOIN products p ON pii.product_id = p.id
                      WHERE pii.id = :invoice_item_id";
        
        $item_stmt = $conn->prepare($item_query);
        $item_stmt->bindParam(':invoice_item_id', $item['invoice_item_id'], PDO::PARAM_INT);
        $item_stmt->execute();
        $invoice_item = $item_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice_item) {
            throw new Exception('Invalid invoice item');
        }
        
        $available_qty = $invoice_item['quantity'] - $invoice_item['returned_quantity'];
        if ($item['quantity'] > $available_qty) {
            throw new Exception('Return quantity exceeds available quantity for item: ' . $invoice_item['product_id']);
        }
        
        // Calculate item totals with discount
        $discount_percent = isset($item['discount_percent']) ? floatval($item['discount_percent']) : 0;
        $item_subtotal = floatval($item['quantity']) * floatval($item['unit_price']);
        $item_discount = $item_subtotal * ($discount_percent / 100);
        $item_subtotal_after_discount = $item_subtotal - $item_discount;
        $item_tax = $item_subtotal_after_discount * (floatval($item['tax_rate']) / 100);
        $item_total = $item_subtotal_after_discount + $item_tax;
        
        // Prepare data for insertion
        $batch_number = !empty($item['batch_number']) ? $item['batch_number'] : null;
        $expiry_date = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
        $description = isset($item['description']) ? $item['description'] : '';
        
        // Use consistent UOM handling with proper fallback
        $uom_id = isset($item['uom_id']) && !empty($item['uom_id']) ? $item['uom_id'] : 1;
        
        // Debug item data
        error_log('Processing item: ' . print_r($item, true));
        
        try {
            // Simplified query with named parameters
            $return_item_query = "INSERT INTO purchase_return_items 
                (return_id, invoice_item_id, product_id, description, quantity, uom_id, 
                unit_price, discount_percent, discount_amount, tax_rate, tax_amount, 
                total_amount, batch_number, expiry_date, created_by, created_at) 
                VALUES 
                (:return_id, :invoice_item_id, :product_id, :description, :quantity, :uom_id, 
                :unit_price, :discount_percent, :discount_amount, :tax_rate, :tax_amount, 
                :total_amount, :batch_number, :expiry_date, :created_by, NOW())";
            
            error_log('SQL Query: ' . $return_item_query);
            
            // Create an array of parameters for binding
            $params = [
                ':return_id' => $return_id,
                ':invoice_item_id' => $item['invoice_item_id'],
                ':product_id' => $item['product_id'],
                ':description' => $description,
                ':quantity' => $item['quantity'],
                ':uom_id' => $uom_id,
                ':unit_price' => $item['unit_price'],
                ':discount_percent' => $discount_percent,
                ':discount_amount' => $item_discount,
                ':tax_rate' => $item['tax_rate'],
                ':tax_amount' => $item_tax,
                ':total_amount' => $item_total,
                ':batch_number' => $batch_number,
                ':expiry_date' => $expiry_date,
                ':created_by' => $_SESSION['user_id']
            ];
            
            error_log('Parameters to bind: ' . print_r($params, true));
            
            // Prepare and execute with the parameter array
            $return_item_stmt = $conn->prepare($return_item_query);
            
            // Bind each parameter with appropriate type
            foreach ($params as $param => $value) {
                if ($value === null) {
                    $return_item_stmt->bindValue($param, null, PDO::PARAM_NULL);
                } elseif (is_int($value)) {
                    $return_item_stmt->bindValue($param, $value, PDO::PARAM_INT);
                } else {
                    $return_item_stmt->bindValue($param, $value, PDO::PARAM_STR);
                }
            }
            
            // Execute the statement
            $return_item_stmt->execute();
            error_log('Item inserted successfully');
            
        } catch (Exception $itemEx) {
            error_log('Error inserting return item: ' . $itemEx->getMessage());
            error_log('Error code: ' . $itemEx->getCode());
            throw $itemEx; // Re-throw to be caught by the outer try-catch
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Purchase return created successfully',
        'return_id' => $return_id,
        'return_number' => $return_number
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Detailed error information
    $errorInfo = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    error_log('Purchase Return Error: ' . print_r($errorInfo, true));
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'debug' => $errorInfo]);
}
?>
