-- =========================================
-- ACSLite Database Migration 002
-- Add Customer Login Fields to ONU Locations
-- (For existing installations only)
-- =========================================

-- Add username column if not exists
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'onu_locations' 
    AND COLUMN_NAME = 'username'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE onu_locations ADD COLUMN username VARCHAR(50) DEFAULT NULL COMMENT ''Customer login username'' AFTER name',
    'SELECT ''Column username already exists'' AS status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add password column if not exists
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'onu_locations' 
    AND COLUMN_NAME = 'password'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE onu_locations ADD COLUMN password VARCHAR(255) DEFAULT NULL COMMENT ''Customer login password (hashed)'' AFTER username',
    'SELECT ''Column password already exists'' AS status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add unique index for username if not exists
SET @index_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'onu_locations' 
    AND INDEX_NAME = 'idx_username'
);

SET @sql = IF(@index_exists = 0, 
    'CREATE UNIQUE INDEX idx_username ON onu_locations(username)',
    'SELECT ''Index idx_username already exists'' AS status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Success message
SELECT 'Migration 002 completed successfully!' AS status;
SELECT COUNT(*) AS total_locations FROM onu_locations;
DESCRIBE onu_locations;
