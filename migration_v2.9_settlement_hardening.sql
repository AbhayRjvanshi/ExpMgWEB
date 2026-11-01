-- ============================================================
-- Migration v2.9 — Settlement hardening (C2, PENTEST/highly sever/C2.md)
--
-- Adds the settlement-period CHECK constraint:
--   chk_settlement_period (period_start >= '2000-01-01'
--                          AND period_start <= period_end)
--
-- Closes the data layer against calendar-impossible / reversed periods that
-- settle.php's shape-only regex used to accept (stored as 0000-00-00 and
-- poisoning confirm.php's MAX(period_end) cursor).
--
-- Idempotent + pre-flight guarded (same pattern as migration_v1.8.sql):
--   1. Every pre-flight check runs FIRST, before any DDL. DDL is not
--      attempted unless the constraint is actually missing.
--   2. The live database already carries the constraint once this migration
--      has been applied once; re-running is a verified no-op.
--   3. All statements assume MariaDB 10.4+ (CHECK constraints enforced).
--
-- Run with:  mysql -u root -p ExpMgWEB < migration_v2.9_settlement_hardening.sql
-- ============================================================

-- ------------------------------------------------------------
-- Pre-flight (informational)
-- ------------------------------------------------------------
SELECT
    CASE WHEN COUNT(*) > 0
         THEN 'chk_settlement_period already present -- no-op'
         ELSE 'chk_settlement_period missing -- will be added'
    END AS preflight_state
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'ExpMgWEB'
  AND TABLE_NAME = 'settlements'
  AND CONSTRAINT_NAME = 'chk_settlement_period';

-- ------------------------------------------------------------
-- Phase 1 - Data audit: refuse to proceed if existing rows would violate
-- the new constraint (strict-mode ALTER would abort mid-way otherwise).
-- ------------------------------------------------------------
SELECT COUNT(*) AS violating_rows
FROM settlements
WHERE period_start < '2000-01-01' OR period_start > period_end;
-- Expected: 0. If this returns > 0, correct the offending rows first.

-- ------------------------------------------------------------
-- Phase 2 - DDL, guarded by an existence check
-- ------------------------------------------------------------
SET @ddl = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE settlements ADD CONSTRAINT chk_settlement_period CHECK (period_start >= ''2000-01-01'' AND period_start <= period_end)',
        'SELECT ''chk_settlement_period already exists -- skipped'' AS status'
    )
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'ExpMgWEB'
      AND TABLE_NAME = 'settlements'
      AND CONSTRAINT_NAME = 'chk_settlement_period'
);
PREPARE add_constraint FROM @ddl;
EXECUTE add_constraint;
DEALLOCATE PREPARE add_constraint;

-- ------------------------------------------------------------
-- Verification
-- ------------------------------------------------------------
SELECT CONSTRAINT_NAME, CHECK_CLAUSE
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'ExpMgWEB'
  AND CONSTRAINT_NAME = 'chk_settlement_period';

SELECT 'migration_v2.9 settlement hardening verified' AS status;
