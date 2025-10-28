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
        if (empty($_POST['as_of_date']) || empty($_POST['financial_year_id'])) {
            throw new Exception("Please fill all required fields");
        }

        $as_of_date = $_POST['as_of_date'];
        $financial_year_id = $_POST['financial_year_id'];
        $include_zero_balances = isset($_POST['include_zero_balances']) && $_POST['include_zero_balances'] == '1';

        // Get financial year details
        $year_query = "SELECT * FROM financial_years WHERE id = :year_id AND status = 1";
        $stmt = $conn->prepare($year_query);
        $stmt->bindParam(':year_id', $financial_year_id, PDO::PARAM_INT);
        $stmt->execute();
        $financial_year = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$financial_year) {
            throw new Exception("Financial year not found");
        }

        // Validate date range
        if ($as_of_date < $financial_year['start_date'] || $as_of_date > $financial_year['end_date']) {
            throw new Exception("Selected date must be within the financial year period");
        }

        // Get all accounts with their categories and balances
        $accounts_query = "
            WITH RECURSIVE account_tree AS (
                SELECT 
                    coa.id, coa.account_code, coa.account_name, 
                    coa.balance_type, coa.is_group, coa.parent_id,
                    ac.type as account_type,
                    0 as level
                FROM chart_of_accounts coa
                JOIN account_categories ac ON coa.category_id = ac.id
                WHERE coa.parent_id IS NULL AND coa.status = 1

                UNION ALL

                SELECT 
                    c.id, c.account_code, c.account_name,
                    c.balance_type, c.is_group, c.parent_id,
                    ac.type as account_type,
                    p.level + 1
                FROM chart_of_accounts c
                JOIN account_categories ac ON c.category_id = ac.id
                INNER JOIN account_tree p ON c.parent_id = p.id
                WHERE c.status = 1
            )
            SELECT 
                t.*,
                CASE 
                    WHEN t.account_type IN ('asset', 'expense') THEN
                        t.opening_balance + COALESCE((
                            SELECT SUM(COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0))
                            FROM journal_entries je
                            JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                            WHERE jed.account_id = t.id
                            AND je.entry_date <= :as_of_date
                            AND je.entry_date >= :start_date
                            AND je.status = 'approved'
                        ), 0)
                    ELSE
                        t.opening_balance + COALESCE((
                            SELECT SUM(COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0))
                            FROM journal_entries je
                            JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                            WHERE jed.account_id = t.id
                            AND je.entry_date <= :as_of_date2
                            AND je.entry_date >= :start_date2
                            AND je.status = 'approved'
                        ), 0)
                END as balance
            FROM account_tree t
            WHERE t.status = 1
            ORDER BY t.account_code";

        $stmt = $conn->prepare($accounts_query);
        $stmt->bindParam(':as_of_date', $as_of_date, PDO::PARAM_STR);
        $stmt->bindParam(':start_date', $financial_year['start_date'], PDO::PARAM_STR);
        $stmt->bindParam(':as_of_date2', $as_of_date, PDO::PARAM_STR);
        $stmt->bindParam(':start_date2', $financial_year['start_date'], PDO::PARAM_STR);
        $stmt->execute();
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $trial_balance = [];
        $total_debit = 0;
        $total_credit = 0;

        foreach ($accounts as $account) {
            if (!$include_zero_balances && $account['balance'] == 0) {
                continue;
            }

            $balance = $account['balance'];
            $debit_amount = 0;
            $credit_amount = 0;

            // For asset/expense accounts:
            // - Positive balance goes to debit
            // - Negative balance goes to credit
            if ($account['account_type'] == 'asset' || $account['account_type'] == 'expense') {
                if ($balance > 0) {
                    $debit_amount = $balance;
                } else {
                    $credit_amount = abs($balance);
                }
            }
            // For liability/equity/revenue accounts:
            // - Positive balance goes to credit
            // - Negative balance goes to debit
            else {
                if ($balance > 0) {
                    $credit_amount = $balance;
                } else {
                    $debit_amount = abs($balance);
                }
            }

            $trial_balance[] = [
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'account_type' => $account['account_type'],
                'debit_amount' => $debit_amount,
                'credit_amount' => $credit_amount
            ];

            $total_debit += $debit_amount;
            $total_credit += $credit_amount;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'period' => [
                    'as_of_date' => $as_of_date,
                    'formatted_date' => date('F d, Y', strtotime($as_of_date)),
                    'financial_year' => [
                        'id' => $financial_year['id'],
                        'name' => $financial_year['name'],
                        'start_date' => $financial_year['start_date'],
                        'end_date' => $financial_year['end_date']
                    ]
                ],
                'trial_balance' => $trial_balance,
                'total_debit' => $total_debit,
                'total_credit' => $total_credit,
                'is_balanced' => abs($total_debit - $total_credit) < 0.01
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
