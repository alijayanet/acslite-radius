# Analisis Task Parameter Pending di GenieACS

## 📊 Struktur Tabel Tasks

Tabel `tasks` di database GenieACS:

```sql
CREATE TABLE `tasks` (
  `id` varchar(64) NOT NULL,
  `serial_number` varchar(64) NOT NULL,
  `name` varchar(64) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `status` varchar(20) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `serial_number` (`serial_number`),
  CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`serial_number`) REFERENCES `devices` (`serial_number`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

## 🔍 Jenis Task yang Dikirim

### 1. SetParameterValues
- Update WiFi SSID
- Update WiFi Password
- Update parameter lainnya

### 2. GetParameterValues
- Refresh data device
- Get parameter WiFi
- Get parameter lainnya

### 3. Reboot
- Reboot device ONU

## 📋 Status Task

| Status | Deskripsi |
|--------|-----------|
| `pending` | Task menunggu dieksekusi |
| `active` | Task sedang dieksekusi |
| `completed` | Task berhasil dieksekusi |
| `failed` | Task gagal dieksekusi |

## 🚨 Penyebab Task Pending

### 1. Device Tidak Online
- Device tidak connect ke ACS
- Device offline
- Network issue

### 2. ACS Service Tidak Berjalan
- GenieACS service tidak running
- Port 7547 tidak accessible

### 3. Task Timeout
- Device tidak merespon dalam waktu yang ditentukan
- Connection timeout

### 4. Task Queue Penuh
- Terlalu banyak task dalam antrian
- Task sebelumnya belum selesai

### 5. Parameter Salah
- Parameter path tidak valid
- Parameter value tidak valid

### 6. Device Tidak Support Parameter
- Device tidak support parameter yang diminta
- Parameter tidak tersedia di device

## 🔧 Cara Cek Task Pending

### Via MySQL Command Line
```bash
mysql -u root -p genieacs -e "
SELECT 
    status,
    COUNT(*) as count,
    MIN(created_at) as oldest_task,
    MAX(created_at) as newest_task
FROM tasks
GROUP BY status;
"
```

### Via PHP Script
```bash
php /opt/acs/cek_task_pending.php
```

## 📊 Query Analisis Task Pending

### 1. Jumlah Task per Status
```sql
SELECT 
    status,
    COUNT(*) as total
FROM tasks
GROUP BY status
ORDER BY total DESC;
```

### 2. Task Pending Lama (> 1 jam)
```sql
SELECT 
    serial_number,
    name,
    created_at,
    TIMESTAMPDIFF(HOUR, created_at, NOW()) as hours_pending
FROM tasks
WHERE status = 'pending'
    AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY created_at ASC
LIMIT 50;
```

### 3. Task Pending per Device
```sql
SELECT 
    serial_number,
    COUNT(*) as pending_tasks,
    MAX(created_at) as latest_pending
FROM tasks
WHERE status = 'pending'
GROUP BY serial_number
ORDER BY pending_tasks DESC
LIMIT 20;
```

### 4. Task Pending per Jenis Task
```sql
SELECT 
    name,
    COUNT(*) as pending_tasks,
    MIN(created_at) as oldest_task
FROM tasks
WHERE status = 'pending'
GROUP BY name
ORDER BY pending_tasks DESC;
```

### 5. Device dengan Task Pending Banyak
```sql
SELECT 
    d.serial_number,
    d._deviceId,
    d.ip,
    COUNT(t.id) as pending_tasks,
    MAX(t.created_at) as latest_pending_task
FROM tasks t
JOIN devices d ON t.serial_number = d.serial_number
WHERE t.status = 'pending'
GROUP BY d.serial_number
ORDER BY pending_tasks DESC
LIMIT 20;
```

## 🛠️ Solusi untuk Task Pending

### 1. Hapus Task Pending Lama
```sql
-- Hapus task pending lebih dari 7 hari
DELETE FROM tasks
WHERE status = 'pending'
    AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
```

### 2. Hapus Task Pending per Device
```sql
-- Hapus semua task pending untuk device tertentu
DELETE FROM tasks
WHERE serial_number = 'SERIAL_NUMBER_DEVICE'
    AND status = 'pending';
```

### 3. Update Status Task Timeout
```sql
-- Update task pending > 24 jam menjadi failed
UPDATE tasks
SET status = 'failed'
WHERE status = 'pending'
    AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

### 4. Restart ACS Service
```bash
systemctl restart genieacs
# atau
systemctl restart acs-go
```

### 5. Force Refresh Device
```bash
# Trigger connection request ke device
curl -X POST "http://localhost:7547/devices/SERIAL_NUMBER/connections" \
  -H "Content-Type: application/json" \
  -d '{}'
```

## 📝 Script Monitoring

### Script Cek Task Pending
```bash
#!/bin/bash
# File: /opt/acs/monitor_task_pending.sh

DB_USER="root"
DB_PASS="password"
DB_NAME="genieacs"

echo "========================================="
echo "Task Status Summary"
echo "========================================="
mysql -u $DB_USER -p$DB_PASS $DB_NAME -e "
SELECT 
    status,
    COUNT(*) as total
FROM tasks
GROUP BY status
ORDER BY total DESC;
"

echo ""
echo "========================================="
echo "Task Pending > 1 Jam"
echo "========================================="
mysql -u $DB_USER -p$DB_PASS $DB_NAME -e "
SELECT 
    serial_number,
    name,
    created_at,
    TIMESTAMPDIFF(HOUR, created_at, NOW()) as hours_pending
FROM tasks
WHERE status = 'pending'
    AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY created_at ASC
LIMIT 10;
"
```

### Script Auto-Cleanup Task Pending
```bash
#!/bin/bash
# File: /opt/acs/cleanup_task_pending.sh

DB_USER="root"
DB_PASS="password"
DB_NAME="genieacs"

# Hapus task pending > 7 hari
mysql -u $DB_USER -p$DB_PASS $DB_NAME -e "
DELETE FROM tasks
WHERE status = 'pending'
    AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
"

echo "Task pending > 7 hari telah dihapus"

# Update task pending > 24 jam menjadi failed
mysql -u $DB_USER -p$DB_PASS $DB_NAME -e "
UPDATE tasks
SET status = 'failed'
WHERE status = 'pending'
    AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
"

echo "Task pending > 24 jam diupdate menjadi failed"
```

## 🔄 Cron Job untuk Auto-Cleanup

Tambahkan ke crontab:
```bash
# Cleanup task pending setiap hari jam 3 pagi
0 3 * * * /opt/acs/cleanup_task_pending.sh >> /var/log/cleanup_task.log 2>&1

# Monitor task pending setiap 6 jam
0 */6 * * * /opt/acs/monitor_task_pending.sh >> /var/log/monitor_task.log 2>&1
```

## 💡 Rekomendasi

1. **Setup Monitoring Rutin**
   - Cek task pending setiap 6 jam
   - Kirim notifikasi jika task pending > threshold

2. **Auto-Cleanup**
   - Hapus task pending > 7 hari
   - Update task pending > 24 jam menjadi failed

3. **Cek Device Status**
   - Pastikan device online sebelum kirim task
   - Cek koneksi device ke ACS

4. **Optimasi Task Queue**
   - Batasi jumlah task per device
   - Implement rate limiting

5. **Log Task Status**
   - Log task yang gagal
   - Analisis pattern kegagalan

## 📞 Troubleshooting

### Jika Banyak Task Pending

1. Cek service ACS:
   ```bash
   systemctl status genieacs
   ```

2. Cek port ACS:
   ```bash
   netstat -tlnp | grep 7547
   ```

3. Cek log ACS:
   ```bash
   tail -f /var/log/genieacs/genieacs.log
   ```

4. Cek koneksi device:
   ```bash
   curl "http://localhost:7547/devices?query=%7B%22%24ne%22%3A%5B%5D%7D"
   ```

5. Cek database:
   ```bash
   mysql -u root -p genieacs -e "SELECT COUNT(*) FROM tasks WHERE status='pending';"
   ```
