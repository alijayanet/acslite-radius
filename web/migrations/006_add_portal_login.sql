-- ================================================
-- Migration: Add Portal Login Credentials
-- ================================================

-- Add portal login fields to customers table
ALTER TABLE customers 
ADD COLUMN portal_username VARCHAR(50) DEFAULT NULL COMMENT 'Username for customer portal login' AFTER pppoe_password,
ADD COLUMN portal_password VARCHAR(255) DEFAULT NULL COMMENT 'Password for customer portal (bcrypt)' AFTER portal_username,
ADD UNIQUE INDEX idx_portal_username (portal_username);

-- Set default portal credentials for existing customers
-- Default password: 1234 (bcrypt hash)
UPDATE customers 
SET portal_username = pppoe_username,
    portal_password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
WHERE portal_username IS NULL AND pppoe_username IS NOT NULL;

-- Success message
SELECT 'Migration: Portal login fields added successfully!' AS status;
SELECT COUNT(*) AS customers_with_portal_login FROM customers WHERE portal_username IS NOT NULL;
