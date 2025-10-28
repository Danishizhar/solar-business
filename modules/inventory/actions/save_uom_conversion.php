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
    if (empty($_POST['from_uom_id']) || empty($_POST['to_uom_id']) || !isset($_POST['conversion_factor'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit();
    }
    
    $from_uom_id = (int)$_POST['from_uom_id'];
    $to_uom_id = (int)$_POST['to_uom_id'];
    $conversion_factor = (float)$_POST['conversion_factor'];
    $user_id = $_SESSION['user_id'];
    
    // Validate conversion factor
    if ($conversion_factor <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Conversion factor must be greater than zero']);
        exit();
    }
    
    // Validate that from and to UOMs are different
    if ($from_uom_id === $to_uom_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Cannot create conversion between the same UOM']);
        exit();
    }
    
    try {
        $db->beginTransaction();
        
        // Check if both UOMs exist
        $check_query = "SELECT id, uom_name FROM uom WHERE (id = ? OR id = ?) AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$from_uom_id, $to_uom_id]);
        
        if ($check_stmt->rowCount() < 2) {
            throw new Exception("One or both UOMs not found");
        }
        
        // Check if conversion already exists
        $check_conv_query = "SELECT id FROM uom_conversion WHERE from_uom_id = ? AND to_uom_id = ?";
        $check_conv_stmt = $db->prepare($check_conv_query);
        $check_conv_stmt->execute([$from_uom_id, $to_uom_id]);
        
        if ($check_conv_stmt->rowCount() > 0) {
            // Update existing conversion
            $update_query = "UPDATE uom_conversion SET 
                           conversion_factor = ?,
                           updated_by = ?,
                           updated_at = NOW()
                           WHERE from_uom_id = ? AND to_uom_id = ?";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([$conversion_factor, $user_id, $from_uom_id, $to_uom_id]);
            $message = "Conversion updated successfully";
        } else {
            // Insert new conversion
            $insert_query = "INSERT INTO uom_conversion (
                           from_uom_id, to_uom_id, conversion_factor, status,
                           created_by, updated_by, created_at, updated_at
                           ) VALUES (
                           ?, ?, ?, 1,
                           ?, ?, NOW(), NOW()
                           )";
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([$from_uom_id, $to_uom_id, $conversion_factor, $user_id, $user_id]);
            $message = "Conversion added successfully";
        }
        
        // Add reverse conversion automatically
        $reverse_factor = 1 / $conversion_factor;
        
        $check_reverse_query = "SELECT id FROM uom_conversion WHERE from_uom_id = :from_id AND to_uom_id = :to_id";
        $check_reverse_stmt = $db->prepare($check_reverse_query);
        $check_reverse_stmt->bindParam(':from_id', $to_uom_id, PDO::PARAM_INT);
        $check_reverse_stmt->bindParam(':to_id', $from_uom_id, PDO::PARAM_INT);
        $check_reverse_stmt->execute();
        
        if ($check_reverse_stmt->rowCount() > 0) {
            // Update existing reverse conversion
            $update_reverse_query = "UPDATE uom_conversion SET 
                                   conversion_factor = :factor,
                                   updated_by = :updated_by,
                                   updated_at = NOW()
                                   WHERE from_uom_id = :from_id AND to_uom_id = :to_id";
            
            $update_reverse_stmt = $db->prepare($update_reverse_query);
            $update_reverse_stmt->bindParam(':factor', $reverse_factor, PDO::PARAM_STR);
            $update_reverse_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
            $update_reverse_stmt->bindParam(':from_id', $to_uom_id, PDO::PARAM_INT);
            $update_reverse_stmt->bindParam(':to_id', $from_uom_id, PDO::PARAM_INT);
            
            $update_reverse_stmt->execute();
        } else {
            // Insert new reverse conversion
            $insert_reverse_query = "INSERT INTO uom_conversion (
                                   from_uom_id, to_uom_id, conversion_factor, status,
                                   created_by, updated_by, created_at, updated_at
                                   ) VALUES (
                                   :from_id, :to_id, :factor, 1,
                                   :created_by, :updated_by, NOW(), NOW()
                                   )";
            
            $insert_reverse_stmt = $db->prepare($insert_reverse_query);
            $insert_reverse_stmt->bindParam(':from_id', $to_uom_id, PDO::PARAM_INT);
            $insert_reverse_stmt->bindParam(':to_id', $from_uom_id, PDO::PARAM_INT);
            $insert_reverse_stmt->bindParam(':factor', $reverse_factor, PDO::PARAM_STR);
            $insert_reverse_stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
            $insert_reverse_stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
            
            $insert_reverse_stmt->execute();
        }
        
        $db->commit();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $message]);
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in save_uom_conversion.php: " . $e->getMessage());
        
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
        
        error_log("Error in save_uom_conversion.php: " . $e->getMessage());
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    // If not a POST request, return error
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
