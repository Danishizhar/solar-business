<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['account_id'])) {
    try {
        $query = "
            SELECT 
                coa.id,
                coa.account_code,
                coa.account_name,
                coa.description,
                coa.is_group,
                coa.parent_id,
                coa.category_id,
                coa.level,
                coa.opening_balance,
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
                ) as current_balance,
                coa.balance_type,
                coa.is_bank_account,
                coa.created_at,
                coa.updated_at,
                ac.name as category_name,
                COALESCE(p.account_name, 'None') as parent_name,
                CASE 
                    WHEN coa.is_group = 1 THEN 'Group Account'
                    WHEN coa.is_bank_account = 1 THEN 'Bank Account'
                    ELSE 'Regular Account'
                END as account_type,
                c.username as created_by,
                u.username as updated_by
            FROM chart_of_accounts coa
            LEFT JOIN account_categories ac ON ac.id = coa.category_id
            LEFT JOIN chart_of_accounts p ON p.id = coa.parent_id
            LEFT JOIN users c ON c.id = coa.created_by
            LEFT JOIN users u ON u.id = coa.updated_by
            WHERE coa.id = :account_id 
            AND coa.status = 1
            LIMIT 1";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':account_id', $_POST['account_id'], PDO::PARAM_INT);
        $stmt->execute();

        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            // Convert balance strings to floats for proper JSON encoding
            $account['opening_balance'] = floatval($account['opening_balance']);
            $account['current_balance'] = floatval($account['current_balance']);
            
            echo json_encode([
                'success' => true,
                'data' => $account
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Account not found'
            ]);
        }
    } catch (PDOException $e) {
        error_log("PDOException in get_account.php: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request'
    ]);
}
