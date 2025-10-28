<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    if (empty($_POST['conversion_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Conversion ID is required']);
        exit();
    }
    
    $conversion_id = (int)$_POST['conversion_id'];
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        
        // Get conversion details before deleting
        $get_query = "SELECT from_uom_id, to_uom_id FROM uom_conversion WHERE id = ?";
        $get_stmt = $db->prepare($get_query);
        $get_stmt->execute([$conversion_id]);
        $conversion = $get_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conversion) {
            throw new Exception("Conversion not found");
        }
        
        // Delete the conversion
        $delete_query = "DELETE FROM uom_conversion WHERE id = ?";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->execute([$conversion_id]);
        
        // Also delete the reverse conversion if it exists
        $delete_reverse_query = "DELETE FROM uom_conversion 
                               WHERE from_uom_id = ? AND to_uom_id = ?";
        $delete_reverse_stmt = $db->prepare($delete_reverse_query);
        $delete_reverse_stmt->execute([$conversion['to_uom_id'], $conversion['from_uom_id']]);
        
        $db->commit();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Conversion deleted successfully']);
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in delete_uom_conversion.php: " . $e->getMessage());
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Database error: " . $e->getMessage()]);
    } catch (Exception $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Error in delete_uom_conversion.php: " . $e->getMessage());
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    // If not a POST request, return error
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
