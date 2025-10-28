<?php
// Script to list all settings in the inventory module
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    $stmt = $db->prepare('SELECT setting_group, setting_key, setting_value, input_type FROM inventory_settings ORDER BY setting_group, setting_key');
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h1>Inventory Settings</h1>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Group</th><th>Key</th><th>Value</th><th>Input Type</th></tr>";
    
    $current_group = '';
    foreach ($results as $row) {
        if ($row['setting_group'] != $current_group) {
            echo "<tr style='background-color: #f0f0f0'><td colspan='4'><strong>" . htmlspecialchars($row['setting_group']) . "</strong></td></tr>";
            $current_group = $row['setting_group'];
        }
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['setting_group']) . "</td>";
        echo "<td>" . htmlspecialchars($row['setting_key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['setting_value']) . "</td>";
        echo "<td>" . htmlspecialchars($row['input_type']) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
