-- Add branch_id column to inventory_locations table
ALTER TABLE `inventory_locations` 
ADD COLUMN `branch_id` INT DEFAULT NULL AFTER `parent_id`;

-- Add foreign key constraint
ALTER TABLE `inventory_locations`
ADD CONSTRAINT `fk_inventory_locations_branch` 
FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL;

-- Update existing records with default branch if needed
-- Uncomment and modify the following line if you want to set a default branch:
-- UPDATE `inventory_locations` SET `branch_id` = 1 WHERE `branch_id` IS NULL;
