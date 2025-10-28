<?php
/**
 * Discount System Upgrade Script
 * This script executes the SQL upgrade script to add new features to the discount system
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to perform this action.']);
    exit();
}

// Check for admin status in multiple ways
$is_admin_by_flag = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$is_admin_by_role = isset($_SESSION['user_role']) && ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'Admin');
$is_super_user = isset($_SESSION['is_super_user']) && $_SESSION['is_super_user'] == 1;

// Allow if any admin condition is met
if (!$is_admin_by_flag && !$is_admin_by_role && !$is_super_user) {
    // Log the session data for debugging
    error_log('User attempted to upgrade discount system without admin rights: ' . print_r($_SESSION, true));
    echo json_encode(['success' => false, 'message' => 'You must have administrator privileges to perform this action.']);
    exit();
}

// Include database connection
require_once '../../../config/database.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

// Path to the SQL file
$sql_file = '../sql/discount_system_upgrade.sql';

// Check if the file exists
if (!file_exists($sql_file)) {
    echo json_encode(['success' => false, 'message' => 'SQL upgrade file not found.']);
    exit();
}

// Read the SQL file
$sql = file_get_contents($sql_file);

// Split the SQL file into individual statements
$statements = explode(';', $sql);

// Begin transaction
$db->beginTransaction();

try {
    $success = true;
    $errors = [];
    
    // Execute each statement
    foreach ($statements as $statement) {
        $statement = trim($statement);
        
        // Skip empty statements
        if (empty($statement)) {
            continue;
        }
        
        // Execute the statement
        $result = $db->exec($statement);
        
        // Check for errors
        if ($result === false) {
            $error_info = $db->errorInfo();
            // Ignore "Duplicate column" errors as the script might be run multiple times
            if ($error_info[0] != '42S21' && $error_info[0] != '42S01') {
                $success = false;
                $errors[] = "Error executing statement: " . $statement . " - " . $error_info[2];
            }
        }
    }
    
    // If all statements executed successfully, commit the transaction
    if ($success) {
        $db->commit();
        
        // Log the upgrade
        $user_id = $_SESSION['user_id'];
        $log_query = "INSERT INTO activity_logs (user_id, action, module, description) 
                     VALUES (:user_id, 'upgrade', 'inventory', 'Upgraded discount management system')";
        $log_stmt = $db->prepare($log_query);
        $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $log_stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Discount system upgraded successfully.']);
    } else {
        // If there were errors, roll back the transaction
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error upgrading discount system.', 'errors' => $errors]);
    }
} catch (PDOException $e) {
    // If there was an exception, roll back the transaction
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
