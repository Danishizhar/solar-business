/**
 * Barcode Templates
 * Defines different barcode label templates and sizes
 */

// Template size definitions (in mm)
const TEMPLATE_SIZES = {
    'small': { width: 38, height: 25, columns: 2, rows: 8 },
    'medium': { width: 50, height: 30, columns: 2, rows: 5 },
    'large': { width: 100, height: 50, columns: 1, rows: 4 },
    'a4-30': { width: 70, height: 29.7, columns: 3, rows: 10 }, // 3x10 labels on A4
    'a4-24': { width: 70, height: 37.1, columns: 3, rows: 8 },  // 3x8 labels on A4
    'a4-16': { width: 99, height: 34, columns: 2, rows: 8 }     // 2x8 labels on A4
};

// Template style definitions
const TEMPLATE_STYLES = {
    // Basic template with product name and barcode
    'basic': function(product, format) {
        return `
            <div class="barcode-label basic-template">
                <div class="product-name">${product.name}</div>
                <div class="product-code">${product.code}</div>
                <div class="barcode-container">
                    <svg class="barcode"></svg>
                </div>
            </div>
        `;
    },
    
    // Compact template with smaller text
    'compact': function(product, format) {
        return `
            <div class="barcode-label compact-template">
                <div class="product-name">${product.name}</div>
                <div class="barcode-container">
                    <svg class="barcode"></svg>
                </div>
            </div>
        `;
    },
    
    // Detailed template with price
    'detailed': function(product, format) {
        return `
            <div class="barcode-label detailed-template">
                <div class="product-name">${product.name}</div>
                <div class="product-details">
                    <span class="product-code">${product.code}</span>
                    <span class="product-price">${formatPrice(product.price)}</span>
                </div>
                <div class="barcode-container">
                    <svg class="barcode"></svg>
                </div>
                <div class="product-category">${product.category}</div>
            </div>
        `;
    },
    
    // Minimal template with barcode only
    'minimal': function(product, format) {
        return `
            <div class="barcode-label minimal-template">
                <div class="barcode-container">
                    <svg class="barcode"></svg>
                </div>
            </div>
        `;
    }
};

// CSS styles for different templates
const TEMPLATE_CSS = {
    'small': `
        .barcode-label {
            width: 38mm;
            height: 25mm;
            padding: 2mm;
            box-sizing: border-box;
            overflow: hidden;
            font-family: Arial, sans-serif;
        }
        .product-name {
            font-size: 7pt;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 1mm;
        }
        .product-code {
            font-size: 6pt;
            margin-bottom: 1mm;
        }
        .barcode-container {
            height: 10mm;
            text-align: center;
        }
        .barcode-container svg {
            height: 100%;
            max-width: 100%;
        }
    `,
    'medium': `
        .barcode-label {
            width: 50mm;
            height: 30mm;
            padding: 2mm;
            box-sizing: border-box;
            overflow: hidden;
            font-family: Arial, sans-serif;
        }
        .product-name {
            font-size: 8pt;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 1mm;
        }
        .product-code {
            font-size: 7pt;
            margin-bottom: 1mm;
        }
        .product-details {
            display: flex;
            justify-content: space-between;
            font-size: 7pt;
            margin-bottom: 1mm;
        }
        .product-price {
            font-weight: bold;
        }
        .barcode-container {
            height: 15mm;
            text-align: center;
        }
        .barcode-container svg {
            height: 100%;
            max-width: 100%;
        }
        .product-category {
            font-size: 6pt;
            text-align: center;
            margin-top: 1mm;
        }
    `,
    'large': `
        .barcode-label {
            width: 100mm;
            height: 50mm;
            padding: 3mm;
            box-sizing: border-box;
            overflow: hidden;
            font-family: Arial, sans-serif;
        }
        .product-name {
            font-size: 12pt;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2mm;
        }
        .product-code {
            font-size: 10pt;
            margin-bottom: 2mm;
        }
        .product-details {
            display: flex;
            justify-content: space-between;
            font-size: 10pt;
            margin-bottom: 2mm;
        }
        .product-price {
            font-weight: bold;
        }
        .barcode-container {
            height: 25mm;
            text-align: center;
        }
        .barcode-container svg {
            height: 100%;
            max-width: 100%;
        }
        .product-category {
            font-size: 8pt;
            text-align: center;
            margin-top: 2mm;
        }
    `,
    'a4-30': `
        .barcode-label {
            width: 70mm;
            height: 29.7mm;
            padding: 2mm;
            box-sizing: border-box;
            overflow: hidden;
            font-family: Arial, sans-serif;
            page-break-inside: avoid;
        }
        .product-name {
            font-size: 9pt;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 1mm;
        }
        .product-code {
            font-size: 8pt;
            margin-bottom: 1mm;
        }
        .product-details {
            display: flex;
            justify-content: space-between;
            font-size: 8pt;
            margin-bottom: 1mm;
        }
        .product-price {
            font-weight: bold;
        }
        .barcode-container {
            height: 15mm;
            text-align: center;
        }
        .barcode-container svg {
            height: 100%;
            max-width: 100%;
        }
        .product-category {
            font-size: 7pt;
            text-align: center;
            margin-top: 1mm;
        }
    `,
    'a4-24': `
        .barcode-label {
            width: 70mm;
            height: 37.1mm;
            padding: 2mm;
            box-sizing: border-box;
            overflow: hidden;
            font-family: Arial, sans-serif;
            page-break-inside: avoid;
        }
        .product-name {
            font-size: 10pt;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2mm;
        }
        .product-code {
            font-size: 8pt;
            margin-bottom: 2mm;
        }
        .product-details {
            display: flex;
            justify-content: space-between;
            font-size: 8pt;
            margin-bottom: 2mm;
        }
        .product-price {
            font-weight: bold;
        }
        .barcode-container {
            height: 20mm;
            text-align: center;
        }
        .barcode-container svg {
            height: 100%;
            max-width: 100%;
        }
        .product-category {
            font-size: 7pt;
            text-align: center;
            margin-top: 2mm;
        }
    `,
    'a4-16': `
        .barcode-label {
            width: 99mm;
            height: 34mm;
            padding: 3mm;
            box-sizing: border-box;
            overflow: hidden;
            font-family: Arial, sans-serif;
            page-break-inside: avoid;
        }
        .product-name {
            font-size: 11pt;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2mm;
        }
        .product-code {
            font-size: 9pt;
            margin-bottom: 2mm;
        }
        .product-details {
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            margin-bottom: 2mm;
        }
        .product-price {
            font-weight: bold;
        }
        .barcode-container {
            height: 18mm;
            text-align: center;
        }
        .barcode-container svg {
            height: 100%;
            max-width: 100%;
        }
        .product-category {
            font-size: 8pt;
            text-align: center;
            margin-top: 2mm;
        }
    `
};

// Style-specific CSS
const STYLE_CSS = {
    'basic': ``,
    'compact': `
        .compact-template .product-name {
            font-size: 90%;
        }
        .compact-template .barcode-container {
            height: 80%;
        }
    `,
    'detailed': `
        .detailed-template .product-name {
            font-size: 95%;
        }
        .detailed-template .barcode-container {
            height: 70%;
        }
    `,
    'minimal': `
        .minimal-template .barcode-container {
            height: 90%;
            margin-top: 5%;
        }
    `
};

/**
 * Format price with currency symbol
 * @param {number} price - The price to format
 * @returns {string} - Formatted price with currency symbol
 */
function formatPrice(price) {
    return 'Rs. ' + parseFloat(price).toFixed(2);
}

/**
 * Generate a barcode label for a product
 * @param {Object} product - Product data
 * @param {string} templateSize - Template size
 * @param {string} templateStyle - Template style
 * @param {string} barcodeFormat - Barcode format
 * @returns {string} - HTML for the barcode label
 */
function generateBarcodeLabel(product, templateSize, templateStyle, barcodeFormat) {
    // Get the template HTML
    const templateHtml = TEMPLATE_STYLES[templateStyle](product, barcodeFormat);
    
    return templateHtml;
}

/**
 * Generate CSS for the selected template
 * @param {string} templateSize - Template size
 * @param {string} templateStyle - Template style
 * @returns {string} - CSS for the template
 */
function generateTemplateCSS(templateSize, templateStyle) {
    return TEMPLATE_CSS[templateSize] + STYLE_CSS[templateStyle];
}

/**
 * Generate a print sheet with multiple barcode labels
 * @param {Array} products - Array of product data
 * @param {string} templateSize - Template size
 * @param {string} templateStyle - Template style
 * @param {string} barcodeFormat - Barcode format
 * @param {number} copiesPerProduct - Number of copies per product
 * @returns {string} - HTML for the print sheet
 */
function generatePrintSheet(products, templateSize, templateStyle, barcodeFormat, copiesPerProduct) {
    const size = TEMPLATE_SIZES[templateSize];
    const css = generateTemplateCSS(templateSize, templateStyle);
    
    let html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Barcode Labels</title>
            <style>
                @page {
                    size: A4;
                    margin: 0;
                }
                body {
                    margin: 0;
                    padding: 0;
                    font-family: Arial, sans-serif;
                }
                .print-sheet {
                    width: 210mm;
                    min-height: 297mm;
                    padding: 5mm;
                    box-sizing: border-box;
                    display: flex;
                    flex-wrap: wrap;
                    justify-content: flex-start;
                    align-content: flex-start;
                }
                ${css}
            </style>
        </head>
        <body>
            <div class="print-sheet">
    `;
    
    // Generate the labels
    for (const product of products) {
        // Skip products without barcodes
        if (!product.barcode) continue;
        
        // Generate multiple copies if requested
        for (let i = 0; i < copiesPerProduct; i++) {
            html += generateBarcodeLabel(product, templateSize, templateStyle, barcodeFormat);
        }
    }
    
    html += `
            </div>
            <script src="../../assets/js/jsbarcode.min.js"></script>
            <script>
                window.onload = function() {
                    // Generate the barcodes
                    document.querySelectorAll('.barcode').forEach(function(barcode) {
                        if (barcode) {
                            const value = barcode.getAttribute('data-value');
                            const format = barcode.getAttribute('data-format');
                            
                            if (value) {
                                try {
                                    JsBarcode(barcode, value, {
                                        format: format || 'CODE128',
                                        width: 2,
                                        height: 50,
                                        displayValue: true,
                                        fontSize: 12,
                                        margin: 5
                                    });
                                }
                                catch (e) {
                                    console.error('Error generating barcode:', e);
                                    
                                    // Try with CODE128 as fallback
                                    try {
                                        JsBarcode(barcode, value, {
                                            format: 'CODE128',
                                            width: 2,
                                            height: 50,
                                            displayValue: true,
                                            fontSize: 12,
                                            margin: 5
                                        });
                                    }
                                    catch (fallbackError) {
                                        console.error('Fallback barcode generation failed:', fallbackError);
                                    }
                                }
                            }
                        }
                    });
                    
                    // Print automatically
                    setTimeout(function() {
                        window.print();
                    }, 500);
                };
            </script>
        </body>
        </html>
    `;
    
    return html;
}

/**
 * Generate a preview of the barcode template
 * @param {Object} product - Product data
 * @param {string} templateSize - Template size
 * @param {string} templateStyle - Template style
 * @param {string} barcodeFormat - Barcode format
 * @returns {Object} - HTML and CSS for the template preview
 */
function generateTemplatePreview(product, templateSize, templateStyle, barcodeFormat) {
    const templateHtml = generateBarcodeLabel(product, templateSize, templateStyle, barcodeFormat);
    const css = generateTemplateCSS(templateSize, templateStyle);
    
    return {
        html: templateHtml,
        css: css
    };
}
