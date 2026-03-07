# Expense Manager

A web-based financial tracking tool built with PHP, MySQL, HTML/CSS, and vanilla JavaScript. Track personal and group expenses, set monthly budgets, manage shared costs with groups, create shopping lists, and visualise spending through interactive charts.

---

## Features

| Module | Highlights |
|---|---|
| **Authentication** | Sign up / Log in / Log out with session-based auth and bcrypt-hashed passwords |
| **Calendar Home** | Month grid view; click a day to see expenses; add/edit/delete from a modal |
| **Expense CRUD** | Personal or group expenses with 8 categories, notes, and date |
| **Charts & Analytics** | Monthly summary cards, category doughnut chart, daily spending bar chart (Chart.js) |
| **Budgets** | Set a monthly budget; progress bar turns amber at 80 % and red at 100 % |
| **Groups** | Create up to 5 groups (max 10 members each); join via 8-character code; admin controls |
| **Lists** | Shopping / to-buy lists with high / moderate / low priority ordering and check-off |
| **Notifications** | Real-time-ish bell icon with polling; group and list event alerts; mark-all-read |

---

## Tech Stack

- **Back-end:** PHP 8+ (procedural, no framework)
- **Database:** MySQL 8 via `mysqli`
- **Front-end:** Vanilla HTML / CSS / JavaScript
- **Charts:** Chart.js (CDN)
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
│   │   ├── create.php         # POST — add expense
│   │   ├── list.php           # GET  — by date or month
│   │   ├── update.php         # POST — edit expense (permission-checked)
│   │   ├── delete.php         # POST — remove expense (permission-checked)
│   │   └── summary.php        # GET  — monthly totals, by-category, by-day
│   ├── budgets/
│   │   ├── get.php            # GET  — fetch budget for a month
│   │   └── set.php            # POST — create / update budget (upsert)
│   ├── groups/
│   │   ├── create.php         # POST — new group (generates join code)
│   │   ├── join.php           # POST — join via code
│   │   ├── leave.php          # POST — leave group
│   │   ├── details.php        # GET  — members + recent expenses
│   │   ├── delete.php         # POST — admin-only delete
│   │   └── user_groups.php    # GET  — current user's groups
│   ├── lists/
│   │   ├── create.php         # POST — new list
│   │   ├── user_lists.php     # GET  — current user's lists
│   │   ├── details.php        # GET  — items in a list
│   │   ├── delete.php         # POST — delete list
│   │   ├── add_item.php       # POST — add item to list
│   │   ├── remove_item.php    # POST — remove item
│   │   └── check_item.php     # POST — toggle checked
│   └── notifications/
│       ├── list.php           # GET  — notifications + unread count
│       ├── read.php           # POST — mark single/all as read
│       └── count.php          # GET  — lightweight unread count (polling)
├── pages/
│   ├── login.php              # Login form
│   ├── signup.php             # Signup form
│   ├── home.php               # Calendar-based day view
│   ├── expenses.php           # Charts, analytics, budget tracking
│   ├── groups.php             # Group management UI
│   └── lists.php              # Shopping list UI
├── public/
│   ├── index.php              # Authenticated shell — page router + nav
│   └── assets/
│       ├── css/styles.css     # Full design system (mint-to-emerald palette)
│       └── js/app.js          # Calendar module + notifications module
├── schema.sql                 # Full database schema (9 tables)
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
- **Notification polling:** Client polls `/api/notifications/count.php` every 15 seconds.
- **List priorities:** Items display grouped by priority (high → moderate → low), oldest first within each tier.

---

## License

This project is for educational / personal use.
