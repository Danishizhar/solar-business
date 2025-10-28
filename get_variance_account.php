<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT id FROM chart_of_accounts WHERE account_code = '9999'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Variance Account ID: {$row['id']}\n";
    } else {
        echo "Variance account not found\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
