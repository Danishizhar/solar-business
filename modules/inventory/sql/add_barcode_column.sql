-- Check if barcode column exists in products table
SET @dbname = 'sbm';
SET @tablename = 'products';
SET @columnname = 'barcode';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname) AND
      (TABLE_NAME = @tablename) AND
      (COLUMN_NAME = @columnname)
  ) > 0,
  "SELECT 'Column already exists, no action needed' AS message;",
  "ALTER TABLE products ADD COLUMN barcode VARCHAR(50) AFTER product_code;"
));

PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
