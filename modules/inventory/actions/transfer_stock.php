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
    if (empty($_POST['product_id']) || empty($_POST['from_location_id']) || empty($_POST['to_location_id']) || 
        !isset($_POST['quantity']) || $_POST['from_location_id'] == $_POST['to_location_id']) {
        $_SESSION['error_message'] = "All fields are required and source/destination locations must be different";
        header("Location: ../stock.php");
        exit();
    }
    
    $product_id = (int)$_POST['product_id'];
    $from_location_id = (int)$_POST['from_location_id'];
    $to_location_id = (int)$_POST['to_location_id'];
    $quantity = (float)$_POST['quantity'];
    $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        
        // Check if product exists
        $product_query = "SELECT id, product_name, base_uom_id FROM products WHERE id = :id AND status = 1";
        $product_stmt = $db->prepare($product_query);
        $product_stmt->bindParam(':id', $product_id, PDO::PARAM_INT);
        $product_stmt->execute();
        $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            throw new Exception("Product does not exist");
        }
        
        // Get UOM ID
        $uom_id = $product['base_uom_id'];
        
        // Check if source location exists
        $from_location_query = "SELECT id, location_name FROM inventory_locations WHERE id = :id AND status = 1";
        $from_location_stmt = $db->prepare($from_location_query);
        $from_location_stmt->bindParam(':id', $from_location_id, PDO::PARAM_INT);
        $from_location_stmt->execute();
        $from_location = $from_location_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$from_location) {
            throw new Exception("Source location does not exist");
        }
        
        // Check if destination location exists
        $to_location_query = "SELECT id, location_name FROM inventory_locations WHERE id = :id AND status = 1";
        $to_location_stmt = $db->prepare($to_location_query);
        $to_location_stmt->bindParam(':id', $to_location_id, PDO::PARAM_INT);
        $to_location_stmt->execute();
        $to_location = $to_location_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$to_location) {
            throw new Exception("Destination location does not exist");
        }
        
        // Check if there is enough stock in the source location
        $stock_query = "SELECT id, quantity, available_quantity, reserved_quantity FROM inventory_stock 
                       WHERE product_id = :product_id AND location_id = :location_id AND status = 1";
        $stock_stmt = $db->prepare($stock_query);
        $stock_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stock_stmt->bindParam(':location_id', $from_location_id, PDO::PARAM_INT);
        $stock_stmt->execute();
        $source_stock = $stock_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$source_stock) {
            throw new Exception("No stock found in source location");
        }
        
        if ($source_stock['available_quantity'] < $quantity) {
            throw new Exception("Not enough available stock in source location. Available: " . $source_stock['available_quantity']);
        }
        
        // Check if stock exists in destination location, create if not
        $stock_stmt->bindParam(':location_id', $to_location_id, PDO::PARAM_INT);
        $stock_stmt->execute();
        $dest_stock = $stock_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$dest_stock) {
            // Create new stock record for destination
            $insert_query = "INSERT INTO inventory_stock (
                            product_id, location_id, quantity, available_quantity, 
                            reserved_quantity, status, created_by, updated_by, 
                            created_at, updated_at
                            ) VALUES (
                            :product_id, :location_id, 0, 0, 0, 1, 
                            :created_by, :updated_by, NOW(), NOW()
                            )";
            
            // Debug the query
            error_log("Insert query for new stock record: " . $insert_query);
            error_log("Parameters: product_id={$product_id}, location_id={$to_location_id}, user_id={$user_id}");
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':location_id', $to_location_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
            $insert_stmt->execute();
            
            // Get the new stock record
            $stock_stmt->execute();
            $dest_stock = $stock_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dest_stock) {
                throw new Exception("Failed to create stock record in destination location");
            }
        }
        
        // Update source stock
        $new_source_quantity = $source_stock['quantity'] - $quantity;
        $new_source_available = $source_stock['available_quantity'] - $quantity;
        
        $update_source_query = "UPDATE inventory_stock SET 
                              quantity = :quantity, 
                              available_quantity = :available_quantity, 
                              updated_by = :user_id, 
                              updated_at = NOW() 
                              WHERE id = :id";
        $update_source_stmt = $db->prepare($update_source_query);
        $update_source_stmt->bindParam(':quantity', $new_source_quantity, PDO::PARAM_STR);
        $update_source_stmt->bindParam(':available_quantity', $new_source_available, PDO::PARAM_STR);
        $update_source_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $update_source_stmt->bindParam(':id', $source_stock['id'], PDO::PARAM_INT);
        $update_source_stmt->execute();
        
        // Update destination stock
        $new_dest_quantity = $dest_stock['quantity'] + $quantity;
        $new_dest_available = $dest_stock['available_quantity'] + $quantity;
        
        $update_dest_query = "UPDATE inventory_stock SET 
                            quantity = :quantity, 
                            available_quantity = :available_quantity, 
                            updated_by = :user_id, 
                            updated_at = NOW() 
                            WHERE id = :id";
        $update_dest_stmt = $db->prepare($update_dest_query);
        $update_dest_stmt->bindParam(':quantity', $new_dest_quantity, PDO::PARAM_STR);
        $update_dest_stmt->bindParam(':available_quantity', $new_dest_available, PDO::PARAM_STR);
        $update_dest_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $update_dest_stmt->bindParam(':id', $dest_stock['id'], PDO::PARAM_INT);
        $update_dest_stmt->execute();
        
        // Generate transaction number if enabled
        $transaction_number = '';
        if (shouldAutoGenerateTransactionNumbers($db)) {
            $transaction_number = generateTransactionNumber($db, 'Transfer');
        } else {
            // Use the transaction number prefix from settings
            $prefix = getTransactionNumberPrefix($db);
            $transaction_number = $prefix . date('YmdHis');
        }
        
        // Log the transaction number for debugging
        error_log("Generated transaction number: {$transaction_number}");
        
        // Get the transaction type ID for 'Transfer'
        $type_query = "SELECT id FROM inventory_transaction_types WHERE transaction_type_name = 'Transfer' LIMIT 1";
        $type_stmt = $db->prepare($type_query);
        $type_stmt->execute();
        $transaction_type_id = $type_stmt->fetchColumn();
        
        if (!$transaction_type_id) {
            // If 'Transfer' type doesn't exist, create it
            $insert_type_query = "INSERT INTO inventory_transaction_types (transaction_type_name, description, status) 
                                VALUES ('Transfer', 'Stock transfer between locations', 1)";
            $db->exec($insert_type_query);
            $transaction_type_id = $db->lastInsertId();
        }
        
        // Create transaction record
        $transaction_query = "INSERT INTO inventory_transactions (
                            transaction_number, transaction_type_id, transaction_date, 
                            reference_type, notes, status, created_by, updated_by, 
                            created_at, updated_at
                            ) VALUES (
                            :transaction_number, :transaction_type_id, NOW(), 
                            :reference_type, :notes, 1, :created_by, :updated_by, 
                            NOW(), NOW()
                            )";
        $transaction_stmt = $db->prepare($transaction_query);
        $transaction_stmt->bindParam(':transaction_number', $transaction_number, PDO::PARAM_STR);
        $transaction_stmt->bindParam(':transaction_type_id', $transaction_type_id, PDO::PARAM_INT);
        $reference_type = 'Stock Transfer';
        $transaction_stmt->bindParam(':reference_type', $reference_type, PDO::PARAM_STR);
        $transaction_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
        $transaction_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        $transaction_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        $transaction_stmt->execute();
        
        $transaction_id = $db->lastInsertId();
        
        // Create transaction details for source (negative quantity)
        $source_details_query = "INSERT INTO inventory_transaction_details (
                               transaction_id, product_id, from_location_id, to_location_id,
                               quantity, uom_id, notes, status,
                               created_by, updated_by, created_at, updated_at
                               ) VALUES (
                               :transaction_id, :product_id, :from_location_id, :to_location_id,
                               :quantity, :uom_id, :notes, 1,
                               :created_by, :updated_by, NOW(), NOW()
                               )";
        
        // Debug: Log the query and parameters
        error_log("Source details query: " . $source_details_query);
        error_log("Parameters: transaction_id={$transaction_id}, product_id={$product_id}, from_location_id={$from_location_id}, to_location_id={$to_location_id}, uom_id={$uom_id}");
        
        try {
            $source_details_stmt = $db->prepare($source_details_query);
            $source_details_stmt->bindParam(':transaction_id', $transaction_id, PDO::PARAM_INT);
            $source_details_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $source_details_stmt->bindParam(':from_location_id', $from_location_id, PDO::PARAM_INT);
            $source_details_stmt->bindParam(':to_location_id', $to_location_id, PDO::PARAM_INT);
            $negative_quantity = -1 * $quantity;
            $source_details_stmt->bindParam(':quantity', $negative_quantity, PDO::PARAM_STR);
            $source_details_stmt->bindParam(':uom_id', $uom_id, PDO::PARAM_INT);
            $source_details_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
            $source_details_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
            $source_details_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
            $source_details_stmt->execute();
            error_log("Source details query executed successfully");
        } catch (PDOException $detail_e) {
            error_log("Error in source details query: " . $detail_e->getMessage());
            error_log("SQL state: " . $detail_e->errorInfo[0] ?? 'Unknown');
            throw $detail_e; // Re-throw to be caught by the main catch block
        }
        
        // Create transaction details for destination (positive quantity)
        $dest_details_query = "INSERT INTO inventory_transaction_details (
                             transaction_id, product_id, from_location_id, to_location_id, 
                             quantity, uom_id, notes, status,
                             created_by, updated_by, created_at, updated_at
                             ) VALUES (
                             :transaction_id, :product_id, :from_location_id, :to_location_id, 
                             :quantity, :uom_id, :notes, 1,
                             :created_by, :updated_by, NOW(), NOW()
                             )";
        
        // Debug: Log the query and parameters
        error_log("Destination details query: " . $dest_details_query);
        error_log("Parameters: transaction_id={$transaction_id}, product_id={$product_id}, from_location_id={$from_location_id}, to_location_id={$to_location_id}, uom_id={$uom_id}, quantity={$quantity}");
        
        try {
            $dest_details_stmt = $db->prepare($dest_details_query);
            $dest_details_stmt->bindParam(':transaction_id', $transaction_id, PDO::PARAM_INT);
            $dest_details_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
            $dest_details_stmt->bindParam(':from_location_id', $from_location_id, PDO::PARAM_INT);
            $dest_details_stmt->bindParam(':to_location_id', $to_location_id, PDO::PARAM_INT);
            $dest_details_stmt->bindParam(':quantity', $quantity, PDO::PARAM_STR);
            $dest_details_stmt->bindParam(':uom_id', $uom_id, PDO::PARAM_INT);
            $dest_details_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
            $dest_details_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
            $dest_details_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
            $dest_details_stmt->execute();
            error_log("Destination details query executed successfully");
        } catch (PDOException $detail_e) {
            error_log("Error in destination details query: " . $detail_e->getMessage());
            error_log("SQL state: " . $detail_e->errorInfo[0] ?? 'Unknown');
            throw $detail_e; // Re-throw to be caught by the main catch block
        }
        
        // Commit transaction
        $db->commit();
        
        $_SESSION['success_message'] = "Stock transferred successfully";
        header("Location: ../stock.php");
        exit();
        
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $rollback_exception) {
            error_log("Rollback failed: " . $rollback_exception->getMessage());
        }
        
        // Detailed error logging
        error_log("Database error in transfer_stock.php: " . $e->getMessage());
        error_log("Error code: " . $e->getCode());
        error_log("SQL state: " . $e->errorInfo[0] ?? 'Unknown');
        error_log("Driver error code: " . $e->errorInfo[1] ?? 'Unknown');
        error_log("Driver error message: " . $e->errorInfo[2] ?? 'Unknown');
        error_log("Stack trace: " . $e->getTraceAsString());
        
        // Show detailed error to the user for debugging
        echo "<h1>Database Error</h1>";
        echo "<p>Error message: " . $e->getMessage() . "</p>";
        echo "<p>Error code: " . $e->getCode() . "</p>";
        echo "<p>SQL state: " . ($e->errorInfo[0] ?? 'Unknown') . "</p>";
        echo "<p>Driver error code: " . ($e->errorInfo[1] ?? 'Unknown') . "</p>";
        echo "<p>Driver error message: " . ($e->errorInfo[2] ?? 'Unknown') . "</p>";
        echo "<p>File: " . $e->getFile() . " (Line: " . $e->getLine() . ")</p>";
        echo "<h2>Stack Trace:</h2>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
        
        // Store basic error message in session
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        exit();
    } catch (Exception $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $rollback_exception) {
            error_log("Rollback failed: " . $rollback_exception->getMessage());
        }
        
        error_log("Error in transfer_stock.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: ../stock.php");
        exit();
    }
} else {
    // If not a POST request, redirect to stock page
    header("Location: ../stock.php");
    exit();
}
