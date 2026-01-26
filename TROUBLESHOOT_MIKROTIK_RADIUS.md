# 🔧 Troubleshooting: Sinkronisasi MikroTik dan FreeRADIUS

Dokumen ini menjelaskan masalah umum sinkronisasi antara MikroTik dan FreeRADIUS beserta solusinya.

---

## 📋 Masalah Umum dan Solusi

### 1. ❌ Session "Orphaned" (Menggantung)

**Gejala:**
- User online di `radacct` tapi sebenarnya sudah disconnect
- Jumlah "Online" di dashboard tidak akurat
- `acctstoptime = NULL` tapi user sudah tidak aktif

**Penyebab:**
- MikroTik tidak mengirim **Accounting-Stop** packet
- Koneksi putus mendadak (power off, signal loss)
- Network issue antara MikroTik dan RADIUS

**Solusi:**

#### A. Konfigurasi MikroTik - Aktifkan Interim-Update
```routeros
/radius
add address=<RADIUS_IP> secret=<SECRET> service=ppp,hotspot accounting=yes interim-update=1m

# Atau untuk hotspot:
/ip hotspot profile
set [find name=hsprof1] radius-interim-update=1m
```

#### B. Auto-Cleanup di Server (Sudah Ada)
File: `/root/cleanup_radius_sessions.sh`
```bash
# Berjalan setiap jam via cron
# Membersihkan session yang tidak update > 4 jam
```

#### C. Manual Cleanup via UI
Di halaman RADIUS Manager:
1. Klik tombol **"Cleanup Orphaned"**
2. Session tanpa update > 30 menit akan ditutup otomatis

---

### 2. ❌ Authentication Gagal

**Gejala:**
- User tidak bisa login
- Log MikroTik: "RADIUS server is not responding" atau "Access-Reject"

**Penyebab & Solusi:**

#### A. Secret Tidak Cocok
```routeros
# Di MikroTik:
/radius print

# Di RADIUS (tabel nas):
mysql> SELECT nasname, secret FROM nas;
```
**Pastikan secret sama persis!**

#### B. IP NAS Tidak Terdaftar
```sql
-- Cek NAS yang terdaftar:
SELECT * FROM nas;

-- Tambahkan jika belum ada:
INSERT INTO nas (nasname, shortname, type, secret, description)
VALUES ('192.168.1.1', 'mikrotik1', 'other', 'rahasia123', 'Router Utama');
```

#### C. Firewall Blocking
```bash
# Di server RADIUS, buka port:
ufw allow 1812/udp   # Authentication
ufw allow 1813/udp   # Accounting
```

---

### 3. ❌ Rate Limit Tidak Diterapkan

**Gejala:**
- User login sukses tapi speed tidak terbatas
- Bandwidth limit tidak sesuai paket

**Penyebab:**
- Attribute `Mikrotik-Rate-Limit` tidak terkirim
- Format rate limit salah

**Solusi:**

#### A. Cek radreply
```sql
SELECT * FROM radreply WHERE username='user123' AND attribute='Mikrotik-Rate-Limit';
```

#### B. Format yang Benar
```
Mikrotik-Rate-Limit = "10M/10M"     -- Download/Upload
Mikrotik-Rate-Limit = "2M/2M 4M/4M" -- Dengan burst
```

#### C. Pastikan FreeRADIUS Terkoneksi ke MikroTik Dictionary
```bash
# /etc/freeradius/3.0/dictionary
$INCLUDE /usr/share/freeradius/dictionary.mikrotik
```

---

### 4. ❌ Accounting Data Tidak Masuk

**Gejala:**
- Tabel `radacct` kosong
- Download/Upload selalu 0

**Penyebab & Solusi:**

#### A. Accounting Tidak Diaktifkan di MikroTik
```routeros
/radius
set [find] accounting=yes
```

#### B. SQL Module Tidak Aktif di FreeRADIUS
```bash
# Cek symlink:
ls -la /etc/freeradius/3.0/mods-enabled/sql

# Jika tidak ada:
ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/
```

#### C. SQL Tidak Ada di Accounting Section
File: `/etc/freeradius/3.0/sites-enabled/default`
```
accounting {
    sql    # <-- Pastikan ada baris ini
    ...
}
```

---

### 5. ❌ DateTime Error (acctstarttime = 0000-00-00)

**Gejala:**
- Tanggal di radacct salah (1970-01-01 atau 0000-00-00)
- Session time tidak terhitung

**Penyebab:**
- FreeRADIUS mengirim UNIX timestamp, tapi MySQL expect DATETIME

**Solusi:**
Script `configure_freeradius_sql.sh` sudah memperbaiki ini dengan:
```sql
acctstarttime = FROM_UNIXTIME(${....event_timestamp})
```

Jalankan ulang jika perlu:
```bash
sudo ./configure_freeradius_sql.sh
```

---

## 🛠️ Script Diagnostik

### Cek Koneksi RADIUS dari MikroTik
```routeros
/radius
print
monitor 0

# Test authentication:
/tool user-manager customer
print
```

### Cek Log FreeRADIUS
```bash
# Mode debug:
sudo systemctl stop freeradius
sudo freeradius -X

# Log normal:
journalctl -u freeradius -f
```

### Cek Database RADIUS
```sql
-- Session aktif:
SELECT username, acctstarttime, acctupdatetime 
FROM radacct 
WHERE acctstoptime IS NULL;

-- Session orphaned (tidak update > 1 jam):
SELECT username, acctstarttime, acctupdatetime 
FROM radacct 
WHERE acctstoptime IS NULL 
AND acctupdatetime < DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- Traffic hari ini:
SELECT username, 
       SUM(acctinputoctets)/1024/1024 AS download_mb,
       SUM(acctoutputoctets)/1024/1024 AS upload_mb
FROM radacct 
WHERE DATE(acctstarttime) = CURDATE()
GROUP BY username;
```

---

## ⚙️ Konfigurasi MikroTik yang Direkomendasikan

### PPPoE dengan RADIUS
```routeros
/radius
add address=<RADIUS_IP> secret=<SECRET> service=ppp accounting=yes interim-update=2m

/ppp profile
set default use-radius=yes

/ppp aaa
set use-radius=yes accounting=yes interim-update=2m
```

### Hotspot dengan RADIUS
```routeros
/radius
add address=<RADIUS_IP> secret=<SECRET> service=hotspot accounting=yes

/ip hotspot profile
set [find name=hsprof1] use-radius=yes radius-accounting=yes radius-interim-update=2m
```

---

## 📊 Checklist Sinkronisasi

| Item | Command/Check | Status |
|------|---------------|--------|
| FreeRADIUS running | `systemctl status freeradius` | ☐ |
| Port 1812/1813 open | `netstat -ulnp \| grep 181` | ☐ |
| NAS terdaftar | `SELECT * FROM nas;` | ☐ |
| Secret cocok | Bandingkan MikroTik vs NAS table | ☐ |
| SQL module aktif | `ls /etc/freeradius/3.0/mods-enabled/sql` | ☐ |
| Accounting enabled | `/radius print` di MikroTik | ☐ |
| Interim-update aktif | `/radius print` atau `/ppp aaa print` | ☐ |
| DateTime queries fixed | Cek `acctstarttime` tidak 1970 | ☐ |

---

## 🔄 Flow Data yang Benar

```
[User Connect]
     ↓
[MikroTik] --Access-Request--> [FreeRADIUS]
     ↓
[FreeRADIUS] --Query--> [radcheck/radreply]
     ↓
[FreeRADIUS] --Access-Accept--> [MikroTik]
     ↓
[MikroTik] --Accounting-Start--> [FreeRADIUS] --INSERT--> [radacct]
     ↓
[Setiap 2 menit]
[MikroTik] --Accounting-Interim-Update--> [FreeRADIUS] --UPDATE--> [radacct]
     ↓
[User Disconnect]
[MikroTik] --Accounting-Stop--> [FreeRADIUS] --UPDATE--> [radacct.acctstoptime]
```

---

*Dokumen ini dibuat pada: 2026-01-04*
