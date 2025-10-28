<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

error_log("==================== SUPPLIER REFUND NEW REQUEST ====================");

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../login.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Debug - Log the included files
error_log("Included files: " . json_encode(get_included_files()));

// Get supplier ID from POST request
$supplier_id = isset($_POST['supplier_id']) ? intval($_POST['supplier_id']) : 0;

// Check if supplier ID is valid
if ($supplier_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid supplier ID']);
    exit();
}

try {
    // Debug - Check if $conn is a valid PDO object
    if (!($conn instanceof PDO)) {
        error_log("Database connection is not a valid PDO object");
        error_log("PDO Object Dump: " . json_encode($conn));
        error_log("PDO Object Type: " . gettype($conn));
        echo json_encode(['success' => false, 'message' => 'Database connection error', 'debug' => 'Not a valid PDO object']);
        exit;
    }
    
    // Debug - Log supplier ID
    error_log("Processing supplier ID: " . $supplier_id);
    error_log("Processing supplier ID: " . $supplier_id);
    error_log("Supplier ID Type: " . gettype($supplier_id));
    error_log("Supplier ID Dump: " . json_encode($supplier_id));
    
    // Get invoices for display purposes (to show sources of overpayment)
    // But exclude them from actual overpayment calculation if they have credit notes
    $invoice_query = "SELECT 
                pi.id,
                pi.invoice_number,
                pi.invoice_date,
                pi.total_amount,
                pi.paid_amount,
                pi.balance_amount,
                pi.status,
                pi.payment_status,
                CASE 
                    WHEN pi.balance_amount < 0 THEN ABS(pi.balance_amount)
                    WHEN pi.status = 'returned' AND pi.paid_amount > 0 THEN pi.paid_amount
                    ELSE 0
                END as display_amount,
                -- Check if this invoice has credit notes
                (SELECT COUNT(*) FROM credit_notes cn 
                 INNER JOIN purchase_returns pr ON cn.return_id = pr.id 
                 WHERE pr.invoice_id = pi.id AND cn.status IN ('active', 'partially_used')) as has_credit_notes,
                'invoice' as source_type
              FROM 
                purchase_invoices pi
              WHERE 
                pi.supplier_id = :supplier_id
                AND (
                    (pi.balance_amount < 0) OR
                    (pi.status = 'returned' AND pi.paid_amount > 0)
                )";
    
    // Debug - Log the invoice query
    error_log("Invoice Query: " . str_replace(':supplier_id', $supplier_id, $invoice_query));
    error_log("Invoice Query: " . str_replace(':supplier_id', $supplier_id, $invoice_query));
                
    $invoice_stmt = $conn->prepare($invoice_query);
    $invoice_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $invoice_stmt->execute();
    
    // Debug - Check for PDO errors
    if ($invoice_stmt->errorCode() !== '00000') {
        $errorInfo = $invoice_stmt->errorInfo();
        error_log("PDO Error (Invoice Query): " . json_encode($errorInfo));
        echo json_encode(['success' => false, 'message' => 'Database query error', 'debug' => $errorInfo[2]]);
        exit;
    }
    
    $invoices = $invoice_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug - Log invoice count
    error_log("Found " . count($invoices) . " invoices with overpayment");
    error_log("Found " . count($invoices) . " invoices with overpayment");
    
    // Now check for active and partially used credit notes for this supplier
    $credit_note_query = "SELECT 
                cn.id,
                cn.note_number as invoice_number,
                DATE_FORMAT(cn.created_at, '%Y-%m-%d') as invoice_date,
                cn.amount as total_amount,
                0 as paid_amount,
                CASE 
                    WHEN cn.status = 'partially_used' AND cn.remaining_amount IS NOT NULL THEN cn.remaining_amount
                    ELSE cn.amount 
                END as balance_amount,
                cn.status,
                'credit_note' as payment_status,
                CASE 
                    WHEN cn.status = 'partially_used' AND cn.remaining_amount IS NOT NULL THEN cn.remaining_amount
                    ELSE cn.amount 
                END as overpaid_amount,
                'credit_note' as source_type,
                cn.return_id,
                IFNULL(pi.invoice_number, '') as original_invoice_number
              FROM 
                credit_notes cn
              LEFT JOIN
                purchase_returns pr ON cn.return_id = pr.id
              LEFT JOIN
                purchase_invoices pi ON pr.invoice_id = pi.id
              WHERE 
                cn.supplier_id = :supplier_id
                AND (cn.status = 'active' OR (cn.status = 'partially_used' AND cn.remaining_amount > 0))";
    
    // Debug - Log the credit note query
    error_log("Credit Note Query: " . str_replace(':supplier_id', $supplier_id, $credit_note_query));
    error_log("Credit Note Query: " . str_replace(':supplier_id', $supplier_id, $credit_note_query));
                
    $credit_note_stmt = $conn->prepare($credit_note_query);
    $credit_note_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $credit_note_stmt->execute();
    
    // Debug - Check for PDO errors
    if ($credit_note_stmt->errorCode() !== '00000') {
        $errorInfo = $credit_note_stmt->errorInfo();
        error_log("PDO Error (Credit Note Query): " . json_encode($errorInfo));
        echo json_encode(['success' => false, 'message' => 'Database query error', 'debug' => $errorInfo[2]]);
        exit;
    }
    
    $credit_notes = $credit_note_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug - Log credit note count
    error_log("Found " . count($credit_notes) . " active credit notes");
    error_log("Found " . count($credit_notes) . " active credit notes");
    
    // Dump credit notes data for debugging
    if (count($credit_notes) > 0) {
        error_log("Credit notes data: " . json_encode($credit_notes));
    }
    
    // Prepare display data - add overpaid_amount field for invoices for display
    foreach ($invoices as &$invoice) {
        $invoice['overpaid_amount'] = $invoice['display_amount'];
    }
    
    // Combine results for display
    $all_results = array_merge($invoices, $credit_notes);
    
    $total_overpayment = 0;
    
    // Calculate total overpayment - ONLY from credit notes and invoices WITHOUT credit notes
    foreach ($all_results as $item) {
        $source_type = isset($item['source_type']) ? $item['source_type'] : 'unknown';
        $item_number = $item['invoice_number'];
        
        if ($source_type === 'credit_note') {
            // Always count credit notes
            $amount_to_add = floatval($item['overpaid_amount']);
            $total_overpayment += $amount_to_add;
            error_log("Credit Note $item_number - Adding to overpayment: $amount_to_add");
        } else if ($source_type === 'invoice' && intval($item['has_credit_notes']) == 0) {
            // Only count invoices that DON'T have credit notes (to avoid double counting)
            $amount_to_add = floatval($item['overpaid_amount']);
            $total_overpayment += $amount_to_add;
            error_log("Invoice $item_number (no credit notes) - Adding to overpayment: $amount_to_add");
        } else if ($source_type === 'invoice' && intval($item['has_credit_notes']) > 0) {
            // Invoice has credit notes - show in display but don't count in overpayment
            error_log("Invoice $item_number (has credit notes) - Showing for display only, not counting in overpayment");
        }
    }
    
    // Debug - Log final calculation summary
    error_log("=== OVERPAYMENT CALCULATION SUMMARY ===");
    error_log("Total Credit Notes: " . count($credit_notes));
    error_log("Total Invoices: " . count($invoices));
    error_log("Final Total Overpayment: $total_overpayment");
    error_log("========================================");
    
    // Return the result
    $result = [
        'success' => true,
        'total_overpayment' => $total_overpayment,
        'formatted_total' => formatNumber($conn, $total_overpayment),
        'invoices' => $all_results
    ];
    
    // Debug - Log the result
    error_log("Returning result: " . json_encode($result));
    error_log("Returning result: " . json_encode($result));
    
    // End of request
    
    header('Content-Type: application/json');
    echo json_encode($result);
    
} catch (PDOException $e) {
    error_log("PDO Exception: " . $e->getMessage());
    error_log("PDO Exception: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'debug' => true]);
} catch (Exception $e) {
    error_log("General Exception: " . $e->getMessage());
    error_log("General Exception: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => true]);
}
?>
