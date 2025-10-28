<?php
/**
 * Script to update all discount approval statuses to 'approved'
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo "You must be logged in to run this script.";
    exit();
}

// Include database connection
require_once '../../../config/database.php';

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
    
    // Update all discounts to have approval_status = 'approved'
    $query = "UPDATE discounts SET approval_status = 'approved' WHERE approval_status = 'pending'";
    $stmt = $db->prepare($query);
    $result = $stmt->execute();
    
    $count = $stmt->rowCount();
    
    // Commit transaction
    $db->commit();
    
    echo "Success! Updated approval status for $count discounts.";
    
} catch (Exception $e) {
    // Rollback transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo "Error: " . $e->getMessage();
}
?>
