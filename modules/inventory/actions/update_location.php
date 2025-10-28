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
    // Validate input
    if (empty($_POST['location_id']) || empty($_POST['location_name']) || empty($_POST['location_type'])) {
        $_SESSION['error_message'] = "Location ID, name, and type are required";
        header("Location: ../locations.php");
        exit();
    }
    
    $location_id = (int)$_POST['location_id'];
    $location_name = trim($_POST['location_name']);
    // Check if branch is required for locations
    $branch_required = isBranchRequiredForLocations($db);
    
    $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    
    // If branch is required but not provided
    if ($branch_required && $branch_id === null) {
        $_SESSION['error_message'] = "Branch is required for locations. Please select a branch.";
        header("Location: ../locations.php");
        exit();
    }
    // Check if hierarchical locations are allowed
    $hierarchical_allowed = areHierarchicalLocationsAllowed($db);
    $max_hierarchy_depth = getMaxHierarchyDepth($db);
    
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    
    // If hierarchical locations are not allowed but parent is provided
    if (!$hierarchical_allowed && $parent_id !== null) {
        $_SESSION['error_message'] = "Hierarchical locations are not allowed in the current settings.";
        header("Location: ../locations.php");
        exit();
    }
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
        
        // Check if location exists
        $check_query = "SELECT id, is_default FROM inventory_locations WHERE id = ? AND status = 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$location_id]);
        $location = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$location) {
            throw new Exception("Location does not exist");
        }
        
        // Check if parent location exists if provided
        if ($parent_id) {
            // Prevent setting a location as its own parent
            if ($parent_id == $location_id) {
                throw new Exception("A location cannot be its own parent");
            }
            
            $check_query = "SELECT id FROM inventory_locations WHERE id = ? AND status = 1";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$parent_id]);
            
            if ($check_stmt->rowCount() === 0) {
                throw new Exception("Parent location does not exist");
            }
            
            // Check hierarchy depth if maximum is set
            if ($max_hierarchy_depth > 0) {
                // Count the depth of the parent location
                $depth_query = "WITH RECURSIVE location_tree AS (
                    SELECT id, parent_id, 0 AS depth
                    FROM inventory_locations
                    WHERE id = ? AND status = 1
                    UNION ALL
                    SELECT l.id, l.parent_id, lt.depth + 1
                    FROM inventory_locations l
                    JOIN location_tree lt ON l.id = lt.parent_id
                    WHERE l.status = 1
                )
                SELECT MAX(depth) AS max_depth FROM location_tree";
                
                $depth_stmt = $db->prepare($depth_query);
                $depth_stmt->execute([$parent_id]);
                $depth_result = $depth_stmt->fetch(PDO::FETCH_ASSOC);
                
                $parent_depth = (int)($depth_result['max_depth'] ?? 0);
                
                // If adding this location would exceed the maximum depth
                if ($parent_depth + 1 >= $max_hierarchy_depth) {
                    throw new Exception("Maximum hierarchy depth of {$max_hierarchy_depth} would be exceeded. Current parent depth is {$parent_depth}.");
                }
            }
            
            // Check for circular references
            $current_parent = $parent_id;
            while ($current_parent) {
                $parent_query = "SELECT parent_id FROM inventory_locations WHERE id = ? AND status = 1";
                $parent_stmt = $db->prepare($parent_query);
                $parent_stmt->execute([$current_parent]);
                $parent_result = $parent_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$parent_result) {
                    break;
                }
                
                $current_parent = $parent_result['parent_id'];
                
                if ($current_parent == $location_id) {
                    throw new Exception("Circular reference detected. Cannot set this parent location.");
                }
            }
        }
        
        // If setting as default and not already default, unset any existing default
        if ($is_default && $location['is_default'] != 1) {
            $update_query = "UPDATE inventory_locations SET 
                           is_default = 0,
                           updated_by = ?,
                           updated_at = NOW()
                           WHERE is_default = 1";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([$user_id]);
        }
        
        // Update location
        $query = "UPDATE inventory_locations SET 
                 location_name = ?,
                 branch_id = ?,
                 parent_id = ?,
                 location_type = ?,
                 description = ?,
                 is_default = ?,
                 updated_by = ?,
                 updated_at = NOW()
                 WHERE id = ?";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            $location_name,
            $branch_id,
            $parent_id,
            $location_type,
            $description,
            $is_default,
            $user_id,
            $location_id
        ]);
        
        $db->commit();
        $_SESSION['success_message'] = "Location updated successfully";
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Database error in update_location.php: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in update_location.php: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: ../locations.php");
    exit();
} else {
    // If not a POST request, redirect to locations page
    header("Location: ../locations.php");
    exit();
}
