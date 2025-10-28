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
    if (empty($_POST['uom_id'])) {
        $_SESSION['error_message'] = "UOM ID is required";
        header("Location: ../uom.php");
        exit();
    }
    
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
    
    $uom_id = (int)$_POST['uom_id'];
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
    
    // Handle UOM code
    $uom_code = '';
    if (!isAutoGenerateUOMCodeEnabled($db)) {
        if (isset($_POST['uom_code']) && !empty($_POST['uom_code'])) {
            $uom_code = trim($_POST['uom_code']);
        } else {
            $_SESSION['error_message'] = "UOM code is required when auto-generation is disabled";
            header("Location: ../uom.php");
            exit();
        }
    } else {
        // If auto-generation is enabled but we're updating, we should keep the existing code
        // We'll retrieve it from the database in the query below
    }
    
    try {
        $db->beginTransaction();
        
        // Check if UOM exists and get current values
        $check_query = "SELECT id, uom_code FROM uom WHERE id = :id AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $uom_id, PDO::PARAM_INT);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() == 0) {
            throw new Exception("UOM not found");
        }
        
        $current_uom = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        // If we're using auto-generated codes but no code was provided in the form,
        // use the existing code from the database
        if (isAutoGenerateUOMCodeEnabled($db) && empty($uom_code)) {
            $uom_code = $current_uom['uom_code'];
        }
        
        // Check if another UOM with same name, symbol, or code already exists
        $check_query = "SELECT id FROM uom WHERE (uom_name = :uom_name OR uom_symbol = :uom_symbol";
        
        // Only check for code uniqueness if a code is provided
        if (!empty($uom_code)) {
            $check_query .= " OR uom_code = :uom_code";
        }
        
        $check_query .= ") AND id != :id AND status = 1";
        
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':uom_name', $uom_name, PDO::PARAM_STR);
        $check_stmt->bindParam(':uom_symbol', $uom_symbol, PDO::PARAM_STR);
        
        if (!empty($uom_code)) {
            $check_stmt->bindParam(':uom_code', $uom_code, PDO::PARAM_STR);
        }
        
        $check_stmt->bindParam(':id', $uom_id, PDO::PARAM_INT);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            throw new Exception("Another UOM with the same name, symbol, or code already exists");
        }
        
        // Determine usage flags based on settings and form input
        $can_be_used_for_purchase = isset($_POST['can_be_used_for_purchase']) ? 1 : 0;
        $can_be_used_for_sales = isset($_POST['can_be_used_for_sales']) ? 1 : 0;
        $can_be_used_for_inventory = isset($_POST['can_be_used_for_inventory']) ? 1 : 0;
        
        // If settings don't allow certain usages, override the form values
        if (!canUOMBeUsedForPurchase($db)) {
            $can_be_used_for_purchase = 0;
        }
        
        if (!canUOMBeUsedForSales($db)) {
            $can_be_used_for_sales = 0;
        }
        
        if (!canUOMBeUsedForInventory($db)) {
            $can_be_used_for_inventory = 0;
        }
        
        // Update UOM
        $query = "UPDATE uom SET 
                 uom_name = :uom_name,
                 uom_symbol = :uom_symbol,
                 uom_code = :uom_code,
                 uom_type = :uom_type,
                 description = :description,
                 can_be_used_for_purchase = :can_be_used_for_purchase,
                 can_be_used_for_sales = :can_be_used_for_sales,
                 can_be_used_for_inventory = :can_be_used_for_inventory,
                 updated_by = :updated_by,
                 updated_at = NOW()
                 WHERE id = :uom_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':uom_name', $uom_name, PDO::PARAM_STR);
        $stmt->bindParam(':uom_symbol', $uom_symbol, PDO::PARAM_STR);
        $stmt->bindParam(':uom_code', $uom_code, PDO::PARAM_STR);
        $stmt->bindParam(':uom_type', $uom_type, PDO::PARAM_STR);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':can_be_used_for_purchase', $can_be_used_for_purchase, PDO::PARAM_INT);
        $stmt->bindParam(':can_be_used_for_sales', $can_be_used_for_sales, PDO::PARAM_INT);
        $stmt->bindParam(':can_be_used_for_inventory', $can_be_used_for_inventory, PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':uom_id', $uom_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Unit of Measurement updated successfully";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in update_uom.php: " . $e->getMessage());
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
        
        error_log("Error in update_uom.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../uom.php");
    exit();
} else {
    // If not a POST request, redirect to UOM page
    header("Location: ../uom.php");
    exit();
}
