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

    // Validate required fields
    if (!isset($_POST['id']) && !isset($_GET['id'])) {
        throw new Exception("Budget ID is required");
    }

    $budget_id = filter_var($_POST['id'] ?? $_GET['id'], FILTER_VALIDATE_INT);
    if (!$budget_id) {
        throw new Exception("Invalid budget ID");
    }

    // Get budget details with related information
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
        WHERE b.id = :id
        AND b.status = 1";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $budget_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$budget) {
        throw new Exception("Budget not found");
    }

    // Initialize period amounts array
    $budget['amounts'] = [];

    // Get period amounts if not yearly
    if ($budget['period'] !== 'yearly') {
        $amounts_query = "
            SELECT period_number, amount 
            FROM budget_details 
            WHERE budget_id = :budget_id 
            ORDER BY period_number";
        
        $amounts_stmt = $conn->prepare($amounts_query);
        $amounts_stmt->bindParam(':budget_id', $budget_id, PDO::PARAM_INT);
        $amounts_stmt->execute();
        
        $budget['amounts'] = $amounts_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Also add period amounts as individual fields for backward compatibility
        foreach ($budget['amounts'] as $amount) {
            $period_key = $budget['period'] . '_amount_' . $amount['period_number'];
            $budget[$period_key] = $amount['amount'];
        }
    }

    // Debug
    error_log("Budget data: " . print_r($budget, true));

    echo json_encode([
        'success' => true,
        'data' => $budget
    ]);

} catch (PDOException $e) {
    error_log("Database error in get_budget.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please check the error logs.',
        'debug' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Application error in get_budget.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
