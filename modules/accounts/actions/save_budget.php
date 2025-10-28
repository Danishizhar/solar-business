<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_add'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $conn->beginTransaction();

        // Validate required fields
        if (empty($_POST['fiscal_year']) || empty($_POST['account_id']) || empty($_POST['period'])) {
            throw new Exception("Please fill all required fields");
        }

        // Parse values
        $fiscal_year = $_POST['fiscal_year'];
        $account_id = $_POST['account_id'];
        $cost_center_id = !empty($_POST['cost_center_id']) ? $_POST['cost_center_id'] : null;
        $period = $_POST['period'];
        $notes = $_POST['notes'];

        // Calculate total amount based on period
        $total_amount = 0;
        $period_amounts = [];

        switch ($period) {
            case 'monthly':
                for ($i = 1; $i <= 12; $i++) {
                    $amount = floatval($_POST["monthly_amount_$i"] ?? 0);
                    $total_amount += $amount;
                    $period_amounts[$i] = $amount;
                }
                break;

            case 'quarterly':
                for ($i = 1; $i <= 4; $i++) {
                    $amount = floatval($_POST["quarterly_amount_$i"] ?? 0);
                    $total_amount += $amount;
                    $period_amounts[$i] = $amount;
                }
                break;

            case 'yearly':
                $total_amount = floatval($_POST['yearly_amount'] ?? 0);
                $period_amounts[1] = $total_amount;
                break;

            default:
                throw new Exception("Invalid period type");
        }

        // Check if budget already exists for this account and fiscal year
        $check_query = "
            SELECT id 
            FROM budgets 
            WHERE fiscal_year = :fiscal_year 
            AND account_id = :account_id 
            AND ((:cost_center_id IS NULL AND cost_center_id IS NULL) 
                OR cost_center_id = :cost_center_id2)
            AND status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':fiscal_year', $fiscal_year, PDO::PARAM_INT);
        $check_stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
        $check_stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
        $check_stmt->bindParam(':cost_center_id2', $cost_center_id, PDO::PARAM_INT);
        $check_stmt->execute();
        
        if ($check_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Budget already exists for this account and fiscal year");
        }

        // Verify fiscal year exists, is active, and not closed
        $year_query = "
            SELECT id, year_code, is_closed 
            FROM financial_years 
            WHERE id = :fiscal_year 
            AND status = 1
        ";
        $year_stmt = $conn->prepare($year_query);
        $year_stmt->bindParam(':fiscal_year', $fiscal_year, PDO::PARAM_INT);
        $year_stmt->execute();
        
        $fiscal_year_data = $year_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fiscal_year_data) {
            throw new Exception("Invalid fiscal year selected");
        }
        
        // Check if financial year is closed
        if ($fiscal_year_data['is_closed'] == 1) {
            throw new Exception("Cannot create budget for closed financial year: " . $fiscal_year_data['year_code']);
        }

        // Verify account exists and is active
        $account_query = "
            SELECT id 
            FROM chart_of_accounts 
            WHERE id = :account_id 
            AND status = 1
        ";
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
                AND status = 1
            ";
            $cc_stmt = $conn->prepare($cc_query);
            $cc_stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
            $cc_stmt->execute();
            
            if (!$cc_stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("Selected cost center is not active");
            }
        }

        // Insert budget
        $insert_query = "
            INSERT INTO budgets (
                fiscal_year,
                account_id,
                cost_center_id,
                period,
                amount,
                notes,
                status,
                created_by,
                updated_by
            ) VALUES (
                :fiscal_year,
                :account_id,
                :cost_center_id,
                :period,
                :amount,
                :notes,
                1,
                :user_id,
                :user_id
            )
        ";

        $stmt = $conn->prepare($insert_query);
        $stmt->bindParam(':fiscal_year', $fiscal_year, PDO::PARAM_INT);
        $stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
        $stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
        $stmt->bindParam(':period', $period, PDO::PARAM_STR);
        $stmt->bindParam(':amount', $total_amount, PDO::PARAM_STR);
        $stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);

        if (!$stmt->execute()) {
            throw new Exception("Error creating budget");
        }

        $budget_id = $conn->lastInsertId();

        // Insert budget details
        if ($period !== 'yearly') {
            $detail_query = "
                INSERT INTO budget_details (
                    budget_id,
                    period_number,
                    amount
                ) VALUES (
                    :budget_id,
                    :period_number,
                    :amount
                )
            ";
            $detail_stmt = $conn->prepare($detail_query);
            $detail_stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);

            foreach ($period_amounts as $period_number => $amount) {
                $detail_stmt->bindParam(':period_number', $period_number, PDO::PARAM_INT);
                $detail_stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
                
                if (!$detail_stmt->execute()) {
                    throw new Exception("Error creating budget details");
                }
            }
        }

        // Log the action
        $log_query = "
            INSERT INTO activity_logs (
                user_id,
                action,
                module,
                record_id,
                description
            ) VALUES (
                :user_id,
                'create',
                'budgets',
                :budget_id,
                :description
            )
        ";
        $log_stmt = $conn->prepare($log_query);
        $description = "Created new budget for fiscal year " . $fiscal_year;
        $log_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $log_stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $log_stmt->execute();

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Budget created successfully',
            'id' => $budget_id
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Database error in save_budget.php: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred. Please check the error logs.'
        ]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
