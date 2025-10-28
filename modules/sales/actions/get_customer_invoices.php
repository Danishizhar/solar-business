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

// Check if customer_id is provided
if (!isset($_POST['customer_id']) || empty($_POST['customer_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Customer ID is required']);
    exit;
}

$customer_id = intval($_POST['customer_id']);

try {
    // Debug - Log customer ID
    error_log("Processing customer ID: " . $customer_id);
    
    // First check if the customer exists
    $customer_check = "SELECT id, customer_name FROM customers WHERE id = :customer_id";
    $customer_stmt = $conn->prepare($customer_check);
    $customer_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
    $customer_stmt->execute();
    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        error_log("Customer not found: " . $customer_id);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Customer not found']);
        exit;
    }
    
    error_log("Customer found: " . $customer['customer_name']);
    
    // First, let's check if there are ANY invoices for this customer
    $all_invoices_query = "SELECT COUNT(*) as total FROM sales_invoices WHERE customer_id = :customer_id";
    $all_stmt = $conn->prepare($all_invoices_query);
    $all_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
    $all_stmt->execute();
    $total_count = $all_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    error_log("Total invoices for customer: " . $total_count);
    
    // Check specifically for approved invoices
    $approved_query = "SELECT COUNT(*) as total FROM sales_invoices WHERE customer_id = :customer_id AND status = 'approved'";
    $approved_stmt = $conn->prepare($approved_query);
    $approved_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
    $approved_stmt->execute();
    $approved_count = $approved_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    error_log("Approved invoices for customer: " . $approved_count);
    
    // Check specifically for unpaid approved invoices
    $unpaid_query = "SELECT COUNT(*) as total FROM sales_invoices WHERE customer_id = :customer_id AND status = 'approved' AND payment_status = 'unpaid'";
    $unpaid_stmt = $conn->prepare($unpaid_query);
    $unpaid_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
    $unpaid_stmt->execute();
    $unpaid_count = $unpaid_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    error_log("Unpaid approved invoices for customer: " . $unpaid_count);
    
    // Get all eligible invoices for the customer (approved only and no existing returns)
    $query = "SELECT 
              pi.id, 
              pi.invoice_number, 
              pi.invoice_date, 
              pi.total_amount, 
              pi.paid_amount,
              pi.payment_status,
              pi.status,
              pi.balance_amount
              FROM sales_invoices pi
              WHERE pi.customer_id = :customer_id 
              AND pi.status = 'approved'
              AND NOT EXISTS (
                  SELECT 1 FROM sales_returns pr 
                  WHERE pr.invoice_id = pi.id AND pr.status = 'approved'
              )
              ORDER BY pi.invoice_date DESC";
    
    // Debug - Log the query
    error_log("Query: " . str_replace(':customer_id', $customer_id, $query));
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
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
    error_log("Database error in get_customer_invoices.php: " . $e->getMessage());
    
    // Return error message
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?>
