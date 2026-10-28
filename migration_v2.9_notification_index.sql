-- ============================================================
-- Migration v2.9 - Notification Feed Index
-- ============================================================
-- Adds the composite index the notification feed actually needs:
--
--   idx_notifications_user_time (user_id, created_at)
--
-- Every feed query filters on user_id and sorts / range-filters on
-- created_at:
--
--   api/notifications/list.php
--     WHERE user_id = ? AND created_at >= CURDATE() ... ORDER BY created_at DESC
--   api/notifications/history.php
--     WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
--     ORDER BY created_at DESC
--   api/notifications/count.php
--     WHERE user_id = ? AND is_read = 0 AND created_at >= CURDATE() ...
--
-- The existing idx_user_read (user_id, is_read, created_at) only helps the
-- count query; the unfiltered feed would otherwise fall back to a filesort
-- on the user's whole history. idx_notif_created (created_at) is retained
-- because the retention cleanup in scripts/cleanup_notifications.php scans
-- it as a leading column.
--
-- Idempotent: guarded by an INFORMATION_SCHEMA.STATISTICS existence check,
-- so it is safe to run any number of times (and a no-op where the index
-- already exists).
-- ============================================================

USE ExpMgWEB;

DELIMITER $$

DROP PROCEDURE IF EXISTS expmg_add_index_if_missing$$
CREATE PROCEDURE expmg_add_index_if_missing(
    IN p_table   VARCHAR(64),
    IN p_index   VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND INDEX_NAME   = p_index
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table,
                          '` ADD INDEX `', p_index, '` (', p_columns, ')');
        PREPARE ddl_stmt FROM @ddl;
        EXECUTE ddl_stmt;
        DEALLOCATE PREPARE ddl_stmt;
    END IF;
END$$

DELIMITER ;

-- Notification feed: per-user, newest-first range scans.
CALL expmg_add_index_if_missing('notifications', 'idx_notifications_user_time', '`user_id`, `created_at`');

-- Remove the helper procedure (leave no residue)
DROP PROCEDURE IF EXISTS expmg_add_index_if_missing;

SELECT 'Notification feed index (v2.9) installed' AS status;
