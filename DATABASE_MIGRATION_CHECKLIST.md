# ✅ CHECKLIST DATABASE MIGRATION - INSTALL.SH

## Status: SEMUA SUDAH LENGKAP! ✅

---

## 📋 **Checklist Tabel & Field di install.sh:**

### **1. ✅ Tabel `customers`**

**Location:** Lines 225-250

**Fields yang sudah ada:**
- [x] `id` INT PRIMARY KEY
- [x] `customer_id` VARCHAR(20) UNIQUE
- [x] `name` VARCHAR(100)
- [x] `phone` VARCHAR(20)
- [x] `email` VARCHAR(100)
- [x] `address` TEXT
- [x] `pppoe_username` VARCHAR(50)
- [x] `pppoe_password` VARCHAR(100)
- [x] **`portal_username` VARCHAR(50)** ← SUDAH ADA! ✅
- [x] **`portal_password` VARCHAR(255)** ← SUDAH ADA! ✅
- [x] `package_id` INT
- [x] `monthly_fee` DECIMAL(12,2)
- [x] `billing_date` TINYINT
- [x] `status` ENUM
- [x] `isolir_date` DATE
- [x] `onu_serial` VARCHAR(50)
- [x] `registered_at` DATE
- [x] `created_at` TIMESTAMP
- [x] `updated_at` TIMESTAMP

**Indexes yang sudah ada:**
- [x] `idx_status` (status)
- [x] `idx_pppoe` (pppoe_username)
- [x] **`idx_portal` (portal_username)** ← SUDAH ADA! ✅
- [x] `idx_onu` (onu_serial)

---

### **2. ✅ Tabel `packages`**

**Location:** Lines 252-263

**Fields yang sudah ada:**
- [x] `id` INT PRIMARY KEY
- [x] `name` VARCHAR(50)
- [x] `speed` VARCHAR(20)
- [x] `price` DECIMAL(12,2)
- [x] `description` TEXT
- [x] **`mikrotik_profile` VARCHAR(50)** ← SUDAH ADA! ✅
- [x] **`mikrotik_profile_isolir` VARCHAR(50)** ← SUDAH ADA! ✅
- [x] `is_active` BOOLEAN
- [x] `created_at` TIMESTAMP

---

### **3. ✅ Tabel `onu_locations`**

**Location:** Lines 209-223

**Fields yang sudah ada:**
- [x] `id` INT PRIMARY KEY
- [x] `serial_number` VARCHAR(50) UNIQUE
- [x] `name` VARCHAR(100)
- [x] `username` VARCHAR(50)
- [x] `password` VARCHAR(255)
- [x] `latitude` DECIMAL(10,8)
- [x] `longitude` DECIMAL(11,8)
- [x] `created_at` TIMESTAMP
- [x] `updated_at` TIMESTAMP

**Indexes:**
- [x] `idx_serial` (serial_number)
- [x] `idx_coords` (latitude, longitude)
- [x] `idx_username` (username) UNIQUE

---

### **4. ✅ Migration Scripts**

**Location:** Lines 346-408

**Migrations yang sudah ada:**

#### **Migration 1: mikrotik_profile_isolir**
```sql
-- Lines 348-365
ALTER TABLE packages 
ADD COLUMN mikrotik_profile_isolir VARCHAR(50) DEFAULT 'isolir'
```
✅ **Status:** SUDAH ADA

#### **Migration 2: portal_username & portal_password**
```sql
-- Lines 367-380
ALTER TABLE customers 
ADD COLUMN portal_username VARCHAR(50) DEFAULT NULL,
ADD COLUMN portal_password VARCHAR(255) DEFAULT NULL
```
✅ **Status:** SUDAH ADA

#### **Migration 3: telegram_config table**
```sql
-- Lines 382-391
CREATE TABLE IF NOT EXISTS telegram_config (...)
```
✅ **Status:** SUDAH ADA

#### **Migration 4: telegram_admins table**
```sql
-- Lines 393-406
CREATE TABLE IF NOT EXISTS telegram_admins (...)
```
✅ **Status:** SUDAH ADA

---

### **5. ✅ Hotspot Voucher System**

**Location:** Lines 419-521

**Tables yang sudah ada:**
- [x] `hotspot_vouchers`
- [x] `voucher_batches`
- [x] `hotspot_sales`
- [x] `hotspot_profiles`

---

### **6. ✅ Settings Table**

**Location:** Lines 529-626

**Table yang sudah ada:**
- [x] `settings` (JSON-based configuration)

**Default settings:**
- [x] `general` (site_name, company, timezone, etc)
- [x] `acs` (api_url, api_key)
- [x] `telegram` (bot_token, chat_id)
- [x] `billing` (due_day, grace_period, auto_isolir)
- [x] `whatsapp` (api_url, api_key)
- [x] `hotspot` (backend, radius config)

---

## 🎯 **KESIMPULAN:**

### ✅ **SEMUA DATABASE MIGRATION SUDAH LENGKAP!**

**Tidak perlu tambahan migration manual!**

### **Fresh Install:**
```bash
sudo bash install.sh
```

**Itu saja!** Semua tabel, field, index, dan migration akan otomatis dibuat.

---

## 📊 **Yang Akan Dibuat Otomatis:**

1. ✅ Database `acs`
2. ✅ Tabel `customers` dengan `portal_username` & `portal_password`
3. ✅ Tabel `packages` dengan `mikrotik_profile` & `mikrotik_profile_isolir`
4. ✅ Tabel `onu_locations`
5. ✅ Tabel `invoices`
6. ✅ Tabel `payments`
7. ✅ Tabel `telegram_config`
8. ✅ Tabel `telegram_admins`
9. ✅ Tabel `hotspot_vouchers`
10. ✅ Tabel `voucher_batches`
11. ✅ Tabel `hotspot_sales`
12. ✅ Tabel `hotspot_profiles`
13. ✅ Tabel `settings`
14. ✅ Semua indexes
15. ✅ Semua migrations untuk upgrade dari versi lama

---

## 🔄 **Upgrade dari Instalasi Lama:**

Jika sudah ada instalasi lama, migration akan:
1. ✅ Cek apakah field `portal_username` sudah ada
2. ✅ Jika belum, tambahkan otomatis
3. ✅ Cek apakah field `portal_password` sudah ada
4. ✅ Jika belum, tambahkan otomatis
5. ✅ Cek apakah field `mikrotik_profile_isolir` sudah ada
6. ✅ Jika belum, tambahkan otomatis
7. ✅ Tidak akan error jika field sudah ada (idempotent)

---

## ✅ **VERIFIED: READY FOR PRODUCTION!**

**Command untuk fresh install:**
```bash
sudo bash install.sh
```

**Tidak perlu perintah tambahan!**
**Tidak perlu migration manual!**
**Semua otomatis!** 🎉
