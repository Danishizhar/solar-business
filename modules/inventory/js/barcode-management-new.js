/**
 * Barcode Management
 * Handles barcode generation, printing, and management
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all components
    initTemplatePreview();
    initBulkPrinting();
    initProductSelection();
    initBarcodeGeneration();
    initBarcodeScanner();
});

// Template size definitions (in mm)
const TEMPLATE_SIZES = {
    'small': { width: 38, height: 25, columns: 2, rows: 8 },
    'medium': { width: 50, height: 30, columns: 2, rows: 5 },
    'large': { width: 100, height: 50, columns: 1, rows: 4 },
    'a4-30': { width: 70, height: 29.7, columns: 3, rows: 10 }, // 3x10 labels on A4
    'a4-24': { width: 70, height: 37.1, columns: 3, rows: 8 },  // 3x8 labels on A4
    'a4-16': { width: 99, height: 34, columns: 2, rows: 8 }     // 2x8 labels on A4
};

/**
 * Initialize template preview
 */
function initTemplatePreview() {
    const templateSizeSelect = document.getElementById('template-size');
    const templateStyleSelect = document.getElementById('template-style');
    const barcodeFormatSelect = document.getElementById('barcode-format');
    const showPriceCheckbox = document.getElementById('show-price');
    const showWholesalePriceCheckbox = document.getElementById('show-wholesale-price');
    const showProductCodeCheckbox = document.getElementById('show-product-code');
    const updatePreviewBtn = document.getElementById('update-preview');
    const templatePreviewContainer = document.getElementById('template-preview');
    
    // Sample product for preview
    const sampleProduct = {
        id: 0,
        name: 'Sample Product',
        code: 'SP001',
        barcode: '1234567890128',
        price: '1250.00',
        wholesalePrice: '1100.00',
        category: 'Sample Category',
        brand: 'Sample Brand'
    };
    
    // Update preview when settings change
    updatePreviewBtn.addEventListener('click', function() {
        updateTemplatePreview();
    });
    
    // Initial preview
    updateTemplatePreview();
    
    /**
     * Update the template preview
     */
    function updateTemplatePreview() {
        const templateSize = templateSizeSelect.value;
        const templateStyle = templateStyleSelect.value;
        const barcodeFormat = barcodeFormatSelect.value;
        const showPrice = showPriceCheckbox.checked;
        const showWholesalePrice = showWholesalePriceCheckbox.checked;
        const showProductCode = showProductCodeCheckbox.checked;
        
        // Clear the preview container
        templatePreviewContainer.innerHTML = '';
        
        // Create a label container
        const label = document.createElement('div');
        label.className = 'barcode-label';
        label.style.border = '1px solid #ccc';
        label.style.padding = '5px';
        label.style.margin = '0 auto';
        label.style.boxSizing = 'border-box';
        label.style.width = TEMPLATE_SIZES[templateSize].width + 'mm';
        label.style.height = TEMPLATE_SIZES[templateSize].height + 'mm';
        label.style.overflow = 'hidden';
        label.style.position = 'relative';
        label.style.background = 'white';
        
        // Add product name
        const nameDiv = document.createElement('div');
        nameDiv.className = 'product-name';
        nameDiv.style.fontWeight = 'bold';
        nameDiv.style.fontSize = templateSize === 'small' ? '8pt' : '10pt';
        nameDiv.style.textAlign = 'center';
        nameDiv.style.marginBottom = '2px';
        nameDiv.style.whiteSpace = 'nowrap';
        nameDiv.style.overflow = 'hidden';
        nameDiv.style.textOverflow = 'ellipsis';
        nameDiv.textContent = sampleProduct.name;
        label.appendChild(nameDiv);
        
        // Add product code if enabled
        if (showProductCode) {
            const codeDiv = document.createElement('div');
            codeDiv.className = 'product-code';
            codeDiv.style.fontSize = templateSize === 'small' ? '7pt' : '8pt';
            codeDiv.style.textAlign = 'center';
            codeDiv.style.marginBottom = '2px';
            codeDiv.textContent = sampleProduct.code;
            label.appendChild(codeDiv);
        }
        
        // Add barcode container
        const barcodeDiv = document.createElement('div');
        barcodeDiv.className = 'barcode-container';
        barcodeDiv.style.textAlign = 'center';
        barcodeDiv.style.margin = '2px 0';
        barcodeDiv.style.display = 'flex';
        barcodeDiv.style.justifyContent = 'center';
        barcodeDiv.style.alignItems = 'center';
        barcodeDiv.style.minHeight = '40px';
        
        // Create SVG for barcode
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.id = 'preview-barcode';
        barcodeDiv.appendChild(svg);
        label.appendChild(barcodeDiv);
        
        // Add price if enabled
        if (showPrice) {
            const priceDiv = document.createElement('div');
            priceDiv.className = 'product-price';
            priceDiv.style.fontWeight = 'bold';
            priceDiv.style.fontSize = templateSize === 'small' ? '8pt' : '10pt';
            priceDiv.style.textAlign = 'center';
            priceDiv.style.marginTop = '1px';
            priceDiv.textContent = 'Retail: Rs. ' + sampleProduct.price;
            label.appendChild(priceDiv);
        }
        
        // Add wholesale price if enabled
        if (showWholesalePrice && sampleProduct.wholesalePrice) {
            const wholesaleDiv = document.createElement('div');
            wholesaleDiv.className = 'product-wholesale-price';
            wholesaleDiv.style.fontSize = templateSize === 'small' ? '7pt' : '9pt';
            wholesaleDiv.style.textAlign = 'center';
            wholesaleDiv.style.marginTop = '1px';
            wholesaleDiv.textContent = 'Wholesale: Rs. ' + sampleProduct.wholesalePrice;
            label.appendChild(wholesaleDiv);
        }
        
        // Add the label to the preview container
        templatePreviewContainer.appendChild(label);
        
        // Generate the barcode
        try {
            // Validate and normalize the barcode format
            let format = 'CODE128'; // Default format
            
            // List of valid formats for JsBarcode
            const validFormats = ['CODE128', 'CODE128A', 'CODE128B', 'CODE128C', 'EAN13', 'EAN8', 'UPC', 'CODE39', 'ITF14', 'MSI', 'PHARMACODE'];
            
            // Check if the selected format is valid
            if (validFormats.includes(barcodeFormat.toUpperCase())) {
                format = barcodeFormat.toUpperCase();
            }
            
            // Make sure the barcode value is valid for the selected format
            let barcodeValue = sampleProduct.barcode;
            
            // Different formats have different requirements
            if (format === 'EAN13' && (!/^\d{13}$/.test(barcodeValue))) {
                barcodeValue = '5901234123457'; // Valid EAN13 sample
            } else if (format === 'EAN8' && (!/^\d{8}$/.test(barcodeValue))) {
                barcodeValue = '12345670'; // Valid EAN8 sample
            } else if (format === 'UPC' && (!/^\d{12}$/.test(barcodeValue))) {
                barcodeValue = '123456789012'; // Valid UPC sample
            }
            
            // Generate the barcode with consistent settings
            JsBarcode('#preview-barcode', barcodeValue, {
                format: format,
                width: 1.5,
                height: 30,
                displayValue: true,
                fontSize: 10,
                margin: 2,
                textMargin: 2,
                background: '#FFFFFF'
            });
        } catch (e) {
            console.error('Error generating barcode:', e);
            
            // Try with CODE128 as fallback
            try {
                JsBarcode('#preview-barcode', sampleProduct.barcode, {
                    format: 'CODE128',
                    width: 1.5,
                    height: 30,
                    displayValue: true,
                    fontSize: 10,
                    margin: 2,
                    textMargin: 2,
                    background: '#FFFFFF'
                });
            } catch (fallbackError) {
                console.error('Fallback barcode generation failed:', fallbackError);
            }
        }
    }
}
/**
 * Initialize bulk printing
 */
function initBulkPrinting() {
    const printSelectedBtn = document.getElementById('print-selected-barcodes');
    const printSingleButtons = document.querySelectorAll('.print-single');
    const printPreviewModal = document.getElementById('print-preview-modal');
    const printPreviewContent = document.getElementById('print-preview-content');
    const confirmPrintBtn = document.getElementById('confirm-print');
    
    // Print selected barcodes
    if (printSelectedBtn) {
        printSelectedBtn.addEventListener('click', function() {
            const selectedProducts = getSelectedProducts();
            
            if (selectedProducts.length === 0) {
                alert('Please select at least one product with a barcode.');
                return;
            }
            
            // Show print preview
            updatePrintPreview(selectedProducts);
            
            // Show the modal
            const modal = new bootstrap.Modal(printPreviewModal);
            modal.show();
        });
    }
    
    // Print single barcode
    printSingleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            const productName = this.getAttribute('data-product-name');
            const productCode = this.getAttribute('data-product-code');
            const barcode = this.getAttribute('data-barcode');
            const price = this.getAttribute('data-price');
            const wholesalePrice = this.getAttribute('data-wholesale');
            const category = this.getAttribute('data-category');
            const brand = this.getAttribute('data-brand');
            
            if (!barcode) {
                alert('This product does not have a barcode.');
                return;
            }
            
            const product = {
                id: productId,
                name: productName,
                code: productCode,
                barcode: barcode,
                price: price,
                wholesalePrice: wholesalePrice,
                category: category,
                brand: brand
            };
            
            // Create an array with just this product
            const singleProduct = [product];
            
            // Show print preview
            updatePrintPreview(singleProduct);
            
            // Show the modal
            const modal = new bootstrap.Modal(printPreviewModal);
            modal.show();
        });
    });
    
    // Confirm print
    if (confirmPrintBtn) {
        confirmPrintBtn.addEventListener('click', function() {
            printBarcodes();
            
            // Close the modal
            const modal = bootstrap.Modal.getInstance(printPreviewModal);
            if (modal) {
                modal.hide();
            }
        });
    }
    
    /**
     * Update the print preview
     * @param {Array} products - Array of product data
     */
    function updatePrintPreview(products = getSelectedProducts()) {
        if (products.length === 0) {
            alert('Please select at least one product to print.');
            return;
        }
        
        const templateSize = document.getElementById('template-size').value;
        const templateStyle = document.getElementById('template-style').value;
        const copiesPerProduct = parseInt(document.getElementById('copies-per-product').value) || 1;
        const showPrice = document.getElementById('show-price').checked;
        const showWholesalePrice = document.getElementById('show-wholesale-price').checked;
        const showProductCode = document.getElementById('show-product-code').checked;
        
        // Get barcode format from settings if available, otherwise use CODE128 as default
        let barcodeFormat = 'CODE128';
        const barcodeFormatElement = document.getElementById('barcode-format');
        if (barcodeFormatElement) {
            barcodeFormat = barcodeFormatElement.value || 'CODE128';
        }
        
        // Show the print preview container
        printPreviewContent.innerHTML = `
            <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle me-2"></i>
                Preview of ${products.length} product(s) with ${copiesPerProduct} copies each.
            </div>
            <div class="embed-responsive embed-responsive-16by9" style="height: 500px; overflow: auto; border: 1px solid #ddd;">
                <div id="print-preview-container" style="padding: 20px; background: white;"></div>
            </div>
        `;
        
        // Get the container for the preview
        const container = document.getElementById('print-preview-container');
        if (!container) return;
        
        // Create a print sheet div
        const printSheet = document.createElement('div');
        printSheet.className = 'print-sheet';
        printSheet.style.display = 'flex';
        printSheet.style.flexWrap = 'wrap';
        printSheet.style.gap = '10px';
        printSheet.style.justifyContent = 'center';
        container.appendChild(printSheet);
        
        // Create labels for each product
        products.forEach(product => {
            if (!product.barcode) return;
            
            // Generate multiple copies if requested
            for (let i = 0; i < copiesPerProduct; i++) {
                // Create label container
                const label = document.createElement('div');
                label.className = 'barcode-label';
                label.style.border = '1px solid #ccc';
                label.style.padding = '5px';
                label.style.margin = '5px';
                label.style.boxSizing = 'border-box';
                label.style.width = TEMPLATE_SIZES[templateSize].width + 'mm';
                label.style.height = TEMPLATE_SIZES[templateSize].height + 'mm';
                label.style.overflow = 'hidden';
                label.style.position = 'relative';
                label.style.background = 'white';
                
                // Add product name
                const nameDiv = document.createElement('div');
                nameDiv.className = 'product-name';
                nameDiv.style.fontWeight = 'bold';
                nameDiv.style.fontSize = templateSize === 'small' ? '8pt' : '10pt';
                nameDiv.style.textAlign = 'center';
                nameDiv.style.marginBottom = '2px';
                nameDiv.style.whiteSpace = 'nowrap';
                nameDiv.style.overflow = 'hidden';
                nameDiv.style.textOverflow = 'ellipsis';
                nameDiv.textContent = product.name;
                label.appendChild(nameDiv);
                
                // Add product code if enabled
                if (showProductCode) {
                    const codeDiv = document.createElement('div');
                    codeDiv.className = 'product-code';
                    codeDiv.style.fontSize = templateSize === 'small' ? '7pt' : '8pt';
                    codeDiv.style.textAlign = 'center';
                    codeDiv.style.marginBottom = '2px';
                    codeDiv.textContent = product.code;
                    label.appendChild(codeDiv);
                }
                
                // Add barcode container
                const barcodeDiv = document.createElement('div');
                barcodeDiv.className = 'barcode-container';
                barcodeDiv.style.textAlign = 'center';
                barcodeDiv.style.margin = '2px 0';
                barcodeDiv.style.display = 'flex';
                barcodeDiv.style.justifyContent = 'center';
                barcodeDiv.style.alignItems = 'center';
                barcodeDiv.style.minHeight = '40px';
                
                // Create SVG for barcode
                const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.id = `barcode-${product.id}-${i}`;
                barcodeDiv.appendChild(svg);
                label.appendChild(barcodeDiv);
                
                // Add price if enabled
                if (showPrice) {
                    const priceDiv = document.createElement('div');
                    priceDiv.className = 'product-price';
                    priceDiv.style.fontWeight = 'bold';
                    priceDiv.style.fontSize = templateSize === 'small' ? '8pt' : '10pt';
                    priceDiv.style.textAlign = 'center';
                    priceDiv.style.marginTop = '1px';
                    priceDiv.textContent = 'Retail: Rs. ' + product.price;
                    label.appendChild(priceDiv);
                }
                
                // Add wholesale price if enabled
                if (showWholesalePrice && product.wholesalePrice) {
                    const wholesaleDiv = document.createElement('div');
                    wholesaleDiv.className = 'product-wholesale-price';
                    wholesaleDiv.style.fontSize = templateSize === 'small' ? '7pt' : '9pt';
                    wholesaleDiv.style.textAlign = 'center';
                    wholesaleDiv.style.marginTop = '1px';
                    wholesaleDiv.textContent = 'Wholesale: Rs. ' + (product.wholesalePrice || '0.00');
                    label.appendChild(wholesaleDiv);
                }
                
                // Add the label to the print sheet
                printSheet.appendChild(label);
                
                // Generate the barcode
                try {
                    // Validate and normalize the barcode format
                    let format = 'CODE128'; // Default format
                    
                    // List of valid formats for JsBarcode
                    const validFormats = ['CODE128', 'CODE128A', 'CODE128B', 'CODE128C', 'EAN13', 'EAN8', 'UPC', 'CODE39', 'ITF14', 'MSI', 'PHARMACODE'];
                    
                    // Check if the selected format is valid
                    if (validFormats.includes(barcodeFormat.toUpperCase())) {
                        format = barcodeFormat.toUpperCase();
                    }
                    
                    // Make sure the barcode value is valid for the selected format
                    let barcodeValue = product.barcode;
                    
                    // Different formats have different requirements
                    if (format === 'EAN13' && (!/^\d{13}$/.test(barcodeValue))) {
                        barcodeValue = '5901234123457'; // Valid EAN13 sample
                    } else if (format === 'EAN8' && (!/^\d{8}$/.test(barcodeValue))) {
                        barcodeValue = '12345670'; // Valid EAN8 sample
                    } else if (format === 'UPC' && (!/^\d{12}$/.test(barcodeValue))) {
                        barcodeValue = '123456789012'; // Valid UPC sample
                    }
                    
                    // Generate the barcode with consistent settings
                    JsBarcode(`#barcode-${product.id}-${i}`, barcodeValue, {
                        format: format,
                        width: 1.5,
                        height: 30,
                        displayValue: true,
                        fontSize: 10,
                        margin: 2,
                        textMargin: 2,
                        background: '#FFFFFF'
                    });
                } catch (e) {
                    console.error('Error generating barcode:', e);
                    
                    // Try with CODE128 as fallback
                    try {
                        JsBarcode(`#barcode-${product.id}-${i}`, product.barcode, {
                            format: 'CODE128',
                            width: 1.5,
                            height: 30,
                            displayValue: true,
                            fontSize: 10,
                            margin: 2,
                            textMargin: 2,
                            background: '#FFFFFF'
                        });
                    } catch (fallbackError) {
                        console.error('Fallback barcode generation failed:', fallbackError);
                    }
                }
            }
        });
        
        // Add print button
        const printButton = document.createElement('button');
        printButton.className = 'btn btn-primary mt-3';
        printButton.innerHTML = '<i class="fas fa-print"></i> Print Barcodes';
        printButton.style.display = 'block';
        printButton.style.margin = '20px auto';
        container.appendChild(printButton);
        
        // Add print functionality
        printButton.addEventListener('click', function() {
            printBarcodes();
        });
    }
    
    /**
     * Print barcodes
     */
    function printBarcodes() {
        // Get selected products
        const selectedProducts = getSelectedProducts();
        
        // Check if there are any products to print
        if (selectedProducts.length === 0) {
            alert('Please select at least one product with a barcode to print.');
            return;
        }
        
        // Create a new window for printing
        const printWindow = window.open('', '_blank');
        
        // Get current settings
        const templateSize = document.getElementById('template-size').value;
        const templateStyle = document.getElementById('template-style').value;
        const copiesPerProduct = parseInt(document.getElementById('copies-per-product').value) || 1;
        const showPrice = document.getElementById('show-price').checked;
        const showWholesalePrice = document.getElementById('show-wholesale-price').checked;
        const showProductCode = document.getElementById('show-product-code').checked;
        const barcodeFormat = document.getElementById('barcode-format').value || 'CODE128';
        
        // Create print content using the same function that generates the template preview
        let printContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Print Barcodes</title>
                <style>
                    @page {
                        size: A4;
                        margin: 5mm;
                    }
                    body {
                        margin: 0;
                        padding: 0;
                        font-family: Arial, sans-serif;
                    }
                    .print-sheet {
                        width: 210mm;
                        min-height: 297mm;
                        padding: 10mm;
                        box-sizing: border-box;
                        display: flex;
                        flex-wrap: wrap;
                        justify-content: flex-start;
                        align-content: flex-start;
                        gap: 5mm;
                    }
                    .barcode-label {
                        border: 1px solid #ccc;
                        padding: 5px;
                        box-sizing: border-box;
                        width: ${TEMPLATE_SIZES[templateSize].width}mm;
                        height: ${TEMPLATE_SIZES[templateSize].height}mm;
                        overflow: hidden;
                        background: white;
                    }
                    .product-name {
                        font-weight: bold;
                        font-size: ${templateSize === 'small' ? '8pt' : '10pt'};
                        text-align: center;
                        margin-bottom: 2px;
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                    }
                    .product-code {
                        font-size: ${templateSize === 'small' ? '7pt' : '8pt'};
                        text-align: center;
                        margin-bottom: 2px;
                        display: ${showProductCode ? 'block' : 'none'};
                    }
                    .barcode-container {
                        text-align: center;
                        margin: 2px 0;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        min-height: 40px;
                    }
                    .barcode-container svg {
                        max-width: 100%;
                        height: auto;
                        margin: 0 auto;
                    }
                    .product-price {
                        font-weight: bold;
                        font-size: ${templateSize === 'small' ? '8pt' : '10pt'};
                        text-align: center;
                        margin-top: 1px;
                        display: ${showPrice ? 'block' : 'none'};
                    }
                    .product-wholesale-price {
                        font-size: ${templateSize === 'small' ? '7pt' : '9pt'};
                        text-align: center;
                        margin-top: 1px;
                        display: ${showWholesalePrice ? 'block' : 'none'};
                    }
                    @media print {
                        #print-now-btn {
                            display: none !important;
                        }
                    }
                </style>
                <script src="../../assets/js/jsbarcode.min.js"></script>
            </head>
            <body>
                <div class="print-sheet">
        `;
        
        // Generate labels for each product
        selectedProducts.forEach(product => {
            for (let i = 0; i < copiesPerProduct; i++) {
                printContent += `
                    <div class="barcode-label">
                        <div class="product-name">${product.name}</div>
                        ${showProductCode ? `<div class="product-code">${product.code}</div>` : ''}
                        <div class="barcode-container">
                            <svg class="barcode-${product.id}-${i}" data-value="${product.barcode}" data-format="${barcodeFormat}"></svg>
                        </div>
                        ${showPrice ? `<div class="product-price">Retail: Rs. ${product.price}</div>` : ''}
                        ${showWholesalePrice && product.wholesalePrice ? `<div class="product-wholesale-price">Wholesale: Rs. ${product.wholesalePrice}</div>` : ''}
                    </div>
                `;
            }
        });
        
        // Close the HTML structure
        printContent += `
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <button id="print-now-btn" style="padding: 10px 20px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px;">
                        Print Now
                    </button>
                </div>
                <script>
                    window.onload = function() {
                        // Generate all barcodes
                        ${selectedProducts.map((product, index) => {
                            let barcodeScript = '';
                            for (let i = 0; i < copiesPerProduct; i++) {
                                barcodeScript += `
                                    try {
                                        // Use the exact same barcode settings as in the preview
                                        JsBarcode(".barcode-${product.id}-${i}", "${product.barcode}", {
                                            format: "${barcodeFormat.toUpperCase()}",
                                            width: 1.5,
                                            height: 30,
                                            displayValue: true,
                                            fontSize: 10,
                                            margin: 2,
                                            textMargin: 2,
                                            background: '#FFFFFF'
                                        });
                                    } catch(e) {
                                        console.error("Error generating barcode:", e);
                                        // Try with CODE128 as fallback
                                        try {
                                            JsBarcode(".barcode-${product.id}-${i}", "${product.barcode}", {
                                                format: "CODE128",
                                                width: 1.5,
                                                height: 30,
                                                displayValue: true,
                                                fontSize: 10,
                                                margin: 2,
                                                textMargin: 2,
                                                background: '#FFFFFF'
                                            });
                                        } catch(fallbackError) {
                                            console.error("Fallback barcode generation failed:", fallbackError);
                                        }
                                    }
                                `;
                            }
                            return barcodeScript;
                        }).join('')}
                        
                        // Add print button event
                        document.getElementById('print-now-btn').addEventListener('click', function() {
                            this.style.display = 'none';
                            // Directly trigger print
                            setTimeout(function() {
                                window.print();
                            }, 500);
                        });
                        
                        // Auto-print after 1 second if all barcodes are loaded
                        setTimeout(function() {
                            // Check if all barcodes are generated
                            const allBarcodes = document.querySelectorAll('svg[class^="barcode-"]');
                            let allLoaded = true;
                            
                            for (let i = 0; i < allBarcodes.length; i++) {
                                if (!allBarcodes[i].innerHTML) {
                                    allLoaded = false;
                                    break;
                                }
                            }
                            
                            if (allLoaded) {
                                document.getElementById('print-now-btn').click();
                            }
                        }, 1000);
                    };
                </script>
            </body>
            </html>
        `;
        
        // Write to the new window
        printWindow.document.open();
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.focus();
    }
}
/**
 * Initialize product selection
 */
function initProductSelection() {
    const selectAllBtn = document.getElementById('select-all');
    const deselectAllBtn = document.getElementById('deselect-all');
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    const productCheckboxes = document.querySelectorAll('.product-checkbox:not([disabled])');
    
    // Select all products
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            productCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = true;
            }
        });
    }
    
    // Deselect all products
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            productCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
            }
        });
    }
    
    // Select all checkbox
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            productCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
        });
    }
}

/**
 * Get selected products
 * @returns {Array} - Array of selected product data
 */
function getSelectedProducts() {
    const selectedCheckboxes = document.querySelectorAll('.product-checkbox:checked');
    const products = [];
    
    selectedCheckboxes.forEach(checkbox => {
        const product = {
            id: checkbox.getAttribute('data-id'),
            name: checkbox.getAttribute('data-name'),
            code: checkbox.getAttribute('data-code'),
            barcode: checkbox.getAttribute('data-barcode'),
            price: checkbox.getAttribute('data-price'),
            wholesalePrice: checkbox.getAttribute('data-wholesale'),
            category: checkbox.getAttribute('data-category'),
            brand: checkbox.getAttribute('data-brand')
        };
        
        if (product.barcode) {
            products.push(product);
        }
    });
    
    return products;
}

/**
 * Initialize barcode generation
 */
function initBarcodeGeneration() {
    const generateBarcodeButtons = document.querySelectorAll('.generate-barcode');
    const generateBarcodeModal = document.getElementById('generate-barcode-modal');
    const modalProductId = document.getElementById('modal-product-id');
    const modalProductName = document.getElementById('modal-product-name');
    const modalProductCode = document.getElementById('modal-product-code');
    const modalBarcode = document.getElementById('modal-barcode');
    const modalGenerateBarcode = document.getElementById('modal-generate-barcode-btn');
    const modalScanBarcode = document.getElementById('modal-scan-barcode');
    const modalBarcodePreview = document.getElementById('modal-barcode-preview');
    const saveBarcode = document.getElementById('save-barcode');
    
    // Show generate barcode modal
    generateBarcodeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            const productName = this.getAttribute('data-product-name');
            const productCode = this.getAttribute('data-product-code');
            
            // Set modal values
            modalProductId.value = productId;
            modalProductName.value = productName;
            modalProductCode.value = productCode;
            modalBarcode.value = '';
            modalBarcodePreview.innerHTML = '';
            modalBarcodePreview.style.display = 'none';
            
            // Show the modal
            const modal = new bootstrap.Modal(generateBarcodeModal);
            modal.show();
        });
    });
    
    // Generate barcode
    if (modalGenerateBarcode) {
        modalGenerateBarcode.addEventListener('click', function() {
            const productCode = modalProductCode.value;
            
            // Generate a barcode based on product code
            let barcode = '';
            
            // Simple algorithm: prefix + product code + check digit
            const prefix = '200'; // Example prefix
            const numericCode = productCode.replace(/\D/g, ''); // Remove non-numeric characters
            const paddedCode = numericCode.padStart(9, '0').substring(0, 9); // Pad and limit to 9 digits
            
            barcode = prefix + paddedCode;
            
            // Add check digit for EAN13
            const checkDigit = calculateEAN13CheckDigit(barcode);
            barcode += checkDigit;
            
            // Set the barcode value
            modalBarcode.value = barcode;
            
            // Show barcode preview
            updateBarcodePreview(barcode);
        });
    }
    
    // Manual barcode input
    if (modalBarcode) {
        modalBarcode.addEventListener('input', function() {
            const barcode = this.value.trim();
            if (barcode.length > 0) {
                updateBarcodePreview(barcode);
            } else {
                modalBarcodePreview.innerHTML = '';
                modalBarcodePreview.style.display = 'none';
            }
        });
    }
    
    // Scan barcode
    if (modalScanBarcode) {
        modalScanBarcode.addEventListener('click', function() {
            // Show barcode scanner modal
            const scannerModal = new bootstrap.Modal(document.getElementById('barcode-scanner-modal'));
            scannerModal.show();
            
            // Initialize barcode scanner
            initBarcodeScanner(function(barcode) {
                modalBarcode.value = barcode;
                updateBarcodePreview(barcode);
                scannerModal.hide();
            });
        });
    }
    
    // Save barcode
    if (saveBarcode) {
        saveBarcode.addEventListener('click', function() {
            const productId = modalProductId.value;
            const barcode = modalBarcode.value.trim();
            
            if (!barcode) {
                alert('Please enter or generate a barcode.');
                return;
            }
            
            // Save barcode to database
            saveBarcodeToDatabase(productId, barcode);
        });
    }
    
    /**
     * Update barcode preview
     * @param {string} barcode - Barcode value
     */
    function updateBarcodePreview(barcode) {
        modalBarcodePreview.innerHTML = '';
        
        if (!barcode) return;
        
        // Create SVG element
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.id = 'modal-barcode-svg';
        modalBarcodePreview.appendChild(svg);
        modalBarcodePreview.style.display = 'block';
        
        // Generate barcode
        try {
            JsBarcode('#modal-barcode-svg', barcode, {
                format: 'CODE128',
                width: 2,
                height: 50,
                displayValue: true,
                fontSize: 12,
                margin: 5
            });
        } catch (e) {
            console.error('Error generating barcode:', e);
            modalBarcodePreview.innerHTML = '<div class="alert alert-danger">Invalid barcode format</div>';
        }
    }
    
    /**
     * Calculate EAN13 check digit
     * @param {string} barcode - 12-digit barcode without check digit
     * @returns {string} - Check digit
     */
    function calculateEAN13CheckDigit(barcode) {
        if (barcode.length !== 12) {
            return '0';
        }
        
        let sum = 0;
        for (let i = 0; i < 12; i++) {
            const digit = parseInt(barcode.charAt(i));
            sum += (i % 2 === 0) ? digit : digit * 3;
        }
        
        const checkDigit = (10 - (sum % 10)) % 10;
        return checkDigit.toString();
    }
    
    /**
     * Save barcode to database
     * @param {string} productId - Product ID
     * @param {string} barcode - Barcode value
     */
    function saveBarcodeToDatabase(productId, barcode) {
        // Create form data
        const formData = new FormData();
        formData.append('product_id', productId);
        formData.append('barcode', barcode);
        
        // Send AJAX request
        fetch('actions/save_barcode.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Barcode saved successfully.');
                
                // Close the modal
                const modal = bootstrap.Modal.getInstance(generateBarcodeModal);
                if (modal) {
                    modal.hide();
                }
                
                // Reload the page
                window.location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error saving barcode:', error);
            alert('An error occurred while saving the barcode.');
        });
    }
}

/**
 * Initialize barcode scanner
 * @param {Function} callback - Callback function to handle scanned barcode
 */
function initBarcodeScanner(callback) {
    const scannerContainer = document.getElementById('scanner-container');
    const scannerViewport = document.getElementById('scanner-viewport');
    const scannerVideo = document.getElementById('scanner-video');
    const scannerCanvas = document.getElementById('scanner-canvas');
    const scannerStatus = document.getElementById('scanner-status');
    const scannerStartBtn = document.getElementById('scanner-start');
    const scannerStopBtn = document.getElementById('scanner-stop');
    
    let scanner = null;
    
    // Initialize scanner
    function initScanner() {
        scannerStatus.textContent = 'Initializing camera...';
        
        Quagga.init({
            inputStream: {
                name: 'Live',
                type: 'LiveStream',
                target: scannerVideo,
                constraints: {
                    width: 640,
                    height: 480,
                    facingMode: 'environment'
                }
            },
            decoder: {
                readers: [
                    'code_128_reader',
                    'ean_reader',
                    'ean_8_reader',
                    'code_39_reader',
                    'code_39_vin_reader',
                    'codabar_reader',
                    'upc_reader',
                    'upc_e_reader',
                    'i2of5_reader'
                ],
                multiple: false
            }
        }, function(err) {
            if (err) {
                console.error('Error initializing barcode scanner:', err);
                scannerStatus.textContent = 'Error initializing camera: ' + err;
                return;
            }
            
            scannerStatus.textContent = 'Camera initialized. Ready to scan.';
            scannerStartBtn.disabled = false;
        });
        
        Quagga.onDetected(function(result) {
            if (result && result.codeResult && result.codeResult.code) {
                const barcode = result.codeResult.code;
                scannerStatus.textContent = 'Barcode detected: ' + barcode;
                
                // Stop scanner
                Quagga.stop();
                scannerStartBtn.disabled = false;
                scannerStopBtn.disabled = true;
                
                // Call callback function
                if (typeof callback === 'function') {
                    callback(barcode);
                }
            }
        });
    }
    
    // Start scanner
    scannerStartBtn.addEventListener('click', function() {
        Quagga.start();
        scannerStatus.textContent = 'Scanning...';
        scannerStartBtn.disabled = true;
        scannerStopBtn.disabled = false;
    });
    
    // Stop scanner
    scannerStopBtn.addEventListener('click', function() {
        Quagga.stop();
        scannerStatus.textContent = 'Scanner stopped.';
        scannerStartBtn.disabled = false;
        scannerStopBtn.disabled = true;
    });
    
    // Initialize scanner when modal is shown
    initScanner();
}