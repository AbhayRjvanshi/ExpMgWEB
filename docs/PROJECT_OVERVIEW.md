# Project Overview

## 1. What ExpMgWEB is

ExpMgWEB is a small multi-user expense manager. A signed-in user can record personal
expenses, create or join groups, share group expenses, maintain personal and group shopping
lists, convert checked list items into expenses, and run a multi-member settlement cycle.
The interface is a PHP-rendered single-page shell with vanilla JavaScript modules and a
server-side API.

There is **no guest mode** in the current application. Every screen except splash, login, and
signup requires an authenticated PHP session. Anonymous API access is rejected by
`requireAuth()`.

The product is being migrated to a Flutter client. Phase 1 freezes and documents the existing
PHP behaviour; it does not change it.

---

## 2. Technology stack

| Layer | Technology | Notes |
|---|---|---|
| Web server | Apache (XAMPP on Windows) | Document root is the project root, not `public/` |
| Backend | PHP 8+ procedural | No framework, no Composer autoload in endpoints |
| Database | MySQL 8 / MariaDB 10.4.32 (XAMPP) | Raw `mysqli` prepared statements only |
| Frontend | Vanilla JavaScript (ES2020) | No build step, no bundler, no npm dependency at runtime |
| Charts | Chart.js from jsDelivr CDN | Loaded by the SPA shell with SRI |
| PDF | jsPDF from jsDelivr CDN | Client-side PDF generation |
| Cache / queue | Redis (optional) | Graceful fallback to file store / MySQL at all times |
| Auth | PHP sessions with a hardened cookie | 24-hour absolute lifetime |
| Notifications | MySQL `notifications` table | File/Redis notification abstraction is dormant |

### Why no framework

The application deliberately avoids a framework and an ORM. Every endpoint owns its request
lifecycle and uses explicit `require_once` chains. This keeps boot time near zero and makes the
application deployable on any XAMPP/LAMP host, at the cost of repetitive boilerplate. That
trade-off is intentional and must not be "cleaned up" during the migration.

---

## 3. Repository layout

```
ExpMgWEB/
├── api/                    40 HTTP endpoints + 1 CLI worker + helpers + middleware + dormant services
├── config/                 db, env, session, idempotency
├── pages/                  PHP page partials included by public/index.php
├── public/
│   ├── index.php           SPA shell and page router
│   ├── splash.php          entry splash
│   └── assets/
│       ├── js/             helpers, app, expenses, groups, lists, notifications, auth, theme
│       └── css/styles.css  design system
├── data/                   file-backed idempotency store and locks
├── logs/app.log            application log (web-denied)
├── scripts/                CLI cleanup and stress scripts
├── tests/run_tests.php     canonical 538-test suite
├── schema.sql              14-table schema (source of truth)
├── migration_v*.sql        incremental migrations
├── PENTEST/                security reports and final outcome
└── docs/                   this Phase 1 audit set
```

`run_tests.php` in the repository root is a **different, stale copy** of the suite. The
canonical suite is `tests/run_tests.php`.

---

## 4. Runtime topology

```
Browser
  │  GET /public/splash.php  →  /pages/login.php  (or /public/index.php if session exists)
  │
  ├── HTML pages (pages/*.php) ── rendered inside public/index.php shell
  │        │
  │        └── assets/js/*.js
  │                 │  fetch(API + '/…', credentials: 'same-origin')
  │                 ▼
  └── api/*.php ── requireAuth() ── mysqli prepared statements ── MySQL
                          │
                          ├── optional Redis (rate limits, cache, locks, idempotency)
                          └── logs/app.log
```

Key points:

- The browser never calls the database directly.
- The SPA shell is served from `public/index.php` and includes one page partial selected by
  `?page=`.
- Login, signup, logout, and splash are standalone PHP pages/endpoints outside the SPA router.
- Static assets are served directly by Apache.
- Redis is optional at every call site; a missing Redis connection must not break a request.

---

## 5. Request lifecycle

### 5.1 Authenticated API request

1. Apache routes the request to e.g. `api/expenses/create.php`.
2. The endpoint requires `config/db.php`, `api/middleware/auth.php`, and helpers.
3. `api/middleware/auth.php`:
   - loads `config/session.php` and calls `ensureSecureSessionStarted()`;
   - sends security headers (`CSP`, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`);
   - `requireAuth()` validates `$_SESSION['user_id']`;
   - enforces the 24-hour absolute session lifetime;
   - verifies CSRF on `POST`/`PUT`/`PATCH`/`DELETE`;
   - releases the PHP session file lock with `session_write_close()`;
   - applies per-user (120/min) and per-IP (240/min) API rate limits.
4. The endpoint parses `$_GET`/`$_POST`, validates input, and runs prepared statements.
5. The endpoint emits a JSON envelope `{ "ok": …, … }` and exits.
6. Unhandled exceptions are caught by endpoint `try/catch` blocks (where adopted) or by the
   global handler installed by `api/helpers/response.php`.

### 5.2 Pre-authentication request

`api/login.php` and `api/signup.php` do not call `requireAuth()`. They:

1. boot the hardened session themselves via `config/session.php`;
2. apply their own IP rate limits (login 5 / 15 min, signup 3 / hour);
3. verify CSRF through `verifyCsrf()`;
4. on success/failure, set a session flash value and `header('Location: …')` back to a PHP page.

They return **redirects and HTML pages**, not the JSON envelope, for normal form flows. They
return JSON only for the rate-limit rejection path.

### 5.3 CLI-only paths

`api/workers/process_outbox.php`, `scripts/cleanup_notifications.php`, and
`tests/run_tests.php` detect a non-CLI SAPI and return HTTP 403.

---

## 6. Authentication and session model

| Property | Value |
|---|---|
| Session storage | PHP file sessions |
| Cookie flags | `HttpOnly`, `SameSite=Lax`, `Secure` only when HTTPS, strict mode |
| Session lifetime | 24 hours absolute from `login_time` |
| Session ID | Regenerated on successful login |
| Password storage | `password_hash(..., PASSWORD_BCRYPT)` |
| CSRF | `X-CSRF-Token` header (with `$_SERVER` and POST-body fallbacks); the cookie is only an optional consistency check |
| CSRF failure gate | 50 failures / 10 minutes per IP → HTTP 429 |
| CSRF exception | `logout.php` has no CSRF check and no method guard (any method); forced-logout risk via cross-site top-level link navigation, KD-31 |
| Session guard | `requireAuth()` on every module endpoint |
| Heartbeat bucket | `notifications/count.php` uses `requireAuth(false)` → 30/min/user |

There is no "remember me", no OAuth, no token API, no API key, and no anonymous read path.

---

## 7. SPA shell and routing

`public/index.php`:

- starts the hardened session and redirects unauthenticated visitors to `pages/login.php`;
- emits the CSP and security headers;
- renders the shell (header, theme toggle, notification bell, page container, modals);
- selects a page partial from `pages/` using a whitelist;
- loads Chart.js and jsPDF from jsDelivr with SRI;
- loads `helpers.js` before page-specific modules.

Routes (via `?page=`):

| Route | Page partial | Primary JS |
|---|---|---|
| `home` (default) | `pages/home.php` | `app.js` |
| `expenses` | `pages/expenses.php` | `expenses.js` (+ `app.js`) |
| `groups` | `pages/groups.php` | `groups.js` |
| `lists` | `pages/lists.php` | `lists.js` |
| `notifications` | `pages/notifications.php` | `notifications.js` |

`partials/lists_section.php` exists but is **not included** by any live page. It is dead code.

---

## 8. Data model in one paragraph

`users` own personal rows and group memberships. `groups` contain up to 10 `group_members`
and are created by an admin. `expenses` are either personal (`group_id NULL`) or group
(`group_id NOT NULL`); the actual payer is `paid_by`, while `user_id`/`created_by` record who
entered the row. `categories` are seeded and shared. `budgets` are one row per user per month.
`lists` and `list_items` support personal and group shopping lists, with priced items converted
to expenses. `settlements` record finalized transfers, and `settlement_confirmations` /
`post_settlement_confirmations` track the multi-member confirmation gates.
`notifications` is the only live notification channel. `rate_limits` backs throttling.
`outbox_events` exists but its write side is unwired.

Full detail: [`DATABASE_AUDIT.md`](DATABASE_AUDIT.md).

---

## 9. Design system

`public/assets/css/styles.css` defines nine CSS token variables at the top and a
mint-to-emerald palette. The theme is selected by a `data-theme` attribute on the document
element and persisted in `localStorage`; `theme-init.js` applies it before first paint to avoid
a flash.

Representative tokens (as documented in the project README): mint surfaces, emerald primary,
deep pine teal for cards/panels. The Flutter client should reproduce the visual system rather
than the CSS implementation.

---

## 10. Configuration

Configuration is loaded from `.env` through `config/env.php` (`env()` helper). `config/db.php`
reads `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT`. The database account should be the
least-privilege `expmg_app` account described in `schema.sql`; root credentials are not
acceptable.

Optional settings include Redis connection values, `NOTIFICATIONS_BACKEND`, and
`IDEMPOTENCY_BACKEND`. All optional backends degrade gracefully.

No credentials are committed. `.env.example` contains placeholder values only. See
[`SECURITY_AUDIT.md`](SECURITY_AUDIT.md) for the secret scan result.

---

## 11. Version context

The application is at the v2.17 code line. The migration itself is Phase 1 of a larger plan.
Relevant planned-but-unbuilt work (service layer v1.7, ephemeral notifications v2.5, pagination
v2.1, response-helper rollout section 30) is catalogued in
[`TECHNICAL_DEBT.md`](TECHNICAL_DEBT.md). None of it is a dependency for the Flutter client.
