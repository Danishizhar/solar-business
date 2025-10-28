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
        $required_fields = ['code', 'name', 'type'];
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

        // Check if category code already exists
        $check_query = "
            SELECT id 
            FROM account_categories 
            WHERE code = :code 
            AND status = 1
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':code', $_POST['code'], PDO::PARAM_STR);
        $check_stmt->execute();
        
        if ($check_stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Category code already exists");
        }

        // If parent_id is provided, verify it exists
        if (!empty($_POST['parent_id'])) {
            $parent_query = "
                SELECT id, type, name 
                FROM account_categories 
                WHERE id = :parent_id 
                AND status = 1
            ";
            $parent_stmt = $conn->prepare($parent_query);
            $parent_stmt->bindParam(':parent_id', $_POST['parent_id'], PDO::PARAM_INT);
            $parent_stmt->execute();
            
            $parent = $parent_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                throw new Exception("Parent category not found");
            }
            
            // Verify parent category type matches
            if ($parent['type'] !== $_POST['type']) {
                throw new Exception("Parent category type must match with the new category type. The parent category '{$parent['name']}' is of type '{$parent['type']}', but you're trying to create a category of type '{$_POST['type']}'.");
            }
        }

        // Insert into account_categories
        $insert_query = "
            INSERT INTO account_categories (
                code,
                name,
                type,
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
                :type,
                :description,
                :parent_id,
                1,
                :user_id,
                NOW(),
                :user_id2,
                NOW()
            )
        ";

        $stmt = $conn->prepare($insert_query);
        
        // Properly handle NULL values
        $description = !empty($_POST['description']) ? $_POST['description'] : null;
        $parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
        
        $stmt->bindParam(':code', $_POST['code'], PDO::PARAM_STR);
        $stmt->bindParam(':name', $_POST['name'], PDO::PARAM_STR);
        $stmt->bindParam(':type', $_POST['type'], PDO::PARAM_STR);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':parent_id', $parent_id, $parent_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':user_id2', $_SESSION['user_id'], PDO::PARAM_INT);

        if (!$stmt->execute()) {
            throw new Exception("Error saving category");
        }

        $category_id = $conn->lastInsertId();

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Category created successfully',
            'data' => [
                'id' => $category_id,
                'code' => $_POST['code'],
                'name' => $_POST['name'],
                'type' => $_POST['type']
            ]
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        error_log("PDOException in save_category.php: " . $e->getMessage());
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
