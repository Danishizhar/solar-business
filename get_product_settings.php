<?php
require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

// Query to find all product-related settings
$query = "SELECT id, setting_key, setting_value, setting_group, display_name, description 
          FROM inventory_settings 
          WHERE setting_group = 'products' 
          ORDER BY display_order, id";
$stmt = $db->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Product Settings</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Key</th><th>Value</th><th>Group</th><th>Display Name</th><th>Description</th></tr>";

foreach ($results as $row) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['setting_key']}</td>";
    echo "<td>{$row['setting_value']}</td>";
    echo "<td>{$row['setting_group']}</td>";
    echo "<td>{$row['display_name']}</td>";
    echo "<td>{$row['description']}</td>";
    echo "</tr>";
}

echo "</table>";
?>
