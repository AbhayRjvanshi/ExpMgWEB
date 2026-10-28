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
│   ├── bootstrap.php           # ⚠ Still included by ZERO files (see KD-02)
│   ├── middleware/auth.php     # requireAuth() — first include of every endpoint;
│   │                           #   boots config/session.php, emits CSP + security
│   │                           #   headers, applies API rate limits, calls verifyCsrf()
│   ├── helpers/
│   │   ├── csrf.php            # verifyCsrf() — canonical CSRF check (+ IP gate 50/10min)
│   │   ├── validator.php       # validateDate(), parsePagination(), paginationMeta()
│   │   ├── logger.php          # logMessage(level, message, context) → logs/app.log
│   │   ├── response.php        # apiSuccess(), apiError() — not yet used by all endpoints
│   │   ├── rate_limiter.php    # checkRateLimit(), recordRateLimit(), cleanupRateLimits()
│   │   ├── outbox.php          # outboxQueueEvent()/outboxStats() — only stats are live
│   │   ├── notification_store.php  # File/Redis notification abstraction (KD-05: unwired)
│   │   └── notification_publisher.php  # publishGroupNotification() (KD-05: unwired)
│   ├── services/               # Infrastructure only — NONE on the HTTP path (KD-11)
│   │   ├── RedisService.php    # Connection pool — returns null gracefully if Redis unavailable
│   │   ├── CacheService.php    # Key-value cache abstraction
│   │   ├── LockService.php     # Distributed lock with file fallback
│   │   ├── HealthService.php   # Health aggregation (NOT used by system/health.php)
│   │   ├── MetricsService.php  # Request/queue metrics
│   │   ├── PredictiveHealthService.php   # reached only via process_outbox.php
│   │   ├── SystemOrchestrator.php        # reached only via process_outbox.php
│   │   ├── NotificationService_Redis.php  # Redis notification backend (not wired)
│   │   ├── FileNotificationStore.php      # File notification backend (not wired)
│   │   └── NotificationStore.php          # Store interface
│   ├── expenses/               # create, update, delete, list, summary, categories, unpriced, price_unpriced
│   ├── groups/                 # create, join, leave, delete, details, user_groups, remove_member
│   ├── settlements/            # calculate, confirm, settle, settle_all, history, details, post_calculate, post_confirm
│   ├── lists/                  # create, delete, details, user_lists, add_item, remove_item, check_item
│   ├── budgets/                # get, set
│   ├── notifications/          # count, list, history, read (MySQL-backed, read-only GETs)
│   ├── workers/process_outbox.php   # CLI-only replay worker (403 over HTTP)
│   └── system/health.php       # inline pressure/redis/outbox snapshot
├── config/
│   ├── db.php                  # mysqli from env() — no hardcoded credentials (was KD-01)
│   ├── env.php                 # phpdotenv loader — provides env() helper
│   ├── session.php             # NEW: configureSecureSessionCookie(),
│   │                           #   ensureSecureSessionStarted(), expmgSendSecurityHeaders()
│   └── idempotency.php         # idempotencyGuard()/idempotencyRespond() — wired to 11 POSTs
├── pages/                      # PHP page files included by public/index.php
│   └── lists.php               # 253 lines; delegates all JS to assets/js/lists.js
├── public/
│   ├── index.php               # SPA shell — routes ?page= to pages/ (+ CSP via session.php)
│   └── assets/
│       ├── js/
│       │   ├── helpers.js      # Shared: $(), $$(), get(), post(), escapeHTML(), API,
│       │   │                   #   per-intent Idempotency-Key cache
│       │   ├── app.js          # Main SPA logic — loads after helpers.js
│       │   └── lists.js        # Lists module — LOADED at pages/lists.php:253
│       └── css/styles.css      # Design system — 9 CSS token variables, mint-to-emerald palette
├── data/
│   ├── idempotency/            # File-backed idempotency store (orphan .lock files expire in 30s)
│   └── locks/                  # LockService file locks (dormant service)
├── logs/app.log                # 403 externally via root .htaccess (was KD-09)
├── scripts/
│   ├── cleanup_notifications.php    # cron/CLI, 7-day retention (403 over HTTP)
│   └── stress/                 # Phase 5 stress scripts — Idempotency-Key now honoured
├── schema.sql                  # Full DB schema (source of truth)
├── migration_v1.8.sql          # CHECK-constraint + idx_expense_date parity (guarded, idempotent, NOT applied to live DB)
├── migration_v2.2–v2.9.sql     # Incremental migrations (v2.9 = notification feed index)
├── run_tests.php               # 539-test suite, 427 pass / 112 fail (all failures catalogued in Test Suite Notes)
├── .htaccess                   # Root webroot lockdown (dotfiles, dumps, docs, internal dirs)
├── AGENTS.md                   # ← You are here
└── .mcp.json                   # Project-scoped MCP server config (403 via .htaccess)
```

---

## Database Schema (14 Tables)

```
users                        — id, username, email, password (bcrypt), created_at, updated_at
categories                   — id, name (seeded with 8+ defaults)
groups                       — id, name, join_code, created_by (admin)
group_members                — group_id, user_id, role (admin|member), joined_at
expenses                     — id, user_id, paid_by, group_id, category_id, amount, note,
                               expense_date, type (personal|group), is_post_settlement,
                               created_by, checked_by, created_at, updated_at
budgets                      — user_id, month (YYYY-MM), amount
lists                        — id, name, user_id, group_id, created_at
list_items                   — id, list_id, description, category_id, priority, price,
                               is_checked, checked_at, expense_created,
                               expense_id (FK → expenses), added_by, created_at
notifications                — id, user_id, message, type, reference_id, is_read, created_at
                               (no event_id column — the ephemeral store is unwired)
settlements                  — id, group_id, period_start, period_end, settled_by,
                               payer_id, payee_id, amount, settled_at
settlement_confirmations     — id, group_id, user_id, period_start, period_end, confirmed_at
                               (keyed by group+user+period — NOT by settlement_id)
post_settlement_confirmations — id, group_id, user_id, period_start, period_end, confirmed_at
rate_limits                  — id, ip_address, action, attempted_at
                               (indexes: idx_rate_ip_action, idx_rate_attempted)
outbox_events                — id, event_type, payload, status, created_at, claimed_at,
                               processed_at, retry_count, last_error (write side unwired)
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
| KD-01 | ~~`config/db.php` used hardcoded `define()`~~ | **FIXED** — reads `env('DB_*')` via `config/env.php` | Done |
| KD-02 | `api/bootstrap.php` defines CSP + session hardening but is included by 0 endpoints | **Superseded** — the same hardening now lives in `config/session.php` (+ called from `auth.php` and `public/index.php`); bootstrap.php is still dead code | Either delete bootstrap.php or make it the single front-controller include |
| KD-03 | ~~`public/assets/js/lists.js` never loaded~~ | **FIXED** — `pages/lists.php:253` loads `lists.js`; the 370-line inline block is gone | Done |
| KD-04 | ~~`config/idempotency.php` unused~~ | **FIXED** — `idempotencyGuard()` / `idempotencyRespond()` wrap 11 POST endpoints; `helpers.js` sends a per-intent key | Done |
| KD-05 | All notification producers use `INSERT INTO notifications`; `notification_publisher.php` / `notification_store.php` are never called | Planned v2.5 migration still incomplete — MySQL remains the only notification path | Route producers through `publishGroupNotification()` *and* migrate consumers together |
| KD-06 | `ExpenseService.php`, `GroupService.php`, `SettlementService.php`, `ListService.php`, `BudgetService.php`, `NotificationService.php` are expected by test section 28 but do not exist | Planned v1.7 service layer never built (88 failing assertions) | Real implementation only — never stubs |
| KD-07 | ~~`api/notifications/list.php` / `history.php` ran a DELETE on every GET~~ | **FIXED** — both are read-only; retention is `scripts/cleanup_notifications.php` (7 days) | Done |
| KD-08 | `data/idempotency/` may contain orphaned `.lock` files | `idempotencyCleanupExpired()` sweeps `.lock` files older than 30 s (~1% of requests) | Safe to delete `.lock` files older than 30 s |
| KD-09 | ~~`logs/app.log` web-accessible~~ | **FIXED** — root `.htaccess` denies `*.log` and `logs/` | Done (moving doc root to `public/` is still the stronger fix) |
| KD-10 | ~~`seed.sql` tracked with bcrypt hashes~~ | **FIXED** — `seed.sql` is no longer tracked (`git ls-files` is clean) | History rewrite still optional |
| KD-11 | Every file in `api/services/` is dormant: nothing outside `tests/`, `scripts/stress/` and the CLI worker includes them | `health.php` computes its snapshot inline; `PredictiveHealthService`/`SystemOrchestrator` are reachable only via `process_outbox.php` | Wire deliberately (and document), or delete |
| KD-12 | ~~`schema.sql` lacked the CHECK constraints (`chk_expense_amount`, `chk_budget_amount`, `chk_settlement_amount`, `chk_item_price`, `chk_max_members`) and `idx_expense_date`; no `migration_v1.8.sql`~~ | **FIXED** — schema.sql carries all six objects and `migration_v1.8.sql` exists (idempotent, pre-flight guarded). **Deliberately unexecuted**: the live DB already has them, so running it there is a verified no-op; a fresh clone applies it once | Done |

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

### Sessions and Security Headers — `config/session.php`
`api/middleware/auth.php` is the session owner for API traffic: it calls `configureSecureSessionCookie()` + `session_start()` before anything else (strict mode, cookies-only, `HttpOnly`, `SameSite=Lax`, `Secure` only on HTTPS), then `expmgSendSecurityHeaders()` (CSP, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`). `public/index.php` does the same for the SPA shell. Endpoints must **not** call `session_start()` themselves (test 27b enforces this).

`api/bootstrap.php` still contains a duplicate copy of this hardening and is included by **zero** files. Do not add it to endpoints — that would double-send headers. Either delete it or make it the single front-controller include (KD-02).

### CSRF — One Implementation, Two Entry Points
- `verifyCsrf()` in `api/helpers/csrf.php` — canonical. Double-submit (cookie + `X-CSRF-Token` header, with `$_SERVER` and POST-body fallbacks), failure recording, IP gate (50 failures / 10 min → `429`), `global $conn` for the rate limiter.
- `verifyCsrfToken()` in `api/middleware/auth.php` — a thin wrapper that now delegates to `verifyCsrf()`. `requireAuth()` calls it on POST/PUT/PATCH/DELETE.

When adding CSRF verification manually, always call `verifyCsrf()`. Do not re-implement token comparison.

### Redis Is Always Optional
`RedisService::get()` / `set()` / `increment()` return `null` when Redis is not connected — this is intentional graceful degradation, not a bug. All callers must handle `null` returns and fall back to DB or file-store equivalents. Do not add `throw new Exception` to these return paths.

---

## Endpoint Conventions

Every API endpoint must:

```php
<?php
// Session (hardened cookie flags) + CSP are booted by middleware/auth.php.
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';      // adjust path depth
require_once __DIR__ . '/../../api/middleware/auth.php';
require_once __DIR__ . '/../../api/helpers/logger.php';

requireAuth();  // exits 401 if not logged in; checks CSRF on POST/PUT/PATCH/DELETE

// ... business logic ...

echo json_encode(['ok' => true, ...]);
```

Do **not** add `session_start()` to a protected endpoint — the middleware already started the hardened session, and test 27b fails the build if an inline call reappears.

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

`run_tests.php` has 35 sections and **539 tests**. Current baseline: **427 passed / 112 failed**. Every remaining failure is accounted for below; none are unknown.

Failure breakdown as of the v2.17 safe set:

| Section | Failures | Cause |
|---|---|---|
| 28 | 88 | the planned business service layer does not exist (KD-06) |
| 34 | 11 | ephemeral notification store built but unwired: `NotificationService`/`consume()`, `read.php` still MySQL-backed, `history.php` raw SQL, service-level `notification_store` usage, frontend still keyed on numeric ids (KD-05) |
| 31, 32 | 3 | `31j`, `32u`, `32v` scan the same missing service files for `logger.php`, `LIMIT`/`array_slice`, `paginationMeta` |
| 30 | 7 | `response.php` adoption, GET-on-POST 405, validation-error 400 — deferred as its own 38-endpoint change |
| 9, 22, 26 | 3 | write-time HTML-entity assertions — **rejected by design** (see below) |

**Total: 88 + 11 + 3 + 7 + 3 = 112.**

### Two standing policy decisions

1. **Never store HTML-escaped entities in MySQL.** Raw text goes in (prepared statements), `esc()` escapes at render time. The three assertions that expect `O&#039;Brien` / `&lt;script&gt;` in the column are rejected: write-time escaping double-escapes on edit round-trips, breaks `LIKE` search, and corrupts exports.
2. **Section 30 is deferred, not rejected.** Moving 38 endpoints onto `response.php`/`apiError` plus try/catch and the 405/400 contracts is a single self-contained change with its own regression surface.

### Pagination ceilings (do not "normalise" these)

| Endpoint(s) | Call | Why |
|---|---|---|
| `expenses/list.php` | `parsePagination(20, 50)` (date), 500/1000 (month) | tests `32l`–`32o` assert defaults 20/1 and cap 50 |
| `notifications/list.php` | `parsePagination(30, 50)` | test `32s` asserts `pagination.limit === 30` |
| `lists/user_lists.php`, `groups/user_groups.php`, `groups/details.php`, `lists/details.php`, `settlements/history.php`, `settlements/details.php`, `expenses/unpriced.php`, `notifications/history.php` | `parsePagination(500, 1000)` | these feeds never send `?limit=`; a 20-row default silently truncates dropdowns and rosters |

`groups/details.php` paginates expenses but never members (max 10 per group), and `lists/details.php` returns all items (the UI sums every row) while still reporting `pagination` for endpoint parity.

To run: `php run_tests.php` (XAMPP must be running with Apache + MySQL on default ports). The runner is CLI-only and answers `403` over HTTP.

The standalone `php tests/distributed_system_test.php` is **not** part of `run_tests.php`: it runs 21/25 against the dormant distributed stack (Redis aggregation, readiness, cache-pattern invalidation and the latency score are the four known failures).

Do not "fix" the remaining sections by creating stub files that make assertions pass without implementing real behavior. The tests are specification, not cosmetic gates.

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
| v2.6 | `migration_v2.6.sql` |
| v2.8 | Performance index parity — `idx_settlement_period`, `idx_notif_created`, `idx_rate_attempted`, `idx_unpriced_lookup` |
| v2.9 | `migration_v2.9_notification_index.sql` — `idx_notifications_user_time (user_id, created_at)` for the notification feed |
| v2.11 | lists.js external module migration — loaded by `pages/lists.php` |
| v2.14 | README audit baseline |
| v2.15 | README audit corrections, .gitignore cleanup |
| v2.16 | Security hardening & wiring: webroot `.htaccess` lockdown, CLI-only gates, `config/session.php` + unified CSP/CSRF, idempotency wired to 11 POSTs, signup/login hardening, action-aware rate-limit retention, CSRF IP gate, read-only notification GETs. Suite: 403/134 of 537 |
| v2.17 | Safe set: `schema.sql` CHECK/index parity + guarded `migration_v1.8.sql`, login attempt accounting for unknown emails, `paginationMeta()` on all 10 list endpoints with per-endpoint ceilings (30/50 notifications, 20/50 expenses, 500/1000 feeds), `validator.php` required by every endpoint, validation-message fixes (`numeric`, `greater than zero`, real month range), INFO log on expense creation, `event_id` compat alias. Suite: 427/112 of 539 |

---

## Quick Reference — Where Things Live

| I need to... | Look at... |
|---|---|
| Change how auth works | `api/middleware/auth.php` |
| Add a new API endpoint | Copy structure from `api/expenses/create.php` |
| Change session/cookie policy | `config/session.php` — `configureSecureSessionCookie()` |
| Change CSP / security headers | `config/session.php` — `expmgSendSecurityHeaders()` |
| Fix CSRF verification | `api/helpers/csrf.php::verifyCsrf()` (+ IP gate) |
| Change notification delivery | `api/helpers/notification_publisher.php` (then wire it to producers) |
| Add idempotency to an endpoint | `config/idempotency.php::idempotencyGuard()` / `idempotencyRespond()` |
| Tune rate-limit retention | `api/helpers/rate_limiter.php::cleanupRateLimits()` |
| Query settlement balances | `api/settlements/settlement_helpers.php::calculateSettlements()` |
| Add a DB column | Update `schema.sql` AND write a `migration_vX.Y.sql` |
| Change the design system | `public/assets/css/styles.css` — 9 CSS token variables at top |
| Add shared JS utility | `public/assets/js/helpers.js` — loaded before `app.js` |
| Block a new path from the web | root `.htaccess` (deny) + a local `Require all denied` file |
| Run all tests | `php run_tests.php` from project root (CLI only) |
| Run the distributed suite | `php tests/distributed_system_test.php` (CLI only, 21/25) |
| Check app health | `GET /api/system/health.php` |

---

## Deferred File Activation Protocol

### Context
27 files for L8.5, L9.5, and L10.5 advanced extensions are documented in
`file_activation_manifest.json` but not yet created. They are created on demand
when activation rules are met, with user approval at every step.

### Key Files
- `.agents/core/meta-cognition/governance-sovereignty/file_activation_manifest.json`
  — Master manifest of all 27 deferred files (path, structured activation rules,
  initial content or template references, creation instructions, validator update
  required)
- `.agents/core/meta-cognition/governance-sovereignty/pending_activations.json`
  — Runtime queue of files awaiting user permission (starts empty, entries
  added by evaluate_file_activation_criteria() or direct user request)
- `.agents/core/meta-cognition/governance-sovereignty/governance_state_controller.py`
  — Provides evaluate_file_activation_criteria() which evaluates structured
  activation_rules against system state and populates the pending queue

### Three-Phase Flow

**Phase 1 — Initial Population:**
The first time activation rules are met (or user requests a file), the agent:
1. Reads `file_activation_manifest.json` and collects current system state
2. Presents full context: which file(s), why (which rule matched), where
   the file will be created, what initial content will be written or what
   template will be used, what validator changes are required
3. Asks explicit permission before creating anything

**Phase 2 — Permission Gate:**
The agent never creates a file or modifies a validator without explicit user
approval. Each step is presented independently.

**Phase 3 — Criteria-Driven (after first activation):**
- `governance_state_controller.py --evaluate-criteria` evaluates all structured
  activation rules against current system state
- If any rules match, entries are added to `pending_activations.json`
- The agent reads this queue at session start and follows the protocol below

### Session Protocol
1. At session start, read `pending_activations.json`
2. If any entries have `status: "awaiting_permission"`:
   a. Present each entry with full context from `file_activation_manifest.json`
      — what file, why (which rule matched), where, initial content or template
   b. Ask explicit permission to create the file
   c. On approval: create file with exact `initial_content` from manifest,
      or copy template file to target path
   d. Show the user what was created
   e. Ask: "Shall I update the validator to track this file?"
   f. On approval: make the edit to the validator's REQUIRED_STATE_FILES list
   g. Move entry from pending to resolved
3. Never proceed to the next step without user confirmation
