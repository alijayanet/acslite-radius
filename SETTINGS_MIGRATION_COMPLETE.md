# 🎯 Settings Migration: JSON → MySQL - Complete Guide

**Date:** 2026-01-03 23:10 WIB  
**Status:** ✅ COMPLETE & READY TO DEPLOY  
**Target:** ACS-Lite v2.0+

---

## 📋 **What Was Done**

### **Objective:**
Migrate `settings.json` from file-based storage to MySQL database for better:
- ✅ Centralized data management
- ✅ Automatic backup with database
- ✅ No file permission issues
- ✅ Better performance & scalability

---

## 📂 **Files Created/Modified**

### 1. **SQL Schema** ✅
**File:** `sql/create_settings_table.sql`

```sql
CREATE TABLE IF NOT EXISTS settings (
    category VARCHAR(50) PRIMARY KEY,
    settings_json JSON NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(50) DEFAULT 'system',
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Purpose:**
- Single table with JSON column
- Each category (general, acs, telegram, etc.) = 1 row
- Backward compatible with existing structure

---

### 2. **Migration Script** ✅
**File:** `migrate_settings_to_db.php`

**Features:**
- ✅ Auto-detect database credentials from `/opt/acs/.env`
- ✅ Read existing `settings.json`
- ✅ Migrate each category to database
- ✅ Create backup: `settings.json.backup.YYYY-MM-DD_HHMMSS`
- ✅ Detailed progress logging
- ✅ Error handling

**Usage:**
```bash
# Manual execution (if needed)
php migrate_settings_to_db.php
```

**Output Example:**
```
========================================
Settings Migration: JSON → MySQL
========================================

[1/4] Reading database credentials...
  ✓ Database: acs @ 127.0.0.1:3306
  ✓ User: root

[2/4] Connecting to MySQL...
  ✓ Connected successfully

[3/4] Reading settings.json...
  ✓ Found 6 categories
    - general
    - acs
    - telegram
    - billing
    - whatsapp
    - hotspot

[4/4] Migrating to database...
  ✓ Migrated: general (from settings.json)
  ✓ Migrated: acs (from settings.json)
  ✓ Migrated: telegram (from settings.json)
  ✓ Migrated: billing (from settings.json)
  ✓ Migrated: whatsapp (from settings.json)
  ✓ Migrated: hotspot (from settings.json)

========================================
Migration Complete!
========================================
Migrated: 6 categories
Errors: 0

✓ SUCCESS: All settings migrated successfully!
✓ Backup created: settings.json.backup.2026-01-03_231015
```

---

### 3. **Updated API** ✅
**File:** `web/api/settings_api.php`

**Changes:**
- ✅ Added `getAcsPDO()` - Database connection function
- ✅ Updated `loadSettings()` - Read from MySQL first, fallback to file
- ✅ Updated `saveSettings()` - Write to MySQL first, also backup to file
- ✅ Added `loadSettingsFromFile()` - Fallback function
- ✅ Added `saveSettingsToFile()` - Fallback function
- ✅ Added `getDefaultSettings()` - Centralized defaults

**Backward Compatibility:**
```php
// Auto-fallback if database not available
try {
    return loadSettings(); // Try MySQL
} catch (Exception $e) {
    return loadSettingsFromFile(); // Fallback to file
}
```

**Smart Behavior:**
1. Check if `settings` table exists
2. If YES → Read from MySQL
3. If NO → Fallback to settings.json
4. On save → Write to both MySQL AND file (dual backup)

---

### 4. **Automated Installation** ✅
**File:** `install.sh` (Updated)

**Added:**
- ✅ Settings table creation (after hotspot tables)
- ✅ Default values insertion
- ✅ Auto-migration if `settings.json` exists
- ✅ Auto-migration if `migrate_settings_to_db.php` exists

**Flow:**
```
install.sh runs
    │
    ├─ Create database tables (customers, packages, hotspot, etc.)
    │
    ├─ Create settings table ✅ NEW!
    │
    ├─ Insert default settings ✅ NEW!
    │
    ├─ Check if settings.json exists
    │   └─ YES → Run PHP migration script
    │          → Backup settings.json
    │          → Copy data to MySQL
    │
    └─ Continue with service installation
```

**No Additional Commands Needed!** 🎉

---

## 🚀 **Installation Flow**

### **Fresh Installation** (Server Baru)
```bash
git clone https://github.com/yourusername/acslite-radius.git
cd acslite-radius
sudo bash install.sh
```

**What Happens:**
1. ✅ MySQL database `acs` created
2. ✅ Tables created (including `settings`)
3. ✅ Default settings inserted to database
4. ✅ **NO settings.json created** (everything in MySQL)
5. ✅ Application uses MySQL for settings

**Result:** Clean, database-only installation ✅

---

### **Existing Installation** (Update)
```bash
cd acslite-radius
git pull
sudo bash install.sh
```

**What Happens:**
1. ✅ Detect existing `settings.json`
2. ✅ Create `settings` table if not exists
3. ✅ Run migration script automatically
4. ✅ Backup created: `settings.json.backup.YYYY-MM-DD_HHMMSS`
5. ✅ Data migrated to MySQL
6. ✅ settings.json kept as fallback

**Result:** Seamless migration, no data loss ✅

---

## 📊 **Database Schema Example**

```sql
mysql> SELECT * FROM settings;
+----------+---------------------------+---------------------+----------------+
| category | settings_json             | updated_at          | updated_by     |
+----------+---------------------------+---------------------+----------------+
| general  | {"site_name":"ACS-Lite... | 2026-01-03 23:10:15 | install_script |
| acs      | {"api_url":"http://...   | 2026-01-03 23:10:15 | install_script |
| telegram | {"enabled":false,...     | 2026-01-03 23:10:15 | install_script |
| billing  | {"enabled":false,...     | 2026-01-03 23:10:15 | install_script |
| whatsapp | {"enabled":false,...     | 2026-01-03 23:10:15 | install_script |
| hotspot  | {"backend":"mikrotik",.. | 2026-01-03 23:10:15 | install_script |
+----------+---------------------------+---------------------+----------------+
6 rows in set (0.00 sec)
```

---

## 🔍 **Testing Checklist**

### Test 1: Fresh Installation
```bash
# 1. Clean slate
mysql -u root -p -e "DROP DATABASE IF EXISTS acs; CREATE DATABASE acs;"
rm -rf /opt/acs

# 2. Run installer
bash install.sh

# 3. Verify settings table
mysql -u root -p acs -e "SELECT category FROM settings;"

# Expected: 6 categories (general, acs, telegram, billing, whatsapp, hotspot)
```

✅ **PASS** if settings table exists with 6 rows

---

### Test 2: API Endpoint
```bash
# Test API
curl http://localhost:8888/api/settings_api.php?action=get

# Expected: JSON response with all settings
```

✅ **PASS** if returns JSON with 6 categories

---

### Test 3: Frontend
```bash
# Open browser
http://SERVER_IP:7547/web/settings.html

# Login (admin/admin123)
# Go to Settings tab
# Change "Site Name"
# Click Save
```

✅ **PASS** if:
- Settings load correctly
- Can save changes
- Changes persist after reload

---

### Test 4: Migration Script (Manual)
```bash
# If you have existing settings.json
php migrate_settings_to_db.php

# Expected: Success message + backup created
```

✅ **PASS** if migration completes without errors

---

### Test 5: Fallback Mechanism
```bash
# 1. Drop settings table
mysql -u root -p acs -e "DROP TABLE settings;"

# 2. Access settings
curl http://localhost:8888/api/settings_api.php?action=get

# Expected: Still works (fallback to file)
```

✅ **PASS** if API returns data from settings.json

---

## ⚙️ **Configuration**

### Database Credentials
**Auto-detected from:**
1. `/opt/acs/.env` (DB_DSN line)
2. Fallback: `root:secret123@127.0.0.1:3306/acs`

### Customization
Edit `migrate_settings_to_db.php` if you need custom DB config:

```php
$dbConfig = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'dbname' => 'acs',
    'username' => 'root',
    'password' => 'your_password_here'
];
```

---

## 🛡️ **Backup Strategy**

### Automatic Backups
1. **On Migration:** `settings.json.backup.YYYY-MM-DD_HHMMSS`
2. **On Save:** Both MySQL AND file updated

### Manual Backup
```bash
# Backup database
mysqldump -u root -p acs settings > settings_backup.sql

# Backup file (if still exists)
cp web/data/settings.json settings.json.backup
```

### Restore
```bash
# From database backup
mysql -u root -p acs < settings_backup.sql

# From file backup
cp settings.json.backup web/data/settings.json
```

---

## 📝 **Maintenance**

### View Current Settings
```sql
-- All settings
SELECT category, settings_json FROM settings;

-- Specific category
SELECT settings_json FROM settings WHERE category = 'general';

-- Pretty print
SELECT 
    category, 
    JSON_PRETTY(settings_json) AS settings
FROM settings 
WHERE category = 'telegram';
```

### Update Settings Manually
```sql
-- Update telegram bot token
UPDATE settings 
SET 
    settings_json = JSON_SET(
        settings_json, 
        '$.bot_token', 
        '1234567890:ABCdef...'
    ),
    updated_by = 'manual_update'
WHERE category = 'telegram';
```

### Query JSON Fields
```sql
-- Get specific field
SELECT 
    JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.site_name')) AS site_name
FROM settings 
WHERE category = 'general';

-- Check if RADIUS enabled
SELECT 
    JSON_EXTRACT(settings_json, '$.radius.enabled') AS radius_enabled
FROM settings 
WHERE category = 'hotspot';
```

---

## 🎯 **Migration Timeline**

### Week 1-2: Testing Phase
- ✅ Settings stored in MySQL
- ✅ settings.json kept as fallback
- ✅ Monitor error logs for fallback usage

### Week 3+: Cleanup (Optional)
```bash
# After stable for 2 weeks, can remove file-based code
# Edit settings_api.php:
# - Remove loadSettingsFromFile()
# - Remove saveSettingsToFile()
# - Remove fallback logic

# Keep settings.json.backup for safety
```

---

## ✅ **Success Criteria**

- [x] Settings table created in MySQL
- [x] Migration script working
- [x] settings_api.php updated
- [x] install.sh automated migration
- [x] Frontend works (settings.html)
- [x] API endpoint functional
- [x] Fallback mechanism tested
- [x] Documentation complete

---

## 🎉 **Deployment Ready**

### For Fresh Servers:
```bash
sudo bash install.sh
```
**That's it!** Everything is automatic ✅

### For Existing Servers:
```bash
git pull
sudo bash install.sh
```
**That's it!** Migration runs automatically ✅

---

## 📚 **Additional Documentation**

- **Full Analysis:** `ANALYSIS_SETTINGS_MIGRATION.md`
- **SQL Schema:** `sql/create_settings_table.sql`
- **Migration Script:** `migrate_settings_to_db.php`
- **API Code:** `web/api/settings_api.php` (lines 24-250)

---

## 🆘 **Troubleshooting**

### Issue: Migration script fails
**Solution:**
```bash
# Check database credentials
cat /opt/acs/.env | grep DB_DSN

# Test MySQL connection
mysql -u root -psecret123 acs -e "SHOW TABLES;"

# Run migration manually
php migrate_settings_to_db.php
```

### Issue: API returns file data instead of database
**Solution:**
```bash
# Check if table exists
mysql -u root -psecret123 acs -e "SHOW TABLES LIKE 'settings';"

# Check error logs
tail -f /var/log/php.log
tail -f /var/log/apache2/error.log
```

### Issue: Settings not saving
**Solution:**
```bash
# Check MySQL connection
mysql -u root -psecret123 acs -e "SELECT * FROM settings LIMIT 1;"

# Test API
curl -X POST http://localhost:8888/api/settings_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"save_general","general":{"site_name":"Test"}}'
```

---

## 🚀 **Summary**

### What You Get:
✅ **Zero manual steps** for new installations  
✅ **Automatic migration** for existing installations  
✅ **Backward compatible** with fallback to file  
✅ **Better performance** with MySQL caching  
✅ **Centralized management** - everything in database  
✅ **Automatic backups** with database dumps  

### What Changed:
- ✅ settings.json → MySQL `settings` table
- ✅ File still works as fallback
- ✅ API transparent (no frontend changes)
- ✅ install.sh handles everything

**READY TO DEPLOY!** 🎊

---

*Documentation complete: 2026-01-03 23:10 WIB*
