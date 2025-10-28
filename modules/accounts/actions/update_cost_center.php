<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_edit'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $conn->beginTransaction();

        // Validate required fields
        if (empty($_POST['id']) || empty($_POST['code']) || empty($_POST['name'])) {
            throw new Exception("Please fill all required fields");
        }

        // Parse values
        $id = $_POST['id'];
        $code = $_POST['code'];
        $name = $_POST['name'];
        $parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
        $description = $_POST['description'] ?? null;
        $status = isset($_POST['status']) ? 1 : 0;

        // Check if cost center exists
        $check_query = "
            SELECT * 
            FROM cost_centers 
            WHERE id = :id 
            AND status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $check_stmt->execute();
        
        $cost_center = $check_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cost_center) {
            throw new Exception("Cost center not found");
        }

        // Check if code already exists for another cost center
        $code_query = "
            SELECT id 
            FROM cost_centers 
            WHERE code = :code 
            AND id != :id 
            AND status = 1
        ";
        $code_stmt = $conn->prepare($code_query);
        $code_stmt->bindParam(':code', $code, PDO::PARAM_STR);
        $code_stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $code_stmt->execute();
        
        if ($code_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Cost center code already exists");
        }

        // Check for circular reference in parent hierarchy
        if ($parent_id) {
            if ($parent_id == $id) {
                throw new Exception("A cost center cannot be its own parent");
            }

            // Check if parent exists and is active
            $parent_query = "
                SELECT id 
                FROM cost_centers 
                WHERE id = :parent_id 
                AND status = 1
            ";
            $parent_stmt = $conn->prepare($parent_query);
            $parent_stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
            $parent_stmt->execute();
            
            if (!$parent_stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("Selected parent cost center does not exist or is inactive");
            }

            // Check for circular reference
            $current_parent_id = $parent_id;
            while ($current_parent_id) {
                $circular_query = "
                    SELECT parent_id 
                    FROM cost_centers 
                    WHERE id = :current_id 
                    AND status = 1
                ";
                $circular_stmt = $conn->prepare($circular_query);
                $circular_stmt->bindParam(':current_id', $current_parent_id, PDO::PARAM_INT);
                $circular_stmt->execute();
                
                $parent_row = $circular_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$parent_row) {
                    break;
                }
                
                $current_parent_id = $parent_row['parent_id'];
                
                if ($current_parent_id == $id) {
                    throw new Exception("Circular reference detected in parent hierarchy");
                }
            }
        }

        // Update cost center
        $update_query = "
            UPDATE cost_centers 
            SET
                code = :code,
                name = :name,
                description = :description,
                parent_id = :parent_id,
                status = :status,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :id
            AND status = 1
        ";

        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':code', $code, PDO::PARAM_STR);
        $update_stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $update_stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $update_stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
        $update_stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $update_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_stmt->bindParam(':id', $id, PDO::PARAM_INT);

        if (!$update_stmt->execute()) {
            throw new Exception("Error updating cost center");
        }

        if ($update_stmt->rowCount() === 0) {
            throw new Exception("Cost center not found or already deleted");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Cost center updated successfully',
            'data' => [
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'parent_id' => $parent_id,
                'status' => $status
            ]
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
