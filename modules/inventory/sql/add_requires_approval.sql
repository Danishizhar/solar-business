-- Add requires_approval column to discounts table
ALTER TABLE discounts ADD COLUMN requires_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER approval_status;
