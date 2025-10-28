<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

try {
    // 1. Check Journal Entry
    echo "=== Recent Journal Entry ===\n";
    $je_query = "
        SELECT 
            je.id, je.entry_number, je.entry_date, je.entry_type,
            je.description, je.total_debit, je.total_credit, je.status,
            je.created_at
        FROM journal_entries je
        WHERE je.entry_date = CURDATE()
        ORDER BY je.id DESC
        LIMIT 1";
    
    $je_stmt = $conn->prepare($je_query);
    $je_stmt->execute();
    $je = $je_stmt->fetch(PDO::FETCH_ASSOC);
    print_r($je);

    if ($je) {
        echo "\n=== Journal Entry Details ===\n";
        $jed_query = "
            SELECT 
                jed.account_id,
                coa.account_code,
                coa.account_name,
                jed.debit_amount,
                jed.credit_amount
            FROM journal_entry_details jed
            JOIN chart_of_accounts coa ON coa.id = jed.account_id
            WHERE jed.journal_entry_id = ?
            ORDER BY jed.id";
        
        $jed_stmt = $conn->prepare($jed_query);
        $jed_stmt->bindValue(1, $je['id']);
        $jed_stmt->execute();
        $details = $jed_stmt->fetchAll(PDO::FETCH_ASSOC);
        print_r($details);

        echo "\n=== Current Bank Balance ===\n";
        $balance_query = "
            SELECT 
                coa.id,
                coa.account_code,
                coa.account_name,
                coa.opening_balance +
                COALESCE(
                    (SELECT SUM(jed2.debit_amount - jed2.credit_amount)
                    FROM journal_entry_details jed2
                    JOIN journal_entries je2 ON je2.id = jed2.journal_entry_id
                    WHERE jed2.account_id = coa.id
                    AND je2.status = 'posted'), 0
                ) as current_balance
            FROM chart_of_accounts coa
            WHERE coa.id = ?";
        
        $balance_stmt = $conn->prepare($balance_query);
        $balance_stmt->bindValue(1, $details[0]['account_id']); // Bank account is first (debit entry)
        $balance_stmt->execute();
        $balance = $balance_stmt->fetch(PDO::FETCH_ASSOC);
        print_r($balance);
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
