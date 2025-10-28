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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Validate required fields
        if (empty($_GET['id'])) {
            throw new Exception("Cost center ID is required");
        }

        $cost_center_id = $_GET['id'];

        // Get cost center details with related information
        $query = "
            SELECT 
                cc.*,
                c.username as created_by_name,
                u.username as updated_by_name,
                p.name as parent_name
            FROM cost_centers cc
            LEFT JOIN users c ON cc.created_by = c.id
            LEFT JOIN users u ON cc.updated_by = u.id
            LEFT JOIN cost_centers p ON cc.parent_id = p.id
            WHERE cc.id = :id AND cc.status = 1";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $cost_center_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $cost_center = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cost_center) {
            echo json_encode(['success' => true, 'data' => $cost_center]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Cost center not found']);
        }

    } catch (PDOException $e) {
        error_log("Database error in get_cost_center.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
