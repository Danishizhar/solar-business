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

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $year_id = $_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['id'] ?? null) : ($_POST['id'] ?? null);
        
        if (empty($year_id)) {
            throw new Exception("Financial year ID is required");
        }

        // Get financial year details
        $query = "
            SELECT 
                fy.*,
                c.username as created_by_name,
                u.username as updated_by_name,
                cl.username as closed_by_name
            FROM financial_years fy
            LEFT JOIN users c ON fy.created_by = c.id
            LEFT JOIN users u ON fy.updated_by = u.id
            LEFT JOIN users cl ON fy.closed_by = cl.id
            WHERE fy.id = :year_id
        ";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':year_id', $year_id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Financial year not found");
        }

        $financial_year = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'data' => $financial_year
        ]);

    } catch (PDOException $e) {
        error_log("Database error in get_financial_year.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
