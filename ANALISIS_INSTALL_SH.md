# Analisis install.sh - Go-ACS Installation Script

## 📋 Ringkasan
File `install.sh` adalah script instalasi utama untuk aplikasi Go-ACS. Dokumen ini menganalisis struktur saat ini dan memberikan rekomendasi perbaikan.

**Status:** ✅ **SEBAGIAN BESAR SUDAH DIPERBAIKI** (2025-01-14)

---

## 🔍 Masalah yang Ditemukan

### 1. **Tabel yang Hilang dari install.sh** ✅ **SUDAH DIPERBAIKI**

Berdasarkan file migration `002_hotspot_voucher_system.sql`:

- ✅ `voucher_batches` - **SUDAH ADA** di install.sh (line 465-485)
- ✅ `hotspot_sales` - **SUDAH ADA** di install.sh (line 488-505)
- ✅ `hotspot_profile_stats` - **SUDAH DITAMBAHKAN** (line 532-545)

### 2. **Struktur Tabel Tidak Lengkap** ✅ **SUDAH DIPERBAIKI**

#### `hotspot_vouchers`:
- ✅ **SUDAH LENGKAP** (18 kolom)
- ✅ Status: `('unused', 'sold', 'active', 'expired', 'disabled')`
- ✅ Semua field ada (created_date, sold_date, first_login, last_login, expired_date, mac_address, comment, scheduler_name, mikrotik_comment, limit_uptime)
- ✅ Index idx_username sudah ditambahkan

#### `hotspot_profiles`:
- ✅ **SUDAH LENGKAP**
- ✅ Field duration_seconds, validity_type, created_date, updated_date sudah ada
- ✅ session_timeout dan idle_timeout sebagai INT (bukan VARCHAR)

### 3. **Fitur Database yang Hilang** ✅ **SUDAH DIPERBAIKI**

Berikut adalah fitur yang ada di migration file dan **SUDAH DITAMBAHKAN** ke install.sh:

- ✅ **Views**: `v_daily_revenue`, `v_profile_performance`, `v_batch_summary` (line 562-609)
- ✅ **Stored Procedures**: `sp_update_batch_stats`, `sp_update_profile_stats` (line 617-652)
- ✅ **Triggers**: `tr_after_voucher_sale`, `tr_after_voucher_status_update` (line 662-684)
- ✅ **Index tambahan**: `idx_voucher_batch_status`, `idx_voucher_profile_status`, `idx_sale_batch_date` (line 554-556)

### 4. **Struktur Tidak Terorganisir** ⚠️ **MEDIUM PRIORITY**

- Tabel-tabel tersebar antara "initial creation" dan "migration"
- Tidak ada komentar jelas untuk setiap section
- Sulit untuk menambah tabel baru karena tidak ada template yang jelas

---

## ✅ Rekomendasi Perbaikan

### 1. **Reorganisasi Struktur install.sh**

Struktur yang disarankan:
```
PART 1: DATABASE SETUP
  ├── 1. Install MariaDB
  ├── 2. Create Database
  └── 3. Create Initial Tables (untuk fresh install)
       ├── Core Tables (onu_locations, customers, packages, invoices, payments)
       ├── Telegram Tables (telegram_config, telegram_admins)
       └── Hotspot Tables (hotspot_profiles, hotspot_vouchers, voucher_batches, hotspot_sales, hotspot_profile_stats)

PART 2: DATABASE MIGRATIONS (untuk existing installations)
  ├── Migration 001: Add customer login to onu_locations
  ├── Migration 002: Add portal login to customers
  ├── Migration 003: Add mikrotik_profile_isolir to packages
  └── Migration 004+: Future migrations
```

### 2. **Sinkronkan dengan Migration Files**

Semua tabel dari migration files harus ada di install.sh:
- ✅ Lengkapi struktur `hotspot_vouchers`
- ✅ Lengkapi struktur `hotspot_profiles`
- ✅ Tambahkan `voucher_batches`
- ✅ Tambahkan `hotspot_sales`
- ✅ Tambahkan `hotspot_profile_stats`
- ✅ Tambahkan Views, Procedures, Triggers (opsional, bisa di migration section)

### 3. **Template untuk Menambah Tabel Baru**

Buat section yang jelas dengan template:
```bash
# ========================================
# TABLE: nama_tabel
# Purpose: Deskripsi fungsi tabel
# Migration: 00X_nama_migration.sql
# ========================================
CREATE TABLE IF NOT EXISTS nama_tabel (
    -- kolom-kolom
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4. **Konsistensi Naming**

- Gunakan `created_at` atau `created_date` secara konsisten
- Gunakan `updated_at` atau `updated_date` secara konsisten
- Status ENUM harus konsisten dengan migration files

---

## 📝 Checklist untuk Update install.sh

- [ ] Tambahkan tabel `voucher_batches`
- [ ] Tambahkan tabel `hotspot_sales`
- [ ] Tambahkan tabel `hotspot_profile_stats`
- [ ] Lengkapi struktur `hotspot_vouchers` (tambah semua field dari migration)
- [ ] Lengkapi struktur `hotspot_profiles` (tambah duration_seconds, validity_type, dll)
- [ ] Perbaiki ENUM status di `hotspot_vouchers` (tambah 'sold' dan 'active')
- [ ] Reorganisasi struktur dengan komentar yang jelas
- [ ] Tambahkan template section untuk memudahkan penambahan tabel baru
- [ ] Test install script pada fresh database
- [ ] Test migration pada existing database

---

## 🎯 Prioritas

**HIGH:**

---

## 📡 Runbook Go-Live RADIUS (Hotspot + PPPoE)

### 1) Prasyarat Server

- **FreeRADIUS aktif**: `systemctl status freeradius`
- **Database radius siap**: tabel `radcheck`, `radreply`, `radacct` ada
- **Firewall**: UDP `1812` dan `1813` terbuka dari MikroTik ke server

### 2) Prasyarat Aplikasi

- Pastikan `settings.json` sudah berisi DB RADIUS:
  - `hotspot.radius.enabled = true`
  - `hotspot.radius.db_host/db_port/db_name/db_user/db_pass` benar

### 3) NAS Client (MikroTik) -> FreeRADIUS

- Buka `http://IP:7547/web/radius.html`
- Tab **NAS Clients**:
  - Tambah **IP MikroTik** + **shared secret**
  - Klik **Apply to FreeRADIUS** (akan membuat include file dan restart freeradius)

### 4) Apply konfigurasi RADIUS ke MikroTik (Hotspot)

- Buka `http://IP:7547/web/settings.html`
- Section **RADIUS Settings**:
  - Pilih **Target Router**
  - Isi **RADIUS Server IP** (kalau server tidak NAT, bisa kosong)
  - Klik **Apply RADIUS to MikroTik**

### 5) Apply konfigurasi RADIUS ke MikroTik (PPPoE)

- Buka `http://IP:7547/web/settings.html`
- Section **RADIUS Settings**:
  - Pilih **Target Router**
  - Isi **RADIUS Server IP** (kalau server tidak NAT, bisa kosong)
  - Klik **Apply PPPoE RADIUS to MikroTik**

### 6) Provisioning User PPPoE via RADIUS

- Buka `http://IP:7547/web/radius.html`
- Tab **PPPoE Users**:
  - Buat **PPPoE Plan** (rate-limit + session timeout)
  - Buat user PPPoE (pilih plan)

### 7) Voucher Hotspot via RADIUS

- Buat/kelola voucher di `http://IP:7547/web/hotspot.html`
- Sync ke RADIUS:
  - Buka `http://IP:7547/web/radius.html` tab **Sync Voucher**
  - Klik **Run Sync Now**

### 8) Verifikasi

- **RADIUS DB OK**: tombol Test DB di `radius.html`
- **Accounting masuk**: tab **Accounting** (`radacct` terisi setelah user login)
- **PPPoE test**: user PPPoE yang dibuat bisa konek dan dapat speed sesuai plan
- **Hotspot test**: voucher bisa login dan rate-limit/session-timeout diterapkan
1. Tambahkan tabel yang hilang (voucher_batches, hotspot_sales, hotspot_profile_stats)
2. Lengkapi struktur hotspot_vouchers dan hotspot_profiles

**MEDIUM:**
3. Reorganisasi struktur dengan komentar jelas
4. Tambahkan template section

**LOW:**
5. Tambahkan Views, Procedures, Triggers (bisa di migration section saja)

---

## 📚 Referensi Files

- `install.sh` - Main installation script
- `web/migrations/001_create_onu_locations.sql`
- `web/migrations/002_add_customer_login.sql`
- `web/migrations/002_hotspot_voucher_system.sql` ⚠️ **PENTING**
- `web/migrations/003_create_billing_tables.sql`
- `web/migrations/004_add_mikrotik_profile_isolir.sql`
- `web/api/voucher_api.php` - Menggunakan tabel-tabel yang hilang

