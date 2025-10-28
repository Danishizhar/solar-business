<?php
/**
 * Execute SQL to add approval PIN to users table
 * 
 * This script executes the SQL to add the approval_pin and user_type columns to the users table.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo "User not logged in. Please log in to continue.";
    exit();
}

// Include database connection
require_once '../../../config/database.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Read SQL file
    $sql_file = '../sql/add_approval_pin_to_users.sql';
    $sql = file_get_contents($sql_file);
    
    if ($sql === false) {
        throw new Exception("Error reading SQL file: $sql_file");
    }
    
    // Split SQL statements
    $statements = array_filter(array_map('trim', explode(';', $sql)), 'strlen');
    
    // Execute each statement
    foreach ($statements as $statement) {
        $result = $db->exec($statement);
        echo "Executed: " . substr($statement, 0, 50) . "...<br>";
    }
    
    echo "<p>SQL executed successfully!</p>";
    echo "<p>Added approval_pin and user_type columns to users table.</p>";
    echo "<a href='../../../index.php'>Return to Dashboard</a>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
