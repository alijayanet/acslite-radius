# Analisis Device Bermasalah - CDTCAF69B126

## 📊 Hasil Cleanup Task Pending

### Sebelum Cleanup
- **Total Tasks:** 9,058 (semua pending)
- **Device Bermasalah:** CDTCAF69B126
- **Task Pending per Device:** 9,058

### Setelah Cleanup
- **Total Tasks:** 4,850
- **Pending:** 510
- **Failed:** 4,340
- **Completed:** 0
- **Active:** 0

---

## 🔍 Analisis Device CDTCAF69B126

### Statistik Task
| Jenis Task | Jumlah | Oldest Task |
|------------|--------|-------------|
| GetParameterNames | 5,438 | 2026-01-02 00:37:19 |
| GetParameterValues | 3,612 | 2026-01-02 00:40:01 |
| SetParameterValues | 8 | 2026-01-02 02:05:20 |
| **Total** | **9,058** | **12 hari lalu** |

### Masalah yang Terdeteksi

1. **Device Tidak Online**
   - Task tertua sudah 12 hari (308 jam)
   - Tidak ada task yang berhasil dieksekusi
   - Semua task masih pending atau failed

2. **Task Queue Terus Bertambah**
   - Sistem terus mengirim task ke device ini
   - Task tidak pernah dieksekusi
   - Menumpuk menjadi 9,058 task

3. **Device Tidak Merespon ACS**
   - GetParameterNames dan GetParameterValues gagal
   - Connection request tidak berhasil
   - Device tidak connect ke ACS

---

## 🚨 Penyebab Kemungkinan

### 1. Device Offline
- Device mati atau tidak terhubung ke internet
- Kabel fiber putus
- ONU tidak menyala

### 2. IP Address Berubah
- IP address device berubah
- ACS tidak bisa reach device
- NAT/firewall blocking

### 3. ACS Configuration Issue
- Device tidak terdaftar dengan benar di ACS
- Informasi device tidak lengkap
- Connection request URL salah

### 4. Network Issue
- Jaringan antara ACS dan device bermasalah
- Port 7547 blocked
- Firewall memblokir koneksi

### 5. Device Configuration Error
- Device tidak support TR-069
- ACS URL salah di device
- Username/password ACS salah di device

---

## 🔧 Solusi untuk Device Bermasalah

### 1. Cek Status Device di ACS
```bash
# Cek device di database ACS
mysql -u root -psecret123 acs -e "
SELECT
    serial_number,
    _deviceId,
    oui,
    product_class,
    ip,
    lastInform,
    _lastInform
FROM devices
WHERE serial_number = 'CDTCAF69B126';
"
```

### 2. Cek Koneksi ke Device
```bash
# Cek apakah IP device bisa di-ping
# (Ganti IP dengan IP device yang benar)
ping <IP_DEVICE>

# Cek port ACS
telnet <IP_DEVICE> 7547
```

### 3. Hapus Task untuk Device Ini
```bash
# Hapus semua task untuk device ini
mysql -u root -psecret123 acs -e "
DELETE FROM tasks
WHERE serial_number = 'CDTCAF69B126';
"

# Verifikasi
mysql -u root -psecret123 acs -e "
SELECT COUNT(*) as remaining_tasks
FROM tasks
WHERE serial_number = 'CDTCAF69B126';
"
```

### 4. Cek dan Perbaiki Device di ACS
```bash
# Cek informasi device lengkap
curl -s "http://localhost:7547/devices?query=%7B%22serial_number%22%3A%22CDTCAF69B126%22%7D"

# Jika device tidak pernah connect, pertimbangkan untuk:
# 1. Hapus device dari database
# 2. Tambahkan ulang device
# 3. Atau biarkan sampai device connect lagi
```

### 5. Hapus Device dari Database (Opsional)
```bash
# Hapus device dan semua task terkait
mysql -u root -psecret123 acs -e "
DELETE FROM tasks WHERE serial_number = 'CDTCAF69B126';
DELETE FROM devices WHERE serial_number = 'CDTCAF69B126';
DELETE FROM presets WHERE serial_number = 'CDTCAF69B126';
DELETE FROM files WHERE serial_number = 'CDTCAF69B126';
DELETE FROM tags WHERE serial_number = 'CDTCAF69B126';
"
```

---

## 🛡️ Pencegahan untuk Masa Depan

### 1. Limit Task per Device
```sql
-- Hapus task pending > 100 per device
DELETE t1 FROM tasks t1
INNER JOIN (
    SELECT serial_number
    FROM tasks
    WHERE status = 'pending'
    GROUP BY serial_number
    HAVING COUNT(*) > 100
) t2 ON t1.serial_number = t2.serial_number
WHERE t1.status = 'pending'
ORDER BY t1.created_at ASC
LIMIT 50;
```

### 2. Auto-Cleanup Task Lama
```bash
# Setup cron job untuk auto-cleanup
crontab -e

# Tambahkan:
# Cleanup task pending > 7 hari setiap hari jam 3 pagi
0 3 * * * /opt/acs/cleanup_task_pending.sh >> /var/log/cleanup_task.log 2>&1

# Monitor task pending setiap 6 jam
0 */6 * * * /opt/acs/cek_task_pending.php >> /var/log/monitor_task.log 2>&1
```

### 3. Alert untuk Device Offline Lama
```php
<?php
// Script: /opt/acs/alert_offline_devices.php

$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=acs', 'root', 'secret123');

// Cek device yang tidak connect > 7 hari
$stmt = $db->query("
    SELECT
        serial_number,
        ip,
        lastInform,
        TIMESTAMPDIFF(DAY, lastInform, NOW()) as days_offline
    FROM devices
    WHERE lastInform < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY lastInform ASC
");

$offlineDevices = $stmt->fetchAll();

if (!empty($offlineDevices)) {
    echo "⚠️  Device Offline > 7 Hari:\n";
    foreach ($offlineDevices as $device) {
        echo sprintf("  - %s (%s): %s hari offline\n",
            $device['serial_number'],
            $device['ip'],
            $device['days_offline']
        );
    }
}
?>
```

### 4. Monitoring Task per Device
```php
<?php
// Script: /opt/acs/monitor_device_tasks.php

$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=acs', 'root', 'secret123');

// Cek device dengan task pending > 50
$stmt = $db->query("
    SELECT
        serial_number,
        COUNT(*) as pending_tasks,
        MAX(created_at) as latest_task
    FROM tasks
    WHERE status = 'pending'
    GROUP BY serial_number
    HAVING COUNT(*) > 50
    ORDER BY pending_tasks DESC
");

$problematicDevices = $stmt->fetchAll();

if (!empty($problematicDevices)) {
    echo "⚠️  Device dengan Task Pending > 50:\n";
    foreach ($problematicDevices as $device) {
        echo sprintf("  - %s: %d task pending\n",
            $device['serial_number'],
            $device['pending_tasks']
        );
    }
}
?>
```

---

## 📋 Rekomendasi Tindakan

### Langkah 1: Bersihkan Device Bermasalah
```bash
# Hapus semua task untuk device CDTCAF69B126
mysql -u root -psecret123 acs -e "
DELETE FROM tasks
WHERE serial_number = 'CDTCAF69B126';
"
```

### Langkah 2: Cek Status Device
```bash
# Cek device di database
mysql -u root -psecret123 acs -e "
SELECT
    serial_number,
    ip,
    lastInform,
    TIMESTAMPDIFF(DAY, lastInform, NOW()) as days_offline
FROM devices
WHERE serial_number = 'CDTCAF69B126';
"
```

### Langkah 3: Setup Auto-Cleanup
```bash
# Tambahkan ke crontab
crontab -e

# Tambahkan baris berikut:
0 3 * * * /opt/acs/cleanup_task_pending.sh >> /var/log/cleanup_task.log 2>&1
0 */6 * * * /opt/acs/cek_task_pending.php >> /var/log/monitor_task.log 2>&1
```

### Langkah 4: Monitoring Rutin
```bash
# Jalankan monitoring setiap 6 jam
php /opt/acs/cek_task_pending.php
```

---

## 📊 Summary

### Hasil Cleanup
- ✅ 4,208 task pending > 7 hari dihapus
- ✅ 4,340 task pending > 24 jam diupdate ke failed
- ✅ Total task berkurang dari 9,058 menjadi 4,850
- ⚠️ Masih ada 510 task pending yang baru

### Masalah Utama
- 1 device (CDTCAF69B126) menyebabkan 9,058 task pending
- Device tidak online selama 12+ hari
- Sistem terus mengirim task ke device ini

### Solusi
- Hapus task untuk device bermasalah
- Setup auto-cleanup rutin
- Monitor device offline
- Limit task per device

---

## 💡 Catatan Penting

1. **Device CDTCAF69B126** perlu diperiksa secara fisik
2. Jika device sudah tidak digunakan, hapus dari database
3. Setup auto-cleanup untuk mencegah penumpukan task
4. Monitoring rutin untuk mendeteksi device bermasalah lebih awal
