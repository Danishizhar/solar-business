-- Add barcode column to products table if it doesn't exist
ALTER TABLE products ADD COLUMN IF NOT EXISTS barcode VARCHAR(50) AFTER product_code;
