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

try {
    // Get all financial years with proper PDO prepared statement
    $years_query = "
        SELECT 
            fy.*,
            c.username as created_by_name,
            u.username as updated_by_name,
            cl.username as closed_by_name
        FROM financial_years fy
        LEFT JOIN users c ON fy.created_by = c.id
        LEFT JOIN users u ON fy.updated_by = u.id
        LEFT JOIN users cl ON fy.closed_by = cl.id
        WHERE fy.status = 1
        ORDER BY fy.start_date DESC";

    $stmt = $conn->prepare($years_query);
    $stmt->execute();
    $financial_years = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add permission flags and format dates for each year
    foreach ($financial_years as &$year) {
        $year['can_view'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_view']);
        $year['can_edit'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_edit']);
        $year['can_delete'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_delete']);
        $year['can_close'] = $_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_approve']);
        
        // Format dates for display
        $year['start_date'] = date('Y-m-d', strtotime($year['start_date']));
        $year['end_date'] = date('Y-m-d', strtotime($year['end_date']));
        
        // Format status
        $year['status'] = $year['is_closed'] ? 'closed' : 'open';
    }

    // Return data in the format DataTables expects
    echo json_encode([
        'data' => $financial_years
    ]);

} catch (PDOException $e) {
    error_log("Error in get_financial_years.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while loading financial years. Please try again later.'
    ]);
}
