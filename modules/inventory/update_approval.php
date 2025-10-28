<?php
/**
 * Script to update all discount approval statuses to 'approved'
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection
require_once '../../config/database.php';

// Create database connection
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    echo "Database connection error: " . $e->getMessage();
    exit();
}

try {
    // Start transaction
    $db->beginTransaction();
    
    // First, check how many discounts are in the system
    $check_query = "SELECT COUNT(*) FROM discounts";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute();
    $total_count = $check_stmt->fetchColumn();
    
    echo "<p>Total discounts in system: $total_count</p>";
    
    // Check how many discounts have pending approval status
    $pending_query = "SELECT COUNT(*) FROM discounts WHERE approval_status = 'pending'";
    $pending_stmt = $db->prepare($pending_query);
    $pending_stmt->execute();
    $pending_count = $pending_stmt->fetchColumn();
    
    echo "<p>Discounts with pending approval: $pending_count</p>";
    
    // Update all discounts to have approval_status = 'approved'
    $query = "UPDATE discounts SET approval_status = 'approved' WHERE approval_status = 'pending'";
    $stmt = $db->prepare($query);
    $result = $stmt->execute();
    
    $count = $stmt->rowCount();
    
    // Commit transaction
    $db->commit();
    
    echo "<p>Success! Updated approval status for $count discounts.</p>";
    
    // Show all discounts with their approval status
    $list_query = "SELECT id, discount_name, discount_code, approval_status FROM discounts";
    $list_stmt = $db->prepare($list_query);
    $list_stmt->execute();
    $discounts = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>All Discounts:</h3>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Name</th><th>Code</th><th>Approval Status</th></tr>";
    
    foreach ($discounts as $discount) {
        echo "<tr>";
        echo "<td>{$discount['id']}</td>";
        echo "<td>{$discount['discount_name']}</td>";
        echo "<td>{$discount['discount_code']}</td>";
        echo "<td>{$discount['approval_status']}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch (Exception $e) {
    // Rollback transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo "Error: " . $e->getMessage();
}
?>
