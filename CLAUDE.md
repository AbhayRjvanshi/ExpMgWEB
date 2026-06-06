# ExpMgWEB — Agent Context & Project Rules

> Read this file before touching any code. It is the authoritative source of project
> conventions, known debt, critical rules, and architecture decisions for every AI agent,
> CLI tool, and IDE assistant working on this codebase.
>
> Compatible with: Claude Code · Cursor Agent · Windsurf · GitHub Copilot · Gemini CLI

---

## Stack & Runtime

| Layer | Technology |
|---|---|
| Backend | PHP 8+ procedural (no framework, no ORM) |
| Database | MySQL 8 — raw `mysqli` prepared statements only |
| Frontend | Vanilla JS (ES2020) — no build step, no bundler |
| Server | Apache via XAMPP on Windows (`C:/xampp/htdocs/ExpMgWEB`) |
| Cache / Queue | Redis (optional) — file-store fallback always available |
| Auth | PHP sessions — `requireAuth()` guard on every API endpoint |

**No Composer autoload in endpoints.** Every file uses explicit `require_once` chains.
**No `$conn->query()` with variables.** Only `prepare()` → `bind_param()` → `execute()`.

---

## Project Structure

```
ExpMgWEB/
├── api/                        # All API endpoints (38 files)
│   ├── bootstrap.php           # Security headers + CSP + session hardening
│   │                           # ⚠ NOT YET included by endpoints (see Known Debt)
│   ├── middleware/auth.php     # requireAuth() — include at top of every endpoint
│   ├── helpers/
│   │   ├── csrf.php            # verifyCsrf() — canonical CSRF check
│   │   ├── validator.php       # validateDate(), parsePagination(), etc.
│   │   ├── logger.php          # logMessage(level, message, context)
│   │   ├── response.php        # apiSuccess(), apiError() — not yet used by all endpoints
│   │   ├── rate_limiter.php    # checkRateLimit(), recordRateLimit()
│   │   ├── outbox.php          # outboxQueueEvent() — durable async delivery
│   │   ├── notification_store.php  # File/Redis notification abstraction (NOT wired to producers)
│   │   └── notification_publisher.php  # publishGroupNotification() (NOT wired to producers)
│   ├── services/               # Infrastructure services only (no business logic services)
│   │   ├── RedisService.php    # Connection pool — returns null gracefully if Redis unavailable
│   │   ├── CacheService.php    # Key-value cache abstraction
│   │   ├── LockService.php     # Distributed lock with file fallback
│   │   ├── HealthService.php   # Health metrics aggregation
│   │   ├── MetricsService.php  # Request/queue metrics
│   │   ├── PredictiveHealthService.php
│   │   ├── SystemOrchestrator.php
│   │   ├── NotificationService_Redis.php  # Redis notification backend (not wired)
│   │   ├── FileNotificationStore.php      # File notification backend (not wired)
│   │   └── NotificationStore.php          # Thin store wrapper
│   ├── expenses/               # create, update, delete, list, summary, categories, unpriced, price_unpriced
│   ├── groups/                 # create, join, leave, delete, details, user_groups, remove_member
│   ├── settlements/            # calculate, confirm, settle, settle_all, history, details, post_calculate, post_confirm
│   ├── lists/                  # create, delete, details, user_lists, add_item, remove_item, check_item
│   ├── budgets/                # get, set
│   ├── notifications/          # count, list, history, read
│   ├── workers/process_outbox.php
│   └── system/health.php
├── config/
│   ├── db.php                  # ⚠ HARDCODED credentials — does not read from .env (see Known Debt)
│   ├── env.php                 # phpdotenv loader — provides env() helper
│   └── idempotency.php         # idempotencyBegin() / idempotencyFinish() (not yet wired to endpoints)
├── pages/                      # PHP page files included by public/index.php
│   └── lists.php               # ⚠ Contains 370-line inline <script> block (see Known Debt)
├── public/
│   ├── index.php               # SPA shell — routes ?page= to pages/
│   └── assets/
│       ├── js/
│       │   ├── helpers.js      # Shared: $(), $$(), get(), post(), escapeHTML(), API constant
│       │   ├── app.js          # Main SPA logic — loads after helpers.js
│       │   └── lists.js        # ⚠ More complete lists module — NOT loaded by index.php (see Known Debt)
│       └── css/styles.css      # Design system — 9 CSS token variables, mint-to-emerald palette
├── data/
│   ├── idempotency/            # File-backed idempotency store (may contain orphaned .lock files)
│   └── locks/                  # LockService file locks (may contain stale files from crashed processes)
├── logs/app.log                # ⚠ Web-accessible if served from project root (no .htaccess)
├── scripts/
│   └── stress/                 # Phase 5 stress scripts — pass Idempotency-Key but endpoints ignore it
├── schema.sql                  # Full DB schema (source of truth)
├── migration_v2.2–v2.6.sql     # Incremental migrations
├── run_tests.php               # 525-test suite (sections 28–34 test planned-but-unbuilt features)
├── CLAUDE.md                   # ← You are here
└── .mcp.json                   # Project-scoped MCP server config
```

---

## Database Schema (13 Tables)

```
users                        — id, username, email, password_hash, created_at
categories                   — id, name (seeded with 8+ defaults)
groups                       — id, name, join_code, created_by (admin)
group_members                — group_id, user_id, role (admin|member), joined_at
expenses                     — id, user_id, group_id, category_id, amount, note,
                               expense_date, paid_by, created_by, checked_by,
                               is_post_settlement, is_personal
budgets                      — user_id, month (YYYY-MM), amount
lists                        — id, user_id, group_id, name, created_by
list_items                   — id, list_id, description, price, priority, is_checked,
                               checked_at, checked_by, expense_id (FK → expenses)
notifications                — id, user_id, type, message, reference_id, is_read, created_at
settlements                  — id, group_id, period_start, period_end, settled_by,
                               payer_id, payee_id, amount, settled_at
settlement_confirmations     — id, settlement_id, user_id, confirmed_at
post_settlement_confirmations — id, group_id, user_id, period_start, period_end, confirmed_at
rate_limits                  — action, identifier, count, window_start
outbox_events                — id, event_type, payload, status, created_at, claimed_at,
                               processed_at, retry_count, last_error
```

---

## Critical Business Rules — Never Violate These

### Settlement Logic
- **`paid_by` not `user_id`**: Settlement GROUP BY and contribution calculations MUST use `paid_by` (actual payer). Using `user_id` or `created_by` silently corrupts balances.
- **`is_post_settlement = 0` filter**: Every settlement query on `expenses` MUST include `AND e.is_post_settlement = 0`. Removing this double-counts late expenses. This was a critical v1.3 fix.
- **Settlement lock is API-enforced**: Expenses within a settled period range (`expense_date` between `period_start` and `period_end` of any settlement for that group) must be rejected on edit and delete. Check `settlements` table before mutating any group expense.
- **3-step confirmation required**: A period only closes when ALL active `group_members` have a row in `settlement_confirmations` for that settlement. Check count vs member count — never assume.
- **`FOR UPDATE` in confirm.php**: The confirmation race condition is fixed with a transaction + `SELECT ... FOR UPDATE`. Never simplify this to a bare INSERT.
- **Post-settlement flow is separate**: Late expenses (`is_post_settlement = 1`) go through `post_calculate.php` → `post_confirm.php`, not the normal settle flow. They update past settlement records rather than creating new ones.
- **settle.php is admin-only**: Any call to `settle.php` by a non-admin must be rejected. This was a critical v1.3 fix.

### Expense Permissions
- Personal expense: only owner (`user_id`) can edit/delete.
- Group expense: only the group admin (`role = 'admin'` in `group_members`) can edit/delete.
- Non-admin attempts must return `ok: false` with HTTP 403.

### List-to-Expense Conversion
- Checking a **priced** list item auto-creates an expense. `expense_id` (FK column on `list_items`) must be stored immediately.
- Unchecking must delete the linked expense by `expense_id` (exact FK). Never match by note + date + amount (fragile — v1.3 bug fix).
- Group list items return `needs_confirm: true` on check; caller must POST `paid_by` to confirm.
- `checked_at` is a datetime — always convert: `date('Y-m-d', strtotime($item['checked_at']))` before using as `expense_date`. Using the raw datetime string as a date breaks comparisons (v1.3 bug fix).

### Group Limits
- Max 5 groups per user. Enforce before INSERT.
- Max 10 members per group. Enforce on join.

### Notification Reference IDs
- `reference_id` in `notifications` must point to the **expense ID** (`$newId` / `insert_id`), not the group ID. Using `$groupId` was a critical v1.3 bug; do not revert it.

---

## Known Debt — Do Not Accidentally "Fix" These Into Regressions

These are intentional incomplete states, not bugs to quietly resolve:

| ID | What | Status | Safe to change? |
|---|---|---|---|
| KD-01 | `config/db.php` uses hardcoded `define()` — ignores `env()` | Broken by design during ZIP export | Fix: replace with `env('DB_HOST', ...)` etc. |
| KD-02 | `api/bootstrap.php` defines CSP + session hardening but is included by 0 endpoints | Not wired — must be added to front controller | Fix: require in every endpoint or create front controller |
| KD-03 | `public/assets/js/lists.js` has full rate-limit-aware implementation but is never loaded by `index.php` | Inline version in `lists.php` is active | Fix: add `<script src="assets/js/lists.js">` to index.php, remove inline block |
| KD-04 | `config/idempotency.php` implements `idempotencyBegin()` / `idempotencyFinish()` but zero endpoints call either | Not wired — stress scripts send `Idempotency-Key` header but it is ignored | Fix: wrap all write endpoints |
| KD-05 | All 16 notification producers use `INSERT INTO notifications` directly; `notification_publisher.php` and `notification_store.php` exist but are never called | Planned v2.5 migration incomplete | Fix: route all producers through `publishGroupNotification()` |
| KD-06 | `ExpenseService.php`, `GroupService.php`, `SettlementService.php`, `ListService.php`, `BudgetService.php`, `NotificationService.php` are expected by tests (sections 28–34) but do not exist in this ZIP | Planned v1.7 service layer never built | These files are missing from the uploaded snapshot |
| KD-07 | `api/notifications/list.php` and `history.php` run a table-wide DELETE on every read request | Cleanup should be in a cron job | Fix: move to `scripts/cleanup_notifications.php` |
| KD-08 | `data/idempotency/` may contain orphaned `.lock` files from crashed processes | Manual cleanup or TTL check needed | Safe to delete `.lock` files older than 30 seconds |
| KD-09 | `logs/app.log` is web-accessible if document root is project root | No `.htaccess` exists anywhere | Fix: move doc root to `public/`, or add `.htaccess` Deny |
| KD-10 | `seed.sql` is git-tracked despite containing bcrypt hashes | Must be removed from history | `git rm --cached seed.sql` |

---

## Architecture Decisions

### Why No Framework
Raw PHP + mysqli is intentional. Zero framework boot time, easy to audit, deployable on any XAMPP/LAMP setup without Composer complexity. The tradeoff is manual repetition in `require_once` chains — that is acceptable for this project's scope.

### Why Procedural Not OOP (Endpoints)
Each endpoint file owns its full request lifecycle. The services in `api/services/` are infrastructure (Redis, locking, caching) — they are OOP because they manage stateful resources. Business logic in endpoints stays procedural by design.

### Notification Architecture (Current vs Intended)
**Current (what actually runs):** All producers → `INSERT INTO notifications` (MySQL) → all consumers read from MySQL `notifications` table. One consistent system.

**Intended (not yet wired):** Producers → `publishGroupNotification()` in `notification_publisher.php` → `notification_store.php` (file or Redis based on `NOTIFICATIONS_BACKEND`) → consumers read from same backend.

Do not assume these two systems are in sync. The publisher/store abstraction is dead code today.

### CSRF — Two Functions, Use the Right One
- `verifyCsrf()` in `api/helpers/csrf.php` — canonical, full implementation. Double-submit (cookie + header), failure recording, IP logging. **Use this one.**
- `verifyCsrfToken()` in `api/middleware/auth.php` — simpler, no cookie check, no failure recording. Called by `requireAuth()` internally.

When adding CSRF verification manually, always call `verifyCsrf()`, never `verifyCsrfToken()`.

### Redis Is Always Optional
`RedisService::get()` / `set()` / `increment()` return `null` when Redis is not connected — this is intentional graceful degradation, not a bug. All callers must handle `null` returns and fall back to DB or file-store equivalents. Do not add `throw new Exception` to these return paths.

---

## Endpoint Conventions

Every API endpoint must:

```php
<?php
session_start();
require_once __DIR__ . '/../../config/db.php';      // adjust path depth
require_once __DIR__ . '/../../api/middleware/auth.php';
require_once __DIR__ . '/../../api/helpers/logger.php';
header('Content-Type: application/json');

requireAuth();  // exits with 401 if not logged in; checks CSRF on POST/PUT/PATCH/DELETE

// ... business logic ...

echo json_encode(['ok' => true, ...]);
```

Response shape is always `{'ok': bool, ...}` — never bare arrays, never `{'success': bool}`.

Error responses: `echo json_encode(['ok' => false, 'error' => 'Human-readable message']);`

### Input Validation
- Amounts: `$amount = (float)($_POST['amount'] ?? 0); if ($amount <= 0) { ... }`
- Dates: use `validateDate()` from `validator.php` — never just `preg_match('/^\d{4}-\d{2}-\d{2}$/')` alone (passes `2024-02-30`)
- Strings: `trim()` then `mb_strlen()` check against DB column limit before INSERT
- Method guard: `if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }`

### DB Pattern
```php
$stmt = $conn->prepare('SELECT ... WHERE id = ? AND user_id = ?');
$stmt->bind_param('ii', $id, $userId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();
```

Always close statements. Always check `$stmt` is not `false` before `bind_param`.
Always wrap multi-step writes in `$conn->begin_transaction()` / `$conn->commit()` / `$conn->rollback()`.

---

## Test Suite Notes

`run_tests.php` has 35 sections, 525 tests total. **Sections 1–27 and 35 should all pass.** Sections 28–34 test planned-but-unbuilt features (service layer, pagination, ephemeral notifications) — failures there are expected against the current codebase.

To run: `php run_tests.php` (XAMPP must be running with Apache + MySQL on default ports).

The single known real failure: `FAIL: Consume notification` in `test_output.txt` is caused by a PHP Warning on line 570 that breaks the HTTP response stream in the XAMPP/Windows environment. It passes cleanly in `test_cross_check.txt` (Linux env run).

Do not "fix" test sections 28–34 by creating stub files that make assertions pass without implementing real behavior. The tests are specification, not cosmetic gates.

---

## MCP Servers Available in This Project

See `.mcp.json` (project root) and `.cursor/mcp.json` for configuration.

| Server | Purpose |
|---|---|
| `filesystem` | Read/write all project files — used for multi-file context |
| `git` | Blame, diff, log — understand why a line exists before changing it |
| `fetch` | Live PHP 8 / Chart.js / jsPDF / Redis docs — prevents hallucinated signatures |
| `sequential-thinking` | Step-by-step reasoning chains for settlement logic and race conditions |
| `mysql` | **Read-only** live DB queries against all 13 tables (INSERT/UPDATE/DELETE disabled) |
| `context7` | Version-accurate library documentation injected on demand |
| `redis` | Inspect live Redis keys — disable if Redis not running locally |

---

## Version History (Summary)

| Version | Key Change |
|---|---|
| v1.3 | Critical audit: 6 security fixes, settlement race condition, `paid_by` fix, `is_post_settlement` filter |
| v1.7 | Planned: service layer extraction (ExpenseService etc.) — NOT BUILT |
| v2.1 | Planned: pagination migration — partially built |
| v2.5 | Planned: ephemeral notification system (file/Redis store) — built but not wired |
| v2.6 | Latest migration in repo — `migration_v2.6.sql` |
| v2.11 | Planned: lists.js external module migration — file exists, not loaded |
| v2.14 | Current git HEAD (per README) |
| v2.15 | Last commit: README audit corrections, .gitignore cleanup |

---

## Quick Reference — Where Things Live

| I need to... | Look at... |
|---|---|
| Change how auth works | `api/middleware/auth.php` |
| Add a new API endpoint | Copy structure from `api/expenses/create.php` |
| Change session/cookie policy | `api/bootstrap.php` (then wire it in) |
| Fix CSRF verification | `api/helpers/csrf.php::verifyCsrf()` |
| Change notification delivery | `api/helpers/notification_publisher.php` (then wire it to producers) |
| Add idempotency to an endpoint | `config/idempotency.php::idempotencyBegin()` / `idempotencyFinish()` |
| Query settlement balances | `api/settlements/settlement_helpers.php::calculateSettlements()` |
| Add a DB column | Update `schema.sql` AND write a `migration_vX.Y.sql` |
| Change the design system | `public/assets/css/styles.css` — 9 CSS token variables at top |
| Add shared JS utility | `public/assets/js/helpers.js` — loaded before `app.js` |
| Run all tests | `php run_tests.php` from project root |
| Check app health | `GET /api/system/health.php` |
