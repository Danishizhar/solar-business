<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required']);
    exit();
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
    // Debug - Log supplier ID
    error_log("Processing supplier ID: " . $supplier_id);
    
    // First check if the supplier exists
    $supplier_check = "SELECT id, supplier_name FROM suppliers WHERE id = :supplier_id";
    $supplier_stmt = $conn->prepare($supplier_check);
    $supplier_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $supplier_stmt->execute();
    $supplier = $supplier_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        error_log("Supplier not found: " . $supplier_id);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Supplier not found']);
        exit;
    }
    
    error_log("Supplier found: " . $supplier['supplier_name']);
    
    // First, let's check if there are ANY invoices for this supplier
    $all_invoices_query = "SELECT COUNT(*) as total FROM purchase_invoices WHERE supplier_id = :supplier_id";
    $all_stmt = $conn->prepare($all_invoices_query);
    $all_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $all_stmt->execute();
    $total_count = $all_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    error_log("Total invoices for supplier: " . $total_count);
    
    // Check specifically for approved invoices
    $approved_query = "SELECT COUNT(*) as total FROM purchase_invoices WHERE supplier_id = :supplier_id AND status = 'approved'";
    $approved_stmt = $conn->prepare($approved_query);
    $approved_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $approved_stmt->execute();
    $approved_count = $approved_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    error_log("Approved invoices for supplier: " . $approved_count);
    
    // Check specifically for unpaid approved invoices
    $unpaid_query = "SELECT COUNT(*) as total FROM purchase_invoices WHERE supplier_id = :supplier_id AND status = 'approved' AND payment_status = 'unpaid'";
    $unpaid_stmt = $conn->prepare($unpaid_query);
    $unpaid_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $unpaid_stmt->execute();
    $unpaid_count = $unpaid_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    error_log("Unpaid approved invoices for supplier: " . $unpaid_count);
    
    // Get all eligible invoices for the supplier (approved only and no existing returns)
    $query = "SELECT 
              pi.id, 
              pi.invoice_number, 
              pi.invoice_date, 
              pi.total_amount, 
              pi.paid_amount,
              pi.payment_status,
              pi.status,
              pi.balance_amount
              FROM purchase_invoices pi
              WHERE pi.supplier_id = :supplier_id 
              AND pi.status = 'approved'
              AND NOT EXISTS (
                  SELECT 1 FROM purchase_returns pr 
                  WHERE pr.invoice_id = pi.id AND pr.status = 'approved'
              )
              ORDER BY pi.invoice_date DESC";
    
    // Debug - Log the query
    error_log("Query: " . str_replace(':supplier_id', $supplier_id, $query));
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $stmt->execute();
    
    // Debug - Check for PDO errors
    if ($stmt->errorCode() !== '00000') {
        $errorInfo = $stmt->errorInfo();
        error_log("PDO Error: " . json_encode($errorInfo));
    }
    
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug - Log invoice count
    error_log("Found " . count($invoices) . " invoices with the filter");
    
    // Debug - Log each invoice
    foreach ($invoices as $invoice) {
        error_log("Invoice: " . $invoice['invoice_number'] . ", Status: " . $invoice['status'] . ", Payment Status: " . $invoice['payment_status']);
    }
    
    // Return the data as JSON
    header('Content-Type: application/json');
    echo json_encode(['debug_info' => [
        'total_invoices' => $total_count,
        'approved_invoices' => $approved_count,
        'unpaid_approved_invoices' => $unpaid_count,
        'filtered_invoices' => count($invoices)
    ], 'invoices' => $invoices]);
    
} catch (PDOException $e) {
    // Log error
    error_log("Database error in get_supplier_invoices.php: " . $e->getMessage());
    
    // Return error message
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?>
