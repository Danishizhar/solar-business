-- Inventory Module Database Schema
-- Created: April 10, 2025

-- Settings table for inventory module configuration
CREATE TABLE IF NOT EXISTS `inventory_settings` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT,
    `setting_group` VARCHAR(50) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `input_type` VARCHAR(20) DEFAULT 'text', -- text, number, checkbox, select, etc.
    `options` TEXT, -- JSON for select options
    `is_required` TINYINT(1) DEFAULT 0,
    `display_order` INT DEFAULT 0,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product categories
CREATE TABLE IF NOT EXISTS `product_categories` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `parent_id` INT DEFAULT NULL,
    `category_name` VARCHAR(100) NOT NULL,
    `category_code` VARCHAR(20),
    `description` TEXT,
    `image_path` VARCHAR(255),
    `status` TINYINT(1) DEFAULT 1,
    `display_order` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    FOREIGN KEY (`parent_id`) REFERENCES `product_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Units of measurement
CREATE TABLE IF NOT EXISTS `uom` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `uom_name` VARCHAR(50) NOT NULL,
    `uom_symbol` VARCHAR(10) NOT NULL,
    `uom_type` VARCHAR(20) NOT NULL, -- length, weight, volume, unit, time, etc.
    `description` TEXT,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_uom_symbol` (`uom_symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- UOM conversion
CREATE TABLE IF NOT EXISTS `uom_conversion` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `from_uom_id` INT NOT NULL,
    `to_uom_id` INT NOT NULL,
    `conversion_factor` DECIMAL(15,5) NOT NULL,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    FOREIGN KEY (`from_uom_id`) REFERENCES `uom`(`id`),
    FOREIGN KEY (`to_uom_id`) REFERENCES `uom`(`id`),
    UNIQUE KEY `unique_conversion` (`from_uom_id`, `to_uom_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Brands
CREATE TABLE IF NOT EXISTS `brands` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `brand_name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `logo_path` VARCHAR(255),
    `website` VARCHAR(255),
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_brand_name` (`brand_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Products master table
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `product_name` VARCHAR(255) NOT NULL,
    `product_code` VARCHAR(50) NOT NULL,
    `barcode` VARCHAR(50),
    `category_id` INT,
    `brand_id` INT,
    `description` TEXT,
    `short_description` VARCHAR(255),
    `base_uom_id` INT,
    `purchase_uom_id` INT,
    `sales_uom_id` INT,
    `cost_price` DECIMAL(15,2) DEFAULT 0,
    `selling_price` DECIMAL(15,2) DEFAULT 0,
    `min_selling_price` DECIMAL(15,2) DEFAULT 0,
    `tax_rate` DECIMAL(5,2) DEFAULT 0,
    `is_taxable` TINYINT(1) DEFAULT 1,
    `min_stock_level` DECIMAL(15,2) DEFAULT 0,
    `reorder_point` DECIMAL(15,2) DEFAULT 0,
    `max_stock_level` DECIMAL(15,2) DEFAULT 0,
    `is_service` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `has_variants` TINYINT(1) DEFAULT 0,
    `is_variant` TINYINT(1) DEFAULT 0,
    `parent_product_id` INT DEFAULT NULL,
    `image_path` VARCHAR(255),
    `weight` DECIMAL(10,2),
    `length` DECIMAL(10,2),
    `width` DECIMAL(10,2),
    `height` DECIMAL(10,2),
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_product_code` (`product_code`),
    FOREIGN KEY (`category_id`) REFERENCES `product_categories`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`base_uom_id`) REFERENCES `uom`(`id`),
    FOREIGN KEY (`purchase_uom_id`) REFERENCES `uom`(`id`),
    FOREIGN KEY (`sales_uom_id`) REFERENCES `uom`(`id`),
    FOREIGN KEY (`parent_product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product attributes definition
CREATE TABLE IF NOT EXISTS `attribute_definitions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `attribute_name` VARCHAR(100) NOT NULL,
    `attribute_code` VARCHAR(50) NOT NULL,
    `description` TEXT,
    `input_type` VARCHAR(20) NOT NULL, -- text, number, select, multiselect, boolean, date, etc.
    `is_required` TINYINT(1) DEFAULT 0,
    `is_unique` TINYINT(1) DEFAULT 0,
    `is_variant` TINYINT(1) DEFAULT 0, -- Whether this attribute can be used for variants
    `validation_rules` TEXT, -- JSON with validation rules
    `default_value` TEXT,
    `options` TEXT, -- JSON array of options for select/multiselect
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_attribute_code` (`attribute_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Category attribute mapping
CREATE TABLE IF NOT EXISTS `category_attributes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `category_id` INT NOT NULL,
    `attribute_id` INT NOT NULL,
    `is_required` TINYINT(1) DEFAULT 0,
    `display_order` INT DEFAULT 0,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_category_attribute` (`category_id`, `attribute_id`),
    FOREIGN KEY (`category_id`) REFERENCES `product_categories`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`attribute_id`) REFERENCES `attribute_definitions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product attribute values
CREATE TABLE IF NOT EXISTS `product_attributes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `product_id` INT NOT NULL,
    `attribute_id` INT NOT NULL,
    `attribute_value` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_product_attribute` (`product_id`, `attribute_id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`attribute_id`) REFERENCES `attribute_definitions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product images
CREATE TABLE IF NOT EXISTS `product_images` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `product_id` INT NOT NULL,
    `image_path` VARCHAR(255) NOT NULL,
    `is_primary` TINYINT(1) DEFAULT 0,
    `display_order` INT DEFAULT 0,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Warehouses/Locations
CREATE TABLE IF NOT EXISTS `inventory_locations` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `location_name` VARCHAR(100) NOT NULL,
    `location_code` VARCHAR(20) NOT NULL,
    `parent_id` INT DEFAULT NULL,
    `branch_id` INT DEFAULT NULL,
    `location_type` VARCHAR(20) DEFAULT 'warehouse', -- warehouse, zone, aisle, rack, shelf, bin
    `address` TEXT,
    `contact_person` VARCHAR(100),
    `contact_number` VARCHAR(20),
    `email` VARCHAR(100),
    `is_default` TINYINT(1) DEFAULT 0,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_location_code` (`location_code`),
    FOREIGN KEY (`parent_id`) REFERENCES `inventory_locations`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Current inventory stock
CREATE TABLE IF NOT EXISTS `inventory_stock` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `product_id` INT NOT NULL,
    `location_id` INT NOT NULL,
    `batch_number` VARCHAR(50),
    `expiry_date` DATE,
    `quantity` DECIMAL(15,2) DEFAULT 0,
    `reserved_quantity` DECIMAL(15,2) DEFAULT 0,
    `available_quantity` DECIMAL(15,2) DEFAULT 0,
    `unit_cost` DECIMAL(15,2) DEFAULT 0,
    `total_value` DECIMAL(15,2) DEFAULT 0,
    `last_counted_date` DATETIME,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_product_location_batch` (`product_id`, `location_id`, `batch_number`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`location_id`) REFERENCES `inventory_locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory transaction types
CREATE TABLE IF NOT EXISTS `inventory_transaction_types` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `transaction_type_code` VARCHAR(20) NOT NULL,
    `transaction_type_name` VARCHAR(100) NOT NULL,
    `affects_stock` TINYINT(1) DEFAULT 1,
    `stock_effect` TINYINT DEFAULT 0, -- 1: increase, -1: decrease, 0: no effect
    `description` TEXT,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_transaction_type_code` (`transaction_type_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory transactions
CREATE TABLE IF NOT EXISTS `inventory_transactions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `transaction_number` VARCHAR(50) NOT NULL,
    `transaction_date` DATETIME NOT NULL,
    `transaction_type_id` INT NOT NULL,
    `reference_type` VARCHAR(50), -- PO, SO, Transfer, Adjustment, etc.
    `reference_id` INT, -- ID of the referenced document
    `notes` TEXT,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_transaction_number` (`transaction_number`),
    FOREIGN KEY (`transaction_type_id`) REFERENCES `inventory_transaction_types`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory transaction details
CREATE TABLE IF NOT EXISTS `inventory_transaction_details` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `transaction_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `from_location_id` INT,
    `to_location_id` INT,
    `quantity` DECIMAL(15,2) NOT NULL,
    `uom_id` INT NOT NULL,
    `unit_cost` DECIMAL(15,2),
    `total_cost` DECIMAL(15,2),
    `batch_number` VARCHAR(50),
    `expiry_date` DATE,
    `notes` TEXT,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    FOREIGN KEY (`transaction_id`) REFERENCES `inventory_transactions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    FOREIGN KEY (`from_location_id`) REFERENCES `inventory_locations`(`id`),
    FOREIGN KEY (`to_location_id`) REFERENCES `inventory_locations`(`id`),
    FOREIGN KEY (`uom_id`) REFERENCES `uom`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory adjustments
CREATE TABLE IF NOT EXISTS `inventory_adjustments` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `adjustment_number` VARCHAR(50) NOT NULL,
    `adjustment_date` DATETIME NOT NULL,
    `location_id` INT NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `notes` TEXT,
    `status` TINYINT(1) DEFAULT 0, -- 0: draft, 1: posted
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    UNIQUE KEY `unique_adjustment_number` (`adjustment_number`),
    FOREIGN KEY (`location_id`) REFERENCES `inventory_locations`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory adjustment details
CREATE TABLE IF NOT EXISTS `inventory_adjustment_details` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `adjustment_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `current_quantity` DECIMAL(15,2) NOT NULL,
    `new_quantity` DECIMAL(15,2) NOT NULL,
    `difference` DECIMAL(15,2) NOT NULL,
    `unit_cost` DECIMAL(15,2),
    `total_cost` DECIMAL(15,2),
    `batch_number` VARCHAR(50),
    `expiry_date` DATE,
    `notes` TEXT,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    FOREIGN KEY (`adjustment_id`) REFERENCES `inventory_adjustments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default inventory transaction types
INSERT INTO `inventory_transaction_types` 
(`transaction_type_code`, `transaction_type_name`, `affects_stock`, `stock_effect`, `description`, `status`, `created_by`) VALUES
('GRN', 'Goods Receipt', 1, 1, 'Receipt of goods into inventory', 1, 1),
('GIN', 'Goods Issue', 1, -1, 'Issue of goods from inventory', 1, 1),
('TRF', 'Transfer', 1, 0, 'Transfer between locations', 1, 1),
('ADJ', 'Adjustment', 1, 0, 'Inventory adjustment', 1, 1),
('RET', 'Return', 1, 1, 'Return to inventory', 1, 1),
('SRET', 'Sales Return', 1, 1, 'Return from customer', 1, 1),
('PRET', 'Purchase Return', 1, -1, 'Return to supplier', 1, 1);

-- Insert default inventory settings
INSERT INTO `inventory_settings` 
(`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `is_required`, `status`, `created_by`) VALUES
('enable_batch_tracking', '0', 'general', 'Enable Batch Tracking', 'Track inventory by batch/lot numbers', 'checkbox', 0, 1, 1),
('enable_expiry_tracking', '0', 'general', 'Enable Expiry Tracking', 'Track expiry dates for inventory items', 'checkbox', 0, 1, 1),
('enable_serial_tracking', '0', 'general', 'Enable Serial Number Tracking', 'Track inventory by serial numbers', 'checkbox', 0, 1, 1),
('default_valuation_method', 'average', 'valuation', 'Default Valuation Method', 'Method used to value inventory', 'select', 1, 1, 1),
('allow_negative_inventory', '0', 'general', 'Allow Negative Inventory', 'Allow stock levels to go below zero', 'checkbox', 0, 1, 1),
('auto_generate_product_code', '1', 'product', 'Auto Generate Product Code', 'Automatically generate product codes', 'checkbox', 0, 1, 1),
('product_code_prefix', 'P', 'product', 'Product Code Prefix', 'Prefix for auto-generated product codes', 'text', 0, 1, 1),
('default_min_stock_level', '0', 'stock', 'Default Minimum Stock Level', 'Default minimum stock level for new products', 'number', 0, 1, 1),
('enable_multi_warehouse', '0', 'warehouse', 'Enable Multi-Warehouse', 'Enable multiple warehouse/location management', 'checkbox', 0, 1, 1),
('enable_product_variants', '0', 'product', 'Enable Product Variants', 'Enable product variants (size, color, etc.)', 'checkbox', 0, 1, 1),
('low_stock_alert', '1', 'notification', 'Low Stock Alerts', 'Enable alerts for low stock levels', 'checkbox', 0, 1, 1),
('expiry_alert_days', '30', 'notification', 'Expiry Alert Days', 'Days before expiry to show alerts', 'number', 0, 1, 1);

-- Insert default UOMs
INSERT INTO `uom` 
(`uom_name`, `uom_symbol`, `uom_type`, `description`, `status`, `created_by`) VALUES
('Each', 'EA', 'unit', 'Individual units', 1, 1),
('Piece', 'PC', 'unit', 'Individual pieces', 1, 1),
('Kilogram', 'KG', 'weight', 'Metric weight unit', 1, 1),
('Gram', 'G', 'weight', 'Metric weight unit', 1, 1),
('Liter', 'L', 'volume', 'Metric volume unit', 1, 1),
('Milliliter', 'ML', 'volume', 'Metric volume unit', 1, 1),
('Meter', 'M', 'length', 'Metric length unit', 1, 1),
('Centimeter', 'CM', 'length', 'Metric length unit', 1, 1),
('Box', 'BOX', 'package', 'Box packaging', 1, 1),
('Carton', 'CTN', 'package', 'Carton packaging', 1, 1),
('Dozen', 'DOZ', 'unit', '12 units', 1, 1),
('Pair', 'PR', 'unit', 'Two matching items', 1, 1);

-- Insert UOM conversions
INSERT INTO `uom_conversion` 
(`from_uom_id`, `to_uom_id`, `conversion_factor`, `status`, `created_by`) VALUES
(3, 4, 1000, 1, 1), -- 1 KG = 1000 G
(5, 6, 1000, 1, 1), -- 1 L = 1000 ML
(7, 8, 100, 1, 1),  -- 1 M = 100 CM
(11, 1, 12, 1, 1);  -- 1 DOZ = 12 EA
