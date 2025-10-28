<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure no output before headers
ob_start();

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '../../../logs/php_errors.log');

// Set JSON header
header('Content-Type: application/json');

// Debug: Log incoming data
error_log("Update Budget Request Data: " . print_r($_POST, true));

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_edit'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("Failed to connect to database");
    }
    
    // Debug: Log connection status
    error_log("Database connection established");
    
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Start transaction
    if (!$database->beginTransaction()) {
        throw new Exception("Failed to start transaction");
    }
    error_log("Transaction started");

    // Validate required fields
    $required_fields = ['id', 'fiscal_year', 'account_id', 'period'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            throw new Exception("Field '{$field}' is required");
        }
    }

    // Parse and validate values
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    $fiscal_year = filter_var($_POST['fiscal_year'], FILTER_VALIDATE_INT);
    $account_id = filter_var($_POST['account_id'], FILTER_VALIDATE_INT);
    $cost_center_id = !empty($_POST['cost_center_id']) ? filter_var($_POST['cost_center_id'], FILTER_VALIDATE_INT) : null;
    $period = filter_var($_POST['period'], FILTER_SANITIZE_STRING);
    $notes = filter_var($_POST['notes'] ?? '', FILTER_SANITIZE_STRING);

    // Debug: Log parsed values
    error_log("Parsed values - ID: $id, FY: $fiscal_year, Account: $account_id, Period: $period");

    if (!$id || !$fiscal_year || !$account_id) {
        throw new Exception("Invalid input values");
    }

    // Validate period type
    $valid_periods = ['monthly', 'quarterly', 'yearly'];
    if (!in_array($period, $valid_periods)) {
        throw new Exception("Invalid period type: $period");
    }

    // Check if budget exists and is active with FOR UPDATE lock
    $check_query = "
        SELECT id, period 
        FROM budgets 
        WHERE id = :id 
        AND status = 1
        FOR UPDATE";
    
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $check_stmt->execute();
    
    $current_budget = $check_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current_budget) {
        throw new Exception("Budget not found or inactive");
    }
    error_log("Current budget found: " . print_r($current_budget, true));

    // Verify fiscal year exists, is active, and check if closed
    $year_query = "
        SELECT id, year_code, is_closed 
        FROM financial_years 
        WHERE id = :fiscal_year 
        AND status = 1";
    
    $year_stmt = $conn->prepare($year_query);
    $year_stmt->bindParam(':fiscal_year', $fiscal_year, PDO::PARAM_INT);
    $year_stmt->execute();
    
    $fiscal_year_data = $year_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fiscal_year_data) {
        throw new Exception("Selected fiscal year is not active");
    }
    
    // Check if financial year is closed - only warn but allow update
    if ($fiscal_year_data['is_closed'] == 1) {
        error_log("Warning: Updating budget for closed financial year: " . $fiscal_year_data['year_code']);
        // We don't throw exception here to allow updates to existing budgets in closed years
    }

    // Verify account exists and is active
    $account_query = "
        SELECT id 
        FROM chart_of_accounts 
        WHERE id = :account_id 
        AND status = 1";
    
    $account_stmt = $conn->prepare($account_query);
    $account_stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
    $account_stmt->execute();
    
    if (!$account_stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception("Selected account is not active");
    }

    // Verify cost center if provided
    if ($cost_center_id !== null) {
        $cc_query = "
            SELECT id 
            FROM cost_centers 
            WHERE id = :cost_center_id 
            AND status = 1";
        
        $cc_stmt = $conn->prepare($cc_query);
        $cc_stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
        $cc_stmt->execute();
        
        if (!$cc_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Selected cost center is not active");
        }
    }

    // Check if another budget exists for this account and fiscal year
    $check_duplicate_query = "
        SELECT id 
        FROM budgets 
        WHERE fiscal_year = :fiscal_year 
        AND account_id = :account_id 
        AND ((:cost_center_id IS NULL AND cost_center_id IS NULL) 
            OR cost_center_id = :cost_center_id2)
        AND id != :id 
        AND status = 1";
    
    $duplicate_stmt = $conn->prepare($check_duplicate_query);
    $duplicate_stmt->bindParam(':fiscal_year', $fiscal_year, PDO::PARAM_INT);
    $duplicate_stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
    $duplicate_stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
    $duplicate_stmt->bindParam(':cost_center_id2', $cost_center_id, PDO::PARAM_INT);
    $duplicate_stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $duplicate_stmt->execute();
    
    if ($duplicate_stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception("Budget already exists for this account and fiscal year");
    }

    // Calculate total amount based on period
    $total_amount = 0;
    $period_amounts = [];
    $max_periods = ['monthly' => 12, 'quarterly' => 4, 'yearly' => 1];
    $num_periods = $max_periods[$period];
    $amount_prefix = $period . '_amount_';

    // Debug: Log period details
    error_log("Period type: $period, Max periods: $num_periods");

    // Validate and collect period amounts
    if ($period === 'yearly') {
        if (!isset($_POST['yearly_amount'])) {
            throw new Exception("Yearly amount is required");
        }
        $amount = filter_var($_POST['yearly_amount'], FILTER_VALIDATE_FLOAT);
        if ($amount === false || $amount < 0) {
            throw new Exception("Invalid yearly amount");
        }
        $total_amount = $amount;
        $period_amounts[1] = $amount;
        error_log("Yearly amount: $amount");
    } else {
        for ($i = 1; $i <= $num_periods; $i++) {
            $amount_key = $amount_prefix . $i;
            if (!isset($_POST[$amount_key])) {
                throw new Exception("Missing amount for period $i");
            }

            $amount = filter_var($_POST[$amount_key], FILTER_VALIDATE_FLOAT);
            if ($amount === false || $amount < 0) {
                throw new Exception("Invalid amount for period $i");
            }

            $period_amounts[$i] = $amount;
            $total_amount += $amount;
            error_log("Period $i amount: $amount");
        }
    }

    // Update budget
    $update_query = "
        UPDATE budgets 
        SET
            fiscal_year = :fiscal_year,
            account_id = :account_id,
            cost_center_id = :cost_center_id,
            period = :period,
            amount = :amount,
            notes = :notes,
            updated_by = :updated_by,
            updated_at = NOW()
        WHERE id = :id
        AND status = 1";

    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bindParam(':fiscal_year', $fiscal_year, PDO::PARAM_INT);
    $update_stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
    $update_stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
    $update_stmt->bindParam(':period', $period, PDO::PARAM_STR);
    $update_stmt->bindParam(':amount', $total_amount, PDO::PARAM_STR);
    $update_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
    $update_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $update_stmt->bindParam(':id', $id, PDO::PARAM_INT);

    if (!$update_stmt->execute()) {
        throw new Exception("Error updating budget: " . implode(", ", $update_stmt->errorInfo()));
    }

    if ($update_stmt->rowCount() === 0) {
        throw new Exception("Budget not found or no changes made");
    }
    error_log("Budget updated successfully");

    // Delete existing budget details
    $delete_query = "DELETE FROM budget_details WHERE budget_id = :budget_id";
    $delete_stmt = $conn->prepare($delete_query);
    $delete_stmt->bindParam(':budget_id', $id, PDO::PARAM_INT);
    
    if (!$delete_stmt->execute()) {
        throw new Exception("Error deleting budget details: " . implode(", ", $delete_stmt->errorInfo()));
    }
    error_log("Old budget details deleted");

    // Insert new budget details if not yearly
    if ($period !== 'yearly') {
        $insert_query = "
            INSERT INTO budget_details 
                (budget_id, period_number, amount) 
            VALUES 
                (:budget_id, :period_number, :amount)";
        
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bindParam(':budget_id', $id, PDO::PARAM_INT);

        foreach ($period_amounts as $period_number => $amount) {
            $insert_stmt->bindParam(':period_number', $period_number, PDO::PARAM_INT);
            $insert_stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
            
            if (!$insert_stmt->execute()) {
                throw new Exception("Error inserting budget details: " . implode(", ", $insert_stmt->errorInfo()));
            }
            error_log("Inserted period $period_number amount: $amount");
        }
    }

    // Log the activity
    $log_query = "
        INSERT INTO activity_log 
            (created_by, action, module, record_id, description, created_at) 
        VALUES 
            (:created_by, 'update', 'budgets', :record_id, :description, NOW())";
    
    $description = "Updated budget for fiscal year {$fiscal_year}, account {$account_id}";
    if ($cost_center_id) {
        $description .= ", cost center {$cost_center_id}";
    }

    $log_stmt = $conn->prepare($log_query);
    $log_stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $log_stmt->bindParam(':record_id', $id, PDO::PARAM_INT);
    $log_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    
    if (!$log_stmt->execute()) {
        throw new Exception("Error logging activity: " . implode(", ", $log_stmt->errorInfo()));
    }
    error_log("Activity logged");

    // Commit transaction
    if (!$database->commit()) {
        throw new Exception("Failed to commit transaction");
    }
    error_log("Transaction committed");

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Budget updated successfully'
    ]);

} catch (PDOException $e) {
    error_log("Database error in update_budget.php: " . $e->getMessage());
    if (isset($database)) {
        $database->rollBack();
        error_log("Transaction rolled back");
    }
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please check the error logs.',
        'debug' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Application error in update_budget.php: " . $e->getMessage());
    if (isset($database)) {
        $database->rollBack();
        error_log("Transaction rolled back");
    }
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Ensure all output is sent
ob_end_flush();
