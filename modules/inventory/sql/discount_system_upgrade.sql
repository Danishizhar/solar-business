-- Discount System Upgrade SQL Script
-- This script adds new features to the discount management system

-- 1. Add fields for discount stacking
ALTER TABLE discounts ADD COLUMN is_stackable TINYINT(1) DEFAULT 0 COMMENT 'Whether this discount can be combined with others';
ALTER TABLE discounts ADD COLUMN stack_priority INT DEFAULT 0 COMMENT 'Priority for stacking (higher numbers applied first)';
ALTER TABLE discounts ADD COLUMN stack_type ENUM('additive', 'multiplicative') DEFAULT 'additive' COMMENT 'How this discount combines with others';

-- 2. Add fields for time-limited flash discounts
ALTER TABLE discounts ADD COLUMN start_time TIME DEFAULT NULL COMMENT 'Start time for the discount';
ALTER TABLE discounts ADD COLUMN end_time TIME DEFAULT NULL COMMENT 'End time for the discount';
ALTER TABLE discounts ADD COLUMN is_flash_sale TINYINT(1) DEFAULT 0 COMMENT 'Whether this is a flash sale';

-- 3. Add fields for discount usage limits
ALTER TABLE discounts ADD COLUMN usage_limit INT DEFAULT NULL COMMENT 'Maximum number of times this discount can be used';
ALTER TABLE discounts ADD COLUMN usage_count INT DEFAULT 0 COMMENT 'Current usage count';
ALTER TABLE discounts ADD COLUMN per_customer_limit INT DEFAULT NULL COMMENT 'Maximum times a single customer can use this';
ALTER TABLE discounts ADD COLUMN budget_cap DECIMAL(15,2) DEFAULT NULL COMMENT 'Maximum total discount amount to be given';
ALTER TABLE discounts ADD COLUMN budget_used DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Total discount amount used so far';

-- 4. Add fields for discount approval workflow
ALTER TABLE discounts ADD COLUMN approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' COMMENT 'Approval status';
ALTER TABLE discounts ADD COLUMN approved_by INT DEFAULT NULL COMMENT 'User ID who approved the discount';
ALTER TABLE discounts ADD COLUMN approval_date DATETIME DEFAULT NULL COMMENT 'When the discount was approved';
ALTER TABLE discounts ADD COLUMN rejection_reason TEXT DEFAULT NULL COMMENT 'Reason for rejection if rejected';

-- 5. Add fields for marketing campaign integration
ALTER TABLE discounts ADD COLUMN campaign_id INT DEFAULT NULL COMMENT 'Associated marketing campaign ID';
ALTER TABLE discounts ADD COLUMN utm_source VARCHAR(100) DEFAULT NULL COMMENT 'UTM source for tracking';
ALTER TABLE discounts ADD COLUMN utm_medium VARCHAR(100) DEFAULT NULL COMMENT 'UTM medium for tracking';
ALTER TABLE discounts ADD COLUMN utm_campaign VARCHAR(100) DEFAULT NULL COMMENT 'UTM campaign for tracking';

-- 6. Create table for customer loyalty tiers
CREATE TABLE IF NOT EXISTS customer_loyalty_tiers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tier_name VARCHAR(50) NOT NULL,
    tier_description TEXT,
    min_purchase_amount DECIMAL(15,2) DEFAULT 0.00,
    min_purchase_count INT DEFAULT 0,
    discount_percentage DECIMAL(5,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Add customer tier relationship
ALTER TABLE customers ADD COLUMN loyalty_tier_id INT DEFAULT NULL;
ALTER TABLE customers ADD CONSTRAINT fk_customer_loyalty_tier FOREIGN KEY (loyalty_tier_id) REFERENCES customer_loyalty_tiers(id) ON DELETE SET NULL;
ALTER TABLE customers ADD COLUMN loyalty_points INT DEFAULT 0 COMMENT 'Accumulated loyalty points';
ALTER TABLE customers ADD COLUMN total_purchase_amount DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Total lifetime purchase amount';
ALTER TABLE customers ADD COLUMN purchase_count INT DEFAULT 0 COMMENT 'Total number of purchases';

-- 8. Create table for bulk/volume discounts
CREATE TABLE IF NOT EXISTS volume_discounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discount_id INT NOT NULL,
    min_quantity INT NOT NULL,
    max_quantity INT DEFAULT NULL,
    discount_value DECIMAL(15,2) NOT NULL,
    discount_type ENUM('percentage', 'fixed') DEFAULT 'percentage',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (discount_id) REFERENCES discounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Create table for bundle discounts
CREATE TABLE IF NOT EXISTS bundle_discounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bundle_name VARCHAR(100) NOT NULL,
    description TEXT,
    discount_value DECIMAL(15,2) NOT NULL,
    discount_type ENUM('percentage', 'fixed') DEFAULT 'percentage',
    is_active TINYINT(1) DEFAULT 1,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Create table for bundle products
CREATE TABLE IF NOT EXISTS bundle_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bundle_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT DEFAULT 1,
    FOREIGN KEY (bundle_id) REFERENCES bundle_discounts(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Create table for discount usage tracking
CREATE TABLE IF NOT EXISTS discount_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discount_id INT NOT NULL,
    customer_id INT DEFAULT NULL,
    order_id INT NOT NULL,
    order_type ENUM('sales', 'purchase') DEFAULT 'sales',
    discount_amount DECIMAL(15,2) NOT NULL,
    used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (discount_id) REFERENCES discounts(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Create table for discount notifications
CREATE TABLE IF NOT EXISTS discount_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discount_id INT NOT NULL,
    notification_title VARCHAR(100) NOT NULL,
    notification_message TEXT NOT NULL,
    notification_type ENUM('email', 'sms', 'app', 'all') DEFAULT 'all',
    is_sent TINYINT(1) DEFAULT 0,
    scheduled_at DATETIME DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (discount_id) REFERENCES discounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Create table for discount approvals
CREATE TABLE IF NOT EXISTS discount_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discount_id INT NOT NULL,
    requested_by INT NOT NULL,
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    rejection_reason TEXT DEFAULT NULL,
    FOREIGN KEY (discount_id) REFERENCES discounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. Create table for discount analytics
CREATE TABLE IF NOT EXISTS discount_analytics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discount_id INT NOT NULL,
    date DATE NOT NULL,
    views INT DEFAULT 0 COMMENT 'Number of times discount was viewed',
    applications INT DEFAULT 0 COMMENT 'Number of times discount was applied',
    total_discount_amount DECIMAL(15,2) DEFAULT 0.00,
    affected_orders INT DEFAULT 0,
    total_order_value DECIMAL(15,2) DEFAULT 0.00,
    FOREIGN KEY (discount_id) REFERENCES discounts(id) ON DELETE CASCADE,
    UNIQUE KEY (discount_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. Add discount tier relationship
ALTER TABLE discounts ADD COLUMN loyalty_tier_id INT DEFAULT NULL;
ALTER TABLE discounts ADD CONSTRAINT fk_discount_loyalty_tier FOREIGN KEY (loyalty_tier_id) REFERENCES customer_loyalty_tiers(id) ON DELETE SET NULL;

-- 16. Add buy X get Y promotion fields
ALTER TABLE discounts ADD COLUMN buy_quantity INT DEFAULT NULL COMMENT 'Buy X quantity';
ALTER TABLE discounts ADD COLUMN get_quantity INT DEFAULT NULL COMMENT 'Get Y quantity';
ALTER TABLE discounts ADD COLUMN get_product_id INT DEFAULT NULL COMMENT 'Product ID for the free/discounted product';
ALTER TABLE discounts ADD COLUMN get_discount_percentage DECIMAL(5,2) DEFAULT 100.00 COMMENT 'Discount percentage for the free product (100 = free)';

-- Insert default loyalty tiers
INSERT INTO customer_loyalty_tiers (tier_name, tier_description, min_purchase_amount, min_purchase_count, discount_percentage, is_active)
VALUES 
('Bronze', 'Entry level tier with basic benefits', 0, 0, 0, 1),
('Silver', 'Mid-level tier with enhanced benefits', 50000, 5, 5, 1),
('Gold', 'High-level tier with premium benefits', 100000, 10, 10, 1),
('Platinum', 'Top-level tier with exclusive benefits', 250000, 25, 15, 1);
