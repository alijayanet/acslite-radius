# 🔄 Revisi: Customer Dashboard - Data ONU dari Database

## 📋 Analisis Baru

Setelah mengecek `db_admin.html` dan struktur database, saya menemukan bahwa:

### **Struktur Data yang Benar:**

1. **Database MySQL** (`acs`):
   - Tabel `onu_locations`: Menyimpan data ONU + customer login
     - `serial_number` (UNIQUE)
     - `name` (nama customer)
     - `username` (login customer)
     - `password` (hashed)
     - `latitude`, `longitude`
   
   - Tabel `customers`: Data billing customer
     - Terhubung dengan `onu_locations` via `serial_number` atau `pppoe_user`

2. **GenieACS** (Port 7547):
   - Menyimpan data real-time ONU:
     - `wifi_services` (SSID, password paths)
     - `parameters` (TR-069 parameters)
     - `rx_power`, `temperature`, dll.

### **Masalah dengan Implementasi Sebelumnya:**

❌ Customer dashboard hanya mengandalkan `localStorage` yang disimpan dari admin dashboard  
❌ Data tidak persistent jika browser di-clear  
❌ Tidak ada sinkronisasi dengan database  

### **Solusi yang Benar:**

✅ Customer login menggunakan data dari tabel `onu_locations`  
✅ Setelah login, fetch data ONU dari **GenieACS API** menggunakan `serial_number`  
✅ Gabungkan data dari database (customer info) + GenieACS (device info)  
✅ Simpan ke session untuk performa  

## 🔧 Perubahan yang Diperlukan

### 1. **Customer Login API** (`customer_api.php`)
Harus query dari tabel `onu_locations`:
```php
SELECT * FROM onu_locations WHERE username = ? AND password = ?
```

### 2. **Customer Dashboard** (`customer_dashboard.html`)
Setelah login, fetch data ONU:
```javascript
// 1. Get serial_number from session (dari login)
// 2. Fetch dari GenieACS: GET /api/devices?sn={serial_number}
// 3. Gabungkan dengan data customer dari session
// 4. Tampilkan + enable WiFi management
```

### 3. **Data Flow yang Benar:**

```
Customer Login
  ↓
Query onu_locations (get serial_number)
  ↓
Fetch GenieACS API (get device data)
  ↓
Merge data
  ↓
Display + WiFi Management
```

## ✅ Kesimpulan

Implementasi sebelumnya **sudah benar** dalam hal:
- WiFi management functions
- UI/UX
- Validasi

Yang perlu diperbaiki:
- **Data source**: Harus dari database + GenieACS API, bukan localStorage
- **Customer login**: Harus query `onu_locations` table
- **Data persistence**: Gunakan database sebagai source of truth

## 📝 Next Steps

1. ✅ Cek `customer_api.php` - pastikan login query dari `onu_locations`
2. ✅ Update `customer_dashboard.html` - fetch dari GenieACS API
3. ✅ Test flow lengkap: Login → Fetch → Display → WiFi Management
