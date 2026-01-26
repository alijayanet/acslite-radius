# ✨ Fitur Baru: Login dengan PPPoE Username

## 🎯 Masalah yang Diselesaikan

**Sebelumnya:**
- Customer harus tahu serial number ONU (contoh: `ZTEG12345678`)
- Serial number susah dihapal
- Admin harus manual input `onu_serial` di database

**Sekarang:**
- ✅ Customer bisa login tanpa perlu tahu serial number
- ✅ Sistem otomatis mencari ONU berdasarkan PPPoE username
- ✅ Database otomatis di-update dengan serial number yang ditemukan

---

## 🔧 Cara Kerja

### **Dual Lookup System:**

```
Customer Login
  ↓
Query database customers
  ↓
┌─────────────────────────────────────┐
│ PRIORITY 1: Cek onu_serial          │
│ - Jika ada, gunakan serial number   │
│ - Fetch device dari GenieACS         │
└──────────────┬──────────────────────┘
               │
               │ Jika tidak ada atau gagal
               ▼
┌─────────────────────────────────────┐
│ PRIORITY 2: Cek pppoe_username      │
│ - Fetch SEMUA devices dari GenieACS │
│ - Cari yang PPPoE username match    │
│ - Return device data lengkap         │
└──────────────┬──────────────────────┘
               │
               │ Jika ditemukan
               ▼
┌─────────────────────────────────────┐
│ AUTO-UPDATE Database                │
│ UPDATE customers                     │
│ SET onu_serial = 'ZTEG12345678'     │
│ WHERE id = customer_id               │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Return Session Data                 │
│ - serialNumber (dari PPPoE lookup)  │
│ - device (data lengkap ONU)         │
│ - customerData (billing info)       │
└─────────────────────────────────────┘
```

---

## 📝 Setup Customer (2 Cara)

### **Cara 1: Dengan Serial Number (Seperti Biasa)**

```sql
INSERT INTO customers (
  name,
  portal_username,
  portal_password,
  onu_serial,           -- ✅ Diisi manual
  pppoe_username,
  package_id
) VALUES (
  'John Doe',
  'john123',
  '$2y$10$...',        -- Bcrypt hash
  'ZTEG12345678',      -- ✅ Serial number ONU
  'john@isp',
  1
);
```

**Login:**
```
Username: john123
Password: password123
→ Sistem langsung fetch ONU via serial: ZTEG12345678
```

---

### **Cara 2: Tanpa Serial Number (BARU!)** ⭐

```sql
INSERT INTO customers (
  name,
  portal_username,
  portal_password,
  onu_serial,           -- ❌ NULL / Kosong
  pppoe_username,       -- ✅ Hanya perlu PPPoE username
  package_id
) VALUES (
  'Jane Doe',
  'jane456',
  '$2y$10$...',
  NULL,                 -- ❌ Tidak perlu serial!
  'jane@isp',           -- ✅ PPPoE username
  1
);
```

**Login Pertama Kali:**
```
Username: jane456
Password: password123

→ Sistem cek onu_serial: NULL
→ Sistem cari ONU dengan PPPoE username: jane@isp
→ Ditemukan ONU: ZTEG87654321
→ Database di-update otomatis:
   UPDATE customers SET onu_serial = 'ZTEG87654321' WHERE id = 2
→ Login berhasil!
```

**Login Berikutnya:**
```
Username: jane456
Password: password123

→ Sistem cek onu_serial: ZTEG87654321 (sudah ada!)
→ Langsung fetch via serial (lebih cepat)
→ Login berhasil!
```

---

## 🔍 Fungsi Baru: `findDeviceByPPPoE()`

### **Lokasi:** `web/api/customer_api.php` (Lines 241-323)

```php
function findDeviceByPPPoE($pppoeUsername) {
    // 1. Fetch semua devices dari GenieACS
    $devices = fetchAllDevices();
    
    // 2. Cari device yang PPPoE username-nya match
    foreach ($devices as $device) {
        $params = $device['parameters'];
        
        // Cek di berbagai path TR-069 yang mungkin
        $pppoeKeys = [
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.2.Username'
        ];
        
        foreach ($pppoeKeys as $key) {
            if ($params[$key] === $pppoeUsername) {
                // FOUND! Return full device data
                return getGenieDevice($device['serial_number']);
            }
        }
    }
    
    return null; // Not found
}
```

---

## 📊 Perbandingan: Sebelum vs Sesudah

| Aspek | Sebelum | Sesudah |
|-------|---------|---------|
| **Setup Customer** | Harus tahu serial ONU | Cukup PPPoE username |
| **Input Admin** | Manual input `onu_serial` | Otomatis dari PPPoE |
| **Customer Login** | Perlu serial number | Cukup username/password |
| **Maintenance** | Update manual jika ONU ganti | Auto-update saat login |
| **User Experience** | Ribet | Mudah ✅ |

---

## 🎬 Contoh Skenario

### **Skenario 1: Customer Baru (Tanpa Serial)**

**Admin:**
```sql
-- Tambah customer baru
INSERT INTO customers (name, portal_username, portal_password, pppoe_username, package_id)
VALUES ('Budi', 'budi789', '$2y$10$...', 'budi@isp', 1);

-- onu_serial = NULL (tidak perlu diisi!)
```

**Customer:**
```
1. Buka customer_login.html
2. Login:
   Username: budi789
   Password: password123
3. Sistem:
   ✅ Query database: customer ditemukan
   ✅ onu_serial = NULL
   ✅ Cari ONU dengan PPPoE: budi@isp
   ✅ Ditemukan: ZTEG11223344
   ✅ Update database: onu_serial = ZTEG11223344
   ✅ Login berhasil!
4. Dashboard muncul dengan data ONU lengkap
```

---

### **Skenario 2: ONU Ganti (Auto-Update)**

**Situasi:**
- Customer lama: `onu_serial = ZTEG12345678`
- ONU rusak, diganti dengan ONU baru: `ZTEG99887766`
- PPPoE username tetap: `customer@isp`

**Proses:**
```
1. Customer login seperti biasa
2. Sistem coba fetch ONU dengan serial lama: ZTEG12345678
3. Gagal (ONU sudah tidak connect)
4. Sistem fallback ke PPPoE lookup: customer@isp
5. Ditemukan ONU baru: ZTEG99887766
6. Database auto-update:
   UPDATE customers SET onu_serial = 'ZTEG99887766'
7. Login berhasil dengan ONU baru!
```

---

## ✅ Keuntungan Fitur Ini

### **Untuk Admin:**
- ✅ Tidak perlu manual input serial number
- ✅ Tidak perlu update database saat ONU ganti
- ✅ Setup customer lebih cepat
- ✅ Maintenance lebih mudah

### **Untuk Customer:**
- ✅ Tidak perlu hapal serial number
- ✅ Login lebih mudah (username/password saja)
- ✅ Tetap bisa login meski ONU diganti
- ✅ User experience lebih baik

### **Untuk Sistem:**
- ✅ Database selalu up-to-date
- ✅ Fallback mechanism yang robust
- ✅ Auto-healing saat ONU ganti
- ✅ Backward compatible (serial number tetap bisa dipakai)

---

## 🔐 Security & Performance

### **Security:**
- ✅ PPPoE username harus match dengan data di ONU
- ✅ Password tetap di-verify dengan bcrypt
- ✅ Tidak ada security risk tambahan

### **Performance:**

**Login dengan Serial (Fast):**
```
1 API call ke GenieACS
~100-200ms
```

**Login dengan PPPoE (Slower, tapi hanya sekali):**
```
1. Fetch all devices: ~500-1000ms
2. Search PPPoE: ~50-100ms
3. Update database: ~50ms
Total: ~600-1150ms (hanya login pertama)

Login berikutnya: Fast (sudah ada serial)
```

---

## 📋 Checklist Setup Customer Baru

### **Opsi 1: Dengan Serial (Tradisional)**
- [x] Isi `portal_username`
- [x] Isi `portal_password` (bcrypt)
- [x] Isi `onu_serial` ⭐
- [x] Isi `pppoe_username`
- [x] Customer bisa login

### **Opsi 2: Tanpa Serial (BARU!)** ⭐
- [x] Isi `portal_username`
- [x] Isi `portal_password` (bcrypt)
- [ ] ~~Isi `onu_serial`~~ (TIDAK PERLU!)
- [x] Isi `pppoe_username` ⭐⭐⭐
- [x] Pastikan ONU sudah connect ke GenieACS
- [x] Pastikan PPPoE username di ONU match dengan database
- [x] Customer bisa login (serial auto-update)

---

## 🎯 Kesimpulan

### **Fitur Baru:**
> **Customer bisa login tanpa perlu tahu serial number ONU!**

### **Cara Kerja:**
1. ✅ Sistem coba serial number dulu (jika ada)
2. ✅ Jika gagal, cari berdasarkan PPPoE username
3. ✅ Jika ditemukan, auto-update database
4. ✅ Login berhasil dengan data ONU lengkap

### **Manfaat:**
- ✅ Setup lebih mudah
- ✅ Maintenance lebih simple
- ✅ User experience lebih baik
- ✅ Auto-healing saat ONU ganti

---

**Status:** ✅ **IMPLEMENTED & READY TO USE**
