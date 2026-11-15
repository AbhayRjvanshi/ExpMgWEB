# Feature Inventory

Every feature below exists in the current PHP application. "Preserve" means the Flutter client
must reproduce the behaviour or its observable outcome. "Frontend-only" means the feature is
implemented in JavaScript and must be reimplemented in Flutter without a new backend endpoint.

Legend for **Status**:

- **Live** — implemented and reachable in the running app.
- **Live (frontend-only)** — no PHP endpoint; computed in the browser.
- **Dormant** — code exists but nothing on the HTTP path calls it.
- **Absent** — does not exist in the PHP app.

---

## 1. Authentication and session

| ID | Feature | Status | Frontend | Backend | Tables | Flutter requirement |
|---|---|---|---|---|---|---|
| AUTH-1 | Signup | Live | `pages/signup.php`, `auth-page.js` | `api/signup.php` | `users` | Preserve validation, duplicate handling, redirect-to-login |
| AUTH-2 | Login | Live | `pages/login.php`, `auth-page.js` | `api/login.php` | `users`, `rate_limits` | Preserve error text, CSRF, 5/15-min IP throttle |
| AUTH-3 | Logout | Live | header action in `app.js` | `api/logout.php` | — | Destroy session; return to login. No CSRF and no method guard (KD-31) |
| AUTH-4 | Session expiry | Live | redirect on 401 | `api/middleware/auth.php` | — | Treat 401 as logout; 24-hour absolute limit |
| AUTH-5 | CSRF protection | Live | `helpers.js` token cache | `api/helpers/csrf.php` | `rate_limits` | Send `X-CSRF-Token` on every write except `logout.php` |
| AUTH-6 | Idempotent retries | Live | `helpers.js` per-intent key | `config/idempotency.php` | file/Redis | Generate one key per user intent |
| AUTH-7 | Rate limiting | Live | handles 429 + `Retry-After` | `api/helpers/rate_limiter.php` | `rate_limits` | Display retry state; do not hammer |
| AUTH-8 | Guest mode | **Absent** | none | none | — | Flutter-only local mode; never calls the API |

The PHP app contains no guest-mode code path. A Flutter guest mode must keep all data on the
device and must not send anonymous requests to these endpoints.

---

## 2. Shell, navigation, and presentation

| ID | Feature | Status | Source | Notes |
|---|---|---|---|---|
| UI-1 | Splash screen | Live | `public/splash.php`, `splash.js` | Redirects to login or app |
| UI-2 | SPA shell + page router | Live | `public/index.php` | Whitelisted `?page=` routes |
| UI-3 | Header, user menu, bell badge | Live | `public/index.php`, `app.js`, `notifications.js` | Bell polls every ~10 s |
| UI-4 | Dark / light theme | Live | `theme-init.js`, `helpers.js`, `styles.css` | `localStorage`, applied pre-paint |
| UI-5 | Responsive layout + modals | Live | `styles.css`, `helpers.js` | Shared modal helpers |
| UI-6 | Toast / error messaging | Live | `helpers.js`, page modules | No global notification store |
| UI-7 | Sorting | Live | page modules | Expenses, groups, lists, notification feeds |

---

## 3. Home and calendar

| ID | Feature | Status | Frontend | Backend | Tables |
|---|---|---|---|---|---|
| HOME-1 | Month calendar | Live (frontend-only) | `app.js`, `pages/home.php` | `expenses/list.php`, `expenses/summary.php` | `expenses` |
| HOME-2 | Day expense drill-down | Live | `app.js` | `expenses/list.php?date=` | `expenses` |
| HOME-3 | Month totals and budget gauge | Live | `app.js` | `expenses/summary.php` | `expenses`, `budgets` |
| HOME-4 | Upcoming/selected-day detail | Live | `app.js` | `expenses/list.php` | `expenses` |

The calendar grid is computed in JavaScript from the expense list. There is no calendar
endpoint.

---

## 4. Expenses

| ID | Feature | Status | Frontend | Backend | Tables |
|---|---|---|---|---|---|
| EXP-1 | List by date / month / range | Live | `expenses.js` | `api/expenses/list.php` | `expenses`, `categories` |
| EXP-2 | Create personal expense | Live | `expenses.js` | `api/expenses/create.php` | `expenses`, `notifications` |
| EXP-3 | Create group expense | Live | `expenses.js` | `api/expenses/create.php` | `expenses`, `group_members`, `notifications` |
| EXP-4 | Edit expense (merge/PATCH) | Live | `expenses.js` | `api/expenses/update.php` | `expenses`, `notifications` |
| EXP-5 | Delete expense | Live | `expenses.js` | `api/expenses/delete.php` | `expenses`, `notifications` |
| EXP-6 | Categories list | Live | shared | `api/expenses/categories.php` | `categories` |
| EXP-7 | Monthly summary + charts | Live | `expenses.js` | `api/expenses/summary.php` | `expenses`, `budgets` |
| EXP-8 | Per-expense permission flags | Live | `expenses.js` | `expenses/list.php` (`can_edit`, `settled`) | `group_members`, `settlements` |
| EXP-9 | PDF expense report | Live (frontend-only) | `expenses.js` + jsPDF | none | — |
| EXP-10 | CSV/PDF summary export | Live (frontend-only) | `expenses.js` | none | — |

Rules preserved by EXP-2…EXP-5:

- Personal expense: only the owner (`user_id`) may edit or delete.
- Group expense: only the group admin may edit or delete.
- Any group expense whose `expense_date` is on or before the group's latest `period_end` is
  locked and must be rejected on create/update/delete/price.
- `paid_by` is the payer used in settlement maths, not `user_id`.
- A personal expense never keeps a `group_id`; a group expense requires a valid membership.

---

## 5. Budgets

| ID | Feature | Status | Frontend | Backend | Tables |
|---|---|---|---|---|---|
| BUD-1 | Set monthly limit | Live | `expenses.js` | `api/budgets/set.php` | `budgets` |
| BUD-2 | Read monthly limit | Live, but **embedded** | `expenses.js` reads `summary.budget` | `api/budgets/get.php` exists but is **not called by any frontend JS** | `budgets` |

The upsert is one row per `(user_id, budget_month)`. The response reports the previous value,
and the write is logged. The Flutter client may call either `budgets/get.php` or read the
`budget` field from `expenses/summary.php`; the field is the same value.

---

## 6. Groups

| ID | Feature | Status | Frontend | Backend | Tables |
|---|---|---|---|---|---|
| GRP-1 | List my groups | Live | `groups.js`, `app.js` | `api/groups/user_groups.php` | `groups`, `group_members` |
| GRP-2 | Create group | Live | `groups.js` | `api/groups/create.php` | `groups`, `group_members` |
| GRP-3 | Join by code | Live | `groups.js` | `api/groups/join.php` | `groups`, `group_members`, `notifications` |
| GRP-4 | Group details (members + expenses) | Live | `groups.js` | `api/groups/details.php` | `groups`, `group_members`, `expenses` |
| GRP-5 | Remove member (admin) | Live | `groups.js` | `api/groups/remove_member.php` | `group_members`, `notifications` |
| GRP-6 | Leave group (member) | Live | `groups.js` | `api/groups/leave.php` | `group_members`, `notifications` |
| GRP-7 | Delete group (creator) | Live | `groups.js` | `api/groups/delete.php` | `groups`, cascade |
| GRP-8 | Max 5 groups per user | Live | error surface | `groups/create.php` | `groups` |
| GRP-9 | Max 10 members per group | Live | error surface | `groups/join.php` | `group_members` |

`groups/details.php` paginates expenses with a 500/1000 ceiling but returns all members
(maximum 10). `settle.php` and `settle_all.php` are not called by any frontend JS; the current
UI drives settlement through the calculate/confirm cycle.

---

## 7. Settlement cycle

| ID | Feature | Status | Frontend | Backend | Tables |
|---|---|---|---|---|---|
| SET-1 | Calculate balances and suggested transfers | Live | `expenses.js` | `api/settlements/calculate.php` | `expenses`, `group_members`, `settlements` |
| SET-2 | Member confirmation (3-step gate) | Live | `expenses.js` | `api/settlements/confirm.php` | `settlement_confirmations`, `settlements` |
| SET-3 | Record a single settlement (admin) | Live but **not called by UI** | none | `api/settlements/settle.php` | `settlements` |
| SET-4 | Force-settle all (admin) | Live but **not called by UI** | none | `api/settlements/settle_all.php` | `settlements`, `settlement_confirmations` |
| SET-5 | Settlement history | Live | `expenses.js` | `api/settlements/history.php` | `settlements` |
| SET-6 | Period expense details | Live | `expenses.js` | `api/settlements/details.php` | `expenses` |
| SET-7 | Post-settlement calculation | Live | `expenses.js` | `api/settlements/post_calculate.php` | `expenses`, `post_settlement_confirmations` |
| SET-8 | Post-settlement confirmation | Live | `expenses.js` | `api/settlements/post_confirm.php` | `post_settlement_confirmations`, `settlements` |
| SET-9 | Settlement lock on late edits | Live | UI disables edits | create/update/delete/price_unpriced | `settlements` |

Non-negotiable invariants (also in [`DATABASE_AUDIT.md`](DATABASE_AUDIT.md)):

1. Balance maths uses `paid_by`.
2. Every settlement expense query filters `is_post_settlement = 0`.
3. A period closes only when **all** active members have a confirmation row.
4. `confirm.php` uses a transaction and `SELECT … FOR UPDATE`.
5. Late expenses use the separate post-settlement flow.
6. Only the group admin may call `settle.php` / `settle_all.php`.
7. All-equal periods still write a `0.00` marker settlement row so the lock advances.

`post_confirm.php` writes its confirmation, counts members, and finalizes without a
`FOR UPDATE` guard; this is recorded as debt, not changed.

---

## 8. Lists and list-to-expense conversion

| ID | Feature | Status | Frontend | Backend | Tables |
|---|---|---|---|---|---|
| LST-1 | List my lists | Live | `lists.js` | `api/lists/user_lists.php` | `lists`, `list_items` |
| LST-2 | Create personal/group list | Live | `lists.js` | `api/lists/create.php` | `lists` |
| LST-3 | Delete list | Live | `lists.js` | `api/lists/delete.php` | `lists`, `list_items` |
| LST-4 | List details + items | Live | `lists.js` | `api/lists/details.php` | `lists`, `list_items` |
| LST-5 | Add item | Live | `lists.js` | `api/lists/add_item.php` | `list_items`, `notifications` |
| LST-6 | Remove item | Live | `lists.js` | `api/lists/remove_item.php` | `list_items` |
| LST-7 | Check/uncheck item | Live | `lists.js` | `api/lists/check_item.php` | `list_items`, `expenses`, `notifications` |
| LST-8 | Group check confirmation popup | Live | `lists.js` | `check_item.php` (`needs_confirm`, `confirm=1`) | `list_items`, `expenses` |
| LST-9 | 10-minute uncheck window | Live | `lists.js` | `check_item.php` | `list_items` |
| LST-10 | Linked-expense deletion on uncheck | Live | automatic | `check_item.php` uses `expense_id` | `list_items`, `expenses` |

Conversion rules preserved:

- Checking a **priced** personal item creates an expense immediately and stores `expense_id`.
- Checking a **priced group** item first returns `needs_confirm: true`; the caller must POST
  `paid_by` (and optionally a price) with `confirm=1` to create the expense.
- The item is marked checked **before** the group confirmation popup appears. Cancelling the
  popup can leave a checked, priced, unconfirmed group item. Such an item is not shown by
  `expenses/unpriced.php` and is a known operational gap.
- Unchecking within 10 minutes deletes the linked expense by exact `expense_id`; after 10
  minutes it is rejected.
- `checked_at` is a datetime; the expense date is `date('Y-m-d', strtotime(checked_at))` (the
  flow uses today's date at creation).

---

## 9. Unpriced items

| ID | Feature | Status | Frontend | Backend | Tables |
|---|---|---|---|---|---|
| UNP-1 | List checked items with no price | Live | `expenses.js` | `api/expenses/unpriced.php` | `list_items`, `lists` |
| UNP-2 | Price an unpriced item | Live | `expenses.js` | `api/expenses/price_unpriced.php` | `list_items`, `expenses` |

`unpriced.php` selects `price IS NULL`. A group item that has a price but was never confirmed
is therefore invisible on this screen. Pricing uses `FOR UPDATE` and an idempotent guarded link
(`AND expense_created = 0`).

---

## 10. Notifications

| ID | Feature | Status | Frontend | Backend | Tables |
|---|---|---|---|---|---|
| NTF-1 | Unread count + latest | Live | `notifications.js` polls | `api/notifications/count.php` | `notifications` |
| NTF-2 | Today's list | Live | `notifications.js` | `api/notifications/list.php` | `notifications` |
| NTF-3 | 7-day history | Live | `notifications.js` | `api/notifications/history.php` | `notifications` |
| NTF-4 | Mark one/all read | Live | `notifications.js` | `api/notifications/read.php` | `notifications` |
| NTF-5 | Retention cleanup | Live (CLI) | — | `scripts/cleanup_notifications.php` | `notifications` |

All producers write directly with `INSERT INTO notifications`. Types in use include
`group_join`, `group_leave`, `group_delete`, `group_expense_add`, `group_expense_update`,
`group_expense_delete`, `settlement`, `list_item_add`, `list_item_remove`, `list_item_check`.
`reference_id` is inconsistent across producers (expense ID for create, group/list ID
elsewhere). The UI does not navigate on click, so this is informational only.

The publisher/store abstraction (`notification_publisher.php`, `notification_store.php`) and
the outbox are **dormant** and must not be used as a sync design.

---

## 11. Analytics and exports

| ID | Feature | Status | Where | Flutter requirement |
|---|---|---|---|---|
| AN-1 | Category breakdown chart | Live (frontend-only) | `expenses.js` + Chart.js | Reimplement with a Flutter chart library; data from `expenses/summary.php` |
| AN-2 | Daily spend chart | Live (frontend-only) | `expenses.js` + Chart.js | Same |
| AN-3 | Budget utilisation | Live (frontend-only) | `expenses.js` | Compute from `summary.budget` and `summary.total_spent` |
| AN-4 | Expense report PDF | Live (frontend-only) | `expenses.js` + jsPDF | Reimplement locally |
| AN-5 | Summary PDF | Live (frontend-only) | `expenses.js` + jsPDF | Reimplement locally |

No analytics or PDF endpoint exists. The backend only supplies rows and aggregates.

---

## 12. System and operational features

| ID | Feature | Status | Source | Notes |
|---|---|---|---|---|
| SYS-1 | Health snapshot | Live | `api/system/health.php` | Requires auth over HTTP; inline pressure/Redis/outbox snapshot |
| SYS-2 | Outbox replay worker | Dormant | `api/workers/process_outbox.php` | CLI only; no producer writes events |
| SYS-3 | Notification cleanup | Live (CLI) | `scripts/cleanup_notifications.php` | 7-day retention |
| SYS-4 | Application log | Live | `logs/app.log` | Web-denied |
| SYS-5 | Structured rate-limit cleanup | Live | `rate_limiter.php` | Action-aware retention |
| SYS-6 | Distributed services | Dormant | `api/services/*` | Not on the HTTP path; see KD-11 |

---

## 13. Features that must **not** be added during Phase 1

- Guest-mode API calls.
- New sync endpoints or schema changes.
- Service-layer extraction / KD-06 stubs.
- Section 30 response-contract rewrite.
- Publisher/outbox adoption.
- Removal of any feature listed above.

The Flutter migration may add client-side local guest mode and may reimplement analytics/PDF,
but the PHP behaviour stays as documented until the migration is complete.
