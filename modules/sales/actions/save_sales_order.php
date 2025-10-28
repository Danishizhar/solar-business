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

// Permission check
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['sales_add'])) {
    header("Location: ../../../index.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'redirect' => ''
];

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Get form data
        $sales_order_id = isset($_POST['sales_order_id']) ? $_POST['sales_order_id'] : null;
        $order_number = $_POST['order_number'];
        $customer_id = $_POST['customer_id'];
        $order_date = $_POST['order_date'];
        $expected_delivery_date = !empty($_POST['expected_delivery_date']) ? $_POST['expected_delivery_date'] : null;
        $status = $_POST['status'];
        $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
        $subtotal = $_POST['subtotal'];
        $discount_amount = $_POST['discount_amount'];
        $tax_amount = $_POST['tax_amount'];
        $delivery_charges = isset($_POST['delivery_charges']) ? $_POST['delivery_charges'] : 0;
        $total_amount = $_POST['total_amount'];
        $items = isset($_POST['items']) ? $_POST['items'] : [];
        
        // Check if order number already exists (for new orders)
        if (empty($sales_order_id)) {
            $check_query = "SELECT id FROM sales_orders WHERE order_number = :order_number";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':order_number', $order_number);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Order number already exists. Please use a different order number.");
            }
        }
        
        // Insert or update sales order
        if (empty($sales_order_id)) {
            // Insert new sales order
            $query = "INSERT INTO sales_orders (
                order_number, customer_id, order_date, expected_delivery_date, 
                subtotal, tax_amount, discount_amount, delivery_charges, total_amount, 
                status, notes, created_by, updated_by
            ) VALUES (
                :order_number, :customer_id, :order_date, :expected_delivery_date,
                :subtotal, :tax_amount, :discount_amount, :delivery_charges, :total_amount,
                :status, :notes, :created_by, :updated_by
            )";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':order_number', $order_number);
            $stmt->bindParam(':customer_id', $customer_id);
            $stmt->bindParam(':order_date', $order_date);
            $stmt->bindParam(':expected_delivery_date', $expected_delivery_date);
            $stmt->bindParam(':subtotal', $subtotal);
            $stmt->bindParam(':tax_amount', $tax_amount);
            $stmt->bindParam(':discount_amount', $discount_amount);
            $stmt->bindParam(':delivery_charges', $delivery_charges);
            $stmt->bindParam(':total_amount', $total_amount);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':created_by', $_SESSION['user_id']);
            $stmt->bindParam(':updated_by', $_SESSION['user_id']);
            
            $stmt->execute();
            $sales_order_id = $conn->lastInsertId();
            $message = "Sales order created successfully.";
        } else {
            // Update existing sales order
            $query = "UPDATE sales_orders SET 
                customer_id = :customer_id,
                order_date = :order_date,
                expected_delivery_date = :expected_delivery_date,
                subtotal = :subtotal,
                tax_amount = :tax_amount,
                discount_amount = :discount_amount,
                delivery_charges = :delivery_charges,
                total_amount = :total_amount,
                status = :status,
                notes = :notes,
                updated_by = :updated_by
            WHERE id = :sales_order_id";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':customer_id', $customer_id);
            $stmt->bindParam(':order_date', $order_date);
            $stmt->bindParam(':expected_delivery_date', $expected_delivery_date);
            $stmt->bindParam(':subtotal', $subtotal);
            $stmt->bindParam(':tax_amount', $tax_amount);
            $stmt->bindParam(':discount_amount', $discount_amount);
            $stmt->bindParam(':delivery_charges', $delivery_charges);
            $stmt->bindParam(':total_amount', $total_amount);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':updated_by', $_SESSION['user_id']);
            $stmt->bindParam(':sales_order_id', $sales_order_id);
            
            $stmt->execute();
            $message = "Sales order updated successfully.";
            
            // Delete existing items to replace with new ones
            $delete_query = "DELETE FROM sales_order_items WHERE sales_order_id = :sales_order_id";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bindParam(':sales_order_id', $sales_order_id);
            $delete_stmt->execute();
        }
        
        // Insert sales order items
        if (!empty($items)) {
            $item_query = "INSERT INTO sales_order_items (
                sales_order_id, product_id, quantity, unit_price,
                tax_rate, tax_amount, discount_percent, discount_amount,
                subtotal, total, notes, uom_id
            ) VALUES (
                :sales_order_id, :product_id, :quantity, :unit_price,
                :tax_rate, :tax_amount, :discount_percent, :discount_amount,
                :subtotal, :total, :notes, :uom_id
            )";
            
            $item_stmt = $conn->prepare($item_query);
            
            foreach ($items as $item) {
                // Calculate item values
                $quantity = floatval($item['quantity']);
                $unit_price = floatval($item['unit_price']);
                $discount_percent = floatval($item['discount_percent']);
                $tax_rate = floatval($item['tax_rate']);
                
                $item_subtotal = $quantity * $unit_price;
                $item_discount = $item_subtotal * ($discount_percent / 100);
                $taxable_amount = $item_subtotal - $item_discount;
                $item_tax = $taxable_amount * ($tax_rate / 100);
                $item_total = $taxable_amount + $item_tax;
                
                // Item notes (if any)
                $item_notes = isset($item['notes']) ? $item['notes'] : '';
                
                // Bind parameters
                $item_stmt->bindParam(':sales_order_id', $sales_order_id);
                $item_stmt->bindParam(':product_id', $item['product_id']);
                $item_stmt->bindParam(':quantity', $quantity);
                $item_stmt->bindParam(':unit_price', $unit_price);
                $item_stmt->bindParam(':tax_rate', $tax_rate);
                $item_stmt->bindParam(':tax_amount', $item_tax);
                $item_stmt->bindParam(':discount_percent', $discount_percent);
                $item_stmt->bindParam(':discount_amount', $item_discount);
                $item_stmt->bindParam(':subtotal', $item_subtotal);
                $item_stmt->bindParam(':total', $item_total);
                $item_stmt->bindParam(':notes', $item_notes);
                
                // Bind UOM ID parameter with proper fallback mechanism
                $formUomId = isset($item['uom_id']) && !empty($item['uom_id']) ? $item['uom_id'] : null;
                if (!$formUomId) {
                    // If no UOM ID provided, get the product's sales_uom_id or fall back to base_uom_id
                    $uomQuery = "SELECT COALESCE(sales_uom_id, base_uom_id, 1) AS uom_id FROM products WHERE id = :product_id LIMIT 1";
                    $uomStmt = $conn->prepare($uomQuery);
                    $uomStmt->bindParam(':product_id', $item['product_id'], PDO::PARAM_INT);
                    $uomStmt->execute();
                    $uomData = $uomStmt->fetch(PDO::FETCH_ASSOC);
                    $formUomId = $uomData['uom_id'];
                }
                $item_stmt->bindParam(':uom_id', $formUomId);
                
                $item_stmt->execute();
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Set success response
        $response['success'] = true;
        $response['message'] = $message;
        $response['redirect'] = '../sales_orders.php';
        
        // Redirect to sales orders list
        header("Location: ../sales_orders.php?success=" . urlencode($message));
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        
        $response['success'] = false;
        $response['message'] = "Error: " . $e->getMessage();
        
        // Redirect back with error
        header("Location: ../sales_order_create.php?id=" . $sales_order_id . "&error=" . urlencode($e->getMessage()));
        exit();
    }
} else {
    // Not a POST request
    header("Location: ../sales_orders.php");
    exit();
}
