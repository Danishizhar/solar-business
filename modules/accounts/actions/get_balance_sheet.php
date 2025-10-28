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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

// Function to get account balances
function getAccountBalances($conn, $type, $as_of_date, $cost_center_id = null) {
    $query = "
        WITH RECURSIVE account_tree AS (
            SELECT 
                coa.id, coa.account_code, coa.account_name, 
                coa.parent_id, coa.is_group, coa.opening_balance,
                ac.type as account_type
            FROM chart_of_accounts coa
            JOIN account_categories ac ON coa.category_id = ac.id
            WHERE coa.parent_id IS NULL AND coa.status = 1
            AND ac.type = :type

            UNION ALL

            SELECT 
                c.id, c.account_code, c.account_name,
                c.parent_id, c.is_group, c.opening_balance,
                ac.type as account_type
            FROM chart_of_accounts c
            JOIN account_categories ac ON c.category_id = ac.id
            INNER JOIN account_tree p ON c.parent_id = p.id
            WHERE c.status = 1
            AND ac.type = :type2
        )
        SELECT 
            t.*,
            t.opening_balance + COALESCE((
                SELECT SUM(
                    CASE 
                        WHEN t.account_type IN ('asset', 'expense') THEN 
                            COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0)
                        ELSE 
                            COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0)
                    END
                )
                FROM journal_entries je
                JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                WHERE jed.account_id = t.id
                AND je.entry_date <= :as_of_date
                AND je.status = 'approved'
                " . ($cost_center_id ? "AND jed.cost_center_id = :cost_center_id" : "") . "
            ), 0) as balance
        FROM account_tree t
        ORDER BY t.account_code";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':type', $type, PDO::PARAM_STR);
    $stmt->bindParam(':type2', $type, PDO::PARAM_STR);
    $stmt->bindParam(':as_of_date', $as_of_date, PDO::PARAM_STR);
    
    if ($cost_center_id) {
        $stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to calculate total for parent accounts
function calculateGroupTotal($accounts, $parent_id = null, $level = 0) {
    $total = 0;
    $children = [];

    foreach ($accounts as $account) {
        if ($account['parent_id'] == $parent_id) {
            $balance = $account['balance'];
            
            if ($account['is_group']) {
                $child_result = calculateGroupTotal($accounts, $account['id'], $level + 1);
                $balance = $child_result['total'];
                $children[] = [
                    'account_code' => $account['account_code'],
                    'account_name' => $account['account_name'],
                    'is_group' => true,
                    'level' => $level,
                    'balance' => $balance,
                    'children' => $child_result['children']
                ];
            } else {
                $children[] = [
                    'account_code' => $account['account_code'],
                    'account_name' => $account['account_name'],
                    'is_group' => false,
                    'level' => $level,
                    'balance' => $balance
                ];
            }
            
            $total += $balance;
        }
    }

    return [
        'total' => $total,
        'children' => $children
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['as_of_date']) || empty($_POST['financial_year_id'])) {
            throw new Exception("Please fill all required fields");
        }

        $as_of_date = $_POST['as_of_date'];
        $financial_year_id = $_POST['financial_year_id'];
        $cost_center_id = !empty($_POST['cost_center_id']) ? $_POST['cost_center_id'] : null;

        // Verify financial year
        $year_query = "SELECT * FROM financial_years WHERE id = :id AND status = 1";
        $stmt = $conn->prepare($year_query);
        $stmt->bindParam(':id', $financial_year_id, PDO::PARAM_INT);
        $stmt->execute();
        $year = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$year) {
            throw new Exception("Invalid financial year");
        }

        // Get balances for each section
        $assets = getAccountBalances($conn, 'asset', $as_of_date, $cost_center_id);
        $liabilities = getAccountBalances($conn, 'liability', $as_of_date, $cost_center_id);
        $equity = getAccountBalances($conn, 'equity', $as_of_date, $cost_center_id);

        // Calculate totals
        $total_assets = calculateGroupTotal($assets)['total'];
        $total_liabilities = calculateGroupTotal($liabilities)['total'];
        $total_equity = calculateGroupTotal($equity)['total'];

        echo json_encode([
            'success' => true,
            'data' => [
                'assets' => calculateGroupTotal($assets)['children'],
                'liabilities' => calculateGroupTotal($liabilities)['children'],
                'equity' => calculateGroupTotal($equity)['children'],
                'total_assets' => $total_assets,
                'total_liabilities' => $total_liabilities,
                'total_equity' => $total_equity
            ]
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
