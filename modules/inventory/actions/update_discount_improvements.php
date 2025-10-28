<?php
/**
 * Update Discount System with Improvements
 * 
 * This script executes the SQL file to add improvements to the discount management system
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    exit();
}

// Include database connection
require_once '../../../config/database.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

// Path to SQL file
$sql_file = '../sql/discount_improvements.sql';

// Check if file exists
if (!file_exists($sql_file)) {
    echo json_encode(['success' => false, 'message' => 'SQL file not found.']);
    exit();
}

// Read SQL file
$sql = file_get_contents($sql_file);

// Split SQL file into individual statements
$statements = array_filter(array_map('trim', explode(';', $sql)), 'strlen');

// Execute each statement
$success = true;
$error_message = '';

try {
    $db->beginTransaction();
    
    foreach ($statements as $statement) {
        $result = $db->exec($statement);
        
        if ($result === false) {
            $error_info = $db->errorInfo();
            throw new Exception("Error executing SQL: " . $error_info[2]);
        }
    }
    
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Discount system has been successfully updated with new features.']);
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error updating discount system: ' . $e->getMessage()]);
}
?>
