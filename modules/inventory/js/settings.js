/**
 * Inventory Settings JavaScript
 */
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Barcode preview functionality
    initBarcodePreview();
    
    // Handle install barcode settings button
    const installBarcodeSettingsBtn = document.getElementById('install-barcode-settings');
    if (installBarcodeSettingsBtn) {
        installBarcodeSettingsBtn.addEventListener('click', function() {
            if (confirm('This will install barcode settings in the database. Continue?')) {
                // Show loading state
                installBarcodeSettingsBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Installing...';
                installBarcodeSettingsBtn.disabled = true;
                
                // Call the install script
                fetch('actions/install_barcode_settings.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Show success message
                            alert('Barcode settings installed successfully. The page will now reload.');
                            location.reload();
                        } else {
                            // Show error message
                            alert('Error: ' + (data.messages ? data.messages.join('\n') : 'Unknown error'));
                            installBarcodeSettingsBtn.innerHTML = '<i class="fas fa-wrench"></i> Install Barcode Settings';
                            installBarcodeSettingsBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Error installing barcode settings:', error);
                        alert('Error installing barcode settings. Please try again.');
                        installBarcodeSettingsBtn.innerHTML = '<i class="fas fa-wrench"></i> Install Barcode Settings';
                        installBarcodeSettingsBtn.disabled = false;
                    });
            }
        });
    }
    
    // Handle install attribute settings button
    const installAttributeSettingsBtn = document.getElementById('install-attribute-settings');
    console.log('Attribute settings button found:', installAttributeSettingsBtn);
    
    if (installAttributeSettingsBtn) {
        installAttributeSettingsBtn.addEventListener('click', function(e) {
            console.log('Attribute settings button clicked');
            e.preventDefault();
            
            if (confirm('This will install attribute settings in the database. Continue?')) {
                console.log('User confirmed installation');
                
                // Show loading state
                installAttributeSettingsBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Installing...';
                installAttributeSettingsBtn.disabled = true;
                
                // Call the install script
                console.log('Fetching add_attribute_settings.php...');
                fetch('actions/add_attribute_settings.php')
                    .then(response => {
                        console.log('Response received:', response);
                        return response.text().then(text => {
                            console.log('Response text:', text);
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                console.error('JSON parse error:', e);
                                // If not JSON, just return the text for display
                                return { success: false, messages: [text] };
                            }
                        });
                    })
                    .then(data => {
                        console.log('Processed data:', data);
                        if (data.success) {
                            // Show success message
                            alert('Attribute settings installed successfully. The page will now reload.');
                            location.reload();
                        } else {
                            // Show error message
                            alert('Error: ' + (data.messages ? data.messages.join('\n') : 'Unknown error'));
                            installAttributeSettingsBtn.innerHTML = '<i class="fas fa-wrench"></i> Install Attribute Settings';
                            installAttributeSettingsBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Error installing attribute settings:', error);
                        alert('Error installing attribute settings. Please try again.');
                        installAttributeSettingsBtn.innerHTML = '<i class="fas fa-wrench"></i> Install Attribute Settings';
                        installAttributeSettingsBtn.disabled = false;
                    });
            }
        });
    }
    
    // Handle tab navigation via URL hash
    function activateTabFromHash() {
        const hash = window.location.hash;
        if (hash) {
            const tabId = hash.substring(1); // Remove the # character
            const tab = document.getElementById(tabId + '-tab');
            if (tab) {
                const bsTab = new bootstrap.Tab(tab);
                bsTab.show();
            }
        }
    }
    
    // Activate tab on page load if hash is present
    activateTabFromHash();
    
    // Activate tab when hash changes
    window.addEventListener('hashchange', activateTabFromHash);
    
    /**
     * Initialize barcode preview functionality
     */
    function initBarcodePreview() {
        const previewContainer = document.getElementById('barcode-preview');
        const generatePreviewBtn = document.getElementById('generate-preview');
        
        if (!previewContainer || !generatePreviewBtn) return;
        
        // Function to generate and render a barcode
        function renderBarcodePreview() {
            // Get selected format
            const formatSelect = document.querySelector('select[name="setting_default_barcode_format"]');
            const format = formatSelect ? formatSelect.value : 'EAN13';
            
            // Get prefix
            const prefixInput = document.querySelector('input[name="setting_barcode_prefix"]');
            const prefix = prefixInput ? prefixInput.value : '';
            
            // Get label style
            const styleSelect = document.querySelector('select[name="setting_barcode_label_style"]');
            const style = styleSelect ? styleSelect.value : 'basic';
            
            // Generate sample barcode
            const barcode = generateSampleBarcode(format, prefix);
            
            // Clear previous content
            previewContainer.innerHTML = '';
            
            // Create SVG element
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            previewContainer.appendChild(svg);
            
            try {
                // Generate barcode using JsBarcode
                JsBarcode(svg, barcode, {
                    format: format,
                    width: 2,
                    height: 50,
                    displayValue: true,
                    fontSize: 12,
                    margin: 10
                });
                
                // Apply style
                const container = document.getElementById('barcode-preview-container');
                if (container) {
                    // Remove previous styles
                    container.classList.remove('bg-light', 'border-primary', 'border-2');
                    
                    // Apply style based on selection
                    switch (style) {
                        case 'detailed':
                            container.classList.add('bg-light', 'border-primary', 'border-2');
                            document.getElementById('preview-product-price').style.display = 'block';
                            document.getElementById('preview-product-code').style.display = 'block';
                            break;
                        case 'compact':
                            container.classList.add('bg-light');
                            document.getElementById('preview-product-price').style.display = 'none';
                            document.getElementById('preview-product-code').style.display = 'block';
                            break;
                        case 'minimal':
                            document.getElementById('preview-product-name').style.display = 'none';
                            document.getElementById('preview-product-price').style.display = 'none';
                            document.getElementById('preview-product-code').style.display = 'none';
                            break;
                        default: // basic
                            document.getElementById('preview-product-name').style.display = 'block';
                            document.getElementById('preview-product-price').style.display = 'block';
                            document.getElementById('preview-product-code').style.display = 'none';
                            break;
                    }
                }
            } catch (e) {
                console.error('Error generating barcode preview:', e);
                previewContainer.innerHTML = `<div class="alert alert-danger">Invalid barcode format: ${e.message}</div>`;
            }
        }
        
        // Generate a sample barcode based on format
        function generateSampleBarcode(format, prefix = '') {
            prefix = prefix.replace(/[^0-9]/g, '');
            
            switch (format) {
                case 'EAN13':
                    return generateEAN13Barcode(prefix);
                case 'EAN8':
                    return generateEAN8Barcode(prefix);
                case 'UPC':
                    return generateUPCBarcode(prefix);
                case 'CODE128':
                    return 'SAMPLE123456';
                case 'CODE39':
                    return 'SAMPLE-123';
                default:
                    return generateEAN13Barcode(prefix);
            }
        }
        
        // Generate EAN-13 barcode
        function generateEAN13Barcode(prefix = '') {
            if (prefix.length > 12) {
                prefix = prefix.substring(0, 12);
            }
            
            let barcode = prefix;
            const remainingDigits = 12 - prefix.length;
            
            // Use a fixed sample number for preview
            barcode += '123456789012'.substring(0, remainingDigits);
            
            // Calculate check digit
            let sum = 0;
            for (let i = 0; i < 12; i++) {
                sum += parseInt(barcode[i]) * (i % 2 === 0 ? 1 : 3);
            }
            const checkDigit = (10 - (sum % 10)) % 10;
            
            return barcode + checkDigit;
        }
        
        // Generate EAN-8 barcode
        function generateEAN8Barcode(prefix = '') {
            if (prefix.length > 7) {
                prefix = prefix.substring(0, 7);
            }
            
            let barcode = prefix;
            const remainingDigits = 7 - prefix.length;
            
            // Use a fixed sample number for preview
            barcode += '1234567'.substring(0, remainingDigits);
            
            // Calculate check digit
            let sum = 0;
            for (let i = 0; i < 7; i++) {
                sum += parseInt(barcode[i]) * (i % 2 === 0 ? 3 : 1);
            }
            const checkDigit = (10 - (sum % 10)) % 10;
            
            return barcode + checkDigit;
        }
        
        // Generate UPC barcode
        function generateUPCBarcode(prefix = '') {
            if (prefix.length > 11) {
                prefix = prefix.substring(0, 11);
            }
            
            let barcode = prefix;
            const remainingDigits = 11 - prefix.length;
            
            // Use a fixed sample number for preview
            barcode += '12345678901'.substring(0, remainingDigits);
            
            // Calculate check digit
            let sum = 0;
            for (let i = 0; i < 11; i++) {
                sum += parseInt(barcode[i]) * (i % 2 === 0 ? 3 : 1);
            }
            const checkDigit = (10 - (sum % 10)) % 10;
            
            return barcode + checkDigit;
        }
        
        // Initial render
        if (typeof JsBarcode !== 'undefined') {
            renderBarcodePreview();
        } else {
            // Load JsBarcode if not available
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js';
            script.onload = renderBarcodePreview;
            document.head.appendChild(script);
        }
        
        // Update preview when button is clicked
        generatePreviewBtn.addEventListener('click', renderBarcodePreview);
        
        // Update preview when settings change
        document.querySelectorAll('select[name^="setting_barcode_"], input[name^="setting_barcode_"]').forEach(el => {
            el.addEventListener('change', renderBarcodePreview);
        });
    }
});
