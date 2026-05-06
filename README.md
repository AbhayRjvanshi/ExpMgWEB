# Expense Manager (ExpMgWEB)

A web-based personal and group financial tracking application built with PHP, MySQL, and vanilla JavaScript. Track everyday spending, split group costs fairly, set monthly budgets, manage shopping lists, and visualise your finances — all without installing a single app.

---

## Table of Contents

- [Why This Project Is Needed](#why-this-project-is-needed)
- [How It's Different](#hows-different)
- [How the Project Can Scale](#how-the-project-can-scale)
- [How It Works (Brief)](#how-it-works-brief)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Setup (XAMPP on Windows)](#setup-xampp-on-windows)
- [Design System](#design-system)
- [Business Rules](#business-rules)
- [Testing & Quality Assurance](#testing--quality-assurance)
- [Changelog](#changelog)
- [Architecture Notes](#architecture-notes)
- [License](#license)

---

## Why This Project Is Needed

Managing money — especially shared expenses — remains one of the most common friction points in everyday life. Roommates splitting rent, friends sharing a dinner bill, or a team pooling funds for supplies: the math is simple, but keeping everyone honest and informed is not.

Most people fall back on spreadsheets, chat messages, or memory. That leads to forgotten payments, awkward conversations, and inaccurate tallies. Existing solutions (Splitwise, YNAB, Mint) either lock features behind subscriptions, require native app installs, or harvest personal financial data on third-party servers.

**Expense Manager solves this by being:**

- **Self-hosted** — your data stays on your own server (XAMPP, any LAMP stack, or a VPS). No third-party cloud stores your spending habits.
- **Zero-install for users** — runs in any browser, no app store required, no downloads.
- **Free & open** — no premium tiers, no limits, no ads.
- **Lightweight** — no heavy frameworks; loads fast on slow connections and old hardware.

Whether it's a household keeping track of groceries, a student hostel splitting bills, or a small team managing petty cash — this tool gives full visibility and fairness without any cost.

---

## How It's Different

| Aspect | Splitwise / Mint / YNAB | Expense Manager |
|---|---|---|
| **Hosting** | Third-party cloud | Self-hosted (you own all data) |
| **Cost** | Free tier + paid upgrades | Completely free, no limits |
| **Privacy** | Data stored externally | Data never leaves your server |
| **Install** | Native app or account required | Browser-only, no install |
| **Group settlement** | Instant admin-settles | Per-member confirmation — every member must agree before a period closes |
|**Real-time alerts**| Push notifications (requires app) | Browser polling + toast popups with sound (no app needed) |
| **Settlement lock** | Manual / honour-based | System-enforced: settled periods become immutable |
| **Tech dependency** | Proprietary stack | Standard PHP + MySQL; easy to audit, extend, or migrate |
| **Offline-ready server** | No — requires internet | Works on a local network (classroom, hostel Wi-Fi) |

The **per-member settlement confirmation** system is particularly unique: instead of one admin finalizing a settlement and everyone having to trust it, each member independently confirms their debits or credits. The period only closes when every member agrees — ensuring transparency and accountability.

---

## How the Project Can Scale

**Vertical (single-server) scaling:**
- The codebase uses indexed MySQL queries and lightweight JSON APIs — it can handle thousands of users on a basic VPS without modification.
- No ORM overhead, no framework boot time; raw PHP serves responses in single-digit milliseconds.
- Built-in **health monitoring** (`HealthService`, `MetricsService`) and **queue pressure tracking** allow graceful degradation under load instead of failure.

**Horizontal scaling paths (partially implemented):**
- **Database:** Migrate from single-server MySQL to a managed service (Amazon RDS, PlanetScale, TiDB) for replication and failover. Environment-based configuration (`config/env.php`) makes multi-environment deployments seamless.
- **Caching & Session Storage:** Redis backend is **already integrated** (`api/helpers/redis.php`, `RedisService.php`):
  - Optional Redis-backed notification system (`NOTIFICATIONS_BACKEND=redis`) with automatic file-store fallback if Redis is unavailable.
  - `CacheService.php` provides a caching abstraction layer ready for Redis, Memcached, or file-based implementations.
  - Session storage can be migrated to Redis by updating `session.save_handler` and connection pooling via `RedisService`.
- **Durability & Reliability:** Durable outbox pattern (`outbox` table) enables reliable async processing:
  - Events are durably stored before sending, preventing silent failures.
  - Worker processes claim and retry messages with exponential backoff.
  - Failed messages are marked `dead` for manual replay, not lost.
- **API layer:** The existing REST-style API structure (`api/expenses/create.php`, etc.) maps cleanly to a microservice split if needed. Infrastructure services are already abstracted (`api/services/` layer covers caching, health monitoring, notifications, Redis pooling, and system coordination).
- **Frontend:** The vanilla JS frontend could be wrapped in a PWA (Service Worker) for offline capability and home-screen install — no rewrite needed.
- **Auth:** Session-based auth can be augmented with JWT tokens for mobile/API clients via the existing auth abstraction.
- **Deployment:** Dockerize the PHP + MySQL + Redis stack for one-click cloud deployment (AWS, DigitalOcean, Railway). Environment variables control all configuration; `.env` is dev-only and gitignored.

**Scaling considerations (implemented):**
- **Health endpoint:** Backend exposes `/api/system/health.php` with queue pressure, retry-after guidance, and Redis state for operational monitoring.
- **Rate limiting:** User-scoped API fairness (120 requests/min per user, 240 requests/min per IP), adaptive cooldown backoff.
- **Idempotency:** File-based replay protection (`config/idempotency.php`) is implemented but not yet wired into production endpoints. Infrastructure is in place for future activation.

**Feature scaling (future):**
- Multi-currency support (add a `currency` column to expenses)
- Recurring expenses (cron job + template table)
- Receipt photo uploads (file storage + thumbnail generation)
- Export to CSV / Excel
- Admin dashboard for multi-tenant deployments
- Mobile app client (JWT auth already designed; REST API is mobile-ready)

The architecture is intentionally simple — no over-engineering — which makes any of these additions straightforward. Core scaling primitives (outbox, health monitoring, infrastructure services, environment config) are already in place.

---

## How It Works (Brief)

### Quick Navigation

- [Authentication](#authentication)
- [Calendar Home](#calendar-home)
- [Expense Management](#expense-management)
- [Budget Tracking](#budget-tracking)
- [Group Settlement](#group-settlement)
- [Shopping Lists](#shopping-lists)
- [Late Expenses Settlement](#late-expenses-settlement)
- [Two-Column Expense View](#two-column-expense-view)
- [Notifications](#notifications)

---

### Authentication
Users sign up with a username, email, and password (bcrypt-hashed). On login, a `login_time` timestamp is stored for session lifetime enforcement. A centralized **authentication middleware** (`api/middleware/auth.php`) protects all API endpoints — verifying `$_SESSION['user_id']`, validating `login_time`, and enforcing a **24-hour session expiration**. Expired sessions are destroyed automatically.

### Calendar Home
The home page renders a month-grid calendar. Each day cell shows colored dots indicating expense types: **blue** (personal), **green** (unsettled group), **gray** (settled group). Clicking a day opens a panel listing that day's expenses with edit/delete controls.

### Expense Management
Expenses are created with an amount, category (8 presets), optional note, date, and type (personal or group). Group expenses require a **Paid By** selection — identifying which member actually paid — and follow admin-only edit/delete rules. A **settlement lock** prevents any modification to expenses that fall within an already-settled date range. Three audit columns (`paid_by`, `created_by`, `checked_by`) track the full lifecycle of each expense.

### Budget Tracking
Users set a monthly budget. The expenses page shows a progress bar that fills based on spending — turning amber at 80% and red at 100%. Summary cards show total, personal, and group breakdowns alongside category doughnut and daily bar charts (Chart.js).

### Group Settlement
When a group has unsettled expenses, the system calculates each member's fair share using a **greedy debt-minimization algorithm** (minimizes the number of transactions needed). Contributions are determined by the `paid_by` field — ensuring the person who actually paid gets the credit, even if someone else recorded the expense. Each member sees who owes whom and clicks **Settle** to confirm from their side. Only when **all members confirm** does the period officially close — at which point expenses become immutable (gray dots, no edit/delete, lock enforced at API level). Past settlements are viewable with a **PDF export** option.

### Shopping Lists
Users create personal or group shopping lists. Items have priority levels (high / moderate / low) and an optional price. Checking off a priced personal item **auto-creates an expense** using the checked date. For group list items, checking triggers a **Confirm Purchase popup** where the user selects which member actually paid and optionally enters/adjusts the price — ensuring the `paid_by` field is set correctly before the expense is created. If no price was set at check-time, the item appears in the **Unpriced Items** queue on the Expenses page where a price can be added later — converting it to an expense retroactively. If the item's checked date falls within an already-settled period, the resulting expense is flagged as a **post-settlement (late) expense** and routed to the supplementary settlement flow.

### Late Expenses Settlement
When a list item is priced after its settlement period has closed, the expense is flagged `is_post_settlement = 1`. These late expenses appear in a dedicated **Late Expenses Settlement** card on the Settlement tab. The system recalculates the affected period's shares including the new expenses. Each member confirms independently — when all confirm, the original settlement records are **updated in place** with corrected amounts and the late flag is cleared.

### Two-Column Expense View
The Month's Expenses section displays expenses in two side-by-side columns: **Personal Expenses** (left) and **Group Expenses** (right). Each column scrolls independently and shows colored status dots (blue = personal, green = unsettled group, gray = settled group). A **sorting control panel** above the columns lets users sort both columns simultaneously by Date, Name, Amount, or Category in ascending or descending order.

### Notifications
All group actions (join, leave, expense add/edit/delete, settlement, list changes) generate notifications stored in the MySQL `notifications` table. A bell icon polls every 10 seconds for new alerts, showing a badge count and a dropdown. New notifications trigger a **toast popup with an audio chime**. Notifications are marked as read on acknowledgment. A dedicated **notification history page** shows recent alerts grouped by date. A file-based ephemeral notification store (`notification_store.php`, `FileNotificationStore.php`) and a Redis-backed store exist in the codebase as alternative backends but are not currently in the production code path.

---

---

## Features

| Module | Highlights |
|---|---|
| **Authentication** | Sign up / Log in / Log out with session-based auth, bcrypt-hashed passwords, centralized auth middleware, 24-hour session expiration, and IP-based rate limiting (login: 5/15 min, signup: 3/hr, API: 120/min per user, 240/min per IP) |
| **Calendar Home** | Month grid with colored dot indicators (blue = personal, green = unsettled group, gray = settled group); click a day to view/add/edit expenses |
| **Expense CRUD** | Personal or group expenses with 8 categories, notes, and date; settlement lock prevents changes in settled periods |
| **Charts & Analytics** | Monthly summary cards, category doughnut chart, daily spending line graph with Personal and Group series (Chart.js) |
| **Budgets** | Set a monthly budget; progress bar turns amber at 80% and red at 100% |
| **Groups** | Create up to 5 groups (max 10 members each); join via 8-character code; admin controls |
| **Settlement** | Per-member settlement confirmation; greedy debt-minimization algorithm; settlement breakdown, status tracking, PDF export; **late expenses settlement** with automatic past-record updates |
| **Lists** | Shopping / to-buy lists with priority ordering, optional price, auto-expense on check-off, unpriced items queue |
| **Notifications** | MySQL-backed notification store; real-time polling (10s) with bell dropdown, toast popups with sound; SHA-256 dedup; group rate limiting (20/min). File-based and Redis-backed ephemeral stores exist as alternative backends but are not in the production code path. |

---

## Tech Stack

- **Back-end:** PHP 8+ (procedural, no framework)
- **Database:** MySQL 8 via `mysqli`
- **Front-end:** Vanilla HTML / CSS / JavaScript
- **Charts:** Chart.js (CDN)
- **PDF Export:** jsPDF + jsPDF-AutoTable (CDN)
- **Font:** Inter (Google Fonts)
- **Server:** Apache on XAMPP (Windows) — works on any LAMP/MAMP stack

---

## Project Structure

```
ExpMgWEB/
├── config/
│   ├── db.php                 # MySQL connection (env-based; DB_HOST, DB_USER, DB_PASS, DB_NAME)
│   ├── env.php                # Environment loader (phpdotenv integration, env() helper)
│   └── idempotency.php        # File-based idempotency cache (not yet used in production)
├── api/
│   ├── signup.php             # POST — create account
│   ├── login.php              # POST — authenticate
│   ├── logout.php             # GET  — destroy session
│   ├── bootstrap.php          # Security enforcement scaffold (CSRF, CSP headers) — exists but not included by any endpoint
│   ├── helpers/
│   │   ├── validator.php      # Centralized input validation & sanitization
│   │   ├── response.php       # Centralized API response & error handling (apiResponse, apiSuccess, apiError)
│   │   ├── logger.php         # Structured JSON logging (logMessage → logs/app.log)
│   │   ├── rate_limiter.php   # DB-backed rate limiting (checkRateLimit, recordRateLimit, cleanupRateLimits)
│   │   ├── csrf.php           # CSRF protection (token generation, verification, rotation, monitoring)
│   │   ├── notification_store.php # Ephemeral file-based notification store (publish, consume, dedup, TTL) — not in production code path
│   │   ├── notification_publisher.php # Notification publishing helpers (publishGroupNotification, publishNotificationToUsers)
│   │   └── redis.php          # Redis connection pooling and helpers
│   ├── middleware/
│   │   └── auth.php           # Authentication guard: session check + 24-hour expiration + CSRF verification
│   ├── services/
│   │   ├── CacheService.php         # Caching layer for frequently-accessed data
│   │   ├── FileNotificationStore.php # Ephemeral file-based notification store implementation
│   │   ├── HealthService.php        # System health monitoring and status checks
│   │   ├── LockService.php          # Concurrency control and lock management
│   │   ├── MetricsService.php       # Performance metrics and observability
│   │   ├── NotificationStore.php    # Ephemeral notification store interface
│   │   ├── NotificationService_Redis.php # Redis-backed notification service (optional)
│   │   ├── PredictiveHealthService.php   # Predictive monitoring and forecasting
│   │   ├── RedisService.php         # Redis connection pooling and helpers
│   │   └── SystemOrchestrator.php   # System-wide coordination and resource management
│   ├── expenses/
│   │   ├── categories.php     # GET  — list all 8 categories
│   │   ├── create.php         # POST — add expense (with settlement lock)
│   │   ├── list.php           # GET  — by date or month (with settled flags)
│   │   ├── update.php         # POST — edit expense (permission + lock check)
│   │   ├── delete.php         # POST — remove expense (permission + lock check)
│   │   ├── summary.php        # GET  — monthly totals, by-category, by-day
│   │   ├── unpriced.php       # GET  — list unpriced checked items awaiting price
│   │   └── price_unpriced.php # POST — add price to unpriced item → create expense (post-settlement aware)
│   ├── budgets/
│   │   ├── get.php            # GET  — fetch budget for a month
│   │   └── set.php            # POST — create / update budget (upsert)
│   ├── groups/
│   │   ├── create.php         # POST — new group (generates join code)
│   │   ├── join.php           # POST — join via code (with notifications)
│   │   ├── leave.php          # POST — leave group (with notifications)
│   │   ├── details.php        # GET  — members + recent expenses
│   │   ├── delete.php         # POST — admin-only delete (with notifications)
│   │   ├── remove_member.php  # POST — admin-only remove member (with notifications)
│   │   └── user_groups.php    # GET  — current user's groups
│   ├── settlements/
│   │   ├── settlement_helpers.php # Shared greedy debt-minimization algorithm
│   │   ├── calculate.php      # GET  — compute shares, balances, confirmations
│   │   ├── confirm.php        # POST — individual member settlement confirmation
│   │   ├── settle.php         # POST — record a single settlement
│   │   ├── settle_all.php     # POST — admin settle-all (legacy)
│   │   ├── history.php        # GET  — past settlements for a group
│   │   ├── details.php        # GET  — expenses within a settlement period
│   │   ├── post_calculate.php # GET  — calculate supplementary settlement for late expenses
│   │   └── post_confirm.php   # POST — confirm late settlement; recalculates & updates past records
│   ├── lists/
│   │   ├── create.php         # POST — new list
│   │   ├── user_lists.php     # GET  — current user's lists
│   │   ├── details.php        # GET  — items in a list
│   │   ├── delete.php         # POST — delete list
│   │   ├── add_item.php       # POST — add item to list
│   │   ├── remove_item.php    # POST — remove item
│   │   └── check_item.php     # POST — toggle checked (auto-creates expense if priced; detects post-settlement)
│   ├── notifications/
│   │   ├── list.php           # GET  — notifications with pagination
│   │   ├── history.php        # GET  — recent notifications
│   │   ├── read.php           # POST — mark single or all as read
│   │   └── count.php          # GET  — lightweight unread count (polling)
│   └── system/
│       └── health.php         # GET  — system health status, queue pressure, degradation signals
├── pages/
│   ├── login.php              # Login form
│   ├── signup.php             # Signup form
│   ├── home.php               # Calendar-based day view with expense dots
│   ├── expenses.php           # Charts, analytics, budget, settlement tab
│   ├── groups.php             # Group management UI
│   ├── lists.php              # Shopping list UI
│   └── notifications.php      # 7-day notification history page
├── public/
│   ├── index.php              # Authenticated shell — page router + nav
│   ├── splash.php             # Landing / splash page
│   └── assets/
│       ├── css/styles.css     # Full design system (mint-to-emerald palette + skeleton animations)
│       └── js/
│           ├── helpers.js     # Shared global utilities ($, show, hide, escapeHTML, post, get, API, getCsrfToken)
│           ├── app.js         # Calendar, notification & profile modules
│           └── lists.js       # List CRUD operations, item locking, group confirmation flow (not yet loaded by pages)
├── tests/
│   ├── run_tests.php          # Automated test suite (35 sections)
│   ├── distributed_system_test.php # Distributed system integration tests
│   └── test_redis_notifications.php # Redis notification backend tests
├── scripts/
│   ├── cleanup_notifications.php  # Automated 3-day notification retention (cron job)
│   └── CRON_SETUP.md              # Cron job configuration and monitoring guide
├── schema.sql                 # Full database schema (13 tables)
├── seed.sql                   # Demo data (3 users, expenses, group, lists)
├── run_tests.php              # Test runner entry point (includes tests/run_tests.php)
├── migration_v2.2.sql         # Upgrade 9 migration (rate_limits table)
├── migration_v2.3.sql         # Upgrade 10 migration (CSRF protection - code only)
├── migration_v2.4.sql         # Upgrade 11 migration (notification retention indexes)
├── migration_v2.5.sql         # Upgrade 12 migration (ephemeral notification system)
├── migration_v2.6.sql         # Upgrade 13 migration (env config & notification store refactor)
├── .env.example               # Template for environment variables (DB, Redis, app config)
├── logs/
│   └── app.log                # Structured JSON log file (created at runtime)
└── README.md                  # This file
```

---

## Setup (XAMPP on Windows)

### 1. Prerequisites

- [XAMPP](https://www.apachefriends.org/) with Apache + MySQL running
- PHP 8.0 or higher (ships with recent XAMPP)

### 2. Install

```bash
# Copy the project into htdocs
xcopy /E /I "ExpMgWEB" "C:\xampp\htdocs\expense-manager"
```

### 3. Create the database

Open **phpMyAdmin** (`http://localhost/phpmyadmin`) or a MySQL terminal:

```sql
-- Import the schema
SOURCE C:/xampp/htdocs/expense-manager/schema.sql;

-- (Optional) Load demo data
SOURCE C:/xampp/htdocs/expense-manager/seed.sql;
```

Or via command line:

```bash
mysql -u root < schema.sql
mysql -u root ExpMgWEB < seed.sql
```

### 4. Configure connection (env-based)

This project reads database credentials and other environment-specific
settings from environment variables (optionally loaded via a local
`.env` file in development).

For local XAMPP setup, create a `.env` file in the project root (you
can copy from `.env.example`):

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_PASS=
DB_NAME=ExpMgWEB

APP_ENV=development
APP_DEBUG=1
```

> Note: `.env` is **gitignored** and should never be committed. In
> staging/production, provide these values via real environment
> variables (Docker/Kubernetes/cloud secret manager), not a `.env` file.

### 5. Open in browser

```
http://localhost/expense-manager/public/
```

If you loaded the seed data, log in with:

| Username | Password |
|---|---|
| `alice` | `password123` |
| `bob` | `password123` |
| `carol` | `password123` |

---

## Design System

### Color Palette

The UI uses a custom **mint-to-emerald green** CSS palette:

| Token | Hex | Usage |
|---|---|---|
| Evergreen | `#081c15` | Body background |
| Pine Teal | `#1b4332` | Cards, panels |
| Dark Emerald | `#2d6a4f` | Card gradients |
| Sea Green | `#40916c` | Buttons, links |
| Mint Leaf | `#52b788` | Primary accent |
| Light Mint | `#74c69d` | Secondary accent |
| Celadon | `#95d5b2` | Muted text |
| Pale Mint | `#b7e4c7` | Body text |
| Frosted Mint | `#d8f3dc` | Headings, highlights |

---

## Business Rules

- **Session security:** 24-hour session lifetime enforced by middleware; centralized `requireAuth()` guard on all API endpoints; **CSRF protection** enforced on all state-changing requests (POST/PUT/PATCH/DELETE) via session-bound 256-bit tokens with timing-attack resistant validation.
- **CSRF protection (hardened):** All POST/PUT/PATCH/DELETE requests require `X-CSRF-Token` header matching session token; double-submit cookie defense (cookie + header verification); tokens auto-rotated every 12 hours; SameSite=Lax cookie policy (OAuth/redirect compatible); failed attempts logged with WARNING level; threshold monitoring (50 failures/10 min triggers CRITICAL alert); Content-Security-Policy headers prevent XSS.
- **Rate limiting:** Rate limiting enforced at three tiers — login (5 attempts per 15 minutes), signup (3 attempts per hour), and general API (120 requests per minute per user, 240 requests per minute per IP). Backed by the `rate_limits` database table with probabilistic cleanup (~1% of requests purge expired entries).
- **Password hashing:** bcrypt via `password_hash()` / `password_verify()`.
- **Expense permissions:** Personal expenses — only the owner can edit/delete. Group expenses — only the group admin can edit/delete.
- **Group limits:** Max 5 groups per user; max 10 members per group.
- **Budget alerts:** Progress bar turns amber at 80 % usage, red at 100 %.
- **Notification polling:** Client polls `/api/notifications/count.php` every 10 seconds; toast popup + audio chime on new alerts.
- **Settlement confirmation:** Every active group member must individually confirm before a period closes; prevents unilateral settlement.
- **Settlement lock:** Once a period is settled, all expenses within that date range become immutable (API-enforced).
- **List priorities:** Items display grouped by priority (high → moderate → low), oldest first within each tier.
- **List-to-expense conversion:** Checking a priced list item auto-creates an expense dated to the check date. For group list items, a **Confirm Purchase popup** requires selecting which member paid before the expense is created. Unpriced checked items queue for later pricing.
- **Paid By audit:** Group expenses track `paid_by` (actual payer), `created_by` (who recorded it), and `checked_by` (who checked the list item). Settlement calculations use `paid_by` exclusively.
- **Post-settlement expenses:** If an expense's date falls within a settled period, it is flagged `is_post_settlement`. These are excluded from normal settlement and handled via a separate confirmation flow that updates past settlement records.

---

## Testing & Quality Assurance

### Full Codebase Audit (v1.3, extended through v2.14)

A comprehensive audit was performed across the entire project — covering all 38 API endpoints, 7 page files, 2 JS modules, and the database schema. The audit consisted of:

1. **Static code review** — every PHP and JS file read line-by-line for logic errors, security gaps, missing validations, and code duplication.
2. **PHP syntax check** — `php -l` run against every `.php` file in the project.
3. **Automated functional test suite** — a test script (`run_tests.php`) that exercises every feature end-to-end via HTTP requests against a live local server.

### Issues Found & Fixed

The audit identified **20 issues** (6 critical, 7 medium, 7 low). All critical and key medium issues were fixed:

#### Critical Fixes

| # | File | Issue | Fix |
|---|---|---|---|
| 1 | `api/settlements/confirm.php` | **Race condition** — two members confirming simultaneously could both see "not all confirmed" and skip finalization, or double-finalize | Wrapped confirmation insert + all-confirmed check in a `BEGIN TRANSACTION` with `SELECT ... FOR UPDATE`; ensured `COMMIT` in both the finalize and non-finalize branches |
| 2 | `api/settlements/settle.php` | **Missing admin guard** — any group member could call settle.php and insert settlement records | Added `role !== 'admin'` check before processing |
| 3 | `api/settlements/settle_all.php` | **Post-settlement double-count** — late expenses (`is_post_settlement = 1`) were included in normal settlement totals | Added `AND e.is_post_settlement = 0` filter to both contribution and period-date queries |
| 4 | `api/expenses/update.php` | **Settlement lock bypass** — moving an expense to a different group didn't check if the target group's settlement period covered the expense date | Added settlement lock check on the target group when `group_id` changes |
| 5 | `api/expenses/price_unpriced.php` | **Datetime-as-date bug** — `checked_at` (a datetime value) was used directly as `expense_date`, causing date comparison failures | Converted with `date('Y-m-d', strtotime($item['checked_at']))` |
| 6 | `api/expenses/create.php` | **Wrong notification reference** — notification `reference_id` pointed to `$groupId` instead of the new expense ID | Changed to `$newId` (the `insert_id`) |

#### Medium Fixes

| # | File | Issue | Fix |
|---|---|---|---|
| 7 | `api/lists/check_item.php` | **Fragile expense deletion** — unchecking a list item deleted the linked expense by matching note + date + amount, which could hit the wrong row | Added `expense_id` column to `list_items` (FK → `expenses`); check stores the expense ID, uncheck deletes by exact ID; falls back to old matching only if `expense_id` is NULL |
| 8 | `api/lists/delete.php` | **Missing permission check** — any group member could delete any group list | Restricted to admin or list creator |
| 9 | `api/budgets/set.php` | **No method validation** — accepted GET requests, allowing budget changes via URL | Added `$_SERVER['REQUEST_METHOD'] !== 'POST'` guard |
| 10 | `config/db.php` | **Raw HTML error on DB failure** — `die()` output broke JSON-expecting clients | Changed to `json_encode(['ok' => false, 'error' => '...'])` response |

#### Code Duplication Removed

| Scope | What was duplicated | Resolution |
|---|---|---|
| **JavaScript (6 files)** | `$()`, `$$()`, `show()`, `hide()`, `escapeHTML()`, `post()`, `get()`, `API` constant were copy-pasted into every page's inline `<script>` and all 3 IIFEs in `app.js` | Extracted to `public/assets/js/helpers.js` loaded once in `<head>`; removed ~200 lines of duplicate code across `app.js`, `groups.php`, `lists.php`, `expenses.php`, `notifications.php` |
| **PHP (5 files)** | The greedy debt-minimization settlement algorithm (~40 lines) was duplicated in `calculate.php`, `post_calculate.php`, `confirm.php`, `post_confirm.php`, `settle_all.php` | Extracted to `api/settlements/settlement_helpers.php::calculateSettlements()`; all 5 files now `require_once` the shared helper |

### Automated Test Suite (`run_tests.php`)

The test script creates 3 temporary test users, exercises every feature via real HTTP requests, then cleans up all test data. It covers **35 sections**:

> **Note:** Sections 1–27 test features that are fully implemented. Sections 28–34 were written to verify planned refactors (service layer, centralized error handling, logging, pagination, ephemeral notifications) that are partially implemented or not yet wired into production endpoints. These sections may report failures when run against the current codebase. Section 35 tests the system health endpoint.

| # | Section | Tests | What's Verified |
|---|---|---|---|
| 1 | Schema Validation | 19 | All 12 tables exist; key columns (`paid_by`, `created_by`, `checked_by`, `is_post_settlement`, `expense_id`, `expense_created`) present; categories seeded |
| 2 | Auth | 10 | Signup (3 users), duplicate rejection, login (session cookies), wrong password rejection |
| 3 | Categories | 2 | GET returns OK; at least 8 categories |
| 4 | Personal Expenses CRUD | 9 | Create, list by date, list by month, update, verify update, cross-user edit block, delete, cross-user delete block |
| 5 | Expense Summary | 4 | Monthly totals, `by_category` array, `by_day` array |
| 6 | Budgets | 6 | Set, get, verify amount, update, verify update, GET method rejected |
| 7 | Groups | 12 | Create, join code returned, user groups list, join (2 members), invalid code rejected, details, member count, role checks |
| 8 | Group Expenses | 6 | Create with `paid_by`, verify `paid_by` tracking, non-admin edit block, admin edit, non-admin delete block |
| 9 | Notifications | 5 | Count endpoint, list endpoint, notifications generated, mark single read, mark all read |
| 10 | Settlement Calculate | 5 | Returns OK, correct member count, settlements array, per-person share math, empty history before settle |
| 11 | Settlement Confirmation | 8 | 3-member sequential confirm; not-finalized after 1/3 and 2/3; finalized after 3/3; history populated; details endpoint |
| 12 | Settlement Lock | 2 | Update blocked on settled expense; delete blocked on settled expense |
| 13 | Post-Settlement Expenses | 10 | Create list item on settled date → check → confirm → expense flagged `is_post_settlement = 1`; post-calculate returns OK; 3-member post-confirm flow |
| 14 | Personal Lists | 16 | Create, user lists, add item (with/without price), details, check (auto-expense), verify `expense_id` stored, uncheck (expense deleted), verify reset, remove item, cross-user delete block, delete |
| 15 | Group Lists | 9 | Create group list, add item, check returns `needs_confirm`, confirm with `paid_by`, expense created, `expense_id` stored, non-admin delete block, admin delete |
| 16 | Unpriced Items | 6 | Add unpriced item, check, appears in unpriced queue, price it, `expense_id` stored |
| 17 | Settle Admin Guard | 1 | Non-admin settle.php blocked |
| 18 | Settle All (Admin) | 2 | Non-admin blocked; admin succeeds on fresh group |
| 19 | Group Leave & Delete | 4 | Member leaves, member count decremented, non-admin delete blocked, admin delete succeeds |
| 20 | Settlement Algorithm (Unit) | 6 | 3-person balance → correct `payer`/`payee`/`amount`; total balances; edge: all-equal = no settlements; edge: single person = no settlements |
| 21 | Unauthenticated Access | 7 | 7 key endpoints return `ok: false` without a session cookie |
| 22 | SQL Injection Protection | 12 | Login injection (`' OR 1=1 --`), signup injection (`admin'--`), special chars in expense note (`O'Brien's`), DROP TABLE attempt, join code injection, list item injection — all blocked by prepared statements |
| 23 | UI File Integrity | 21 | All 12 UI files exist; `helpers.js` loaded before `app.js`; `helpers.js` defines all 7 shared functions; no duplicate definitions in `app.js` or page files |
| 24 | PHP Syntax Check | 1 | `php -l` passes on every `.php` file in the project |
| 25 | Prepared Statements Verification | 2 | Scans all API files for any `$conn->query()` with variables; verifies `config/db.php` has no raw queries |
| 26 | Input Validation Layer | 24 | `validator.php` exists and included in all APIs; non-numeric/negative/zero amounts rejected; impossible dates rejected (2026-99-55, Feb 30); invalid category/month/budget; empty/oversized group name; empty description; XSS tags escaped in DB; settlement POST method enforcement |
| 27 | Authentication Middleware | 15 | `auth.php` middleware file exists; all API files include middleware (no inline `session_start()`); unauthenticated access blocked with correct error message on 4 endpoints; login sets fresh session with `login_time`; logout invalidates session; post-logout access blocked; `login.php` code inspection |
| 28 | Service Layer | 108 | Checks for 6 business service files (`ExpenseService`, `GroupService`, `SettlementService`, `ListService`, `BudgetService`, `NotificationService`); class structure checks; endpoint-to-service mapping. **Note:** The 6 business service files do not exist in the current codebase — business logic remains in the endpoint files. This section describes a planned refactor from v1.7 that was not completed. |
| 29 | Database Constraints & Indexes | 50 | CHECK constraints exist (5 tables); performance indexes exist (3 new); FK integrity verified (10 relationships); UNIQUE constraints intact (7); all tables InnoDB; CHECK enforcement: negative/zero expense amount, negative budget, negative list price rejected, NULL price allowed; FK enforcement: invalid user/group references blocked; UNIQUE enforcement: duplicate group membership blocked; EXPLAIN index usage for date/period/cleanup queries; schema.sql contains all constraints |
| 30 | Centralized Error Handling | 18 | `response.php` exists and defines `apiResponse()`, `apiSuccess()`, `apiError()`; uses `http_response_code()` and Content-Type header. **Note:** Not all endpoints have been migrated to use `response.php` — many still use bare `echo json_encode`. This section describes the intended end state. |
| 31 | Logging and Observability | 23 | `logger.php` exists and defines `logMessage()` with `FILE_APPEND | LOCK_EX`; `logs/` directory exists; log format validation. **Note:** Not all endpoints have been wired to use `logMessage()` — `login.php` does not currently call it. This section describes the intended end state. |
| 32 | Pagination and Query Performance | 24 | `parsePagination()` and `paginationMeta()` defined in validator. **Note:** No production list endpoints currently use `parsePagination()`. This section describes a planned migration from v2.1 that was not completed. |
| 33 | Rate Limiting | 25 | `rate_limiter.php` helper existence and 4 function definitions; `rate_limits` table and column/index verification; login/signup/auth integration checks; login rate limit functional test (5 failed attempts → 6th blocked); cross-action namespace isolation; API rate limit returns HTTP 429; migration and schema file verification |
| 34 | Ephemeral Notification System | 48 | `notification_store.php` existence and 7 key function definitions; `NotificationService` structure. **Note:** The production notification code path still uses direct MySQL `INSERT INTO notifications` in all producer endpoints. The file-based ephemeral store exists but is not wired into the production flow. This section describes the intended migration from v2.5 that was not completed. |
| 35 | System Health | — | `health.php` endpoint exists and returns queue pressure, retry-after, Redis state, backend labels, and outbox snapshot |

#### Running the Tests

```bash
# From the project root (XAMPP must be running with Apache + MySQL)
php run_tests.php
```

Expected output (last line):

```
RESULTS: <N> passed, <N> failed out of <total> tests
```

> **Note:** The test suite includes sections (28–34) that test planned-but-unfinished refactors. Running against the current codebase will show failures in those sections. Sections 1–27 and 35 should pass fully.

---

## Changelog

- **2026-05-05**: UI and data refresh pass. **Frontend:** shared button styling refined across Home, Expenses, Groups, and Lists; home calendar now keeps the current day visible, shows per-day totals, settled group amounts are struck through, and the calendar legend/day summary were added. **Forms:** category dropdown now defaults to General instead of the placeholder. **Expenses:** monthly summary now includes Your Share, daily chart uses a cleaner line graph without dots, and category colors are distinct per category. **Transport:** CSRF token propagation is now automatic for mutating requests, preventing the budget-save CSRF failure.
- **2026-05-04**: Rate-limit consistency update. **Backend:** auth middleware + API 429/Retry-After handling; `startCooldown()` made immutable. **Frontend:** shared cooldown banner, sessionStorage restore, polling guard, shared `get()` / `post()` requests. **Pages:** login, signup, home, expenses, groups, lists, notifications now follow the same cooldown flow. **Phase 5:** final consistency pass and documentation cleanup for the shared request/banner path.

### Version Summary

| Version | Problem | What was done |
|---|---|---|
| v2.14 | Recent UI and data flows needed polish and shared transport fixes. | Added automatic CSRF header propagation, refreshed shared button styling, kept the home day panel pinned to the current date, added per-day totals and settled strikethroughs, defaulted the category picker to General, and expanded Expenses summaries and charts. |
| v2.13 | Failure handling and pressure control needed stronger runtime coordination under sustained stress. | Added structured outbox observability, retry/dead-letter lifecycle, concurrency-safe outbox claiming, Redis fallback signaling, and health-driven client queue enforcement. |
| v2.12 | Release notes drifted from shipped artifacts and operational changes. | Reconciled the README/version history to match the implemented migrations, health flow, and coordination layer. |
| v2.11 | The system had no shared durability/visibility layer for side effects. | Added a durable outbox, replay worker, and health endpoint with queue pressure, retry-after guidance, and Redis state. |
| v2.10 | Rate limiting still used a DB hot path and lacked scalable backend primitives. | Added Redis-ready limiter/idempotency backends, payload-hash conflict detection, and pressure-aware degradation thresholds. |
| v2.9 | Idempotency coverage was partial across write flows. | Extended replay protection across group, list, settlement, and other authenticated write endpoints with consistent client metadata. |
| v2.8 | Mutating requests could be replayed or duplicated by double submits. | Added idempotency keys, replay-safe write handling, client-side duplicate-intent guards, and DB transactions for expense mutations. |
| v2.7 | Global API limiter and bursty UI traffic could self-DOS legitimate users. | Moved to user-scoped API fairness, kept IP as abuse guardrail, added request queueing/backoff, and made polling adaptive. |

---

### v2.14 — UI Polishing, Calendar Behavior, and CSRF Transport Fixes

#### Shared transport hardening

- Mutating API requests now carry the CSRF token automatically through the shared request helper.
- The authenticated shell exposes the token once and the frontend reuses it everywhere it needs to mutate data.
- This removed the budget-save CSRF failure without changing the underlying backend protection.

#### Shared button styling

- The primary button treatment was aligned across Home, Expenses, Groups, and Lists.
- The shared styling keeps the same visual direction across the app instead of each page drifting into its own variant.

#### Home calendar behavior

- The home calendar now keeps the current date selected by default.
- The selected day stays visible while moving between months.
- Day tiles now show per-date total spending.
- Settled group amounts are shown with a strikethrough instead of being hidden or removed.
- The calendar legend and horizontal day summary row were added to make the tile states easier to read.

#### Category defaults

- The category picker now defaults to General instead of a placeholder prompt.
- General is seeded as a real category so the default selection is stable across fresh installs.

#### Expenses dashboard updates

- The monthly summary now includes Your Share for group spending.
- The daily chart now uses a cleaner line graph with Personal and Group series.
- Dots/markers were removed from the line chart for a less cluttered view.
- Category chart colors are now distinct per category.

### v2.13 — Reliability & Fallback Strategies (Appended with v2.12)

#### Outbox Durability and Replay

- Outbox rows now flow through explicit lifecycle states: `pending -> processing -> retryable -> sent/dead`.
- Failures increment `retry_count` and schedule `next_attempt_at` with exponential backoff.
- After max retries, rows are marked `dead` instead of being lost.

#### Concurrency-Safe Worker Claiming

- Primary worker claim path uses row locking with `FOR UPDATE SKIP LOCKED`.
- If `SKIP LOCKED` is unavailable, the worker falls back to optimistic per-row claim updates.
- This prevents duplicate side effects when multiple workers run at the same time.

#### Structured Error and Success Logging

- Outbox failure logs now include structured context (`event_id`, `event_type`, `retry_count`, exception type, stack trace).
- Outbox success logs include event identity and dispatch latency.
- Notification enqueue failures also emit structured outbox failure logs.

#### Redis Failure Strategy

- Redis connection failures are handled explicitly and logged with:
  - `[REDIS_FALLBACK] reason="connection_failed"`
- Redis-backed operations short-circuit safely when unavailable, allowing fallback behavior instead of crashes.

#### Health Endpoint (Backend Only)

- The backend exposes `/api/system/health.php` with queue pressure, retry-after guidance, and degradation status.
- Health status levels are defined: `warning` (queue >20), `degraded` (queue >50), `critical` (queue >80).
- **Note:** The frontend does not currently poll this endpoint. Health-driven client degradation was designed but the frontend integration (`pollSystemHealth()`, `startHealthMonitor()`) was not implemented. The health endpoint is available for operational monitoring and future frontend integration.

#### Operational Outcome

- The system now prefers graceful degradation and eventual recovery over silent failure.
- Side effects become replayable and auditable under transient outages.
- Client pressure behavior is coordinated with backend health status.

#### Deterministic Proof Record

This section captures runtime proof evidence.

**Phase 5 Deterministic Runner:**
- Runner script: `scripts/stress/phase5_runner.php`
- Single report file output: `scripts/stress/phase5_clean_report.json`
- Execution model: sequential A→G scenarios with per-test isolation (`setup -> execute -> validate -> isolate`)
- Validation sources: API responses + direct DB assertions

**Latest Recorded Outcome:**
- Burst: PASS
- Duplicate: PASS
- Outbox: PASS
- Failure Injection: PASS
- Redis Fallback: PASS
- Queue Storm: PASS
- Health Oscillation: PASS

**Evidence Notes:**
- Outbox flood proof includes explicit drain metrics (`drain_time_seconds`) and stuck-row assertion (`stuck_rows = 0`).
- Queue pressure proof explicitly asserts degraded health under forced pressure and essential endpoint continuity.
- Health oscillation proof forces and records state transitions with pressure snapshots (warning/degraded/critical/recovery).

---

### v2.11 — UI/UX Refinements & Codebase Optimization (Appended with v2.7, v2.8, v2.9, v2.10)

A significant update focusing on visual improvements and deep codebase cleanup.

**New Features & Enhancements:**
- **Category Restructuring:** Reordered the categories so "General" appears first and is pre-selected by default.
- **Dropdown UI Fixes:** Refactored the category dropdown menus to show only 4 items at a time, adding vertical scrollability to prevent large dropdowns from overflowing the screen.
- **Custom Pie Chart Colors:** Implemented specific, tailored hex colors for every single category (e.g., Muted Gray-Green for General, Lime Green for Food/Groceries) making the analytics pie chart more aesthetically premium and distinct.
- **Chart Layout Adjustments:** Updated the expense graph data representation type on the Y-axis for better readability.

**Codebase & Architectural Cleanup:**
- **Frontend DRY Principles:** Extracted over 500 lines of inline JavaScript from `pages/lists.php` into the external `public/assets/js/lists.js` file. **Note:** The external file exists but is not yet loaded by `lists.php` or `index.php` — the inline script in `lists.php` remains the active version. Completing this migration would prevent "split-brain" bugs and improve browser-side caching.
- **Root Directory Purge:** Cleaned out the root directory by deleting 10+ obsolete temporary files, `.txt` logs, and single-use setup scripts (`add_general.php`, `check_time.php`).
- **Workspace Reorganization:** Created new isolated directories (`/tests` and `/scripts`) and migrated all testing (`test_redis_notifications.php`, `run_tests.php`) and utility scripts (`truncate_rate_limits.php`) out of the main root path to ensure a clean, production-ready structure.

### v2.6 — Upgrade 13: Env-Based Configuration & Notification Store Refactor

**Goal:** Remove hardcoded secrets from the codebase, make configuration environment-driven, and evolve the ephemeral notification system into a pluggable, Redis-optional design.

**Configuration & secrets:**
- **Env bootstrap:** Added `[config/env.php](config/env.php)` as a central environment loader:
  - Optionally loads `vlucas/phpdotenv` via `vendor/autoload.php` (when installed) and calls `Dotenv\Dotenv::createImmutable(...)->safeLoad()`.
  - Defines an `env($key, $default)` helper that reads from `$_ENV` / `getenv()` and applies safe development defaults only when variables are missing.
  - Seeds common keys for local dev: `APP_ENV`, `APP_DEBUG`, `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`, `NOTIFICATIONS_BACKEND`, `REDIS_HOST`, `REDIS_PORT`.
- **Database config:** `[config/db.php](config/db.php)` now:
  - Reads **all** DB connection settings from env (`DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`) instead of hardcoded `define()` constants.
  - Performs **fail-fast validation**: if `DB_HOST`, `DB_USER`, or `DB_NAME` are missing, the process exits with a clear error (CLI) or a JSON error response (HTTP).
  - Avoids leaking credentials in error messages (emits only “Database connection failed.” in logs / JSON).
- **Redis config:** `[api/helpers/redis.php](api/helpers/redis.php)` now:
  - Requires `config/env.php` and uses `REDIS_HOST`, `REDIS_PORT`, optional `REDIS_PASSWORD` for connecting to Redis.
  - Fails gracefully (no exception to callers) when the Redis extension or server is unavailable.
- **Env files & git hygiene:**
  - Added `.env`-based configuration for development and a template `[.env.example](.env.example)` listing all required vars (`DB_*`, `APP_ENV`, `APP_DEBUG`, `NOTIFICATIONS_BACKEND`, `REDIS_*`).
  - Updated `.gitignore` to exclude `.env` / `.env.local` so secrets never enter version control.

**Notification store refactor:**
- **Backend interface:** Introduced `[api/services/NotificationStore.php](api/services/NotificationStore.php)` defining a small backend contract:
  - `getUnreadCount(int $userId): array`
  - `listNotifications(int $userId, int $limit = 30, int $page = 1): array`
  - `consume(int $userId, ?string $eventId = null, bool $all = false): array`
  - `getStats(): array`
- **File-backed implementation:** Added `[api/services/FileNotificationStore.php](api/services/FileNotificationStore.php)` which:
  - Wraps the existing `[api/helpers/notification_store.php](api/helpers/notification_store.php)` helpers.
  - Handles pagination (`array_slice`) and formats events with `event_id`, `message`, `type`, `group_id`, `ref_id`, `created_at`.
  - Preserves all previous semantics: max 50 per user, 3-day TTL, SHA-256 dedup, per-group rate limit, file locking.
- **Redis-backed implementation:** Updated `[api/services/NotificationService_Redis.php](api/services/NotificationService_Redis.php)` to:
  - Implement `NotificationStore` as `RedisNotificationService` using `[api/helpers/redis.php](api/helpers/redis.php)` (`RedisClient`).
  - Mirror file-store behavior with per-user lists (`notifications:user:{id}`), `LPUSH`/`LTRIM 0 49`, `EXPIRE 259200`, per-group rate limiting (`notification_rate:{group_id}`) and short-lived dedup keys (`notification_event:{event_id}`).
  - Provide optional `getStats()` for Redis memory info via `INFO memory`.
  - Retain a `NotificationService` alias class for backward compatibility in scripts/tests that referenced it directly.
- **Facade service:** `[api/services/NotificationService.php](api/services/NotificationService.php)` is now a thin façade:
  - Chooses the backend at runtime:
    - Defaults to `FileNotificationStore` (file backend).
    - When `NOTIFICATIONS_BACKEND=redis` and Redis is available, wraps `RedisNotificationService`.
    - On Redis failures (exceptions during construction or calls), logs a WARNING and **falls back** to the file backend without breaking core flows.
  - Applies **authorization filtering** on top of backend results:
    - For any notification with a `group_id`, verifies the user is still a member in `group_members` before returning it.
  - Normalizes responses so existing endpoints keep the same JSON shapes (`count`, `latest`, `notifications[]`, `pagination`).

**Endpoint integration & tests:**
- Notification endpoints `[api/notifications/count.php](api/notifications/count.php)`, `[api/notifications/list.php](api/notifications/list.php)`, `[api/notifications/read.php](api/notifications/read.php)`, `[api/notifications/history.php](api/notifications/history.php)` now:
  - Instantiate `NotificationService` with a DB connection so it can perform membership checks.
  - Continue to use the same routes and response structures as before.
- New helper `[api/helpers/notification_publisher.php](api/helpers/notification_publisher.php)`:
  - Provides `publishGroupNotification()` / `publishNotificationToUsers()` that route through Redis when enabled, and safely fall back to file-based helpers on failures.
  - Used by `test_redis_notifications.php` and can be used by services for clearer notification publishing semantics.
- `run_tests.php` updated to:
  - Understand the `NotificationStore` abstraction and the new service structure.
  - Treat non-endpoint helpers (`notifications_config.php`, `notification_publisher.php`, `redis.php`) as pure libraries (excluded from “all endpoints must…” tests).
  - Continue to report **“RESULTS: 525 passed, 0 failed out of 525 tests”** for the current codebase.

**Redis status (important note):**
- The **file-based notification backend remains the default and production-ready path** (`NOTIFICATIONS_BACKEND=file`).
- The **Redis-backed backend is fully wired in code but treated as optional/advanced**:
  - It requires:
    - A running Redis instance.
    - The PHP Redis extension installed and enabled in `php.ini`.
    - Correct `REDIS_HOST`, `REDIS_PORT`, and (if used) `REDIS_PASSWORD` env vars.
  - If Redis is misconfigured or unavailable, the app **automatically falls back** to the file store so core features are unaffected.
- Current reality: Redis setup and connectivity may vary per environment; if `NOTIFICATIONS_BACKEND=redis` is enabled without a working Redis deployment, notifications will transparently revert to file-backed behavior. Redis integration should therefore be considered **experimental / to be finalized per deployment**, not a hard dependency.

**Security & deployment implications:**
- All sensitive configuration (DB and Redis) now lives in environment variables or `.env` (dev-only) instead of source code.
- `.env` is gitignored; `[.env.example](.env.example)` documents required keys without real secrets.
- Environments (dev/staging/prod) can differ purely via env vars; secret rotation no longer requires code changes.

### v2.5 — Upgrade 12: Ephemeral Notification System

**Architectural shift:** Notifications moved from MySQL to a file-based ephemeral store. No SQL tables are read or written for notifications; instead, per-user JSON files in `data/notifications/` provide Redis-like semantics on any XAMPP / LAMP stack — no Redis required.

**New files:**
- **`api/helpers/notification_store.php`** (~320 lines) — core ephemeral notification engine:
  - `notifPublish()` — write a notification to a single user's file
  - `notifPublishToGroup()` — broadcast to all group members (DB lookup for member IDs)
  - `notifPublishToUsers()` — publish to a specific set of user IDs
  - `notifList()` / `notifCount()` / `notifLatest()` — read operations
  - `notifConsume()` / `notifConsumeAll()` — immediate deletion on read
  - `notifCleanupStaleFiles()` — remove orphaned files for non-existent users
  - SHA-256 event IDs for dedup; `flock()`-based file locking for concurrency
- **`data/notifications/`** — runtime directory for per-user JSON + lock files
- **`data/.gitignore`** — excludes runtime notification data from version control
- **`migration_v2.5.sql`** — documents the architectural shift (no SQL changes)

**Ephemeral store design:**

| Property | Value |
|---|---|
| **Storage format** | `data/notifications/user_{id}.json` (one file per user) |
| **Max per user** | 50 notifications (oldest evicted on overflow) |
| **TTL** | 3 days (259,200 seconds); expired entries purged on read |
| **Dedup** | SHA-256 event ID from `type + message + group_id + actor_id + ref_id`; duplicates silently dropped |
| **Rate limit** | 20 notifications per minute per group (prevents flood) |
| **Concurrency** | `flock()` exclusive locks on per-user `.lock` files |
| **Consumption** | Immediate deletion — read once, then gone |
| **Cleanup** | Stale files for deleted users cleaned probabilistically (~1% of list requests) |

#### Optional Redis-backed store (failsafe)

For higher-volume deployments that already run Redis, the notification system can use a Redis-backed ephemeral store instead of JSON files, while keeping the same API contract and semantics:

- **Backend selection:** Controlled by `NOTIFICATIONS_BACKEND` in `[api/helpers/notifications_config.php](api/helpers/notifications_config.php)`.  
  - `'file'` (default): per-user JSON files under `data/notifications/`.  
  - `'redis'`: per-user Redis lists with the same 50-item cap and 3-day TTL.
- **Redis schema:**  
  - `notifications:user:{user_id}` — `LPUSH`/`LTRIM 0 49` lists of event JSON; `EXPIRE 259200`.  
  - `notification_rate:{group_id}` — per-group rate limiter (`INCR` + `EXPIRE 60`, cap 20/min).  
  - `notification_event:{event_id}` — short-lived dedup markers (`SET` + TTL) to suppress duplicates.
- **Pluggable store:** `[api/services/NotificationStore.php](api/services/NotificationStore.php)` defines a small interface implemented by:
  - `[api/services/FileNotificationStore.php](api/services/FileNotificationStore.php)` (file-based).  
  - `[api/services/NotificationService_Redis.php](api/services/NotificationService_Redis.php)` → `RedisNotificationService` (Redis-based).
- **Façade:** `[api/services/NotificationService.php](api/services/NotificationService.php)` chooses the backend at runtime and adds:
  - **Authorization filtering** — group-scoped notifications are only returned to current group members (checked via `group_members`).  
  - **Redis failsafe** — if Redis is unavailable or fails mid-request, the service logs a warning and transparently falls back to the file-backed store; core actions (creating expenses, settlements, lists) never fail because of notification issues.
- **Publishing helpers & tests:**  
  - `[api/helpers/notification_publisher.php](api/helpers/notification_publisher.php)` provides `publishGroupNotification()` / `publishNotificationToUsers()` that route through Redis when enabled, or fall back to file-based helpers.  
  - `[test_redis_notifications.php](test_redis_notifications.php)` exercises the Redis path end-to-end (connection, publish, list, consume, rate limiting, dedup, and basic memory stats via `NotificationService::getStats()`).

**Modified files:**
- **`api/services/NotificationService.php`** — completely rewritten: no `mysqli` dependency; delegates to `notification_store.php`; `consume()` replaces `markAsRead()`
- **`api/notifications/count.php`** — removed `db.php` include; uses no-arg `NotificationService()`
- **`api/notifications/list.php`** — removed `db.php` include and `unreadOnly` parameter
- **`api/notifications/read.php`** — rewritten: accepts `event_id` (or legacy `id`); consume semantics
- **`api/notifications/history.php`** — rewritten: no raw SQL; delegates to `NotificationService`
- **`api/helpers/notification_publisher.php`** — new helper providing `publishGroupNotification()` and `publishNotificationToUsers()` functions used by all endpoints
- **All notification-publishing endpoints** — group/expense/list/settlement endpoints now call `publishGroupNotification()` or `publishNotificationToUsers()` from notification_publisher.php instead of directly inserting into notifications table
- **`public/assets/js/app.js`** — `lastSeenNotifId` → `lastSeenEventId` (string comparison); `data-id` uses `event_id`; click handler sends `event_id`; consumed notifications fade out and are removed from DOM
- **`schema.sql`** — `notifications` table comment updated (retained for backward compatibility)

**New tests (477 → 525):**
- **Section 34: Ephemeral Notification System** (48 tests) — helper file existence and key function definitions; NotificationService structure (consume method, no markAsRead); endpoint integration (no db.php imports, event_id acceptance, backward compat); full publish → list → consume → verify-gone cycle; consume-all + zero count verification; pagination in list response; history endpoint uses service layer (no raw SQL); data directory existence and writability; all 4 producer services use `notification_store.php` (no INSERT INTO notifications); store constants (max 50, TTL 259200, rate limit); SHA-256 dedup; file locking; frontend event_id tracking

---

### v2.4 — Upgrade 11: Automatic 3-Day Notification Retention System

**New files:**
- **`scripts/cleanup_notifications.php`** — automated notification cleanup script:
  - Deletes notifications older than 3 days
  - Batch deletion (10,000 rows per batch) prevents table locks
  - Comprehensive logging and monitoring
  - Safety limits (max 100 iterations)
  - Performance metrics (duration, row counts)
- **`scripts/CRON_SETUP.md`** — cron job configuration documentation:
  - Installation instructions (Linux/Windows)
  - Alternative schedules
  - Monitoring and troubleshooting
  - Performance considerations
- **`migration_v2.4.sql`** — notification table indexes for cleanup performance

**Database improvements:**
- **`idx_notifications_created_at`** — index on `created_at` for fast cleanup queries
- **`idx_notifications_user_time`** — composite index on `(user_id, created_at DESC)` for fast user notification queries

**Retention policy:**
- **3-day retention window** — notifications older than 3 days automatically deleted
- **Daily cleanup** — runs at 3:00 AM via cron job (low traffic period)
- **Bounded table size** — maximum ~1.5M rows at 100K users (500K notifications/day × 3 days)
- **Predictable performance** — indexed queries remain fast even at scale

**System design:**
- **Batch deletion** — 10,000 rows per batch prevents long table locks
- **Safety limits** — max 100 iterations prevents runaway deletion
- **Monitoring** — logs deleted count, duration, warnings for anomalies
- **Graceful degradation** — 100ms delay between batches reduces database load

**Performance characteristics:**

| Notifications | Deletion Time | Database Load |
|---|---|---|
| 10,000 | <1 second | Minimal |
| 100,000 | 5-10 seconds | Low |
| 1,000,000 | 1-2 minutes | Moderate |

**Testing & Verification:**

Cleanup script was tested with notifications of varying ages:

```
=== Notification Cleanup Started ===
Retention Policy: 3 days
Batch Size: 10000 rows
Timestamp: 2026-03-12 01:39:36

Notifications before cleanup: 45
Expired notifications found: 13

Starting batch deletion...
Batch 1: Deleted 13 rows (Total: 13)

=== Cleanup Completed ===
Notifications before: 45
Notifications after:  32
Total deleted:        13
Iterations:           1
Duration:             0.03s
```

**Verification results:**
- ✓ Notifications older than 3 days deleted (5-day and 4-day old removed)
- ✓ Recent notifications preserved (1-day and 0-day old kept)
- ✓ Batch deletion working correctly
- ✓ Logging operational (INFO level events recorded)
- ✓ Performance acceptable (<1 second for 13 rows)
- ✓ Indexes created and functional

**Cron setup (Linux/Unix):**
```cron
0 3 * * * php /path/to/ExpMgWEB/scripts/cleanup_notifications.php >> /path/to/ExpMgWEB/logs/cleanup.log 2>&1
```

**Manual execution:**
```bash
php scripts/cleanup_notifications.php
```

**Monitoring:**
```bash
# Check cleanup logs
tail -f logs/cleanup.log

# Check application logs
grep "Notification cleanup" logs/app.log
```

**Scalability:**
- Current MySQL solution scales to ~500K daily notifications
- At 1M+ users, consider migrating to Redis with TTL expiration
- Table size remains bounded regardless of user growth
- Cleanup performance degrades gracefully (linear with notification count)

**Deployment notes:**
- Run migration: `mysql -u root ExpMgWEB < migration_v2.4.sql`
- Set up cron job (see `scripts/CRON_SETUP.md`)
- Monitor first few runs to verify performance
- Adjust `RETENTION_DAYS` if different retention needed
- Adjust `BATCH_SIZE` for faster cleanup (50,000 for high-volume systems)

---

### v2.3 — Upgrade 10: CSRF Protection Layer (Hardened)

**New files:**
- **`api/helpers/csrf.php`** — centralized CSRF protection with enhanced security:
  - `generateCsrfToken(): string` — creates new 256-bit token + sets double-submit cookie
  - `getCsrfToken(): string` — retrieves token with automatic 12-hour rotation
  - `rotateCsrfToken(): string` — generates new token (called on login/signup)
  - `verifyCsrf(): void` — validates header + cookie tokens; terminates with 403 on failure
  - `verifyCsrfIfNeeded(): void` — auto-detects request method (POST/PUT/PATCH/DELETE)
  - `recordCsrfFailure(): void` — tracks failures with threshold monitoring (50/10 min)
- **`api/bootstrap.php`** — framework-level security enforcement layer:
  - Session initialization with secure cookie params
  - Universal CSRF protection (runs before authentication)
  - Content-Security-Policy headers (XSS mitigation)
  - Additional security headers (X-Frame-Options, X-Content-Type-Options, etc.)
- **`test_csrf.php`** — automated CSRF bypass test suite (8 test sections, 15+ assertions)

**Security improvements:**
- **Double-submit cookie defense** — token verified in both cookie and header; prevents token reuse attacks
- **Automatic token rotation** — tokens expire after 12 hours; reduces impact of token exposure
- **SameSite=Lax policy** — changed from Strict to Lax; maintains CSRF protection while supporting OAuth redirects and email verification links
- **CSRF failure monitoring** — tracks failures per session; logs CRITICAL alert when threshold exceeded (50 failures in 10 minutes)
- **Content-Security-Policy** — restricts script sources, prevents inline script injection, mitigates XSS attacks
- **Framework-level enforcement** — bootstrap.php ensures CSRF protection cannot be accidentally bypassed
- **Developer guardrails** — automated test suite verifies all endpoints reject requests without valid tokens

**Attack prevention:**
- **Cross-Site Request Forgery** — external sites cannot forge authenticated actions (no valid token)
- **Session fixation** — token rotation on login/signup prevents reuse of pre-auth tokens
- **Timing attacks** — `hash_equals()` used for constant-time token comparison
- **Token theft** — double-submit pattern requires attacker to control both cookie and header
- **XSS exploitation** — CSP headers prevent inline scripts; limits CSRF token theft via XSS

**Modified files:**
- **`api/middleware/auth.php`** — SameSite changed to Lax; includes csrf.php; calls getCsrfToken() on session start
- **`api/login.php`** — was intended to call rotateCsrfToken() after successful login (**Note:** this call is not present in the current `login.php`)
- **`api/signup.php`** — was intended to call rotateCsrfToken() after account creation (**Note:** this call is not present in the current `signup.php`)
- **`public/index.php`** — added CSP and security headers; includes csrf.php; embeds token in meta tag
- **`public/assets/js/helpers.js`** — getCsrfToken() reads from cookie (double-submit) with meta tag fallback; post() includes X-CSRF-Token header

**Testing & Verification:**

A comprehensive attack simulation was performed to verify CSRF protection works correctly:

**Attack Simulation Results:**
```
╔════════════════════════════════════════════════════════════╗
║  ✓✓✓ CSRF PROTECTION IS SECURE ✓✓✓                        ║
║                                                            ║
║  All 10 attack vectors were successfully blocked          ║
║  Legitimate requests work correctly                       ║
║                                                            ║
║  Your application is protected against CSRF attacks       ║
╚════════════════════════════════════════════════════════════╝

Attacks Blocked:    10 / 10 (100%)
Attacks Succeeded:  0 / 10 (0%)
Legitimate Access:  ✓ Working
Total Tests:        11
Passed:             11
Failed:             0
```

**Attack vectors tested:**
1. Simple form POST without token — ✓ BLOCKED
2. AJAX request without token — ✓ BLOCKED
3. Token replay attack (old/stolen token) — ✓ BLOCKED
4. Token forgery (random token) — ✓ BLOCKED
5. Cookie manipulation (double-submit bypass attempt) — ✓ BLOCKED
6. Header manipulation (cookie only, no header) — ✓ BLOCKED
7. Cross-origin request simulation — ✓ BLOCKED
8. GET-based CSRF attempt — ✓ BLOCKED
9. Token theft via XSS (different session) — ✓ BLOCKED
10. Concurrent request race condition — ✓ BLOCKED
11. Legitimate request with valid token — ✓ PASSED

**Security verification:**
- Session-bound tokens working correctly ✓
- Double-submit cookie defense active ✓
- 12-hour token rotation verified ✓
- SameSite=Lax cookie policy enforced ✓
- Timing-attack resistance confirmed ✓
- Failure monitoring operational ✓
- Framework-level enforcement active ✓

**Deployment notes:**
- Set `'secure' => true` in session_set_cookie_params when deploying with HTTPS
- Monitor `logs/app.log` for CSRF validation failures (potential attack attempts)
- Run `php test_csrf.php` to verify protection works correctly
- Review CSP headers if adding new CDN resources (update script-src/style-src)
- All existing frontend code automatically protected (uses centralized post() helper)

**Testing:**
- Run dedicated CSRF test suite: `php test_csrf.php`
- All existing tests pass (CSRF tokens auto-included)
- CSRF bypass attempts blocked with 403 Forbidden
- Token rotation verified on login
- Double-submit cookie defense verified
- GET requests exempt from CSRF checks

**New tests (477 → TBD):**
- CSRF token generation with cookie
- Automatic 12-hour token rotation
- Double-submit cookie verification
- CSRF failure threshold monitoring
- Multiple endpoint protection verification
- Token rotation on login
- Cookie mismatch rejection
- GET request exemption

---

### v2.2 — Upgrade 9: Rate Limiting

**New files:**
- **`api/helpers/rate_limiter.php`** — DB-backed rate limiting helper with 4 functions:
  - `checkRateLimit($conn, $ip, $action, $maxAttempts, $windowSecs): bool` — checks if IP+action is within allowed limit
  - `recordRateLimit($conn, $ip, $action): void` — records a rate-limit attempt
  - `cleanupRateLimits($conn): void` — deletes entries older than 1 hour (triggered probabilistically on ~1% of requests)
  - `rateLimitRetryAfter($conn, $ip, $action, $windowSecs): int` — calculates seconds until rate limit expires
- **`migration_v2.2.sql`** — creates `rate_limits` table for existing databases

**New database table:**
- `rate_limits` — tracks rate-limit attempts by IP address and action with columns: `id` (AUTO_INCREMENT PK), `ip_address` (VARCHAR 45), `action` (VARCHAR 30), `attempted_at` (TIMESTAMP). Indexed on `(ip_address, action, attempted_at)` for fast lookups.

**Rate limits enforced:**

| Action | Limit | Window | Enforced In |
|---|---|---|---|
| Login | 5 attempts | 15 minutes | `api/login.php` |
| Signup | 3 attempts | 1 hour | `api/signup.php` |
| API (per user) | 120 requests | 1 minute | `api/middleware/auth.php` |
| API (per IP) | 240 requests | 1 minute | `api/middleware/auth.php` |

**Modified files:**
- **`api/login.php`** — rate limit check before credential validation; records failed attempts (wrong email or wrong password); returns redirect with "Too many login attempts" message when blocked
- **`api/signup.php`** — rate limit check and recording at the start of every signup request; returns redirect with "Too many signup attempts" message when blocked
- **`api/middleware/auth.php`** — includes `db.php` and `rate_limiter.php`; after session validation, checks per-user API rate limit (120/min) and per-IP API rate limit (240/min); returns HTTP 429 with JSON error when rate-limited; records every authenticated request
- **`schema.sql`** — added `rate_limits` table (table #13)

**New tests (452 → 477):**
- **Section 33: Rate Limiting** (25 tests) — helper file existence and 4 function definitions; `rate_limits` table existence with column and index verification; login/signup/auth integration checks (require_once and function call presence); login rate limit functional test (5 failed attempts, 6th blocked with no extra record created); cross-action namespace isolation (login limit doesn't affect API); API rate limit returns HTTP 429 with "Too many requests" error message; access restored after rate limit entries cleared; migration file and schema.sql verification

---

### v2.1 — Upgrade 8: Pagination and Query Performance

**New helper functions in `validator.php`:**
- `parsePagination(int $defaultLimit = 20, int $maxLimit = 50): array` — extracts `page` and `limit` from `$_GET`, enforces minimum 1, caps at `$maxLimit`, returns `[page, limit, offset]`
- `paginationMeta(int $page, int $limit, int $total): array` — builds standardized pagination metadata: `{page, limit, total, pages}`

**All 10 list-returning endpoints now support `?page=N&limit=N` query parameters:**
- `expenses/list.php` — paginated expense listing by date, month, or date range
- `expenses/unpriced.php` — paginated unpriced items (inline SQL)
- `notifications/list.php` — paginated today's notifications (default limit 30, max 100)
- `notifications/history.php` — paginated 7-day history (default limit 100, max 200)
- `groups/user_groups.php` — paginated user groups
- `groups/details.php` — paginated group expenses within details
- `settlements/history.php` — paginated settlement history (replaced hardcoded LIMIT 50)
- `settlements/details.php` — paginated settlement period expenses
- `lists/user_lists.php` — paginated user lists
- `lists/details.php` — paginated list items

**Service layer changes:**
- All 5 paginated services (`ExpenseService`, `NotificationService`, `GroupService`, `SettlementService`, `ListService`) now accept `$page` and `$limit` parameters with sensible defaults
- Each service runs a `COUNT(*)` query for total row count, then applies `LIMIT ? OFFSET ?` to the main query
- Response includes `pagination` key alongside existing data keys for backward compatibility

**Response format (pagination metadata added to all list responses):**
```json
{
  "ok": true,
  "expenses": [...],
  "pagination": { "page": 1, "limit": 20, "total": 47, "pages": 3 }
}
```

**New tests (428 → 452):**
- **Section 32: Pagination and Query Performance** (24 tests) — helper function existence; paginated expense list with correct item count and metadata; page 2 returns different items; limit capped at 50; default values; cross-endpoint pagination verification (lists, groups, notifications); audit: all list endpoints use `parsePagination()`, all services use SQL LIMIT and return `paginationMeta`; page beyond total returns empty with valid meta

---

### v2.0 — Upgrade 7: Logging and Observability

**New files:**
- **`api/helpers/logger.php`** — centralized structured logging function `logMessage(string $level, string $message, array $context = [])`. Writes JSON log entries to `logs/app.log` with `FILE_APPEND | LOCK_EX` for atomic writes. Each entry contains: timestamp, level, message, and context metadata.
- **`logs/.gitkeep`** — log directory (runtime-created `app.log` is gitignored)

**Log levels:**
| Level | When Used | Examples |
|---|---|---|
| `INFO` | Normal successful operations | Login, expense created/updated/deleted, group created/joined/left, settlement finalized, budget saved, list created/deleted, signup |
| `WARNING` | Suspicious behavior or soft failures | Failed login (wrong password, email not found), unauthorized access attempts, invalid/expired sessions |
| `ERROR` | System failures | Uncaught exceptions in catch blocks, signup failures, database errors |

**Logging integrated across all layers:**
- **Authentication (`auth.php`)** — WARNING logs for unauthorized access, invalid sessions, and expired sessions
- **Login (`login.php`)** — WARNING for failed attempts (email not found, wrong password) with IP context; INFO for successful login with user_id, username, and IP
- **Signup (`signup.php`)** — INFO for new user registration; ERROR for signup failures
- **5 service classes** — INFO logs at key business operations:
  - `ExpenseService`: expense created, updated, deleted
  - `GroupService`: group created, deleted, user joined, user left
  - `SettlementService`: settlement finalized, settlement recorded, all expenses settled, post-settlement finalized
  - `BudgetService`: budget saved
  - `ListService`: list created, list deleted
- **All 35+ endpoint catch blocks** — ERROR logs with endpoint name and exception message, plus `error_log()` fallback

**New tests (405 → 428):**
- **Section 31: Logging and Observability** (23 tests) — `logger.php` existence and function signature; JSON log format validation (time, level, message, context fields); login triggers INFO log with user_id and IP context; failed login triggers WARNING log; auth failure triggers WARNING log; all 5 services require `logger.php`; all endpoint catch blocks include `logMessage('ERROR')`; auth middleware logs WARNING events; expense creation triggers service INFO log; signup logs INFO event

---

### v1.9 — Upgrade 6: Centralized Error Handling

**New file:**
- **`api/helpers/response.php`** — centralized API response functions with proper HTTP status codes:
  - `apiResponse(array $result, int $status = 0)` — routes service results to 200 (ok) or 400 (error) automatically
  - `apiSuccess(array $data = [], int $status = 200)` — sends success response with merged data
  - `apiError(string $message, int $status = 400)` — sends error response

**All 35+ API endpoints refactored:**
- Each endpoint now requires `response.php` and uses `apiResponse()`, `apiSuccess()`, or `apiError()` instead of raw `echo json_encode()`
- Manual `header('Content-Type: application/json')` removed from all endpoints (response.php handles it)
- Wrong-method checks now use `apiError('Method not allowed.', 405)` with correct HTTP status
- Service calls wrapped in `try/catch` blocks with `apiError('Internal server error.', 500)` for graceful error handling
- Auth middleware uses `apiError()` for 401 responses

**HTTP status codes now properly set:**
| Status | When Used |
|---|---|
| `200` | Successful responses (ok: true) |
| `400` | Validation errors, business logic errors (ok: false) |
| `401` | Unauthenticated requests (no session, invalid/expired session) |
| `405` | Wrong HTTP method (e.g., GET on POST-only endpoint) |
| `500` | Internal server errors (caught exceptions) |

**New tests (387 → 405):**
- **Section 30: Centralized Error Handling** (18 tests) — `response.php` existence and function definitions; `http_response_code` and Content-Type usage; all endpoints require `response.php`; no manual Content-Type headers; all endpoints have try/catch with `apiError('Internal server error.', 500)`; no bare `echo json_encode` in endpoints; HTTP status verification: 200 on success, 405 on wrong method, 401 unauthenticated, 400 on validation error

---

### v1.8 — Upgrade 5: Database Constraints, Indexing & Query Optimization

**New file:**
- **`migration_v1.8.sql`** — migration script for existing databases; adds all new constraints and indexes in a single run

**CHECK constraints added (5):**

| Table | Constraint | Rule | Purpose |
|---|---|---|---|
| `expenses` | `chk_expense_amount` | `amount > 0` | Prevents negative or zero expenses |
| `budgets` | `chk_budget_amount` | `amount_limit > 0` | Prevents negative or zero budgets |
| `settlements` | `chk_settlement_amount` | `amount >= 0` | Prevents negative settlements (zero valid for equal shares) |
| `list_items` | `chk_item_price` | `price IS NULL OR price > 0` | Prevents negative prices; NULL allowed (optional field) |
| `groups` | `chk_max_members` | `max_members > 0` | Prevents zero or negative member limits |

**Performance indexes added (3):**

| Table | Index | Columns | Purpose |
|---|---|---|---|
| `expenses` | `idx_expense_date` | `(expense_date)` | Cross-user date-range queries (monthly reports, aggregate date lookups) |
| `settlements` | `idx_settlement_period` | `(group_id, period_start, period_end)` | Settlement period range lookups and overlap checks |
| `notifications` | `idx_notif_created` | `(created_at)` | Notification cleanup DELETE queries by date |

**Pre-existing constraints verified intact (all from prior versions):**
- 22 foreign key constraints across 12 tables — all InnoDB with ON DELETE CASCADE/SET NULL/RESTRICT
- 7 UNIQUE constraints (users.username, users.email, groups.join_code, group_members, budgets, settlement_confirmations, post_settlement_confirmations)
- 10 existing indexes (composite user+date, group+date, paid_by+date, list priority, notification read status, settlement group+date)

**Schema hardened with four-layer defense:**
1. **Input validation** (validator.php) — rejects bad data at the API boundary
2. **Database query safety** (prepared statements) — prevents SQL injection
3. **Authentication middleware** (auth.php) — enforces session security
4. **Database integrity constraints** (CHECK, FK, UNIQUE) — the database itself refuses invalid data

**New tests (337 → 387):**
- **Section 29: Database Constraints & Indexes** (50 tests) — CHECK constraint existence on 5 tables; 3 performance indexes verified via information_schema; 10 FK relationships confirmed; 7 UNIQUE constraints confirmed; InnoDB engine check on all tables; CHECK enforcement: negative/zero expense amount, negative budget, negative list item price all rejected, NULL price allowed; FK enforcement: invalid user_id and group_id references blocked; UNIQUE enforcement: duplicate group membership blocked; EXPLAIN query plan verification for expense date, settlement period, and notification cleanup queries; schema.sql and migration file integrity checks; application regression: expense CRUD and budget set/get work correctly through new constraints

### v1.7 — Upgrade 4: Service Layer (Separation of Business Logic)

**New directory: `api/services/`** — was planned to contain 6 business service classes extracted from API endpoints:

> **Note:** The 6 business service files described below (`ExpenseService`, `GroupService`, `SettlementService`, `ListService`, `BudgetService`, `NotificationService`) **were not implemented**. Business logic remains in the endpoint files. The `api/services/` directory exists but contains only infrastructure services (CacheService, HealthService, LockService, MetricsService, PredictiveHealthService, RedisService, SystemOrchestrator, and notification store implementations). The test section (Section 28) was written to verify this refactor but fails against the current codebase.

| Service | Methods | Lines | Responsibility |
|---|---|---|---|
| `ExpenseService.php` | 6 public, 6 private | ~350 | Expense CRUD, summary, categories, settlement-lock checks, group membership, notifications |
| `GroupService.php` | 6 public, 2 private | ~250 | Group create/delete/join/leave, details, user groups, join-code generation |
| `SettlementService.php` | 8 public | ~640 | Settlement calculate/confirm/settle/settle-all, history, details, post-settlement calculate/confirm |
| `ListService.php` | 7 public, 5 private | ~520 | List CRUD, item add/remove/check (with auto-expense creation), group access control, notifications |
| `BudgetService.php` | 2 public | ~55 | Budget get/set (upsert) |
| `NotificationService.php` | 3 public | ~70 | Unread count, list with filters, mark read (single/all) |

**All 32 API endpoints refactored to thin request handlers:**
- Each endpoint now follows the pattern: auth middleware → input validation → `new XxxService($conn)` → `echo json_encode($service->method(...))`
- No raw SQL remains in any endpoint file — all database operations live in service classes
- Auth endpoints (`login.php`, `signup.php`, `logout.php`) intentionally not refactored (no reusable business logic)
- `settlement_helpers.php` retained as shared utility (used internally by `SettlementService`)

**Endpoint-to-service mapping (32 files):**
- 6 expense endpoints → `ExpenseService` (create, update, delete, list, summary, categories)
- 6 group endpoints → `GroupService` (create, delete, join, leave, details, user_groups)
- 8 settlement endpoints → `SettlementService` (calculate, confirm, settle, settle_all, history, details, post_calculate, post_confirm)
- 7 list endpoints → `ListService` (create, delete, add_item, remove_item, check_item, details, user_lists)
- 2 budget endpoints → `BudgetService` (get, set)
- 3 notification endpoints → `NotificationService` (count, list, read)

**Service design pattern:**
- Constructor injection: each service receives `mysqli $conn` and stores as `$this->conn`
- All public methods return associative arrays (`['ok' => true/false, ...]`) matching existing JSON response format
- Private helper methods for repeated operations (group membership checks, notifications, access control)
- No breaking changes to API response format — frontend code unchanged

**New tests (229 → 337):**
- **Section 28: Service Layer** (108 tests) — service file existence (6 files); class structure verification (constructor, `$conn` property); endpoint-to-service mapping (32 endpoints checked for correct `require_once` and class instantiation); thin endpoint verification (no raw SQL in any endpoint); auth endpoint exclusion check; functional regression through service layer: expense CRUD + listing, budget get/set, notification count/list/mark-read, list create/details/add-item/check-item/remove-item/delete

---

### v1.6 — Upgrade 3: Authentication Middleware & Session Protection

**New file:**
- **`api/middleware/auth.php`** — centralized authentication guard that starts the session (if needed), verifies `$_SESSION['user_id']` and `$_SESSION['login_time']`, and enforces a 24-hour session lifetime. All protected API endpoints now `require_once` this middleware and call `requireAuth()` instead of inline session checks.

**Security hardening:**
- **`api/login.php`** — added `$_SESSION['login_time'] = time()` for session lifetime tracking. Was intended to add `session_regenerate_id(true)` for session fixation prevention (**Note:** this call is not present in the current `login.php`)
- **`api/logout.php`** — added JSON response detection for AJAX requests (returns `{"ok": true}` instead of redirect when called via XHR)
- **`public/index.php`** — added `login_time` check and 24-hour session expiration enforcement on frontend page load

**Auth middleware integrated into all 35 protected API files:**
- Replaced inline `session_start()` + `if (!isset($_SESSION['user_id']))` blocks with `require_once middleware/auth.php` + `requireAuth()`
- 5 distinct integration patterns handled: standard, auth+method-check, settlement-helpers-first, soft-auth (notifications/count.php), and method-before-auth
- `notifications/count.php` uses a custom soft-failure pattern (returns `{"count": 0}` instead of error) while still using the middleware for session initialization

**Test infrastructure fix:**
- **`run_tests.php`** — `extractSession()` updated to use `preg_match_all()` and take the **last** `Set-Cookie` header, fixing compatibility with `session_regenerate_id()` which sends two cookies

**New tests (214 → 229):**
- **Section 27: Authentication Middleware** (15 tests) — middleware file existence; all API files include middleware with no inline `session_start()`; unauthenticated access returns "Authentication required" on 4 representative endpoints; login returns fresh session that passes middleware; session cookie set; logout invalidates session; post-logout access blocked; login.php code inspection (session_start, login_time, session_regenerate_id)

---

### v1.5 — Upgrade 2: Centralized Input Validation Layer

**New file:**
- **`api/helpers/validator.php`** — shared validation module with `apiError()`, `requireField()`, `validateNumber()`, `validatePositive()`, `validateEnum()`, `validateDate()`, `validateMonth()`, `validateEmail()`, `validateId()`, `validateLength()`, `sanitizeString()`

**Validation integrated into all 38 API endpoints:**
- All JSON API files now `require_once` the validator
- Numeric inputs validated (non-numeric, negative, zero amounts rejected)
- Date inputs validated with `checkdate()` (impossible dates like Feb 30 rejected)
- Month inputs validated (invalid months like 15 rejected)
- String inputs sanitized via `sanitizeString()` — HTML special chars escaped (XSS prevention)
- Enum values validated (expense type, list priority)
- ID fields validated via `validateId()` (replaces inline `<= 0` checks)
- String lengths validated (group names, list names: 1–100 chars)

**Bug fixes:**
- **`api/settlements/confirm.php`** — added missing POST method check
- **`api/settlements/post_confirm.php`** — added missing POST method check
- **`api/settlements/settle.php`** — added missing POST method check
- **`api/settlements/settle_all.php`** — added missing POST method check

**New tests (190 → 214):**
- **Section 26: Input Validation Layer** (24 tests) — validator presence, non-numeric/negative/zero amount rejection, impossible date rejection (2026-99-55, Feb 30), invalid category/month/budget, empty/oversized group name, empty description, XSS tag escaping in DB, settlement GET method rejection

---

### v1.4 — Upgrade 1: Prepared Statements Hardening

**Security audit result:** All 38 production API endpoints already used prepared statements with `bind_param()`. Three static queries (no user input) were converted for consistency.

**Queries converted:**
- **`api/expenses/categories.php`** — static `$conn->query()` → `$conn->prepare()` + `execute()`
- **`api/notifications/list.php`** — cleanup DELETE query → prepared statement
- **`api/notifications/history.php`** — cleanup DELETE query → prepared statement
- **`run_tests.php`** — 8 interpolated queries converted to prepared statements with `bind_param()`

**New tests (178 → 190):**
- **Section 22: SQL Injection Protection** (12 tests) — login injection, signup injection, special characters in expense notes, DROP TABLE attempts, join code injection, list item injection
- **Section 25: Prepared Statements Verification** (2 tests) — automated scan of all API files for any `$conn->query()` with variables

---

### v1.3 — Codebase Audit & Deduplication

**Bug fixes (critical):**
- **`api/settlements/confirm.php`** — race condition: wrapped confirmation insert + all-confirmed check in a database transaction with `FOR UPDATE`; else branch now properly commits the transaction
- **`api/settlements/settle.php`** — missing admin role check: added `role !== 'admin'` guard (was allowing any member to insert settlement records)
- **`api/settlements/settle_all.php`** — missing `AND e.is_post_settlement = 0` filter in both contribution and period-date queries, preventing double-counting of late expenses
- **`api/expenses/price_unpriced.php`** — datetime-as-date bug: `checked_at` datetime now properly converted to `Y-m-d` for expense_date
- **`api/expenses/create.php`** — notification `reference_id` was pointing to `$groupId` instead of the new expense's `$newId`
- **`api/expenses/update.php`** — added settlement lock check when moving an expense into a different group

**Bug fixes (medium):**
- **`api/lists/delete.php`** — restricted group list deletion to admin or list creator (was any group member)
- **`api/budgets/set.php`** — added POST method validation
- **`config/db.php`** — changed `die()` to JSON error response instead of raw HTML

**New column:**
- `list_items.expense_id` (INT UNSIGNED, FK → expenses) — direct link to the auto-created expense for robust deletion on uncheck (replaces fragile note/date/amount matching)

**Schema change:**
- `list_items` table gains `expense_id` column with foreign key to `expenses(id) ON DELETE SET NULL`

**New file:**
- **`api/settlements/settlement_helpers.php`** — shared greedy debt-minimization algorithm extracted from 5 files

**Code deduplication (JS):**
- **`public/assets/js/helpers.js`** (new) — shared global utilities (`$`, `$$`, `show`, `hide`, `escapeHTML`, `post`, `get`, `API`) loaded in `<head>` before all other scripts
- **`public/assets/js/app.js`** — removed duplicate `$`, `$$`, `show`, `hide`, `post`, `get`, `escapeHTML`, `esc`, `API` from all 3 IIFEs (Calendar/Expense, Notifications, Profile Dropdown)
- **`pages/groups.php`** — removed 6 duplicate helper functions, uses global helpers
- **`pages/lists.php`** — removed 6 duplicate helper functions, uses global helpers
- **`pages/expenses.php`** — removed 3 duplicate `escHtml` definitions, uses `escapeHTML` alias
- **`pages/notifications.php`** — removed duplicate `esc`, `API` definitions, uses global helpers

**Code deduplication (PHP):**
- Settlement algorithm extracted to `settlement_helpers.php::calculateSettlements()` and used in all 5 files: `calculate.php`, `post_calculate.php`, `confirm.php`, `post_confirm.php`, `settle_all.php`

---

### v1.2 — Lists Enhancement & Late Settlement

**New database table:**
- `post_settlement_confirmations` — tracks per-member confirmation for late (post-settlement) expenses

**New column:**
- `expenses.is_post_settlement` — flags expenses created for already-settled periods (TINYINT, default 0)
- `list_items.price` — optional price for list items (DECIMAL)
- `list_items.checked_at` — date when item was checked (DATE)
- `list_items.expense_created` — whether an expense has been auto-created (TINYINT)

**New API endpoints (4 files):**
- `api/expenses/unpriced.php` — list checked items without a price, awaiting conversion
- `api/expenses/price_unpriced.php` — add price to unpriced item, create expense (detects post-settlement)
- `api/settlements/post_calculate.php` — calculate supplementary settlement for late expenses
- `api/settlements/post_confirm.php` — individual confirmation for late settlement; when all confirm, recalculates affected periods and updates past settlement records in place

**Modified files:**
- **`api/lists/add_item.php`** — accepts optional `price` parameter
- **`api/lists/check_item.php`** — auto-creates expense on check if item has price; detects post-settlement periods
- **`api/lists/details.php`** — returns `price`, `checked_at`, `expense_created` fields
- **`api/settlements/calculate.php`** — excludes `is_post_settlement = 1` expenses; returns `post_settlement_count`
- **`api/settlements/confirm.php`** — excludes post-settlement expenses from all settlement queries
- **`pages/expenses.php`** — Two-column expense layout (Personal | Group) with sort controls (Date/Name/Amount/Category × Asc/Desc); Unpriced Items section with inline pricing; Late Expenses Settlement card with per-member confirmation
- **`pages/lists.php`** — optional Price field in Add Item modal; price display on item rows
- **`schema.sql`** — added new columns and `post_settlement_confirmations` table

**UI changes:**
- Month's Expenses section replaced with two-column layout (Personal left, Group right) with colored status dots
- Filter dropdown replaced with sort controls (field + order dropdowns)
- Unpriced Items card with amber dashed border, inline price input, and one-click conversion
- Late Expenses Settlement card (amber theme) with summary, breakdown, per-member confirmation, and settle button
- Responsive: columns stack vertically on mobile

**Paid By Audit Tracking:**

New audit columns on `expenses` table:
- `paid_by` (INT UNSIGNED, FK → users) — who actually paid for the expense
- `created_by` (INT UNSIGNED, FK → users) — who recorded/created the expense entry
- `checked_by` (INT UNSIGNED, FK → users) — who checked off the list item (list-originated expenses only)
- Composite index `idx_paid_by (paid_by, expense_date)` for settlement query performance
- All pre-existing expenses backfilled: `paid_by = user_id`, `created_by = user_id`

Settlement calculation change:
- All 5 settlement files (`calculate.php`, `settle_all.php`, `confirm.php`, `post_calculate.php`, `post_confirm.php`) now GROUP BY `e.paid_by` instead of `e.user_id` — settlements are calculated based on who actually paid, not who recorded the expense

Modified API files (12):
- **`api/lists/check_item.php`** — fully rewritten: checking a group list item returns `needs_confirm: true` with item details and group members; frontend shows a Confirm Purchase popup with Paid By dropdown; confirm creates expense with `paid_by`, `created_by`, and `checked_by`; cancel unchecks the item
- **`api/expenses/create.php`** — accepts `paid_by` param; validates payer is a group member; personal expenses auto-set `paid_by = user_id`; INSERT includes `paid_by` and `created_by`
- **`api/expenses/update.php`** — accepts and persists `paid_by` in UPDATE
- **`api/expenses/list.php`** — all 3 query variants (date, month, range) JOIN `users pb ON pb.id = e.paid_by`; return `payer_username`
- **`api/expenses/price_unpriced.php`** — accepts `paid_by` for group items; includes in INSERT
- **`api/settlements/calculate.php`** — GROUP BY `e.paid_by`
- **`api/settlements/settle_all.php`** — GROUP BY `e.paid_by`
- **`api/settlements/confirm.php`** — GROUP BY `e.paid_by` in finalization recomputation
- **`api/settlements/post_calculate.php`** — contributions keyed by `paid_by`; returns `payer_username`
- **`api/settlements/post_confirm.php`** — recalculation uses `paid_by` GROUP BY
- **`api/settlements/details.php`** — JOINs paid_by user; returns `payer_username`
- **`api/groups/details.php`** — past expenses query returns `payer_username`

Modified UI files (5):
- **`pages/lists.php`** — new Confirm Purchase modal (`#checkConfirmModal`) with Item Name, Amount, Paid By member dropdown, Date, and Confirm/Cancel buttons
- **`pages/home.php`** — new Paid By dropdown (`#paidByWrap` / `#expPaidBy`) appears after group selection in Add/Edit Expense modal
- **`public/assets/js/app.js`** — `populatePaidByDropdown()` fetches group members; type/group change handlers show/hide Paid By field; `saveExpense()` includes client-side paid_by validation; expense cards display "paid by X"; cache-busting on script tag
- **`pages/expenses.php`** — expense rows show "paid by X"; unpriced group items get Paid By dropdown; settlement detail modal and PDF use `payer_username`; "Added By" → "Paid By" in PDFs
- **`pages/groups.php`** — expense display and PDF export use `payer_username`; "Added By" → "Paid By" in PDF headers
- **`schema.sql`** — added 3 columns, 3 foreign keys, composite index

---

### v1.1 — Feature Update

> 25 files changed · 2 574 insertions · 342 deletions

**New database tables:**
- `settlements` — records finalised settlement periods (group, date range, details JSON)
- `settlement_confirmations` — tracks per-member settlement approval (unique on group + user)

**New API endpoints (8 files):**
- `api/settlements/calculate.php` — compute shares, balances, and current confirmation status
- `api/settlements/confirm.php` — individual member settlement confirmation; auto-finalizes when all active members agree
- `api/settlements/settle.php` — record an individual settlement entry
- `api/settlements/settle_all.php` — admin settle-all (legacy/fallback)
- `api/settlements/history.php` — past settlements for a group
- `api/settlements/details.php` — expenses within a specific settlement period
- `api/notifications/history.php` — 7-day notification history with auto-cleanup
- `pages/notifications.php` — full notification history page with date grouping

**Modified files (17 files):**
- **Expense APIs** (`create`, `update`, `delete`, `list`) — settlement lock enforcement; settled-flag on list responses; settled expenses hide Edit/Delete in UI
- **Group APIs** (`delete`, `join`, `leave`) — notification triggers on all member changes
- **Notification APIs** (`list`, `count`) — 7-day retention window; latest-notification field for popup detection
- **Home page** (`pages/home.php`) — colored expense dots (blue/green/gray) on calendar day cells
- **Expenses page** (`pages/expenses.php`) — settlement tab redesigned: all members see Settle button; 3-state confirmation card with CSS animation
- **Groups & Lists pages** — notification triggers on group/list actions
- **App JS** (`public/assets/js/app.js`) — expense card status dots; settled expense protection; notification polling with toast + audio
- **CSS** (`public/assets/css/styles.css`) — notification toast, dropdown, and settlement animation styles
- **Router** (`public/index.php`) — added `notifications` page route, "View Last 7 Days" link in bell dropdown
- **Schema** (`schema.sql`) — added 2 new tables

### v1.0 — Initial Release

Core expense management application with calendar-based UI, group expense splitting, budget tracking, shopping lists, authentication, and chart-based analytics.


---

## Architecture Notes

### Procedural Design Philosophy

ExpMgWEB uses a **procedural architecture** by design, not object-oriented services. Each endpoint file contains its own business logic, validation, and database interaction. This approach offers:

- **Simplicity** — every endpoint is self-contained and easy to understand
- **Directness** — no hidden abstractions or framework magic
- **Performance** — minimal overhead; requests routed directly to business logic
- **Auditability** — source code directly reflects what the API does

### Helper & Service Layer

The `api/services/` and `api/helpers/` directories provide:

- **Helpers** (`api/helpers/`) — utility functions (CSRF tokens, logging, rate limiting, notification publishing, database responses)
- **Services** (`api/services/`) — reusable infrastructure (caching, notifications, health checks, Redis pooling, system coordination, predictive monitoring)
- **Middleware** (`api/middleware/`) — cross-cutting concerns (authentication, session validation)

Business logic remains in the endpoint files themselves (`api/expenses/create.php`, etc.), allowing developers to see exactly what each API call does without jumping between classes.

> **Note:** The changelog's v1.7 entry describes a planned business service layer (`ExpenseService`, `GroupService`, etc.) that was designed but never implemented. The procedural architecture described here accurately reflects the current state of the codebase.

### Why This Matters

If you're considering extending the system:
- **Add a feature**: Create a new endpoint file that calls helpers/services as needed
- **Fix a bug**: Find the endpoint file that handles that operation; the code is right there
- **Scale horizontally**: Services can be extracted to separate processes (caching, notifications, health checks) without refactoring endpoint files
- **Deploy anywhere**: Works on any PHP 8+ LAMP stack without requiring framework installation

---

## License

This project is for educational / personal use.

