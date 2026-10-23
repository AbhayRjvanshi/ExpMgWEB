-- ============================================================
-- migration_v2.7.sql — Repair legacy 1970-01-01 expense dates
-- ============================================================
-- Root cause: api/expenses/price_unpriced.php previously derived
-- expense_date via date('Y-m-d', strtotime(checked_at)) without a NULL
-- guard. Items with NULL checked_at produced date('Y-m-d', false) =
-- '1970-01-01', which then displayed literally in the Late Expenses list.
--
-- This migration repairs those rows using DATE(created_at) (falling back
-- to CURDATE() only if created_at were somehow NULL) while preserving
-- settlement-lock consistency: if the repaired date falls on or before
-- the group's latest settled period end, is_post_settlement is set to 1
-- so the expense is excluded from normal settlement math and handled by
-- the late-expense (post_calculate / post_confirm) flow instead.
--
-- Idempotent: safe to re-run — no rows match after the first execution.
-- Apply after schema.sql / earlier migrations:
--   mysql -u root ExpMgWEB < migration_v2.7.sql
-- ============================================================

USE ExpMgWEB;

UPDATE expenses e
LEFT JOIN (
    SELECT group_id, MAX(period_end) AS max_settled_end
    FROM settlements
    GROUP BY group_id
) s ON e.group_id = s.group_id
SET
    e.expense_date = COALESCE(DATE(e.created_at), CURDATE()),
    e.is_post_settlement = CASE
        WHEN e.group_id IS NOT NULL
             AND s.max_settled_end IS NOT NULL
             AND COALESCE(DATE(e.created_at), CURDATE()) <= s.max_settled_end
        THEN 1
        ELSE e.is_post_settlement
    END
WHERE e.expense_date = '1970-01-01';
