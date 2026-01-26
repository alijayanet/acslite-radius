# ✅ Perubahan Customer Dashboard - ONU Management

## 🎯 Tujuan
Memastikan ONU yang terdaftar di halaman admin ACS dapat ditampilkan datanya dan di-manage (ganti SSID dan password WiFi) dari dashboard pelanggan.

## 📝 Perubahan yang Dilakukan

### 1. **Prioritas Data dari localStorage**
- Customer dashboard sekarang **prioritas membaca dari localStorage** (`acs_devices`)
- Data ini disimpan otomatis oleh admin dashboard saat membuka halaman ACS
- Data mencakup semua informasi penting: `wifi_services`, `parameters`, `wan_services`, dll.

### 2. **Alert Sinkronisasi**
- Ditambahkan alert box yang muncul jika data ONU tidak lengkap
- Alert memberitahu customer untuk meminta admin membuka halaman ACS terlebih dahulu
- Alert otomatis hilang jika data sudah lengkap

### 3. **Validasi WiFi Management**
- Fungsi `saveSSIDOnly()` dan `savePasswordOnly()` sekarang:
  - ✅ Cek apakah data perangkat tersedia
  - ✅ Cek apakah data lengkap (ada `wifi_services` dan `parameters`)
  - ✅ Tampilkan pesan error yang jelas jika data tidak lengkap
  - ✅ Auto-scroll ke alert jika customer coba ubah WiFi tanpa data

### 4. **Logging yang Lebih Baik**
- Console log sekarang menampilkan:
  - Status data yang ditemukan
  - Jumlah devices di cache
  - Informasi wifi_services dan parameters
  - Status kelengkapan data

## 🚀 Cara Menggunakan

### Untuk Admin:
1. **Buka halaman ACS** (`acs.html`) minimal 1x
2. Data semua ONU akan otomatis tersimpan ke browser
3. Customer sekarang bisa akses data lengkap

### Untuk Customer:
1. **Login** ke customer portal
2. **Lihat data ONU** di halaman Home
3. **Ubah WiFi** jika data lengkap:
   - Klik "Ubah WiFi"
   - Pilih ubah SSID atau Password
   - Tunggu beberapa menit untuk diterapkan

4. **Jika muncul alert**:
   - Hubungi admin
   - Minta admin buka halaman ACS
   - Refresh halaman customer

## 🔍 Troubleshooting

| Problem | Solusi |
|---------|--------|
| Data ONU tidak muncul | Admin perlu buka halaman ACS dulu |
| Tombol WiFi tidak bekerja | Cek apakah alert muncul, jika ya admin perlu buka ACS |
| Perubahan WiFi tidak diterapkan | Tunggu 2-5 menit, pastikan ONU online |

## 📊 Alur Data

```
Admin buka acs.html 
  → Data disimpan ke localStorage 
    → Customer buka dashboard 
      → Data dibaca dari localStorage 
        → Customer bisa lihat & ubah WiFi
```

## ✨ Hasil Akhir

✅ Customer bisa lihat semua data ONU mereka  
✅ Customer bisa ubah SSID WiFi sendiri  
✅ Customer bisa ubah password WiFi sendiri  
✅ Sistem memberikan feedback jelas jika ada masalah  
✅ Tidak perlu login ulang atau refresh berkali-kali  

---

**Catatan**: Admin **HARUS** membuka halaman ACS minimal sekali agar data ONU tersimpan dan dapat diakses customer.
