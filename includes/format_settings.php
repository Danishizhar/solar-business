<?php
/**
 * Format Settings
 * Outputs JavaScript variables with system formatting settings
 * Include this file before including format_helpers.js
 */

// Make sure company_helpers.php is included
if (!function_exists('getSystemSetting')) {
    require_once __DIR__ . '/company_helpers.php';
}

// Get database connection
if (!isset($conn)) {
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
}

// Get system settings for formatting
$decimalPlaces = (int)getSystemSetting($conn, 'decimal_places', 2);
$thousandSeparator = getSystemSetting($conn, 'thousand_separator', ',');
$decimalSeparator = getSystemSetting($conn, 'decimal_separator', '.');
$dateFormat = getSystemSetting($conn, 'date_format', 'Y-m-d');
$timeFormat = getSystemSetting($conn, 'time_format', 'H:i:s');

// Get company information for currency
$company = getCompanyInfo($conn, 'currency');
$currencySymbol = $company['currency'] ?? getSystemSetting($conn, 'default_currency_symbol', 'Rs');

// Output JavaScript variables
?>
<script>
// System formatting settings
window.systemDecimalPlaces = <?php echo $decimalPlaces; ?>;
window.systemThousandSeparator = <?php echo json_encode($thousandSeparator); ?>;
window.systemDecimalSeparator = <?php echo json_encode($decimalSeparator); ?>;
window.systemDateFormat = <?php echo json_encode($dateFormat); ?>;
window.systemTimeFormat = <?php echo json_encode($timeFormat); ?>;
window.systemCurrencySymbol = <?php echo json_encode($currencySymbol); ?>;
</script>
