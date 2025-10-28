<?php
/**
 * Refresh Currency Settings
 * 
 * This script ensures that the currency settings are properly applied throughout the system.
 * It clears any cached data and updates the database to ensure consistency.
 */

// Include database connection
require_once 'config/database.php';
require_once 'modules/purchase/includes/purchase_helpers.php';

// Create database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Start transaction
    $db->beginTransaction();
    
    // Get company information for currency
    $company_query = "SELECT currency FROM company LIMIT 1";
    $company_stmt = $db->prepare($company_query);
    $company_stmt->execute();
    
    if ($company_stmt->rowCount() > 0) {
        $company = $company_stmt->fetch(PDO::FETCH_ASSOC);
        $currency = $company['currency'];
        
        echo "Company currency is set to: " . $currency . "<br>";
        
        // Update any existing purchase orders to use the company currency
        $update_po_query = "UPDATE purchase_orders SET currency = ? WHERE 1";
        $update_po_stmt = $db->prepare($update_po_query);
        $update_po_stmt->execute([$currency]);
        
        $affected = $update_po_stmt->rowCount();
        echo "Updated currency for " . $affected . " purchase orders<br>";
        
        // Force browser to reload JavaScript and CSS files
        echo "<script>
            // Add a timestamp to force reload of all cached resources
            function addTimestampToUrl(url) {
                return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'v=' + new Date().getTime();
            }
            
            // Force reload all scripts and stylesheets
            document.querySelectorAll('script').forEach(function(script) {
                if (script.src) {
                    var newScript = document.createElement('script');
                    newScript.src = addTimestampToUrl(script.src);
                    script.parentNode.replaceChild(newScript, script);
                }
            });
            
            document.querySelectorAll('link[rel=\"stylesheet\"]').forEach(function(link) {
                if (link.href) {
                    var newLink = document.createElement('link');
                    newLink.rel = 'stylesheet';
                    newLink.href = addTimestampToUrl(link.href);
                    link.parentNode.replaceChild(newLink, link);
                }
            });
        </script>";
        
        echo "<p>Currency settings have been refreshed. Please <a href='modules/purchase/purchase_orders.php'>click here</a> to go back to the purchase orders page.</p>";
    } else {
        echo "No company information found. Please set up your company details first.";
    }
    
    // Commit transaction
    $db->commit();
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo "Error: " . $e->getMessage();
}
?>
