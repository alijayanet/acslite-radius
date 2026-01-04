-- ===================================================
-- Migration 005: Settings Table
-- Migrate settings.json to MySQL database
-- Date: 2026-01-03
-- ===================================================

USE acs;

-- Create settings table with JSON column
CREATE TABLE IF NOT EXISTS settings (
    category VARCHAR(50) PRIMARY KEY,
    settings_json JSON NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(50) DEFAULT 'system',
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Application settings (migrated from settings.json)';

-- Insert default settings (will be overwritten by migration if settings.json exists)
INSERT INTO settings (category, settings_json, updated_by) VALUES
('general', '{
    "site_name": "ACS-Lite ISP Manager",
    "company_name": "My ISP",
    "timezone": "Asia/Jakarta",
    "currency": "IDR",
    "date_format": "d/m/Y",
    "language": "id",
    "address": "", 
    "phone": "",
    "email": ""
}', 'migration_005'),

('acs', '{
    "api_url": "http://localhost:7547",
    "api_key": "secret",
    "periodic_inform_interval": 300,
    "auto_refresh_interval": 15
}', 'migration_005'),

('telegram', '{
    "enabled": false,
    "bot_token": "",
    "chat_id": "",
    "notify_isolir": true,
    "notify_payment": true,
    "notify_new_device": true
}', 'migration_005'),

('billing', '{
    "enabled": false,
    "due_day": 1,
    "grace_period": 7,
    "auto_isolir": true,
    "isolir_profile": "isolir"
}', 'migration_005'),

('whatsapp', '{
    "enabled": false,
    "api_url": "",
    "api_key": ""
}', 'migration_005'),

('hotspot', '{
    "backend": "mikrotik",
    "backup_to_radius": false,
    "selected_router_id": "router1",
    "radius_server_ip": "",
    "radius": {
        "enabled": false,
        "db_host": "127.0.0.1",
        "db_port": 3306,
        "db_name": "radius",
        "db_user": "radius",
        "db_pass": ""
    }
}', 'migration_005')

ON DUPLICATE KEY UPDATE
    settings_json = VALUES(settings_json),
    updated_at = CURRENT_TIMESTAMP,
    updated_by = 'migration_005';

-- Success message
SELECT 'Migration 005: Settings table created successfully!' AS status;
