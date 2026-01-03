-- ================================================
-- ACS-Lite Billing Database Schema
-- ================================================

-- Customers Table
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id VARCHAR(20) UNIQUE NOT NULL COMMENT 'Format: CST001',
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    address TEXT,
    
    -- PPPoE Credentials
    pppoe_username VARCHAR(50) DEFAULT NULL,
    pppoe_password VARCHAR(100) DEFAULT NULL,
    
    -- Package & Billing
    package_id INT DEFAULT NULL,
    monthly_fee DECIMAL(12,2) DEFAULT 0,
    billing_date TINYINT DEFAULT 1 COMMENT 'Day of month for billing',
    
    -- Status
    status ENUM('active', 'isolir', 'suspended', 'terminated') DEFAULT 'active',
    isolir_date DATE DEFAULT NULL,
    
    -- ONU Link
    onu_serial VARCHAR(50) DEFAULT NULL COMMENT 'Link to ONU device',
    
    -- Timestamps
    registered_at DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_status (status),
    INDEX idx_pppoe (pppoe_username),
    INDEX idx_onu (onu_serial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Packages Table
CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    speed VARCHAR(20) NOT NULL COMMENT 'e.g. 10M, 20M, 50M',
    price DECIMAL(12,2) NOT NULL,
    description TEXT,
    mikrotik_profile VARCHAR(50) DEFAULT NULL COMMENT 'MikroTik PPPoE profile name',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices Table
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(30) UNIQUE NOT NULL COMMENT 'Format: INV-202312-001',
    customer_id INT NOT NULL,
    
    -- Billing Period
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    due_date DATE NOT NULL,
    
    -- Amount
    subtotal DECIMAL(12,2) NOT NULL,
    discount DECIMAL(12,2) DEFAULT 0,
    tax DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) NOT NULL,
    
    -- Status
    status ENUM('draft', 'sent', 'paid', 'overdue', 'cancelled') DEFAULT 'draft',
    paid_at DATETIME DEFAULT NULL,
    paid_amount DECIMAL(12,2) DEFAULT 0,
    
    -- Notes
    notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments Table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_no VARCHAR(30) UNIQUE NOT NULL COMMENT 'Format: PAY-202312-001',
    invoice_id INT NOT NULL,
    customer_id INT NOT NULL,
    
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash', 'transfer', 'qris', 'ewallet', 'other') DEFAULT 'cash',
    payment_date DATE NOT NULL,
    
    -- Reference
    reference_no VARCHAR(100) DEFAULT NULL COMMENT 'Bank transfer ref, etc',
    notes TEXT,
    
    -- Who recorded
    recorded_by VARCHAR(50) DEFAULT 'admin',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_invoice (invoice_id),
    INDEX idx_customer (customer_id),
    INDEX idx_date (payment_date),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert Default Packages
INSERT IGNORE INTO packages (id, name, speed, price, description, mikrotik_profile) VALUES
(1, 'Basic 10 Mbps', '10M', 150000, 'Paket internet 10 Mbps untuk rumah tangga', '10mbps'),
(2, 'Standard 20 Mbps', '20M', 200000, 'Paket internet 20 Mbps cocok untuk WFH', '20mbps'),
(3, 'Premium 50 Mbps', '50M', 350000, 'Paket internet 50 Mbps untuk streaming & gaming', '50mbps'),
(4, 'Ultra 100 Mbps', '100M', 500000, 'Paket internet 100 Mbps untuk kebutuhan besar', '100mbps');
