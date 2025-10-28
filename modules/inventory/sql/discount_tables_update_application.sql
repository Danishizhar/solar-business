-- Add discount_application field to discounts table
ALTER TABLE `discounts` 
ADD COLUMN `discount_application` ENUM('sales', 'purchase', 'both') NOT NULL DEFAULT 'both' 
AFTER `discount_type`;
