# Database Audit

Source of truth: `schema.sql`. Live engine: MySQL 8 / MariaDB 10.4.32 under XAMPP.
All access is through `mysqli` prepared statements; no ORM and no query builder.

---

## 1. Overview

| Item | Value |
|---|---|
| Database | `ExpMgWEB` |
| Charset / collation | `utf8mb4` / `utf8mb4_general_ci` |
| Engine | InnoDB for all tables |
| Tables | 14 |
| Foreign keys | Present on every relationship except `rate_limits` and `outbox_events` |
| CHECK constraints | 5 in `schema.sql` + 1 added by `migration_v2.9_settlement_hardening.sql` |
| Migrations | `migration_v1.8` through `migration_v2.9` |

The 14 tables are:

```
users, categories, groups, group_members, expenses, budgets, lists, list_items,
notifications, settlements, settlement_confirmations, post_settlement_confirmations,
rate_limits, outbox_events
```

---

## 2. Table reference

### 2.1 `users`

| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED PK AI | |
| `username` | VARCHAR(50) NOT NULL UNIQUE | 3–50 enforced in PHP |
| `email` | VARCHAR(100) NOT NULL UNIQUE | `FILTER_VALIDATE_EMAIL` in PHP |
| `password` | VARCHAR(255) NOT NULL | bcrypt |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP ON UPDATE | |

Deleting a user cascades to their personal data and memberships.

### 2.2 `categories`

`id` PK, `name` VARCHAR(50) UNIQUE. Seeded with nine rows: General, Food/Groceries,
Transport, Utilities, Bills, Shopping, Education, Health, Others. Shared, not per-user.
`expenses.category_id` and `list_items.category_id` reference it.

### 2.3 `groups`

| Column | Type | Notes |
|---|---|---|
| `id` | PK AI | |
| `name` | VARCHAR(100) | required |
| `join_code` | VARCHAR(20) UNIQUE | generated uppercase code |
| `created_by` | INT UNSIGNED | FK → `users.id`, cascade |
| `max_members` | TINYINT UNSIGNED DEFAULT 10 | `CHECK (max_members > 0)` |
| `created_at`, `updated_at` | TIMESTAMP | |

The 5-groups-per-user and 10-members-per-group limits are enforced in PHP, not by the schema.

### 2.4 `group_members`

`id` PK, `group_id` FK cascade, `user_id` FK cascade, `role ENUM('admin','member')` default
`member`, `joined_at`. `UNIQUE (group_id, user_id)`.

There is no database constraint that enforces exactly one admin, or that the creator is the
admin. `groups.created_by` and `group_members.role` are kept consistent by PHP only.

### 2.5 `expenses`

| Column | Type | Notes |
|---|---|---|
| `id` | PK AI | |
| `user_id` | INT UNSIGNED NOT NULL | who recorded it; cascade |
| `paid_by` | INT UNSIGNED NULL | **actual payer**; `ON DELETE SET NULL` |
| `group_id` | INT UNSIGNED NULL | NULL = personal; `ON DELETE SET NULL` |
| `amount` | DECIMAL(12,2) NOT NULL | `CHECK (amount > 0)` |
| `category_id` | INT UNSIGNED NOT NULL | FK restrict |
| `note` | VARCHAR(255) NULL | |
| `expense_date` | DATE NOT NULL | |
| `type` | ENUM('personal','group') default `personal` | |
| `is_post_settlement` | TINYINT(1) default 0 | late-expense marker |
| `created_by` | INT UNSIGNED NULL | FK set null |
| `checked_by` | INT UNSIGNED NULL | list-check actor |
| `created_at`, `updated_at` | TIMESTAMP | |

Indexes:

```
idx_user_date     (user_id, expense_date)
idx_group_date    (group_id, expense_date)
idx_post_settl    (group_id, is_post_settlement)
idx_paid_by       (paid_by, expense_date)
idx_expense_date  (expense_date)
```

There is **no** database constraint tying `type='personal'` to `group_id IS NULL`; that rule is
enforced by application code only.

### 2.6 `budgets`

`id` PK, `user_id` FK cascade, `budget_month CHAR(7)` (`YYYY-MM`), `amount_limit
DECIMAL(12,2)` with `CHECK (amount_limit > 0)`, timestamps.
`UNIQUE (user_id, budget_month)` makes the write an upsert.

### 2.7 `lists`

`id` PK, `name` VARCHAR(100), `user_id` FK cascade (creator), `group_id` FK cascade NULL for
personal, `created_at`. No uniqueness on name.

### 2.8 `list_items`

| Column | Type | Notes |
|---|---|---|
| `id` | PK AI | |
| `list_id` | FK cascade | |
| `description` | VARCHAR(255) | required |
| `category_id` | FK set null | |
| `priority` | ENUM('high','moderate','low') default `low` | |
| `is_checked` | TINYINT(1) default 0 | |
| `price` | DECIMAL(12,2) NULL | `CHECK (price IS NULL OR price > 0)` |
| `checked_at` | DATETIME NULL | drives the 10-minute uncheck window |
| `expense_created` | TINYINT(1) default 0 | |
| `expense_id` | FK set null | exact link used for deletion |
| `added_by` | FK cascade | |
| `created_at` | TIMESTAMP | |

Indexes: `idx_list_priority (list_id, priority, created_at)`,
`idx_unpriced_lookup (is_checked, expense_created, list_id, checked_at)`.

`price` has no upper bound in the schema; the 99 999 999.00 ceiling is enforced in PHP on some
paths and missing on `check_item.php` confirm mode.

### 2.9 `notifications`

`id` PK, `user_id` FK cascade, `message` VARCHAR(500), `type` VARCHAR(50), `reference_id`
INT UNSIGNED NULL, `is_read` TINYINT(1) default 0, `created_at`.

Indexes: `idx_user_read (user_id, is_read, created_at)`,
`idx_notifications_user_time (user_id, created_at)`, `idx_notif_created (created_at)`.

There is no `event_id` column. `list.php`/`history.php` synthesize `event_id = id`.
`reference_id` has no FK and its meaning varies by producer.

### 2.10 `settlements`

| Column | Type | Notes |
|---|---|---|
| `id` | PK AI | |
| `group_id` | FK cascade | |
| `settled_by` | FK cascade | admin actor |
| `payer_id` | FK cascade | debtor |
| `payee_id` | FK cascade | creditor |
| `amount` | DECIMAL(12,2) | **`CHECK (amount >= 0)`** |
| `period_start`, `period_end` | DATE | |
| `created_at` | TIMESTAMP | |

Indexes: `idx_group_date (group_id, created_at)`,
`idx_settlement_period (group_id, period_start, period_end)`.

`amount >= 0` is deliberate: all-equal periods write a `0.00` marker row, which advances
`MAX(period_end)` and therefore the settlement lock.

`migration_v2.9_settlement_hardening.sql` adds
`chk_settlement_period (period_start >= '2000-01-01' AND period_start <= period_end)`.
**Drift:** that constraint is not present in `schema.sql`; a fresh clone that only runs
`schema.sql` lacks it until the migration is applied.

### 2.11 `settlement_confirmations`

`id` PK, `group_id` FK cascade, `user_id` FK cascade, `period_start` DATE, `period_end` DATE,
`confirmed_at`. `UNIQUE (group_id, user_id)`.

A user has at most one pending confirmation row per group. The key is **group + user**, not
settlement ID and not period. This is why finalization counts rows against active members and
why stale rows must be cleared after a period closes.

### 2.12 `post_settlement_confirmations`

`id` PK, `group_id` FK cascade, `user_id` FK cascade, `confirmed_at`.
`UNIQUE (group_id, user_id)`. No period columns: it tracks the current late-expense round.

### 2.13 `rate_limits`

`id` PK, `ip_address` VARCHAR(45), `action` VARCHAR(30), `attempted_at` TIMESTAMP.
Indexes: `idx_rate_ip_action (ip_address, action, attempted_at)`,
`idx_rate_attempted (attempted_at)`. No FK.

Used for per-user keys (`user:<id>`) and per-IP keys (`ip:<addr>`), plus login/signup/CSRF
buckets. Cleanup is action-aware.

### 2.14 `outbox_events`

`id` PK, `event_key` CHAR(64) UNIQUE, `event_type` VARCHAR(80), `payload_json` LONGTEXT,
`status ENUM('pending','processing','retryable','sent','dead')`, `retry_count`,
`next_attempt_at`, `processing_since`, `last_error`, `processed_at`, timestamps.
Indexes: `idx_outbox_status_next`, `idx_outbox_type_created`.

**The write side is unwired.** Nothing on the HTTP path inserts an outbox event. Only
`outboxStats()` is used by the health endpoint. This table is not a sync mechanism today.

---

## 3. Invariants and where they are enforced

| Invariant | Enforced by | Database support |
|---|---|---|
| `paid_by` is the settlement payer | `calculate.php`, `post_calculate.php`, helper | none beyond FK |
| Exclude `is_post_settlement = 1` from normal balances | every settlement aggregate | index only |
| Group expense dated inside a settled period is immutable | create/update/delete/price_unpriced | none |
| A period closes only when all members confirm | `confirm.php` + `recordSettlement()` | none |
| `settle*` admin-only | endpoint + helper | none |
| A personal expense has no group | PHP normalization | none |
| List uncheck deletes exactly the linked expense | `check_item.php` by `expense_id` | FK set null |
| Max 5 groups / 10 members | PHP only | `max_members > 0` only |
| Amounts > 0 and ≤ 99 999 999.00 | PHP + CHECK | CHECK on amount/price/budget |
| Settlement amounts may be 0.00 | CHECK `>= 0` | yes |

Database constraints are a backstop, not the primary enforcement layer. The Flutter client
must not assume the database will reject a logically invalid write that PHP currently blocks.

---

## 4. Query patterns

- All reads use `prepare` → `bind_param` → `execute` → `get_result`.
- Multi-step writes use `begin_transaction` / `commit` / `rollback` in the list conversion,
  pricing, confirmation, settlement, and group expense paths.
- `FOR UPDATE` exists in `confirm.php` and `price_unpriced.php`.
- `post_confirm.php` does not use `FOR UPDATE`.
- `groups/join.php` read-then-insert is untransacted.
- `groups/create.php` group insert + admin member insert are separate statements.
- Aggregate queries are `GROUP BY paid_by` / `GROUP BY category` / `GROUP BY DATE(expense_date)`.
- Settlement lock lookup is `SELECT MAX(period_end) FROM settlements WHERE group_id = ?`.
- Notification feeds use SARGable range predicates, not `DATE()` wrappers.

---

## 5. Migrations

| File | Purpose | State |
|---|---|---|
| `migration_v1.8.sql` | CHECK constraints + `idx_expense_date` parity | Idempotent, pre-flight guarded; deliberately unexecuted on live (already present) |
| `migration_v2.2.sql` | incremental schema | |
| `migration_v2.3.sql` | incremental schema | |
| `migration_v2.4.sql` | notification indexes | superseded in part by v2.9 |
| `migration_v2.5.sql` | notification/ephemeral groundwork | table shape only; code not wired |
| `migration_v2.6.sql` | incremental schema | |
| `migration_v2.7.sql` | incremental schema | |
| `migration_v2.8_performance_indexes.sql` | settlement/notification/rate/unpriced indexes | guarded |
| `migration_v2.9_notification_index.sql` | `idx_notifications_user_time` | guarded |
| `migration_v2.9_settlement_hardening.sql` | `chk_settlement_period` | guarded |

Phase 1 adds **no** migration. Schema changes are out of scope.

---

## 6. Data lifecycle

| Data | Created | Removed |
|---|---|---|
| Users | signup | no UI delete |
| Groups | create | creator delete (cascade) |
| Group membership | join/create/remove/leave | row delete |
| Expenses | create, list check, pricing | owner/admin delete; cascade with user/group |
| Budgets | set (upsert) | never deleted |
| Lists/items | list UI | list delete / item remove |
| Notifications | producers | `cleanup_notifications.php` after 7 days |
| Settlements | confirm/settle/settle_all | never deleted |
| Confirmations | confirm/post_confirm | deleted when period finalizes |
| Rate limits | every limited action | action-aware cleanup |
| Outbox | never | never |

There are no soft deletes and no tombstones. A deleted expense or list is gone.

---

## 7. Sync-readiness gap analysis

This is a **gap analysis only**. No schema change is made in Phase 1, and the Flutter client
must not be designed around a sync protocol that the backend cannot support.

### 7.1 What exists that helps

- Stable integer primary keys on all entity tables.
- `created_at` / `updated_at` on users, groups, expenses, budgets.
- `expense_id` FK linking a list item to its generated expense.
- Response-level idempotency for 11 write endpoints.
- `outbox_events` table shape (unused).
- Per-user scoping is explicit in nearly every query, which makes a "pull my data" API feasible.

### 7.2 Blocking gaps

| Gap | Impact on offline/sync | Evidence |
|---|---|---|
| No client-generated IDs / UUIDs | Client cannot create a row offline and reconcile without server ID remapping | all PKs are AUTO_INCREMENT |
| No global change cursor | Cannot ask "everything changed since X" | no `updated_at` on `lists`, `list_items`, `notifications`, `settlements`, confirmations |
| No tombstones | A deletion is invisible to a client that was offline | hard `DELETE` only |
| `updated_at` missing on several tables | Cannot detect edits by timestamp | `lists`, `list_items`, `settlements`, confirmations |
| `updated_at` has second granularity | Ties are possible within the same second | TIMESTAMP default precision |
| No row version / ETag | Cannot detect conflicting concurrent edits | none |
| No device or client identifier | Cannot attribute or dedupe offline writes | none |
| No per-entity change log | Cannot replay field-level changes | none |
| Response idempotency only | Replaying a request is safe; merging two offline edits is not | `config/idempotency.php` |
| Notification `reference_id` is polymorphic | Cannot reliably resolve the referenced entity | producers use expense/group/list IDs |
| Confirmation tables keyed by group+user | Two offline confirmations for different periods collapse | `UNIQUE (group_id, user_id)` |
| No database-enforced expense type/group consistency | A sync writer could create invalid rows | PHP-only rule |
| Server clock is authoritative | No timezone/clock-skew contract | `CURRENT_TIMESTAMP`, `date()` |
| Money is DECIMAL but JSON numbers are floats | Rounding differences across clients | `(float)` casts in responses |
| No conflict policy | Last write wins silently today | no version checks |

### 7.3 Consequences for the migration plan

1. Phase 1 cannot promise offline write sync. The Flutter app should be **online-first** for
   shared data.
2. Guest mode must be local-only and must never attempt to merge into the server without a
   future, explicit import design.
3. Analytics and PDF remain client-side; they need no schema support.
4. If sync is pursued later, it requires its own design phase (IDs, cursor, tombstones,
   conflict policy) and a migration. It must not be smuggled into the Flutter client as an
   assumption.
5. The dormant outbox is **not** a solution: it has no producer, no payload contract, and no
   consumer on the HTTP path.

---

## 8. Backup and operational notes

- `schema.sql` creates the database and seeds categories; it is safe to re-run (`IF NOT EXISTS`,
  `INSERT IGNORE`).
- Migrations are guarded and idempotent; v1.8 and v2.9 hardening are verified no-ops on a
  database that already has the objects.
- `logs/`, `data/`, `config/`, `tests/`, and `scripts/` are web-denied by the root `.htaccess`.
- The application account should be `expmg_app` with only `SELECT, INSERT, UPDATE, DELETE`
  (plus `DROP` on `rate_limits` for cleanup) — never root.
