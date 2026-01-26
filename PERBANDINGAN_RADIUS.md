# Perbandingan Fitur RADIUS: Folder Utama vs acslite-radius

Dokumen ini membandingkan implementasi RADIUS antara folder utama (`e:\acs-radius`) dan subfolder `acslite-radius`.

---

## 📊 Ringkasan Perbandingan

| Aspek | Folder Utama | acslite-radius | Status |
|-------|--------------|----------------|--------|
| **Versi** | Lebih baru (diperbarui) | Versi lama / backup | ⚠️ Berbeda |
| **install_radius.sh** | 313 baris | 271 baris | ⚠️ Berbeda |
| **radius_api.php** | 669 baris | 654 baris | ⚠️ Berbeda |
| **radius_sync.php** | 271 baris | 213 baris | ⚠️ Berbeda |

---

## 🔍 Detail Perbedaan

### 1. **install_radius.sh**

| Fitur | Folder Utama | acslite-radius |
|-------|--------------|----------------|
| MySQL Root Password Default | `""` (kosong) | `"radius123"` |
| Default NAS IP | `192.168.1.1` | `103.197.92.22` |
| Cleanup backup files | ✅ Ada (line 19-26) | ❌ Tidak ada |
| INSERT NAS method | `INSERT IGNORE` + `ON DUPLICATE KEY UPDATE` | `SELECT WHERE NOT EXISTS` |
| SQL Schema inline | ✅ Lengkap dengan proper SQL termination | ❌ Ada syntax error (shell command di dalam heredoc) |

#### ⚠️ Bug di acslite-radius/install_radius.sh:
```bash
# Line 119-138: Ada shell commands di dalam SQL heredoc!
# Ini akan menyebabkan error saat dijalankan:
CREATE TABLE IF NOT EXISTS radreply (...)

# -----------------------------------------------------------------
# Insert dummy data (editable later)
# -----------------------------------------------------------------
# Dummy NAS entry (router)
${MYSQL_CMD[@]} -D "${DB_NAME_RADIUS}" -e "INSERT IGNORE INTO nas..."  # ❌ SYNTAX ERROR!
```

**Ini adalah bug serius** - command shell berada di dalam heredoc SQL yang akan dikirim ke MySQL.

### 2. **radius_api.php**

| Fitur | Folder Utama | acslite-radius |
|-------|--------------|----------------|
| Windows Support | ✅ Ada (line 92-99) | ❌ Tidak ada |
| serviceIsActive() | Cross-platform (Windows + Linux) | Linux only |
| apply_clients restart | Cross-platform | Linux only |

#### Folder Utama (radius_api.php) - Cross Platform:
```php
function serviceIsActive() {
    $os = strtoupper(substr(PHP_OS, 0, 3));
    if ($os === 'WIN') {
        // Windows support
        $out = @shell_exec('sc query freeradius | findstr RUNNING');
        if (!$out) {
            $out = @shell_exec('tasklist /FI "IMAGENAME eq radius.exe" | findstr radius.exe');
        }
        return !empty(trim((string)$out));
    }
    
    $out = @shell_exec('systemctl is-active freeradius 2>/dev/null');
    return trim((string)$out) === 'active';
}
```

#### acslite-radius (radius_api.php) - Linux Only:
```php
function serviceIsActive() {
    $out = @shell_exec('systemctl is-active freeradius 2>/dev/null');
    return trim((string)$out) === 'active';
}
```

### 3. **radius_sync.php**

| Fitur | Folder Utama | acslite-radius |
|-------|--------------|----------------|
| Error handling | ✅ try-catch | ❌ Tidak ada |
| return vs exit | `return;` (safe) | `exit(0);` |
| PPPoE Customer Sync | ✅ Ada (line 215-267) | ❌ Tidak ada |
| Regex untuk DB_DSN | Lebih baik (`[^@]*`) | Kurang baik (`[^@]+`) |

#### Folder Utama - Termasuk PPPoE Customer Sync:
```php
// -----------------------------------------------------------------
// 2. Sync PPPoE Customers (Billing)
// -----------------------------------------------------------------
$sqlCustomers = "
SELECT 
    c.pppoe_username, 
    c.pppoe_password, 
    c.status,
    p.mikrotik_profile,
    p.speed
FROM customers c
LEFT JOIN packages p ON c.package_id = p.id
WHERE c.pppoe_username IS NOT NULL AND c.pppoe_username != ''
";
```

#### acslite-radius - Hanya Voucher Sync:
```php
// Hanya sync vouchers, tidak ada customer sync
echo "OK: synced vouchers to radius. enabled={$enabledCount} disabled={$disabledCount}\n";
```

---

## 📋 Tabel Database RADIUS

### Struktur Tabel (Keduanya Sama)

| Tabel | Kolom | Status |
|-------|-------|--------|
| `radcheck` | id, username, attribute, op, value | ✅ Sama |
| `radreply` | id, username, attribute, op, value | ✅ Sama |
| `radacct` | radacctid, acctsessionid, username, nasipaddress, acctstarttime, acctstoptime, acctinputoctets, acctoutputoctets, dll | ✅ Sama |
| `nas` | nasname, shortname, type, ports, secret, server, community, description | ✅ Sama |
| `radgroupcheck` | id, groupname, attribute, op, value | ✅ Sama |
| `radgroupreply` | id, groupname, attribute, op, value | ✅ Sama |
| `radpostauth` | id, username, pass, reply, authdate | ✅ Sama |
| `radusergroup` | id, username, groupname, priority | ✅ Sama |

### Tabel Sumber ACS

| Tabel ACS | Folder Utama | acslite-radius |
|-----------|--------------|----------------|
| `hotspot_vouchers` | ✅ Disync | ✅ Disync |
| `hotspot_profiles` | ✅ Disync | ✅ Disync |
| `customers` (PPPoE) | ✅ Disync ke RADIUS | ❌ Tidak disync |
| `packages` | ✅ Disync (speed) | ❌ Tidak disync |

---

## 🔧 Perbaikan yang Sudah Dilakukan di Folder Utama

1. **Menghapus duplikasi INSERT** di `install_radius.sh`
2. **Menggunakan credentials dinamis** di `configure_freeradius_sql.sh` untuk cleanup script
3. **Menambahkan konversi format speed** (`10M` → `10M/10M`) di `radius_sync.php`
4. **Cross-platform support** untuk Windows di `radius_api.php`

---

## ✅ Rekomendasi

1. **Gunakan folder utama** (`e:\acs-radius`) untuk deployment - ini adalah versi terbaru dan sudah diperbaiki
2. **Folder `acslite-radius`** sebaiknya dihapus atau ditandai sebagai backup/arsip karena:
   - Memiliki bug serius di `install_radius.sh`
   - Tidak memiliki fitur PPPoE Customer Sync
   - Tidak cross-platform (Windows not supported)
3. **Jangan gunakan `acslite-radius`** untuk production karena bug di SQL heredoc

---

## 📁 Status Folder

| Folder | Rekomendasi | Alasan |
|--------|-------------|--------|
| `e:\acs-radius` (root) | ✅ **GUNAKAN INI** | Versi terbaru, bug-fix, cross-platform |
| `e:\acs-radius\acslite-radius` | ⚠️ HAPUS/ARSIP | Versi lama, ada bug, fitur kurang |

---

*Dokumen ini dibuat pada: 2026-01-04*
