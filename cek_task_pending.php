<?php
/**
 * Script untuk Cek dan Analisis Task Pending di GenieACS
 * Usage: php cek_task_pending.php
 */

// Database Configuration
$dbHost = '127.0.0.1';
$dbPort = 3306;
$dbUser = 'root';
$dbPass = 'secret123';
$dbName = 'acs';

try {
    // Connect to database
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    echo "=========================================\n";
    echo "  Task Status Summary - GenieACS\n";
    echo "=========================================\n\n";

    // 1. Jumlah Task per Status
    echo "📊 1. Jumlah Task per Status:\n";
    echo str_repeat("-", 50) . "\n";
    $stmt = $pdo->query("
        SELECT
            status,
            COUNT(*) as total
        FROM tasks
        GROUP BY status
        ORDER BY total DESC
    ");
    $statusCounts = $stmt->fetchAll();

    foreach ($statusCounts as $row) {
        $status = strtoupper($row['status']);
        $count = number_format($row['total'], 0, ',', '.');
        $bar = str_repeat('█', min(50, $row['total'] / 10));
        echo sprintf("  %-12s: %8s %s\n", $status, $count, $bar);
    }
    echo "\n";

    // 2. Total Task
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM tasks");
    $totalTasks = $stmt->fetch()['total'];
    echo "  Total Tasks: " . number_format($totalTasks, 0, ',', '.') . "\n\n";

    // 3. Task Pending Lama (> 1 jam)
    echo "⏰ 2. Task Pending > 1 Jam:\n";
    echo str_repeat("-", 50) . "\n";
    $stmt = $pdo->query("
        SELECT
            serial_number,
            name,
            created_at,
            TIMESTAMPDIFF(HOUR, created_at, NOW()) as hours_pending
        FROM tasks
        WHERE status = 'pending'
            AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ORDER BY created_at ASC
        LIMIT 10
    ");
    $oldPendingTasks = $stmt->fetchAll();

    if (empty($oldPendingTasks)) {
        echo "  ✅ Tidak ada task pending > 1 jam\n\n";
    } else {
        echo sprintf("  %s task pending > 1 jam:\n\n", count($oldPendingTasks));
        foreach ($oldPendingTasks as $i => $task) {
            echo sprintf("  %d. %s\n", $i + 1, substr($task['serial_number'], 0, 20));
            echo sprintf("     Task: %s\n", $task['name']);
            echo sprintf("     Pending: %s jam\n", $task['hours_pending']);
            echo sprintf("     Created: %s\n\n", $task['created_at']);
        }
    }

    // 4. Task Pending per Device
    echo "📱 3. Device dengan Task Pending Terbanyak:\n";
    echo str_repeat("-", 50) . "\n";
    $stmt = $pdo->query("
        SELECT
            serial_number,
            COUNT(*) as pending_tasks,
            MAX(created_at) as latest_pending
        FROM tasks
        WHERE status = 'pending'
        GROUP BY serial_number
        ORDER BY pending_tasks DESC
        LIMIT 10
    ");
    $devicesWithPending = $stmt->fetchAll();

    if (empty($devicesWithPending)) {
        echo "  ✅ Tidak ada task pending\n\n";
    } else {
        foreach ($devicesWithPending as $i => $device) {
            echo sprintf("  %d. %s\n", $i + 1, substr($device['serial_number'], 0, 30));
            echo sprintf("     Pending Tasks: %d\n", $device['pending_tasks']);
            echo sprintf("     Latest: %s\n\n", $device['latest_pending']);
        }
    }

    // 5. Task Pending per Jenis Task
    echo "📋 4. Task Pending per Jenis Task:\n";
    echo str_repeat("-", 50) . "\n";
    $stmt = $pdo->query("
        SELECT
            name,
            COUNT(*) as pending_tasks,
            MIN(created_at) as oldest_task
        FROM tasks
        WHERE status = 'pending'
        GROUP BY name
        ORDER BY pending_tasks DESC
    ");
    $taskTypes = $stmt->fetchAll();

    if (empty($taskTypes)) {
        echo "  ✅ Tidak ada task pending\n\n";
    } else {
        foreach ($taskTypes as $i => $type) {
            echo sprintf("  %d. %s\n", $i + 1, $type['name']);
            echo sprintf("     Pending: %d tasks\n", $type['pending_tasks']);
            echo sprintf("     Oldest: %s\n\n", $type['oldest_task']);
        }
    }

    // 6. Statistik Tambahan
    echo "📈 5. Statistik Tambahan:\n";
    echo str_repeat("-", 50) . "\n";

    // Task pending > 24 jam
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM tasks
        WHERE status = 'pending'
            AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $pending24h = $stmt->fetch()['count'];
    echo sprintf("  Task pending > 24 jam: %d\n", $pending24h);

    // Task pending > 7 hari
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM tasks
        WHERE status = 'pending'
            AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $pending7d = $stmt->fetch()['count'];
    echo sprintf("  Task pending > 7 hari: %d\n", $pending7d);

    // Rata-rata task pending per device
    $stmt = $pdo->query("
        SELECT AVG(pending_count) as avg_pending
        FROM (
            SELECT COUNT(*) as pending_count
            FROM tasks
            WHERE status = 'pending'
            GROUP BY serial_number
        ) as t
    ");
    $avgPending = $stmt->fetch()['avg_pending'];
    echo sprintf("  Rata-rata pending per device: %.2f\n", $avgPending);

    // Device dengan task pending
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT serial_number) as devices
        FROM tasks
        WHERE status = 'pending'
    ");
    $devicesWithPendingCount = $stmt->fetch()['devices'];
    echo sprintf("  Device dengan task pending: %d\n", $devicesWithPendingCount);

    echo "\n";

    // 7. Rekomendasi
    echo "💡 Rekomendasi:\n";
    echo str_repeat("-", 50) . "\n";

    if ($pending7d > 0) {
        echo "  ⚠️  Ada {$pending7d} task pending > 7 hari, disarankan untuk dihapus\n";
    }

    if ($pending24h > 10) {
        echo "  ⚠️  Ada {$pending24h} task pending > 24 jam, disarankan untuk diupdate statusnya\n";
    }

    if ($devicesWithPendingCount > 10) {
        echo "  ⚠️  Ada {$devicesWithPendingCount} device dengan task pending, cek koneksi device\n";
    }

    if ($avgPending > 5) {
        echo sprintf("  ⚠️  Rata-rata pending per device tinggi (%.2f), cek ACS service\n", $avgPending);
    }

    if ($pending24h == 0 && $pending7d == 0 && $avgPending < 5) {
        echo "  ✅ Semua task dalam kondisi baik\n";
    }

    echo "\n";
    echo "=========================================\n";
    echo "  Selesai\n";
    echo "=========================================\n";

} catch (PDOException $e) {
    echo "❌ Database Error: " . $e->getMessage() . "\n";
    echo "Pastikan konfigurasi database sudah benar\n";
    exit(1);
}
