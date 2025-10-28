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
require_once '../includes/stock_settings_helper.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    if (empty($_POST['stock_id']) || empty($_POST['product_id']) || empty($_POST['location_id']) || 
        !isset($_POST['new_quantity']) || empty($_POST['reason'])) {
        $_SESSION['error_message'] = "All fields are required";
        header("Location: ../stock.php");
        exit();
    }
    
    // Check if notes are required based on settings
    if (areNotesRequiredForTransactions($db) && empty($_POST['notes'])) {
        $_SESSION['error_message'] = "Notes are required for stock adjustments based on your system settings";
        header("Location: ../stock.php");
        exit();
    }
    
    $stock_id = (int)$_POST['stock_id'];
    $product_id = (int)$_POST['product_id'];
    $location_id = (int)$_POST['location_id'];
    $new_quantity = (float)$_POST['new_quantity'];
    $reason = trim($_POST['reason']);
    $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        
        // Get current stock record
        $query = "SELECT quantity, available_quantity, reserved_quantity FROM inventory_stock 
                 WHERE id = :id AND product_id = :product_id AND location_id = :location_id AND status = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $stock_id, PDO::PARAM_INT);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
        $stmt->execute();
        $stock = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$stock) {
            throw new Exception("Stock record not found");
        }
        
        $current_quantity = (float)$stock['quantity'];
        $current_available = (float)$stock['available_quantity'];
        $current_reserved = (float)$stock['reserved_quantity'];
        
        // Calculate the change in quantity
        $quantity_change = $new_quantity - $stock['quantity'];
        
        // Check if this would result in negative stock and if that's allowed
        if ($new_quantity < 0 && !areNegativeStockAdjustmentsAllowed($db)) {
            throw new Exception("Negative stock adjustments are not allowed. Please check your inventory settings.");
        }
        
        // Calculate new available quantity (ensuring reserved items are preserved)
        $new_available_quantity = max(0, $new_quantity - $current_reserved);
        
        // Update stock record
        $update_query = "UPDATE inventory_stock SET 
                        quantity = :new_quantity,
                        available_quantity = :new_available_quantity,
                        updated_at = NOW(),
                        updated_by = :user_id
                        WHERE id = :id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':new_quantity', $new_quantity, PDO::PARAM_STR);
        $update_stmt->bindParam(':new_available_quantity', $new_available_quantity, PDO::PARAM_STR);
        $update_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $update_stmt->bindParam(':id', $stock_id, PDO::PARAM_INT);
        $update_stmt->execute();
        
        // Get transaction type ID for adjustment
        $type_query = "SELECT id FROM inventory_transaction_types WHERE transaction_type_code = 'ADJ'";
        $type_stmt = $db->prepare($type_query);
        $type_stmt->execute();
        $transaction_type_id = $type_stmt->fetchColumn();
        
        if (!$transaction_type_id) {
            // If not found, use default ID 4 (ADJ from the SQL file)
            $transaction_type_id = 4;
        }
        
        // Generate transaction number
        $transaction_number = 'ADJ' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Record inventory transaction
        $transaction_query = "INSERT INTO inventory_transactions (
                            transaction_number, transaction_date, transaction_type_id, 
                            reference_type, reference_id, notes, status,
                            created_by, updated_by, created_at, updated_at
                            ) VALUES (
                            :transaction_number, NOW(), :transaction_type_id, 
                            'Quick Adjustment', :stock_id, :notes, 1,
                            :created_by, :updated_by, NOW(), NOW()
                            )";
        
        $transaction_stmt = $db->prepare($transaction_query);
        $transaction_stmt->bindParam(':transaction_number', $transaction_number, PDO::PARAM_STR);
        $transaction_stmt->bindParam(':transaction_type_id', $transaction_type_id, PDO::PARAM_INT);
        $transaction_stmt->bindParam(':stock_id', $stock_id, PDO::PARAM_INT);
        $transaction_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
        $transaction_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        $transaction_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        
        $transaction_stmt->execute();
        $transaction_id = $db->lastInsertId();
        
        // Record transaction details
        $details_query = "INSERT INTO inventory_transaction_details (
                         transaction_id, product_id, to_location_id, quantity, 
                         uom_id, unit_cost, total_cost, notes, status,
                         created_by, updated_by, created_at, updated_at
                         ) VALUES (
                         :transaction_id, :product_id, :location_id, :quantity, 
                         :uom_id, 0, 0, :notes, 1,
                         :created_by, :updated_by, NOW(), NOW()
                         )";
        
        // Get default UOM if available
        $uom_query = "SELECT base_uom_id FROM products WHERE id = :product_id";
        $uom_stmt = $db->prepare($uom_query);
        $uom_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $uom_stmt->execute();
        $uom_id = $uom_stmt->fetchColumn() ?: 1; // Default to 1 (Each) if not found
        
        $details_stmt = $db->prepare($details_query);
        $details_stmt->bindParam(':transaction_id', $transaction_id, PDO::PARAM_INT);
        $details_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $details_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
        $details_stmt->bindParam(':quantity', $quantity_change, PDO::PARAM_STR);
        $details_stmt->bindParam(':uom_id', $uom_id, PDO::PARAM_INT);
        $details_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
        $details_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        $details_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        
        $details_stmt->execute();
        
        $db->commit();
        
        // Get product and location names for the success message
        $info_query = "SELECT p.product_name, l.location_name 
                      FROM products p 
                      JOIN inventory_locations l ON l.id = :location_id 
                      WHERE p.id = :product_id";
        $info_stmt = $db->prepare($info_query);
        $info_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $info_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
        $info_stmt->execute();
        $info = $info_stmt->fetch(PDO::FETCH_ASSOC);
        
        $product_name = $info['product_name'] ?? 'Product';
        $location_name = $info['location_name'] ?? 'Location';
        
        $_SESSION['success_message'] = "Stock adjusted successfully for {$product_name} at {$location_name}";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in quick_adjust_stock.php: " . $e->getMessage());
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
        
        error_log("Error in quick_adjust_stock.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../stock.php");
    exit();
} else {
    // If not a POST request, redirect to stock page
    header("Location: ../stock.php");
    exit();
}
