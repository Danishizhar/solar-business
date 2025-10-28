<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if customer ID is provided
if (!isset($_POST['customer_id']) || empty($_POST['customer_id'])) {
    echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
    exit();
}

$customer_id = $_POST['customer_id'];

try {
    // Get customer details
    $query = "SELECT 
                id, 
                customer_name, 
                contact_person, 
                phone, 
                email, 
                address, 
                city, 
                state, 
                postal_code, 
                country, 
                tax_number, 
                payment_terms, 
                credit_limit 
              FROM customers 
              WHERE id = :customer_id AND status = 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':customer_id', $customer_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Format address
        $formatted_address = $customer['address'];
        if (!empty($customer['city'])) {
            $formatted_address .= ", " . $customer['city'];
        }
        if (!empty($customer['state'])) {
            $formatted_address .= ", " . $customer['state'];
        }
        if (!empty($customer['postal_code'])) {
            $formatted_address .= " " . $customer['postal_code'];
        }
        if (!empty($customer['country'])) {
            $formatted_address .= ", " . $customer['country'];
        }
        
        $customer['formatted_address'] = $formatted_address;
        
        echo json_encode([
            'success' => true,
            'customer' => $customer
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found'
        ]);
    }
} catch (Exception $e) {
    // Log error
    error_log("Error fetching customer details: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
