-- ============================================
-- HOTSPOT VOUCHER SYSTEM - DATABASE SCHEMA
-- ============================================
-- Created: 2025-01-30
-- Purpose: Database schema for hotspot voucher management system
-- Compatible with: MySQL 5.7+, MariaDB 10.3+
-- ============================================

-- Table: hotspot_vouchers
-- Purpose: Store all generated vouchers and their status
CREATE TABLE IF NOT EXISTS hotspot_vouchers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    batch_id VARCHAR(50) NOT NULL COMMENT 'Format: vc-acslite-YYYYMMDD-HHMMSS',
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(100) NOT NULL,
    profile VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration VARCHAR(20) NOT NULL COMMENT 'Format: 3h, 1d, 7d',
    limit_uptime INT NULL COMMENT 'Seconds',
    created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sold_date DATETIME NULL,
    first_login DATETIME NULL,
    last_login DATETIME NULL,
    expired_date DATETIME NULL,
    status ENUM('unused', 'sold', 'active', 'expired', 'disabled') DEFAULT 'unused',
    mac_address VARCHAR(17) NULL,
    comment TEXT NULL,
    scheduler_name VARCHAR(100) NULL COMMENT 'Format: vc-acslite-USERNAME-YYYYMMDD',
    mikrotik_comment TEXT NULL COMMENT 'Full comment from MikroTik',
    
    INDEX idx_batch (batch_id),
    INDEX idx_profile (profile),
    INDEX idx_status (status),
    INDEX idx_created (created_date),
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: voucher_batches
-- Purpose: Track batch generation and statistics
CREATE TABLE IF NOT EXISTS voucher_batches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    batch_id VARCHAR(50) UNIQUE NOT NULL,
    profile VARCHAR(50) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration VARCHAR(20) NOT NULL,
    prefix VARCHAR(20) NULL,
    code_length INT NOT NULL DEFAULT 6,
    created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(100) NULL,
    
    -- Statistics (updated periodically)
    total_unused INT DEFAULT 0,
    total_sold INT DEFAULT 0,
    total_active INT DEFAULT 0,
    total_expired INT DEFAULT 0,
    total_disabled INT DEFAULT 0,
    revenue DECIMAL(10,2) DEFAULT 0,
    
    notes TEXT NULL,
    
    INDEX idx_profile (profile),
    INDEX idx_created (created_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: hotspot_sales
-- Purpose: Track voucher sales transactions
CREATE TABLE IF NOT EXISTS hotspot_sales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    voucher_id INT NOT NULL,
    batch_id VARCHAR(50) NOT NULL,
    username VARCHAR(100) NOT NULL,
    sale_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    price DECIMAL(10,2) NOT NULL,
    actual_price DECIMAL(10,2) NULL COMMENT 'If different from list price (discount)',
    seller VARCHAR(100) NULL,
    customer_name VARCHAR(100) NULL,
    customer_phone VARCHAR(20) NULL,
    payment_method ENUM('cash', 'transfer', 'qris', 'ewallet', 'other') DEFAULT 'cash',
    notes TEXT NULL,
    
    FOREIGN KEY (voucher_id) REFERENCES hotspot_vouchers(id) ON DELETE CASCADE,
    INDEX idx_batch (batch_id),
    INDEX idx_sale_date (sale_date),
    INDEX idx_payment_method (payment_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: hotspot_profile_stats
-- Purpose: Daily statistics per profile
CREATE TABLE IF NOT EXISTS hotspot_profile_stats (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profile VARCHAR(50) NOT NULL,
    date DATE NOT NULL,
    
    -- Generation stats
    total_generated INT DEFAULT 0,
    
    -- Sales stats
    total_sold INT DEFAULT 0,
    revenue DECIMAL(10,2) DEFAULT 0,
    
    -- Usage stats
    total_active INT DEFAULT 0,
    total_expired INT DEFAULT 0,
    
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_profile_date (profile, date),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: hotspot_profiles
-- Purpose: Store hotspot profile configurations
CREATE TABLE IF NOT EXISTS hotspot_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) UNIQUE NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    duration VARCHAR(20) NOT NULL,
    duration_seconds INT NOT NULL COMMENT 'Duration in seconds for calculation',
    rate_limit VARCHAR(50) NULL COMMENT 'Format: upload/download (e.g., 2M/2M)',
    shared_users INT DEFAULT 1,
    session_timeout INT NULL COMMENT 'Seconds',
    idle_timeout INT NULL COMMENT 'Seconds',
    validity_type ENUM('uptime', 'time', 'both') DEFAULT 'uptime',
    on_login_script TEXT NULL,
    created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_date DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    
    INDEX idx_name (name),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INITIAL DATA / SAMPLE PROFILES
-- ============================================

-- Sample hotspot profiles (optional, can be removed)
INSERT INTO hotspot_profiles (name, price, duration, duration_seconds, rate_limit, validity_type) VALUES
('3JAM', 3000, '3h', 10800, '2M/2M', 'uptime'),
('1HARI', 5000, '1d', 86400, '2M/2M', 'uptime'),
('3HARI', 10000, '3d', 259200, '2M/2M', 'uptime'),
('1MINGGU', 20000, '7d', 604800, '3M/3M', 'uptime')
ON DUPLICATE KEY UPDATE updated_date = CURRENT_TIMESTAMP;

-- ============================================
-- VIEWS FOR REPORTING
-- ============================================

-- View: Daily revenue summary
CREATE OR REPLACE VIEW v_daily_revenue AS
SELECT 
    DATE(sale_date) as date,
    COUNT(*) as total_sales,
    SUM(actual_price) as revenue,
    COUNT(DISTINCT batch_id) as batches_sold
FROM hotspot_sales
GROUP BY DATE(sale_date)
ORDER BY date DESC;

-- View: Profile performance
CREATE OR REPLACE VIEW v_profile_performance AS
SELECT 
    p.name as profile,
    p.price,
    p.duration,
    COUNT(v.id) as total_generated,
    SUM(CASE WHEN v.status = 'sold' OR v.status = 'active' THEN 1 ELSE 0 END) as total_sold,
    SUM(CASE WHEN v.status = 'active' THEN 1 ELSE 0 END) as total_active,
    SUM(CASE WHEN v.status = 'unused' THEN 1 ELSE 0 END) as total_unused,
    COALESCE(SUM(s.actual_price), 0) as total_revenue
FROM hotspot_profiles p
LEFT JOIN hotspot_vouchers v ON p.name = v.profile
LEFT JOIN hotspot_sales s ON v.id = s.voucher_id
WHERE p.is_active = 1
GROUP BY p.name, p.price, p.duration
ORDER BY total_revenue DESC;

-- View: Batch summary
CREATE OR REPLACE VIEW v_batch_summary AS
SELECT 
    b.batch_id,
    b.profile,
    b.quantity,
    b.price,
    b.created_date,
    COUNT(v.id) as vouchers_count,
    SUM(CASE WHEN v.status = 'unused' THEN 1 ELSE 0 END) as unused,
    SUM(CASE WHEN v.status = 'sold' THEN 1 ELSE 0 END) as sold,
    SUM(CASE WHEN v.status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN v.status = 'expired' THEN 1 ELSE 0 END) as expired,
    COALESCE(SUM(s.actual_price), 0) as revenue
FROM voucher_batches b
LEFT JOIN hotspot_vouchers v ON b.batch_id = v.batch_id
LEFT JOIN hotspot_sales s ON v.id = s.voucher_id
GROUP BY b.batch_id, b.profile, b.quantity, b.price, b.created_date
ORDER BY b.created_date DESC;

-- ============================================
-- STORED PROCEDURES
-- ============================================

DELIMITER //

-- Procedure: Update batch statistics
CREATE PROCEDURE IF NOT EXISTS sp_update_batch_stats(IN p_batch_id VARCHAR(50))
BEGIN
    UPDATE voucher_batches b
    SET 
        total_unused = (SELECT COUNT(*) FROM hotspot_vouchers WHERE batch_id = p_batch_id AND status = 'unused'),
        total_sold = (SELECT COUNT(*) FROM hotspot_vouchers WHERE batch_id = p_batch_id AND status = 'sold'),
        total_active = (SELECT COUNT(*) FROM hotspot_vouchers WHERE batch_id = p_batch_id AND status = 'active'),
        total_expired = (SELECT COUNT(*) FROM hotspot_vouchers WHERE batch_id = p_batch_id AND status = 'expired'),
        total_disabled = (SELECT COUNT(*) FROM hotspot_vouchers WHERE batch_id = p_batch_id AND status = 'disabled'),
        revenue = COALESCE((SELECT SUM(actual_price) FROM hotspot_sales WHERE batch_id = p_batch_id), 0)
    WHERE batch_id = p_batch_id;
END//

-- Procedure: Update profile daily stats
CREATE PROCEDURE IF NOT EXISTS sp_update_profile_stats(IN p_profile VARCHAR(50), IN p_date DATE)
BEGIN
    INSERT INTO hotspot_profile_stats (profile, date, total_generated, total_sold, revenue, total_active, total_expired)
    SELECT 
        p_profile,
        p_date,
        COUNT(*) as total_generated,
        SUM(CASE WHEN v.status IN ('sold', 'active') THEN 1 ELSE 0 END) as total_sold,
        COALESCE(SUM(s.actual_price), 0) as revenue,
        SUM(CASE WHEN v.status = 'active' THEN 1 ELSE 0 END) as total_active,
        SUM(CASE WHEN v.status = 'expired' THEN 1 ELSE 0 END) as total_expired
    FROM hotspot_vouchers v
    LEFT JOIN hotspot_sales s ON v.id = s.voucher_id AND DATE(s.sale_date) = p_date
    WHERE v.profile = p_profile AND DATE(v.created_date) <= p_date
    ON DUPLICATE KEY UPDATE
        total_generated = VALUES(total_generated),
        total_sold = VALUES(total_sold),
        revenue = VALUES(revenue),
        total_active = VALUES(total_active),
        total_expired = VALUES(total_expired);
END//

DELIMITER ;

-- ============================================
-- TRIGGERS
-- ============================================

DELIMITER //

-- Trigger: After voucher sale, update batch stats
CREATE TRIGGER IF NOT EXISTS tr_after_voucher_sale
AFTER INSERT ON hotspot_sales
FOR EACH ROW
BEGIN
    -- Update voucher status
    UPDATE hotspot_vouchers 
    SET status = 'sold', sold_date = NEW.sale_date 
    WHERE id = NEW.voucher_id;
    
    -- Update batch stats
    CALL sp_update_batch_stats(NEW.batch_id);
END//

-- Trigger: After voucher status change, update batch stats
CREATE TRIGGER IF NOT EXISTS tr_after_voucher_status_update
AFTER UPDATE ON hotspot_vouchers
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        CALL sp_update_batch_stats(NEW.batch_id);
    END IF;
END//

DELIMITER ;

-- ============================================
-- INDEXES FOR PERFORMANCE
-- ============================================

-- Additional indexes for common queries
CREATE INDEX idx_voucher_batch_status ON hotspot_vouchers(batch_id, status);
CREATE INDEX idx_voucher_profile_status ON hotspot_vouchers(profile, status);
CREATE INDEX idx_sale_batch_date ON hotspot_sales(batch_id, sale_date);

-- ============================================
-- GRANTS (Optional - adjust as needed)
-- ============================================

-- GRANT SELECT, INSERT, UPDATE, DELETE ON hotspot_vouchers TO 'acs_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON voucher_batches TO 'acs_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON hotspot_sales TO 'acs_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON hotspot_profiles TO 'acs_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE sp_update_batch_stats TO 'acs_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE sp_update_profile_stats TO 'acs_user'@'localhost';

-- ============================================
-- END OF SCHEMA
-- ============================================
