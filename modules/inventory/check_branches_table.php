<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

try {
    // Check if branches table exists
    $query = "SHOW TABLES LIKE 'branches'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $branchesTableExists = $stmt->rowCount() > 0;
    
    echo "<h2>Database Check Results</h2>";
    echo "<p>Branches table exists: " . ($branchesTableExists ? "Yes" : "No") . "</p>";
    
    if ($branchesTableExists) {
        // Check branches table structure
        $query = "DESCRIBE branches";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Branches Table Structure:</h3>";
        echo "<table border='1'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . $column['Field'] . "</td>";
            echo "<td>" . $column['Type'] . "</td>";
            echo "<td>" . $column['Null'] . "</td>";
            echo "<td>" . $column['Key'] . "</td>";
            echo "<td>" . $column['Default'] . "</td>";
            echo "<td>" . $column['Extra'] . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        // Check if there are any branches in the table
        $query = "SELECT id, branch_name FROM branches WHERE status = 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Available Branches:</h3>";
        if (count($branches) > 0) {
            echo "<ul>";
            foreach ($branches as $branch) {
                echo "<li>ID: " . $branch['id'] . " - Name: " . $branch['branch_name'] . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>No active branches found in the database.</p>";
        }
    }
    
    // Check inventory_locations table
    echo "<h3>Inventory Locations Table Structure:</h3>";
    $query = "DESCRIBE inventory_locations";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . $column['Default'] . "</td>";
        echo "<td>" . $column['Extra'] . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    // Check a sample location to see if branch_id is being saved
    $query = "SELECT id, location_name, branch_id FROM inventory_locations LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Sample Locations:</h3>";
    if (count($locations) > 0) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Location Name</th><th>Branch ID</th></tr>";
        
        foreach ($locations as $location) {
            echo "<tr>";
            echo "<td>" . $location['id'] . "</td>";
            echo "<td>" . $location['location_name'] . "</td>";
            echo "<td>" . ($location['branch_id'] ? $location['branch_id'] : 'NULL') . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    } else {
        echo "<p>No locations found in the database.</p>";
    }
    
} catch (PDOException $e) {
    echo "<div style='color: red;'>Database error: " . $e->getMessage() . "</div>";
}
?>
