-- Add description column to inventory_locations table
ALTER TABLE `inventory_locations` 
ADD COLUMN `description` TEXT AFTER `email`;
