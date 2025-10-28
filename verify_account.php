<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "
        SELECT c.*, ac.name as category_name, ac.type as category_type
        FROM chart_of_accounts c
        JOIN account_categories ac ON c.category_id = ac.id
        WHERE c.account_code = '9999'";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Account Details:\n";
        echo "ID: {$row['id']}\n";
        echo "Code: {$row['account_code']}\n";
        echo "Name: {$row['account_name']}\n";
        echo "Category: {$row['category_name']} ({$row['category_type']})\n";
        echo "Balance Type: {$row['balance_type']}\n";
        echo "Current Balance: {$row['current_balance']}\n";
        echo "Status: " . ($row['status'] ? 'Active' : 'Inactive') . "\n";
    } else {
        echo "Account not found\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
