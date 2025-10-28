-- Add approval_pin column to users table for discount approval
ALTER TABLE users ADD COLUMN approval_pin VARCHAR(10) NULL AFTER password;

-- Add user_type column to users table if it doesn't exist (to replace role)
ALTER TABLE users ADD COLUMN user_type ENUM('admin', 'manager', 'staff', 'user') NOT NULL DEFAULT 'user' AFTER approval_pin;
