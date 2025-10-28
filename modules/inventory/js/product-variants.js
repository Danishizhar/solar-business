/**
 * Product Variants Management
 * Handles dynamic creation and management of product variants
 */
document.addEventListener('DOMContentLoaded', function() {
    // Add event listener for the form submission
    const productForm = document.getElementById('addProductForm');
    if (productForm) {
        productForm.addEventListener('submit', function(e) {
            const hasVariantsCheckbox = document.getElementById('has_variants');
            if (hasVariantsCheckbox && hasVariantsCheckbox.checked) {
                // Get variant data
                const attributeInputs = document.querySelectorAll('.variant-attributes input[name="variant_attributes[]"]');
                const valueInputs = document.querySelectorAll('.variant-attributes input[name="variant_values[]"]');
                
                // Store for later use after form submission
                const attributes = [];
                const values = [];
                
                for (let i = 0; i < attributeInputs.length; i++) {
                    const attributeName = attributeInputs[i].value.trim();
                    const valuesStr = valueInputs[i].value.trim();
                    
                    if (attributeName && valuesStr) {
                        attributes.push(attributeName);
                        values.push(valuesStr);
                    }
                }
                
                // Store in session storage for use after redirect
                if (attributes.length > 0) {
                    sessionStorage.setItem('pendingVariantAttributes', JSON.stringify(attributes));
                    sessionStorage.setItem('pendingVariantValues', JSON.stringify(values));
                }
            }
        });
    }
    
    // Check if we need to process pending variants
    const successMessage = document.querySelector('.alert-success');
    if (successMessage && successMessage.textContent.includes('Product added successfully')) {
        const pendingAttributes = sessionStorage.getItem('pendingVariantAttributes');
        const pendingValues = sessionStorage.getItem('pendingVariantValues');
        
        if (pendingAttributes && pendingValues) {
            // Get the product ID from the URL or page content
            // This is a simplified approach - you might need to adjust based on your actual page structure
            const productIdMatch = window.location.search.match(/[?&]id=(\d+)/);
            let productId = productIdMatch ? productIdMatch[1] : null;
            
            if (!productId) {
                // Try to find it in the page content
                const editButtons = document.querySelectorAll('a[href*="edit_product.php?id="]');
                if (editButtons.length > 0) {
                    const href = editButtons[0].getAttribute('href');
                    const idMatch = href.match(/id=(\d+)/);
                    if (idMatch) {
                        productId = idMatch[1];
                    }
                }
            }
            
            if (productId) {
                const attributes = JSON.parse(pendingAttributes);
                const values = JSON.parse(pendingValues);
                
                // Generate combinations
                const allValues = values.map(v => v.split(',').map(item => item.trim()));
                const combinations = generateCombinations(allValues);
                const combinationsForSubmit = combinations.map(combo => combo.join(' | '));
                
                // Save variants
                saveVariants(productId, attributes, values, combinationsForSubmit);
                
                // Clear pending data
                sessionStorage.removeItem('pendingVariantAttributes');
                sessionStorage.removeItem('pendingVariantValues');
            }
        }
    }
    
    // Toggle variant container visibility
    const hasVariantsCheckbox = document.getElementById('has_variants');
    const editHasVariantsCheckbox = document.getElementById('edit_has_variants');
    const variantsContainer = document.getElementById('variants-container');
    const editVariantsContainer = document.getElementById('edit-variants-container');
    
    if (hasVariantsCheckbox && variantsContainer) {
        hasVariantsCheckbox.addEventListener('change', function() {
            variantsContainer.style.display = this.checked ? 'block' : 'none';
        });
    }
    
    if (editHasVariantsCheckbox && editVariantsContainer) {
        editHasVariantsCheckbox.addEventListener('change', function() {
            editVariantsContainer.style.display = this.checked ? 'block' : 'none';
        });
    }
    
    // Add variant attribute row
    const addAttributeBtn = document.getElementById('add-attribute');
    const editAddAttributeBtn = document.getElementById('edit-add-attribute');
    
    if (addAttributeBtn) {
        addAttributeBtn.addEventListener('click', function() {
            addVariantAttributeRow('add');
        });
    }
    
    if (editAddAttributeBtn) {
        editAddAttributeBtn.addEventListener('click', function() {
            addVariantAttributeRow('edit');
        });
    }
    
    // Function to add variant attribute row
    function addVariantAttributeRow(mode) {
        const container = document.querySelector(mode === 'edit' ? '.edit-variant-attributes' : '.variant-attributes');
        
        const row = document.createElement('div');
        row.className = 'row mb-2 variant-attribute-row';
        
        row.innerHTML = `
            <div class="col-md-5">
                <input type="text" class="form-control" name="variant_attributes[]" placeholder="e.g. Size, Color, Material">
            </div>
            <div class="col-md-6">
                <input type="text" class="form-control" name="variant_values[]" placeholder="Values (comma separated)">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-sm btn-danger remove-attribute">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        container.appendChild(row);
        
        // Add event listener to remove button
        row.querySelector('.remove-attribute').addEventListener('click', function() {
            container.removeChild(row);
        });
    }
    
    // Remove variant attribute row
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-attribute') || e.target.closest('.remove-attribute')) {
            const button = e.target.classList.contains('remove-attribute') ? e.target : e.target.closest('.remove-attribute');
            const row = button.closest('.variant-attribute-row');
            if (row && row.parentNode) {
                row.parentNode.removeChild(row);
            }
        }
    });
    
    // Generate variants button
    const generateVariantsBtn = document.getElementById('generate-variants');
    const editGenerateVariantsBtn = document.getElementById('edit-generate-variants');
    
    if (generateVariantsBtn) {
        generateVariantsBtn.addEventListener('click', function() {
            generateVariantCombinations('add');
        });
    }
    
    if (editGenerateVariantsBtn) {
        editGenerateVariantsBtn.addEventListener('click', function() {
            generateVariantCombinations('edit');
        });
    }
    
    // Function to generate variant combinations
    function generateVariantCombinations(mode) {
        const prefix = mode === 'edit' ? 'edit_' : '';
        const container = document.getElementById(`${prefix}variants-preview`);
        const attributeInputs = document.querySelectorAll(`${mode === 'edit' ? '.edit-variant-attributes' : '.variant-attributes'} input[name="variant_attributes[]"]`);
        const valueInputs = document.querySelectorAll(`${mode === 'edit' ? '.edit-variant-attributes' : '.variant-attributes'} input[name="variant_values[]"]`);
        
        // Check if variant images are enabled
        const variantImagesEnabled = document.getElementById(`${prefix}variant_images`).checked;
        console.log('Variant images enabled:', variantImagesEnabled);
        
        // Collect attributes and values
        const attributes = [];
        const allValues = [];
        
        for (let i = 0; i < attributeInputs.length; i++) {
            const attributeName = attributeInputs[i].value.trim();
            const valuesStr = valueInputs[i].value.trim();
            
            if (attributeName && valuesStr) {
                attributes.push(attributeName);
                allValues.push(valuesStr.split(',').map(v => v.trim()));
            }
        }
        
        if (attributes.length === 0) {
            alert('Please add at least one variant attribute with values.');
            return;
        }
        
        // Generate all combinations
        const combinations = generateCombinations(allValues);
        
        // Store combinations for form submission
        const combinationsForSubmit = combinations.map(combo => combo.join(' | '));
        
        // Create hidden input to store combinations
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'variant_combinations';
        hiddenInput.value = JSON.stringify(combinationsForSubmit);
        container.appendChild(hiddenInput);
        
        // Display preview
        container.innerHTML = '';
        
        const table = document.createElement('table');
        table.className = 'table table-bordered table-sm';
        
        // Create header
        const thead = document.createElement('thead');
        let headerRow = document.createElement('tr');
        
        for (const attr of attributes) {
            const th = document.createElement('th');
            th.textContent = attr;
            headerRow.appendChild(th);
        }
        
        // Add additional columns
        let additionalHeaders = ['SKU', 'Price Adjustment', 'Stock'];
        
        // Always add Image column for simplicity
        additionalHeaders.push('Image');
        additionalHeaders.push('Actions');
        
        for (const header of additionalHeaders) {
            const th = document.createElement('th');
            th.textContent = header;
            headerRow.appendChild(th);
        }
        
        thead.appendChild(headerRow);
        table.appendChild(thead);
        
        // Create body
        const tbody = document.createElement('tbody');
        
        for (let i = 0; i < combinations.length; i++) {
            const row = document.createElement('tr');
            
            // Add variant values
            for (const value of combinations[i]) {
                const td = document.createElement('td');
                td.textContent = value;
                row.appendChild(td);
            }
            
            // Add SKU cell
            const skuCell = document.createElement('td');
            const skuInput = document.createElement('input');
            skuInput.type = 'text';
            skuInput.className = 'form-control form-control-sm';
            skuInput.name = `variant_sku[]`;
            skuInput.placeholder = 'Auto-generated';
            skuCell.appendChild(skuInput);
            row.appendChild(skuCell);
            
            // Add price adjustment cell
            const priceCell = document.createElement('td');
            const priceInput = document.createElement('input');
            priceInput.type = 'number';
            priceInput.className = 'form-control form-control-sm';
            priceInput.name = `variant_price_adj[]`;
            priceInput.placeholder = '0.00';
            priceInput.step = '0.01';
            priceCell.appendChild(priceInput);
            row.appendChild(priceCell);
            
            // Add stock cell
            const stockCell = document.createElement('td');
            const stockInput = document.createElement('input');
            stockInput.type = 'number';
            stockInput.className = 'form-control form-control-sm';
            stockInput.name = `variant_stock[]`;
            stockInput.placeholder = '0';
            stockInput.min = '0';
            stockCell.appendChild(stockInput);
            row.appendChild(stockCell);
            
            // Always add image cell for simplicity
            const imageCell = document.createElement('td');
            const imageInput = document.createElement('input');
            imageInput.type = 'file';
            imageInput.className = 'form-control form-control-sm';
            imageInput.name = `variant_image[]`;
            imageInput.accept = 'image/*';
            
            // Add a small preview container
            const previewDiv = document.createElement('div');
            previewDiv.className = 'mt-1 variant-image-preview';
            previewDiv.id = `variant-image-preview-${i}`;
            
            // Add event listener to show image preview
            imageInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewDiv.innerHTML = `<img src="${e.target.result}" class="img-thumbnail" style="max-width: 50px; max-height: 50px;">`;
                    };
                    reader.readAsDataURL(file);
                } else {
                    previewDiv.innerHTML = '';
                }
            });
            
            imageCell.appendChild(imageInput);
            imageCell.appendChild(previewDiv);
            row.appendChild(imageCell);
            
            // Add actions cell
            const actionsCell = document.createElement('td');
            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'btn btn-sm btn-danger remove-variant';
            deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
            deleteBtn.addEventListener('click', function() {
                tbody.removeChild(row);
            });
            actionsCell.appendChild(deleteBtn);
            row.appendChild(actionsCell);
            
            // Add hidden inputs for variant values
            for (let j = 0; j < attributes.length; j++) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = `variant_value_${j}[]`;
                hiddenInput.value = combinations[i][j];
                row.appendChild(hiddenInput);
            }
            
            // Add hidden input for attribute names
            if (i === 0) {
                for (let j = 0; j < attributes.length; j++) {
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = `variant_attribute_${j}`;
                    hiddenInput.value = attributes[j];
                    container.appendChild(hiddenInput);
                }
                
                // Add hidden input for attribute count
                const countInput = document.createElement('input');
                countInput.type = 'hidden';
                countInput.name = 'variant_attribute_count';
                countInput.value = attributes.length;
                container.appendChild(countInput);
            }
            
            tbody.appendChild(row);
        }
        
        table.appendChild(tbody);
        container.appendChild(table);
        
        // Save variants button
        const saveVariantsBtn = document.createElement('button');
        saveVariantsBtn.type = 'button';
        saveVariantsBtn.className = 'btn btn-sm btn-primary';
        saveVariantsBtn.textContent = 'Save Variants';
        saveVariantsBtn.addEventListener('click', function() {
            saveVariants(mode === 'edit' ? document.getElementById('edit_product_id').value : document.getElementById('product_id').value, attributes, allValues, combinations);
        });
        container.appendChild(saveVariantsBtn);
    }
    
    // Helper function to generate all combinations
    function generateCombinations(arrays) {
        if (!arrays.length) return [];
        if (arrays.length === 1) return arrays[0].map(value => [value]);
        
        const result = [];
        const allCasesOfRest = generateCombinations(arrays.slice(1));
        
        for (const value of arrays[0]) {
            for (const item of allCasesOfRest) {
                result.push([value, ...item]);
            }
        }
        
        return result;
    }
    
    // Function to save variants to database
    function saveVariants(productId, variantAttributes, variantValues, variantCombinations) {
        // Create form data
        const formData = new FormData();
        formData.append('product_id', productId);
        
        // Add variant attributes and values
        variantAttributes.forEach((attr, index) => {
            formData.append(`variant_attributes[${index}]`, attr);
        });
        
        variantValues.forEach((values, index) => {
            formData.append(`variant_values[${index}]`, values);
        });
        
        // Add variant combinations
        variantCombinations.forEach((combo, index) => {
            formData.append(`variant_combinations[${index}]`, combo);
        });
        
        // Add SKUs, price adjustments, and stock levels
        const skuInputs = document.querySelectorAll('input[name="variant_sku[]"]');
        const priceAdjInputs = document.querySelectorAll('input[name="variant_price_adj[]"]');
        const stockInputs = document.querySelectorAll('input[name="variant_stock[]"]');
        
        skuInputs.forEach((input, index) => {
            formData.append(`variant_sku[${index}]`, input.value);
        });
        
        priceAdjInputs.forEach((input, index) => {
            formData.append(`variant_price_adj[${index}]`, input.value);
        });
        
        stockInputs.forEach((input, index) => {
            formData.append(`variant_stock[${index}]`, input.value);
        });
        
        // Send AJAX request
        fetch('actions/save_variants.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`${data.count} variants created successfully!`);
            } else {
                alert('Error creating variants: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while saving variants.');
        });
    }
});
