<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Get the highest supplier code
    $query = "SELECT supplier_code FROM suppliers ORDER BY supplier_code DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_code = $result['supplier_code'];
        
        // Extract numeric part if the code follows a pattern like 'SUP-0001'
        if (preg_match('/^SUP-(\d+)$/', $current_code, $matches)) {
            $number = (int)$matches[1];
            $next_number = $number + 1;
            $next_code = 'SUP-' . str_pad($next_number, 4, '0', STR_PAD_LEFT);
        } else {
            // If no pattern or first entry
            $next_code = 'SUP-0001';
        }
    } else {
        // No suppliers yet
        $next_code = 'SUP-0001';
    }
    
    echo json_encode(['success' => true, 'next_code' => $next_code]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
