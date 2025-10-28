<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to continue']);
    exit;
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if invoice_id is provided
if (!isset($_POST['invoice_id']) || empty($_POST['invoice_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid invoice selected']);
    exit;
}

$invoice_id = $_POST['invoice_id'];

try {
    // Get invoice details and previously returned amounts
    $query = "SELECT 
        pi.subtotal as invoice_subtotal,
        pi.discount_amount as invoice_discount,
        pi.tax_amount as invoice_tax,
        pi.delivery_charges as invoice_delivery,
        pi.total_amount as invoice_total,
        COALESCE((SELECT SUM(pr.subtotal) FROM sales_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved'), 0) as returned_subtotal,
        COALESCE((SELECT SUM(pr.discount_amount) FROM sales_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved'), 0) as returned_discount,
        COALESCE((SELECT SUM(pr.tax_amount) FROM sales_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved'), 0) as returned_tax,
        COALESCE((SELECT SUM(pr.delivery_charges) FROM sales_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved'), 0) as returned_delivery,
        COALESCE((SELECT SUM(pr.total_amount) FROM sales_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved'), 0) as returned_total
    FROM sales_invoices pi
    WHERE pi.id = :invoice_id";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $max_delivery_charges = $result['invoice_delivery'] - $result['returned_delivery'];
        if ($max_delivery_charges < 0) $max_delivery_charges = 0;
        
        // Calculate remaining amounts for each component
        $remaining_subtotal = $result['invoice_subtotal'] - $result['returned_subtotal'];
        $remaining_discount = $result['invoice_discount'] - $result['returned_discount'];
        $remaining_tax = $result['invoice_tax'] - $result['returned_tax'];
        $remaining_total = $result['invoice_total'] - $result['returned_total'];
        
        if ($remaining_subtotal < 0) $remaining_subtotal = 0;
        if ($remaining_discount < 0) $remaining_discount = 0;
        if ($remaining_tax < 0) $remaining_tax = 0;
        if ($remaining_total < 0) $remaining_total = 0;
        
        echo json_encode([
            'success' => true,
            'max_delivery_charges' => $max_delivery_charges,
            'original_delivery_charges' => $result['invoice_delivery'],
            'returned_delivery_charges' => $result['returned_delivery'],
            // Include all invoice details
            'invoice_subtotal' => $result['invoice_subtotal'],
            'invoice_discount' => $result['invoice_discount'],
            'invoice_tax' => $result['invoice_tax'],
            'invoice_total' => $result['invoice_total'],
            'returned_subtotal' => $result['returned_subtotal'],
            'returned_discount' => $result['returned_discount'],
            'returned_tax' => $result['returned_tax'],
            'returned_total' => $result['returned_total'],
            // Include remaining amounts
            'remaining_subtotal' => $remaining_subtotal,
            'remaining_discount' => $remaining_discount,
            'remaining_tax' => $remaining_tax,
            'remaining_total' => $remaining_total
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    error_log('Error in get_invoice_delivery_charges.php: ' . $e->getMessage());
}
?>
