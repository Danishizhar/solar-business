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
    // Start transaction
    $db->beginTransaction();
    
    // List of settings to keep (these are the ones that are actually used in the system)
    $settings_to_keep = [
        'default_adjustment_reasons',
        'auto_generate_transaction_numbers',
        'transaction_number_prefix',
        'default_transaction_type',
        'require_notes_for_transactions'
    ];
    
    // Convert the array to a comma-separated list of quoted strings for SQL
    $settings_list = "'" . implode("','", $settings_to_keep) . "'";
    
    // Delete all stock settings that are not in the list of settings to keep
    $delete_query = "DELETE FROM inventory_settings 
                    WHERE setting_group = 'stock' 
                    AND setting_key NOT IN ($settings_list)";
    
    $stmt = $db->prepare($delete_query);
    $stmt->execute();
    
    $deleted_count = $stmt->rowCount();
    
    // Commit transaction
    $db->commit();
    
    $response['success'] = true;
    $response['messages'][] = "Successfully removed unused stock settings. Removed $deleted_count settings.";
    
    // Set session message
    $_SESSION['success_message'] = "Successfully removed unused stock settings. Removed $deleted_count settings.";
    
    // Redirect back to settings page
    header("Location: ../settings.php");
    exit();
    
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    $error_message = "Database error: " . $e->getMessage();
    error_log($error_message);
    
    $response['messages'][] = $error_message;
    
    // Set session message
    $_SESSION['error_message'] = "Error removing unused stock settings: " . $e->getMessage();
    
    // Redirect back to settings page
    header("Location: ../settings.php");
    exit();
}
