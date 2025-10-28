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

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    if (empty($_POST['uom_id'])) {
        $_SESSION['error_message'] = "UOM ID is required";
        header("Location: ../uom.php");
        exit();
    }
    
    $uom_id = (int)$_POST['uom_id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        // Begin transaction
        $db->beginTransaction();
        
        // Get UOM name for success message
        $name_query = "SELECT uom_name FROM uom WHERE id = ?";
        $name_stmt = $db->prepare($name_query);
        $name_stmt->execute([$uom_id]);
        $uom_data = $name_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$uom_data) {
            throw new Exception("UOM not found");
        }
        
        $uom_name = $uom_data['uom_name'];
        
        // First delete any UOM conversions
        $conv_query = "DELETE FROM uom_conversion WHERE from_uom_id = ? OR to_uom_id = ?";
        $conv_stmt = $db->prepare($conv_query);
        $conv_stmt->execute([$uom_id, $uom_id]);
        
        // Then delete the UOM itself
        $delete_query = "DELETE FROM uom WHERE id = ?";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->execute([$uom_id]);
        
        // Commit transaction
        $db->commit();
        
        $_SESSION['success_message'] = "Unit of Measurement '$uom_name' deleted successfully";
    } catch (PDOException $e) {
        // Roll back transaction on error
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        
        error_log("Database error in delete_uom.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        // Roll back transaction on error
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        
        error_log("Error in delete_uom.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    // Redirect back to UOM page
    header("Location: ../uom.php");
    exit();
} else {
    // If not a POST request, redirect to UOM page
    header("Location: ../uom.php");
    exit();
}
