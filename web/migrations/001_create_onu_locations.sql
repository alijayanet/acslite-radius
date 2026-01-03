-- =========================================
-- ACSLite Database Migration
-- Add ONU Locations Table (with Customer Login)
-- =========================================

-- Create onu_locations table
CREATE TABLE IF NOT EXISTS onu_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial_number VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) DEFAULT NULL,
    username VARCHAR(50) DEFAULT NULL COMMENT 'Customer login username',
    password VARCHAR(255) DEFAULT NULL COMMENT 'Customer login password (hashed)',
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_serial (serial_number),
    INDEX idx_coords (latitude, longitude),
    UNIQUE INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Success message
SELECT 'Migration 001 completed successfully!' AS status;
SELECT COUNT(*) AS total_locations FROM onu_locations;

