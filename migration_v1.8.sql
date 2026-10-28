-- ============================================================
-- Migration v1.8 - CHECK Constraint & Index Parity
-- ============================================================
-- Brings schema.sql and the live database into parity for the five
-- data-integrity CHECK constraints and the expense-date index.
--
--   chk_expense_amount      expenses.amount > 0
--   chk_budget_amount       budgets.amount_limit > 0
--   chk_settlement_amount   settlements.amount >= 0
--   chk_item_price          list_items.price IS NULL OR price > 0
--   chk_max_members         groups.max_members > 0
--   idx_expense_date        expenses (expense_date)
--
-- settlements.amount is deliberately >= 0, not > 0: an all-equal period
-- still writes a 0.00 marker row and the settlement-lock MAX(period_end)
-- lookups depend on that row existing.
--
-- SAFETY MODEL (read before running):
--   1. Every pre-flight check runs FIRST, before any DDL. DDL is not
--      transactional in MySQL, so a late failure would otherwise leave a
--      half-applied migration.
--   2. If existing rows violate a constraint the procedure aborts with
--      SQLSTATE 45000 and a message naming the table and offending count.
--      Fix the data, then re-run - nothing has been altered up to that point.
--   3. Each DDL statement is guarded by an INFORMATION_SCHEMA existence
--      check, so the migration is idempotent and a no-op where the
--      constraint/index already exists.
--
-- The live ExpMgWEB database already has all six objects; running this
-- there is a clean no-op. It exists so a fresh clone can reach the same
-- state without hand-written DDL.
--
-- RUNNING IT: use the mysql client (`mysql -u root -p < migration_v1.8.sql`)
-- because DELIMITER is a client-side directive. In phpMyAdmin, set the
-- delimiter field to $$ before executing. Verified end-to-end against a
-- scratch schema: creates all six objects, re-adds any that were dropped,
-- and aborts (SQLSTATE 45000) before any DDL when violating rows exist.
-- ============================================================

USE ExpMgWEB;

DELIMITER $$

DROP PROCEDURE IF EXISTS expmg_apply_v18$$
CREATE PROCEDURE expmg_apply_v18()
BEGIN
    DECLARE v_bad BIGINT DEFAULT 0;

    -- ========================================================
    -- Phase 1 - pre-flight data validation (no DDL yet)
    -- ========================================================

    SELECT COUNT(*) INTO v_bad FROM expenses WHERE amount <= 0;
    IF v_bad > 0 THEN
        SET @msg = CONCAT('ABORT: ', v_bad,
            ' row(s) in expenses violate amount > 0. Clean them up before applying chk_expense_amount.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @msg;
    END IF;

    SELECT COUNT(*) INTO v_bad FROM budgets WHERE amount_limit <= 0;
    IF v_bad > 0 THEN
        SET @msg = CONCAT('ABORT: ', v_bad,
            ' row(s) in budgets violate amount_limit > 0. Clean them up before applying chk_budget_amount.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @msg;
    END IF;

    SELECT COUNT(*) INTO v_bad FROM settlements WHERE amount < 0;
    IF v_bad > 0 THEN
        SET @msg = CONCAT('ABORT: ', v_bad,
            ' row(s) in settlements violate amount >= 0. Clean them up before applying chk_settlement_amount.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @msg;
    END IF;

    SELECT COUNT(*) INTO v_bad FROM list_items WHERE price IS NOT NULL AND price <= 0;
    IF v_bad > 0 THEN
        SET @msg = CONCAT('ABORT: ', v_bad,
            ' row(s) in list_items violate price IS NULL OR price > 0. Clean them up before applying chk_item_price.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @msg;
    END IF;

    SELECT COUNT(*) INTO v_bad FROM `groups` WHERE max_members <= 0;
    IF v_bad > 0 THEN
        SET @msg = CONCAT('ABORT: ', v_bad,
            ' row(s) in groups violate max_members > 0. Clean them up before applying chk_max_members.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @msg;
    END IF;

    -- ========================================================
    -- Phase 2 - DDL, each guarded by an existence check
    -- ========================================================

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'expenses'
          AND CONSTRAINT_NAME = 'chk_expense_amount'
    ) THEN
        ALTER TABLE expenses ADD CONSTRAINT chk_expense_amount CHECK (amount > 0);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'budgets'
          AND CONSTRAINT_NAME = 'chk_budget_amount'
    ) THEN
        ALTER TABLE budgets ADD CONSTRAINT chk_budget_amount CHECK (amount_limit > 0);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'settlements'
          AND CONSTRAINT_NAME = 'chk_settlement_amount'
    ) THEN
        ALTER TABLE settlements ADD CONSTRAINT chk_settlement_amount CHECK (amount >= 0);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'list_items'
          AND CONSTRAINT_NAME = 'chk_item_price'
    ) THEN
        ALTER TABLE list_items ADD CONSTRAINT chk_item_price CHECK (price IS NULL OR price > 0);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'groups'
          AND CONSTRAINT_NAME = 'chk_max_members'
    ) THEN
        ALTER TABLE `groups` ADD CONSTRAINT chk_max_members CHECK (max_members > 0);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'expenses'
          AND INDEX_NAME = 'idx_expense_date'
    ) THEN
        ALTER TABLE expenses ADD INDEX idx_expense_date (expense_date);
    END IF;
END$$

DELIMITER ;

CALL expmg_apply_v18();

DROP PROCEDURE IF EXISTS expmg_apply_v18;

SELECT 'CHECK constraints and idx_expense_date (v1.8) verified' AS status;
