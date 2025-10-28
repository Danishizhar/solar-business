<?php
/**
 * Verify discount PIN for approval
 * This file handles PIN verification for discounts that require approval
 */

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit;
}

// Include database connection
require_once '../../../includes/db_connection.php';
$db = new DBConnection();
$conn = $db->getConnection();

// Get discount ID and PIN from request
$discount_id = isset($_POST['discount_id']) ? intval($_POST['discount_id']) : 0;
$pin = isset($_POST['pin']) ? trim($_POST['pin']) : '';

// Validate inputs
if (empty($discount_id) || empty($pin)) {
    echo json_encode([
        'success' => false,
        'message' => 'Discount ID and PIN are required'
    ]);
    exit;
}

try {
    // Get discount details and approval PIN
    $query = "SELECT id, discount_name, approval_pin FROM discounts WHERE id = :discount_id AND requires_approval = 1";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':discount_id', $discount_id);
    $stmt->execute();
    
    $discount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$discount) {
        echo json_encode([
            'success' => false,
            'message' => 'Discount not found or does not require approval'
        ]);
        exit;
    }
    
    // Verify PIN
    if ($discount['approval_pin'] === $pin) {
        // PIN is correct, log the approval
        $log_query = "INSERT INTO discount_approval_logs (discount_id, approved_by, approved_at) 
                     VALUES (:discount_id, :user_id, NOW())";
        $log_stmt = $conn->prepare($log_query);
        $log_stmt->bindParam(':discount_id', $discount_id);
        $log_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $log_stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'PIN verified successfully'
        ]);
    } else {
        // PIN is incorrect
        echo json_encode([
            'success' => false,
            'message' => 'Invalid PIN'
        ]);
    }
} catch (PDOException $e) {
    error_log("Error verifying discount PIN: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while verifying the PIN'
    ]);
}
?>
