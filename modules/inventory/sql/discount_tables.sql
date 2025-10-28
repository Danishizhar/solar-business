-- Created: May 5, 2025
-- Discount tables for inventory module

-- Main discounts table
CREATE TABLE IF NOT EXISTS `discounts` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `discount_name` VARCHAR(100) NOT NULL,
    `discount_code` VARCHAR(50) UNIQUE,
    `discount_type` ENUM('percentage', 'fixed_amount') NOT NULL,
    `discount_value` DECIMAL(15,2) NOT NULL,
    `min_purchase_amount` DECIMAL(15,2) DEFAULT 0,
    `min_quantity` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `start_date` DATE,
    `end_date` DATE,
    `description` TEXT,
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Discount conditions table
CREATE TABLE IF NOT EXISTS `discount_conditions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `discount_id` INT NOT NULL,
    `condition_type` ENUM('product', 'brand', 'category', 'attribute', 'location', 'supplier', 'customer') NOT NULL,
    `condition_value` VARCHAR(100) NOT NULL, -- ID of the entity (product_id, brand_id, etc.)
    `status` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT,
    `updated_by` INT,
    FOREIGN KEY (`discount_id`) REFERENCES `discounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Discount usage tracking
CREATE TABLE IF NOT EXISTS `discount_usage` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `discount_id` INT NOT NULL,
    `reference_type` VARCHAR(50) NOT NULL, -- 'sales_order', 'purchase_order', etc.
    `reference_id` INT NOT NULL,
    `customer_id` INT,
    `supplier_id` INT,
    `discount_amount` DECIMAL(15,2) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT,
    FOREIGN KEY (`discount_id`) REFERENCES `discounts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default discount settings
INSERT INTO `inventory_settings` 
(`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `is_required`, `status`, `created_by`) VALUES
('enable_discounts', '1', 'discount', 'Enable Discounts', 'Enable discount functionality', 'checkbox', 0, 1, 1),
('auto_generate_discount_code', '1', 'discount', 'Auto Generate Discount Code', 'Automatically generate discount codes', 'checkbox', 0, 1, 1),
('discount_code_prefix', 'DISC-', 'discount', 'Discount Code Prefix', 'Prefix for auto-generated discount codes', 'text', 0, 1, 1);
