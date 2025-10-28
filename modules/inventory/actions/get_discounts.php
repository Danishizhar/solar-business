<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
require_once '../includes/discount_helpers.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get all discounts
    $query = "SELECT * FROM discounts WHERE status = 1 ORDER BY id DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response for DataTables
    $response = array(
        "data" => $discounts
    );
    
    echo json_encode($response);
} catch (PDOException $e) {
    error_log("Error fetching discounts: " . $e->getMessage());
    
    // Return empty dataset with error
    $response = array(
        "data" => array(),
        "error" => "Error fetching discounts: " . $e->getMessage()
    );
    
    echo json_encode($response);
}
?>
