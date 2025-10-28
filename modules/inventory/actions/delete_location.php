<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Include helper functions
require_once '../includes/inventory_helpers.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    if (empty($_POST['location_id'])) {
        $_SESSION['error_message'] = "Location ID is required";
        header("Location: ../locations.php");
        exit();
    }
    
    $location_id = (int)$_POST['location_id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        
        // Check if location exists and is not default
        $check_query = "SELECT id, is_default, location_name FROM inventory_locations WHERE id = ? AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$location_id]);
        $location = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$location) {
            throw new Exception("Location does not exist");
        }
        
        if ($location['is_default'] == 1) {
            throw new Exception("Cannot delete the default location");
        }
        
        // Check if any child locations exist
        $child_query = "SELECT COUNT(*) as count FROM inventory_locations WHERE parent_id = ? AND status = 1";
        $child_stmt = $db->prepare($child_query);
        $child_stmt->execute([$location_id]);
        $child_result = $child_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($child_result['count'] > 0) {
            throw new Exception("Cannot delete location with child locations. Please remove child locations first.");
        }
        
        // Check if location has any stock items
        $stock_query = "SELECT COUNT(*) as count FROM inventory_stock WHERE location_id = ? AND status = 1";
        $stock_stmt = $db->prepare($stock_query);
        $stock_stmt->execute([$location_id]);
        $stock_result = $stock_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($stock_result['count'] > 0) {
            throw new Exception("Cannot delete location with associated stock items. This location has {$stock_result['count']} stock items.");
        }
        
        // Check if location is used in any inventory transaction details
        $trans_query = "SELECT COUNT(*) as count FROM inventory_transaction_details WHERE from_location_id = ? OR to_location_id = ?";
        $trans_stmt = $db->prepare($trans_query);
        $trans_stmt->execute([$location_id, $location_id]);
        $trans_result = $trans_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($trans_result['count'] > 0) {
            throw new Exception("Cannot delete location that is used in inventory transactions. This location is referenced in {$trans_result['count']} transaction details.");
        }
        
        // Hard delete the location
        $query = "DELETE FROM inventory_locations WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$location_id]);
        
        $db->commit();
        $_SESSION['success_message'] = "Location '{$location['location_name']}' permanently deleted successfully";
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Database error in delete_location.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Error in delete_location.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../locations.php");
    exit();
} else {
    // If not a POST request, redirect to locations page
    header("Location: ../locations.php");
    exit();
}
