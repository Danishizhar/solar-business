/**
 * Products Management JavaScript
 * This file handles the functionality for the products page
 */

$(document).ready(function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Add custom validation for checkbox groups (multiselect attributes)
    function validateCheckboxGroups() {
        $('.checkbox-group[data-required="true"]').each(function() {
            var group = $(this);
            var groupId = group.attr('id');
            var checkboxes = group.find('input[type="checkbox"]');
            var isValid = checkboxes.is(':checked');
            
            // Add validation message if needed
            if (!isValid && !group.next('.invalid-feedback').length) {
                group.after('<div class="invalid-feedback" style="display: block;">Please select at least one option</div>');
            } else if (isValid && group.next('.invalid-feedback').length) {
                group.next('.invalid-feedback').remove();
            }
            
            // Mark checkboxes as invalid if none are checked
            checkboxes.toggleClass('is-invalid', !isValid);
        });
    }
    
    // Add event listener for checkbox changes
    $(document).on('change', '.checkbox-group input[type="checkbox"]', function() {
        var group = $(this).closest('.checkbox-group');
        if (group.attr('data-required') === 'true') {
            var isValid = group.find('input[type="checkbox"]').is(':checked');
            group.find('input[type="checkbox"]').toggleClass('is-invalid', !isValid);
            
            if (isValid && group.next('.invalid-feedback').length) {
                group.next('.invalid-feedback').remove();
            } else if (!isValid && !group.next('.invalid-feedback').length) {
                group.after('<div class="invalid-feedback" style="display: block;">Please select at least one option</div>');
            }
        }
    });
    
    // Add form submission validation
    $('#productForm').on('submit', function(e) {
        validateCheckboxGroups();
        
        // Prevent form submission if there are invalid checkbox groups
        if ($('.checkbox-group .is-invalid').length > 0) {
            e.preventDefault();
            return false;
        }
    });
    
    // Auto-generate product code if setting is enabled
    if ($('#auto_generate_product_code').val() === '1') {
        $('#product_code').prop('readonly', true);
        
        // Generate code when product name changes
        $('#product_name').on('blur', function() {
            if ($(this).val().trim() !== '') {
                generateProductCode();
            }
        });
    }
    
    // Function to generate product code
    function generateProductCode() {
        var prefix = $('#product_code_prefix').val() || 'P';
        var productName = $('#product_name').val().trim();
        
        if (productName !== '') {
            $.ajax({
                url: 'actions/generate_product_code.php',
                type: 'POST',
                data: {
                    prefix: prefix,
                    product_name: productName
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#product_code').val(response.product_code);
                    }
                }
            });
        }
    }
    
    // Function to load category attributes
    function loadCategoryAttributes(categoryId) {
        var containerSelector = '#category-attributes-container';
        var allAttributesSelector = '#all-attributes-container';
        
        // Always show all attributes container regardless of category selection
        $(allAttributesSelector).show();
        
        if (categoryId) {
            // Load category attributes via AJAX
            $.ajax({
                url: 'actions/get_category_attributes.php',
                type: 'GET',
                data: {
                    category_id: categoryId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Clear previous attributes
                        $(containerSelector).empty();
                        
                        if (response.html) {
                            // Use the pre-generated HTML from the server
                            $(containerSelector).html(response.html);
                        } else if (response.attributes && response.attributes.length > 0) {
                            // Fallback to client-side generation if HTML is not provided
                            response.attributes.forEach(function(attribute) {
                                var attributeHtml = generateAttributeHtml(attribute);
                                $(containerSelector).append(attributeHtml);
                            });
                        } else {
                            $(containerSelector).html('<p class="text-muted">No category-specific attributes found</p>');
                        }
                    } else {
                        $(containerSelector).html('<p class="text-danger">Error loading attributes: ' + response.message + '</p>');
                    }
                },
                error: function() {
                    $(containerSelector).html('<p class="text-danger">Failed to load category attributes</p>');
                }
            });
        } else {
            // No category selected, clear attributes
            $(containerSelector).empty();
        }
    }
    
    // Category selection - load attributes for add product
    $('#category_id').change(function() {
        var categoryId = $(this).val();
        loadCategoryAttributes(categoryId);
        
        // Toggle required attribute on category-specific attributes
        if (categoryId) {
            // Make category attributes required when category is selected
            $('.category-specific-attribute').prop('required', true);
            $('.category-required-indicator').show();
        } else {
            // Make category attributes optional when no category is selected
            $('.category-specific-attribute').prop('required', false);
            $('.category-required-indicator').hide();
        }
    });
    
    // Add new product button click
    $('#addNewProductBtn').click(function() {
        // Reset form
        if ($('#productForm').length) {
            $('#productForm')[0].reset();
        }
        
        // Clear any previous data
        $('#category-attributes-container').empty();
        $('#variants-container').hide();
        $('#current_image_container').empty();
        $('#image-preview-container').hide();
        $('#image-preview').attr('src', '');
        
        // Set default values
        $('#product_id').val('');
        $('#productForm').attr('action', 'actions/save_product_new.php');
        $('#productModalTitle').text('Add New Product');
        $('#saveProductBtn').text('Save Product');
    });
    
    // Image preview functionality
    $('#product_image').change(function() {
        var file = this.files[0];
        var imagePreview = $('#image-preview');
        var imagePreviewContainer = $('#image-preview-container');
        
        if (file) {
            // Check if the file is an image
            if (file.type.match('image.*')) {
                var reader = new FileReader();
                
                reader.onload = function(e) {
                    imagePreview.attr('src', e.target.result);
                    imagePreviewContainer.show();
                }
                
                reader.readAsDataURL(file);
            } else {
                // Not an image file, hide the preview
                imagePreviewContainer.hide();
                imagePreview.attr('src', '');
            }
        } else {
            // No file selected, hide the preview
            imagePreviewContainer.hide();
            imagePreview.attr('src', '');
        }
    });
    
    // Variant-related function removed
    
    // Function to generate HTML for category attributes
    function generateAttributeHtml(attribute) {
        var html = '<div class="mb-3" data-attribute-id="' + attribute.id + '">';
        html += '<label class="form-label">' + attribute.name;
        if (attribute.is_required == 1) {
            html += ' <span class="text-danger">*</span>';
        }
        html += '</label>';
        
        // Different input types based on attribute type
        switch(attribute.input_type) {
            case 'text':
                html += '<input type="text" class="form-control" name="attributes[' + attribute.id + ']"';
                if (attribute.is_required == 1) {
                    html += ' required';
                }
                html += '>';
                break;
                
            case 'textarea':
                html += '<textarea class="form-control" name="attributes[' + attribute.id + ']"';
                if (attribute.is_required == 1) {
                    html += ' required';
                }
                html += '></textarea>';
                break;
                
            case 'select':
                html += '<select class="form-control" name="attributes[' + attribute.id + ']"';
                if (attribute.is_required == 1) {
                    html += ' required';
                }
                html += '>';
                html += '<option value="">Select ' + attribute.name + '</option>';
                
                if (attribute.options) {
                    var options = attribute.options.split(',');
                    options.forEach(function(option) {
                        option = option.trim();
                        html += '<option value="' + option + '">' + option + '</option>';
                    });
                }
                
                html += '</select>';
                break;
                
            case 'multiselect':
                html += '<select class="form-control" name="attributes[' + attribute.id + '][]" multiple';
                if (attribute.is_required == 1) {
                    html += ' required';
                }
                html += '>';
                
                if (attribute.options) {
                    var options = attribute.options.split(',');
                    options.forEach(function(option) {
                        option = option.trim();
                        html += '<option value="' + option + '">' + option + '</option>';
                    });
                }
                
                html += '</select>';
                break;
                
            case 'boolean':
                html += '<div class="form-check">';
                html += '<input type="checkbox" class="form-check-input" id="attr_' + attribute.id + '" name="attributes[' + attribute.id + ']" value="1">';
                html += '<label class="form-check-label" for="attr_' + attribute.id + '">Yes</label>';
                html += '</div>';
                break;
                
            case 'date':
                html += '<input type="date" class="form-control" name="attributes[' + attribute.id + ']"';
                if (attribute.is_required == 1) {
                    html += ' required';
                }
                html += '>';
                break;
                
            case 'number':
                html += '<input type="number" class="form-control" name="attributes[' + attribute.id + ']"';
                if (attribute.is_required == 1) {
                    html += ' required';
                }
                html += '>';
                break;
                
            default:
                html += '<input type="text" class="form-control" name="attributes[' + attribute.id + ']"';
                if (attribute.is_required == 1) {
                    html += ' required';
                }
                html += '>';
        }
        
        // Add description if available
        if (attribute.description) {
            html += '<small class="form-text text-muted">' + attribute.description + '</small>';
        }
        
        html += '</div>';
        return html;
    }
    
    // Edit Product functionality
    $(document).on('click', '.edit-product', function() {
        var productId = $(this).data('id');
        
        // Reset form first
        if ($('#productForm').length) {
            $('#productForm')[0].reset();
        }
        
        // Clear any previous data
        $('#category-attributes-container').empty();
        $('#variants-container').hide();
        $('#current_image_container').empty();
        $('#image-preview-container').hide();
        $('#image-preview').attr('src', '');
        
        // Set modal title and button text for edit mode
        $('#productModalTitle').text('Edit Product');
        $('#saveProductBtn').text('Update Product');
        
        // Set form action for edit mode
        $('#productForm').attr('action', 'actions/save_product.php');
        
        // Load product data via AJAX
        $.ajax({
            url: 'actions/get_product_data.php',
            type: 'GET',
            data: {
                product_id: productId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var product = response.product;
                    
                    // Set hidden product ID field
                    $('#product_id').val(product.id);
                    
                    // Basic Info tab
                    $('#product_name').val(product.product_name);
                    $('#product_code').val(product.product_code);
                    $('#barcode').val(product.barcode);
                    $('#category_id').val(product.category_id);
                    $('#brand_id').val(product.brand_id);
                    $('#description').val(product.description);
                    $('#short_description').val(product.short_description);
                    
                    // Physical dimensions
                    $('#weight').val(product.weight);
                    $('#length').val(product.length);
                    $('#width').val(product.width);
                    $('#height').val(product.height);
                    
                    // Min. Wholesale Qty
                    if ($('#min_wholesale_qty').length) {
                        $('#min_wholesale_qty').val(product.min_wholesale_qty);
                    }
                    
                    // If product has an image, show it
                    if (product.image_path) {
                        // Log the image path for debugging
                        console.log('Product image path:', product.image_path);
                        
                        // Fix the image URL path
                        var imageUrl;
                        if (product.image_path.startsWith('uploads/')) {
                            // If the path already starts with 'uploads/', prepend '../../'
                            imageUrl = '../../' + product.image_path;
                        } else if (product.image_path.startsWith('/uploads/')) {
                            // If the path starts with '/uploads/', remove the leading slash and prepend '../../'
                            imageUrl = '../../' + product.image_path.substring(1);
                        } else {
                            // Otherwise, use the path as is
                            imageUrl = product.image_path;
                        }
                        
                        console.log('Image URL:', imageUrl);
                        
                        // Show the image
                        $('#current_image_container').html('<div class="mb-3"><label class="form-label">Current Image</label><div><img src="' + imageUrl + '" class="img-thumbnail" style="max-height: 150px;"></div></div>');
                        
                        // Also set a hidden input to keep track of the current image
                        if (!$('#current_image_path').length) {
                            $('#productForm').append('<input type="hidden" id="current_image_path" name="current_image_path" value="' + product.image_path + '">');
                        } else {
                            $('#current_image_path').val(product.image_path);
                        }
                    }
                    
                    // Pricing tab
                    $('#cost_price').val(product.cost_price);
                    $('#selling_price').val(product.selling_price);
                    $('#min_selling_price').val(product.min_selling_price);
                    $('#wholesale_price').val(product.wholesale_price);
                    $('#min_wholesale_qty').val(product.min_wholesale_qty);
                    $('#is_taxable').prop('checked', product.is_taxable == 1);
                    $('#tax_rate').val(product.tax_rate);
                    
                    // Log wholesale values for debugging
                    console.log('Wholesale values:', {
                        wholesale_price: product.wholesale_price,
                        min_wholesale_qty: product.min_wholesale_qty
                    });
                    
                    // Inventory tab
                    $('#base_uom_id').val(product.base_uom_id);
                    $('#purchase_uom_id').val(product.purchase_uom_id);
                    $('#sales_uom_id').val(product.sales_uom_id);
                    $('#min_stock_level').val(product.min_stock_level);
                    $('#reorder_point').val(product.reorder_point);
                    $('#max_stock_level').val(product.max_stock_level);
                    $('#is_service').prop('checked', product.is_service == 1);
                    $('#is_active').prop('checked', product.is_active == 1);
                    $('#track_batches').prop('checked', product.track_batches == 1);
                    $('#track_expiry').prop('checked', product.track_expiry == 1);
                    $('#track_serial').prop('checked', product.track_serial == 1);
                    $('#enable_stock_alerts').prop('checked', product.enable_stock_alerts == 1);
                    
                    // Log UOM values for debugging
                    console.log('UOM values:', {
                        base_uom_id: product.base_uom_id,
                        purchase_uom_id: product.purchase_uom_id,
                        sales_uom_id: product.sales_uom_id
                    });
                    
                    // Load category attributes if category is selected
                    if (product.category_id) {
                        loadCategoryAttributes(product.category_id);
                        
                        // Set a timeout to populate attribute values after attributes are loaded
                        setTimeout(function() {
                            if (response.attributes) {
                                $.each(response.attributes, function(attributeId, value) {
                                    // Handle different attribute types
                                    var attributeField = $('[name="attributes[' + attributeId + ']"]');
                                    var multiSelectField = $('[name="attributes[' + attributeId + '][]"]'); // Note the [] for multiselect
                                    
                                    if (attributeField.length) {
                                        if (attributeField.is(':checkbox')) {
                                            // Boolean attribute
                                            attributeField.prop('checked', value == 1);
                                        } else {
                                            // Regular text/select attribute
                                            attributeField.val(value);
                                        }
                                    } else if (multiSelectField.length) {
                                        // Handle multiselect attributes
                                        try {
                                            // Skip if the value contains 'Array' string representation
                                            if (typeof value === 'string' && value.indexOf('Array') === 0) {
                                                console.log('Skipping PHP array string representation:', value);
                                                // Skip this iteration by using an early return instead of continue
                                                return;
                                            }
                                            
                                            // Try to parse JSON if it's a string
                                            var parsedValue;
                                            if (typeof value === 'string') {
                                                // Check if it's already a valid JSON string
                                                try {
                                                    parsedValue = JSON.parse(value);
                                                } catch (jsonError) {
                                                    // If it's not valid JSON, use it as a single value
                                                    parsedValue = [value];
                                                }
                                            } else {
                                                // If it's not a string, use it directly
                                                parsedValue = value;
                                            }
                                            
                                            // Set the value to the multiselect field
                                            if (Array.isArray(parsedValue)) {
                                                multiSelectField.val(parsedValue);
                                            } else {
                                                // If it's not an array, try to use it as a single value
                                                multiSelectField.val([parsedValue]);
                                            }
                                        } catch (e) {
                                            console.error('Error handling multiselect value:', e);
                                            // If all else fails, leave the field empty
                                        }
                                    }
                                });
                            }
                        }, 500);
                    }
                } else {
                    alert('Error loading product data: ' + response.message);
                }
            },
            error: function() {
                alert('Failed to load product data. Please try again.');
            }
        });
    });
    
    // Delete Product - Set product data
    $('.delete-product').click(function() {
        var productId = $(this).data('id');
        var productName = $(this).data('name');
        
        $('#delete_product_id').val(productId);
        $('#delete_product_name').text(productName);
    });
    
    // Form validation before submission
    $('#addProductModal form').submit(function(e) {
        var isValid = true;
        var errorMessage = '';
        
        // Check required fields
        $(this).find('[required]').each(function() {
            if ($(this).val().trim() === '') {
                isValid = false;
                $(this).addClass('is-invalid');
                errorMessage = 'Please fill in all required fields.';
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        // Variant functionality removed
        
        // UOM validation removed - will be handled separately
        
        // Validate pricing
        var costPrice = parseFloat($('#cost_price').val()) || 0;
        var sellingPrice = parseFloat($('#selling_price').val()) || 0;
        var minSellingPrice = parseFloat($('#min_selling_price').val()) || 0;
        
        if (minSellingPrice > sellingPrice && minSellingPrice > 0 && sellingPrice > 0) {
            isValid = false;
            $('#min_selling_price').addClass('is-invalid');
            errorMessage = 'Minimum selling price cannot be greater than selling price.';
            $('#pricing-tab').tab('show');
        }
        
        // Show error message if validation fails
        if (!isValid) {
            e.preventDefault();
            alert(errorMessage);
        }
    });
});
