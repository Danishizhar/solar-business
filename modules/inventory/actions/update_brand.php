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
    if (empty($_POST['brand_id']) || empty($_POST['brand_name'])) {
        $_SESSION['error_message'] = "Brand ID and name are required";
        header("Location: ../brands.php");
        exit();
    }
    
    $brand_id = (int)$_POST['brand_id'];
    $brand_name = trim($_POST['brand_name']);
    $brand_code = !empty($_POST['brand_code']) ? trim($_POST['brand_code']) : null;
    $brand_type = !empty($_POST['brand_type']) ? trim($_POST['brand_type']) : null;
    $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
    $website = !empty($_POST['website']) ? trim($_POST['website']) : null;
    $user_id = $_SESSION['user_id'];
    
    // Get brand settings
    $brand_types = getBrandTypes($db);
    $require_logo = isBrandLogoRequired($db);
    $max_logo_file_size = getMaxLogoFileSize($db) * 1024 * 1024; // Convert MB to bytes
    
    try {
        $db->beginTransaction();
        
        // Check if brand exists
        $check_query = "SELECT id, logo_path, brand_code FROM brands WHERE id = :id AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $brand_id, PDO::PARAM_INT);
        $check_stmt->execute();
        $brand = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$brand) {
            throw new Exception("Brand does not exist");
        }
        
        // Check if brand name already exists for another brand
        $name_query = "SELECT id FROM brands WHERE brand_name = :brand_name AND id != :id AND status = 1";
        $name_stmt = $db->prepare($name_query);
        $name_stmt->bindParam(':brand_name', $brand_name, PDO::PARAM_STR);
        $name_stmt->bindParam(':id', $brand_id, PDO::PARAM_INT);
        $name_stmt->execute();
        
        if ($name_stmt->rowCount() > 0) {
            throw new Exception("Brand name already exists. Please use a different name.");
        }
        
        // Validate brand type
        if (empty($brand_type) || !in_array($brand_type, $brand_types)) {
            throw new Exception("Invalid brand type. Allowed types are: " . implode(', ', $brand_types));
        }
        
        // Use existing brand code if not provided
        if (empty($brand_code)) {
            $brand_code = $brand['brand_code'];
        }
        
        // Check if logo is required
        if ($require_logo && empty($brand['logo_path']) && (!isset($_FILES['brand_logo']) || $_FILES['brand_logo']['error'] != 0 || empty($_FILES['brand_logo']['name']))) {
            throw new Exception("Brand logo is required.");
        }
        
        // Handle logo upload
        $logo_path = $brand['logo_path']; // Keep existing logo by default
        
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
                // Delete old logo if exists
                $old_logo_path = dirname(dirname(dirname(__DIR__))) . '/' . $brand['logo_path'];
                if (!empty($brand['logo_path']) && file_exists($old_logo_path)) {
                    unlink($old_logo_path);
                }
                
                $logo_path = 'uploads/brands/' . $file_name;
            } else {
                throw new Exception("Failed to upload image.");
            }
        }
        
        // Update brand
        $query = "UPDATE brands SET 
                 brand_name = :brand_name,
                 brand_code = :brand_code,
                 brand_type = :brand_type,
                 description = :description,
                 logo_path = :logo_path,
                 website = :website,
                 updated_by = :updated_by,
                 updated_at = NOW()
                 WHERE id = :brand_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':brand_name', $brand_name, PDO::PARAM_STR);
        $stmt->bindParam(':brand_code', $brand_code, PDO::PARAM_STR);
        $stmt->bindParam(':brand_type', $brand_type, PDO::PARAM_STR);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':logo_path', $logo_path, PDO::PARAM_STR);
        $stmt->bindParam(':website', $website, PDO::PARAM_STR);
        $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':brand_id', $brand_id, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success_message'] = "Brand updated successfully";
    } catch (PDOException $e) {
        // Only roll back if there's an active transaction
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $ex) {
            // Ignore any errors from rollback attempt
        }
        
        error_log("Database error in update_brand.php: " . $e->getMessage());
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
        
        error_log("Error in update_brand.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../brands.php");
    exit();
} else {
    // If not a POST request, redirect to brands page
    header("Location: ../brands.php");
    exit();
}
