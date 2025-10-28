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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_add'])) {
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
        if (empty($_POST['code']) || empty($_POST['name'])) {
            throw new Exception("Please fill all required fields");
        }

        // Parse values
        $code = $_POST['code'];
        $name = $_POST['name'];
        $parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
        $description = $_POST['description'] ?? null;
        $status = isset($_POST['status']) ? 1 : 0;

        // Check if code already exists
        $check_query = "
            SELECT id 
            FROM cost_centers 
            WHERE code = :code 
            AND status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':code', $code, PDO::PARAM_STR);
        $check_stmt->execute();
        
        if ($check_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Cost center code already exists");
        }

        // Check if parent exists if provided
        if ($parent_id) {
            $parent_query = "
                SELECT id, code, name 
                FROM cost_centers 
                WHERE id = :parent_id 
                AND status = 1
            ";
            $parent_stmt = $conn->prepare($parent_query);
            $parent_stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
            $parent_stmt->execute();
            
            $parent = $parent_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                throw new Exception("Selected parent cost center does not exist");
            }
        }

        // Insert cost center
        $insert_query = "
            INSERT INTO cost_centers (
                code, 
                name, 
                description, 
                parent_id, 
                status, 
                created_by, 
                created_at,
                updated_by, 
                updated_at
            ) VALUES (
                :code,
                :name,
                :description,
                :parent_id,
                :status,
                :user_id,
                NOW(),
                :user_id2,
                NOW()
            )
        ";

        $stmt = $conn->prepare($insert_query);
        
        $stmt->bindParam(':code', $code, PDO::PARAM_STR);
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':user_id2', $_SESSION['user_id'], PDO::PARAM_INT);

        if (!$stmt->execute()) {
            throw new Exception("Error creating cost center");
        }

        $cost_center_id = $conn->lastInsertId();

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Cost center created successfully',
            'data' => [
                'id' => $cost_center_id,
                'code' => $code,
                'name' => $name,
                'parent_id' => $parent_id,
                'parent_name' => $parent['name'] ?? null
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
