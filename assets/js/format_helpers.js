/**
 * Format Helpers
 * JavaScript utility functions for consistent formatting across the application
 * These functions use system settings from PHP via inline script variables
 */

/**
 * Format a number according to system settings
 * 
 * @param {number} number - The number to format
 * @param {number} decimalPlaces - Override system decimal places (optional)
 * @returns {string} - Formatted number string
 */
function formatNumber(number, customDecimalPlaces) {
    // Use provided decimal places or fall back to system setting
    const places = (customDecimalPlaces !== undefined) ? customDecimalPlaces : window.systemDecimalPlaces;
    
    // Format the number according to system settings
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: places,
        maximumFractionDigits: places,
        useGrouping: true
    }).format(number)
        .replace(',', window.systemThousandSeparator)
        .replace('.', window.systemDecimalSeparator);
}

/**
 * Format a currency amount according to system settings
 * 
 * @param {number} amount - The amount to format
 * @param {boolean} includeSymbol - Whether to include the currency symbol (default: true)
 * @returns {string} - Formatted currency string
 */
function formatCurrency(amount, includeSymbol = true) {
    // Format the number first
    const formattedAmount = formatNumber(amount);
    
    // Add currency symbol if requested
    if (includeSymbol) {
        return window.systemCurrencySymbol + ' ' + formattedAmount;
    }
    
    return formattedAmount;
}

/**
 * Format a date according to system settings
 * 
 * @param {string|Date} date - The date to format
 * @returns {string} - Formatted date string
 */
function formatDate(date) {
    if (!date) return '';
    
    // Convert to Date object if string
    const dateObj = (date instanceof Date) ? date : new Date(date);
    
    // Return empty string if invalid date
    if (isNaN(dateObj.getTime())) return '';
    
    // Format based on system date format
    // This is a simplified version - for production, use a library like moment.js
    // that can handle custom date formats more robustly
    
    const year = dateObj.getFullYear();
    const month = String(dateObj.getMonth() + 1).padStart(2, '0');
    const day = String(dateObj.getDate()).padStart(2, '0');
    
    // Basic implementation - assumes Y-m-d format
    // In a real implementation, parse the PHP date format and convert accordingly
    return window.systemDateFormat
        .replace('Y', year)
        .replace('m', month)
        .replace('d', day);
}

/**
 * Format a percentage value
 * 
 * @param {number} value - The percentage value
 * @param {number} decimalPlaces - Number of decimal places (default: 2)
 * @returns {string} - Formatted percentage string
 */
function formatPercentage(value, decimalPlaces = 2) {
    return formatNumber(value, decimalPlaces) + '%';
}
