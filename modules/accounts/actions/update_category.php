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
        $required_fields = ['id', 'code', 'name', 'type'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field {$field} is required");
            }
        }

        // Validate account type
        $valid_types = ['asset', 'liability', 'equity', 'revenue', 'expense', 'cogs'];
        if (!in_array($_POST['type'], $valid_types)) {
            throw new Exception("Invalid account type");
        }

        // Check if category exists
        $check_query = "
            SELECT id 
            FROM account_categories 
            WHERE id = :id 
            AND status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $check_stmt->execute();
        
        if (!$check_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Category not found");
        }

        // Check if category code already exists for other categories
        $code_query = "
            SELECT id 
            FROM account_categories 
            WHERE code = :code 
            AND id != :id 
            AND status = 1
        ";
        $code_stmt = $conn->prepare($code_query);
        $code_stmt->bindParam(':code', $_POST['code'], PDO::PARAM_STR);
        $code_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
        $code_stmt->execute();
        
        if ($code_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Category code already exists");
        }

        // Check for circular reference in parent relationship and validate parent type
        if (!empty($_POST['parent_id'])) {
            // First check parent type
            $parent_type_query = "
                SELECT id, type, name 
                FROM account_categories 
                WHERE id = :parent_id 
                AND status = 1
            ";
            $parent_type_stmt = $conn->prepare($parent_type_query);
            $parent_type_stmt->bindParam(':parent_id', $_POST['parent_id'], PDO::PARAM_INT);
            $parent_type_stmt->execute();
            
            $parent = $parent_type_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                throw new Exception("Parent category not found");
            }
            
            // Verify parent category type matches
            if ($parent['type'] !== $_POST['type']) {
                throw new Exception("Parent category type must match with the category type. The parent category '{$parent['name']}' is of type '{$parent['type']}', but you're trying to update this category to type '{$_POST['type']}'.");
            }
            
            // Then check for circular reference
            $current_id = $_POST['parent_id'];
            $visited = [$_POST['id']];
            
            while ($current_id) {
                if (in_array($current_id, $visited)) {
                    throw new Exception("Circular reference detected in parent relationship");
                }
                $visited[] = $current_id;
                
                $parent_query = "
                    SELECT parent_id 
                    FROM account_categories 
                    WHERE id = :current_id 
                    AND status = 1
                ";
                $parent_stmt = $conn->prepare($parent_query);
                $parent_stmt->bindParam(':current_id', $current_id, PDO::PARAM_INT);
                $parent_stmt->execute();
                
                $result = $parent_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$result) {
                    throw new Exception("Parent category not found");
                }
                $current_id = $result['parent_id'];
            }
        }

        // Update account_categories
        $update_query = "
            UPDATE account_categories 
            SET
                code = :code,
                name = :name,
                type = :type,
                description = :description,
                parent_id = :parent_id,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :id
            AND status = 1
        ";

        $update_stmt = $conn->prepare($update_query);
        
        $parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
        $description = !empty($_POST['description']) ? $_POST['description'] : null;
        
        $update_stmt->bindParam(':code', $_POST['code'], PDO::PARAM_STR);
        $update_stmt->bindParam(':name', $_POST['name'], PDO::PARAM_STR);
        $update_stmt->bindParam(':type', $_POST['type'], PDO::PARAM_STR);
        $update_stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $update_stmt->bindParam(':parent_id', $parent_id, $parent_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $update_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);

        if (!$update_stmt->execute()) {
            throw new Exception("Error updating category");
        }

        if ($update_stmt->rowCount() === 0) {
            throw new Exception("Category not found or already deleted");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Category updated successfully',
            'data' => [
                'id' => $_POST['id'],
                'code' => $_POST['code'],
                'name' => $_POST['name'],
                'type' => $_POST['type'],
                'parent_id' => $parent_id
            ]
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("PDOException in update_category.php: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again later.']);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
