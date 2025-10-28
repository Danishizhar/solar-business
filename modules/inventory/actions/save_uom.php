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
require_once '../includes/uom_settings_helper.php';
$database = new Database();
$db = $database->getConnection();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    if (empty($_POST['uom_name'])) {
        $_SESSION['error_message'] = "UOM name is required";
        header("Location: ../uom.php");
        exit();
    }
    
    if (empty($_POST['uom_symbol'])) {
        $_SESSION['error_message'] = "UOM symbol is required";
        header("Location: ../uom.php");
        exit();
    }
    
    if (empty($_POST['uom_type'])) {
        $_SESSION['error_message'] = "UOM type is required";
        header("Location: ../uom.php");
        exit();
    }
    
    $uom_name = trim($_POST['uom_name']);
    $uom_symbol = trim($_POST['uom_symbol']);
    $uom_type = trim($_POST['uom_type']);
    $description = isset($_POST['description']) ? trim($_POST['description']) : null;
    $user_id = $_SESSION['user_id'];
    
    // Validate against UOM settings
    $allowed_types = getAllowedUOMTypes($db);
    // Case-sensitive comparison
    if (!in_array($uom_type, $allowed_types, true)) {
        $_SESSION['error_message'] = "Invalid UOM type. Allowed types are: " . implode(', ', $allowed_types);
        header("Location: ../uom.php");
        exit();
    }
    
    // Generate UOM code if auto-generation is enabled
    $uom_code = '';
    if (isAutoGenerateUOMCodeEnabled($db)) {
        $prefix = getUOMCodePrefix($db);
        $uom_code = $prefix . strtoupper(substr(str_replace(' ', '', $uom_name), 0, 3)) . '_' . time();
    } else if (isset($_POST['uom_code']) && !empty($_POST['uom_code'])) {
        $uom_code = trim($_POST['uom_code']);
    } else {
        $_SESSION['error_message'] = "UOM code is required when auto-generation is disabled";
        header("Location: ../uom.php");
        exit();
    }
    
    try {
        $db->beginTransaction();
        
        // Check if UOM with same name, symbol, or code already exists
        $check_query = "SELECT id FROM uom WHERE (uom_name = :uom_name OR uom_symbol = :uom_symbol OR uom_code = :uom_code) AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':uom_name', $uom_name, PDO::PARAM_STR);
        $check_stmt->bindParam(':uom_symbol', $uom_symbol, PDO::PARAM_STR);
        $check_stmt->bindParam(':uom_code', $uom_code, PDO::PARAM_STR);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            throw new Exception("A UOM with the same name, symbol, or code already exists");
        }
        
        // Determine usage flags based on settings
        $can_be_used_for_purchase = canUOMBeUsedForPurchase($db) ? 1 : 0;
        $can_be_used_for_sales = canUOMBeUsedForSales($db) ? 1 : 0;
        $can_be_used_for_inventory = canUOMBeUsedForInventory($db) ? 1 : 0;
        
        // Insert UOM
        $query = "INSERT INTO uom (
                 uom_name, uom_symbol, uom_code, uom_type, description, 
                 can_be_used_for_purchase, can_be_used_for_sales, can_be_used_for_inventory,
                 status, created_by, updated_by, created_at, updated_at
                 ) VALUES (
                 :uom_name, :uom_symbol, :uom_code, :uom_type, :description, 
                 :can_be_used_for_purchase, :can_be_used_for_sales, :can_be_used_for_inventory,
                 1, :created_by, :updated_by, NOW(), NOW()
                 )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':uom_name', $uom_name, PDO::PARAM_STR);
        $stmt->bindParam(':uom_symbol', $uom_symbol, PDO::PARAM_STR);
        $stmt->bindParam(':uom_code', $uom_code, PDO::PARAM_STR);
        $stmt->bindParam(':uom_type', $uom_type, PDO::PARAM_STR);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':can_be_used_for_purchase', $can_be_used_for_purchase, PDO::PARAM_INT);
        $stmt->bindParam(':can_be_used_for_sales', $can_be_used_for_sales, PDO::PARAM_INT);
        $stmt->bindParam(':can_be_used_for_inventory', $can_be_used_for_inventory, PDO::PARAM_INT);
        $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Unit of Measurement added successfully";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in save_uom.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Error in save_uom.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../uom.php");
    exit();
} else {
    // If not a POST request, redirect to UOM page
    header("Location: ../uom.php");
    exit();
}
