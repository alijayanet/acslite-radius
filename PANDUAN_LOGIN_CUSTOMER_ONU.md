# 🔐 Panduan Login Pelanggan & Sinkronisasi ONU

## 📋 Cara Login Pelanggan

### **Metode 1: Login via Database (RECOMMENDED)** ✅

Pelanggan login menggunakan data dari tabel `customers` (billing database):

```
URL: customer_login.html
Username: portal_username (dari tabel customers)
Password: portal_password (hashed)
```

#### **Flow Login:**
```
1. Customer buka customer_login.html
2. Masukkan username & password
3. POST /api/customer_api.php
   Body: { username, password }
4. API query database:
   SELECT * FROM customers 
   WHERE portal_username = ? OR pppoe_username = ?
5. Verify password (bcrypt)
6. Fetch device data dari GenieACS menggunakan onu_serial
7. Return session data:
   {
     success: true,
     username: "customer1",
     serialNumber: "ZTEG12345678",
     device: { ... data lengkap dari GenieACS ... },
     customerData: { ... data billing ... }
   }
8. Save ke sessionStorage
9. Redirect ke customer_dashboard.html
```

---

## 🔄 Sinkronisasi Data Pelanggan dengan ONU

### **Struktur Database:**

#### **Tabel `customers`** (Billing):
```sql
CREATE TABLE customers (
  id INT PRIMARY KEY AUTO_INCREMENT,
  customer_id VARCHAR(20) UNIQUE,
  name VARCHAR(100),
  portal_username VARCHAR(50) UNIQUE,  ← Login username
  portal_password VARCHAR(255),        ← Login password (bcrypt)
  onu_serial VARCHAR(50),               ← Link ke ONU (PENTING!)
  pppoe_username VARCHAR(50),
  phone VARCHAR(20),
  address TEXT,
  package_id INT,
  status ENUM('active', 'suspended', 'terminated'),
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

#### **Tabel `onu_locations`** (Map/Location):
```sql
CREATE TABLE onu_locations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  serial_number VARCHAR(50) UNIQUE,    ← Link ke ONU
  name VARCHAR(100),
  username VARCHAR(50),                ← Login alternatif
  password VARCHAR(255),               ← Hashed
  latitude DECIMAL(10, 8),
  longitude DECIMAL(11, 8),
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

---

## 🔗 Cara Menghubungkan Customer dengan ONU

### **Opsi 1: Via Halaman Customers (RECOMMENDED)**

1. **Admin buka `customers.html`**
2. **Tambah/Edit customer**
3. **Isi field `ONU Serial`** dengan serial number ONU
4. **Simpan**

```javascript
// Data yang disimpan:
{
  name: "John Doe",
  portal_username: "john123",      ← Username login
  portal_password: "hashed...",    ← Password (bcrypt)
  onu_serial: "ZTEG12345678",      ← Link ke ONU ⭐
  pppoe_username: "john@isp",
  phone: "08123456789",
  address: "Jl. Example No. 123",
  package_id: 1
}
```

### **Opsi 2: Via Halaman ACS (Map)**

1. **Admin buka `acs.html`**
2. **Klik tombol "Set Location" pada ONU**
3. **Isi koordinat + username + password**
4. **Simpan ke `onu_locations`**

```javascript
// Data yang disimpan:
{
  serial_number: "ZTEG12345678",
  name: "John Doe",
  username: "john123",             ← Login alternatif
  password: "hashed...",
  latitude: -6.200000,
  longitude: 106.816666
}
```

---

## 📊 Data Flow Lengkap

### **Scenario 1: Customer Login Normal**

```
┌─────────────────────┐
│ Customer Login      │
│ (customer_login)    │
└──────────┬──────────┘
           │
           │ POST { username, password }
           ▼
┌─────────────────────┐
│ customer_api.php    │
│ - Query customers   │
│ - Verify password   │
│ - Get onu_serial    │
└──────────┬──────────┘
           │
           │ Fetch device by serial
           ▼
┌─────────────────────┐
│ GenieACS API        │
│ GET /api/devices    │
│ Filter: serial_num  │
└──────────┬──────────┘
           │
           │ Return device data:
           │ - wifi_services
           │ - parameters
           │ - rx_power, temp, etc.
           ▼
┌─────────────────────┐
│ Session Data        │
│ {                   │
│   username,         │
│   serialNumber,     │
│   customerData: {   │
│     ...device,      │
│     ...billing      │
│   }                 │
│ }                   │
└──────────┬──────────┘
           │
           │ Save to sessionStorage
           ▼
┌─────────────────────┐
│ Customer Dashboard  │
│ - Display ONU info  │
│ - WiFi management   │
│ - Billing info      │
└─────────────────────┘
```

### **Scenario 2: Admin Sudah Buka ACS**

```
┌─────────────────────┐
│ Admin buka acs.html │
└──────────┬──────────┘
           │
           │ Fetch all devices
           ▼
┌─────────────────────┐
│ GenieACS API        │
│ GET /api/devices    │
└──────────┬──────────┘
           │
           │ Save to localStorage
           │ Key: 'acs_devices'
           ▼
┌─────────────────────┐
│ localStorage        │
│ [                   │
│   { serial, wifi... │
│   { serial, wifi... │
│   ...               │
│ ]                   │
└──────────┬──────────┘
           │
           │ Customer login
           ▼
┌─────────────────────┐
│ Customer Dashboard  │
│ - Read localStorage │
│ - Find by serial    │
│ - Data LENGKAP! ✅  │
└─────────────────────┘
```

---

## 🛠️ Cara Setup Customer Baru

### **Langkah 1: Tambah Customer di Database**

**Via `customers.html`:**

```
1. Klik "Tambah Pelanggan"
2. Isi form:
   - Nama: John Doe
   - Username Portal: john123        ← Login username
   - Password Portal: password123    ← Login password
   - ONU Serial: ZTEG12345678        ← PENTING! Link ke ONU
   - PPPoE Username: john@isp
   - Telepon: 08123456789
   - Alamat: Jl. Example No. 123
   - Paket: Pilih paket
3. Klik "Simpan"
```

**Via SQL Direct:**

```sql
INSERT INTO customers (
  customer_id,
  name,
  portal_username,
  portal_password,
  onu_serial,
  pppoe_username,
  phone,
  address,
  package_id,
  status
) VALUES (
  'CUST001',
  'John Doe',
  'john123',                                    -- Login username
  '$2y$10$...',                                 -- Bcrypt hash
  'ZTEG12345678',                               -- ONU Serial ⭐
  'john@isp',
  '08123456789',
  'Jl. Example No. 123',
  1,
  'active'
);
```

### **Langkah 2: Pastikan ONU Terdaftar di GenieACS**

```
1. ONU harus sudah connect ke GenieACS (port 7547)
2. Cek di acs.html apakah ONU muncul
3. Serial number harus MATCH dengan onu_serial di database
```

### **Langkah 3: Customer Login**

```
1. Customer buka: customer_login.html
2. Masukkan:
   - Username: john123
   - Password: password123
3. Klik "Masuk"
4. Sistem akan:
   - Query database customers
   - Ambil onu_serial: ZTEG12345678
   - Fetch data ONU dari GenieACS
   - Gabungkan data billing + device
   - Redirect ke dashboard
```

---

## 🔍 Troubleshooting

### **Problem 1: Customer tidak bisa login**

**Penyebab:**
- Username/password salah
- Data tidak ada di tabel `customers`
- Password tidak di-hash dengan bcrypt

**Solusi:**
```sql
-- Cek apakah customer ada
SELECT * FROM customers WHERE portal_username = 'john123';

-- Reset password (hash baru)
UPDATE customers 
SET portal_password = '$2y$10$...'  -- Hash dari 'password123'
WHERE portal_username = 'john123';
```

### **Problem 2: Data ONU tidak muncul**

**Penyebab:**
- `onu_serial` di database tidak match dengan serial di GenieACS
- ONU belum connect ke GenieACS
- GenieACS API tidak bisa diakses

**Solusi:**
```sql
-- Cek onu_serial di database
SELECT onu_serial FROM customers WHERE portal_username = 'john123';
-- Result: ZTEG12345678

-- Cek apakah serial ini ada di GenieACS
-- Buka acs.html, cari ZTEG12345678

-- Jika tidak ada, pastikan ONU sudah connect ke ACS server
```

### **Problem 3: WiFi management tidak bisa**

**Penyebab:**
- Data `wifi_services` dan `parameters` tidak lengkap
- Admin belum buka halaman ACS

**Solusi:**
```
1. Admin buka acs.html
2. Data semua ONU akan di-fetch dari GenieACS
3. Data disimpan ke localStorage
4. Customer refresh dashboard
5. WiFi management akan enabled
```

---

## 📝 Contoh Data Session

### **Session yang Disimpan:**

```javascript
{
  "username": "john123",
  "serialNumber": "ZTEG12345678",
  "customerData": {
    // Data dari GenieACS
    "serial_number": "ZTEG12345678",
    "product_class": "F670L",
    "manufacturer": "ZTE",
    "ip_address": "192.168.1.100",
    "ssid": "MyWiFi_5G",
    "pppoe_user": "john@isp",
    "rx_power": "-21.5",
    "temperature": "45",
    "online": true,
    "wifi_services": {
      "1": {
        "ssid_path": "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID",
        "password_path": "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase"
      }
    },
    "parameters": {
      "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID": "MyWiFi_5G",
      "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase": "password123",
      // ... 100+ TR-069 parameters
    },
    
    // Data dari Billing
    "name": "John Doe",
    "phone": "08123456789",
    "address": "Jl. Example No. 123",
    "dbId": 1,
    "customerId": "CUST001"
  },
  "loginTime": 1704340800000,
  "expiry": 1704369600000  // 8 jam
}
```

---

## ✅ Checklist Setup Customer

- [ ] **Customer ada di tabel `customers`**
- [ ] **`portal_username` terisi** (untuk login)
- [ ] **`portal_password` terisi** (bcrypt hash)
- [ ] **`onu_serial` terisi** (link ke ONU) ⭐ **PENTING!**
- [ ] **ONU sudah connect ke GenieACS**
- [ ] **Serial number MATCH** antara database & GenieACS
- [ ] **Admin sudah buka `acs.html`** minimal 1x (untuk cache)
- [ ] **Customer bisa login** via `customer_login.html`
- [ ] **Data ONU muncul** di dashboard
- [ ] **WiFi management berfungsi**

---

## 🎯 Kesimpulan

**Sinkronisasi Customer dengan ONU:**

1. ✅ **Database Link**: Field `onu_serial` di tabel `customers`
2. ✅ **Login**: Via `portal_username` + `portal_password`
3. ✅ **Fetch Device**: API ambil data dari GenieACS by serial
4. ✅ **Merge Data**: Billing data + Device data
5. ✅ **Display**: Semua info muncul di dashboard
6. ✅ **WiFi Management**: Customer bisa ubah SSID & password

**Key Point:**
> **Field `onu_serial` di tabel `customers` adalah KUNCI untuk menghubungkan data pelanggan dengan ONU mereka!**

Tanpa field ini, sistem tidak tahu ONU mana yang dimiliki customer tersebut.
