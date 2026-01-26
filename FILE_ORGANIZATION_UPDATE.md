# 📁 File Organization Update - Settings Migration

**Date:** 2026-01-03 23:16 WIB  
**Status:** ✅ REORGANIZED  

---

## 🎯 **What Changed**

### **Before (Root-level Files):**
```
acs-radius/
├── sql/
│   └── create_settings_table.sql ❌ 
├── migrate_settings_to_db.php ❌
├── install.sh
└── web/
    ├── api/
    ├── data/
    ├── migrations/ ✅ (already exists)
    └── templates/
```

### **After (Organized Structure):**
```
acs-radius/
├── install.sh ✅ (updated paths)
└── web/
    ├── api/
    ├── data/
    ├── migrations/ ✅
    │   ├── 001_create_onu_locations.sql
    │   ├── 002_add_customer_login.sql
    │   ├── 002_hotspot_voucher_system.sql
    │   ├── 003_create_billing_tables.sql
    │   ├── 004_add_mikrotik_profile_isolir.sql
    │   ├── 005_create_settings_table.sql ✅ NEW
    │   └── migrate_settings_to_db.php ✅ NEW
    └── templates/
```

---

## 📝 **Changes Made**

### 1. **Moved SQL Migration** ✅
**From:** `sql/create_settings_table.sql`  
**To:** `web/migrations/005_create_settings_table.sql`

**Why:**
- ✅ Konsisten dengan migration files lain (001, 002, 003, 004)
- ✅ Sequential numbering (005)
- ✅ Semua SQL migrations di satu tempat

### 2. **Moved PHP Script** ✅
**From:** `migrate_settings_to_db.php` (root)  
**To:** `web/migrations/migrate_settings_to_db.php`

**Why:**
- ✅ Dekat dengan SQL migration file
- ✅ Tidak clutter di root directory
- ✅ Easier to find & maintain

### 3. **Updated install.sh Paths** ✅
**Changed:**
```bash
# OLD
if [ -f "migrate_settings_to_db.php" ]; then
    php migrate_settings_to_db.php

# NEW
if [ -f "web/migrations/migrate_settings_to_db.php" ]; then
    php web/migrations/migrate_settings_to_db.php
```

**Also added:**
- ✅ Better error messages
- ✅ Info message if script not found
- ✅ Info message if no settings.json

---

## 🎯 **Benefits**

| Aspect | Before | After |
|--------|--------|-------|
| **Organization** | ❌ Files scattered | ✅ Centralized in migrations/ |
| **Discoverability** | ❌ Hard to find | ✅ Easy to locate |
| **Consistency** | ❌ Inconsistent naming | ✅ Follows 00X pattern |
| **Maintenance** | ❌ Multiple locations | ✅ Single location |
| **Git Diff** | ❌ Cluttered root | ✅ Clean structure |

---

## 📂 **Current Migration Files**

```bash
web/migrations/
├── 001_create_onu_locations.sql          # ONU location tracking
├── 002_add_customer_login.sql            # Customer portal
├── 002_hotspot_voucher_system.sql        # Hotspot vouchers
├── 003_create_billing_tables.sql         # Billing system
├── 004_add_mikrotik_profile_isolir.sql   # MikroTik isolation
├── 005_create_settings_table.sql         # Settings migration ✅ NEW
└── migrate_settings_to_db.php            # Migration helper ✅ NEW
```

**Pattern:**
- `00X_*.sql` - Database schema migrations
- `*.php` - Data migration helpers

---

## 🚀 **Usage (No Changes Required!)**

### **Fresh Installation:**
```bash
sudo bash install.sh
```
**Auto-runs:** `web/migrations/migrate_settings_to_db.php` if settings.json exists

### **Existing Installation:**
```bash
git pull
sudo bash install.sh
```
**Auto-runs:** Migration with new path ✅

### **Manual Migration (if needed):**
```bash
# OLD (deprecated)
php migrate_settings_to_db.php

# NEW (correct path)
php web/migrations/migrate_settings_to_db.php
```

---

## ✅ **Testing**

### Test 1: Fresh Install
```bash
# Run installer
sudo bash install.sh

# Expected: Settings table created with defaults
mysql -u root -p acs -e "SELECT COUNT(*) FROM settings;"
# Should return: 6
```

### Test 2: Existing Install with settings.json
```bash
# Create test settings.json
cat > web/data/settings.json << 'EOF'
{
    "general": {
        "site_name": "Test Site"
    }
}
EOF

# Run installer
sudo bash install.sh

# Expected: 
# [INFO] Found existing settings.json, migrating to database...
# [SUCCESS] Settings migrated from settings.json to MySQL.

# Verify
mysql -u root -p acs -e "SELECT JSON_EXTRACT(settings_json, '$.site_name') FROM settings WHERE category='general';"
# Should return: "Test Site"
```

### Test 3: Manual Migration
```bash
php web/migrations/migrate_settings_to_db.php

# Expected:
# [1/4] Reading database credentials...
# [2/4] Connecting to MySQL...
# [3/4] Reading settings.json...
# [4/4] Migrating to database...
# ✓ SUCCESS
```

---

## 🗂️ **Old Files (Can be Removed)**

After confirming everything works:

```bash
# Optional: Remove old files if they exist
rm -f sql/create_settings_table.sql
rm -f migrate_settings_to_db.php
rmdir sql  # If empty
```

**Note:** Old files might not exist on fresh clone, as they were moved before commit.

---

## 📝 **Documentation Updated**

- ✅ `SETTINGS_MIGRATION_COMPLETE.md` - Still valid (references new paths)
- ✅ `ANALYSIS_SETTINGS_MIGRATION.md` - Implementation guide
- ✅ This file - Organization changelog

---

## ✅ **Checklist**

- [x] Moved SQL to `web/migrations/005_create_settings_table.sql`
- [x] Moved PHP to `web/migrations/migrate_settings_to_db.php`
- [x] Updated `install.sh` paths
- [x] Updated `install.sh` error messages
- [x] Tested fresh installation
- [x] Tested existing installation
- [x] Documentation updated

---

## 🎉 **Summary**

**What:** Reorganized settings migration files  
**Where:** `web/migrations/` folder  
**Why:** Better organization & consistency  
**Impact:** ✅ Zero breaking changes (install.sh updated)  

**Status:** READY TO DEPLOY ✅

---

*File organization complete: 2026-01-03 23:16 WIB*
