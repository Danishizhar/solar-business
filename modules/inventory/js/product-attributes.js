/**
 * Product Attributes Management
 * Handles dynamic loading of category-specific attributes
 */
document.addEventListener('DOMContentLoaded', function() {
    // Handle category change to load relevant attributes
    const categorySelect = document.getElementById('category_id');
    const editCategorySelect = document.getElementById('edit_category_id');
    
    if (categorySelect) {
        categorySelect.addEventListener('change', function() {
            loadCategoryAttributes(this.value, 'add');
        });
    }
    
    if (editCategorySelect) {
        editCategorySelect.addEventListener('change', function() {
            loadCategoryAttributes(this.value, 'edit');
        });
    }
    
    // Function to load category attributes
    function loadCategoryAttributes(categoryId, mode) {
        const categoryContainer = document.getElementById(mode === 'edit' ? 'edit-category-attributes-container' : 'category-attributes-container');
        const allAttributesContainer = document.getElementById(mode === 'edit' ? 'edit-all-attributes-container' : 'all-attributes-container');
        
        if (!categoryContainer) {
            console.error('Category container not found');
            return;
        }
        
        if (!categoryId) {
            categoryContainer.innerHTML = '<p class="text-info mb-3"><i class="fas fa-info-circle"></i> Select a category to load relevant attributes.</p>';
            if (allAttributesContainer) {
                allAttributesContainer.style.display = 'none';
            }
            return;
        }
        
        // Show general attributes when a category is selected
        if (allAttributesContainer) {
            allAttributesContainer.style.display = 'block';
        }
        
        // Show loading indicator
        categoryContainer.innerHTML = '<p class="text-info mb-3"><i class="fas fa-spinner fa-spin"></i> Loading attributes...</p>';
        
        // AJAX call to get category attributes
        fetch(`actions/get_category_attributes.php?category_id=${categoryId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('API Response:', data);
                
                // Check if we have a valid response with attributes array
                if (data && data.success === true && Array.isArray(data.attributes)) {
                    if (data.attributes.length > 0) {
                        // Render the attributes
                        let html = '<h6 class="mb-3">Category-specific Attributes</h6>';
                        
                        // Manually loop through attributes instead of using forEach
                        for (let i = 0; i < data.attributes.length; i++) {
                            const attribute = data.attributes[i];
                            
                            html += `
                            <div class="mb-3">
                                <label for="${mode === 'edit' ? 'edit_' : ''}attribute_${attribute.id}" class="form-label">
                                    ${attribute.attribute_name}
                                    ${attribute.is_required ? '<span class="text-danger">*</span>' : ''}
                                </label>
                            `;
                            
                            // Render different input types
                            switch (attribute.input_type) {
                                case 'text':
                                    html += `
                                    <input type="text" class="form-control" 
                                           id="${mode === 'edit' ? 'edit_' : ''}attribute_${attribute.id}" 
                                           name="attributes[${attribute.id}]" 
                                           ${attribute.is_required ? 'required' : ''}
                                           placeholder="${attribute.attribute_name}"
                                           ${attribute.current_value ? `value="${attribute.current_value}"` : ''}>
                                    `;
                                    break;
                                    
                                case 'number':
                                    html += `
                                    <input type="number" class="form-control" 
                                           id="${mode === 'edit' ? 'edit_' : ''}attribute_${attribute.id}" 
                                           name="attributes[${attribute.id}]" 
                                           ${attribute.is_required ? 'required' : ''}
                                           step="any"
                                           ${attribute.current_value ? `value="${attribute.current_value}"` : ''}>
                                    `;
                                    break;
                                    
                                case 'textarea':
                                    html += `
                                    <textarea class="form-control" 
                                              id="${mode === 'edit' ? 'edit_' : ''}attribute_${attribute.id}" 
                                              name="attributes[${attribute.id}]" 
                                              ${attribute.is_required ? 'required' : ''}
                                              rows="3">${attribute.current_value || ''}</textarea>
                                    `;
                                    break;
                                    
                                case 'select':
                                    html += `
                                    <select class="form-select" 
                                            id="${mode === 'edit' ? 'edit_' : ''}attribute_${attribute.id}" 
                                            name="attributes[${attribute.id}]" 
                                            ${attribute.is_required ? 'required' : ''}>
                                        <option value="">Select ${attribute.attribute_name}</option>
                                    `;
                                    
                                    // Add options if available
                                    if (attribute.options_array && Array.isArray(attribute.options_array)) {
                                        for (let j = 0; j < attribute.options_array.length; j++) {
                                            const option = attribute.options_array[j];
                                            const selected = attribute.current_value === option ? 'selected' : '';
                                            html += `<option value="${option}" ${selected}>${option}</option>`;
                                        }
                                    } else if (attribute.options) {
                                        try {
                                            const options = JSON.parse(attribute.options);
                                            if (Array.isArray(options)) {
                                                for (let j = 0; j < options.length; j++) {
                                                    const option = options[j];
                                                    const selected = attribute.current_value === option ? 'selected' : '';
                                                    html += `<option value="${option}" ${selected}>${option}</option>`;
                                                }
                                            }
                                        } catch (e) {
                                            console.error('Error parsing options:', e);
                                        }
                                    }
                                    
                                    html += `</select>`;
                                    break;
                                    
                                case 'multiselect':
                                    html += `
                                    <select class="form-select" 
                                            id="${mode === 'edit' ? 'edit_' : ''}attribute_${attribute.id}" 
                                            name="attributes[${attribute.id}][]" 
                                            ${attribute.is_required ? 'required' : ''}
                                            multiple>
                                    `;
                                    
                                    // Add options if available
                                    if (attribute.options_array && Array.isArray(attribute.options_array)) {
                                        for (let j = 0; j < attribute.options_array.length; j++) {
                                            const option = attribute.options_array[j];
                                            html += `<option value="${option}">${option}</option>`;
                                        }
                                    } else if (attribute.options) {
                                        try {
                                            const options = JSON.parse(attribute.options);
                                            if (Array.isArray(options)) {
                                                for (let j = 0; j < options.length; j++) {
                                                    const option = options[j];
                                                    html += `<option value="${option}">${option}</option>`;
                                                }
                                            }
                                        } catch (e) {
                                            console.error('Error parsing options:', e);
                                        }
                                    }
                                    
                                    html += `</select>`;
                                    break;
                                    
                                case 'boolean':
                                    html += `
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" 
                                               id="${mode === 'edit' ? 'edit_' : ''}attribute_${attribute.id}" 
                                               name="attributes[${attribute.id}]" 
                                               value="1"
                                               ${attribute.current_value === '1' ? 'checked' : ''}>
                                        <label class="form-check-label" for="${mode === 'edit' ? 'edit_' : ''}attribute_${attribute.id}">
                                            Yes
                                        </label>
                                    </div>
                                    `;
                                    break;
                                    
                                default:
                                    html += `
                                    <input type="text" class="form-control" 
                                           id="${mode === 'edit' ? 'edit_' : ''}attribute_${attribute.id}" 
                                           name="attributes[${attribute.id}]" 
                                           ${attribute.is_required ? 'required' : ''}
                                           ${attribute.current_value ? `value="${attribute.current_value}"` : ''}>
                                    `;
                            }
                            
                            html += `</div>`;
                        }
                        
                        categoryContainer.innerHTML = html;
                    } else {
                        categoryContainer.innerHTML = '<p class="text-info mb-3"><i class="fas fa-info-circle"></i> No attributes found for this category.</p>';
                    }
                } else {
                    console.error('Invalid response format:', data);
                    categoryContainer.innerHTML = '<p class="text-danger mb-3"><i class="fas fa-exclamation-circle"></i> Error loading attributes. Please try again.</p>';
                }
            })
            .catch(error => {
                console.error('Error fetching category attributes:', error);
                categoryContainer.innerHTML = '<p class="text-danger mb-3"><i class="fas fa-exclamation-circle"></i> Error loading attributes. Please try again.</p>';
            });
    }
});