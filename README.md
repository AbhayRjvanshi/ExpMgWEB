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
Expenses are created with an amount, category (8 presets), optional note, date, and type (personal or group). Group expenses are linked to a group and follow admin-only edit/delete rules. A **settlement lock** prevents any modification to expenses that fall within an already-settled date range.

### Budget Tracking
Users set a monthly budget. The expenses page shows a progress bar that fills based on spending — turning amber at 80% and red at 100%. Summary cards show total, personal, and group breakdowns alongside category doughnut and daily bar charts (Chart.js).

### Group Settlement
When a group has unsettled expenses, the system calculates each member's fair share using a **greedy debt-minimization algorithm** (minimizes the number of transactions needed). Each member sees who owes whom and clicks **Settle** to confirm from their side. Only when **all members confirm** does the period officially close — at which point expenses become immutable (gray dots, no edit/delete, lock enforced at API level). Past settlements are viewable with a **PDF export** option.

### Shopping Lists
Users create personal or group shopping lists. Items have priority levels (high / moderate / low) and can be checked off. Group list changes trigger notifications.

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
| **Settlement** | Per-member settlement confirmation; greedy debt-minimization algorithm; settlement breakdown, status tracking, PDF export |
| **Lists** | Shopping / to-buy lists with high / moderate / low priority ordering and check-off |
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
│   │   └── check_item.php     # POST — toggle checked
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
│       └── js/app.js          # Calendar, notification & profile modules
├── schema.sql                 # Full database schema (11 tables)
├── seed.sql                   # Demo data (3 users, expenses, group, lists)
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

---

## Changelog

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
