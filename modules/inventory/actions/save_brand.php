<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Include helper functions
require_once '../includes/brand_settings_helper.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    if (empty($_POST['brand_name'])) {
        $_SESSION['error_message'] = "Brand name is required";
        header("Location: ../brands.php");
        exit();
    }
    
    $brand_name = trim($_POST['brand_name']);
    $brand_code = !empty($_POST['brand_code']) ? trim($_POST['brand_code']) : null;
    $brand_type = !empty($_POST['brand_type']) ? trim($_POST['brand_type']) : null;
    $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
    $website = !empty($_POST['website']) ? trim($_POST['website']) : null;
    $user_id = $_SESSION['user_id'];
    
    // Get brand settings
    $auto_generate_code = isAutoGenerateBrandCodeEnabled($db);
    $brand_code_prefix = getBrandCodePrefix($db);
    $brand_types = getBrandTypes($db);
    $require_logo = isBrandLogoRequired($db);
    $max_logo_file_size = getMaxLogoFileSize($db) * 1024 * 1024; // Convert MB to bytes
    
    try {
        $db->beginTransaction();
        
        // Check if brand name already exists
        $check_query = "SELECT id FROM brands WHERE brand_name = :brand_name AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':brand_name', $brand_name, PDO::PARAM_STR);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            throw new Exception("Brand name already exists. Please use a different name.");
        }
        
        // Validate brand type
        if (empty($brand_type) || !in_array($brand_type, $brand_types)) {
            throw new Exception("Invalid brand type. Allowed types are: " . implode(', ', $brand_types));
        }
        
        // Generate brand code if auto-generation is enabled
        if (empty($brand_code)) {
            if (!$auto_generate_code) {
                throw new Exception("Brand code is required when auto-generation is disabled.");
            }
            
            // Create a code based on the brand name with prefix
            $name_part = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $brand_name), 0, 5));
            $brand_code = $brand_code_prefix . $name_part;
            
            // Check if code exists and append numbers if needed
            $check_query = "SELECT COUNT(*) as count FROM brands WHERE brand_code = :code";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':code', $brand_code, PDO::PARAM_STR);
            $check_stmt->execute();
            $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                $brand_code .= rand(10, 99);
            }
        }
        
        // Check if logo is required
        if ($require_logo && (!isset($_FILES['brand_logo']) || $_FILES['brand_logo']['error'] != 0 || empty($_FILES['brand_logo']['name']))) {
            throw new Exception("Brand logo is required.");
        }
        
        // Handle logo upload
        $logo_path = null;
        if (isset($_FILES['brand_logo']) && $_FILES['brand_logo']['error'] == 0 && !empty($_FILES['brand_logo']['name'])) {
            // Create absolute path for uploads
            $upload_dir = dirname(dirname(dirname(__DIR__))) . '/uploads/brands/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0777, true)) {
                    throw new Exception("Failed to create upload directory.");
                }
            }
            
            $file_name = time() . '_' . basename($_FILES['brand_logo']['name']);
            $target_file = $upload_dir . $file_name;
            
            // Check if image file is a valid image
            $check = getimagesize($_FILES['brand_logo']['tmp_name']);
            if ($check === false) {
                throw new Exception("File is not a valid image.");
            }
            
            // Check file size against setting
            if ($_FILES['brand_logo']['size'] > $max_logo_file_size) {
                throw new Exception("File is too large. Maximum size is {$max_logo_file_size} bytes.");
            }
            
            // Allow only certain file formats
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($_FILES['brand_logo']['type'], $allowed_types)) {
                throw new Exception("Only JPG, PNG, GIF, and WebP files are allowed.");
            }
            
            if (move_uploaded_file($_FILES['brand_logo']['tmp_name'], $target_file)) {
                $logo_path = 'uploads/brands/' . $file_name;
            } else {
                throw new Exception("Failed to upload image.");
            }
        }
        
        // Insert brand
        $query = "INSERT INTO brands (
                 brand_name, brand_code, brand_type, description, logo_path, website,
                 status, created_by, updated_by, created_at, updated_at
                 ) VALUES (
                 :brand_name, :brand_code, :brand_type, :description, :logo_path, :website,
                 1, :created_by, :updated_by, NOW(), NOW()
                 )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':brand_name', $brand_name, PDO::PARAM_STR);
        $stmt->bindParam(':brand_code', $brand_code, PDO::PARAM_STR);
        $stmt->bindParam(':brand_type', $brand_type, PDO::PARAM_STR);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':logo_path', $logo_path, PDO::PARAM_STR);
        $stmt->bindParam(':website', $website, PDO::PARAM_STR);
        $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Brand added successfully";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in save_brand.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Error in save_brand.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../brands.php");
    exit();
} else {
    // If not a POST request, redirect to brands page
    header("Location: ../brands.php");
    exit();
}
