# ExpMgWEB — Full Project Audit Report

> Conducted on: 2026-06-03  
> Scope: Complete codebase scan — security, architecture, logic, data integrity, infrastructure

---

## CRITICAL ISSUES (Severity: P0 — Fix Immediately)

---

### ISSUE-01 · `.env` and `config/db.php` Committed to Git with Real Credentials

**File:** `.env`, `config/db.php`  
**What:** Both files containing database credentials (`root` user, DB name, host) and environment flags are tracked in Git and were included in the uploaded ZIP. `.gitignore` acknowledges this is wrong — it even has a comment saying `config/db.php` should use `env.php` — yet both files are committed.

**Effect:** Any person with access to the repository (or this ZIP) has complete database credentials. If this repo is ever pushed to a public or shared remote, you have a full data breach. The `.env` also exposes `APP_DEBUG=1` and `APP_ENV=development`, which should never appear in any production-bound artifact.

**Fix:**
1. Immediately rotate the database password.
2. Remove both files from git history: `git filter-branch` or `git-filter-repo` to purge historical commits.
3. Confirm `.env` and `config/db.php` are in `.gitignore` and **never** committed again.
4. In production, inject env vars via Docker, systemd environment files, or a secrets manager — never a `.env` file on disk.

---

### ISSUE-02 · `config/db.php` Does Not Use `.env` / `env()` — Dual Credential System

**File:** `config/db.php`  
**What:** `config/env.php` correctly loads `.env` via `phpdotenv` and exposes an `env()` helper. But `config/db.php` ignores all of this entirely and uses hardcoded `define()` constants with literal values. There are now **two separate credential sources** that can silently diverge.

**Effect:** Any change to `.env` DB credentials has zero effect because `db.php` is always used directly. You can change the database password in `.env`, deploy, and the app still connects using the old hardcoded value. This makes the env system useless for the most critical config of all.

**Fix:**
```php
// config/db.php — replace hardcoded defines with env()
require_once __DIR__ . '/env.php';

$conn = new mysqli(
    env('DB_HOST', '127.0.0.1'),
    env('DB_USER', 'root'),
    env('DB_PASS', ''),
    env('DB_NAME', 'ExpMgWEB'),
    (int) env('DB_PORT', 3306)
);
$conn->set_charset('utf8mb4');
```

---

### ISSUE-03 · Idempotency System Completely Unused on All Write Endpoints

**Files:** All 39 API endpoint files under `api/`  
**What:** `config/idempotency.php` implements a full `idempotencyBegin()` / `idempotencyFinish()` pair. Zero endpoints call either function. The entire idempotency infrastructure — file-based and Redis-backed — exists but is never invoked.

**Effect:** Every write endpoint (create expense, settle, budget set, group create, etc.) is vulnerable to duplicate execution on retries, double-submits (user double-clicking), and network timeouts. A user submitting an expense twice creates two identical records. A settlement can be recorded multiple times. This is especially dangerous for financial operations.

**Fix:** Wrap every POST mutation endpoint with the idempotency guards:
```php
// Top of each write endpoint, after requireAuth():
$idempCtx = idempotencyBegin((int)$_SESSION['user_id'], 'expenses/create');
if ($idempCtx && $idempCtx['replay']) {
    http_response_code($idempCtx['status']);
    echo json_encode($idempCtx['body']);
    exit;
}

// ... perform the actual write ...

idempotencyFinish($idempCtx, 200, ['ok' => true, 'id' => $newId]);
```
Priority targets: `expenses/create`, `expenses/delete`, `expenses/update`, `settlements/settle`, `settlements/settle_all`, `budgets/set`, `groups/create`, `groups/join`.

---

### ISSUE-04 · `api/settlements/settle.php` Has No Transaction and No Duplicate Prevention

**File:** `api/settlements/settle.php`  
**What:** The single-settlement endpoint inserts directly with no transaction, no uniqueness check, and no SKIP LOCKED guard. Concurrent or repeated POSTs will insert multiple identical settlement rows.

**Effect:** Financially critical — a group admin clicking "Settle" twice, or a network retry, creates duplicate settlement records. This corrupts the settlement history and breaks future balance calculations.

**Fix:**
```php
$conn->begin_transaction();
try {
    // Check if same settlement already exists
    $dup = $conn->prepare(
        'SELECT id FROM settlements WHERE group_id=? AND payer_id=? AND payee_id=? AND amount=? AND period_start=? AND period_end=? LIMIT 1'
    );
    $dup->bind_param('iiidss', $groupId, $payerId, $payeeId, $amount, $periodStart, $periodEnd);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'This settlement already exists.']);
        exit;
    }
    // ... insert ...
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    // ...
}
```

---

## HIGH ISSUES (Severity: P1 — Fix Before Production)

---

### ISSUE-05 · `seed.sql` Committed to Git (Contains Bcrypt Password Hashes)

**File:** `seed.sql`  
**What:** `seed.sql` is tracked in git despite `.gitignore` explicitly saying it "contains test user bcrypt hashes and demo data" and should be excluded. The file is present in the git index.

**Effect:** Password hashes for test users (including user_id=1 "Abhay Rajvanshi" visible in `logs/app.log`) are publicly version-controlled. Bcrypt hashes are safe from rainbow tables but can be cracked offline given enough compute. More importantly, if test credentials match production patterns, this is a credential leak.

**Fix:** `git rm --cached seed.sql`, add to `.gitignore`, purge history.

---

### ISSUE-06 · Two CSRF Functions Exist — `verifyCsrf()` vs `verifyCsrfToken()` — Called Inconsistently

**Files:** `api/helpers/csrf.php`, `api/middleware/auth.php`, `api/bootstrap.php`  
**What:** `api/helpers/csrf.php` defines `verifyCsrf()` (full verification: checks header AND cookie, logs failures, records CSRF failures). `api/middleware/auth.php` defines its own `verifyCsrfToken()` (checks only header and POST body, no cookie, no failure recording). `api/bootstrap.php` calls `verifyCsrf()`. `auth.php`'s `requireAuth()` calls `verifyCsrfToken()`.

**Effect:** An endpoint that goes through `requireAuth()` uses the weaker CSRF check — missing cookie validation and failure rate tracking. Critically, if both `bootstrap.php` and `auth.php` are loaded in the same request, CSRF is verified **twice** — once on every state-changing request by bootstrap, and again inside `requireAuth()`. This is redundant and potentially causes double 403s or side effects if token state changes between checks.

**Fix:** Delete `verifyCsrfToken()` from `auth.php`. Have `requireAuth()` call `verifyCsrf()` from `csrf.php`. Remove the CSRF check in `bootstrap.php` (since `auth.php` handles it for authenticated endpoints), or ensure `bootstrap.php` is not included alongside `auth.php`.

---

### ISSUE-07 · `data/` and `logs/` Directories Have No Web Server Protection

**Files:** `data/`, `logs/`, `data/idempotency/`, `data/locks/`  
**What:** If the web server's document root is set to the project root (which is the typical XAMPP/Apache setup), `data/idempotency/*.json`, `data/idempotency/*.lock`, `data/locks/*.lock`, and `logs/app.log` are directly accessible via HTTP. There is no `.htaccess` anywhere in the project.

**Effect:**
- `logs/app.log` reveals usernames, user IDs, IP addresses, all authentication events.
- `data/idempotency/*.json` contains serialized request payloads and response bodies from financial operations.
- An attacker can enumerate all active lock files and understand ongoing requests.

**Fix:**
1. Move the document root to `public/` — nothing outside `public/` should be web-accessible.
2. Or add `.htaccess` to each sensitive directory:
```apache
# data/.htaccess, logs/.htaccess
Order deny,allow
Deny from all
```
3. Add `config/db.php` and `config/env.php` protection even if inside web root.

---

### ISSUE-08 · `app_debug=1` and `app_env=development` Are the Hardcoded Defaults

**Files:** `config/env.php`, `.env`  
**What:** `config/env.php` sets `APP_ENV=development` and `APP_DEBUG=1` as defaults if no environment is found. The committed `.env` also has `APP_DEBUG=1`. These values are used nowhere in the codebase to conditionally change behavior (PHP's `display_errors` is not toggled), but they create a false sense of security — someone deploying to production and not configuring a `.env` will have debug mode silently on.

**Effect:** If `APP_DEBUG` were ever wired to `error_reporting(E_ALL)` and `display_errors = 1` (which is a natural next step), production would expose stack traces. Right now it's a latent risk but it's a wrong default.

**Fix:** Flip the defaults in `env.php`:
```php
$_ENV['APP_ENV']   = $envFromFile ?? 'production';
$_ENV['APP_DEBUG'] = $envFromFile ?? '0';
```

---

### ISSUE-09 · CSRF Failure Tracking Is Per-Session, Not Per-IP

**File:** `api/helpers/csrf.php`  
**What:** `recordCsrfFailure()` tracks failures in `$_SESSION['csrf_failures']`. An attacker without a session (e.g., scripted attack with no cookies) would trigger a new session per request — or bypass session-based tracking entirely.

**Effect:** The CSRF failure threshold (50 failures in 10 minutes triggering a `CRITICAL` log) provides no actual blocking. An attacker can make unlimited CSRF probes without ever hitting the counter, because each request starts a fresh session. The log entry is created but no IP block or response change occurs.

**Fix:** Track CSRF failures in the rate-limiting backend (DB or Redis) by IP address rather than in `$_SESSION`. Use `checkRateLimit` / `recordRateLimit` with action `'csrf_probe'`.

---

### ISSUE-10 · `db.php` Missing `set_charset('utf8mb4')`

**File:** `config/db.php`  
**What:** The `mysqli` connection is created without calling `$conn->set_charset('utf8mb4')`. The schema uses `utf8mb4` and `utf8mb4_general_ci`, but PHP's connection defaults to `latin1`.

**Effect:** Emoji and 4-byte Unicode characters in expense notes, group names, and usernames may be corrupted, silently truncated, or cause DB errors. This also opens a character encoding-based SQL injection vector on some MySQL versions when connection charset differs from server charset.

**Fix:**
```php
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn->connect_error) {
    $conn->set_charset('utf8mb4');
}
```

---

### ISSUE-11 · Notifications Bypass the Outbox System — Direct DB INSERT

**Files:** `api/expenses/create.php`, `api/expenses/delete.php`, `api/expenses/update.php`, `api/groups/join.php`, `api/settlements/settle_all.php`  
**What:** All five files that generate notifications do so with a direct `INSERT INTO notifications` query inline in the request path. The outbox system (`api/helpers/outbox.php`) exists specifically to decouple notification delivery, but it is never used for these primary notification events.

**Effect:** If the notification INSERT fails (DB issue, lock timeout), the main operation succeeds but the notification is silently lost — with no retry. If notification delivery is slow, it holds up the response to the user. There is no audit trail for notification delivery failures. The entire outbox/retry/dead-letter architecture is never exercised in practice.

**Fix:** Replace direct `INSERT INTO notifications` calls with `outboxQueueEvent()` calls:
```php
outboxQueueEvent($conn, 'group.expense_added', [
    'mode' => 'group',
    'group_id' => $groupId,
    'exclude_user_id' => $userId,
    'event' => [
        'event_id' => uniqid('evt_', true),
        'type' => 'group_expense_add',
        'message' => "$username added a new expense to $groupName.",
        'reference_id' => $newId,
    ]
]);
```

---

### ISSUE-12 · `api/workers/process_outbox.php` Has No CRON Auth / IP Guard When Called via HTTP

**File:** `api/workers/process_outbox.php`  
**What:** The worker checks `if (php_sapi_name() !== 'cli') { requireAuth(); }`, meaning any authenticated user can trigger the outbox worker via HTTP. This means any logged-in user can artificially drain or trigger the outbox queue.

**Effect:** An authenticated attacker can flood the outbox worker endpoint to cause excessive DB load. The `limit` parameter is taken from `$_GET` for HTTP calls — while capped by the `SystemOrchestrator`, this is still user-controllable.

**Fix:** Add an IP whitelist check for HTTP invocations, or restrict to CLI-only by removing the HTTP path entirely and running only via cron.

---

## MEDIUM ISSUES (Severity: P2 — Fix in Next Sprint)

---

### ISSUE-13 · `note` Field in Expenses Has No Server-Side Length Validation

**Files:** `api/expenses/create.php`, `api/expenses/update.php`  
**What:** `$note = trim($_POST['note'] ?? '')` — no maximum length check. The DB column is `VARCHAR(255)`.

**Effect:** A POST with a `note` exceeding 255 characters will either silently truncate (MySQL's default behavior in non-strict mode) or throw a DB error that results in `Failed to save expense.` with no useful message. A malicious user could test for truncation behavior. Worse, if strict mode is not enabled, data is silently corrupted.

**Fix:** Add `if (mb_strlen($note) > 255) { echo json_encode(['ok' => false, 'error' => 'Note must be 255 characters or fewer.']); exit; }` after trimming.

---

### ISSUE-14 · `expense_date` Validated With Regex Only — Invalid Dates Like `2024-02-30` Pass

**Files:** `api/expenses/create.php`  
**What:** `preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)` matches `2024-02-30`, `2024-13-01`, `0000-00-00`. The `validateDate()` helper in `api/helpers/validator.php` correctly calls `checkdate()` but is never used in `create.php` or `update.php`.

**Effect:** Invalid dates are stored in the database. Depending on MySQL strict mode, `2024-02-30` might be stored as-is (corrupted) or auto-corrected. Date comparisons in settlement logic (`expense_date <= $lockRow['last_end']`) can behave unexpectedly.

**Fix:** Use the existing helper: `validateDate($expenseDate, 'expense_date')` wrapped in a try-catch.

---

### ISSUE-15 · `api/settlements/settle.php` Does Not Validate That `period_start <= period_end`

**File:** `api/settlements/settle.php`  
**What:** Both date fields are validated for format only. No comparison checks `period_start <= period_end`. A settlement with `period_start='2024-12-31'` and `period_end='2024-01-01'` is accepted.

**Effect:** Inverted date ranges corrupt the settlement history. The lock-check query `expense_date <= MAX(period_end)` uses the stored `period_end` to block edits; an inverted settlement can incorrectly lock or unlock entire expense ranges.

**Fix:** After format validation, add: `if ($periodStart > $periodEnd) { echo json_encode(['ok' => false, 'error' => 'period_start must be on or before period_end.']); exit; }`

---

### ISSUE-16 · Rate Limit `cleanupRateLimits()` Hardcodes 1-Hour Cutoff But Actions Have Different Windows

**File:** `api/helpers/rate_limiter.php`  
**What:** `cleanupRateLimits()` deletes rows older than `time() - 3600` (1 hour). But `api_user` and `api_ip` actions use a 60-second window. This means the DB accumulates 59 extra minutes of `api_user`/`api_ip` records that have long since expired from a limiting perspective.

**Effect:** The `rate_limits` table grows 60× larger than necessary for high-traffic API actions. Under heavy load, the probabilistic 1% cleanup doesn't run often enough to trim the excess. DB queries on the `rate_limits` table slow down as the table inflates.

**Fix:** Pass the action's actual window to `cleanupRateLimits()`, or use the minimum of the action's window: `$cutoff = date('Y-m-d H:i:s', time() - min(array_values(rateLimiterCooldownWindow())))`.

---

### ISSUE-17 · Orphaned Idempotency Lock Files in `data/idempotency/`

**Directory:** `data/idempotency/`  
**What:** Two `.lock` files exist without corresponding `.json` files:
- `18b68a92...lock` (no JSON)
- `1b909af2...lock` (no JSON)

Additionally, `data/locks/` contains 6 empty lock files from previous process runs — none have a cleanup mechanism.

**Effect:** These lock files can permanently block idempotency keys if the hash of a new request matches an orphaned lock. `flock(LOCK_EX)` on an abandoned lock will block the request thread until the file is deleted. The `data/locks/` files from `LockService` fall back have no TTL and will never self-clean.

**Fix:**
1. Delete orphaned `.lock` files immediately.
2. Add a cleanup script to `scripts/` that removes `.lock` files older than `IDEMPOTENCY_LOCK_TTL_SECONDS` (30s).
3. In `LockService::acquireFileLock()`, add file-age TTL checks before `flock()`.

---

### ISSUE-18 · Stale Lock Files in `data/locks/` From Previous Runs

**Directory:** `data/locks/`  
**What:** Six `.lock` files from April 29 are present with 0 bytes. These are filesystem locks from `LockService::acquireFileLock()` fallback path. No cleanup code exists for these files.

**Effect:** These locks are 0-byte files left when a process crashed without calling `releaseLock()`. Any new lock acquisition on the same key will `fopen()` the existing file and attempt `flock(LOCK_EX)` — on Linux this succeeds immediately because no process holds the lock. But the file remains permanently until a manual cleanup, contributing to directory clutter and potential future confusion.

**Fix:** Add to `LockService::acquireFileLock()`: check `filemtime()` of the lock file; if older than DEFAULT_TTL (30s), unlink and retry. Add a `scripts/cleanup_locks.php` to the cron setup.

---

### ISSUE-19 · Password Minimum Length Is Only 6 Characters

**File:** `api/signup.php`  
**What:** `if (strlen($password) < 6)` — accepts passwords like `abc123`.

**Effect:** Weak passwords pass validation. Modern best practice (NIST SP 800-63B) recommends a minimum of 8 characters, with a focus on length over complexity. A 6-character password space is easily bruted even with bcrypt.

**Fix:** Raise to 8 characters minimum. Optionally add a `zxcvbn`-style strength hint on the frontend.

---

### ISSUE-20 · `api/outbox.php` Unnecessarily `require`s `api/middleware/auth.php`

**File:** `api/helpers/outbox.php`  
**What:** Line 4: `require_once __DIR__ . '/../middleware/auth.php';` — `auth.php` in turn requires `config/db.php`, `helpers/response.php`, `helpers/logger.php`, and `helpers/rate_limiter.php`. This creates a dependency chain in a low-level helper that should have no knowledge of authentication state.

**Effect:** Any file that includes `outbox.php` (including the background worker and tests) drags in the full auth middleware. This causes `requireAuth()` definitions and rate limiter initialization in contexts where they are not needed. If `auth.php` is refactored, `outbox.php` breaks.

**Fix:** Remove the `auth.php` require from `outbox.php`. `outbox.php` already correctly requires `config/db.php` directly. The only auth-related thing used in `outbox.php` is `logMessage()` — which comes from `logger.php`, not `auth.php`.

---

## LOW ISSUES (Severity: P3 — Technical Debt)

---

### ISSUE-21 · Session Cookie `secure` Flag Is Hardcoded to `false`

**Files:** `api/bootstrap.php`, `api/helpers/csrf.php`  
**What:** Both `session_set_cookie_params()` and `setcookie()` calls have `'secure' => false` hardcoded with a comment "Set to true in production with HTTPS".

**Effect:** If deployed over HTTPS (even partially), session cookies and CSRF cookies can be transmitted over unencrypted connections, enabling cookie theft on mixed-content pages.

**Fix:** Make this conditional on the environment:
```php
'secure' => (env('APP_ENV') === 'production' || isset($_SERVER['HTTPS'])),
```

---

### ISSUE-22 · `rate_limiter.php` Records Successful Auth After Check — Does Not Count Legitimate Load

**File:** `api/middleware/auth.php`  
**What:** `requireAuth()` calls `checkRateLimit()` (returns false if over limit, does NOT record), then calls `recordRateLimit()` at the end (after auth is successful). This is correct. However, `api/login.php` only calls `recordRateLimit()` on **failed** password verification, not on successful login.

**Effect:** A valid login does not consume any rate limit tokens. An attacker who knows a valid password can log in unlimited times per minute with zero throttling. The login rate limit only punishes failed attempts, not successful ones — which is correct behavior for brute-force protection but means a valid credential is never rate-limited.

**Assessment:** This is the intended design for brute-force protection (don't penalize correct logins). However, it allows unlimited successful logins from the same IP, which could be relevant for session-farming attacks. Acceptable as-is but worth documenting.

---

### ISSUE-23 · `public/index.php` Loads `helpers.js` With `time()` Cache-Buster on Every Request

**File:** `public/index.php`  
**What:** `<script src="assets/js/helpers.js?v=<?= time() ?>">` — `time()` changes every second, so `helpers.js` is never cached by the browser.

**Effect:** Every single page load re-downloads `helpers.js` (407 lines). No browser caching. In production this unnecessarily increases load times and server bandwidth, and fights CDN caches.

**Fix:** Use a file hash or build version: `?v=<?= filemtime(__DIR__ . '/assets/js/helpers.js') ?>`. This gives cache-busting on deploy while allowing caching between deploys.

---

### ISSUE-24 · `api/groups/create.php` Defines `generateJoinCode()` as a Function Inside an Endpoint File

**File:** `api/groups/create.php`  
**What:** `generateJoinCode()` is defined as a named function at the top level of `create.php`. If any other file ever includes `create.php`, PHP will throw a fatal "Cannot redeclare function" error.

**Effect:** Latent breakage risk during testing or refactoring. Any test that includes `api/groups/create.php` twice will crash.

**Fix:** Move `generateJoinCode()` into a shared helper (e.g., `api/helpers/groups.php`) or wrap in `if (!function_exists('generateJoinCode'))`.

---

### ISSUE-25 · `api/signup.php` Has a TOCTOU Race Condition on Username/Email Uniqueness

**File:** `api/signup.php`  
**What:** The uniqueness check (`SELECT id FROM users WHERE username=? OR email=?`) and the `INSERT INTO users` are separate queries with no transaction or `INSERT ... ON DUPLICATE KEY IGNORE` pattern. Two simultaneous signup requests with the same email can both pass the uniqueness check before either inserts.

**Effect:** In high-concurrency scenarios (rare for this app's scale), duplicate users can be created. The DB has `UNIQUE KEY` constraints on `email` and `username`, so the second insert will throw a mysqli error — but the user sees a generic "Something went wrong" message rather than "Email already taken."

**Fix:** Rely on the DB unique constraint as the authoritative check. Use `INSERT IGNORE` or catch the duplicate-key error code (1062):
```php
if ($conn->errno === 1062) {
    $_SESSION['auth_error'] = 'Username or email already taken.';
} else {
    $_SESSION['auth_error'] = 'Something went wrong.';
}
```

---

### ISSUE-26 · `logMessage()` Has No Log Rotation or Size Cap

**File:** `api/helpers/logger.php`  
**What:** `logMessage()` appends to `logs/app.log` with `FILE_APPEND | LOCK_EX`. No rotation, size check, or external log management.

**Effect:** On a production server handling steady traffic, `app.log` grows indefinitely. The current log sample shows notifications polling every 10 seconds — in a week that alone produces ~60,000 log lines. A large log file slows every write and can fill the disk.

**Fix:** Implement log rotation: either use `logrotate` at OS level (add a `scripts/logrotate.conf`), or add a size check inside `logMessage()`:
```php
if (file_exists($logFile) && filesize($logFile) > 50 * 1024 * 1024) { // 50 MB
    rename($logFile, $logFile . '.' . date('YmdHis'));
}
```

---

### ISSUE-27 · `api/system/health.php` Logs the Health Status Twice Per Call

**File:** `api/system/health.php`  
**What:** The health endpoint calls `logMessage()` twice in sequence — once with message `'System health snapshot'` and immediately again with `'[HEALTH] status update'` — with identical context data.

**Effect:** Every health check (which may be polled frequently by monitoring) writes two identical log entries. This doubles the log bloat from health polling and makes it harder to grep for genuine events.

**Fix:** Remove the second `logMessage()` call or merge both into one.

---

### ISSUE-28 · `settle_all.php` — Settlement "Marker" for Zero-Debt Case Is Semantically Wrong

**File:** `api/settlements/settle_all.php`  
**What:** When all members contributed equally and `calculateSettlements()` returns an empty array, the code inserts a dummy settlement record with `payer_id = payee_id = $userId` and `amount = 0.0` to mark the period as settled. This row is a self-settlement for amount zero.

**Effect:** `history.php` and `calculate.php` queries include this row in settlement history. It shows as a `$0.00` settlement from the admin to themselves. Balance recalculation must filter it out to avoid miscounts. The `period_end` lock check still works correctly, but the UX of showing a self-settlement is confusing.

**Fix:** Instead of inserting a dummy row, use a dedicated `settled_periods` table or a flag column on the `groups` table to mark periods as closed without requiring a synthetic transaction record.

---

### ISSUE-29 · `app.js` `catch (_) {}` Silently Swallows Errors in Multiple Places

**File:** `public/assets/js/app.js`  
**What:** At least 8 `catch (_) {}` blocks (lines 93, 337, 506, 507, 783, 794, 805) swallow exceptions without any error logging, user feedback, or retry logic.

**Effect:** When a network error, parse error, or unexpected API response occurs in these paths, the UI silently enters an inconsistent state. Users see nothing — not an error message, not a loading indicator — and have no way to know the operation failed. This is the "frontend must not map request failures to empty-state UI" issue you've previously identified.

**Fix:** Replace `catch (_) {}` with meaningful handlers that at minimum log to `console.error()` and show a generic error toast. For operations with UI state (like expense deletion), revert the optimistic UI update on failure.

---

## SUMMARY TABLE

| # | Issue | Severity | Category | File(s) |
|---|-------|----------|----------|---------|
| 01 | `.env` and `db.php` committed to Git | **P0 Critical** | Security | `.env`, `config/db.php` |
| 02 | `db.php` ignores env system | **P0 Critical** | Architecture | `config/db.php` |
| 03 | Idempotency system unused on all writes | **P0 Critical** | Data Integrity | All API endpoints |
| 04 | `settle.php` has no transaction / dedup | **P0 Critical** | Data Integrity | `api/settlements/settle.php` |
| 05 | `seed.sql` committed with password hashes | **P1 High** | Security | `seed.sql` |
| 06 | Dual CSRF functions, inconsistently called | **P1 High** | Security | `csrf.php`, `auth.php`, `bootstrap.php` |
| 07 | `data/` and `logs/` web-accessible | **P1 High** | Security | Directory structure |
| 08 | Debug mode on as default | **P1 High** | Security | `config/env.php`, `.env` |
| 09 | CSRF failure tracking is per-session not per-IP | **P1 High** | Security | `api/helpers/csrf.php` |
| 10 | Missing `set_charset('utf8mb4')` on DB conn | **P1 High** | Data Integrity | `config/db.php` |
| 11 | Notifications bypass outbox system | **P1 High** | Architecture | 5 endpoint files |
| 12 | Outbox worker accessible to any authed user | **P1 High** | Security | `api/workers/process_outbox.php` |
| 13 | `note` field has no length validation | **P2 Medium** | Validation | `expenses/create.php`, `update.php` |
| 14 | Expense dates validated with regex only | **P2 Medium** | Validation | `expenses/create.php` |
| 15 | Settlement dates not compared for ordering | **P2 Medium** | Logic | `settlements/settle.php` |
| 16 | Rate limit cleanup window hardcoded to 1hr | **P2 Medium** | Performance | `api/helpers/rate_limiter.php` |
| 17 | Orphaned idempotency lock files | **P2 Medium** | Infrastructure | `data/idempotency/` |
| 18 | Stale lock files in `data/locks/` | **P2 Medium** | Infrastructure | `data/locks/` |
| 19 | Password minimum only 6 characters | **P2 Medium** | Security | `api/signup.php` |
| 20 | `outbox.php` unnecessarily requires `auth.php` | **P2 Medium** | Architecture | `api/helpers/outbox.php` |
| 21 | Session cookie `secure` flag hardcoded false | **P3 Low** | Security | `bootstrap.php`, `csrf.php` |
| 22 | Successful login doesn't consume rate limit | **P3 Low** | Logic | `api/login.php` |
| 23 | `helpers.js` cache-busted with `time()` | **P3 Low** | Performance | `public/index.php` |
| 24 | `generateJoinCode()` defined inside endpoint | **P3 Low** | Code Quality | `api/groups/create.php` |
| 25 | Signup TOCTOU race on uniqueness check | **P3 Low** | Data Integrity | `api/signup.php` |
| 26 | No log rotation on `app.log` | **P3 Low** | Operations | `api/helpers/logger.php` |
| 27 | Health endpoint double-logs per call | **P3 Low** | Operations | `api/system/health.php` |
| 28 | Settle-all inserts semantically wrong marker row | **P3 Low** | Logic | `api/settlements/settle_all.php` |
| 29 | Silent `catch (_) {}` blocks in frontend JS | **P3 Low** | UX / Reliability | `public/assets/js/app.js` |

---

## WHAT IS WORKING WELL

- Prepared statements used consistently throughout — no raw SQL string interpolation.
- `session_regenerate_id(true)` called on successful login — session fixation is handled.
- Consistent same error message for invalid email and wrong password — no user enumeration via error message.
- `hash_equals()` used for all CSRF token comparisons — timing attack resistant.
- `settle_all.php` uses a proper DB transaction with rollback — the multi-row case is protected.
- `bootstrap.php` sets comprehensive security headers (CSP, X-Frame-Options, X-Content-Type-Options, etc.).
- Rate limiter has Redis fallback to DB — graceful degradation is correct.
- `outboxClaimDueEvents()` uses `FOR UPDATE SKIP LOCKED` with a fallback — production-grade.
- `calculateSettlements()` greedy algorithm correctly minimizes the number of transactions.

---

*End of Audit Report — 29 issues documented across 4 severity levels.*
