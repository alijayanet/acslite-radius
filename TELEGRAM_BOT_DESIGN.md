# Telegram Bot - Menu Interaktif Design

## 📋 Struktur Menu Utama

```
🏠 Menu Utama
├── 👥 Pelanggan
│   ├── 📋 List Pelanggan
│   ├── 🔍 Cari Pelanggan
│   ├── ➕ Tambah Pelanggan
│   ├── ✏️ Edit Pelanggan
│   ├── 🔴 List Isolir
│   ├── 🟢 List Aktif
│   └── ⬅️ Kembali
│
├── 📄 Invoice
│   ├── 📋 List Invoice
│   ├── ⏰ Jatuh Tempo
│   ├── ✅ Lunas
│   ├── ⏳ Belum Lunas
│   ├── ➕ Buat Invoice
│   └── ⬅️ Kembali
│
├── 💰 Pembayaran
│   ├── 📋 List Pembayaran
│   ├── ➕ Catat Pembayaran
│   ├── 📊 Laporan Harian
│   ├── 📊 Laporan Bulanan
│   └── ⬅️ Kembali
│
├── 📦 Paket
│   ├── 📋 List Paket
│   ├── ➕ Tambah Paket
│   ├── ✏️ Edit Paket
│   └── ⬅️ Kembali
│
├── 🔌 MikroTik PPPoE
│   ├── 👥 List PPPoE Users
│   ├── 🟢 Active Sessions
│   ├── 🔴 Offline Users
│   ├── ➕ Tambah User
│   ├── ✏️ Edit User
│   ├── ❌ Hapus User
│   ├── 🔌 Connect User
│   ├── 🔌 Disconnect User
│   └── ⬅️ Kembali
│
├── 📡 Hotspot
│   ├── 🎫 Generate Voucher
│   ├── 👥 Active Users
│   ├── 📋 List Profiles
│   ├── ➕ Tambah Profile
│   ├── ✏️ Edit Profile
│   ├── 📊 Statistics
│   └── ⬅️ Kembali
│
├── 🛠️ MikroTik Tools
│   ├── 📊 Resource Monitor
│   ├── 🌐 Ping Test
│   ├── 🔌 Interface Status
│   ├── 📝 Log Viewer
│   ├── 📈 Traffic Monitor
│   ├── 🌐 DHCP Leases
│   ├── 🔥 Firewall Rules
│   ├── 🔄 Reboot Router
│   ├── 📡 Wireless Scan
│   └── ⬅️ Kembali
│
├── 📊 Dashboard
│   ├── 📈 Statistik Pelanggan
│   ├── 💰 Statistik Pendapatan
│   ├── 📡 Statistik Hotspot
│   └── ⬅️ Kembali
│
└── ❓ Help
    ├── 📖 Panduan Perintah
    ├── 🔧 Konfigurasi Bot
    └── ⬅️ Kembali
```

## 📱 Perintah Text (Commands)

### Perintah Dasar
- `/start` - Mulai bot
- `/menu` - Tampilkan menu utama
- `/help` - Bantuan
- `/dashboard` - Dashboard statistik

### Perintah Pelanggan
- `/cari [keyword]` - Cari pelanggan (nama/kode/pppoe)
- `/tagihan [kode]` - Cek tagihan pelanggan
- `/isolir [kode]` - Isolir pelanggan
- `/unisolir [kode]` - Buka isolir pelanggan
- `/addcust [nama] [phone] [pppoe_user] [pppoe_pass] [paket]` - Tambah pelanggan baru

### Perintah Invoice
- `/invlist` - List semua invoice
- `/invoverdue` - Invoice jatuh tempo
- `/invpaid` - Invoice lunas
- `/invunpaid` - Invoice belum lunas
- `/createinv [kode_pelanggan]` - Buat invoice baru

### Perintah Pembayaran
- `/pay [inv_no] [jumlah]` - Catat pembayaran
- `/paylist` - List pembayaran terbaru
- `/paytoday` - Pembayaran hari ini
- `/paymonth` - Pembayaran bulan ini

### Perintah Paket
- `/pkglist` - List paket
- `/pkgadd [nama] [speed] [harga]` - Tambah paket

### Perintah PPPoE
- `/pppoe` - List PPPoE users
- `/pppoeactive` - List active sessions
- `/pppoeoffline` - List offline users
- `/addpppoe [user] [pass] [profile]` - Tambah user PPPoE
- `/editpppoe [user] [profile]` - Edit profile user
- `/delpppoe [user]` - Hapus user PPPoE
- `/connect [user]` - Connect user
- `/disconnect [user]` - Disconnect user

### Perintah Hotspot
- `/voucher [profile] [jumlah]` - Generate voucher random 5 digit
- `/vcr [user] [profile]` - Generate voucher manual (user=pass)
- `/member [user] [pass] [profile]` - Generate member (user≠pass)
- `/hsactive` - List hotspot active users
- `/hsprofiles` - List hotspot profiles
- `/hsstats` - Statistik hotspot

### Perintah MikroTik Tools
- `/resource` - Cek resource router
- `/ping [host]` - Ping ke host
- `/interface` - List interface
- `/log [lines]` - Lihat log router
- `/traffic [interface]` - Monitor traffic interface
- `/dhcp` - List DHCP leases
- `/firewall` - List firewall rules
- `/wireless` - Scan wireless
- `/reboot` - Reboot router
- `/backup` - Backup config router

## 🎯 Fitur Baru yang Akan Ditambahkan

### 1. Menu MikroTik Tools Lengkap
- Resource monitoring (CPU, RAM, Disk, Uptime)
- Interface status (up/down, traffic)
- Log viewer dengan filter
- Traffic monitoring per interface
- DHCP leases management
- Firewall rules management
- Wireless scan
- Router reboot
- Backup/restore config

### 2. Menu Management Lengkap
- Pelanggan: CRUD lengkap
- Invoice: CRUD lengkap
- Pembayaran: CRUD lengkap
- Paket: CRUD lengkap
- PPPoE: CRUD lengkap + session management
- Hotspot: CRUD lengkap + statistics

### 3. Fitur Interaktif
- Inline keyboard untuk semua menu
- Pagination untuk list panjang
- Search dengan filter
- Quick action buttons
- Confirmation dialogs
- Error handling yang jelas

### 4. Notifikasi
- Notifikasi isolir otomatis
- Notifikasi pembayaran
- Notifikasi user baru
- Notifikasi error system
- Notifikasi resource warning

## 📝 Contoh Flow Penggunaan

### Flow 1: Isolir Pelanggan
```
User: /menu
Bot: Tampilkan menu utama
User: Klik "👥 Pelanggan"
Bot: Tampilkan menu pelanggan
User: Klik "🔴 List Isolir"
Bot: List pelanggan isolir
User: Klik "📋 CST001"
Bot: Detail pelanggan CST001
User: Klik "🔓 Buka Isolir"
Bot: Konfirmasi
User: Klik "✅ Ya"
Bot: Pelanggan berhasil di-unisolir
```

### Flow 2: Generate Voucher Hotspot
```
User: /menu
Bot: Tampilkan menu utama
User: Klik "📡 Hotspot"
Bot: Tampilkan menu hotspot
User: Klik "🎫 Generate Voucher"
Bot: Pilih profile
User: Klik "10k"
Bot: Masukkan jumlah
User: "5"
Bot: Generate 5 voucher 10k
Bot: Tampilkan list voucher
```

### Flow 3: Cek Resource MikroTik
```
User: /menu
Bot: Tampilkan menu utama
User: Klik "🛠️ MikroTik Tools"
Bot: Tampilkan menu tools
User: Klik "📊 Resource Monitor"
Bot: Tampilkan resource (CPU, RAM, Disk, Uptime)
```

## 🎨 Desain Keyboard

### Main Menu (4x3)
```
[👥 Pelanggan] [📄 Invoice]
[💰 Pembayaran] [📦 Paket]
[🔌 MikroTik]  [📡 Hotspot]
[🛠️ Tools]    [📊 Dashboard]
```

### Customer Menu (3x2)
```
[📋 List]      [🔍 Cari]
[➕ Tambah]     [✏️ Edit]
[🔴 Isolir]     [⬅️ Kembali]
```

### PPPoE Menu (3x3)
```
[👥 List]       [🟢 Active]
[🔴 Offline]    [➕ Tambah]
[✏️ Edit]       [❌ Hapus]
[🔌 Connect]    [⬅️ Kembali]
```

### MikroTik Tools Menu (3x3)
```
[📊 Resource]   [🌐 Ping]
[🔌 Interface]  [📝 Log]
[📈 Traffic]    [🌐 DHCP]
[🔥 Firewall]   [⬅️ Kembali]
```

## 🔧 Implementation Plan

1. ✅ Analisis fitur yang sudah ada
2. ⏳ Desain menu interaktif
3. ⏳ Implementasi menu admin management
4. ⏳ Implementasi menu MikroTik commands
5. ⏳ Implementasi menu billing & hotspot
6. ⏳ Implementasi menu customer management
7. ⏳ Testing dan dokumentasi
