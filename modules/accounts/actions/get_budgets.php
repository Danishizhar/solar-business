<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '../../../logs/php_errors.log');

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();

    // Get all budgets with related information
    $query = "
        SELECT 
            b.*,
            fy.year_code as fiscal_year_code,
            coa.account_code,
            coa.account_name,
            COALESCE(cc.code, '') as cost_center_code,
            COALESCE(cc.name, '') as cost_center_name,
            COALESCE(cb.username, 'System') as created_by_name,
            COALESCE(ub.username, cb.username) as updated_by_name
        FROM budgets b
        LEFT JOIN financial_years fy ON b.fiscal_year = fy.id
        LEFT JOIN chart_of_accounts coa ON b.account_id = coa.id
        LEFT JOIN cost_centers cc ON b.cost_center_id = cc.id
        LEFT JOIN users cb ON b.created_by = cb.id
        LEFT JOIN users ub ON b.updated_by = ub.id
        WHERE b.status = 1
        ORDER BY fy.year_code DESC, coa.account_code";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get period amounts for each budget
    $amounts_query = "
        SELECT budget_id, period_number, amount 
        FROM budget_details 
        WHERE budget_id IN (?)";
    
    if (!empty($budgets)) {
        $budget_ids = array_column($budgets, 'id');
        $amounts_stmt = $conn->prepare(str_replace('?', implode(',', array_fill(0, count($budget_ids), '?')), $amounts_query));
        $amounts_stmt->execute($budget_ids);
        $amounts = $amounts_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Index amounts by budget_id
        $budget_amounts = [];
        foreach ($amounts as $amount) {
            $budget_amounts[$amount['budget_id']][$amount['period_number']] = $amount['amount'];
        }
    }

    // Format data for DataTables
    $formatted_budgets = [];
    foreach ($budgets as $budget) {
        $budget_data = [
            'id' => $budget['id'],
            'fiscal_year' => $budget['fiscal_year'],
            'fiscal_year_code' => $budget['fiscal_year_code'],
            'account_id' => $budget['account_id'],
            'account_code' => $budget['account_code'],
            'account_name' => $budget['account_name'],
            'cost_center_id' => $budget['cost_center_id'],
            'cost_center_code' => $budget['cost_center_code'],
            'cost_center_name' => $budget['cost_center_name'],
            'period' => $budget['period'],
            'amount' => $budget['amount'],
            'notes' => $budget['notes'],
            'status' => (int)$budget['status'],
            'created_by' => $budget['created_by_name'],
            'created_at' => $budget['created_at'],
            'updated_by' => $budget['updated_by_name'],
            'updated_at' => $budget['updated_at'],
            'can_edit' => $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_edit']),
            'can_delete' => $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_delete'])
        ];

        // Add period amounts if not yearly
        if ($budget['period'] !== 'yearly' && isset($budget_amounts[$budget['id']])) {
            foreach ($budget_amounts[$budget['id']] as $period_number => $amount) {
                $budget_data[$budget['period'] . '_amount_' . $period_number] = $amount;
            }
        }

        $formatted_budgets[] = $budget_data;
    }

    echo json_encode([
        'success' => true,
        'data' => $formatted_budgets
    ]);

} catch (PDOException $e) {
    error_log("Database error in get_budgets.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please check the error logs.',
        'debug' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Application error in get_budgets.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
