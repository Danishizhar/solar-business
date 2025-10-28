<?php
// Get all product settings
// Basic settings
$auto_generate_product_code = getInventorySetting($db, 'auto_generate_product_code', '0') == '1';
$product_code_prefix = getInventorySetting($db, 'product_code_prefix', 'P');
// Removed variant functionality
$default_product_status = getInventorySetting($db, 'default_product_status', 'active');

// Tracking settings
$enable_batch_tracking = getInventorySetting($db, 'enable_batch_tracking', '0') == '1';
$enable_expiry_tracking = getInventorySetting($db, 'enable_expiry_tracking', '0') == '1';
$enable_serial_tracking = getInventorySetting($db, 'enable_serial_tracking', '0') == '1';
$enable_stock_alerts = getInventorySetting($db, 'enable_stock_alerts', '1') == '1';

// Stock settings
$default_min_stock_level = getInventorySetting($db, 'default_min_stock_level', '5');
$default_reorder_point = getInventorySetting($db, 'default_reorder_point', '10');

// Required fields settings
$require_base_uom = getInventorySetting($db, 'require_base_uom', '0') == '1';
$require_category = getInventorySetting($db, 'require_category', '0') == '1';
$require_brand = getInventorySetting($db, 'require_brand', '0') == '1';
$require_barcode = getInventorySetting($db, 'require_barcode', '0') == '1';
$require_product_description = getInventorySetting($db, 'require_product_description', '0') == '1';
$require_product_short_description = getInventorySetting($db, 'require_product_short_description', '0') == '1';
$require_cost_price = getInventorySetting($db, 'require_cost_price', '0') == '1';
$require_selling_price = getInventorySetting($db, 'require_selling_price', '0') == '1';
$require_min_selling_price = getInventorySetting($db, 'require_min_selling_price', '0') == '1';
$require_product_dimensions = getInventorySetting($db, 'require_product_dimensions', '0') == '1';
$require_product_weight = getInventorySetting($db, 'require_product_weight', '0') == '1';
$require_product_image = getInventorySetting($db, 'require_product_image', '0') == '1';

// Image settings
$max_image_file_size = getInventorySetting($db, 'max_image_file_size', '2'); // in MB
$max_product_images = getInventorySetting($db, 'max_product_images', '5');
$allowed_image_types = getInventorySetting($db, 'allowed_image_types', 'jpg,jpeg,png,gif,webp');

// Tax settings
$products_taxable_by_default = getInventorySetting($db, 'products_taxable_by_default', '1') == '1';
$default_tax_rate = getInventorySetting($db, 'default_tax_rate', '15');
?>
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productModalTitle">Add New Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="productForm" action="actions/save_product_new.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="product_id" name="product_id" value="">
                <!-- Hidden settings fields -->
                <input type="hidden" id="auto_generate_product_code" name="auto_generate_product_code" value="<?php echo $auto_generate_product_code ? '1' : '0'; ?>">
                <input type="hidden" id="product_code_prefix" name="product_code_prefix" value="<?php echo htmlspecialchars($product_code_prefix); ?>">
                <div class="modal-body">
                    <ul class="nav nav-tabs" id="productTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic-info" type="button" role="tab" aria-controls="basic-info" aria-selected="true">Basic Info</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="pricing-tab" data-bs-toggle="tab" data-bs-target="#pricing-info" type="button" role="tab" aria-controls="pricing-info" aria-selected="false">Pricing</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#inventory-info" type="button" role="tab" aria-controls="inventory-info" aria-selected="false">Inventory</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="attributes-tab" data-bs-toggle="tab" data-bs-target="#attributes-info" type="button" role="tab" aria-controls="attributes-info" aria-selected="false">Attributes</button>
                        </li>
                    </ul>
                    
                    <div class="tab-content p-3 border border-top-0 rounded-bottom" id="productTabsContent">
                        <!-- Basic Info Tab -->
                        <div class="tab-pane fade show active" id="basic-info" role="tabpanel" aria-labelledby="basic-tab">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="product_name" class="form-label">Product Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="product_name" name="product_name" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="product_code" class="form-label">Product Code</label>
                                        <input type="text" class="form-control" id="product_code" name="product_code">
                                        <?php if ($auto_generate_product_code): ?>
                                        <div class="form-text">Leave empty for auto-generation</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="barcode" class="form-label">Barcode<?php if ($require_barcode): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                        <div class="input-group mb-2">
                                            <input type="text" class="form-control" id="barcode" name="barcode" placeholder="Enter or generate barcode" <?php if ($require_barcode): ?>required<?php endif; ?>>
                                            <button class="btn btn-outline-secondary" type="button" id="generate-barcode" title="Generate Barcode">
                                                <i class="fas fa-barcode"></i>
                                            </button>
                                        </div>
                                        <div class="btn-group w-100 mb-2">
                                            <button class="btn btn-sm btn-outline-primary" type="button" id="generate-barcode-btn">
                                                <i class="fas fa-barcode"></i> Generate
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary" type="button" id="scan-barcode" title="Scan Barcode">
                                                <i class="fas fa-camera"></i> Scan
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary" type="button" id="print-barcode" title="Print Barcode">
                                                <i class="fas fa-print"></i> Print
                                            </button>
                                        </div>
                                        <div id="barcode-preview" class="mt-2 text-center p-2 border rounded" style="display: none;"></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="category_id" class="form-label">Category<?php if ($require_category): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                        <select class="form-select" id="category_id" name="category_id" <?php if ($require_category): ?>required<?php endif; ?>>
                                            <option value="">Select Category</option>
                                            <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>">
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="brand_id" class="form-label">Brand<?php if ($require_brand): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                        <select class="form-select" id="brand_id" name="brand_id" <?php if ($require_brand): ?>required<?php endif; ?>>
                                            <option value="">Select Brand</option>
                                            <?php foreach ($brands as $brand): ?>
                                            <option value="<?php echo $brand['id']; ?>">
                                                <?php echo htmlspecialchars($brand['brand_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="product_image" class="form-label">Product Image<?php if ($require_product_image): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                        <!-- Container for displaying current image when editing -->
                                        <div id="current_image_container" class="mb-2"></div>
                                        <input type="file" class="form-control" id="product_image" name="product_image" accept="<?php echo str_replace(',', ',image/', $allowed_image_types); ?>">
                                        <div class="form-text">Recommended size: 800x800px. Max size: <?php echo $max_image_file_size; ?>MB. Allowed types: <?php echo str_replace(',', ', ', $allowed_image_types); ?></div>
                                        <div id="image-preview-container" class="mt-2 text-center" style="display: none;">
                                            <img id="image-preview" src="" alt="Product Image Preview" class="img-thumbnail" style="max-height: 200px; max-width: 100%;">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Description<?php if ($require_product_description): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                <textarea class="form-control" id="description" name="description" rows="3" <?php if ($require_product_description): ?>required<?php endif; ?>></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="short_description" class="form-label">Short Description<?php if ($require_product_short_description): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                <textarea class="form-control" id="short_description" name="short_description" rows="2" <?php if ($require_product_short_description): ?>required<?php endif; ?>></textarea>
                            </div>
                            
                            <!-- Physical Dimensions -->
                            <div class="card mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Physical Dimensions</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="weight" class="form-label">Weight<?php if ($require_product_weight): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" id="weight" name="weight" step="0.01" min="0" <?php if ($require_product_weight): ?>required<?php endif; ?>>
                                                    <span class="input-group-text">kg</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="length" class="form-label">Length<?php if ($require_product_dimensions): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" id="length" name="length" step="0.01" min="0" <?php if ($require_product_dimensions): ?>required<?php endif; ?>>
                                                    <span class="input-group-text">cm</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="width" class="form-label">Width<?php if ($require_product_dimensions): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" id="width" name="width" step="0.01" min="0" <?php if ($require_product_dimensions): ?>required<?php endif; ?>>
                                                    <span class="input-group-text">cm</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="height" class="form-label">Height<?php if ($require_product_dimensions): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" id="height" name="height" step="0.01" min="0" <?php if ($require_product_dimensions): ?>required<?php endif; ?>>
                                                    <span class="input-group-text">cm</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="is_taxable" name="is_taxable" value="1" <?php if ($products_taxable_by_default): ?>checked<?php endif; ?>>
                                <label class="form-check-label" for="is_taxable">Taxable</label>
                            </div>
                        </div>
                        
                        <!-- Pricing Tab -->
                        <div class="tab-pane fade" id="pricing-info" role="tabpanel" aria-labelledby="pricing-tab">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="cost_price" class="form-label">Cost Price<?php if ($require_cost_price): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" id="cost_price" name="cost_price" step="0.01" min="0" <?php if ($require_cost_price): ?>required<?php endif; ?>>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="selling_price" class="form-label">Selling Price<?php if ($require_selling_price): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" id="selling_price" name="selling_price" step="0.01" min="0" <?php if ($require_selling_price): ?>required<?php endif; ?>>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="min_selling_price" class="form-label">Min. Selling Price<?php if ($require_min_selling_price): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" id="min_selling_price" name="min_selling_price" step="0.01" min="0" <?php if ($require_min_selling_price): ?>required<?php endif; ?>>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="wholesale_price" class="form-label">Wholesale Price</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" id="wholesale_price" name="wholesale_price" step="0.01" min="0">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="min_wholesale_qty" class="form-label">Min. Wholesale Qty</label>
                                        <input type="number" class="form-control" id="min_wholesale_qty" name="min_wholesale_qty" min="0">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="tax_rate" class="form-label">Tax Rate (%)</label>
                                        <input type="number" class="form-control" id="tax_rate" name="tax_rate" step="0.01" min="0" max="100" value="<?php echo htmlspecialchars($default_tax_rate); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Discount field removed as per request - will be handled separately -->
                        </div>
                        
                        <!-- Inventory Tab -->
                        <div class="tab-pane fade" id="inventory-info" role="tabpanel" aria-labelledby="inventory-tab">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="base_uom_id" class="form-label">Base Unit of Measure<?php if ($require_base_uom): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                        <select class="form-select" id="base_uom_id" name="base_uom_id">
                                            <option value="">Select UOM</option>
                                            <?php foreach ($uoms as $uom): ?>
                                            <option value="<?php echo $uom['id']; ?>">
                                                <?php echo htmlspecialchars($uom['uom_name'] . ' (' . $uom['uom_symbol'] . ')'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="min_stock_level" class="form-label">Min. Stock Level</label>
                                        <input type="number" class="form-control" id="min_stock_level" name="min_stock_level" min="0" step="0.01" value="<?php echo htmlspecialchars($default_min_stock_level); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="max_stock_level" class="form-label">Max. Stock Level</label>
                                        <input type="number" class="form-control" id="max_stock_level" name="max_stock_level" min="0" step="0.01">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="reorder_point" class="form-label">Reorder Point</label>
                                        <input type="number" class="form-control" id="reorder_point" name="reorder_point" min="0" step="0.01" value="<?php echo htmlspecialchars($default_reorder_point); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="purchase_uom_id" class="form-label">Purchase UOM</label>
                                        <select class="form-select" id="purchase_uom_id" name="purchase_uom_id">
                                            <option value="">Same as Base UOM</option>
                                            <?php foreach ($uoms as $uom): ?>
                                            <option value="<?php echo $uom['id']; ?>">
                                                <?php echo htmlspecialchars($uom['uom_name'] . ' (' . $uom['uom_symbol'] . ')'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- Purchase UOM Conversion removed - will be handled separately -->
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="sales_uom_id" class="form-label">Sales UOM</label>
                                        <select class="form-select" id="sales_uom_id" name="sales_uom_id">
                                            <option value="">Same as Base UOM</option>
                                            <?php foreach ($uoms as $uom): ?>
                                            <option value="<?php echo $uom['id']; ?>">
                                                <?php echo htmlspecialchars($uom['uom_name'] . ' (' . $uom['uom_symbol'] . ')'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- Sales UOM Conversion removed - will be handled separately -->
                            </div>
                            
                            <?php if ($enable_batch_tracking || $enable_expiry_tracking || $enable_serial_tracking): ?>
                            <hr>
                            <div class="row">
                                <?php if ($enable_batch_tracking): ?>
                                <div class="col-md-4">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="track_batches" name="track_batches" value="1">
                                        <label class="form-check-label" for="track_batches">Track Batches</label>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($enable_expiry_tracking): ?>
                                <div class="col-md-4">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="track_expiry" name="track_expiry" value="1">
                                        <label class="form-check-label" for="track_expiry">Track Expiry Dates</label>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($enable_serial_tracking): ?>
                                <div class="col-md-4">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="track_serial" name="track_serial" value="1">
                                        <label class="form-check-label" for="track_serial">Track Serial Numbers</label>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($enable_stock_alerts): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="enable_stock_alerts" name="enable_stock_alerts" value="1" checked>
                                <label class="form-check-label" for="enable_stock_alerts">Enable Stock Alerts</label>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Attributes Tab -->
                        <div class="tab-pane fade" id="attributes-info" role="tabpanel" aria-labelledby="attributes-tab">

                            <!-- Variant-related message removed -->
                            
                            <div id="category-attributes-container">
                                <p class="text-info mb-3">
                                    <i class="fas fa-info-circle"></i> 
                                    Select a category to load category-specific attributes.
                                </p>
                            </div>
                            <div id="all-attributes-container">
                                <?php if (!empty($attributes)): ?>
                                <h6 class="mb-3">General Attributes</h6>
                                <?php foreach ($attributes as $attribute): ?>
                                <?php if (empty($attribute['category_ids'])): ?>
                                <div class="mb-3">
                                    <label for="attribute_<?php echo $attribute['id']; ?>" class="form-label">
                                        <?php echo htmlspecialchars($attribute['attribute_name']); ?>
                                        <?php if ($attribute['is_required']): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    
                                    <?php if ($attribute['input_type'] == 'text'): ?>
                                    <input type="text" class="form-control" id="attribute_<?php echo $attribute['id']; ?>" 
                                           name="attributes[<?php echo $attribute['id']; ?>]" 
                                           <?php echo $attribute['is_required'] ? 'required' : ''; ?>
                                           placeholder="<?php echo htmlspecialchars($attribute['attribute_name']); ?>">
                                    
                                    <?php elseif ($attribute['input_type'] == 'number'): ?>
                                    <input type="number" class="form-control" id="attribute_<?php echo $attribute['id']; ?>" 
                                           name="attributes[<?php echo $attribute['id']; ?>]" 
                                           <?php echo $attribute['is_required'] ? 'required' : ''; ?>
                                           step="any">
                                    
                                    <?php elseif ($attribute['input_type'] == 'textarea'): ?>
                                    <textarea class="form-control" id="attribute_<?php echo $attribute['id']; ?>" 
                                              name="attributes[<?php echo $attribute['id']; ?>]" 
                                              <?php echo $attribute['is_required'] ? 'required' : ''; ?>
                                              rows="3"></textarea>
                                    
                                    <?php elseif ($attribute['input_type'] == 'select' && !empty($attribute['options'])): ?>
                                    <select class="form-select" id="attribute_<?php echo $attribute['id']; ?>" 
                                            name="attributes[<?php echo $attribute['id']; ?>]" 
                                            <?php echo $attribute['is_required'] ? 'required' : ''; ?>>
                                        <option value="">Select <?php echo htmlspecialchars($attribute['attribute_name']); ?></option>
                                        <?php 
                                        $options = json_decode($attribute['options'], true);
                                        if (is_array($options)):
                                            foreach ($options as $option):
                                                // Handle both new format (value/label objects) and old format (simple strings)
                                                if (is_array($option) && isset($option['value']) && isset($option['label'])) {
                                                    $optionValue = $option['value'];
                                                    $optionLabel = $option['label'];
                                                } else {
                                                    $optionValue = $option;
                                                    $optionLabel = $option;
                                                }
                                        ?>
                                        <option value="<?php echo htmlspecialchars($optionValue); ?>"><?php echo htmlspecialchars($optionLabel); ?></option>
                                        <?php 
                                            endforeach;
                                        endif;
                                        ?>
                                    </select>
                                    
                                    <?php elseif ($attribute['input_type'] == 'multiselect' && !empty($attribute['options'])): ?>
                                    <div class="checkbox-group" id="attribute_<?php echo $attribute['id']; ?>_group"
                                         <?php echo $attribute['is_required'] ? 'data-required="true"' : ''; ?>>
                                        <?php 
                                        $options = json_decode($attribute['options'], true);
                                        if (is_array($options)):
                                            foreach ($options as $index => $option):
                                                // Handle both new format (value/label objects) and old format (simple strings)
                                                if (is_array($option) && isset($option['value']) && isset($option['label'])) {
                                                    $optionValue = $option['value'];
                                                    $optionLabel = $option['label'];
                                                } else {
                                                    $optionValue = $option;
                                                    $optionLabel = $option;
                                                }
                                        ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" 
                                                   id="attribute_<?php echo $attribute['id']; ?>_<?php echo $index; ?>" 
                                                   name="attributes[<?php echo $attribute['id']; ?>][]" 
                                                   value="<?php echo htmlspecialchars($optionValue); ?>">
                                            <label class="form-check-label" for="attribute_<?php echo $attribute['id']; ?>_<?php echo $index; ?>">
                                                <?php echo htmlspecialchars($optionLabel); ?>
                                            </label>
                                        </div>
                                        <?php 
                                            endforeach;
                                        endif;
                                        ?>
                                    </div>
                                    
                                    <?php elseif ($attribute['input_type'] == 'boolean'): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" id="attribute_<?php echo $attribute['id']; ?>_yes" 
                                               name="attributes[<?php echo $attribute['id']; ?>]" value="1">
                                        <label class="form-check-label" for="attribute_<?php echo $attribute['id']; ?>_yes">
                                            Yes
                                        </label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" id="attribute_<?php echo $attribute['id']; ?>_no" 
                                               name="attributes[<?php echo $attribute['id']; ?>]" value="0">
                                        <label class="form-check-label" for="attribute_<?php echo $attribute['id']; ?>_no">
                                            No
                                        </label>
                                    </div>
                                    
                                    <?php elseif ($attribute['input_type'] == 'date'): ?>
                                    <input type="date" class="form-control" id="attribute_<?php echo $attribute['id']; ?>" 
                                           name="attributes[<?php echo $attribute['id']; ?>]" 
                                           <?php echo $attribute['is_required'] ? 'required' : ''; ?>>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($attribute['description'])): ?>
                                    <div class="form-text"><?php echo htmlspecialchars($attribute['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveProductBtn">Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>
