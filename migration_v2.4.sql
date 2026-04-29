-- ============================================================
-- Migration v2.4 — Notification Retention System
-- Adds indexes for 3-day automatic notification cleanup
-- ============================================================

USE ExpMgWEB;

-- Index for cleanup queries (WHERE created_at < NOW() - INTERVAL 3 DAY)
CREATE INDEX IF NOT EXISTS idx_notifications_created_at 
ON notifications (created_at);

-- Composite index for user notification queries (WHERE user_id = ? ORDER BY created_at DESC)
CREATE INDEX IF NOT EXISTS idx_notifications_user_time 
ON notifications (user_id, created_at DESC);

-- Verify indexes were created
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'ExpMgWEB'
  AND TABLE_NAME = 'notifications'
  AND INDEX_NAME IN ('idx_notifications_created_at', 'idx_notifications_user_time')
ORDER BY INDEX_NAME, SEQ_IN_INDEX;
