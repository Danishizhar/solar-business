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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['start_date']) || empty($_POST['end_date']) || empty($_POST['financial_year_id'])) {
            throw new Exception("Please fill all required fields");
        }

        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $financial_year_id = $_POST['financial_year_id'];
        $cost_center_id = !empty($_POST['cost_center_id']) ? $_POST['cost_center_id'] : null;
        $compare_with_previous = isset($_POST['compare_with_previous']);
        $show_percentages = isset($_POST['show_percentages']);

        // Get financial year details
        $year_query = "SELECT * FROM financial_years WHERE id = :year_id";
        $stmt = $conn->prepare($year_query);
        $stmt->bindParam(':year_id', $financial_year_id, PDO::PARAM_INT);
        $stmt->execute();
        $financial_year = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$financial_year) {
            throw new Exception("Financial year not found");
        }

        // Validate date range
        if ($start_date < $financial_year['start_date'] || $end_date > $financial_year['end_date']) {
            throw new Exception("Selected date range must be within the financial year period");
        }

        if (strtotime($start_date) > strtotime($end_date)) {
            throw new Exception("Start date must be before end date");
        }

        // Get cost center details if specified
        $cost_center_name = '';
        if ($cost_center_id) {
            $cc_query = "SELECT code, name FROM cost_centers WHERE id = :cc_id AND status = 1";
            $stmt = $conn->prepare($cc_query);
            $stmt->bindParam(':cc_id', $cost_center_id, PDO::PARAM_INT);
            $stmt->execute();
            $cc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cc) {
                $cost_center_name = $cc['code'] . ' - ' . $cc['name'];
            }
        }

        // Calculate previous period dates if comparison is requested
        $previous_start_date = null;
        $previous_end_date = null;
        
        if ($compare_with_previous) {
            $period_length = strtotime($end_date) - strtotime($start_date);
            $previous_end_date = date('Y-m-d', strtotime($start_date) - 86400); // Day before start date
            $previous_start_date = date('Y-m-d', strtotime($previous_end_date) - $period_length);
        }

        // Function to get account balances for a specific type and period
        function getAccountBalances($conn, $type, $start_date, $end_date, $cost_center_id = null) {
            $balances = [];
            
            // Get all accounts of the specified type
            $accounts_query = "
                WITH RECURSIVE account_tree AS (
                    SELECT 
                        coa.id, coa.account_code, coa.account_name, coa.parent_id, 
                        coa.is_group, coa.balance_type, 0 as level
                    FROM chart_of_accounts coa
                    JOIN account_categories ac ON coa.category_id = ac.id
                    WHERE ac.type = :type1 AND coa.parent_id IS NULL AND coa.status = 1

                    UNION ALL

                    SELECT 
                        c.id, c.account_code, c.account_name, c.parent_id, 
                        c.is_group, c.balance_type, p.level + 1
                    FROM chart_of_accounts c
                    JOIN account_categories ac ON c.category_id = ac.id
                    INNER JOIN account_tree p ON c.parent_id = p.id
                    WHERE ac.type = :type2 AND c.status = 1
                )
                SELECT * FROM account_tree
                ORDER BY account_code";

            $stmt = $conn->prepare($accounts_query);
            $stmt->bindParam(':type1', $type, PDO::PARAM_STR);
            $stmt->bindParam(':type2', $type, PDO::PARAM_STR);
            $stmt->execute();
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($accounts as $account) {
                // Get transaction totals for the period
                $transaction_query = "
                    SELECT 
                        COALESCE(SUM(
                            CASE WHEN jed.debit_credit = 'debit' THEN jed.amount ELSE 0 END
                        ), 0) as total_debit,
                        COALESCE(SUM(
                            CASE WHEN jed.debit_credit = 'credit' THEN jed.amount ELSE 0 END
                        ), 0) as total_credit
                    FROM journal_entries je
                    JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                    WHERE jed.account_id = :account_id
                    AND je.entry_date BETWEEN :start_date AND :end_date
                    AND je.status = 'posted'
                    " . ($cost_center_id ? "AND (je.cost_center_id = :cc_id1 OR jed.cost_center_id = :cc_id2)" : "");

                $stmt = $conn->prepare($transaction_query);
                $stmt->bindParam(':account_id', $account['id'], PDO::PARAM_INT);
                $stmt->bindParam(':start_date', $start_date, PDO::PARAM_STR);
                $stmt->bindParam(':end_date', $end_date, PDO::PARAM_STR);
                if ($cost_center_id) {
                    $stmt->bindParam(':cc_id1', $cost_center_id, PDO::PARAM_INT);
                    $stmt->bindParam(':cc_id2', $cost_center_id, PDO::PARAM_INT);
                }
                $stmt->execute();
                $transactions = $stmt->fetch(PDO::FETCH_ASSOC);

                // Calculate balance based on account type
                $balance = 0;
                if ($account['balance_type'] == 'debit') {
                    $balance = $transactions['total_debit'] - $transactions['total_credit'];
                } else {
                    $balance = $transactions['total_credit'] - $transactions['total_debit'];
                }

                // Only include accounts with non-zero balances or parent accounts
                if ($balance != 0 || $account['is_group']) {
                    $balances[] = [
                        'id' => $account['id'],
                        'account_code' => $account['account_code'],
                        'account_name' => $account['account_name'],
                        'parent_id' => $account['parent_id'],
                        'is_group' => $account['is_group'],
                        'level' => $account['level'],
                        'balance' => $balance
                    ];
                }
            }

            // Calculate totals for parent accounts
            foreach ($balances as &$account) {
                if ($account['is_group']) {
                    $account['balance'] = calculateGroupTotal($balances, $account['id']);
                }
            }

            return $balances;
        }

        // Function to calculate total for parent accounts
        function calculateGroupTotal($accounts, $parent_id) {
            $total = 0;
            foreach ($accounts as $account) {
                if ($account['parent_id'] == $parent_id) {
                    $total += $account['balance'];
                }
            }
            return $total;
        }

        // Get current period balances
        $current_revenue = getAccountBalances($conn, 'revenue', $start_date, $end_date, $cost_center_id);
        $current_cogs = getAccountBalances($conn, 'cogs', $start_date, $end_date, $cost_center_id);
        $current_expenses = getAccountBalances($conn, 'expense', $start_date, $end_date, $cost_center_id);

        // Get previous period balances if comparison is requested
        $previous_revenue = [];
        $previous_cogs = [];
        $previous_expenses = [];
        
        if ($compare_with_previous) {
            $previous_revenue = getAccountBalances($conn, 'revenue', $previous_start_date, $previous_end_date, $cost_center_id);
            $previous_cogs = getAccountBalances($conn, 'cogs', $previous_start_date, $previous_end_date, $cost_center_id);
            $previous_expenses = getAccountBalances($conn, 'expense', $previous_start_date, $previous_end_date, $cost_center_id);
        }

        // Calculate totals
        $total_revenue = array_reduce($current_revenue, function($carry, $item) {
            return $carry + ($item['parent_id'] === null ? $item['balance'] : 0);
        }, 0);

        $total_cogs = array_reduce($current_cogs, function($carry, $item) {
            return $carry + ($item['parent_id'] === null ? $item['balance'] : 0);
        }, 0);

        $total_expenses = array_reduce($current_expenses, function($carry, $item) {
            return $carry + ($item['parent_id'] === null ? $item['balance'] : 0);
        }, 0);

        $gross_profit = $total_revenue - $total_cogs;
        $net_profit = $gross_profit - $total_expenses;

        // Previous period totals
        $previous_total_revenue = 0;
        $previous_total_cogs = 0;
        $previous_total_expenses = 0;
        $previous_gross_profit = 0;
        $previous_net_profit = 0;

        if ($compare_with_previous) {
            $previous_total_revenue = array_reduce($previous_revenue, function($carry, $item) {
                return $carry + ($item['parent_id'] === null ? $item['balance'] : 0);
            }, 0);

            $previous_total_cogs = array_reduce($previous_cogs, function($carry, $item) {
                return $carry + ($item['parent_id'] === null ? $item['balance'] : 0);
            }, 0);

            $previous_total_expenses = array_reduce($previous_expenses, function($carry, $item) {
                return $carry + ($item['parent_id'] === null ? $item['balance'] : 0);
            }, 0);

            $previous_gross_profit = $previous_total_revenue - $previous_total_cogs;
            $previous_net_profit = $previous_gross_profit - $previous_total_expenses;
        }

        // Format response
        $response = [
            'success' => true,
            'data' => [
                'period' => [
                    'current' => [
                        'start_date' => $start_date,
                        'end_date' => $end_date
                    ],
                    'previous' => $compare_with_previous ? [
                        'start_date' => $previous_start_date,
                        'end_date' => $previous_end_date
                    ] : null
                ],
                'cost_center' => $cost_center_name,
                'show_percentages' => $show_percentages,
                'revenue' => [
                    'accounts' => $current_revenue,
                    'total' => $total_revenue,
                    'previous_total' => $previous_total_revenue
                ],
                'cogs' => [
                    'accounts' => $current_cogs,
                    'total' => $total_cogs,
                    'previous_total' => $previous_total_cogs
                ],
                'expenses' => [
                    'accounts' => $current_expenses,
                    'total' => $total_expenses,
                    'previous_total' => $previous_total_expenses
                ],
                'summary' => [
                    'gross_profit' => [
                        'current' => $gross_profit,
                        'previous' => $previous_gross_profit,
                        'percentage' => $total_revenue != 0 ? ($gross_profit / $total_revenue) * 100 : 0
                    ],
                    'net_profit' => [
                        'current' => $net_profit,
                        'previous' => $previous_net_profit,
                        'percentage' => $total_revenue != 0 ? ($net_profit / $total_revenue) * 100 : 0
                    ]
                ]
            ]
        ];

        echo json_encode($response);

    } catch (PDOException $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
