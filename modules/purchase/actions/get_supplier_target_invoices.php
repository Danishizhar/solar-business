<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if supplier_id is provided
if (!isset($_POST['supplier_id']) || empty($_POST['supplier_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Supplier ID is required']);
    exit;
}

$supplier_id = intval($_POST['supplier_id']);

try {
    // Debug: First get ALL invoices for this supplier to see what's available
    error_log('=== TARGET INVOICE DEBUG ===');
    error_log('Supplier ID: ' . $supplier_id);
    
    $debug_query = "SELECT pi.id, pi.invoice_number, pi.invoice_date, pi.total_amount, pi.paid_amount, 
                          pi.balance_amount, pi.status, pi.payment_status
                   FROM purchase_invoices pi
                   WHERE pi.supplier_id = :supplier_id 
                   ORDER BY pi.invoice_date DESC";
                   
    $debug_stmt = $conn->prepare($debug_query);
    $debug_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $debug_stmt->execute();
    $all_invoices = $debug_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log('Total invoices for supplier: ' . count($all_invoices));
    foreach ($all_invoices as $inv) {
        error_log('Invoice: ' . $inv['invoice_number'] . ' | Status: ' . $inv['status'] . ' | Payment: ' . $inv['payment_status'] . ' | Balance: ' . $inv['balance_amount']);
    }
    
    // Get all unpaid or partially paid invoices for this supplier
    $query = "SELECT pi.id, pi.invoice_number, pi.invoice_date, pi.total_amount, pi.paid_amount, 
                    pi.balance_amount, pi.status, pi.payment_status
              FROM purchase_invoices pi
              WHERE pi.supplier_id = :supplier_id 
              AND pi.status = 'approved'
              AND (pi.payment_status = 'unpaid' OR pi.payment_status = 'partial')
              AND pi.balance_amount > 0
              ORDER BY pi.invoice_date ASC";
              
    error_log('Target invoice query: ' . $query);
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log('Target invoices found: ' . count($invoices));
    foreach ($invoices as $inv) {
        error_log('Target Invoice: ' . $inv['invoice_number'] . ' | Balance: ' . $inv['balance_amount']);
    }
    error_log('=== END TARGET INVOICE DEBUG ===');
    
    // Return the data as JSON
    header('Content-Type: application/json');
    echo json_encode($invoices);
    
} catch (PDOException $e) {
    // Log error
    error_log("Database error in get_supplier_target_invoices.php: " . $e->getMessage());
    
    // Return error message
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?>
