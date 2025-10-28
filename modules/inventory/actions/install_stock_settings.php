<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Check if user has admin role
if ($_SESSION['role_id'] != 1) {
    header("Location: ../../../index.php");
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Initialize response
$response = [
    'success' => false,
    'messages' => []
];

try {
    // Read SQL file
    $sql_file = file_get_contents('../sql/add_stock_settings.sql');
    
    if (!$sql_file) {
        throw new Exception("Could not read SQL file");
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Split into individual statements
    $statements = explode(';', $sql_file);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $db->exec($statement);
        }
    }
    
    // Commit transaction
    $db->commit();
    
    // Update response
    $response['success'] = true;
    $response['messages'][] = "Stock settings installed successfully";
    
    // Set session message
    $_SESSION['success_message'] = "Stock settings installed successfully";
    
} catch (PDOException $e) {
    // Rollback transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Log error
    error_log("Error installing stock settings: " . $e->getMessage());
    
    // Update response
    $response['messages'][] = "Database error: " . $e->getMessage();
    
    // Set session message
    $_SESSION['error_message'] = "Error installing stock settings: " . $e->getMessage();
} catch (Exception $e) {
    // Update response
    $response['messages'][] = $e->getMessage();
    
    // Set session message
    $_SESSION['error_message'] = $e->getMessage();
}

// Return JSON response if AJAX request
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Redirect to settings page if not AJAX
header("Location: ../settings.php");
exit();
