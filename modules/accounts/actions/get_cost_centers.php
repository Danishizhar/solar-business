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
    // Get all cost centers
    $cost_centers_query = "
        SELECT 
            cc.*,
            p.name as parent_name,
            c.username as created_by_name,
            u.username as updated_by_name
        FROM cost_centers cc
        LEFT JOIN cost_centers p ON cc.parent_id = p.id
        LEFT JOIN users c ON cc.created_by = c.id
        LEFT JOIN users u ON cc.updated_by = u.id
        WHERE cc.status = 1
        ORDER BY cc.code";

    $stmt = $conn->prepare($cost_centers_query);
    $stmt->execute();
    $cost_centers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format data for DataTables
    $data = [];
    foreach ($cost_centers as $center) {
        $status_class = $center['status'] ? 'success' : 'danger';
        $status_text = $center['status'] ? 'Active' : 'Inactive';
        
        $actions = '<div class="btn-group" role="group">';
        $actions .= '<button type="button" class="btn btn-sm btn-info view-cost-center" ' .
                  'data-id="' . $center['id'] . '" ' .
                  'data-bs-toggle="tooltip" ' .
                  'title="View Details">' .
                  '<i class="fas fa-eye"></i></button>';
        
        if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_edit'])) {
            $actions .= '<button type="button" class="btn btn-sm btn-warning edit-cost-center" ' .
                      'data-id="' . $center['id'] . '" ' .
                      'data-bs-toggle="tooltip" ' .
                      'title="Edit">' .
                      '<i class="fas fa-edit"></i></button>';
        }
        
        if ($_SESSION['role_id'] == 1 || isset($_SESSION['permissions']['accounts_delete'])) {
            $actions .= '<button type="button" class="btn btn-sm btn-danger delete-cost-center" ' .
                      'data-id="' . $center['id'] . '" ' .
                      'data-name="' . htmlspecialchars($center['name']) . '" ' .
                      'data-bs-toggle="tooltip" ' .
                      'title="Delete">' .
                      '<i class="fas fa-trash"></i></button>';
        }
        
        $actions .= '</div>';
        
        $data[] = [
            'code' => htmlspecialchars($center['code']),
            'name' => htmlspecialchars($center['name']),
            'parent' => htmlspecialchars($center['parent_name'] ?? 'None'),
            'description' => htmlspecialchars($center['description'] ?? ''),
            'status' => '<span class="badge bg-' . $status_class . '">' . $status_text . '</span>',
            'actions' => $actions
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
    
} catch (PDOException $e) {
    error_log("PDOException in get_cost_centers.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred. Please try again later.'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>
