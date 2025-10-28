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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_edit'])) {
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
        if (empty($_POST['id'])) {
            throw new Exception("Financial year ID is required");
        }

        $year_id = $_POST['id'];

        // Check if financial year exists and is not already closed
        $check_query = "SELECT * FROM financial_years WHERE id = :year_id";
        $stmt = $conn->prepare($check_query);
        $stmt->bindParam(':year_id', $year_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            throw new Exception("Financial year not found");
        }

        $financial_year = $result;

        if ($financial_year['is_closed']) {
            throw new Exception("Financial year is already closed");
        }

        // Check if there's a next financial year for carrying forward balances
        $next_year_query = "
            SELECT id, start_date FROM financial_years 
            WHERE start_date > :end_date 
            ORDER BY start_date ASC 
            LIMIT 1
        ";
        $stmt = $conn->prepare($next_year_query);
        $stmt->bindParam(':end_date', $financial_year['end_date'], PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception("Cannot close financial year because there is no next financial year to carry forward balances");
        }
        
        $next_year = $result;
        $next_year_id = $next_year['id'];
        $next_year_start_date = $next_year['start_date'];

        // Removed check for pending journal entries as per requirement
        // Financial year can now be closed even with transactions

        // Get all balance sheet accounts (assets, liabilities, equity)
        $accounts_query = "
            SELECT 
                coa.id, 
                coa.account_code, 
                coa.account_name, 
                coa.current_balance,
                coa.balance_type,
                ac.type
            FROM chart_of_accounts coa
            JOIN account_categories ac ON coa.category_id = ac.id
            WHERE ac.type IN ('asset', 'liability', 'equity')
            AND coa.is_group = 0
            AND coa.status = 1
        ";
        $stmt = $conn->prepare($accounts_query);
        $stmt->execute();
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Create closing journal entry
        $closing_entry_number = 'CLOSING-' . $financial_year['year_code'];
        $closing_entry_date = $financial_year['end_date'];
        $closing_description = 'Closing entry for financial year ' . $financial_year['year_code'];

        $insert_closing_entry = "
            INSERT INTO journal_entries (
                entry_number, entry_date, description, entry_type, 
                total_debit, total_credit, status, posting_date,
                created_by, updated_by, approved_by, approved_at
            ) VALUES (:entry_number, :entry_date, :description, 'adjustment', 0, 0, 'approved', NOW(), :created_by, :updated_by, :approved_by, NOW())
        ";

        $stmt = $conn->prepare($insert_closing_entry);
        $stmt->bindParam(':entry_number', $closing_entry_number, PDO::PARAM_STR);
        $stmt->bindParam(':entry_date', $closing_entry_date, PDO::PARAM_STR);
        $stmt->bindParam(':description', $closing_description, PDO::PARAM_STR);
        $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':approved_by', $_SESSION['user_id'], PDO::PARAM_INT);

        if (!$stmt->execute()) {
            throw new Exception("Error creating closing journal entry: " . $stmt->error);
        }

        $closing_entry_id = $conn->lastInsertId();
        $total_debit = 0;
        $total_credit = 0;

        // Create opening journal entry for next year
        $opening_entry_number = 'OPENING-' . substr($next_year_start_date, 0, 4);
        $opening_entry_date = $next_year_start_date;
        $opening_description = 'Opening balances carried forward from ' . $financial_year['year_code'];

        $insert_opening_entry = "
            INSERT INTO journal_entries (
                entry_number, entry_date, description, entry_type, 
                total_debit, total_credit, status, posting_date,
                created_by, updated_by, approved_by, approved_at
            ) VALUES (:entry_number, :entry_date, :description, 'adjustment', 0, 0, 'approved', NOW(), :created_by, :updated_by, :approved_by, NOW())
        ";

        $stmt = $conn->prepare($insert_opening_entry);
        $stmt->bindParam(':entry_number', $opening_entry_number, PDO::PARAM_STR);
        $stmt->bindParam(':entry_date', $opening_entry_date, PDO::PARAM_STR);
        $stmt->bindParam(':description', $opening_description, PDO::PARAM_STR);
        $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':approved_by', $_SESSION['user_id'], PDO::PARAM_INT);

        if (!$stmt->execute()) {
            throw new Exception("Error creating opening journal entry: " . $stmt->error);
        }

        $opening_entry_id = $conn->lastInsertId();

        // Process each balance sheet account
        foreach ($accounts as $account) {
            $balance = $account['current_balance'];
            
            if ($balance == 0) {
                continue; // Skip accounts with zero balance
            }

            // Add to closing entry
            $debit_amount = 0;
            $credit_amount = 0;

            // For closing entry, we zero out the accounts
            if ($account['balance_type'] == 'debit') {
                $credit_amount = $balance;
                $total_credit += $balance;
            } else {
                $debit_amount = $balance;
                $total_debit += $balance;
            }

            $insert_closing_detail = "
                INSERT INTO journal_entry_details (
                    journal_entry_id, account_id, description, debit_amount, credit_amount
                ) VALUES (:journal_entry_id, :account_id, :description, :debit_amount, :credit_amount)
            ";

            $closing_description = "Closing balance for {$account['account_name']}";
            
            $stmt = $conn->prepare($insert_closing_detail);
            $stmt->bindParam(':journal_entry_id', $closing_entry_id, PDO::PARAM_INT);
            $stmt->bindParam(':account_id', $account['id'], PDO::PARAM_INT);
            $stmt->bindParam(':description', $closing_description, PDO::PARAM_STR);
            $stmt->bindParam(':debit_amount', $debit_amount, PDO::PARAM_INT);
            $stmt->bindParam(':credit_amount', $credit_amount, PDO::PARAM_INT);

            if (!$stmt->execute()) {
                throw new Exception("Error adding closing entry detail: " . $stmt->error);
            }

            // Add to opening entry for next year
            $debit_amount = 0;
            $credit_amount = 0;

            // For opening entry, we restore the balances
            if ($account['balance_type'] == 'debit') {
                $debit_amount = $balance;
                $total_debit += $balance;
            } else {
                $credit_amount = $balance;
                $total_credit += $balance;
            }

            $insert_opening_detail = "
                INSERT INTO journal_entry_details (
                    journal_entry_id, account_id, description, debit_amount, credit_amount
                ) VALUES (:journal_entry_id, :account_id, :description, :debit_amount, :credit_amount)
            ";

            $opening_description = "Opening balance for {$account['account_name']}";
            
            $stmt = $conn->prepare($insert_opening_detail);
            $stmt->bindParam(':journal_entry_id', $opening_entry_id, PDO::PARAM_INT);
            $stmt->bindParam(':account_id', $account['id'], PDO::PARAM_INT);
            $stmt->bindParam(':description', $opening_description, PDO::PARAM_STR);
            $stmt->bindParam(':debit_amount', $debit_amount, PDO::PARAM_INT);
            $stmt->bindParam(':credit_amount', $credit_amount, PDO::PARAM_INT);

            if (!$stmt->execute()) {
                throw new Exception("Error adding opening entry detail: " . $stmt->error);
            }
        }

        // Update totals for closing entry
        $update_closing_totals = "UPDATE journal_entries SET total_debit = :total_debit, total_credit = :total_credit WHERE id = :closing_entry_id";
        $stmt = $conn->prepare($update_closing_totals);
        $stmt->bindParam(':total_debit', $total_debit, PDO::PARAM_INT);
        $stmt->bindParam(':total_credit', $total_credit, PDO::PARAM_INT);
        $stmt->bindParam(':closing_entry_id', $closing_entry_id, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error updating closing entry totals: " . $stmt->error);
        }

        // Update totals for opening entry
        $update_opening_totals = "UPDATE journal_entries SET total_debit = :total_debit, total_credit = :total_credit WHERE id = :opening_entry_id";
        $stmt = $conn->prepare($update_opening_totals);
        $stmt->bindParam(':total_debit', $total_debit, PDO::PARAM_INT);
        $stmt->bindParam(':total_credit', $total_credit, PDO::PARAM_INT);
        $stmt->bindParam(':opening_entry_id', $opening_entry_id, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error updating opening entry totals: " . $stmt->error);
        }

        // Update financial year to closed status
        $update_query = "
            UPDATE financial_years 
            SET is_closed = 1, 
                closed_by = :closed_by, 
                closed_at = NOW() 
            WHERE id = :year_id";
        
        $stmt = $conn->prepare($update_query);
        $stmt->bindParam(':closed_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':year_id', $year_id, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error closing financial year");
        }

        // Get all account balances at the end of the financial year
        $balances_query = "
            WITH RECURSIVE account_tree AS (
                SELECT 
                    coa.id, coa.parent_id, coa.account_code, coa.account_name,
                    COALESCE(
                        (SELECT 
                            SUM(CASE 
                                WHEN je.entry_type = 'debit' THEN jed.debit_amount - jed.credit_amount 
                                ELSE jed.credit_amount - jed.debit_amount 
                            END)
                        FROM journal_entries je
                        JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                        WHERE jed.account_id = coa.id
                        AND je.financial_year_id = :year_id
                        AND je.status = 'posted'
                        AND je.entry_date <= :end_date), 
                    0) as balance
                FROM chart_of_accounts coa
                WHERE coa.parent_id IS NULL AND coa.status = 1
                
                UNION ALL
                
                SELECT 
                    c.id, c.parent_id, c.account_code, c.account_name,
                    COALESCE(
                        (SELECT 
                            SUM(CASE 
                                WHEN je.entry_type = 'debit' THEN jed.debit_amount - jed.credit_amount 
                                ELSE jed.credit_amount - jed.debit_amount 
                            END)
                        FROM journal_entries je
                        JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                        WHERE jed.account_id = c.id
                        AND je.financial_year_id = :year_id2
                        AND je.status = 'posted'
                        AND je.entry_date <= :end_date2), 
                    0) as balance
                FROM chart_of_accounts c
                JOIN account_tree p ON c.parent_id = p.id
                WHERE c.status = 1
            )
            SELECT * FROM account_tree 
            WHERE balance != 0
            ORDER BY account_code";
            
        $stmt = $conn->prepare($balances_query);
        $stmt->bindParam(':year_id', $year_id, PDO::PARAM_INT);
        $stmt->bindParam(':year_id2', $year_id, PDO::PARAM_INT);
        $stmt->bindParam(':end_date', $financial_year['end_date'], PDO::PARAM_STR);
        $stmt->bindParam(':end_date2', $financial_year['end_date'], PDO::PARAM_STR);
        $stmt->execute();
        
        $account_balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => "Financial year '{$financial_year['year_code']}' closed successfully. Balances have been carried forward to the next financial year."
        ]);

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
