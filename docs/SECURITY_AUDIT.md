# Security Audit

This document records the **current verified security posture** of the PHP application and the
controls the Flutter client must preserve. It is not a new penetration test.

Primary source: `PENTEST/FINAL_OUTCOME.md` — a re-run of 355 probes on 2026-11-01 at commit
`105f4c0`, reporting every in-scope finding closed and no new attack surface. Key claims were
re-checked against the source at `5ed8de3` for this audit. Where source and older reports
differ, source wins.

---

## 1. Verdict

| Area | Current state |
|---|---|
| Authentication | Required on every module endpoint; no anonymous API path |
| Session | Hardened cookie, 24-hour absolute lifetime, ID rotation on login |
| CSRF | Enforced on writes via `requireAuth()`, and directly on login/signup; NOT on `logout.php`; IP failure gate |
| SQL injection | No dynamic SQL with user values; prepared statements throughout |
| Authorization | Row-level scoping verified; two documented residual gaps |
| Settlement integrity | C1/C2/C3 closed; admin-only writers; lock enforced |
| Rate limiting | Per-user, per-IP, heartbeat, login, signup, CSRF buckets |
| Headers / CSP | Strong CSP without `unsafe-inline`; all responses covered |
| Webroot lockdown | Internal directories and dumps denied |
| XSS | Raw text stored, escaped at render; CSP blocks inline script |
| Secrets | No credentials or keys in the working tree or git history |
| Idempotency | Replay safe; conflicting payload returns 409 |
| Error handling | No stack traces to clients; logged server-side |

No finding from the original test remains open in remediation scope.

---

## 2. Findings closed at `105f4c0`

| ID | Finding | Verified closure |
|---|---|---|
| C1 | Settlement recorded without confirmations | 400 `"All group members must confirm"` |
| C2 | Calendar-impossible / reversed period | 400 `"Invalid date format"`; no `0000-00-00` row |
| C3 | Outsider as payer/payee | Gate inside `recordSettlement()`, member-party count = 2 |
| H1 | Root DB user / LAN listener | `expmg_app` least privilege, bind `127.0.0.1` |
| H2 | Heartbeat never rate-limited | Dedicated 30/min bucket; first 429 at burst #31 |
| H3 | Silent amount truncation | 400 for oversized and exponent amounts |
| M1 | Direct page path leak | Fragment guards return 404 |
| M2 | `splash.php` missing headers | Security headers on every response |
| M3 | Version fingerprinting | No `X-Powered-By`; generic `Server` |
| M4 | Date/month validation lottery | 400 for impossible dates and months |
| M5 | 200 instead of 405 | 405 + `Allow` on method mismatch |
| M6 | Silent enum/price coercion | 400 for invalid priority and negative price |
| G-1 | Post-settlement path unreachable | Late expense flow works and is visible to post-calculate |
| G-2 | Silent budget overwrite | Response carries previous value + INFO log |
| L-1/G-4 | Blind foreign notification mark | Uniform `marked` count, no oracle |
| L-2 | Reversed range accepted | 400 `"Invalid date format"` |
| L-3 | No CSRF on login/signup | 403 for token-less pre-auth POSTs |
| L-4 | CSP `unsafe-inline` | `script-src 'self'` + CDN with SRI |
| L-5 | Replace-not-patch update | Field-merge PATCH semantics |

---

## 3. Controls in detail

### 3.1 Session and cookies

`config/session.php::configureSecureSessionCookie()` sets:

- `HttpOnly` — JavaScript cannot read the session cookie;
- `SameSite=Lax` — cross-site POSTs do not carry it;
- `Secure` only when HTTPS — local XAMPP HTTP remains usable;
- strict mode — uninitialised session IDs are rejected;
- cookies-only mode.

`session_regenerate_id(true)` runs on successful login. The 24-hour lifetime is absolute from
`login_time`, not idle-based. An expired session is destroyed and returns
`401 {"ok":false,"error":"Session expired"}`.

### 3.2 CSRF

`api/helpers/csrf.php::verifyCsrf()` is the single implementation; `verifyCsrfToken()` in
`middleware/auth.php` only delegates to it.

**Coverage.** `requireAuth()` calls it for `POST`, `PUT`, `PATCH`, and `DELETE` on every
authenticated endpoint. `login.php` and `signup.php` are unauthenticated but call `verifyCsrf()`
directly after their POST guard (L-3). **`api/logout.php` is the documented exception** — it
verifies nothing and has no method guard (see §4).

**Request token.** Read from `X-CSRF-Token` (via `getallheaders()`, case-insensitive), falling
back to `$_SERVER['HTTP_X_CSRF_TOKEN']`, then to the `csrf_token` POST field.

**Cookie.** `generateCsrfToken()` also sets a JS-readable `csrf_token` cookie holding the same
value as the session token. It is **not** an independent token source: it is compared to the
session token only when present, and skipped when absent. Dropping the cookie does not weaken or
bypass the check, and the cookie alone cannot authenticate a request.

**Failures.** All are 403 with a specific message — `CSRF token missing from session`,
`CSRF token missing from request`, `Invalid CSRF token`, or `CSRF cookie mismatch`. Each failure
is logged and recorded against the IP in `rate_limits` (`csrf_fail`). 50 failures in 10 minutes
produce HTTP 429 `Too many failed security checks. Try again later.` The IP keying matters: an
attacker who drops cookies still accumulates failures because the gate is stored in the database,
not the session.

**Rotation.** Every 12 hours, on login, and after expiry.

A Flutter client using the same cookie session must send `X-CSRF-Token` on every write except
logout, and must obtain the token from the page/session bootstrap rather than from the cookie. A
future token-based API would require a different CSRF story; none exists today.

### 3.3 Authorization

- Every module endpoint calls `requireAuth()`.
- Personal rows are scoped by `user_id`.
- Group rows require a `group_members` row.
- Settlement writers require `role = 'admin'`.
- Group expense edit/delete requires admin.
- List edit/delete requires owner, group admin, or list creator depending on list type.
- Notification read/mark is scoped by `user_id`.
- Settlement history/details require membership.

Verified residual gaps (preserved, not fixed):

| Gap | Effect | Location |
|---|---|---|
| Target-group authorization on expense update | A member of the current group can move an expense to another group and assign any `paid_by` | `api/expenses/update.php` |
| `join.php` capacity race | Concurrent joins can exceed `max_members` | `api/groups/join.php` |
| `post_confirm.php` race | Confirmation insert/count/finalize is not under `FOR UPDATE` | `api/settlements/post_confirm.php` |
| `check_item.php` price ceiling | Confirm-mode price has no upper bound | `api/lists/check_item.php` |
| Notification `reference_id` | Polymorphic and inconsistent; not an authorization boundary | producers |
| Inline `onclick` handlers | Blocked by CSP, so close buttons on lists fail | `pages/lists.php` |

These are documented in [`TECHNICAL_DEBT.md`](TECHNICAL_DEBT.md). They are not introduced by the
Flutter migration and are not silently exploitable without an authenticated account.

### 3.4 SQL injection

Every query with a variable uses `prepare()` + `bind_param()`. Dynamic SQL is limited to:

- placeholder lists built from a count of integer IDs;
- whitelisted column/order fragments;
- the rate-limiter's batched insert, whose values are bound.

No user string is interpolated into SQL. The test suite's section 22/25 checks reinforce this.

### 3.5 Settlement integrity

- `recordSettlement()` is the only settlement writer used by `settle.php`.
- It verifies admin caller, member payer/payee, complete confirmations, computed-transfer
  match, and duplicate absence inside a transaction.
- `confirm.php` uses `SELECT … FOR UPDATE`.
- `settle_all.php` clears confirmations after force-finalizing.
- `expense_date <= MAX(period_end)` is rejected on create/update/delete/price.
- Future `period_end` is rejected.
- `0.00` marker rows are permitted and required for lock advancement.

### 3.6 Rate limiting

Six buckets are active (see `BACKEND_AUDIT.md` §2.6). The heartbeat bucket was added because
the old `$recordUsage = false` path could never trip. Login records failures for unknown emails
too, so account probing is not free.

### 3.7 Headers and CSP

`expmgSendSecurityHeaders()` emits:

- `Content-Security-Policy` with `script-src 'self' https://cdn.jsdelivr.net
  https://fonts.googleapis.com`, `base-uri`, `frame-ancestors`, and no `unsafe-inline` for
  scripts;
- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: DENY`;
- `Referrer-Policy`.

`style-src` permits inline styles by design. Inline event handlers are blocked. External
scripts use SRI.

### 3.8 Webroot

The root `.htaccess` denies dotfiles, SQL/MD/log/TXT/INI/lock/JSONL dumps, `composer.*`,
`mcp.json`, `run_tests.php`, `.git/`, `vendor/`, `graphify-out/`, `partials/`, `data/`, `logs/`,
`config/`, `scripts/`, and `tests/`. `logs/app.log` is not web-readable.

### 3.9 XSS

The application stores raw text in MySQL and escapes at render time (`escapeHTML`, PHP `htmlspecialchars`).
Write-time HTML-entity encoding is explicitly rejected: it double-escapes, breaks `LIKE`, and
corrupts exports. Three test assertions expecting encoded entities are policy-rejected, not
bugs.

CSP provides defence in depth. The only stored-XSS vector would be a render path that forgets to
escape; none was found in scope.

### 3.10 Error handling

- `api/helpers/response.php` installs a global exception handler and fatal shutdown handler.
- Clients receive `{ok:false, success:false, error:"Internal server error.", code:500}`.
- Details, file paths, and SQL errors go to `logs/app.log` only.
- Endpoint-level `try/catch` blocks log context and return the same generic message.

### 3.11 Idempotency

- Key is scoped by user + route + key.
- Payload hash detects reuse with a different body → 409.
- In-progress key → 409.
- File backend uses `flock`; Redis backend uses `SET NX EX` and a token-checked release.
- Orphan `.lock` files are swept after 30 s.

This protects against duplicate writes on retry; it is not a general conflict-resolution system.

### 3.12 Secrets

A git-history scan found:

- no `.env` object in any revision;
- `.env.example` contains only `DB_PASS=change-me`;
- `seed.sql` was added in `eb58d5a` and removed in `913c45f`; it contained bcrypt hashes of the
  demo password `password123`, also documented in `README.md` and stress scripts;
- no API keys, PEM files, or credential files.

The demo password is not a production secret, but it should not be reused. Database
credentials live in the untracked `.env`.

---

## 4. Residual risk and accepted debt

| Risk | Severity | Status |
|---|---|---|
| Expense update target-group authorization | Moderate | Documented, preserved |
| Group join capacity race | Low/Moderate | Documented, preserved |
| Post-confirm race | Low | Documented, preserved |
| List confirm price has no ceiling | Low | Documented, preserved |
| Inline handlers blocked by CSP | Low (UI) | Documented, preserved |
| Unguarded logout (no CSRF, no method guard) | Low — forced logout only | Documented, preserved (KD-31) |
| No TLS in local XAMPP | Environmental | Deployment responsibility |
| Session cookie `Secure` absent on HTTP | Environmental | Expected locally |
| Demo password in history/docs | Low | Do not reuse; history rewrite optional |
| No MFA / no password reset | Product gap | Out of scope |
| No audit trail for admin actions | Product gap | Out of scope |

None of these justify changing the backend during Phase 1. A critical security or data-loss fix
would require explicit approval and separate evidence.

---

## 5. What the Flutter client must preserve

1. Never call a protected endpoint without an authenticated session.
2. Never add anonymous API access for guest mode.
3. Send CSRF on every write, including login/signup. `logout.php` needs no token and accepts GET.
4. Send `Idempotency-Key` for create/confirm/settle actions and reuse it on retry.
5. Treat 401 as logout and 429 as a hard retry-after signal.
6. Respect the 24-hour absolute session lifetime.
7. Do not store raw credentials; use the cookie session or a future explicit token design.
8. Do not assume 200 on business errors; check `ok`.
9. Do not weaken TLS/certificate validation in production.
10. Do not expose settlement admin actions to non-admin users in the UI.

---

## 6. Reporting and evidence

- Full suite: `tests/run_tests.php` — 433/105/538.
- Penetration probes: `scripts/pentest/penetration_test.php`, `pentest_results.jsonl`,
  `pentest_state.json`.
- Final verdict: `PENTEST/FINAL_OUTCOME.md`.
- Baseline output captured for this audit:
  [`evidence/phase1-test-baseline.txt`](evidence/phase1-test-baseline.txt).

Any future security claim should cite the commit and date, not the undated June audit report.
