-- ============================================================
-- Migration v2.8 - Performance Index Parity
-- ============================================================
-- Brings schema.sql and the live database into 100% index parity.
--
--   1. ENSURES (never drops or renames - asserted by exact name by
--      run_tests.php Section 29):
--        idx_settlement_period (settlements: group_id, period_start, period_end)
--        idx_notif_created     (notifications: created_at)
--
--   2. DROPS the redundant duplicate index on notifications,
--      idx_notifications_created_at (created_at) - identical column
--      list to idx_notif_created.
--
--   3. ADDS the two genuinely missing scaling indexes:
--        idx_rate_attempted  (rate_limits: attempted_at)
--          -> rate-limit window cleanup + per-action count scans
--        idx_unpriced_lookup (list_items: is_checked, expense_created,
--                             list_id, checked_at)
--          -> unpriced-items queue lookups
--
-- Idempotent: every DDL statement is guarded by an
-- INFORMATION_SCHEMA.STATISTICS existence check (equivalent to
-- SHOW INDEX), so this migration is safe to run any number of times.
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

DROP PROCEDURE IF EXISTS expmg_drop_index_if_present$$
CREATE PROCEDURE expmg_drop_index_if_present(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64)
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND INDEX_NAME   = p_index
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table,
                          '` DROP INDEX `', p_index, '`');
        PREPARE ddl_stmt FROM @ddl;
        EXECUTE ddl_stmt;
        DEALLOCATE PREPARE ddl_stmt;
    END IF;
END$$

DELIMITER ;

-- 1) Test-asserted indexes (ensure only - NEVER drop or rename these)
CALL expmg_add_index_if_missing('settlements',   'idx_settlement_period', '`group_id`, `period_start`, `period_end`');
CALL expmg_add_index_if_missing('notifications', 'idx_notif_created',     '`created_at`');

-- 2) Drop the redundant duplicate (Section 29 asserts idx_notif_created,
--    NOT idx_notifications_created_at - identical column list)
CALL expmg_drop_index_if_present('notifications', 'idx_notifications_created_at');

-- 3) Genuinely missing scaling indexes
CALL expmg_add_index_if_missing('rate_limits', 'idx_rate_attempted',  '`attempted_at`');
CALL expmg_add_index_if_missing('list_items',  'idx_unpriced_lookup', '`is_checked`, `expense_created`, `list_id`, `checked_at`');

-- Remove the helper procedures (leave no residue)
DROP PROCEDURE IF EXISTS expmg_add_index_if_missing;
DROP PROCEDURE IF EXISTS expmg_drop_index_if_present;

SELECT 'Performance index parity (v2.8) installed' AS status;
