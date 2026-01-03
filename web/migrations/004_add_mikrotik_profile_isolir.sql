-- =========================================
-- ACSLite Database Migration 004
-- Add mikrotik_profile_isolir column to packages table
-- =========================================

-- Add mikrotik_profile_isolir column if not exists
SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'packages' 
    AND COLUMN_NAME = 'mikrotik_profile_isolir'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE packages ADD COLUMN mikrotik_profile_isolir VARCHAR(50) DEFAULT ''isolir'' COMMENT ''MikroTik profile untuk isolir'' AFTER mikrotik_profile',
    'SELECT ''Column mikrotik_profile_isolir already exists'' AS status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing packages to use 'isolir' as default
UPDATE packages SET mikrotik_profile_isolir = 'isolir' WHERE mikrotik_profile_isolir IS NULL OR mikrotik_profile_isolir = '';

-- Success message
SELECT 'Migration 004 completed successfully!' AS status;
DESCRIBE packages;
