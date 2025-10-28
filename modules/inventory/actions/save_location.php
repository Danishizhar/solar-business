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
require_once '../includes/inventory_helpers.php';
require_once '../includes/location_settings_helper.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if multi-locations is enabled
    $multi_locations_enabled = isMultiLocationsEnabled($db);
    
    // If multi-locations is disabled, check if we already have locations
    if (!$multi_locations_enabled) {
        $query = "SELECT COUNT(*) as count FROM inventory_locations WHERE status = 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If we already have a location and multi-locations is disabled, prevent adding more
        if ($result['count'] > 0) {
            $_SESSION['error_message'] = "Multi-locations is disabled in settings. You cannot add new locations until you enable multi-locations in Inventory Settings.";
            header("Location: ../locations.php");
            exit();
        }
    }
    
    // Validate input
    if (empty($_POST['location_name']) || empty($_POST['location_type'])) {
        $_SESSION['error_message'] = "Location name and type are required";
        header("Location: ../locations.php");
        exit();
    }
    
    $location_name = trim($_POST['location_name']);
    $location_code = !empty($_POST['location_code']) ? trim($_POST['location_code']) : null;
    
    // Debug branch_id
    $raw_branch_id = isset($_POST['branch_id']) ? $_POST['branch_id'] : 'not set';
    error_log("Raw branch_id from form: " . print_r($raw_branch_id, true));
    
    // Check if branch is required for locations
    $branch_required = isBranchRequiredForLocations($db);
    
    // Make sure branch_id is properly set
    $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    error_log("Processed branch_id: " . ($branch_id === null ? 'NULL' : $branch_id));
    
    // If branch is required but not provided
    if ($branch_required && $branch_id === null) {
        $_SESSION['error_message'] = "Branch is required for locations. Please select a branch.";
        header("Location: ../locations.php");
        exit();
    }
    
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $location_type = trim($_POST['location_type']);
    
    // Validate location type against allowed types from settings
    $allowed_types = getLocationTypes($db);
    if (!in_array($location_type, $allowed_types)) {
        $_SESSION['error_message'] = "Invalid location type. Allowed types are: " . implode(', ', $allowed_types);
        header("Location: ../locations.php");
        exit();
    }
    $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        
        // Check if auto-generate location code is enabled
        $auto_generate_code = isAutoGenerateLocationCodeEnabled($db);
        
        // Generate location code if not provided and auto-generate is enabled
        if (empty($location_code) && $auto_generate_code) {
            // Get location code prefix from settings
            $prefix = getLocationCodePrefix($db);
            
            // Create a code based on the prefix and location name
            $location_code = $prefix . '-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $location_name), 0, 5));
            
            // Check if code exists and append numbers if needed
            $check_query = "SELECT COUNT(*) as count FROM inventory_locations WHERE location_code = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$location_code]);
            $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                $location_code .= rand(10, 99);
            }
        } else if (empty($location_code) && !$auto_generate_code) {
            // If auto-generate is disabled and no code provided, throw an error
            throw new Exception("Location code is required. Auto-generate location code is disabled in settings.");
        }
        
        // Check if hierarchical locations are allowed
        $hierarchical_allowed = areHierarchicalLocationsAllowed($db);
        
        // If parent_id is provided but hierarchical locations are not allowed
        if ($parent_id && !$hierarchical_allowed) {
            throw new Exception("Hierarchical locations are disabled in settings. You cannot create a location with a parent.");
        }
        
        // Check if parent location exists if provided
        if ($parent_id) {
            $check_query = "SELECT id FROM inventory_locations WHERE id = ? AND status = 1";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$parent_id]);
            
            if ($check_stmt->rowCount() === 0) {
                throw new Exception("Parent location does not exist");
            }
            
            // Check hierarchy depth if max depth is set
            $max_depth = getMaxHierarchyDepth($db);
            if ($max_depth > 0) {
                // Count the depth of the parent
                $depth_query = "WITH RECURSIVE location_tree AS (
                                SELECT id, parent_id, 0 as depth
                                FROM inventory_locations
                                WHERE id = ?
                                UNION ALL
                                SELECT l.id, l.parent_id, lt.depth + 1
                                FROM inventory_locations l
                                JOIN location_tree lt ON l.id = lt.parent_id
                                )
                                SELECT MAX(depth) as max_depth FROM location_tree";
                $depth_stmt = $db->prepare($depth_query);
                $depth_stmt->execute([$parent_id]);
                $depth_result = $depth_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($depth_result['max_depth'] >= $max_depth) {
                    throw new Exception("Maximum location hierarchy depth of {$max_depth} has been reached. Cannot add more sub-locations.");
                }
            }
        }
        
        // If setting as default, unset any existing default
        if ($is_default) {
            $update_query = "UPDATE inventory_locations SET 
                           is_default = 0,
                           updated_by = ?,
                           updated_at = NOW()
                           WHERE is_default = 1";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([$user_id]);
        }
        
        // Insert new location
        $query = "INSERT INTO inventory_locations (
                 location_name, location_code, branch_id, parent_id, 
                 location_type, description, is_default,
                 status, created_by, updated_by, created_at, updated_at
                 ) VALUES (
                 ?, ?, ?, ?, 
                 ?, ?, ?,
                 1, ?, ?, NOW(), NOW()
                 )";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            $location_name,
            $location_code,
            $branch_id,
            $parent_id,
            $location_type,
            $description,
            $is_default,
            $user_id,
            $user_id
        ]);
        
        $db->commit();
        $_SESSION['success_message'] = "Location added successfully";
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Database error in save_location.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in save_location.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../locations.php");
    exit();
} else {
    // If not a POST request, redirect to locations page
    header("Location: ../locations.php");
    exit();
}
