# Dokumentasi: Integrasi ONU Management di Customer Dashboard

## 📋 Ringkasan Perubahan

File `customer_dashboard.html` telah diperbarui untuk memastikan data ONU yang terdaftar di halaman admin (`acs.html`) dapat ditampilkan dan di-manage (ubah SSID dan password WiFi) dari dashboard pelanggan.

## ✅ Fitur yang Telah Diimplementasikan

### 1. **Sinkronisasi Data ONU dari Admin ke Customer Portal**

#### Mekanisme Kerja:
- **Admin Dashboard** (`acs.html`) menyimpan data lengkap semua ONU ke `localStorage` dengan key `acs_devices`
- **Customer Dashboard** (`customer_dashboard.html`) membaca data dari `localStorage` sebagai prioritas utama
- Data yang disimpan mencakup:
  - `serial_number` - Serial number perangkat
  - `product_class` - Model perangkat
  - `manufacturer` - Produsen
  - `ip_address` - IP Address
  - `wifi_services` - **Penting**: Informasi WiFi termasuk path SSID dan password
  - `wan_services` - Informasi WAN/PPPoE
  - `parameters` - **Penting**: Parameter TR-069 lengkap
  - `rx_power` - Kekuatan sinyal
  - `temperature` - Suhu perangkat
  - `last_inform_time` - Waktu terakhir device check-in

#### Prioritas Pengambilan Data:
1. **PRIORITY 1**: `localStorage` (`acs_devices`) - Data paling lengkap dari admin dashboard
2. **PRIORITY 2**: Session `customerData` - Data dari login customer
3. **PRIORITY 3**: GenieACS API - Fallback (biasanya gagal untuk customer)

### 2. **Tampilan Data ONU di Customer Dashboard**

Customer dapat melihat informasi lengkap perangkat mereka:

#### Informasi Perangkat:
- ✅ Serial Number
- ✅ Model/Product Class
- ✅ Manufacturer
- ✅ IP Address

#### Informasi Jaringan:
- ✅ PPPoE Username
- ✅ WiFi SSID (nama WiFi)
- ✅ Rx Power (kekuatan sinyal) dengan visualisasi bar
- ✅ Temperature
- ✅ Connected Devices
- ✅ Last Seen (terakhir online)

### 3. **WiFi Management dari Customer Portal**

Customer dapat mengubah pengaturan WiFi mereka sendiri:

#### Fitur Ubah SSID:
```javascript
async function saveSSIDOnly()
```
- ✅ Validasi data perangkat tersedia
- ✅ Validasi data lengkap (wifi_services & parameters)
- ✅ Validasi input (1-32 karakter)
- ✅ Kirim task ke GenieACS API
- ✅ Update tampilan secara optimistic
- ✅ Auto-refresh setelah 5 detik

#### Fitur Ubah Password:
```javascript
async function savePasswordOnly()
```
- ✅ Validasi data perangkat tersedia
- ✅ Validasi data lengkap (wifi_services & parameters)
- ✅ Validasi input (minimal 8 karakter)
- ✅ Kirim task ke GenieACS API
- ✅ Auto-refresh setelah 5 detik

### 4. **Alert Sinkronisasi Data**

Sistem menampilkan alert informatif jika data tidak lengkap:

```html
<div class="alert alert-info" id="sync-alert">
  📡 Sinkronisasi Data Perangkat
  Untuk mendapatkan data perangkat lengkap dan mengubah pengaturan WiFi, 
  admin perlu membuka halaman ACS / TR-069 terlebih dahulu.
</div>
```

#### Logika Alert:
- **Tampil**: Jika `wifi_services` atau `parameters` kosong/tidak ada
- **Sembunyi**: Jika data lengkap tersedia
- **Auto-scroll**: Saat customer mencoba ubah WiFi tanpa data lengkap

## 🔧 Cara Penggunaan

### Untuk Admin:

1. **Buka halaman Admin ACS** (`acs.html`)
   - Data semua ONU akan otomatis di-fetch dari GenieACS
   - Data disimpan ke `localStorage` browser
   
2. **Pastikan ONU customer sudah terdaftar**
   - ONU harus sudah connect ke GenieACS
   - Serial number harus match dengan data customer

### Untuk Customer:

1. **Login ke Customer Portal** menggunakan:
   - Username/PPPoE username
   - Password

2. **Lihat Data Perangkat**
   - Semua informasi ONU ditampilkan di halaman Home
   - Status online/offline real-time

3. **Ubah WiFi (jika data lengkap)**
   - Klik tombol "Ubah WiFi" di Quick Actions
   - Pilih ubah SSID atau Password (terpisah)
   - Perubahan akan diterapkan dalam beberapa menit

4. **Jika Data Tidak Lengkap**
   - Alert akan muncul dengan instruksi
   - Hubungi admin untuk membuka halaman ACS
   - Setelah admin buka ACS, refresh halaman customer

## 🔍 Troubleshooting

### Problem: Data ONU tidak muncul di Customer Dashboard

**Solusi:**
1. ✅ Pastikan admin sudah membuka halaman `acs.html` minimal 1x
2. ✅ Cek browser console untuk log: `"Found X devices in localStorage cache"`
3. ✅ Pastikan serial number di session match dengan data di localStorage
4. ✅ Clear browser cache dan refresh halaman admin terlebih dahulu

### Problem: Tombol "Ubah WiFi" tidak bekerja

**Solusi:**
1. ✅ Cek apakah alert sinkronisasi muncul
2. ✅ Jika muncul, admin perlu buka halaman ACS dulu
3. ✅ Cek console untuk error: `"⚠️ Incomplete device data"`
4. ✅ Pastikan ONU memiliki `wifi_services` dan `parameters`

### Problem: Perubahan WiFi tidak diterapkan

**Solusi:**
1. ✅ Cek apakah ONU sedang online (status hijau)
2. ✅ Tunggu 2-5 menit untuk ONU melakukan inform berikutnya
3. ✅ Cek GenieACS tasks untuk melihat status task
4. ✅ Reboot ONU jika perlu untuk force inform

## 📊 Data Flow Diagram

```
┌─────────────────┐
│  GenieACS API   │
│  (Port 7547)    │
└────────┬────────┘
         │
         │ Fetch devices
         ▼
┌─────────────────┐
│   acs.html      │
│ (Admin Page)    │
└────────┬────────┘
         │
         │ Save to localStorage
         │ Key: 'acs_devices'
         ▼
┌─────────────────┐
│  localStorage   │
│  (Browser)      │
└────────┬────────┘
         │
         │ Read on load
         ▼
┌─────────────────┐
│customer_dash.   │
│html (Customer)  │
└────────┬────────┘
         │
         │ Display & Manage
         ▼
┌─────────────────┐
│  Customer UI    │
│  - View Info    │
│  - Change WiFi  │
└─────────────────┘
```

## 🔐 Security Considerations

1. **API Access**: Customer menggunakan API key yang sama dengan admin (`'secret'`)
   - ⚠️ Pertimbangkan untuk membuat API key terpisah untuk customer
   
2. **Data Isolation**: Customer hanya bisa lihat data ONU mereka sendiri
   - ✅ Filtered by `serial_number` dari session
   
3. **WiFi Management**: Customer hanya bisa ubah WiFi ONU mereka
   - ✅ Validasi serial number di setiap request

## 📝 File yang Dimodifikasi

### `customer_dashboard.html`

#### Fungsi yang Diubah:
1. **`loadDeviceData()`** - Prioritas localStorage sebagai sumber data utama
2. **`updateDeviceInfo()`** - Tambah logika show/hide sync alert
3. **`saveSSIDOnly()`** - Tambah validasi data lengkap
4. **`savePasswordOnly()`** - Tambah validasi data lengkap

#### HTML yang Ditambahkan:
- Alert box untuk sinkronisasi data (`#sync-alert`)

## ✨ Kesimpulan

Dengan perubahan ini, customer dashboard sekarang dapat:
- ✅ Menampilkan data ONU lengkap dari admin dashboard
- ✅ Mengubah SSID dan password WiFi secara mandiri
- ✅ Memberikan feedback yang jelas jika data tidak tersedia
- ✅ Sinkronisasi otomatis melalui localStorage browser

**Catatan Penting**: Admin harus membuka halaman ACS minimal sekali agar data ONU tersimpan di localStorage dan dapat diakses oleh customer portal.
