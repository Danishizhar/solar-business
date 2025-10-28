<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'draw' => isset($_POST['draw']) ? intval($_POST['draw']) : 0,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => []
    ]);
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['purchase_view'])) {
    echo json_encode([
        'draw' => isset($_POST['draw']) ? intval($_POST['draw']) : 0,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => []
    ]);
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// DataTables parameters
$draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
$start = isset($_POST['start']) ? intval($_POST['start']) : 0;
$length = isset($_POST['length']) ? intval($_POST['length']) : 10;
$search = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';

// Order column
$order_column = 0; // Default to first column
$order_dir = 'desc'; // Default to descending

if (isset($_POST['order']) && isset($_POST['order'][0]['column'])) {
    $order_column = intval($_POST['order'][0]['column']);
    $order_dir = $_POST['order'][0]['dir'] === 'asc' ? 'asc' : 'desc';
}

// Map DataTable column index to database column name
$columns = [
    0 => 'pr.return_number',
    1 => 'pr.return_date',
    2 => 'pi.invoice_number',
    3 => 's.supplier_name',
    4 => 'pr.total_amount',
    5 => 'pr.status'
];

// Build the query
$query = "SELECT pr.id, pr.return_number, pr.return_date, pr.total_amount, pr.status,
          pi.invoice_number, s.supplier_name, s.supplier_code, pi.id as invoice_id
          FROM purchase_returns pr
          JOIN purchase_invoices pi ON pr.invoice_id = pi.id
          JOIN suppliers s ON pr.supplier_id = s.id";

// Add search condition if search value is provided
if (!empty($search)) {
    $query .= " WHERE (pr.return_number LIKE :search OR pi.invoice_number LIKE :search OR s.supplier_name LIKE :search)";
}

// Add order by clause
$query .= " ORDER BY " . $columns[$order_column] . " " . $order_dir;

// Count total records without filtering
$count_query = "SELECT COUNT(*) as count FROM purchase_returns";
$count_stmt = $conn->prepare($count_query);
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Count filtered records
$filtered_count_query = "SELECT COUNT(*) as count FROM purchase_returns pr
                        JOIN purchase_invoices pi ON pr.invoice_id = pi.id
                        JOIN suppliers s ON pr.supplier_id = s.id";

if (!empty($search)) {
    $filtered_count_query .= " WHERE (pr.return_number LIKE :search OR pi.invoice_number LIKE :search OR s.supplier_name LIKE :search)";
}

$filtered_count_stmt = $conn->prepare($filtered_count_query);

if (!empty($search)) {
    $search_param = '%' . $search . '%';
    $filtered_count_stmt->bindParam(':search', $search_param, PDO::PARAM_STR);
}

$filtered_count_stmt->execute();
$filtered_records = $filtered_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Add limit clause for pagination
$query .= " LIMIT :start, :length";

// Prepare and execute the main query
$stmt = $conn->prepare($query);

if (!empty($search)) {
    $search_param = '%' . $search . '%';
    $stmt->bindParam(':search', $search_param, PDO::PARAM_STR);
}

$stmt->bindParam(':start', $start, PDO::PARAM_INT);
$stmt->bindParam(':length', $length, PDO::PARAM_INT);
$stmt->execute();

$data = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $data[] = [
        'id' => $row['id'],
        'return_number' => $row['return_number'],
        'return_date' => date('d M Y', strtotime($row['return_date'])),
        'invoice_number' => $row['invoice_number'],
        'invoice_id' => $row['invoice_id'],
        'supplier_name' => $row['supplier_name'],
        'supplier_code' => $row['supplier_code'],
        'total_amount' => $row['total_amount'],
        'status' => $row['status']
    ];
}

// Prepare the response
$response = [
    'draw' => $draw,
    'recordsTotal' => $total_records,
    'recordsFiltered' => $filtered_records,
    'data' => $data
];

// Add error handling for debugging
try {
    // Send the response as JSON
    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Exception $e) {
    // Log error
    error_log('DataTables JSON error: ' . $e->getMessage());
    
    // Send a valid but empty response
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'An error occurred while processing the request.'
    ]);
}
?>
