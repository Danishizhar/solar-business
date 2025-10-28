<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if invoice_id is provided
if (!isset($_POST['invoice_id']) || empty($_POST['invoice_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invoice ID is required']);
    exit;
}

$invoice_id = $_POST['invoice_id'];

try {
    // Get invoice location
    $query = "SELECT location_id FROM sales_invoices WHERE id = :invoice_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'success' => true, 
            'location_id' => $result['location_id']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    error_log('Error in get_invoice_location.php: ' . $e->getMessage());
}
?>
