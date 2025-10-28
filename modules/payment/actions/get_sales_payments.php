<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['payment_view'])) {
    header("Location: ../../../index.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// DataTables server-side processing variables
$draw = isset($_POST['draw']) ? intval($_POST['draw']) : 1;
$start = isset($_POST['start']) ? intval($_POST['start']) : 0;
$length = isset($_POST['length']) ? intval($_POST['length']) : 10;
$search = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';

// Order column
$order_column = 0; // Default to first column
$order_dir = 'DESC'; // Default to descending

if (isset($_POST['order']) && is_array($_POST['order'])) {
    $order_column = intval($_POST['order'][0]['column']);
    $order_dir = $_POST['order'][0]['dir'] === 'asc' ? 'ASC' : 'DESC';
}

// Columns array for ordering
$columns = [
    'sp.payment_date',
    'sp.reference_number',
    'si.invoice_number',
    'c.customer_name',
    'sp.amount',
    'sp.payment_method',
    'sp.status'
];

// Base query
$query = "SELECT sp.*, si.invoice_number, c.customer_name, c.customer_code
          FROM sales_payments sp
          LEFT JOIN sales_invoices si ON sp.invoice_id = si.id
          LEFT JOIN customers c ON si.customer_id = c.id";

// Search condition
$search_condition = "";
if (!empty($search)) {
    $search_condition = " WHERE (si.invoice_number LIKE :search 
                         OR c.customer_name LIKE :search 
                         OR sp.reference_number LIKE :search 
                         OR sp.payment_method LIKE :search 
                         OR sp.status LIKE :search)";
}

// Count total records
$count_query = "SELECT COUNT(*) as total FROM sales_payments sp
                LEFT JOIN sales_invoices si ON sp.invoice_id = si.id
                LEFT JOIN customers c ON si.customer_id = c.id" . $search_condition;

$count_stmt = $conn->prepare($count_query);

if (!empty($search)) {
    $search_param = '%' . $search . '%';
    $count_stmt->bindParam(':search', $search_param);
}

$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Order by
$order_by = " ORDER BY " . $columns[$order_column] . " " . $order_dir;

// Limit
$limit = " LIMIT :start, :length";

// Final query
$query .= $search_condition . $order_by . $limit;

$stmt = $conn->prepare($query);

// Bind search parameter if search is not empty
if (!empty($search)) {
    $search_param = '%' . $search . '%';
    $stmt->bindParam(':search', $search_param);
}

// Bind limit parameters
$stmt->bindParam(':start', $start, PDO::PARAM_INT);
$stmt->bindParam(':length', $length, PDO::PARAM_INT);

// Execute query
$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare response
$response = [
    "draw" => $draw,
    "recordsTotal" => $total_records,
    "recordsFiltered" => $total_records,
    "data" => $payments
];

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
