# Backend Audit

Source snapshot: `5ed8de3` (documentation prepared at `66dedc1`). All statements below were
checked against the PHP source, not against README claims.

---

## 1. Surface counts

| Category | Count | Files |
|---|---|---|
| Endpoint + auth + worker files | 41 | **40 HTTP endpoints** (36 module + `login.php`, `logout.php`, `signup.php` + `system/health.php`) **plus 1 CLI-only worker** |
| Middleware | 1 | `api/middleware/auth.php` |
| Helpers | 10 | `csrf`, `validator`, `logger`, `response`, `rate_limiter`, `outbox`, `notification_store`, `notification_publisher`, `notifications_config`, `redis` |
| Dormant services | 10 | `RedisService`, `CacheService`, `LockService`, `HealthService`, `MetricsService`, `PredictiveHealthService`, `SystemOrchestrator`, `NotificationService_Redis`, `FileNotificationStore`, `NotificationStore` |
| Dead bootstrap | 1 | `api/bootstrap.php` |
| Settlement helper | 1 | `api/settlements/settlement_helpers.php` |
| **Total PHP files under `api/`** | **64** | |

Only 41 of the 64 files are endpoint/auth/worker files: **40 HTTP endpoints** (36 module
endpoints, 3 auth endpoints, and `system/health.php`) plus one CLI-only worker that is not
reachable over HTTP. The rest are infrastructure, dormant code, or the dead bootstrap.

### Endpoint census

```
auth            login.php, logout.php, signup.php                         3 HTTP
expenses        categories, create, delete, list, price_unpriced,
                summary, unpriced, update                                 8 HTTP
groups          create, delete, details, join, leave, remove_member,
                user_groups                                               7 HTTP
settlements     calculate, confirm, details, history, post_calculate,
                post_confirm, settle, settle_all                          8 HTTP
                + settlement_helpers.php  (shared helper, NOT an endpoint)
lists           add_item, check_item, create, delete, details,
                remove_item, user_lists                                   7 HTTP
budgets         get, set                                                   2 HTTP
notifications   count, history, list, read                                 4 HTTP
system          health                                                     1 HTTP
workers         process_outbox  (CLI only, not HTTP reachable)             0 HTTP
```

```
module HTTP endpoints   36   (8 + 7 + 8 + 7 + 2 + 4)
auth HTTP endpoints      3
system HTTP endpoints    1
--------------------------
HTTP endpoints total    40

CLI-only worker          1   (workers/process_outbox.php)
non-endpoint helper      1   (settlements/settlement_helpers.php)
```

---

## 2. Standard request pipeline

Most endpoints follow this shape:

```php
<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/logger.php';

requireAuth();                 // session + 24h expiry + CSRF + rate limits
// optional: idempotencyGuard(), requirePostMethod()/requireGetMethod()
// parse input, validate, prepare/bind/execute, close
// emit {ok: true, ...} or {ok: false, error: "..."}
```

### 2.1 Auth middleware

`api/middleware/auth.php` performs, in order:

1. `ensureSecureSessionStarted()` — hardened cookie, strict mode.
2. `expmgSendSecurityHeaders()` — CSP, `nosniff`, frame denial, referrer policy.
3. `requireAuth()`:
   - missing `user_id` → HTTP **401** `{"ok":false,"error":"Authentication required"}`
   - session older than 86 400 s → HTTP **401** `{"ok":false,"error":"Session expired"}`
   - CSRF failure on writes → HTTP **403** (message from `csrf.php`)
   - per-user 120/min or per-IP 240/min exceeded → HTTP **429** with `Retry-After`
   - `requireAuth(false)` (heartbeat) → 30/min/user `api_heartbeat` bucket
4. `session_write_close()` releases the session lock before rate-limit DB work.

`requireAuth()` never returns; all failure paths `exit`.

### 2.2 CSRF

Canonical implementation: `api/helpers/csrf.php::verifyCsrf()`. Request token sources, in order:

1. `X-CSRF-Token` header (read via `getallheaders()`, case-insensitive);
2. `$_SERVER['HTTP_X_CSRF_TOKEN']` (same header where `getallheaders()` is unavailable);
3. POST body `csrf_token`.

The `csrf_token` cookie is **not** a request token source. It is an optional consistency check:
compared to the session token only when present, skipped when absent. See `API_CONTRACT.md` §4.

Failures are recorded per IP; 50 failures in 10 minutes produce a `429` gate. The session token
is rotated about every 12 hours. `verifyCsrfToken()` in the middleware is a thin wrapper.

`login.php` and `signup.php` also call `verifyCsrf()`, so pre-auth POSTs without a token are
rejected. `logout.php` is the exception: no CSRF check and no method guard (KD-31).
The 403 messages are `CSRF token missing from session`, `CSRF token missing from request`,
`Invalid CSRF token`, and `CSRF cookie mismatch`.

### 2.3 Response envelope

Two generations coexist:

| Helper | Success body | Error body | Default status |
|---|---|---|---|
| Raw `echo json_encode` | `{"ok":true,…}` | `{"ok":false,"error":"…"}` | 200 (unless set) |
| `apiResponse($result, $status=0)` | `{"ok":true,"success":true,"code":200,…}` | `{"ok":false,"success":false,"error":"…","code":400}` | **400 for `ok:false`** |
| `apiError($msg,$status=400)` | — | `{"ok":false,"success":false,"error":"…","code":…}` | given status |
| `apiSuccess($data,$status=200)` | `{"ok":true,"success":true,"code":200,…}` | — | 200 |

All module folders (`expenses`, `groups`, `lists`, `budgets`, `notifications`, `settlements`)
now include `response.php`, so their error responses carry HTTP 400 by default plus the
`success`/`code` keys. Authentication endpoints do not use this envelope for normal form flows.

**Contract consequence:** a Flutter client must read the `ok` field and the HTTP status, and
must tolerate the extra `success`/`code` keys. It must not assume `200` for a business error.

### 2.4 Method guards

`requirePostMethod()` returns **405** with `Allow: POST`; `requireGetMethod()` returns **405**
with `Allow: GET`. Adoption is partial: settlement and expense endpoints use them explicitly;
some older paths retain an inline method check that returns `apiResponse(['ok'=>false,
'error'=>'Invalid method.'])` with HTTP 400. `logout.php` has no guard at all and therefore
accepts every method. The contract records the effective status per endpoint.

### 2.5 Idempotency

`config/idempotency.php`:

- key source: `Idempotency-Key` header, max 200 bytes;
- scope: `sha256(user_id | route | key)`;
- payload binding: `sha256(sorted $_POST)`;
- TTL: 900 s; lock TTL: 30 s;
- backends: file (default) or Redis (`IDEMPOTENCY_BACKEND`);
- same key + same payload → replay cached status/body;
- same key + different payload → HTTP **409**
  `{"ok":false,"error":"Idempotency key reuse with different payload."}`;
- key in progress → HTTP **409**
  `{"ok":false,"error":"A request with this idempotency key is already in progress."}`;
- absent key → feature bypass, normal execution.

Wired endpoints (exactly 11, verified by grep): `expenses/create`, `expenses/update`,
`expenses/delete`, `expenses/price_unpriced`, `groups/create`, `groups/join`, `budgets/set`,
`settlements/confirm`, `settlements/post_confirm`, `settlements/settle`,
`settlements/settle_all`. List endpoints and `groups/delete` are **not** idempotency-wrapped.
See `API_CONTRACT.md` §6 for the per-endpoint table.

### 2.6 Rate limiting

`api/helpers/rate_limiter.php` uses MySQL `rate_limits` and can use Redis when available.
Buckets observed:

| Bucket | Limit | Window |
|---|---|---|
| `api_user` | 120 | 60 s |
| `api_ip` | 240 | 60 s |
| `api_heartbeat` | 30 | 60 s |
| `login` | 5 | 900 s |
| `signup` | 3 | 3600 s |

`signup` counts **successful registrations only** — `recordRateLimit()` runs inside the
`if ($insertOk)` branch (`api/signup.php:128-130`), so validation failures, duplicates, and DB
errors do not consume the quota. It limits account creation, not attempts.
| `csrf_fail` | 50 | 600 s |

`cleanupRateLimits()` applies action-aware retention. A denied request receives
`{"ok":false,"error":"Too many requests. Rate limited.","retry_after":N,"limited_by":"user|ip"}`.

### 2.7 Validation helpers

`api/helpers/validator.php`:

- `validateDate($value, $label)` — accepts only a real calendar `YYYY-MM-DD`, throws otherwise.
- `parsePagination($default, $max)` — clamps `page ≥ 1`, `limit` to `1…max`.
- `paginationMeta($page,$limit,$total)` — `{page, limit, total, pages}` (no `has_more`).
- `normalizeMoney($value)` — numeric, rounds to 2 dp, range 0.01…99 999 999.00.

Some endpoints still use `preg_match` for month shape (`^\d{4}-\d{2}$`) plus a range check;
`create.php` adds `checkdate()` explicitly.

---

## 3. Module-by-module behaviour

### 3.1 Authentication

| Endpoint | Method | Behaviour |
|---|---|---|
| `api/signup.php` | POST | Validates username 3–50, valid email, password ≥ 8, matching confirm; bcrypt hash; duplicate username/email → flash error; success → flash + redirect to login; IP bucket 3/hour counting successful registrations only; CSRF required |
| `api/login.php` | POST | Validates non-empty; unknown email and bad password both record a failed attempt and return the same message; success regenerates session ID, stores `user_id`, `username`, `login_time`, rotates CSRF, redirects to SPA; IP bucket 5/15 min |
| `api/logout.php` | **Any** (no guard) | **No CSRF and no method guard**, so every HTTP method behaves the same. Clears session and cookie, redirects to login (KD-31) |

Normal failures are **HTTP 302 redirects with session flash messages**, not JSON. The
rate-limit rejection is JSON with HTTP 429.

### 3.2 Expenses

| Endpoint | Method | Key inputs | Permission |
|---|---|---|---|
| `categories.php` | GET | — | any authenticated user |
| `list.php` | GET | `date` or `month` or `start`+`end`, `page`, `limit` | own personal + groups |
| `summary.php` | GET | `month` (required) | own personal + groups |
| `create.php` | POST | `amount`, `category_id`, `note`, `expense_date`, `type`, `group_id`, `paid_by` | personal or member of target group |
| `update.php` | POST | `id` + any of `amount`,`category_id`,`note`,`type`,`group_id`,`paid_by` | personal owner / group admin |
| `delete.php` | POST | `id` | personal owner / group admin |
| `unpriced.php` | GET | `page`,`limit` | items in own lists / member groups |
| `price_unpriced.php` | POST | `item_id`, `price`, `paid_by` | list owner or group member |

Notable verified behaviour:

- `create.php` normalizes `type` to exactly `personal`/`group`, rejects an explicitly invalid
  type, enforces amount 0.01…99 999 999.00, calendar-valid date, category > 0, note ≤ 255.
- `create.php` validates group membership and captures the group name for notifications.
- `create.php` blocks a group expense dated on or before `MAX(period_end)`.
- `create.php` requires `paid_by` for a group expense and validates that the payer is a member
  of that group; personal expenses force `paid_by = user_id`.
- `update.php` is true field-merge PATCH: omitted fields keep their existing values.
- `update.php` normalizes `type`, forces `paid_by = user_id` and `group_id = NULL` for personal
  expenses.
- `update.php` authorizes an existing **group** expense only by checking that the caller is a
  member of the expense's **current** group. It then permits changing `group_id` to another
  group and `paid_by` to any user ID. A caller who is a member of the current group but not an
  admin, or a member of both groups, can move/assign the expense. This is a real authorization
  gap and is preserved as documented debt.
- `update.php` contains a `type` validity check that is ineffective because `$type` has already
  been normalized; the explicit check lives in `create.php`.
- `expenses/list.php` returns `can_edit` (bool) and `settled` (bool) per row. `settled` is true
  when a settlement's `period_end >= expense_date`.
- `expenses/list.php` uses `parsePagination(20, 50)` for `date`, and 500/1000 for month/range.
- `summary.php` returns `total_spent`, `personal_total`, `group_total`, `group_share`,
  `by_category`, `by_day`, `budget`, `month`.
- `price_unpriced.php` locks the item with `FOR UPDATE`, checks the settlement lock, inserts the
  expense (marking `is_post_settlement` when the date is inside a settled period), then links
  with `AND expense_created = 0`; a concurrent link rolls back with 400.

### 3.3 Groups

| Endpoint | Method | Inputs | Rule |
|---|---|---|---|
| `user_groups.php` | GET | pagination | groups the user belongs to |
| `create.php` | POST | `name` | max 5 owned groups; unique join code; creator becomes admin |
| `join.php` | POST | `join_code` | code normalized uppercase; duplicate membership and capacity checked |
| `details.php` | GET | `group_id` | member only; group, members, expenses, pagination |
| `remove_member.php` | POST | `group_id`, `user_id` | admin only; cannot remove admin |
| `leave.php` | POST | `group_id` | member only; admin cannot leave |
| `delete.php` | POST | `group_id` | creator only; cascades |

Verified gaps:

- `join.php` reads the member count and then inserts without a transaction or lock. Two
  simultaneous joins can exceed `max_members` under race conditions. Capacity is also only
  enforced in PHP, not by a database constraint.
- `create.php` inserts the group and the admin membership in two separate statements without a
  transaction. A failure between them can leave a group with no member row.
- `remove_member.php` does not reassign group expenses paid by the removed member; historical
  settlement maths still references their `paid_by` value (the user row remains, so joins still
  resolve).

### 3.4 Settlements

| Endpoint | Method | Inputs | Rule |
|---|---|---|---|
| `calculate.php` | GET | `group_id` | member; computes contributions and transfers |
| `confirm.php` | POST | `group_id` | member; records confirmation; finalizes when all confirmed |
| `settle.php` | POST | `group_id`, `payer_id`, `payee_id`, `amount`, `period_start`, `period_end` | **admin only**; delegates to `recordSettlement()` |
| `settle_all.php` | POST | `group_id` | **admin only**; force-settles all suggested transfers |
| `history.php` | GET | `group_id`, pagination | member; 500/1000 |
| `details.php` | GET | `group_id`, `start`, `end`, pagination | member; period expenses + total |
| `post_calculate.php` | GET | `group_id` | member; late expenses since last settlement |
| `post_confirm.php` | POST | `group_id` | member; confirmation for late expenses |

Verified behaviour:

- `calculate.php` computes contributions with `SUM(amount) GROUP BY paid_by`, filters
  `is_post_settlement = 0`, and excludes dates `<= last period_end`.
- `period_start` is the day after the last settlement (or the earliest expense), and
  `period_end` is `max(last expense date, today)`.
- `per_person = round(total_spend / member_count, 2)`; balances are
  `contribution - per_person`, then greedily matched debtor→creditor.
- `confirm.php` runs a transaction with `SELECT … FOR UPDATE` on the confirmation set, inserts
  or updates the caller's row, counts confirmations against active members, and only then
  finalizes. It writes settlements (including `0.00` markers), deletes confirmation rows, and
  commits.
- `post_confirm.php` mirrors the flow for late expenses but **does not** use `FOR UPDATE` around
  its insert/count/finalize block. This is a preserved race window.
- `settle.php` validates real dates, rejects reversed ranges and future `period_end`, verifies
  admin, and delegates all write gating to `recordSettlement()`.
- `recordSettlement()` enforces member parties, confirmation completeness, computed-transfer
  match, duplicate guard, and transactionality.
- `settle_all.php` is the admin force path; it clears lingering confirmation rows after
  finalization to keep post-conditions identical to `confirm.php`.
- `settlements/details.php` validates dates with `validateDate()` and rejects reversed ranges,
  returning `400 "Invalid date format."`.

### 3.5 Lists

| Endpoint | Method | Inputs | Rule |
|---|---|---|---|
| `user_lists.php` | GET | pagination | own personal + member group lists |
| `create.php` | POST | `name`, `group_id` | personal owner or group member |
| `delete.php` | POST | `list_id` | personal owner; group admin or list creator |
| `details.php` | GET | `list_id` | owner / group member; all items |
| `add_item.php` | POST | `list_id`, `description`, `category_id`, `priority`, `price` | list access; priority enum; price 0.01…99 999 999.00 |
| `remove_item.php` | POST | `item_id` | list access |
| `check_item.php` | POST | `item_id`, optional `paid_by`, `price`, `confirm` | list access; group payer must be a member |

`check_item.php` branches:

1. **Confirm mode** (`confirm=1`): group list only; requires `paid_by`; validates membership;
   in one transaction optionally updates the price, creates the expense, and collapses the item
   update; notifies other members. Personal lists ignore confirm mode and take the normal path.
2. **Uncheck** (`is_checked=1` → 0): enforces the 600-second window; deletes the linked expense
   by exact `expense_id`; clears the item link; notifies the group.
3. **Check, group list**: marks the item checked immediately and returns
   `needs_confirm: true`, the item preview, and the member list. No expense is created yet.
4. **Check, personal list**: in one transaction creates the expense when the item has a price,
   links it, and collapses the item update.

Verified gaps:

- Confirm-mode `price` is used directly (`floatval`, only `> 0` check) and has **no upper
  bound**, unlike `add_item.php`. A confirmed group item can therefore carry a price above
  99 999 999.00, which the `expenses` insert would reject under the CHECK constraint, leaving
  the item checked/priced/unconfirmed.
- The group check marks the item checked before the popup; cancelling the popup strands the
  item (see `FEATURE_INVENTORY.md` LST-8).

### 3.6 Budgets

| Endpoint | Method | Inputs | Rule |
|---|---|---|---|
| `get.php` | GET | `month` | returns `{budget: {id, amount_limit, month}}` or `null` |
| `set.php` | POST | `month`, `amount_limit` | upsert; > 0 and ≤ 99 999 999.00; reports previous value |

`get.php` is not called by any frontend module; the summary endpoint embeds the same value.

### 3.7 Notifications

| Endpoint | Method | Inputs | Behaviour |
|---|---|---|---|
| `count.php` | GET | — | today's unread count + latest unread; `requireAuth(false)` |
| `list.php` | GET | `unread_only=1`, pagination | today's notifications; 30/50 ceiling |
| `history.php` | GET | pagination | last 7 days; 500/1000 ceiling |
| `read.php` | POST | `all=1` or `event_id`/`id` | marks read; returns `marked` |

All four are read-only except `read.php`, which only updates `is_read`. There is no DELETE on a
GET. `list.php` and `history.php` add an `event_id` alias equal to `id` for compatibility.

### 3.8 System

`api/system/health.php` requires auth and returns an inline snapshot of DB pressure, Redis
status, and outbox counts. It does **not** use `HealthService`. Observable HTTP failure returns
500 `"Internal server error."`.

---

## 4. Settlement helper internals

`api/settlements/settlement_helpers.php`:

- `calculateSettlements(array $balances, array $usernames): array` — deterministic greedy
  matching; returns transfers with payer/payee IDs, names, and amounts.
- `recordSettlement(...)` — one transaction:
  1. verify group + admin caller;
  2. verify payer and payee are current members;
  3. verify all active members have confirmed the period;
  4. verify the requested amount matches a computed transfer (tolerance 0.01);
  5. reject duplicates for the same group/period/payer/payee/amount;
  6. insert the settlement row (allowing `0.00`);
  7. commit.

This helper is the only writer that enforces the C1/C3 security gates.

---

## 5. Dead and dormant backend code

| Item | Status | Evidence |
|---|---|---|
| `api/bootstrap.php` | Dead | Included by zero files; duplicates CSP/session logic now in `config/session.php` |
| `api/services/*` (10 files) | Dormant | Included only by tests, stress scripts, and the CLI worker |
| `api/helpers/notification_publisher.php` | Dormant | No producer calls it |
| `api/helpers/notification_store.php` | Dormant | No producer/consumer uses it |
| `api/helpers/outbox.php` | Partially live | `outboxStats()` is used by health; the event queue write side is unused |
| `api/workers/process_outbox.php` | Dormant | CLI-only, 403 over HTTP; no events produced |
| `partials/lists_section.php` | Dead | Not included by any live page |

Phase 1 neither wires nor deletes these files. See [`TECHNICAL_DEBT.md`](TECHNICAL_DEBT.md).

---

## 6. Backend behaviours the Flutter client must not assume away

1. `ok` is the authoritative success flag; HTTP status may be 400 for a handled business error.
2. Some write endpoints return `200` even when the body says `ok:false` if they use a raw
   `json_encode`; always check both.
3. Idempotency is opt-in by header and payload-bound.
4. Pagination defaults/ceilings differ by endpoint (see `API_CONTRACT.md` §7).
5. Settlement endpoints are membership-gated, and `settle*` are admin-only.
6. A group expense dated inside a settled period is rejected on every write path.
7. `paid_by`, not `user_id`, is the settlement payer.
8. Notification `reference_id` is not a stable navigation key.
