# Expense Manager (ExpMgWEB)

A web-based personal and group financial tracking application built with PHP, MySQL, and vanilla JavaScript. Track everyday spending, split group costs fairly, set monthly budgets, manage shopping lists, and visualise your finances — all without installing a single app.

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

**Horizontal scaling paths:**
- **Database:** Migrate from single-server MySQL to a managed service (Amazon RDS, PlanetScale, TiDB) for replication and failover.
- **Caching:** Add Redis/Memcached for session storage and frequently-hit data (budget summaries, group member lists).
- **API layer:** The existing REST-style API structure (`api/expenses/create.php`, etc.) maps cleanly to a microservice split if needed.
- **Frontend:** The vanilla JS frontend could be wrapped in a PWA (Service Worker) for offline capability and home-screen install — no rewrite needed.
- **Auth:** Session-based auth can be augmented with JWT tokens for mobile/API clients.
- **Deployment:** Dockerize the PHP + MySQL stack for one-click cloud deployment (AWS, DigitalOcean, Railway).

**Feature scaling:**
- Multi-currency support (add a `currency` column to expenses)
- Recurring expenses (cron job + template table)
- Receipt photo uploads (file storage + thumbnail generation)
- Export to CSV / Excel
- Admin dashboard for multi-tenant deployments

The architecture is intentionally simple — no over-engineering — which makes any of these additions straightforward.

---

## How It Works (Brief)

### Authentication
Users sign up with a username, email, and password (bcrypt-hashed). Sessions track logged-in state. All API endpoints verify `$_SESSION['user_id']` before processing.

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
All group actions (join, leave, expense add/edit/delete, settlement, list changes) generate notifications. A bell icon polls every 10 seconds for new alerts, showing a badge count and a dropdown. New notifications trigger a **toast popup with an audio chime**. A dedicated **notification history page** shows the last 7 days of alerts grouped by date.

---

## Features

| Module | Highlights |
|---|---|
| **Authentication** | Sign up / Log in / Log out with session-based auth and bcrypt-hashed passwords |
| **Calendar Home** | Month grid with colored dot indicators (blue = personal, green = unsettled group, gray = settled group); click a day to view/add/edit expenses |
| **Expense CRUD** | Personal or group expenses with 8 categories, notes, and date; settlement lock prevents changes in settled periods |
| **Charts & Analytics** | Monthly summary cards, category doughnut chart, daily spending bar chart (Chart.js) |
| **Budgets** | Set a monthly budget; progress bar turns amber at 80% and red at 100% |
| **Groups** | Create up to 5 groups (max 10 members each); join via 8-character code; admin controls |
| **Settlement** | Per-member settlement confirmation; greedy debt-minimization algorithm; settlement breakdown, status tracking, PDF export; **late expenses settlement** with automatic past-record updates |
| **Lists** | Shopping / to-buy lists with priority ordering, optional price, auto-expense on check-off, unpriced items queue |
| **Notifications** | Real-time polling (10s) with bell dropdown, toast popups with sound; 7-day notification history page; group, list, expense & settlement event alerts |

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
│   └── db.php                 # MySQL connection (host, user, pass, db name)
├── api/
│   ├── signup.php             # POST — create account
│   ├── login.php              # POST — authenticate
│   ├── logout.php             # GET  — destroy session
│   ├── expenses/
│   │   ├── categories.php     # GET  — list all 8 categories
│   │   ├── create.php         # POST — add expense (with settlement lock)
│   │   ├── list.php           # GET  — by date or month (with settled flags)
│   │   ├── update.php         # POST — edit expense (permission + lock check)
│   │   ├── delete.php         # POST — remove expense (permission + lock check)
│   │   └── summary.php        # GET  — monthly totals, by-category, by-day
│   ├── budgets/
│   │   ├── get.php            # GET  — fetch budget for a month
│   │   └── set.php            # POST — create / update budget (upsert)
│   ├── groups/
│   │   ├── create.php         # POST — new group (generates join code)
│   │   ├── join.php           # POST — join via code (with notifications)
│   │   ├── leave.php          # POST — leave group (with notifications)
│   │   ├── details.php        # GET  — members + recent expenses
│   │   ├── delete.php         # POST — admin-only delete (with notifications)
│   │   └── user_groups.php    # GET  — current user's groups
│   ├── settlements/
│   │   ├── settlement_helpers.php # Shared greedy debt-minimization algorithm
│   │   ├── calculate.php      # GET  — compute shares, balances, confirmations
│   │   ├── confirm.php        # POST — individual member settlement confirmation
│   │   ├── settle.php         # POST — record a single settlement
│   │   ├── settle_all.php     # POST — admin settle-all (legacy)
│   │   ├── history.php        # GET  — past settlements for a group
│   │   └── details.php        # GET  — expenses within a settlement period
│   ├── lists/
│   │   ├── create.php         # POST — new list
│   │   ├── user_lists.php     # GET  — current user's lists
│   │   ├── details.php        # GET  — items in a list
│   │   ├── delete.php         # POST — delete list
│   │   ├── add_item.php       # POST — add item to list
│   │   ├── remove_item.php    # POST — remove item
│   │   └── check_item.php     # POST — toggle checked (auto-creates expense if priced; detects post-settlement)
│   ├── expenses/
│   │   ├── unpriced.php        # GET  — list unpriced checked items awaiting price
│   │   └── price_unpriced.php  # POST — add price to unpriced item → create expense (post-settlement aware)
│   ├── settlements/
│   │   ├── post_calculate.php  # GET  — calculate supplementary settlement for late expenses
│   │   └── post_confirm.php    # POST — confirm late settlement; recalculates & updates past records
│   └── notifications/
│       ├── list.php           # GET  — today's notifications + unread count
│       ├── history.php        # GET  — last 7 days of notifications
│       ├── read.php           # POST — mark single/all as read
│       └── count.php          # GET  — lightweight unread count (polling)
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
│       ├── css/styles.css     # Full design system (mint-to-emerald palette)
│       └── js/
│           ├── helpers.js     # Shared global utilities ($, show, hide, escapeHTML, post, get, API)
│           └── app.js         # Calendar, notification & profile modules
├── schema.sql                 # Full database schema (12 tables)
├── seed.sql                   # Demo data (3 users, expenses, group, lists)
├── run_tests.php              # Automated test suite (178 tests across 23 sections)
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

### 4. Configure connection

Edit `config/db.php` if your MySQL credentials differ from the defaults:

```php
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');          // default XAMPP has no password
define('DB_NAME', 'ExpMgWEB');
```

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

### Full Codebase Audit (v1.3)

A comprehensive audit was performed across the entire project — covering all 38 API endpoints, 7 page files, 2 JS modules, and the database schema. The audit consisted of:

1. **Static code review** — every PHP and JS file read line-by-line for logic errors, security gaps, missing validations, and code duplication.
2. **PHP syntax check** — `php -l` run against every `.php` file in the project.
3. **Automated functional test suite** — a 178-test script (`run_tests.php`) that exercises every feature end-to-end via HTTP requests against a live local server.

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

The test script creates 3 temporary test users, exercises every feature via real HTTP requests, then cleans up all test data. It covers **178 assertions** across 23 sections:

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
| 22 | UI File Integrity | 21 | All 12 UI files exist; `helpers.js` loaded before `app.js`; `helpers.js` defines all 7 shared functions; no duplicate definitions in `app.js` or page files |
| 23 | PHP Syntax Check | 1 | `php -l` passes on every `.php` file in the project |

#### Running the Tests

```bash
# From the project root (XAMPP must be running with Apache + MySQL)
php run_tests.php
```

Expected output (last line):

```
RESULTS: 178 passed, 0 failed out of 178 tests
```

---

## Changelog

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

## License

This project is for educational / personal use.
