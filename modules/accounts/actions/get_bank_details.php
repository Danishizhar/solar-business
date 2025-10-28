<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['account_id'])) {
    try {
        $query = "
            SELECT 
                ba.bank_name,
                ba.branch_name,
                ba.account_number,
                ba.swift_code,
                ba.iban,
                ba.account_title,
                ba.opening_balance,
                ba.current_balance
            FROM bank_accounts ba
            WHERE ba.account_id = :account_id 
            AND ba.status = 1
            LIMIT 1";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':account_id', $_POST['account_id'], PDO::PARAM_INT);
        $stmt->execute();

        $bankDetails = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($bankDetails) {
            echo json_encode([
                'success' => true,
                'data' => $bankDetails
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Bank details not found'
            ]);
        }
    } catch (PDOException $e) {
        error_log("PDOException in get_bank_details.php: " . $e->getMessage());
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
