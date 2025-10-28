-- Add barcode_format column to products table if it doesn't exist
ALTER TABLE products 
ADD COLUMN IF NOT EXISTS barcode_format VARCHAR(20) DEFAULT 'EAN13' AFTER barcode;
