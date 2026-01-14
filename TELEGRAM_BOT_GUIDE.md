# Telegram Bot - Panduan Penggunaan Lengkap

## 📱 Fitur Baru yang Ditambahkan

Bot Telegram ACS-Lite sekarang memiliki fitur yang lebih lengkap dengan menu interaktif untuk semua fitur admin dan MikroTik!

---

## 🎯 Menu Utama Baru

### Menu yang Tersedia:

```
┌─────────────────────────────┐
│  🏠 Menu Utama              │
├─────────────────────────────┤
│ 👥 Pelanggan  │ 📄 Invoice  │
│ 💰 Pembayaran │ 📦 Paket    │
│ 🔌 MikroTik   │ 📡 Hotspot  │
│ 🛠️ Tools     │ 📊 Dashboard│
│ ❓ Help                     │
└─────────────────────────────┘
```

---

## 📋 Perintah Text Lengkap

### Perintah Dasar
| Perintah | Deskripsi |
|----------|-----------|
| `/start` | Mulai bot |
| `/menu` | Tampilkan menu utama |
| `/help` | Bantuan |
| `/dashboard` | Dashboard statistik |

### Perintah Pelanggan
| Perintah | Deskripsi | Contoh |
|----------|-----------|--------|
| `/cari [keyword]` | Cari pelanggan | `/cari Ahmad` |
| `/tagihan [kode]` | Cek tagihan | `/tagihan CST001` |
| `/isolir [kode]` | Isolir pelanggan | `/isolir CST001` |
| `/unisolir [kode]` | Buka isolir | `/unisolir CST001` |
| `/addcust [nama] [phone] [pppoe_user] [pppoe_pass] [paket]` | Tambah pelanggan | `/addcust Ahmad 081234567890 user123 pass123 10Mbps` |

### Perintah Invoice
| Perintah | Deskripsi |
|----------|-----------|
| `/invlist` | List semua invoice |
| `/invoverdue` | Invoice jatuh tempo |
| `/invpaid` | Invoice lunas |
| `/invunpaid` | Invoice belum lunas |
| `/createinv [kode_pelanggan]` | Buat invoice baru |

### Perintah Pembayaran
| Perintah | Deskripsi | Contoh |
|----------|-----------|--------|
| `/pay [inv_no] [jumlah]` | Catat pembayaran | `/pay INV-202412-001 150000` |
| `/paylist` | List pembayaran terbaru |
| `/paytoday` | Pembayaran hari ini |
| `/paymonth` | Pembayaran bulan ini |

### Perintah Paket
| Perintah | Deskripsi |
|----------|-----------|
| `/pkglist` | List paket |
| `/pkgadd [nama] [speed] [harga]` | Tambah paket |

### Perintah PPPoE
| Perintah | Deskripsi | Contoh |
|----------|-----------|--------|
| `/pppoe` | List PPPoE users |
| `/pppoeactive` | List active sessions |
| `/pppoeoffline` | List offline users |
| `/addpppoe [user] [pass] [profile]` | Tambah user PPPoE | `/addpppoe user123 pass123 10Mbps` |
| `/editpppoe [user] [profile]` | Edit profile user | `/editpppoe user123 20Mbps` |
| `/delpppoe [user]` | Hapus user PPPoE | `/delpppoe user123` |
| `/connect [user]` | Connect user |
| `/disconnect [user]` | Disconnect user |

### Perintah Hotspot
| Perintah | Deskripsi | Contoh |
|----------|-----------|--------|
| `/voucher [profile] [jumlah]` | Generate voucher random 5 digit | `/voucher 10k 5` |
| `/vcr [user] [profile]` | Generate voucher manual (user=pass) | `/vcr 12345 10k` |
| `/member [user] [pass] [profile]` | Generate member (user≠pass) | `/member ahmad secret123 10k` |
| `/hsactive` | List hotspot active users |
| `/hsprofiles` | List hotspot profiles |
| `/hsstats` | Statistik hotspot |

### Perintah MikroTik Tools
| Perintah | Deskripsi | Contoh |
|----------|-----------|--------|
| `/resource` | Cek resource router |
| `/ping [host]` | Ping ke host | `/ping 8.8.8.8` |
| `/interface` | List interface |
| `/log [lines]` | Lihat log router | `/log 20` |
| `/traffic [interface]` | Monitor traffic interface | `/traffic ether1` |
| `/dhcp` | List DHCP leases |
| `/firewall` | List firewall rules |
| `/wireless` | Scan wireless |
| `/reboot` | Reboot router |
| `/backup` | Backup config router |

---

## 🎮 Cara Menggunakan Menu Interaktif

### 1. Buka Menu Utama
Ketik `/start` atau `/menu` untuk membuka menu utama.

### 2. Navigasi Menu
Klik tombol-tombol di menu untuk navigasi:
- Klik **👥 Pelanggan** untuk menu pelanggan
- Klik **📄 Invoice** untuk menu invoice
- Klik **💰 Pembayaran** untuk menu pembayaran
- Klik **📦 Paket** untuk menu paket
- Klik **🔌 MikroTik PPPoE** untuk menu PPPoE
- Klik **📡 Hotspot** untuk menu hotspot
- Klik **🛠️ MikroTik Tools** untuk tools MikroTik
- Klik **📊 Dashboard** untuk statistik
- Klik **❓ Help** untuk bantuan

### 3. Contoh Flow Penggunaan

#### Flow 1: Cek Resource MikroTik
```
1. Ketik /menu
2. Klik "🛠️ MikroTik Tools"
3. Klik "📊 Resource Monitor"
4. Bot menampilkan resource router (CPU, RAM, Disk, Uptime)
```

#### Flow 2: Generate Voucher Hotspot
```
1. Ketik /menu
2. Klik "📡 Hotspot"
3. Klik "🎫 Generate Voucher"
4. Ketik: /voucher 10k 5
5. Bot generate 5 voucher profile 10k
```

#### Flow 3: Isolir Pelanggan
```
1. Ketik /menu
2. Klik "👥 Pelanggan"
3. Klik "🔴 List Isolir"
4. Klik pelanggan yang ingin di-unisolir
5. Klik tombol aksi yang tersedia
```

#### Flow 4: Cek Traffic Interface
```
1. Ketik /menu
2. Klik "🛠️ MikroTik Tools"
3. Klik "📈 Traffic Monitor"
4. Ketik: /traffic ether1
5. Bot menampilkan traffic interface ether1
```

---

## 🛠️ Fitur MikroTik Tools Lengkap

### Resource Monitor
Menampilkan informasi lengkap tentang router:
- Identity & Version
- Board Name
- Uptime
- Memory Usage (Total, Free, Used, Percentage)
- Disk Usage (Total, Free, Used)
- CPU Load

### Ping Test
Test koneksi ke host tertentu:
- Ping 4x ke host
- Tampilkan response time
- Tampilkan packet loss
- Tampilkan average time

### Interface Status
Menampilkan semua interface router:
- Status (Up/Down)
- Interface Name
- Interface Type
- Running status

### Log Viewer
Menampilkan log terbaru dari router:
- Default 20 baris terbaru
- Bisa filter dengan keyword
- Format waktu dan message

### Traffic Monitor
Monitor traffic per interface:
- Download traffic
- Upload traffic
- Total traffic

### DHCP Leases
Menampilkan semua DHCP leases:
- IP Address
- MAC Address
- Hostname
- Lease time

### Firewall Rules
Menampilkan semua firewall rules:
- Chain (input, forward, output)
- Action (accept, drop, reject)
- Source address
- Destination port

### Wireless Scan
Scan wireless networks:
- SSID
- BSSID
- Signal strength
- Channel
- Encryption

### Reboot Router
Reboot router MikroTik:
- Konfirmasi dulu
- Reboot setelah konfirmasi

### Backup Config
Backup konfigurasi router:
- Generate file backup
- Timestamp otomatis
- Simpan di router

---

## 📊 Dashboard Statistik

Dashboard menampilkan statistik lengkap:

### Pelanggan
- Total pelanggan
- Pelanggan aktif
- Pelanggan isolir

### Invoice
- Invoice jatuh tempo
- Invoice lunas
- Invoice belum lunas

### Pendapatan
- Pendapatan bulan ini
- Pendapatan hari ini

---

## 🔧 Konfigurasi Bot

### 1. Setup Bot Token
Edit file `/opt/acs/web/data/admin.json` atau database `telegram_config`:
```json
{
  "telegram": {
    "bot_token": "YOUR_BOT_TOKEN",
    "admin_chat_ids": ["YOUR_CHAT_ID"]
  }
}
```

### 2. Setup Webhook
```bash
curl -X POST "https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://yourdomain.com/api/telegram_webhook.php"
```

### 3. Add Admin
Tambahkan admin chat ID di database atau config file:
```sql
INSERT INTO telegram_admins (chat_id, name, is_active) VALUES ('YOUR_CHAT_ID', 'Admin Name', 1);
```

---

## 📝 Tips & Trik

### 1. Perintah Cepat
Gunakan perintah text untuk aksi cepat:
- `/dashboard` - Cek statistik cepat
- `/resource` - Cek resource router cepat
- `/ping 8.8.8.8` - Test koneksi cepat

### 2. Navigation
Gunakan tombol "⬅️ Kembali" untuk kembali ke menu sebelumnya.

### 3. Search
Gunakan `/cari` untuk mencari pelanggan dengan cepat:
- Cari berdasarkan nama
- Cari berdasarkan kode pelanggan
- Cari berdasarkan username PPPoE

### 4. Batch Operations
Untuk generate banyak voucher:
```
/voucher 10k 100
```
Ini akan generate 100 voucher profile 10k.

---

## 🚀 Contoh Use Case

### Use Case 1: Monitoring Harian
```
1. /dashboard - Cek statistik harian
2. /resource - Cek resource router
3. /pppoeactive - Cek user PPPoE aktif
4. /hsactive - Cek user hotspot aktif
```

### Use Case 2: Troubleshooting
```
1. /ping 8.8.8.8 - Test koneksi internet
2. /interface - Cek status interface
3. /log - Cek log router
4. /traffic ether1 - Cek traffic interface
```

### Use Case 3: Management Pelanggan
```
1. /cari Ahmad - Cari pelanggan
2. /tagihan CST001 - Cek tagihan
3. /isolir CST001 - Isolir pelanggan
4. /unisolir CST001 - Buka isolir
```

### Use Case 4: Hotspot Management
```
1. /hsprofiles - List profile hotspot
2. /voucher 10k 10 - Generate 10 voucher 10k
3. /hsactive - Cek user aktif
4. /hsstats - Cek statistik hotspot
```

---

## ❓ Troubleshooting

### Bot tidak merespon
1. Cek webhook sudah diset
2. Cek bot token benar
3. Cek chat ID admin sudah terdaftar
4. Cek koneksi internet

### Error database
1. Cek koneksi database
2. Cek tabel sudah ada
3. Cek permission database

### Error MikroTik API
1. Cek koneksi ke MikroTik
2. Cek API MikroTik sudah enable
3. Cek username/password API
4. Cek IP address sudah di-allow

---

## 📞 Support

Untuk bantuan lebih lanjut:
- Ketik `/help` untuk bantuan
- Cek dokumentasi di `/opt/acs/TELEGRAM_BOT_GUIDE.md`
- Cek desain menu di `/opt/acs/TELEGRAM_BOT_DESIGN.md`

---

**Update:** 2026-01-14
**Version:** 2.0
**Status:** ✅ Production Ready
