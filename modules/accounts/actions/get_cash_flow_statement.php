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
        $compare_with_previous = !empty($_POST['compare_with_previous']);

        // Verify financial year
        $year_query = "SELECT * FROM financial_years WHERE id = :id AND status = 1";
        $stmt = $conn->prepare($year_query);
        $stmt->bindParam(':id', $financial_year_id, PDO::PARAM_INT);
        $stmt->execute();
        $year = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$year) {
            throw new Exception("Invalid financial year");
        }

        // Calculate previous period dates if comparison is requested
        if ($compare_with_previous) {
            $date_diff = (strtotime($end_date) - strtotime($start_date)) + 1;
            $previous_end_date = date('Y-m-d', strtotime($start_date . ' -1 day'));
            $previous_start_date = date('Y-m-d', strtotime($previous_end_date . ' -' . ($date_diff - 1) . ' days'));
        }

        // Function to calculate variance and percentage
        function calculateVariance($current, $previous) {
            $variance = $current - $previous;
            $variance_percentage = $previous != 0 ? ($variance / abs($previous)) * 100 : 0;
            return [
                'variance' => $variance,
                'variance_percentage' => $variance_percentage
            ];
        }

        // Function to get net income for the period
        function getNetIncome($conn, $start_date, $end_date) {
            $query = "
                SELECT 
                    COALESCE(SUM(
                        CASE 
                            WHEN je.entry_type = 'debit' THEN jed.amount * -1
                            ELSE jed.amount
                        END
                    ), 0) as net_income
                FROM journal_entries je
                JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                JOIN chart_of_accounts coa ON jed.account_id = coa.id
                WHERE je.entry_date BETWEEN :start_date AND :end_date
                AND je.status = 'posted'
                AND coa.account_type IN ('income', 'expense')
                AND coa.status = 1";

            $stmt = $conn->prepare($query);
            $stmt->bindParam(':start_date', $start_date, PDO::PARAM_STR);
            $stmt->bindParam(':end_date', $end_date, PDO::PARAM_STR);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['net_income'] ?? 0;
        }

        // Function to get account balance change for the period
        function getAccountBalanceChange($conn, $account_type, $start_date, $end_date) {
            $query = "
                SELECT 
                    COALESCE(SUM(
                        CASE 
                            WHEN je.entry_type = 'debit' THEN jed.amount
                            ELSE -jed.amount
                        END
                    ), 0) as balance_change
                FROM journal_entries je
                JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                JOIN chart_of_accounts coa ON jed.account_id = coa.id
                WHERE je.entry_date BETWEEN :start_date AND :end_date
                AND je.status = 'posted'
                AND coa.account_type = :account_type
                AND coa.status = 1";

            $stmt = $conn->prepare($query);
            $stmt->bindParam(':start_date', $start_date, PDO::PARAM_STR);
            $stmt->bindParam(':end_date', $end_date, PDO::PARAM_STR);
            $stmt->bindParam(':account_type', $account_type, PDO::PARAM_STR);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['balance_change'] ?? 0;
        }

        // Function to get cash balance at a specific date
        function getCashBalance($conn, $date) {
            $query = "
                SELECT 
                    COALESCE(SUM(
                        CASE 
                            WHEN je.entry_type = 'debit' THEN jed.amount
                            ELSE -jed.amount
                        END
                    ), 0) as balance
                FROM journal_entries je
                JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                JOIN chart_of_accounts coa ON jed.account_id = coa.id
                WHERE je.entry_date <= :date
                AND je.status = 'posted'
                AND coa.account_type = 'cash'
                AND coa.status = 1";

            $stmt = $conn->prepare($query);
            $stmt->bindParam(':date', $date, PDO::PARAM_STR);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['balance'] ?? 0;
        }

        // Function to get specific account changes for the period
        function getSpecificAccountChange($conn, $account_keyword, $start_date, $end_date) {
            $query = "
                SELECT 
                    COALESCE(SUM(
                        CASE 
                            WHEN je.entry_type = 'debit' THEN jed.amount
                            ELSE -jed.amount
                        END
                    ), 0) as amount_change
                FROM journal_entries je
                JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                JOIN chart_of_accounts coa ON jed.account_id = coa.id
                WHERE je.entry_date BETWEEN :start_date AND :end_date
                AND je.status = 'posted'
                AND LOWER(coa.account_name) LIKE :account_keyword
                AND coa.status = 1";

            $stmt = $conn->prepare($query);
            $stmt->bindParam(':start_date', $start_date, PDO::PARAM_STR);
            $stmt->bindParam(':end_date', $end_date, PDO::PARAM_STR);
            $stmt->bindParam(':account_keyword', '%' . strtolower($account_keyword) . '%', PDO::PARAM_STR);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['amount_change'] ?? 0;
        }

        // Get current period data
        $current_net_income = getNetIncome($conn, $start_date, $end_date);
        $current_depreciation = getSpecificAccountChange($conn, 'depreciation', $start_date, $end_date);
        $current_accounts_receivable = getSpecificAccountChange($conn, 'accounts receivable', $start_date, $end_date) * -1;
        $current_inventory = getSpecificAccountChange($conn, 'inventory', $start_date, $end_date) * -1;
        $current_accounts_payable = getSpecificAccountChange($conn, 'accounts payable', $start_date, $end_date);
        
        // Calculate operating cash flow
        $current_net_operating_cash = $current_net_income + $current_depreciation + 
                                    $current_accounts_receivable + $current_inventory + 
                                    $current_accounts_payable;

        // Get investing activities
        $current_capital_expenditures = getSpecificAccountChange($conn, 'fixed asset', $start_date, $end_date);
        $current_net_investing_cash = $current_capital_expenditures;

        // Get financing activities
        $current_loan_proceeds = getSpecificAccountChange($conn, 'loan', $start_date, $end_date);
        $current_net_financing_cash = $current_loan_proceeds;

        // Get cash balances
        $current_beginning_cash = getCashBalance($conn, date('Y-m-d', strtotime($start_date . ' -1 day')));
        $current_ending_cash = getCashBalance($conn, $end_date);

        // Initialize previous period variables
        $previous_net_income = 0;
        $previous_depreciation = 0;
        $previous_accounts_receivable = 0;
        $previous_inventory = 0;
        $previous_accounts_payable = 0;
        $previous_net_operating_cash = 0;
        $previous_capital_expenditures = 0;
        $previous_net_investing_cash = 0;
        $previous_loan_proceeds = 0;
        $previous_net_financing_cash = 0;
        $previous_beginning_cash = 0;
        $previous_ending_cash = 0;

        // Get previous period data if comparison is requested
        if ($compare_with_previous) {
            $previous_net_income = getNetIncome($conn, $previous_start_date, $previous_end_date);
            $previous_depreciation = getSpecificAccountChange($conn, 'depreciation', $previous_start_date, $previous_end_date);
            $previous_accounts_receivable = getSpecificAccountChange($conn, 'accounts receivable', $previous_start_date, $previous_end_date) * -1;
            $previous_inventory = getSpecificAccountChange($conn, 'inventory', $previous_start_date, $previous_end_date) * -1;
            $previous_accounts_payable = getSpecificAccountChange($conn, 'accounts payable', $previous_start_date, $previous_end_date);
            
            // Calculate previous operating cash flow
            $previous_net_operating_cash = $previous_net_income + $previous_depreciation + 
                                         $previous_accounts_receivable + $previous_inventory + 
                                         $previous_accounts_payable;

            // Get previous investing activities
            $previous_capital_expenditures = getSpecificAccountChange($conn, 'fixed asset', $previous_start_date, $previous_end_date);
            $previous_net_investing_cash = $previous_capital_expenditures;

            // Get previous financing activities
            $previous_loan_proceeds = getSpecificAccountChange($conn, 'loan', $previous_start_date, $previous_end_date);
            $previous_net_financing_cash = $previous_loan_proceeds;

            // Get previous cash balances
            $previous_beginning_cash = getCashBalance($conn, date('Y-m-d', strtotime($previous_start_date . ' -1 day')));
            $previous_ending_cash = getCashBalance($conn, $previous_end_date);
        }

        // Calculate variances
        $net_income_variance_data = calculateVariance($current_net_income, $previous_net_income);
        $depreciation_variance_data = calculateVariance($current_depreciation, $previous_depreciation);
        $accounts_receivable_variance_data = calculateVariance($current_accounts_receivable, $previous_accounts_receivable);
        $inventory_variance_data = calculateVariance($current_inventory, $previous_inventory);
        $accounts_payable_variance_data = calculateVariance($current_accounts_payable, $previous_accounts_payable);
        $operating_cash_variance_data = calculateVariance($current_net_operating_cash, $previous_net_operating_cash);
        $capital_expenditures_variance_data = calculateVariance($current_capital_expenditures, $previous_capital_expenditures);
        $investing_cash_variance_data = calculateVariance($current_net_investing_cash, $previous_net_investing_cash);
        $loan_proceeds_variance_data = calculateVariance($current_loan_proceeds, $previous_loan_proceeds);
        $financing_cash_variance_data = calculateVariance($current_net_financing_cash, $previous_net_financing_cash);
        $beginning_cash_variance_data = calculateVariance($current_beginning_cash, $previous_beginning_cash);
        $ending_cash_variance_data = calculateVariance($current_ending_cash, $previous_ending_cash);

        echo json_encode([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $start_date,
                    'end' => $end_date
                ],
                'compare' => $compare_with_previous,
                'operating_activities' => [
                    'net_income' => [
                        'current' => $current_net_income,
                        'previous' => $previous_net_income,
                        'variance' => $net_income_variance_data['variance'],
                        'variance_percentage' => $net_income_variance_data['variance_percentage']
                    ],
                    'depreciation' => [
                        'current' => $current_depreciation,
                        'previous' => $previous_depreciation,
                        'variance' => $depreciation_variance_data['variance'],
                        'variance_percentage' => $depreciation_variance_data['variance_percentage']
                    ],
                    'accounts_receivable' => [
                        'current' => $current_accounts_receivable,
                        'previous' => $previous_accounts_receivable,
                        'variance' => $accounts_receivable_variance_data['variance'],
                        'variance_percentage' => $accounts_receivable_variance_data['variance_percentage']
                    ],
                    'inventory' => [
                        'current' => $current_inventory,
                        'previous' => $previous_inventory,
                        'variance' => $inventory_variance_data['variance'],
                        'variance_percentage' => $inventory_variance_data['variance_percentage']
                    ],
                    'accounts_payable' => [
                        'current' => $current_accounts_payable,
                        'previous' => $previous_accounts_payable,
                        'variance' => $accounts_payable_variance_data['variance'],
                        'variance_percentage' => $accounts_payable_variance_data['variance_percentage']
                    ],
                    'net_cash' => [
                        'current' => $current_net_operating_cash,
                        'previous' => $previous_net_operating_cash,
                        'variance' => $operating_cash_variance_data['variance'],
                        'variance_percentage' => $operating_cash_variance_data['variance_percentage']
                    ]
                ],
                'investing_activities' => [
                    'capital_expenditures' => [
                        'current' => $current_capital_expenditures,
                        'previous' => $previous_capital_expenditures,
                        'variance' => $capital_expenditures_variance_data['variance'],
                        'variance_percentage' => $capital_expenditures_variance_data['variance_percentage']
                    ],
                    'net_cash' => [
                        'current' => $current_net_investing_cash,
                        'previous' => $previous_net_investing_cash,
                        'variance' => $investing_cash_variance_data['variance'],
                        'variance_percentage' => $investing_cash_variance_data['variance_percentage']
                    ]
                ],
                'financing_activities' => [
                    'loan_proceeds' => [
                        'current' => $current_loan_proceeds,
                        'previous' => $previous_loan_proceeds,
                        'variance' => $loan_proceeds_variance_data['variance'],
                        'variance_percentage' => $loan_proceeds_variance_data['variance_percentage']
                    ],
                    'net_cash' => [
                        'current' => $current_net_financing_cash,
                        'previous' => $previous_net_financing_cash,
                        'variance' => $financing_cash_variance_data['variance'],
                        'variance_percentage' => $financing_cash_variance_data['variance_percentage']
                    ]
                ],
                'cash_balances' => [
                    'beginning' => [
                        'current' => $current_beginning_cash,
                        'previous' => $previous_beginning_cash,
                        'variance' => $beginning_cash_variance_data['variance'],
                        'variance_percentage' => $beginning_cash_variance_data['variance_percentage']
                    ],
                    'ending' => [
                        'current' => $current_ending_cash,
                        'previous' => $previous_ending_cash,
                        'variance' => $ending_cash_variance_data['variance'],
                        'variance_percentage' => $ending_cash_variance_data['variance_percentage']
                    ]
                ]
            ]
        ]);

    } catch (PDOException $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
