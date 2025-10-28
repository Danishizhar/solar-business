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

// Check if supplier ID is provided
if (!isset($_POST['supplier_id']) || empty($_POST['supplier_id'])) {
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required']);
    exit();
}

$supplier_id = $_POST['supplier_id'];

try {
    // Get supplier details
    $query = "SELECT 
                id, 
                supplier_name, 
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
              FROM suppliers 
              WHERE id = :supplier_id AND status = 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':supplier_id', $supplier_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Format address
        $formatted_address = $supplier['address'];
        if (!empty($supplier['city'])) {
            $formatted_address .= ", " . $supplier['city'];
        }
        if (!empty($supplier['state'])) {
            $formatted_address .= ", " . $supplier['state'];
        }
        if (!empty($supplier['postal_code'])) {
            $formatted_address .= " " . $supplier['postal_code'];
        }
        if (!empty($supplier['country'])) {
            $formatted_address .= ", " . $supplier['country'];
        }
        
        $supplier['formatted_address'] = $formatted_address;
        
        echo json_encode([
            'success' => true,
            'supplier' => $supplier
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Supplier not found'
        ]);
    }
} catch (Exception $e) {
    // Log error
    error_log("Error fetching supplier details: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
