<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

function log_debug($message, $data = null) {
    try {
        $log = date('Y-m-d H:i:s') . " - " . $message;
        if ($data !== null) {
            $log .= "\n" . print_r($data, true);
        }
        $log .= "\n";
        $log_file = dirname(__DIR__, 3) . '/logs/reconciliation.log';
        file_put_contents($log_file, $log, FILE_APPEND);
    } catch (Exception $e) {
        error_log("Error writing to log: " . $e->getMessage());
    }
}

try {
    // Check if user is not logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit();
    }

    // Check if user doesn't have permission
    if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_reconcile'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }

    require_once dirname(__DIR__, 3) . '/config/database.php';

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Sanitize numeric inputs
    if (isset($input['statement_balance'])) {
        $input['statement_balance'] = floatval(str_replace(',', '', $input['statement_balance']));
    }
    
    log_debug("Received input", $input);

    // Validate input
    if (!isset($input['bank_account']) || !isset($input['statement_date']) || 
        !isset($input['statement_balance']) || !isset($input['transactions'])) {
        throw new Exception('Missing required fields');
    }

    $database = new Database();
    $conn = $database->getConnection();
    
    // Start transaction
    $conn->beginTransaction();
    log_debug("Started transaction");

    // Get bank account details with balance type from chart of accounts
    $account_query = "
        SELECT ba.id as bank_id, ba.account_id, coa.account_name, coa.balance_type
        FROM bank_accounts ba
        JOIN chart_of_accounts coa ON ba.account_id = coa.id
        WHERE (ba.id = :bank_id OR ba.account_id = :account_id) AND ba.status = 1";
    
    $stmt = $conn->prepare($account_query);
    $stmt->bindValue(':bank_id', $input['bank_account'], PDO::PARAM_INT);
    $stmt->bindValue(':account_id', $input['bank_account'], PDO::PARAM_INT);
    $stmt->execute();
    $bank_account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bank_account) {
        throw new Exception('Bank account not found or inactive');
    }

    log_debug("Bank account details", $bank_account);
    
    // Get adjustment account (either custom or default variance account)
    if (!empty($input['adjustment_account'])) {
        // Use the specified adjustment account
        $adjustment_query = "SELECT id, balance_type FROM chart_of_accounts WHERE id = :account_id AND status = 1";
        $stmt = $conn->prepare($adjustment_query);
        $stmt->bindValue(':account_id', $input['adjustment_account'], PDO::PARAM_INT);
        $stmt->execute();
        $adjustment_account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$adjustment_account) {
            throw new Exception('Selected adjustment account not found or inactive');
        }
    } else {
        // Use default variance account
        $variance_query = "SELECT id, balance_type FROM chart_of_accounts WHERE account_code = '9999' AND status = 1";
        $stmt = $conn->prepare($variance_query);
        $stmt->execute();
        $adjustment_account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$adjustment_account) {
            throw new Exception('Reconciliation variance account not found');
        }
    }

    log_debug("Adjustment account details", $adjustment_account);
    
    // Calculate current bank balance
    $balance_query = "SELECT 
        SUM(CASE 
            WHEN je.status = 'approved' THEN 
                CASE 
                    WHEN coa.balance_type = 'debit' THEN 
                        COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0)
                    ELSE 
                        COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0)
                END
            ELSE 0 
        END) as bank_balance
        FROM journal_entries je
        JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
        JOIN chart_of_accounts coa ON jed.account_id = coa.id
        WHERE jed.account_id = :account_id
        AND je.status = 'approved'";
    
    $stmt = $conn->prepare($balance_query);
    $stmt->bindValue(':account_id', $bank_account['account_id'], PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $bank_balance = $result['bank_balance'] ?? 0;
    
    // Calculate reconciled balance from selected transactions
    $reconciled_query = "SELECT 
        SUM(CASE 
            WHEN coa.balance_type = 'debit' THEN 
                COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0)
            ELSE 
                COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0)
            END
        ) as reconciled_balance
        FROM journal_entries je
        JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
        JOIN chart_of_accounts coa ON jed.account_id = coa.id
        WHERE je.id IN (" . implode(',', array_map('intval', $input['transactions'])) . ")
        AND jed.account_id = :account_id";
    
    $stmt = $conn->prepare($reconciled_query);
    $stmt->bindValue(':account_id', $bank_account['account_id'], PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $reconciled_balance = $result['reconciled_balance'] ?? 0;
    
    // Calculate difference - ensure numeric values
    $reconciled_balance = floatval($reconciled_balance);
    $difference = $input['statement_balance'] - $reconciled_balance;
    
    // Ensure the difference is not a floating point rounding error
    if (abs($difference) < 0.0001) {
        $difference = 0;
    }
    
    log_debug("Balances", [
        'bank_balance' => $bank_balance,
        'reconciled_balance' => $reconciled_balance,
        'statement_balance' => $input['statement_balance'],
        'difference' => $difference
    ]);
    
    // Insert reconciliation record
    $insert_query = "INSERT INTO bank_reconciliation (
        bank_account_id, statement_date, statement_balance,
        bank_balance, reconciled_balance, difference,
        status, notes, created_by, updated_by
    ) VALUES (
        :bank_account, :statement_date, :statement_balance,
        :bank_balance, :reconciled_balance, :difference,
        'draft', :notes, :created_by, :updated_by
    )";
    
    $stmt = $conn->prepare($insert_query);
    $stmt->bindValue(':bank_account', $bank_account['account_id'], PDO::PARAM_INT);
    $stmt->bindValue(':statement_date', $input['statement_date']);
    $stmt->bindValue(':statement_balance', $input['statement_balance'], PDO::PARAM_STR);
    $stmt->bindValue(':bank_balance', $bank_balance, PDO::PARAM_STR);
    $stmt->bindValue(':reconciled_balance', $reconciled_balance, PDO::PARAM_STR);
    $stmt->bindValue(':difference', $difference, PDO::PARAM_STR);
    $stmt->bindValue(':notes', $input['notes'] ?? null);
    $stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    
    $reconciliation_id = $conn->lastInsertId();
    log_debug("Created reconciliation record: " . $reconciliation_id);
    
    // Create reconciliation items for selected transactions
    foreach ($input['transactions'] as $transaction_id) {
        $item_query = "INSERT INTO bank_reconciliation_items (
            reconciliation_id, journal_entry_id, is_reconciled,
            created_by, updated_by
        ) VALUES (?, ?, 1, ?, ?)";
        
        $stmt = $conn->prepare($item_query);
        $stmt->bindValue(1, $reconciliation_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $transaction_id, PDO::PARAM_INT);
        $stmt->bindValue(3, $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(4, $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();
    }
    
    // If there's a difference, create adjustment entry
    if (abs($difference) > 0.01) {  // Only create adjustment if difference is significant
        // Generate a unique entry number for the reconciliation entry
        $entry_number_query = "SELECT CONCAT('REC-', LPAD(COALESCE(MAX(SUBSTRING_INDEX(entry_number, '-', -1))+1, 1), 5, '0')) as next_entry_number 
            FROM journal_entries 
            WHERE entry_number LIKE 'REC-%'";
        $stmt = $conn->prepare($entry_number_query);
        $stmt->execute();
        $entry_number_result = $stmt->fetch(PDO::FETCH_ASSOC);
        $entry_number = $entry_number_result['next_entry_number'] ?? ('REC-' . date('Ymd') . '-' . $reconciliation_id);
        
        log_debug("Generated entry number", $entry_number);
        
        // Get current active financial year for the transaction date
        $financial_year_query = "SELECT id FROM financial_years 
            WHERE :entry_date BETWEEN start_date AND end_date 
            AND status = 1 AND is_closed = 0 
            ORDER BY start_date DESC LIMIT 1";
        $stmt = $conn->prepare($financial_year_query);
        $stmt->bindValue(':entry_date', $input['statement_date']);
        $stmt->execute();
        $financial_year = $stmt->fetch(PDO::FETCH_ASSOC);
        $financial_year_id = $financial_year ? $financial_year['id'] : null;
        
        log_debug("Financial year for reconciliation", ['date' => $input['statement_date'], 'financial_year_id' => $financial_year_id]);
        
        // Create journal entry for reconciliation adjustment
        $entry_query = "INSERT INTO journal_entries (
            entry_number, entry_date, entry_type, description, reference_number,
            total_debit, total_credit, status, financial_year_id, created_by, updated_by
        ) VALUES (
            :entry_number, :entry_date, 'reconciliation', :description,
            CONCAT('REC-', :reconciliation_id),
            :total_debit, :total_credit, 'approved', :financial_year_id, :created_by, :updated_by
        )";
        
        // Set the total debit and credit amounts based on the difference
        // In a balanced journal entry, total_debit = total_credit = the absolute adjustment amount
        $total_debit = abs($difference);
        $total_credit = abs($difference);
        
        // Format the amounts properly to avoid floating point issues
        $total_debit = formatNumber($conn, $total_debit, true);
        $total_credit = formatNumber($conn, $total_credit, true);
        
        $stmt = $conn->prepare($entry_query);
        $stmt->bindValue(':entry_number', $entry_number);
        $stmt->bindValue(':entry_date', $input['statement_date']);
        $stmt->bindValue(':description', 'Bank reconciliation adjustment for ' . $bank_account['account_name']);
        $stmt->bindValue(':reconciliation_id', $reconciliation_id, PDO::PARAM_INT);
        $stmt->bindValue(':total_debit', $total_debit, PDO::PARAM_STR);
        $stmt->bindValue(':total_credit', $total_credit, PDO::PARAM_STR);
        $stmt->bindValue(':financial_year_id', $financial_year_id, PDO::PARAM_INT);
        $stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();
        
        log_debug("Created journal entry with totals", [
            'journal_entry_id' => $conn->lastInsertId(),
            'total_debit' => $total_debit,
            'total_credit' => $total_credit
        ]);
        
        $journal_entry_id = $conn->lastInsertId();
        
        // Create journal entry details based on account types
        $details_query = "INSERT INTO journal_entry_details (
            journal_entry_id, account_id, 
            debit_amount, credit_amount,
            created_by, updated_by
        ) VALUES 
        (?, ?, ?, ?, ?, ?),
        (?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($details_query);
        
        // Store the formatted difference amount to ensure consistency
        $diff_amount = formatNumber($conn, abs($difference), true);
        log_debug("Formatted difference amount", ['raw' => $difference, 'formatted' => $diff_amount]);
        
        if ($difference > 0) {
            // Statement balance is higher than reconciled balance
            if ($bank_account['balance_type'] === 'debit') {
                // For debit-type bank accounts:
                // Debit bank account (increase)
                // Credit variance account
                $stmt->bindValue(1, $journal_entry_id, PDO::PARAM_INT);
                $stmt->bindValue(2, $bank_account['account_id'], PDO::PARAM_INT);
                $stmt->bindValue(3, $diff_amount, PDO::PARAM_STR);
                $stmt->bindValue(4, '0.00', PDO::PARAM_STR);
                $stmt->bindValue(5, $_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->bindValue(6, $_SESSION['user_id'], PDO::PARAM_INT);
                
                $stmt->bindValue(7, $journal_entry_id, PDO::PARAM_INT);
                $stmt->bindValue(8, $adjustment_account['id'], PDO::PARAM_INT);
                $stmt->bindValue(9, 0, PDO::PARAM_STR);
                $stmt->bindValue(10, abs($difference), PDO::PARAM_STR);
                $stmt->bindValue(11, $_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->bindValue(12, $_SESSION['user_id'], PDO::PARAM_INT);
            } else {
                // For credit-type bank accounts:
                // Credit bank account (increase)
                // Debit variance account
                $stmt->bindValue(1, $journal_entry_id, PDO::PARAM_INT);
                $stmt->bindValue(2, $bank_account['account_id'], PDO::PARAM_INT);
                $stmt->bindValue(3, '0.00', PDO::PARAM_STR);
                $stmt->bindValue(4, formatNumber($conn, abs($difference), true), PDO::PARAM_STR);
                $stmt->bindValue(5, $_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->bindValue(6, $_SESSION['user_id'], PDO::PARAM_INT);
                
                $stmt->bindValue(7, $journal_entry_id, PDO::PARAM_INT);
                $stmt->bindValue(8, $adjustment_account['id'], PDO::PARAM_INT);
                $stmt->bindValue(9, abs($difference), PDO::PARAM_STR);
                $stmt->bindValue(10, 0, PDO::PARAM_STR);
                $stmt->bindValue(11, $_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->bindValue(12, $_SESSION['user_id'], PDO::PARAM_INT);
            }
        } else {
            // Statement balance is lower than reconciled balance
            if ($bank_account['balance_type'] === 'debit') {
                // For debit-type bank accounts:
                // Credit bank account (decrease)
                // Debit variance account
                $stmt->bindValue(1, $journal_entry_id, PDO::PARAM_INT);
                $stmt->bindValue(2, $bank_account['account_id'], PDO::PARAM_INT);
                $stmt->bindValue(3, '0.00', PDO::PARAM_STR);
                $stmt->bindValue(4, formatNumber($conn, abs($difference), true), PDO::PARAM_STR);
                $stmt->bindValue(5, $_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->bindValue(6, $_SESSION['user_id'], PDO::PARAM_INT);
                
                $stmt->bindValue(7, $journal_entry_id, PDO::PARAM_INT);
                $stmt->bindValue(8, $adjustment_account['id'], PDO::PARAM_INT);
                $stmt->bindValue(9, abs($difference), PDO::PARAM_STR);
                $stmt->bindValue(10, 0, PDO::PARAM_STR);
                $stmt->bindValue(11, $_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->bindValue(12, $_SESSION['user_id'], PDO::PARAM_INT);
            } else {
                // For credit-type bank accounts:
                // Debit bank account (decrease)
                // Credit variance account
                $stmt->bindValue(1, $journal_entry_id, PDO::PARAM_INT);
                $stmt->bindValue(2, $bank_account['account_id'], PDO::PARAM_INT);
                $stmt->bindValue(3, $diff_amount, PDO::PARAM_STR);
                $stmt->bindValue(4, '0.00', PDO::PARAM_STR);
                $stmt->bindValue(5, $_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->bindValue(6, $_SESSION['user_id'], PDO::PARAM_INT);
                
                $stmt->bindValue(7, $journal_entry_id, PDO::PARAM_INT);
                $stmt->bindValue(8, $adjustment_account['id'], PDO::PARAM_INT);
                $stmt->bindValue(9, 0, PDO::PARAM_STR);
                $stmt->bindValue(10, abs($difference), PDO::PARAM_STR);
                $stmt->bindValue(11, $_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->bindValue(12, $_SESSION['user_id'], PDO::PARAM_INT);
            }
        }
        
        $stmt->execute();
        // Add detailed logging for debugging
        log_debug("Created journal entry details for difference", [
            'difference' => $difference,
            'abs_difference' => abs($difference),
            'bank_balance_type' => $bank_account['balance_type'],
            'adjustment_account_id' => $adjustment_account['id'],
            'adjustment_balance_type' => $adjustment_account['balance_type'],
            'journal_entry_id' => $journal_entry_id,
            'sql_params' => [
                'bank_account_id' => $bank_account['account_id'],
                'adjustment_account_id' => $adjustment_account['id']
            ]
        ]);
    }
    
    // Update reconciliation status to completed
    $update_query = "UPDATE bank_reconciliation SET status = 'completed' WHERE id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bindValue(1, $reconciliation_id, PDO::PARAM_INT);
    $stmt->execute();
    log_debug("Updated reconciliation status to completed");
    
    // Update bank account balance and reconciliation date
    $update_balance = "UPDATE bank_accounts 
                      SET current_balance = (
                          SELECT SUM(
                              CASE 
                                  WHEN je.status = 'approved' THEN 
                                      CASE 
                                          WHEN coa.balance_type = 'debit' THEN 
                                              COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0)
                                          ELSE 
                                              COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0)
                                      END
                                  ELSE 0 
                              END
                          )
                          FROM journal_entries je
                          JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                          JOIN chart_of_accounts coa ON jed.account_id = coa.id
                          WHERE jed.account_id = ?
                          AND je.status = 'approved'
                      ),
                      last_reconciliation_date = ?,
                      updated_by = ?,
                      updated_at = NOW()
                      WHERE id = ?";
    
    $stmt = $conn->prepare($update_balance);
    $stmt->bindValue(1, $bank_account['account_id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $input['statement_date']);
    $stmt->bindValue(3, $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(4, $bank_account['bank_id'], PDO::PARAM_INT);
    $stmt->execute();
    log_debug("Updated bank account balance and reconciliation date");
    
    $conn->commit();
    log_debug("Committed transaction");
    
    echo json_encode([
        'success' => true, 
        'message' => 'Reconciliation saved successfully',
        'data' => [
            'reconciliation_id' => $reconciliation_id,
            'difference' => $difference,
            'statement_balance' => $input['statement_balance'],
            'reconciled_balance' => $reconciled_balance
        ]
    ]);
    
} catch (Exception $e) {
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
        log_debug("Rolled back transaction due to error: " . $e->getMessage());
    }
    echo json_encode([
        'success' => false, 
        'message' => 'Error saving reconciliation: ' . $e->getMessage()
    ]);
    exit();
}
?>
