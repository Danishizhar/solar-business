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
require_once '../../../includes/company_helpers.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['id'])) {
            throw new Exception("Bank account ID is required");
        }

        $bank_account_id = $_POST['id'];

        // Get bank account details
        $query = "
            SELECT 
                ba.*,
                coa.account_code,
                coa.account_name,
                c.username as created_by_name,
                u.username as updated_by_name,
                coa.opening_balance + COALESCE(
                    (SELECT SUM(
                        CASE 
                            WHEN ac.type IN ('asset', 'expense') THEN 
                                COALESCE(jed.debit_amount, 0) - COALESCE(jed.credit_amount, 0)
                            ELSE 
                                COALESCE(jed.credit_amount, 0) - COALESCE(jed.debit_amount, 0)
                        END
                    )
                    FROM journal_entries je
                    JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                    WHERE jed.account_id = coa.id
                    AND je.status IN ('posted', 'approved')
                    ), 0
                ) as current_balance
            FROM bank_accounts ba
            JOIN chart_of_accounts coa ON ba.account_id = coa.id
            JOIN account_categories ac ON coa.category_id = ac.id
            LEFT JOIN users c ON ba.created_by = c.id
            LEFT JOIN users u ON ba.updated_by = u.id
            WHERE ba.id = :id";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $bank_account_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $bank_account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($bank_account) {
            // Format current balance
            $bank_account['current_balance'] = formatNumber($conn, $bank_account['current_balance']);
            
            // Set currency (you can make this dynamic later)
            $bank_account['currency'] = 'PKR';
            
            // Get recent transactions
            $transactions_query = "
                SELECT 
                    je.id,
                    je.entry_number,
                    je.entry_date,
                    je.entry_type,
                    je.description,
                    CASE 
                        WHEN jed.debit_amount > 0 THEN jed.debit_amount 
                        ELSE -jed.credit_amount 
                    END as amount,
                    je.status
                FROM journal_entries je
                JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
                WHERE jed.account_id = :account_id
                AND je.status IN ('draft', 'posted')
                ORDER BY je.entry_date DESC, je.id DESC
                LIMIT 10";
            
            $stmt = $conn->prepare($transactions_query);
            $stmt->bindParam(':account_id', $bank_account['account_id'], PDO::PARAM_INT);
            $stmt->execute();
            $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $bank_account['recent_transactions'] = $recent_transactions;
            
            // Activity logging removed - table doesn't exist yet
            // Uncomment and create table if activity logging is needed
            /*
            $log_query = "
                INSERT INTO activity_log (
                    module, action, record_id, description, 
                    created_by, created_at
                ) VALUES (
                    'bank_accounts', 'view', :record_id, 
                    :description, :created_by, NOW()
                )";
            $stmt = $conn->prepare($log_query);
            $description = "Viewed bank account details for " . $bank_account['account_name'];
            $stmt->bindParam(':record_id', $bank_account_id, PDO::PARAM_INT);
            $stmt->bindParam(':description', $description, PDO::PARAM_STR);
            $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
            $stmt->execute();
            */
            
            echo json_encode(['success' => true, 'data' => $bank_account]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Bank account not found']);
        }

    } catch (PDOException $e) {
        error_log("Database error in get_bank_account.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
