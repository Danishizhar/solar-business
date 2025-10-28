-- Add Stock Settings to inventory_settings table
-- Created: April 23, 2025

-- Stock Movement Control Settings

-- Require Approval for Stock Adjustments
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'require_approval_for_adjustments', 
    '0', 
    'stock', 
    'Require Approval for Stock Adjustments', 
    'Require manager approval before stock adjustments are processed', 
    'checkbox', 
    NULL, 
    10,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'require_approval_for_adjustments'
);

-- Allow Negative Stock Adjustments
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'allow_negative_stock_adjustments', 
    '0', 
    'stock', 
    'Allow Negative Stock Adjustments', 
    'Allow stock to be adjusted to negative values', 
    'checkbox', 
    NULL, 
    20,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'allow_negative_stock_adjustments'
);

-- Default Adjustment Reason Options
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'default_adjustment_reasons', 
    'Damaged,Expired,Inventory Count,Lost,Found,Theft,Return,Quality Issue', 
    'stock', 
    'Default Adjustment Reason Options', 
    'Comma-separated list of common adjustment reasons', 
    'textarea', 
    NULL, 
    30,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'default_adjustment_reasons'
);

-- Stock Transaction Settings

-- Auto-Generate Transaction Numbers
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'auto_generate_transaction_numbers', 
    '1', 
    'stock', 
    'Auto-Generate Transaction Numbers', 
    'Automatically generate transaction numbers for stock movements', 
    'checkbox', 
    NULL, 
    40,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'auto_generate_transaction_numbers'
);

-- Transaction Number Prefix
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'transaction_number_prefix', 
    'STKTX-', 
    'stock', 
    'Transaction Number Prefix', 
    'Prefix to use when auto-generating transaction numbers', 
    'text', 
    NULL, 
    50,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'transaction_number_prefix'
);

-- Default Transaction Type
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'default_transaction_type', 
    'Adjustment', 
    'stock', 
    'Default Transaction Type', 
    'Default type for new stock transactions', 
    'select', 
    'Purchase,Sale,Adjustment,Transfer,Return,Scrap,Count', 
    60,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'default_transaction_type'
);

-- Require Notes for Transactions
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'require_notes_for_transactions', 
    '0', 
    'stock', 
    'Require Notes for Transactions', 
    'Make notes mandatory for all stock transactions', 
    'checkbox', 
    NULL, 
    70,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'require_notes_for_transactions'
);

-- Stock Counting Settings

-- Stock Count Frequency
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'stock_count_frequency', 
    'Monthly', 
    'stock', 
    'Stock Count Frequency', 
    'Recommended frequency for physical inventory counts', 
    'select', 
    'Weekly,Monthly,Quarterly,Annually', 
    80,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'stock_count_frequency'
);

-- Stock Count Threshold
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'stock_count_threshold', 
    '1000', 
    'stock', 
    'Stock Count Threshold', 
    'Value threshold that requires physical count verification', 
    'number', 
    NULL, 
    90,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'stock_count_threshold'
);

-- Auto-Generate Count Sheets
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'auto_generate_count_sheets', 
    '1', 
    'stock', 
    'Auto-Generate Count Sheets', 
    'Automatically generate count sheets for inventory counts', 
    'checkbox', 
    NULL, 
    100,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'auto_generate_count_sheets'
);

-- Stock Alert Settings

-- Low Stock Alert Threshold
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'low_stock_alert_threshold', 
    '10', 
    'stock', 
    'Low Stock Alert Threshold', 
    'Percentage below reorder point to trigger low stock alerts', 
    'number', 
    NULL, 
    110,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'low_stock_alert_threshold'
);

-- Overstock Alert Threshold
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'overstock_alert_threshold', 
    '20', 
    'stock', 
    'Overstock Alert Threshold', 
    'Percentage above maximum level to trigger overstock alerts', 
    'number', 
    NULL, 
    120,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'overstock_alert_threshold'
);

-- Stock Alert Recipients
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'stock_alert_recipients', 
    'inventory_manager,warehouse_manager', 
    'stock', 
    'Stock Alert Recipients', 
    'Roles or email addresses to receive stock alerts (comma-separated)', 
    'text', 
    NULL, 
    130,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'stock_alert_recipients'
);

-- Alert Notification Method
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'alert_notification_method', 
    'System', 
    'stock', 
    'Alert Notification Method', 
    'Method for sending stock alert notifications', 
    'select', 
    'Email,System,Both', 
    140,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'alert_notification_method'
);

-- Batch and Expiry Settings

-- Default Batch Naming Format
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'default_batch_naming_format', 
    'BATCH-{PRODUCT_CODE}-{DATE}', 
    'stock', 
    'Default Batch Naming Format', 
    'Format for auto-generated batch names. Use {PRODUCT_CODE}, {DATE}, {COUNTER} as placeholders', 
    'text', 
    NULL, 
    150,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'default_batch_naming_format'
);

-- FIFO/FEFO Enforcement
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'inventory_flow_method', 
    'FIFO', 
    'stock', 
    'Inventory Flow Method', 
    'Method for inventory flow (FIFO: First-In-First-Out, FEFO: First-Expired-First-Out)', 
    'select', 
    'FIFO,FEFO,LIFO,Average', 
    160,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'inventory_flow_method'
);

-- Expiry Warning Period
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'expiry_warning_period', 
    '30', 
    'stock', 
    'Expiry Warning Period', 
    'Days before expiry to show warnings', 
    'number', 
    NULL, 
    170,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'expiry_warning_period'
);

-- Hide Expired Items
INSERT INTO inventory_settings (
    setting_key, 
    setting_value, 
    setting_group, 
    display_name, 
    description, 
    input_type, 
    options, 
    display_order,
    status,
    created_at,
    updated_at
)
SELECT 
    'hide_expired_items', 
    '0', 
    'stock', 
    'Hide Expired Items', 
    'Hide expired items from stock listings', 
    'checkbox', 
    NULL, 
    180,
    1,
    NOW(),
    NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM inventory_settings WHERE setting_key = 'hide_expired_items'
);
