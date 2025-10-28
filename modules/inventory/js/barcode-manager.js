/**
 * Barcode Management
 * Handles barcode generation, scanning, and printing
 */
document.addEventListener('DOMContentLoaded', function() {
    // Initialize barcode generation buttons
    initBarcodeGenerators();
    
    // Initialize barcode scanner
    initBarcodeScanner();
    
    // Initialize barcode printing
    initBarcodePrinting();
});

/**
 * Initialize barcode generation buttons
 */
function initBarcodeGenerators() {
    // Add product form
    const generateBarcodeBtn = document.getElementById('generate-barcode');
    const generateBarcodeBtnFull = document.getElementById('generate-barcode-btn');
    const barcodeInput = document.getElementById('barcode');
    const barcodePreview = document.getElementById('barcode-preview');
    
    // Edit product form
    const editGenerateBarcodeBtn = document.getElementById('edit-generate-barcode');
    const editGenerateBarcodeBtnFull = document.getElementById('edit-generate-barcode-btn');
    const editBarcodeInput = document.getElementById('edit_barcode');
    const editBarcodePreview = document.getElementById('edit-barcode-preview');
    
    // Add product form event listeners
    if (generateBarcodeBtn && barcodeInput && barcodePreview) {
        generateBarcodeBtn.addEventListener('click', function() {
            generateBarcode(barcodeInput, barcodePreview);
        });
    }
    
    if (generateBarcodeBtnFull && barcodeInput && barcodePreview) {
        generateBarcodeBtnFull.addEventListener('click', function() {
            generateBarcode(barcodeInput, barcodePreview);
        });
    }
    
    // Edit product form event listeners
    if (editGenerateBarcodeBtn && editBarcodeInput && editBarcodePreview) {
        editGenerateBarcodeBtn.addEventListener('click', function() {
            generateBarcode(editBarcodeInput, editBarcodePreview);
        });
    }
    
    if (editGenerateBarcodeBtnFull && editBarcodeInput && editBarcodePreview) {
        editGenerateBarcodeBtnFull.addEventListener('click', function() {
            generateBarcode(editBarcodeInput, editBarcodePreview);
        });
    }
}

/**
 * Generate a barcode and display it in the preview
 * @param {HTMLInputElement} input - The input field containing the barcode value
 * @param {HTMLElement} preview - The element to display the barcode in
 */
function generateBarcode(input, preview) {
    const value = input.value.trim();
    
    if (!value) {
        // Generate a random barcode if none is provided
        const randomBarcode = generateRandomBarcode();
        input.value = randomBarcode;
        renderBarcode(preview, randomBarcode);
    } else {
        renderBarcode(preview, value);
    }
}

/**
 * Generate a random barcode (EAN-13 format)
 * @returns {string} - A random 13-digit barcode
 */
function generateRandomBarcode() {
    // Generate first 12 digits
    let barcode = '';
    for (let i = 0; i < 12; i++) {
        barcode += Math.floor(Math.random() * 10);
    }
    
    // Calculate check digit
    let sum = 0;
    for (let i = 0; i < 12; i++) {
        sum += parseInt(barcode[i]) * (i % 2 === 0 ? 1 : 3);
    }
    const checkDigit = (10 - (sum % 10)) % 10;
    
    return barcode + checkDigit;
}

/**
 * Render a barcode in the preview element
 * @param {HTMLElement} preview - The element to display the barcode in
 * @param {string} value - The barcode value
 */
function renderBarcode(preview, value) {
    // Clear previous content
    preview.innerHTML = '';
    
    // Create SVG element
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    preview.appendChild(svg);
    
    try {
        // Generate barcode using JsBarcode
        JsBarcode(svg, value, {
            format: "EAN13",
            width: 2,
            height: 50,
            displayValue: true,
            fontSize: 12,
            margin: 10
        });
        
        // Show the preview
        preview.style.display = 'block';
    } catch (e) {
        console.error('Error generating barcode:', e);
        preview.innerHTML = `<div class="alert alert-danger">Invalid barcode format: ${e.message}</div>`;
    }
}

/**
 * Initialize barcode scanner
 */
function initBarcodeScanner() {
    const scanBarcodeBtn = document.getElementById('scan-barcode');
    const scannerModal = document.getElementById('barcode-scanner-modal');
    
    if (scanBarcodeBtn && scannerModal) {
        scanBarcodeBtn.addEventListener('click', function() {
            // Show the scanner modal
            const modal = new bootstrap.Modal(scannerModal);
            modal.show();
            
            // Start the scanner when the modal is shown
            scannerModal.addEventListener('shown.bs.modal', function() {
                startBarcodeScanner();
            });
            
            // Stop the scanner when the modal is hidden
            scannerModal.addEventListener('hidden.bs.modal', function() {
                stopBarcodeScanner();
            });
        });
    }
}

let quaggaInstance = null;

/**
 * Start the barcode scanner
 */
function startBarcodeScanner() {
    const scannerViewport = document.getElementById('scanner-viewport');
    
    if (!scannerViewport) return;
    
    // Initialize Quagga
    Quagga.init({
        inputStream: {
            name: "Live",
            type: "LiveStream",
            target: scannerViewport,
            constraints: {
                width: 640,
                height: 480,
                facingMode: "environment" // Use the rear camera on mobile devices
            }
        },
        decoder: {
            readers: [
                "ean_reader",
                "ean_8_reader",
                "code_128_reader",
                "code_39_reader",
                "upc_reader"
            ]
        }
    }, function(err) {
        if (err) {
            console.error("Error initializing barcode scanner:", err);
            return;
        }
        
        // Start scanning
        Quagga.start();
        quaggaInstance = Quagga;
        
        // Listen for scan results
        Quagga.onDetected(function(result) {
            const code = result.codeResult.code;
            
            // Play a beep sound
            const beep = new Audio('/assets/sounds/beep.mp3');
            beep.play();
            
            // Fill the barcode input
            const barcodeInput = document.getElementById('barcode') || document.getElementById('edit_barcode');
            if (barcodeInput) {
                barcodeInput.value = code;
                
                // Generate preview
                const barcodePreview = document.getElementById('barcode-preview') || document.getElementById('edit-barcode-preview');
                if (barcodePreview) {
                    renderBarcode(barcodePreview, code);
                }
            }
            
            // Close the modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('barcode-scanner-modal'));
            if (modal) {
                modal.hide();
            }
        });
    });
}

/**
 * Stop the barcode scanner
 */
function stopBarcodeScanner() {
    if (quaggaInstance) {
        quaggaInstance.stop();
        quaggaInstance = null;
    }
}

/**
 * Initialize barcode printing
 */
function initBarcodePrinting() {
    const printBarcodeBtn = document.getElementById('print-barcode');
    
    if (printBarcodeBtn) {
        printBarcodeBtn.addEventListener('click', function() {
            const barcodePreview = document.getElementById('barcode-preview') || document.getElementById('edit-barcode-preview');
            
            if (barcodePreview && barcodePreview.querySelector('svg')) {
                printBarcode(barcodePreview);
            } else {
                alert('Please generate a barcode first.');
            }
        });
    }
}

/**
 * Print a barcode
 * @param {HTMLElement} barcodeElement - The element containing the barcode
 */
function printBarcode(barcodeElement) {
    // Get product info
    const productName = document.getElementById('product_name')?.value || document.getElementById('edit_product_name')?.value || 'Product';
    const productCode = document.getElementById('product_code')?.value || document.getElementById('edit_product_code')?.value || '';
    
    // Create a new window for printing
    const printWindow = window.open('', '_blank');
    
    // Get the SVG content
    const svgElement = barcodeElement.querySelector('svg');
    const svgContent = new XMLSerializer().serializeToString(svgElement);
    
    // Create the print content
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Barcode Label</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 0;
                    padding: 0;
                }
                .label {
                    width: 100mm;
                    padding: 5mm;
                    box-sizing: border-box;
                    text-align: center;
                }
                .product-name {
                    font-size: 12pt;
                    font-weight: bold;
                    margin-bottom: 3mm;
                }
                .product-code {
                    font-size: 10pt;
                    margin-bottom: 3mm;
                }
                .barcode {
                    margin: 0 auto;
                }
                @media print {
                    @page {
                        size: 100mm 50mm;
                        margin: 0;
                    }
                }
            </style>
        </head>
        <body>
            <div class="label">
                <div class="product-name">${productName}</div>
                <div class="product-code">${productCode}</div>
                <div class="barcode">${svgContent}</div>
            </div>
            <script>
                window.onload = function() {
                    window.print();
                    setTimeout(function() {
                        window.close();
                    }, 500);
                };
            </script>
        </body>
        </html>
    `);
    
    printWindow.document.close();
}
