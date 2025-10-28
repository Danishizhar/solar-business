<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Authentication required</div>';
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Include helper functions
require_once '../../../includes/company_helpers.php';
require_once '../includes/inventory_helpers.php';

// Check if product ID and location ID are provided
if (!isset($_GET['product_id']) || !isset($_GET['location_id'])) {
    echo '<div class="alert alert-danger">Product ID and Location ID are required</div>';
    exit();
}

$product_id = (int)$_GET['product_id'];
$location_id = (int)$_GET['location_id'];

try {
    // Enable detailed error logging
    error_log("Starting get_stock_history.php with product_id={$product_id} and location_id={$location_id}");
    
    // Get product and location details
    $info_query = "SELECT p.product_name, p.product_code, l.location_name 
                  FROM products p 
                  LEFT JOIN inventory_locations l ON l.id = :location_id 
                  WHERE p.id = :product_id";
    $info_stmt = $db->prepare($info_query);
    $info_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $info_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
    $info_stmt->execute();
    $info = $info_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$info) {
        echo '<div class="alert alert-danger">Product or location not found</div>';
        exit();
    }
    
    // Get current stock quantity
    $stock_query = "SELECT quantity FROM inventory_stock 
                    WHERE product_id = :product_id AND location_id = :location_id";
    $stock_stmt = $db->prepare($stock_query);
    $stock_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stock_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
    $stock_stmt->execute();
    $current_quantity = $stock_stmt->fetchColumn() ?: 0;
    
    // Get transaction history
    // For transfers, we only want to show the relevant part of the transaction for this location
    // If this is the source location, show the outgoing transfer (negative quantity)
    // If this is the destination location, show the incoming transfer (positive quantity)
    $query = "SELECT 
             t.id, t.transaction_number, t.transaction_date, t.created_at, t.notes, t.reference_type,
             tt.transaction_type_name as transaction_type,
             td.quantity as quantity_change, 
             td.from_location_id, td.to_location_id,
             u.username as created_by_name
             FROM inventory_transactions t
             LEFT JOIN inventory_transaction_details td ON td.transaction_id = t.id
             LEFT JOIN inventory_transaction_types tt ON tt.id = t.transaction_type_id
             LEFT JOIN users u ON t.created_by = u.id
             WHERE td.product_id = :product_id 
             AND (
                 (td.to_location_id = :location_id AND td.from_location_id != :location_id2) OR 
                 (td.from_location_id = :location_id2 AND td.to_location_id != :location_id)
             )
             AND t.status = 1
             GROUP BY t.id, td.from_location_id, td.to_location_id
             ORDER BY t.created_at DESC
             LIMIT 50";
    
    // Debug the query
    error_log("Stock history query: " . $query);
    error_log("Parameters: product_id={$product_id}, location_id={$location_id}");
    
    // First, get all transaction IDs that involve this product and location
    // This approach breaks the complex query into simpler parts to avoid parameter binding issues
    $transaction_ids_query = "SELECT DISTINCT td.transaction_id 
                            FROM inventory_transaction_details td 
                            WHERE td.product_id = ? 
                            AND (td.from_location_id = ? OR td.to_location_id = ?)
                            ORDER BY td.transaction_id DESC
                            LIMIT 50";
    
    error_log("Transaction IDs query: " . $transaction_ids_query);
    error_log("Parameters: product_id={$product_id}, location_id={$location_id}");
    
    $transaction_ids_stmt = $db->prepare($transaction_ids_query);
    $transaction_ids_stmt->execute([$product_id, $location_id, $location_id]);
    $transaction_ids = $transaction_ids_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    error_log("Found " . count($transaction_ids) . " unique transaction IDs");
    
    // If no transactions found, return empty array
    if (empty($transaction_ids)) {
        $transactions = [];
    } else {
        // Convert the array of IDs to a comma-separated string for the IN clause
        $transaction_ids_str = implode(',', $transaction_ids);
        
        // Now get the transaction details using the transaction IDs
        $query = "SELECT 
                t.id, t.transaction_number, t.transaction_date, t.created_at, t.notes, t.reference_type,
                tt.transaction_type_name as transaction_type,
                td.quantity as quantity_change, 
                td.from_location_id, td.to_location_id,
                u.username as created_by_name
                FROM inventory_transactions t
                JOIN inventory_transaction_details td ON td.transaction_id = t.id
                LEFT JOIN inventory_transaction_types tt ON tt.id = t.transaction_type_id
                LEFT JOIN users u ON t.created_by = u.id
                WHERE t.id IN ({$transaction_ids_str})
                AND td.product_id = ?
                AND (td.from_location_id = ? OR td.to_location_id = ?)
                AND t.status = 1
                ORDER BY t.created_at DESC";
        
        error_log("Main query: " . $query);
        
        $stmt = $db->prepare($query);
        $stmt->execute([$product_id, $location_id, $location_id]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Query executed successfully, found " . count($transactions) . " transactions");
    }
    
    // Filter out duplicate transactions (keep only one record per transaction)
    $processed_transaction_ids = [];
    $filtered_transactions = [];
    
    foreach ($transactions as $transaction) {
        if (!in_array($transaction['id'], $processed_transaction_ids)) {
            $filtered_transactions[] = $transaction;
            $processed_transaction_ids[] = $transaction['id'];
        }
    }
    
    $transactions = $filtered_transactions;
    error_log("After filtering duplicates: " . count($transactions) . " transactions");
    
    // Debug the results
    error_log("Found " . count($transactions) . " transactions");
    
    // COMPLETELY REWRITTEN TRANSACTION HISTORY CALCULATION
    // Get the current stock quantity to use as a reference
    $stock_query = "SELECT quantity FROM inventory_stock 
                    WHERE product_id = :product_id AND location_id = :location_id";
    $stock_stmt = $db->prepare($stock_query);
    $stock_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stock_stmt->bindParam(':location_id', $location_id, PDO::PARAM_INT);
    $stock_stmt->execute();
    $current_quantity = $stock_stmt->fetchColumn() ?: 0;
    
    // Get transactions in chronological order (oldest first)
    $transactions = array_reverse($transactions);
    
    // Create a new array to hold processed transactions
    $processed_transactions = [];
    
    // Process each transaction to determine its effect
    $running_total = 0; // Start with zero
    
    foreach ($transactions as $transaction) {
        // Store the before value (current running total)
        $before_value = $running_total;
        
        // Determine the change based on transaction type
        $change_value = 0;
        
        if ($transaction['transaction_type'] == 'Goods Receipt') {
            // For goods receipt, it's always a positive change
            $change_value = abs($transaction['quantity_change']);
        } 
        else if ($transaction['transaction_type'] == 'Adjustment') {
            // For adjustments, we need to understand how the quantity_change is stored
            // In quick_adjust_stock.php, the quantity_change is calculated as (new_quantity - old_quantity)
            // This means:
            // - If new_quantity > old_quantity (stock increased), quantity_change is positive
            // - If new_quantity < old_quantity (stock decreased), quantity_change is negative
            
            // For display purposes, we want to show:
            // - Positive value when stock increases
            // - Negative value when stock decreases
            
            // So for adjustments, we should use the quantity_change directly
            $change_value = $transaction['quantity_change'];
            
            // Debug
            error_log(sprintf(
                "Adjustment: ID=%s, Reference=%s, DB quantity_change=%s, display_change=%s",
                $transaction['id'],
                $transaction['reference_type'],
                $transaction['quantity_change'],
                $change_value
            ));
        }
        else if ($transaction['to_location_id'] == $location_id && $transaction['from_location_id'] != $location_id) {
            // Incoming transfer - positive change
            $change_value = abs($transaction['quantity_change']);
        } 
        else if ($transaction['from_location_id'] == $location_id && $transaction['to_location_id'] != $location_id) {
            // Outgoing transfer - negative change
            $change_value = -abs($transaction['quantity_change']);
        }
        
        // Calculate the after value
        $after_value = $before_value + $change_value;
        
        // No special cases needed anymore - the calculation should work correctly for all adjustments
        
        // Update the running total for the next transaction
        $running_total = $after_value;
        
        // Add the processed transaction to our array
        $processed_transaction = $transaction;
        $processed_transaction['quantity_before'] = $before_value;
        $processed_transaction['effective_change'] = $change_value;
        $processed_transaction['quantity_after'] = $after_value;
        $processed_transactions[] = $processed_transaction;
        
        // Debug log
        error_log(sprintf(
            'Transaction: %s, Type: %s, Before: %.2f, Change: %.2f, After: %.2f', 
            $transaction['transaction_number'],
            $transaction['transaction_type'],
            $before_value,
            $change_value,
            $after_value
        ));
    }
    
    // Output the history
    ?>
    <div class="mb-3">
        <h6>Product: <?php echo htmlspecialchars($info['product_name']); ?> (<?php echo htmlspecialchars($info['product_code']); ?>)</h6>
        <h6>Location: <?php echo htmlspecialchars($info['location_name'] ?? 'Unassigned'); ?></h6>
    </div>
    
    <?php if (empty($transactions)): ?>
    <div class="alert alert-info">No transaction history found for this product at this location.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-sm">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Transaction Type</th>
                    <th>Before</th>
                    <th>Change</th>
                    <th>After</th>
                    <th>Reference</th>
                    <th>User</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($processed_transactions as $transaction): ?>
                <tr>
                    <td><?php echo date('Y-m-d H:i', strtotime($transaction['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($transaction['transaction_type'] ?? 'Unknown'); ?></td>
                    <td><?php echo formatNumber($db, $transaction['quantity_before']); ?></td>
                    <td>
                        <?php if (isset($transaction['effective_change']) && $transaction['effective_change'] > 0): ?>
                        <span class="text-success">+<?php echo formatNumber($db, $transaction['effective_change']); ?></span>
                        <?php elseif (isset($transaction['effective_change']) && $transaction['effective_change'] < 0): ?>
                        <span class="text-danger"><?php echo formatNumber($db, $transaction['effective_change']); ?></span>
                        <?php else: ?>
                        <?php echo isset($transaction['effective_change']) ? formatNumber($db, $transaction['effective_change']) : formatNumber($db, 0); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo formatNumber($db, $transaction['quantity_after']); ?></td>
                    <td><?php echo htmlspecialchars($transaction['reference_type'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($transaction['created_by_name'] ?? 'System'); ?></td>
                    <td><?php echo nl2br(htmlspecialchars($transaction['notes'] ?? '')); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php
} catch (PDOException $e) {
    error_log("Database error in get_stock_history.php: " . $e->getMessage());
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
} catch (Exception $e) {
    error_log("Error in get_stock_history.php: " . $e->getMessage());
    echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
}
