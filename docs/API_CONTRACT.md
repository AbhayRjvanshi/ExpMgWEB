# API Contract

**Audience:** the Flutter client developer.
**Authority:** the PHP source at snapshot `5ed8de3`. Every parameter, default, status code, and
message below was read from the implementation.
**Status:** frozen for the duration of the migration. Changes require a new version of this
document and a backend change review.

---

## 1. Base URL and transport

Local XAMPP example:

```
http://localhost/ExpMgWEB/api
```

The SPA shell uses the relative base `../api`, so the same code works under any subdirectory.
All endpoints are plain PHP files. There is no router and no trailing-slash convention:
`/api/expenses/list.php` is the resource.

- Requests are `application/x-www-form-urlencoded` or `multipart/form-data` (the frontend uses
  `FormData`).
- Responses are `application/json`, except login/signup/logout normal flows, which are `302`
  redirects.
- Cookies are same-origin. The Flutter HTTP client must persist the PHP session cookie.
- There is no CORS configuration; do not call the API from a browser origin other than the app.

---

## 2. Authentication

Every endpoint except `signup.php`, `login.php`, and `logout.php` starts with `requireAuth()`.

| Condition | HTTP | Body |
|---|---|---|
| No session `user_id` | 401 | `{"ok":false,"error":"Authentication required"}` |
| Session older than 24 h | 401 | `{"ok":false,"error":"Session expired"}` |
| CSRF missing/invalid on write | 403 | see §4 — not applicable to `logout.php` |
| Rate limit exceeded | 429 | see §5 |
| Valid session | continues | — |

`requireAuth()` exits; no endpoint code runs after a failure.

### 2.1 Login

`POST /api/login.php`

| Field | Required | Rules |
|---|---|---|
| `email` | yes | non-empty; looked up exactly |
| `password` | yes | non-empty; `password_verify` |
| `csrf_token` | yes | header `X-CSRF-Token` or body field |

Success:

```
HTTP 302
Location: ../public/index.php
Set-Cookie: PHPSESSID=<new>; HttpOnly; SameSite=Lax
```

The session stores `user_id`, `username`, `login_time`, and a fresh CSRF token.

Failure:

```
HTTP 302
Location: ../pages/login.php
Session flash: auth_error = "Invalid email or password." | "Please fill in all fields."
```

Unknown email and wrong password are indistinguishable. Both record a failed attempt.

Rate limit (5 failures / 15 min / IP):

```
HTTP 429
{"ok":false,"error":"Rate limited","retry_after":N,"limited_by":"login"}
```

### 2.2 Signup

`POST /api/signup.php`

| Field | Required | Rules |
|---|---|---|
| `username` | yes | 3–50 characters |
| `email` | yes | valid email |
| `password` | yes | at least 8 characters |
| `confirm_password` | yes | must match |
| `csrf_token` | yes | as above |

Success → `302` to `../pages/login.php` with `auth_success = "Account created! Please sign in."`
Failure → `302` to `../pages/signup.php` with `auth_error` containing one or more of:

```
Username must be 3–50 characters.
Please enter a valid email address.
Password must be at least 8 characters.
Passwords do not match.
Username or email already taken.
```

Rate limit: 3 successful registrations / hour / IP → HTTP 429 JSON, `limited_by: "signup"`.
Only rows actually inserted are recorded (`api/signup.php:130`); validation failures, duplicate
username/email, and DB errors do **not** consume the quota. The limit therefore caps account
creation, not registration attempts.

### 2.3 Logout

`/api/logout.php` accepts **any** HTTP method — it has no method guard at all, so `GET`, `POST`,
`PUT`, `DELETE`, and the rest behave identically. There is no auth requirement and **no CSRF
verification**. The file calls `session_start()`, empties `$_SESSION`, expires the session
cookie, calls `session_destroy()`, then `302` to `../pages/login.php`.

**Forced-logout risk.** The relevant case is a cross-site **top-level navigation using a safe
method** — an attacker page linking to `https://host/ExpMgWEB/api/logout.php`, or any link the
user clicks. The session cookie is `SameSite=Lax`, so it is attached to top-level safe-method
navigations and that request does end the session.

Embedded cross-site requests cannot do this: an `<img>`, `<iframe>`, or `fetch()` from another
origin is a subresource request, `SameSite=Lax` does not attach the session cookie to it, and
`session_start()` therefore finds no session to destroy.

The impact is a nuisance logout, not an account compromise: the request carries no data and
cannot act as the user. It is recorded as low-severity debt (`TECHNICAL_DEBT.md` KD-31) and is
**not** fixed during the freeze.

For the Flutter client: call it on explicit sign-out only, and treat a 302 to the login page as
success. Do not send a CSRF token — it is ignored.

---

## 3. Response envelope

### 3.1 Success

Raw endpoints:

```json
{"ok": true}
```

`apiResponse`/`apiSuccess` endpoints additionally include:

```json
{"ok": true, "success": true, "code": 200}
```

### 3.2 Business error

```json
{"ok": false, "error": "Human-readable message"}
```

`apiResponse`/`apiError` endpoints additionally include:

```json
{"ok": false, "success": false, "error": "…", "code": 400}
```

**Important:** `apiResponse()` maps `ok:false` to **HTTP 400 by default**. Some older paths use
raw `json_encode` and return HTTP 200 with `ok:false`.

### 3.3 Server error

```json
{"ok": false, "success": false, "error": "Internal server error.", "code": 500}
```

No stack trace, SQL text, or file path is returned.

### 3.4 Client rule

```dart
if (body['ok'] == true) { success } else { show(body['error'] ?? 'Request failed'); }
```

Never branch on HTTP 200 alone. Treat a missing `ok` as failure.

---

## 4. CSRF contract

Enforced by `requireAuth()` on `POST`, `PUT`, `PATCH`, and `DELETE` for every authenticated
endpoint. `login.php` and `signup.php` are unauthenticated but call `verifyCsrf()` directly after
their own POST guard, so they are covered too.

**One exception: `api/logout.php` performs no CSRF verification and has no method guard.** See
§2.3.

### 4.1 Request token sources

The request token is read from exactly one of these, in this order
(`api/helpers/csrf.php:156-158`):

| # | Source | Notes |
|---:|---|---|
| 1 | `X-CSRF-Token` request header | Read via `getallheaders()` and lower-cased, so the header is case-insensitive |
| 2 | `$_SERVER['HTTP_X_CSRF_TOKEN']` | The same header, exposed by SAPIs where `getallheaders()` is unavailable |
| 3 | `csrf_token` POST field | The fallback that keeps native form posts working |

Sources 1 and 2 are the same HTTP header seen through two APIs; only one is ever present. A
client should send the header and never rely on the POST field.

### 4.2 The `csrf_token` cookie is not a token source

`generateCsrfToken()` also sets a `csrf_token` cookie (`HttpOnly = false` so JavaScript can read
it) holding the same value as the session token. On verification that cookie is **only** an
optional consistency check — if it is present and differs from the session token the request is
rejected, and if it is absent the check is skipped entirely:

```php
if (isset($_COOKIE['csrf_token'])) { /* compare, reject on mismatch */ }
```

Consequences for a client:

- the cookie can never substitute for the header or POST field;
- omitting the cookie is not an error;
- reading the cookie is a convenient way to *obtain* the token, not a way to *send* it;
- a stale cookie after token rotation produces a 403 even when the header is correct, so on a
  403 the client should re-read the session token rather than reuse a cached cookie value.

### 4.3 Failure responses

All CSRF rejections are HTTP 403 with `{"ok":false,"error":"<message>"}`. The message identifies
which check failed (`api/helpers/csrf.php`):

| Condition | Exact `error` |
|---|---|
| No `$_SESSION['csrf_token']` | `CSRF token missing from session` |
| No header and no `csrf_token` POST field | `CSRF token missing from request` |
| Request token differs from the session token | `Invalid CSRF token` |
| Cookie present but differs from the session token | `CSRF cookie mismatch` |

These are literal strings. There is no combined "invalid or missing" message — the four above
are the only CSRF error strings the backend emits. Treat any 403 from a write as "fetch a fresh
token, retry once".

Every 403 is logged with the IP, endpoint and user (plus the submitted token length on a
mismatch), and recorded in the `rate_limits` table under `csrf_fail`.

### 4.4 Failure gate

50 recorded CSRF failures per IP in 10 minutes → HTTP 429:

```json
{"ok":false,"error":"Too many failed security checks. Try again later.","retry_after":42}
```

A `Retry-After` header is added when the remaining wait is known. Do not retry blindly.

### 4.5 Token lifetime

The session token rotates after 12 hours (`CSRF_TOKEN_LIFETIME = 43200`), on login, and whenever
`getCsrfToken()` finds an expired one.

---

## 5. Rate limiting contract

| Bucket | Limit | Window | Applies to |
|---|---:|---:|---|
| `api_user` | 120 | 60 s | every authenticated non-heartbeat request |
| `api_ip` | 240 | 60 s | every authenticated request, per IP |
| `api_heartbeat` | 30 | 60 s | `notifications/count.php` |
| `login` | 5 | 900 s | failed login attempts |
| `signup` | 3 | 3600 s | **successful** registrations only — failed, invalid, and duplicate attempts are not counted |
| `csrf_fail` | 50 | 600 s | CSRF failures |

Denied response:

```json
HTTP 429
Retry-After: <seconds>
{
  "ok": false,
  "error": "Too many requests. Rate limited.",
  "retry_after": 42,
  "limited_by": "user"
}
```

`limited_by` is `"user"` or `"ip"` (login/signup use their own names).

---

## 6. Idempotency contract

Header: `Idempotency-Key: <opaque string ≤ 200 bytes>`.

- Scope: user + route + key.
- Payload binding: all POST fields, sorted.
- TTL: 900 seconds.
- Same key + same payload → replay of the cached status/body.
- Same key + different payload → HTTP **409**:
  `{"ok":false,"error":"Idempotency key reuse with different payload."}`
- Same key while the first request is still running → HTTP **409**:
  `{"ok":false,"error":"A request with this idempotency key is already in progress."}`
- No header → feature bypass (normal execution).

Wrapped endpoints (11):

| Endpoint | Route key |
|---|---|
| `expenses/create.php` | `expenses/create` |
| `expenses/update.php` | `expenses/update` |
| `expenses/delete.php` | `expenses/delete` |
| `expenses/price_unpriced.php` | `expenses/price_unpriced` |
| `groups/create.php` | `groups/create` |
| `groups/join.php` | `groups/join` |
| `budgets/set.php` | `budgets/set` |
| `settlements/confirm.php` | `settlements/confirm` |
| `settlements/post_confirm.php` | `settlements/post_confirm` |
| `settlements/settle.php` | `settlements/settle` |
| `settlements/settle_all.php` | `settlements/settle_all` |

Not wrapped: list create/delete/add/remove/check, group delete/leave/remove_member,
notifications read, expense listing.

The Flutter client should generate a new key per user intent and reuse it only for retries of
that exact intent.

---

## 7. Pagination contract

`pagination` object shape:

```json
{"page": 1, "limit": 20, "total": 43, "pages": 3}
```

There is **no** `has_more`. `page < 1` becomes 1; `limit` is clamped to `1…max`.

| Endpoint | Default | Max | Notes |
|---|---:|---:|---|
| `expenses/list.php?date=` | 20 | 50 | day view |
| `expenses/list.php?month=` | 500 | 1000 | month view |
| `expenses/list.php?start=&end=` | 500 | 1000 | range view |
| `expenses/unpriced.php` | 500 | 1000 | |
| `groups/user_groups.php` | 500 | 1000 | dropdown/roster feed |
| `groups/details.php` | 500 | 1000 | expenses only; members never paginated |
| `lists/user_lists.php` | 500 | 1000 | |
| `lists/details.php` | — | — | returns **all** items; reports `pagination` with `page=1`, `limit=max(total,1)` |
| `settlements/history.php` | 500 | 1000 | |
| `settlements/details.php` | 500 | 1000 | |
| `notifications/list.php` | 30 | 50 | today's feed |
| `notifications/history.php` | 500 | 1000 | 7-day feed |

Do not "normalise" these values. Tests and UI assumptions depend on them.

---

## 8. Validation contract

| Value | Rule | Typical error |
|---|---|---|
| Money | numeric, rounded 2 dp, `0.01…99 999 999.00`; `0.001` is rejected | `Amount must be greater than zero.` / `Amount must be at most 99999999.00.` |
| Date | real `YYYY-MM-DD` (`checkdate`) | `Invalid date format.` / `Invalid calendar date.` |
| Month | `YYYY-MM` with month 01–12 | `Invalid month format.` |
| Note | ≤ 255 characters | `Note is too long (max 255 characters).` |
| List description | non-empty, ≤ 255 | `Description is required.` |
| List priority | `high` \| `moderate` \| `low` | `Priority must be high, moderate or low.` |
| Expense type | `personal` \| `group` | `Type must be personal or group.` |
| Group name | 1–100 characters | `Group name is required (max 100 chars).` |
| List name | 1–100 characters | `List name is required (max 100 chars).` |
| Join code | non-empty; uppercased/trimmed | `Please enter a join code.` |
| Username | 3–50 | `Username must be 3–50 characters.` |
| Password | ≥ 8 | `Password must be at least 8 characters.` |

Multiple validation failures may be joined into one `error` string with spaces.

---

## 9. Endpoint reference

### 9.1 Endpoint inventory and classification

The `api/` tree at snapshot `5ed8de3` contains **64 PHP files**. Of those,
**40 are HTTP endpoints**, one is a **CLI-only worker**, and one is a **helper that is not an
endpoint**.

| Class | Count | Files |
|---|---:|---|
| Module HTTP endpoints | 36 | Expenses 8, Groups 7, Settlements 8, Lists 7, Budgets 2, Notifications 4 |
| Authentication HTTP endpoints | 3 | `login.php`, `logout.php`, `signup.php` |
| System HTTP endpoint | 1 | `system/health.php` |
| **HTTP endpoints total** | **40** | |
| CLI-only worker (not HTTP) | 1 | `workers/process_outbox.php` |
| Non-endpoint helper inside a module dir | 1 | `settlements/settlement_helpers.php` |

Module HTTP endpoints by directory:

| Directory | Count | Endpoints |
|---|---:|---|
| `expenses/` | 8 | `categories`, `list`, `summary`, `create`, `update`, `delete`, `unpriced`, `price_unpriced` |
| `groups/` | 7 | `user_groups`, `create`, `join`, `details`, `remove_member`, `leave`, `delete` |
| `settlements/` | 8 | `calculate`, `confirm`, `settle`, `settle_all`, `history`, `details`, `post_calculate`, `post_confirm` |
| `lists/` | 7 | `user_lists`, `create`, `delete`, `details`, `add_item`, `remove_item`, `check_item` |
| `budgets/` | 2 | `get`, `set` |
| `notifications/` | 4 | `count`, `list`, `history`, `read` |

`expenses/` + `groups/` + `settlements/` + `lists/` + `budgets/` + `notifications/` = 36 module
HTTP endpoints. Adding the 3 auth endpoints and `system/health.php` gives **40 HTTP endpoints**.

Three things that are **not** HTTP endpoints:

- `api/workers/process_outbox.php` — CLI-only. Over HTTP it returns
  `403 {"ok":false,"error":"Forbidden: CLI execution only."}`. It is not part of the client
  contract; see §9.9.
- `api/settlements/settlement_helpers.php` — a shared library (`calculateSettlements()`), not a
  route. It returns no response and is never called directly.
- The 10 helpers, `middleware/auth.php`, the 10 dormant services, and `api/bootstrap.php` —
  infrastructure, documented in `BACKEND_AUDIT.md`.

> Earlier documents in this repository quote "38 API endpoints" (dated version-history sections
> of `README.md`) and "41 endpoint files" (endpoint + auth + worker). Only the counts on this
> page reflect the tree at the audit snapshot: **40 HTTP endpoints** plus 1 CLI-only worker.

### 9.2 Expenses

#### GET `/api/expenses/categories.php`

Auth: yes. Params: none.

```json
{"ok":true,"success":true,"code":200,
 "categories":[{"id":1,"name":"General"},{"id":2,"name":"Food/Groceries"}]}
```

#### GET `/api/expenses/list.php`

Auth: yes. One of:

| Mode | Params |
|---|---|
| Day | `date=YYYY-MM-DD` |
| Month | `month=YYYY-MM` |
| Range | `start=YYYY-MM-DD&end=YYYY-MM-DD` |

Plus `page`, `limit`.

Response:

```json
{
  "ok": true,
  "expenses": [
    {
      "id": 12,
      "amount": "24.50",
      "note": "Lunch",
      "expense_date": "2026-11-14",
      "type": "group",
      "category_id": 2,
      "group_id": 4,
      "user_id": 1,
      "paid_by": 3,
      "created_at": "2026-11-14 13:02:11",
      "category_name": "Food/Groceries",
      "added_by": "alice",
      "payer_username": "carol",
      "group_name": "Flat 3",
      "can_edit": true,
      "settled": false
    }
  ],
  "pagination": {"page":1,"limit":500,"total":1,"pages":1}
}
```

- `can_edit` is true for a personal owner or a group admin.
- `settled` is true when a settlement `period_end >= expense_date` for that group.
- Numeric column types are not normalized consistently; cast on the client.
- Missing/invalid mode → HTTP 400 `"Provide ?date=YYYY-MM-DD or ?month=YYYY-MM or ?start=YYYY-MM-DD&end=YYYY-MM-DD"`.

#### GET `/api/expenses/summary.php`

Auth: yes. `month=YYYY-MM` required (real month). Invalid → 400 `Invalid month format.`

```json
{
  "ok": true,
  "total_spent": 420.5,
  "personal_total": 120.5,
  "group_total": 300.0,
  "group_share": 100.0,
  "by_category": [{"name":"Food/Groceries","total":210.25}],
  "by_day": [{"day":"2026-11-01","total":30.0}],
  "budget": 500.0,
  "month": "2026-11"
}
```

`budget` is `null` when unset. `group_share` divides each group expense by that group's member
count. All totals include personal + member groups.

#### POST `/api/expenses/create.php`

Auth + CSRF + idempotency. `requirePostMethod()` → 405.

| Field | Required | Notes |
|---|---|---|
| `amount` | yes | numeric, > 0, ≤ 99 999 999.00 |
| `category_id` | yes | > 0 |
| `note` | no | ≤ 255 |
| `expense_date` | yes | real date |
| `type` | no | `personal` (default) or `group`; any other explicit value is rejected |
| `group_id` | for group | must be a group the caller belongs to |
| `paid_by` | for group | must be a member of that group |

Successful response:

```json
{"ok":true,"id":42}
```

Other errors (HTTP 400 unless noted):

```
Amount must be a numeric value.
Amount must be greater than zero.
Amount must be at most 99999999.00.
Type must be personal or group.
Please select a category.
Invalid date format.
Invalid calendar date.
Note is too long (max 255 characters).
Please select a group for a group expense.
You are not a member of this group.
Please select who paid for this expense.
The payer must be a member of this group.
This expense date has already been settled and cannot be modified.
Failed to save expense.
```

A group expense dated on or before the group's `MAX(period_end)` is rejected.

#### POST `/api/expenses/update.php`

Auth + CSRF + idempotency. `requirePostMethod()` → 405.

| Field | Required | Notes |
|---|---|---|
| `id` | yes | expense ID |
| `amount` | no | if present, > 0 and ≤ ceiling |
| `category_id` | no | if present, > 0 |
| `note` | no | if present, ≤ 255 |
| `type` | no | `personal` or `group` |
| `group_id` | no | `""` clears it |
| `paid_by` | no | `""` clears it |

Merge semantics: omitted fields keep their stored values. Personal expenses force
`paid_by = caller` and `group_id = null`.

Permission: personal owner, or admin of the expense's **current** group.

Errors include:

```
Invalid expense ID.
Expense not found.
Amount must be > 0.
Amount must be at most 99999999.00.
Type must be personal or group.
Select a category.
Note is too long (max 255 characters).
This expense date has already been settled and cannot be modified.
Cannot move expense into a group with a settled period covering this date.
You can only edit your own personal expenses.
Only the group admin can edit group expenses.
Failed to update expense.
```

Response: `{"ok":true}`.

**Known gap:** the target `group_id`/`paid_by` are not re-authorized. Do not rely on the server
to reject a cross-group move. The Flutter UI should only offer groups the user belongs to.

#### POST `/api/expenses/delete.php`

Auth + CSRF + idempotency. `id` required. Personal owner / group admin only. Settlement-locked
rows are rejected.

Response: `{"ok":true}`. Errors: `Invalid expense ID.`, `Expense not found.`, permission and
lock messages, `Failed to delete expense.`

#### GET `/api/expenses/unpriced.php`

Auth: yes. `page`, `limit` (500/1000). Selects checked, unpriced items (`price IS NULL`) in the
caller's personal lists and member groups.

```json
{"ok":true,"items":[
  {"id":7,"description":"Milk","category_id":2,"checked_at":"2026-11-14 10:00:00",
   "list_id":3,"category_name":"Food/Groceries","list_name":"Weekly",
   "group_id":null,"group_name":null}
],"pagination":{"page":1,"limit":500,"total":1,"pages":1}}
```

#### POST `/api/expenses/price_unpriced.php`

Auth + CSRF + idempotency. `requirePostMethod()`.

| Field | Required | Notes |
|---|---|---|
| `item_id` | yes | |
| `price` | yes | normalized money |
| `paid_by` | group items | must be a group member |

Behavior: `FOR UPDATE`; creates a personal or group expense; sets `is_post_settlement = 1` when
the item's checked date falls inside a settled period; links with
`AND expense_created = 0`.

Success:

```json
{"ok":true,"expense_id":55,"is_post_settlement":false}
```

Errors: `Invalid item.`, `Item not found.`, `Invalid price.`, `Payer must be a group member.`,
`This expense date has already been settled and cannot be modified.`,
`Item was concurrently priced.`, `Failed to create expense.`

### 9.3 Groups

#### GET `/api/groups/user_groups.php`

Auth. `page`, `limit` (500/1000).

```json
{"ok":true,"groups":[{"id":4,"name":"Flat 3","role":"admin"}],
 "pagination":{"page":1,"limit":500,"total":1,"pages":1}}
```

Ordered by group name.

#### POST `/api/groups/create.php`

Auth + CSRF + idempotency. `requirePostMethod()`. `name` required, 1–100.

Success:

```json
{"ok":true,"group":{"id":9,"name":"Trip","join_code":"AB12CD","role":"admin"}}
```

Errors: `Group name is required (max 100 chars).`, `You can create at most 5 groups.`,
`Failed to generate join code. Try again.`, `Failed to create group.`

The creator is inserted as `admin`.

#### POST `/api/groups/join.php`

Auth + CSRF + idempotency. `join_code` required; trailing/leading spaces trimmed, uppercased.

Success:

```json
{"ok":true,"group":{"id":4,"name":"Flat 3","role":"member"}}
```

Errors: `Please enter a join code.`, `No group found with that code.`,
`You are already a member of this group.`, `This group is full (max 10 members).`

**Known gap:** the capacity check is read-then-insert without a transaction; concurrent joins
can exceed the limit.

#### GET `/api/groups/details.php`

Auth. `group_id` required; caller must be a member. `page`, `limit` (500/1000) for expenses.

```json
{
  "ok": true,
  "group": {"id":4,"name":"Flat 3","join_code":"AB12CD","created_by":1,
            "max_members":10,"created_at":"2026-10-01 09:00:00"},
  "my_role": "admin",
  "members": [{"user_id":1,"username":"alice","role":"admin","joined_at":"…"}],
  "expenses": [{"id":12,"amount":"24.50","note":"Lunch","expense_date":"2026-11-14",
                "category_id":2,"paid_by":3,"category_name":"Food/Groceries",
                "added_by":"alice","payer_username":"carol","created_at":"…"}],
  "pagination": {"page":1,"limit":500,"total":1,"pages":1}
}
```

Errors: `Invalid group.`, `You are not a member of this group.`

#### POST `/api/groups/remove_member.php`

Auth + CSRF. Admin only. `group_id`, `user_id`.

```json
{"ok":true,"removed_user_id":3,"removed_username":"carol"}
```

Errors: `Invalid request.`, `Only the group admin can remove members.`,
`User is not a member of this group.`, `Admin cannot be removed from the group.`,
`Failed to remove member.`

#### POST `/api/groups/leave.php`

Auth + CSRF. `group_id`. Admin cannot leave.

```json
{"ok":true}
```

Errors: `Invalid group.`, `You are not a member of this group.`,
`Admin cannot leave the group. Delete the group instead.`

#### POST `/api/groups/delete.php`

Auth + CSRF. `group_id`. Creator only. Cascades.

```json
{"ok":true}
```

Errors: `Invalid group.`, `Group not found.`,
`Only the group creator can delete the group.`, `Failed to delete group.`

### 9.4 Settlements

#### GET `/api/settlements/calculate.php`

Auth. `group_id`; caller must be a member.

```json
{
  "ok": true,
  "group_id": 4,
  "member_count": 3,
  "total_spend": 300.0,
  "per_person": 100.0,
  "members": [
    {"user_id":1,"username":"alice","role":"admin","contribution":150.0,"balance":50.0},
    {"user_id":3,"username":"carol","role":"member","contribution":150.0,"balance":50.0},
    {"user_id":2,"username":"bob","role":"member","contribution":0.0,"balance":-100.0}
  ],
  "settlements": [
    {"from_id":2,"from_username":"bob","to_id":1,"to_username":"alice","amount":50.0}
  ],
  "period_start": "2026-10-02",
  "period_end": "2026-11-15",
  "last_settlement": "2026-10-01",
  "confirmed_users": [1],
  "confirmed_count": 1,
  "user_confirmed": true,
  "post_settlement_count": 0
}
```

Rules:

- Contributions use `paid_by`, `is_post_settlement = 0`, and `expense_date > last period_end`.
- `period_start` is the earliest included expense date, or `null` when there are none.
- `period_end` is `max(latest expense date, today)`.
- `confirmed_users` counts only rows whose `period_end` matches the current `period_end`.
- `settlements` are greedy debtor→creditor transfers.

Errors: `Invalid group.`, `You are not a member of this group.`, `No members in group.`

#### POST `/api/settlements/confirm.php`

Auth + CSRF + idempotency. `requirePostMethod()`. `group_id`.

Not all confirmed:

```json
{"ok":true,"all_settled":false,"remaining":2,
 "message":"Your settlement has been confirmed."}
```

All confirmed:

```json
{"ok":true,"all_settled":true,
 "message":"All members have confirmed. Settlement is complete!"}
```

Finalization writes one settlement per computed transfer (or a `0.00` marker), deletes all
`settlement_confirmations` for the group, and commits.

Errors: `Invalid group.`, `You are not a member of this group.`, `No members in group.`,
`No unsettled expenses to confirm.`, `Failed to finalize settlement.`

#### POST `/api/settlements/settle.php`

Auth + CSRF + idempotency. `requirePostMethod()`. **Admin only.**

| Field | Required |
|---|---|
| `group_id` | yes |
| `payer_id` | yes |
| `payee_id` | yes |
| `period_start` | yes |
| `period_end` | yes |
| `amount` | yes |

Success:

```json
{"ok":true,"message":"Settlement recorded.","id":17}
```

Validation and gate errors:

```
Invalid settlement data.
Invalid date format.
Invalid period: start date is after end date.
Invalid period: end date cannot be in the future.
Not a member of this group.
Only the group admin can record settlements.
Payer and payee must be different members of this group.
Payer and payee must be members of this group.
No members in group.
All group members must confirm the settlement before it can be recorded.
Settlement does not match the computed unsettled transfers.
This settlement has already been recorded.
Failed to record settlement.
```

#### POST `/api/settlements/settle_all.php`

Auth + CSRF + idempotency. **Admin only.** `group_id`.

```json
{"ok":true,"message":"All expenses have been settled.","count":2}
```

Clears `settlement_confirmations` after finalizing. Errors: `Invalid group.`,
`Not a member of this group.`, `Only the group admin can settle all expenses.`,
`No unsettled expenses to settle.`, `Failed to record settlements.`

#### GET `/api/settlements/history.php`

Auth. `group_id`; member. `page`, `limit` (500/1000).

```json
{"ok":true,"settlements":[
  {"id":17,"payer_id":2,"payee_id":1,"payer_name":"bob","payee_name":"alice",
   "amount":50.0,"period_start":"2026-10-02","period_end":"2026-11-15",
   "created_at":"2026-11-15 18:00:00"}
],"pagination":{"page":1,"limit":500,"total":1,"pages":1}}
```

Errors: `Invalid group.`, `Not a member of this group.`

#### GET `/api/settlements/details.php`

Auth. `group_id`, `start`, `end`; member. `page`, `limit` (500/1000).

```json
{"ok":true,
 "expenses":[{"id":12,"amount":24.5,"note":"Lunch","expense_date":"2026-11-14",
              "category_name":"Food/Groceries","added_by":"alice",
              "payer_username":"carol","user_id":1,"paid_by":3}],
 "total":24.5,
 "pagination":{"page":1,"limit":500,"total":1,"pages":1}}
```

Dates are validated (`validateDate`, reversed range rejected). `total` covers the whole range,
not the page. This report does **not** filter `is_post_settlement`.

Errors: `Invalid group.`, `Invalid date format.`, `Not a member of this group.`

#### GET `/api/settlements/post_calculate.php`

Auth. `group_id`; member. Computes late-expense (`is_post_settlement = 1`) balances since the
last settlement.

```json
{
  "ok": true,
  "has_expenses": true,
  "group_id": 4,
  "member_count": 3,
  "total_spend": 60.0,
  "per_person": 20.0,
  "members": [{"user_id":1,"username":"alice","role":"admin",
               "contribution":60.0,"balance":40.0}],
  "settlements": [{"from_id":2,"from_username":"bob","to_id":1,"to_username":"alice","amount":20.0}],
  "expenses": [{"id":31,"amount":60.0,"note":"Late","expense_date":"2026-11-10",
                "paid_by":1,"user_id":1,"category_name":"Others"}],
  "confirmed_users": [1],
  "confirmed_count": 1,
  "user_confirmed": true
}
```

When there are no late expenses:

```json
{"ok":true,"has_expenses":false,"total_spend":0,"expenses":[],"members":[],"settlements":[]}
```

Errors: `Invalid group.`, `You are not a member of this group.`, `No members in group.`

#### POST `/api/settlements/post_confirm.php`

Auth + CSRF + idempotency. `requirePostMethod()`. `group_id`.

Not all confirmed:

```json
{"ok":true,"all_settled":false,"remaining":1,
 "message":"Your confirmation has been recorded."}
```

All confirmed:

```json
{"ok":true,"all_settled":true,
 "message":"All members have confirmed. Late expenses settlement is complete! Past records updated."}
```

Finalization recomputes balances, deletes/updates affected past settlement rows, clears
`is_post_settlement`, and deletes post-settlement confirmations.

Errors: `Invalid group.`, `You are not a member of this group.`, `No members in group.`,
`No post-settlement expenses to confirm.`, `Failed to finalize settlement.`

**Known gap:** the insert/count/finalize block is not under `SELECT … FOR UPDATE`.

### 9.5 Lists

#### GET `/api/lists/user_lists.php`

Auth. `page`, `limit` (500/1000).

```json
{"ok":true,"lists":[
  {"id":3,"name":"Weekly","user_id":1,"group_id":null,"created_at":"…",
   "created_by":"alice","group_name":null,"item_count":5,"checked_count":2}
],"pagination":{"page":1,"limit":500,"total":1,"pages":1}}
```

#### POST `/api/lists/create.php`

Auth + CSRF. `requirePostMethod()`. `name` required (1–100), optional `group_id`.

```json
{"ok":true,"id":3}
```

Errors: `List name is required (max 100 chars).`, `You are not a member of this group.`,
`Failed to create list.`

#### POST `/api/lists/delete.php`

Auth + CSRF. `list_id`. Personal owner; for group lists, group admin or list creator.

```json
{"ok":true}
```

Errors: `Invalid list.`, `List not found.`, `Access denied.`,
`Only the group admin or list creator can delete this list.`,
`You can only delete your own personal lists.`, `Failed to delete list.`

#### GET `/api/lists/details.php`

Auth. `list_id`; owner or group member.

```json
{
  "ok": true,
  "list": {"id":3,"name":"Weekly","user_id":1,"group_id":null,
           "created_at":"…","created_by":"alice","group_name":null},
  "items": [
    {"id":7,"description":"Milk","category_id":2,"priority":"high",
     "is_checked":1,"price":"3.50","checked_at":"2026-11-14 10:00:00",
     "expense_created":1,"added_by":1,"created_at":"…",
     "category_name":"Food/Groceries","added_by_name":"alice"}
  ],
  "pagination": {"page":1,"limit":1,"total":1,"pages":1}
}
```

All items are returned, ordered `high → moderate → low`, then creation time ascending.

Errors: `Invalid list.`, `List not found.`, `Access denied.`

#### POST `/api/lists/add_item.php`

Auth + CSRF. `requirePostMethod()`.

| Field | Required | Notes |
|---|---|---|
| `list_id` | yes | |
| `description` | yes | ≤ 255 |
| `category_id` | no | |
| `priority` | no | default `low`; must be valid |
| `price` | no | if set, 0.01…99 999 999.00 |

```json
{"ok":true,"id":19}
```

Errors: `Priority must be high, moderate or low.`,
`Price must be between 0.01 and 99999999.00.`, `Invalid list.`,
`Description is required.`, `List not found.`, `Access denied.`, `Failed to add item.`

For group lists, other members are notified (`list_item_add`).

#### POST `/api/lists/remove_item.php`

Auth + CSRF. `item_id`. List access required.

```json
{"ok":true}
```

Errors: `Invalid item.`, `Item not found.`, `Access denied.`, `Failed to remove item.`

#### POST `/api/lists/check_item.php`

Auth + CSRF. `requirePostMethod()`.

| Field | Required | Notes |
|---|---|---|
| `item_id` | yes | |
| `paid_by` | group confirm | must be a group member |
| `price` | no | confirm-mode override |
| `confirm` | no | `1` = confirm group purchase |

Branches and responses:

**A. Group check (no confirm):** marks the item checked immediately.

```json
{
  "ok": true,
  "is_checked": 1,
  "needs_confirm": true,
  "item": {"id":7,"description":"Milk","price":null,"category_name":null,"date":"2026-11-15"},
  "members": [{"user_id":1,"username":"alice"}]
}
```

**B. Group confirm (`confirm=1`):** requires `paid_by`; creates the expense in a transaction.

```json
{"ok":true,"is_checked":1,"expense_created":true,"is_post_settlement":false}
```

**C. Personal check:** creates an expense immediately when the item has a price.

```json
{"ok":true,"is_checked":1,"expense_created":true,"is_post_settlement":false}
```

**D. Uncheck:** allowed within 600 seconds; deletes the linked expense by `expense_id`.

```json
{"ok":true,"is_checked":0,"expense_created":false,"is_post_settlement":false}
```

Errors: `Invalid item.`, `Item not found.`, `Access denied.`,
`Please select who paid for this item.`, `Payer must be a group member.`,
`Failed to confirm purchase.`, `Cannot uncheck — more than 10 minutes have passed.`,
`Failed to uncheck item.`, `Failed to update item.`

**Known gaps:** the group check marks the item before the popup, and confirm-mode `price` has no
upper bound. See `TECHNICAL_DEBT.md`.

### 9.6 Budgets

#### GET `/api/budgets/get.php`

Auth. `month=YYYY-MM` required.

```json
{"ok":true,"budget":{"id":2,"amount_limit":500.0,"month":"2026-11"}}
```

or

```json
{"ok":true,"budget":null}
```

Errors: `Invalid month format.`, `Provide ?month=YYYY-MM`

Not called by the current frontend; `summary.php` embeds the same value.

#### POST `/api/budgets/set.php`

Auth + CSRF + idempotency. `requirePostMethod()`. `month`, `amount_limit`.

```json
{"ok":true,"message":"Budget saved.","previous":450.0}
```

`previous` is `null` on first create.

Errors: `Invalid month format.`, `Budget must be greater than zero.`,
`Budget must be at most 99999999.00.`, `Failed to save budget.`

### 9.7 Notifications

#### GET `/api/notifications/count.php`

Auth with `requireAuth(false)` → heartbeat bucket 30/min. Today only.

```json
{"ok":true,"count":3,
 "latest":{"id":88,"message":"alice added a new expense to Flat 3.",
           "type":"group_expense_add","created_at":"2026-11-15 12:00:00"}}
```

`latest` is `null` when there is no unread notification.

#### GET `/api/notifications/list.php`

Auth. `unread_only=1` optional. `page`, `limit` (30/50). Today only.

```json
{"ok":true,
 "notifications":[{"id":88,"message":"…","type":"group_expense_add",
                   "reference_id":42,"is_read":0,"created_at":"…","event_id":88}],
 "unread_count":3,
 "pagination":{"page":1,"limit":30,"total":3,"pages":1}}
```

#### GET `/api/notifications/history.php`

Auth. `page`, `limit` (500/1000). Last 7 days, including read.

```json
{"ok":true,"notifications":[{…,"event_id":88}],
 "pagination":{"page":1,"limit":500,"total":10,"pages":1}}
```

#### POST `/api/notifications/read.php`

Auth + CSRF. `requirePostMethod()`.

- `all=1`: mark all read.
- `event_id` (preferred) or `id`: mark one.

```json
{"ok":true,"marked":1}
```

A foreign/nonexistent ID returns `marked: 0` with HTTP 200 (no enumeration oracle).
Errors: `Invalid notification ID.`

### 9.8 System

#### GET `/api/system/health.php`

Auth required over HTTP. Returns an inline snapshot of DB pressure, Redis status, and outbox
counts. Shape is diagnostic and may vary; treat it as observability, not product data.

Failure: HTTP 500 `{"ok":false,"success":false,"error":"Internal server error.","code":500}`.

### 9.9 CLI worker

#### `api/workers/process_outbox.php`

Not callable over HTTP: returns 403 `{"ok":false,"error":"Forbidden: CLI execution only."}`.
The outbox has no producers; the worker is dormant.

---

## 10. Shared object shapes

### 10.1 Expense

| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `amount` | number/string | cast on client |
| `note` | string/null | raw text |
| `expense_date` | `YYYY-MM-DD` | |
| `type` | `personal`/`group` | |
| `category_id` | int | |
| `group_id` | int/null | |
| `user_id` | int | recorder |
| `paid_by` | int/null | actual payer |
| `category_name` | string | joined |
| `added_by` | string | recorder username |
| `payer_username` | string/null | joined |
| `group_name` | string/null | joined |
| `can_edit` | bool | list only |
| `settled` | bool | list only |

### 10.2 Group

`id`, `name`, `join_code`, `created_by`, `max_members`, `created_at`, plus `role` or `my_role`.

### 10.3 Member

`user_id`, `username`, `role` (`admin`/`member`), `joined_at`; in settlement responses also
`contribution` and `balance`.

### 10.4 Suggested transfer

`from_id`, `from_username`, `to_id`, `to_username`, `amount`.

Positive balance = creditor; negative = debtor.

### 10.5 List item

`id`, `description`, `category_id`, `priority`, `is_checked`, `price`, `checked_at`,
`expense_created`, `added_by`, `created_at`, `category_name`, `added_by_name`.

`expense_id` is stored but not returned by `lists/details.php`; do not depend on it in the UI.

### 10.6 Notification

`id`, `message`, `type`, `reference_id`, `is_read`, `created_at`, `event_id`.
`event_id == id`. `reference_id` is polymorphic and must not be used as a navigation key.

### 10.7 Pagination

`page`, `limit`, `total`, `pages`.

---

## 11. Data flows (frontend → JS → PHP → MySQL → response → UI)

### 11.1 Create a personal expense

```
Expense modal (pages/expenses.php)
  → app.js submitExpense()
  → POST ../api/expenses/create.php
      headers: X-CSRF-Token, Idempotency-Key
      body: amount, category_id, note, expense_date, type=personal
  → requireAuth() (session, CSRF, rate limit)
  → idempotencyGuard('expenses/create')
  → validate amount/category/date/note
  → INSERT expenses (user_id, paid_by=user, group_id=NULL, …)
  → log INFO "Expense created"
  → idempotencyRespond({ok:true,id})
  → UI closes modal, refreshes calendar/summary
```

Personal expenses do not notify anyone.

### 11.2 Create a group expense

```
Expense modal with group + payer
  → POST expenses/create.php (type=group, group_id, paid_by)
  → membership check for caller and payer
  → settlement lock: expense_date <= MAX(period_end) → 400
  → INSERT expenses
  → INSERT notifications SELECT group_members != caller (type=group_expense_add,
    reference_id = new expense id)
  → {ok:true,id}
```

### 11.3 Edit an expense

```
Edit modal prefilled from the list row
  → POST expenses/update.php with id + changed fields only
  → load existing row
  → settlement lock on existing group/date and target group/date
  → permission: personal owner / current-group admin
  → merge omitted fields
  → normalize personal (paid_by=caller, group_id=null)
  → UPDATE expenses
  → notify current group (reference_id = group id)
  → {ok:true}
```

### 11.4 List check → expense (personal)

```
lists.js check button
  → POST lists/check_item.php {item_id}
  → load item + list, permission
  → newState = 1
  → transaction:
       if price > 0 and not expense_created:
           INSERT expenses (personal, paid_by=caller, date=today, checked_by=caller)
       UPDATE list_items SET is_checked=1, checked_at, expense_created=1, expense_id
     commit
  → {ok:true,is_checked:1,expense_created:true}
  → UI marks row checked and refreshes lists summary
```

### 11.5 List check → expense (group, two-step)

```
Step 1: POST check_item.php {item_id}
  → UPDATE list_items SET is_checked=1, checked_at
  → SELECT group members
  → {ok:true,needs_confirm:true,item,members}
  → UI opens payer/price popup

Step 2: POST check_item.php {item_id, confirm:1, paid_by, price?}
  → validate paid_by membership
  → transaction:
       update price if provided
       INSERT expenses (group, paid_by, is_post_settlement if date locked)
       UPDATE list_items SET expense_created=1, expense_id (preserve original checked_at)
     commit
  → notify group (reference_id = list id)
  → {ok:true,expense_created:true,is_post_settlement}
```

### 11.6 Uncheck within 10 minutes

```
POST check_item.php {item_id}
  → load item (is_checked=1)
  → if now - checked_at > 600 → 400
  → transaction:
       DELETE expenses WHERE id = item.expense_id   (exact FK)
       UPDATE list_items SET is_checked=0, checked_at=NULL, expense_created=0, expense_id=NULL
     commit
  → notify group
  → {ok:true,is_checked:0}
```

### 11.7 Settlement calculate → confirm

```
Select group
  → GET settlements/calculate.php?group_id
      → contributions GROUP BY paid_by, is_post_settlement=0, date > last_end
      → per_person = total / member_count
      → balances → calculateSettlements() greedy
      → confirmation state for current period_end
  → GET settlements/history.php?group_id
  → UI renders members, transfers, confirm button

Confirm
  → POST settlements/confirm.php {group_id}
      → FOR UPDATE on confirmations
      → insert/update caller row for (group_id, user_id)
      → count confirmed vs active members
      → if all:
           insert computed settlements (or 0.00 marker)
           delete settlement_confirmations for group
           commit
           notify
           {ok:true,all_settled:true}
        else:
           commit confirmation
           notify others
           {ok:true,all_settled:false,remaining:N}
```

### 11.8 Late expense → post-settlement

```
Late group expense is created with is_post_settlement=1
  → GET settlements/post_calculate.php?group_id
      → aggregate only is_post_settlement=1
      → compute per-person balances
  → POST settlements/post_confirm.php {group_id}
      → insert post_settlement_confirmations row
      → when all confirmed:
           recompute and update past settlements
           UPDATE expenses SET is_post_settlement=0
           DELETE post_settlement_confirmations
           commit
           notify
           {ok:true,all_settled:true}
```

### 11.9 Notification poll

```
notifications.js every ~10 s
  → GET notifications/count.php (heartbeat bucket)
  → {count, latest}
  → update bell badge; if latest.id changed, show popup
  → GET notifications/list.php?limit=30 when dropdown opens
  → POST notifications/read.php {event_id} or {all:1}
  → {ok:true,marked}
```

### 11.10 Summary/charts

```
Dashboard month change
  → GET expenses/summary.php?month=YYYY-MM
  → GET expenses/list.php?month=YYYY-MM
  → Chart.js renders by_category and by_day
  → budget gauge = total_spent / budget
  → jsPDF exports locally
```

---

## 12. Error catalogue (exact strings)

Auth/limits:

```
Authentication required
Session expired
CSRF token missing from session
CSRF token missing from request
Invalid CSRF token
CSRF cookie mismatch
Too many failed security checks. Try again later.
Too many requests. Rate limited.
Rate limited
```

Expenses:

```
Invalid expense ID.
Expense not found.
Amount must be a numeric value.
Amount must be greater than zero.
Amount must be > 0.
Amount must be at most 99999999.00.
Type must be personal or group.
Please select a category.
Select a category.
Invalid date format.
Invalid calendar date.
Note is too long (max 255 characters).
Please select a group for a group expense.
You are not a member of this group.
Please select who paid for this expense.
The payer must be a member of this group.
This expense date has already been settled and cannot be modified.
Cannot move expense into a group with a settled period covering this date.
You can only edit your own personal expenses.
Only the group admin can edit group expenses.
Failed to save expense.
Failed to update expense.
Failed to delete expense.
Invalid item.
Item not found.
Invalid price.
Payer must be a group member.
Item was concurrently priced.
Failed to create expense.
```

Groups:

```
Invalid group.
Group not found.
Group name is required (max 100 chars).
You can create at most 5 groups.
Failed to generate join code. Try again.
Failed to create group.
Please enter a join code.
No group found with that code.
You are already a member of this group.
This group is full (max N members).
You are not a member of this group.
Only the group admin can remove members.
User is not a member of this group.
Admin cannot be removed from the group.
Failed to remove member.
Admin cannot leave the group. Delete the group instead.
Only the group creator can delete the group.
Failed to delete group.
Invalid request.
```

Settlements:

```
No members in group.
No unsettled expenses to confirm.
Failed to finalize settlement.
Invalid settlement data.
Invalid period: start date is after end date.
Invalid period: end date cannot be in the future.
Not a member of this group.
Only the group admin can record settlements.
Payer and payee must be different members of this group.
Payer and payee must be members of this group.
All group members must confirm the settlement before it can be recorded.
Settlement does not match the computed unsettled transfers.
This settlement has already been recorded.
Failed to record settlement.
Only the group admin can settle all expenses.
No unsettled expenses to settle.
Failed to record settlements.
No post-settlement expenses to confirm.
```

Lists:

```
Invalid list.
List not found.
Access denied.
List name is required (max 100 chars).
Failed to create list.
Only the group admin or list creator can delete this list.
You can only delete your own personal lists.
Failed to delete list.
Priority must be high, moderate or low.
Price must be between 0.01 and 99999999.00.
Description is required.
Failed to add item.
Failed to remove item.
Invalid item.
Please select who paid for this item.
Payer must be a group member.
Failed to confirm purchase.
Cannot uncheck — more than 10 minutes have passed.
Failed to uncheck item.
Failed to update item.
```

Budgets/notifications/system:

```
Invalid month format.
Provide ?month=YYYY-MM
Budget must be greater than zero.
Budget must be at most 99999999.00.
Failed to save budget.
Invalid notification ID.
Internal server error.
```

---

## 13. Flutter implementation checklist

1. Persist the session cookie across app restarts; on 401, clear it and route to login.
2. Fetch and cache the CSRF token; send it as `X-CSRF-Token` (not the cookie, which is only a
   consistency check); refresh after login and after a 403. `logout.php` needs no token.
3. Wrap all writes with a per-intent `Idempotency-Key` for the 11 wrapped endpoints.
4. Parse `ok` first; show `error` verbatim where appropriate.
5. Cast numeric fields defensively (`num.tryParse(value.toString())`).
6. Implement the documented pagination defaults/ceilings; do not request 10 000 rows.
7. Treat `settled`/`can_edit` as server authority for UI affordances.
8. Reproduce the two-step group list check, including the cancel path’s consequences.
9. Reproduce the 10-minute uncheck rule client-side and handle the server rejection.
10. Do not call `settle.php`/`settle_all.php` unless building an explicit admin tool.
11. Use `settlements/calculate.php` for display and `confirm.php` for closure.
12. Keep guest mode fully local; never call the API from it.
13. Reimplement charts/PDF locally; there is no export endpoint.
14. Handle 429 with a visible countdown from `Retry-After`.
15. Do not assume 200 for business errors.
16. Do not treat `reference_id` as a route parameter.
17. Test against `5ed8de3`/`66dedc1` behaviour, not against older README claims.

---

## 14. Contract change policy

- Any backend change that alters a parameter, response field, status code, error string, or
  permission rule requires an update to this document and a new baseline capture.
- Section 30 (`response.php` rollout) will change some 200→400/405 behaviours. Until it is
  explicitly scheduled, this document describes the mixed current state.
- The Flutter client may be lenient (accept extra keys), but must not depend on undocumented
  fields.
