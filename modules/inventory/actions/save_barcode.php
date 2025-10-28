<?php
/**
 * Save Barcode
 * Updates the barcode for a product
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check if required parameters are provided
if (!isset($_POST['product_id']) || !isset($_POST['barcode'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$product_id = $_POST['product_id'];
$barcode = $_POST['barcode'];
$user_id = $_SESSION['user_id'];

// Get barcode format from POST if available
$format = isset($_POST['format']) ? $_POST['format'] : 'EAN13';

// Validate barcode format based on type
switch ($format) {
    case 'EAN13':
        if (!preg_match('/^\d{13}$/', $barcode)) {
            echo json_encode(['success' => false, 'message' => 'Invalid EAN-13 barcode format. Must be exactly 13 digits.']);
            exit;
        }
        break;
    case 'EAN8':
        if (!preg_match('/^\d{8}$/', $barcode)) {
            echo json_encode(['success' => false, 'message' => 'Invalid EAN-8 barcode format. Must be exactly 8 digits.']);
            exit;
        }
        break;
    case 'UPC':
        if (!preg_match('/^\d{12}$/', $barcode)) {
            echo json_encode(['success' => false, 'message' => 'Invalid UPC barcode format. Must be exactly 12 digits.']);
            exit;
        }
        break;
    case 'CODE128':
        // CODE128 can contain any ASCII character
        if (strlen($barcode) < 1 || strlen($barcode) > 50) {
            echo json_encode(['success' => false, 'message' => 'Invalid CODE128 barcode format. Length must be between 1 and 50 characters.']);
            exit;
        }
        break;
    case 'CODE39':
        // CODE39 can contain: 0-9, A-Z, -, ., $, /, +, %, and space
        if (!preg_match('/^[0-9A-Z\-\.\$\/\+\% ]{1,50}$/', $barcode)) {
            echo json_encode(['success' => false, 'message' => 'Invalid CODE39 barcode format. Must contain only 0-9, A-Z, -, ., $, /, +, %, and space.']);
            exit;
        }
        break;
    default:
        // For any other format, just ensure it's not empty and not too long
        if (empty($barcode) || strlen($barcode) > 50) {
            echo json_encode(['success' => false, 'message' => 'Invalid barcode format. Barcode cannot be empty or longer than 50 characters.']);
            exit;
        }
}

// Check if barcode already exists for another product
try {
    $check_query = "SELECT id FROM products WHERE barcode = :barcode AND id != :product_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':barcode', $barcode, PDO::PARAM_STR);
    $check_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Barcode already exists for another product']);
        exit();
    }
} catch (PDOException $e) {
    error_log('Error checking barcode uniqueness: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}

// We won't add a barcode_format column for now since we're working with the existing schema
$has_format_column = false;

// Update the barcode
try {
    // Update just the barcode using the existing schema
    $update_query = "UPDATE products 
                     SET barcode = :barcode, 
                         updated_by = :user_id 
                     WHERE id = :product_id";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':barcode', $barcode, PDO::PARAM_STR);
    $update_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $update_stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    
    $update_stmt->execute();
    
    if ($update_stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Barcode saved successfully', 'format' => $format]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Product not found or no changes made']);
    }
} catch (PDOException $e) {
    error_log('Error updating barcode: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}
