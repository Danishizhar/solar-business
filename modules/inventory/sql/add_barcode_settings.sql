-- Add barcode format settings to inventory_settings table

-- Check if default_barcode_format setting exists
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order
)
SELECT 
    'default_barcode_format', 
    'EAN13', 
    'barcode', 
    'Default Barcode Format', 
    'The default format to use when generating barcodes', 
    'select', 
    'EAN13,CODE128,CODE39,UPC,EAN8', 
    10
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'default_barcode_format'
);

-- Check if barcode_prefix setting exists
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order
)
SELECT 
    'barcode_prefix', 
    '', 
    'barcode', 
    'Barcode Prefix', 
    'Optional prefix to add to automatically generated barcodes (e.g., country code)', 
    'text', 
    NULL, 
    20
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'barcode_prefix'
);

-- Check if auto_generate_barcode setting exists
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order
)
SELECT 
    'auto_generate_barcode', 
    '0', 
    'barcode', 
    'Auto-Generate Barcodes', 
    'Automatically generate barcodes for new products', 
    'checkbox', 
    NULL, 
    30
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'auto_generate_barcode'
);

-- Check if barcode_label_size setting exists
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order
)
SELECT 
    'barcode_label_size', 
    'medium', 
    'barcode', 
    'Default Label Size', 
    'Default size for barcode labels', 
    'select', 
    'small,medium,large,a4-30,a4-24,a4-16', 
    40
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'barcode_label_size'
);

-- Check if barcode_label_style setting exists
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order
)
SELECT 
    'barcode_label_style', 
    'basic', 
    'barcode', 
    'Default Label Style', 
    'Default style for barcode labels', 
    'select', 
    'basic,compact,detailed,minimal', 
    50
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'barcode_label_style'
);
