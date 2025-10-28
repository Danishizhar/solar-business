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
    if (empty($_POST['product_id']) || empty($_POST['location_id']) || !isset($_POST['quantity'])) {
        $_SESSION['error_message'] = "Product, location, and quantity are required";
        header("Location: ../stock.php");
        exit();
    }
    
    // Check if notes are required
    if (areNotesRequiredForTransactions($db) && empty($_POST['notes'])) {
        $_SESSION['error_message'] = "Notes are required for stock transactions";
        header("Location: ../stock.php");
        exit();
    }
    
    $product_id = (int)$_POST['product_id'];
    $location_id = (int)$_POST['location_id'];
    $quantity = (float)$_POST['quantity'];
    $reorder_level = isset($_POST['reorder_level']) ? (float)$_POST['reorder_level'] : 0;
    $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        
        // Check if product exists
        $product_query = "SELECT id, product_name FROM products WHERE id = :id AND status = 1";
        $product_stmt = $db->prepare($product_query);
        $product_stmt->bindParam(':id', $product_id, PDO::PARAM_INT);
        $product_stmt->execute();
        $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            throw new Exception("Product does not exist");
        }
        
        // Check if location exists
        $location_query = "SELECT id, location_name FROM inventory_locations WHERE id = :id AND status = 1";
        $location_stmt = $db->prepare($location_query);
        $location_stmt->bindParam(':id', $location_id, PDO::PARAM_INT);
        $location_stmt->execute();
        $location = $location_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$location) {
            throw new Exception("Location does not exist");
        }
        
        // Check if stock record already exists for this product and location
        $check_query = "SELECT id FROM inventory_stock 
                       WHERE product_id = :product_id AND location_id = :location_id AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $check_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
        $check_stmt->execute();
        $existing_stock = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_stock) {
            throw new Exception("Stock record already exists for this product and location. Please use adjust stock instead.");
        }
        
        // Insert new stock record
        $query = "INSERT INTO inventory_stock (
                 product_id, location_id, quantity, available_quantity, 
                 reserved_quantity, status,
                 created_by, updated_by, created_at, updated_at
                 ) VALUES (
                 :product_id, :location_id, :quantity, :available_quantity, 
                 0, 1,
                 :created_by, :updated_by, NOW(), NOW()
                 )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
        $stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
        $stmt->bindParam(':available_quantity', $quantity, PDO::PARAM_STR);
        $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        
        $stmt->execute();
        $stock_id = $db->lastInsertId();
        
        // Get transaction type ID for GRN (Goods Receipt)
        $type_query = "SELECT id FROM inventory_transaction_types WHERE transaction_type_code = 'GRN'";
        $type_stmt = $db->prepare($type_query);
        $type_stmt->execute();
        $transaction_type_id = $type_stmt->fetchColumn();
        
        if (!$transaction_type_id) {
            // If not found, use default ID 1 (GRN from the SQL file)
            $transaction_type_id = 1;
        }
        
        // Generate transaction number based on settings
        if (shouldAutoGenerateTransactionNumbers($db)) {
            $transaction_number = generateTransactionNumber($db, 'GRN');
        } else {
            $transaction_number = 'GRN-' . date('YmdHis');
        }
        
        // Record inventory transaction
        $transaction_query = "INSERT INTO inventory_transactions (
                            transaction_number, transaction_date, transaction_type_id, 
                            reference_type, reference_id, notes, status,
                            created_by, updated_by, created_at, updated_at
                            ) VALUES (
                            :transaction_number, NOW(), :transaction_type_id, 
                            'Initial Stock', :stock_id, :notes, 1,
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
        $details_stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
        $details_stmt->bindParam(':uom_id', $uom_id, PDO::PARAM_INT);
        $details_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
        $details_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        $details_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        
        $details_stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Stock added successfully for {$product['product_name']} at {$location['location_name']}";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in add_stock.php: " . $e->getMessage());
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
        
        error_log("Error in add_stock.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../stock.php");
    exit();
} else {
    // If not a POST request, redirect to stock page
    header("Location: ../stock.php");
    exit();
}
