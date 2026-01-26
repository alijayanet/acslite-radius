#!/bin/bash

# Script Auto-Cleanup Task Pending di GenieACS
# Usage: ./cleanup_task_pending.sh

# Database Configuration
DB_USER="root"
DB_PASS="secret123"
DB_NAME="acs"

echo "========================================="
echo "  Auto-Cleanup Task Pending"
echo "========================================="
echo ""

# 1. Hapus task pending > 7 hari
echo "🧹 1. Menghapus task pending > 7 hari..."
DELETED=$(mysql -u $DB_USER -p$DB_PASS $DB_NAME -N -s -e "
    DELETE FROM tasks
    WHERE status = 'pending'
        AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
    SELECT ROW_COUNT();
")
echo "   ✅ Dihapus: $DELETED task"
echo ""

# 2. Update task pending > 24 jam menjadi failed
echo "🔄 2. Mengupdate task pending > 24 jam menjadi failed..."
UPDATED=$(mysql -u $DB_USER -p$DB_PASS $DB_NAME -N -s -e "
    UPDATE tasks
    SET status = 'failed'
    WHERE status = 'pending'
        AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
    SELECT ROW_COUNT();
")
echo "   ✅ Diupdate: $UPDATED task"
echo ""

# 3. Hapus task completed > 30 hari
echo "🧹 3. Menghapus task completed > 30 hari..."
DELETED_COMPLETED=$(mysql -u $DB_USER -p$DB_PASS $DB_NAME -N -s -e "
    DELETE FROM tasks
    WHERE status = 'completed'
        AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    SELECT ROW_COUNT();
")
echo "   ✅ Dihapus: $DELETED_COMPLETED task completed"
echo ""

# 4. Hapus task failed > 30 hari
echo "🧹 4. Menghapus task failed > 30 hari..."
DELETED_FAILED=$(mysql -u $DB_USER -p$DB_PASS $DB_NAME -N -s -e "
    DELETE FROM tasks
    WHERE status = 'failed'
        AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    SELECT ROW_COUNT();
")
echo "   ✅ Dihapus: $DELETED_FAILED task failed"
echo ""

# 5. Tampilkan statistik setelah cleanup
echo "📊 5. Statistik Task Setelah Cleanup:"
echo "   " $(mysql -u $DB_USER -p$DB_PASS $DB_NAME -N -s -e "
    SELECT CONCAT('Total: ', COUNT(*), ' | Pending: ',
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END), ' | Active: ',
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END), ' | Completed: ',
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END), ' | Failed: ',
        SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END))
    FROM tasks;
")
echo ""

echo "========================================="
echo "  Cleanup Selesai"
echo "========================================="
echo ""
echo "📝 Log:"
echo "   - Task pending > 7 hari: $DELETED dihapus"
echo "   - Task pending > 24 jam: $UPDATED diupdate ke failed"
echo "   - Task completed > 30 hari: $DELETED_COMPLETED dihapus"
echo "   - Task failed > 30 hari: $DELETED_FAILED dihapus"
echo ""
