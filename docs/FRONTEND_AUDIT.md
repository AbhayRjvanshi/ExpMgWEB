# Frontend Audit

The frontend is a server-rendered SPA shell with hand-written ES2020 modules. There is no build
step, no bundler, no framework, and no runtime npm dependency.

---

## 1. File inventory

| File | Lines | Responsibility |
|---|---:|---|
| `public/index.php` | 12 733 B | SPA shell, auth redirect, CSP, header, page router, CDN scripts |
| `public/splash.php` | 4 253 B | Entry splash and redirect decision |
| `pages/home.php` | 353 | Calendar/home markup |
| `pages/expenses.php` | 651 | Expense dashboard, charts, settlement panels, modals |
| `pages/groups.php` | 146 | Group list/detail markup and modals |
| `pages/lists.php` | 256 | Lists markup and modals |
| `pages/notifications.php` | 81 | Notification centre markup |
| `pages/login.php` | 78 | Login form |
| `pages/signup.php` | 88 | Signup form |
| `public/assets/js/helpers.js` | 658 | API wrapper, CSRF, idempotency, errors, modals, theme, shared formatting |
| `public/assets/js/app.js` | 1 069 | Shell boot, calendar, expense modal, notifications, navigation |
| `public/assets/js/expenses.js` | 1 158 | Summary, charts, budget, unpriced, settlements, post-settlement, PDFs |
| `public/assets/js/groups.js` | 347 | Group list/detail/actions |
| `public/assets/js/lists.js` | 708 | List list/detail/items/check flow |
| `public/assets/js/notifications.js` | 110 | Notification history page |
| `public/assets/js/auth-page.js` | 22 | Password visibility / small auth helpers |
| `public/assets/js/theme-init.js` | 14 | Pre-paint theme application |
| `public/assets/js/splash.js` | 12 | Splash redirect |
| `public/assets/css/styles.css` | 34 863 B | Design system, layout, components, theme tokens |

`partials/lists_section.php` is dead code and is not included by any live page.

---

## 2. Page shell and routing

`public/index.php`:

1. Requires `config/session.php`, starts the hardened session, and redirects to
   `pages/login.php` if no `user_id`.
2. Sends the CSP and security headers.
3. Renders the application chrome: brand, navigation, theme toggle, notification bell, user
   menu, page container, global modal root, toast root.
4. Resolves `?page=` against a whitelist:

   ```php
   $allowed = ['home','expenses','groups','lists','notifications'];
   ```

5. Includes `pages/{page}.php`.
6. Loads CDN scripts with SRI (Chart.js, jsPDF), then `helpers.js`, then page modules.

Unauthenticated requests never reach the shell. There is no client-side router; navigation is a
full page load to `?page=…`.

---

## 3. Shared JavaScript layer (`helpers.js`)

| Concern | Implementation |
|---|---|
| API base | `const API = '../api'` resolved relative to the shell |
| GET | `get(url)` — same-origin credentials, JSON parse, in-flight de-duplication for identical GETs |
| POST | `post(url, data)` — `FormData`, CSRF header, idempotency key, cooldown handling |
| CSRF | Token read from cookie/meta and cached; re-read after rotation |
| Idempotency | Per-intent `Idempotency-Key` cached briefly so a double-submit reuses the key |
| Error handling | Normalizes `{ok:false,error}` and HTTP failures into a user-visible message |
| 429 handling | Reads `Retry-After`, shows a retry state, prevents hammering |
| DOM helpers | `$`, `$$`, `escapeHTML`, show/hide, modal open/close |
| Formatting | Currency, date, relative time |
| Theme | Reads/writes `localStorage`, toggles `data-theme` |
| Toasts | Shared transient message surface |

The wrapper is the only place that knows about CSRF and idempotency. Flutter must reproduce the
same headers and the same `ok`-first response handling.

---

## 4. Frontend → API call map

| Caller | Endpoint | Trigger |
|---|---|---|
| `app.js` | `GET expenses/categories.php` | shell boot |
| `app.js` | `GET groups/user_groups.php` | shell boot, expense modal |
| `app.js` | `GET expenses/list.php?month=` | calendar month |
| `app.js` | `GET expenses/list.php?date=` | day drill-down |
| `app.js` | `POST expenses/create.php` | expense modal create |
| `app.js` | `POST expenses/update.php` | expense modal edit |
| `app.js` | `POST expenses/delete.php` | delete action |
| `app.js` | `GET groups/details.php` | group context lookup |
| `app.js` | `GET notifications/list.php?limit=30` | bell dropdown |
| `app.js` | `POST notifications/read.php` | mark one / all |
| `app.js` | `GET notifications/count.php` | bell poll (~10 s) |
| `expenses.js` | `GET expenses/summary.php?month=` | dashboard |
| `expenses.js` | `GET expenses/list.php?month=` | dashboard list |
| `expenses.js` | `GET expenses/list.php?start=&end=` | range report |
| `expenses.js` | `POST budgets/set.php` | budget editor |
| `expenses.js` | `GET expenses/unpriced.php` | unpriced panel |
| `expenses.js` | `POST expenses/price_unpriced.php` | price action |
| `expenses.js` | `GET groups/user_groups.php` | settlement selector |
| `expenses.js` | `GET groups/details.php` | member cache |
| `expenses.js` | `GET settlements/calculate.php` | settlement panel |
| `expenses.js` | `GET settlements/history.php` | settlement history |
| `expenses.js` | `GET settlements/details.php` | period details |
| `expenses.js` | `POST settlements/confirm.php` | confirm button |
| `expenses.js` | `GET settlements/post_calculate.php` | late-expense panel |
| `expenses.js` | `POST settlements/post_confirm.php` | late confirm |
| `groups.js` | `GET groups/user_groups.php` | group list |
| `groups.js` | `GET groups/details.php` | group detail |
| `groups.js` | `POST groups/create.php` | create modal |
| `groups.js` | `POST groups/join.php` | join modal |
| `groups.js` | `POST groups/remove_member.php` | admin action |
| `groups.js` | `POST groups/leave.php` | leave action |
| `groups.js` | `POST groups/delete.php` | creator action |
| `lists.js` | `GET expenses/categories.php` | list item form |
| `lists.js` | `GET groups/user_groups.php` | list create form |
| `lists.js` | `GET lists/user_lists.php` | lists index |
| `lists.js` | `GET lists/details.php` | list detail |
| `lists.js` | `POST lists/create.php` | create modal |
| `lists.js` | `POST lists/delete.php` | delete action |
| `lists.js` | `POST lists/add_item.php` | add item |
| `lists.js` | `POST lists/remove_item.php` | remove item |
| `lists.js` | `POST lists/check_item.php` | check/uncheck/confirm |
| `notifications.js` | `GET notifications/history.php?limit=100` | notification page |

### Endpoints with no frontend caller

| Endpoint | Reason |
|---|---|
| `api/budgets/get.php` | budget is embedded in `expenses/summary.php` |
| `api/settlements/settle.php` | no UI action; confirmation flow finalizes instead |
| `api/settlements/settle_all.php` | no UI action |

These endpoints are live and must remain in the contract for API completeness and possible
future/admin use.

---

## 5. Key UI flows

### 5.1 Login / signup

- `pages/login.php` and `pages/signup.php` are standalone forms with hidden CSRF tokens.
- They POST to `api/login.php` / `api/signup.php` and render session flash errors.
- On success the browser is redirected to the SPA shell (or back to login after signup).

### 5.2 Home calendar

- On load, `app.js` fetches categories, groups, and the current month's expenses.
- The calendar grid is built in JavaScript; each day cell shows totals/indicators.
- Clicking a day fetches `expenses/list.php?date=` and renders details.
- Month changes refetch month expenses and summary.

### 5.3 Expense create/edit

- One shared modal handles create and edit. The URL is chosen by whether an `id` is present.
- Category and group dropdowns are populated from the boot requests.
- Group selection reveals the payer (`paid_by`) selector.
- Edit is a merge update: the form sends the fields present in the modal.
- The server's `can_edit`/`settled` flags decide whether the action is offered.

### 5.4 Budget

- The budget editor posts `month` + `amount_limit`.
- The dashboard reads `budget` from `expenses/summary.php`; no separate GET is issued.

### 5.5 Settlement

1. Select a group.
2. Fetch `calculate.php` and `history.php` in parallel.
3. Render per-member contributions, balances, suggested transfers, and confirmation state.
4. Confirm posts `group_id` to `confirm.php`; the response reports `all_settled` and
   `remaining`.
5. When all members confirm, the server finalizes the period and creates settlement rows.
6. Late expenses are handled in a separate panel through `post_calculate.php` and
   `post_confirm.php`.

### 5.6 Lists

1. Lists index from `user_lists.php`.
2. Detail from `details.php`; items are grouped by priority.
3. Adding an item posts description/category/priority/price.
4. Checking a personal priced item creates an expense immediately.
5. Checking a group item returns `needs_confirm`, and the UI opens a payer/price popup, then
   posts `confirm=1` with `paid_by` (and optional price).
6. Unchecking within 10 minutes deletes the linked expense; later attempts are rejected.

### 5.7 Notifications

- The bell polls `count.php` about every 10 seconds.
- The dropdown loads `list.php?limit=30` for today.
- Opening the notification page loads `history.php?limit=100` for seven days.
- Marking read posts to `read.php` and refreshes the count.

### 5.8 Analytics and PDF

- Charts use Chart.js on data from `summary.php` (`by_category`, `by_day`).
- Budget utilisation is `total_spent / budget`.
- PDF reports use jsPDF and browser data; there is no server PDF endpoint.
- All of this is client-side and must be reimplemented in Flutter.

---

## 6. Theme and design system

- Nine CSS custom properties at the top of `styles.css` define the palette and surfaces.
- `theme-init.js` runs before first paint, reads `localStorage`, and sets `data-theme`.
- `helpers.js` owns the toggle and persistence.
- The palette is mint-to-emerald with deep pine teal cards.
- Components: cards, pills, tables, modals, toasts, segmented controls, calendar cells.

Flutter should reproduce the visual language, not the CSS variable mechanism.

---

## 7. Security-relevant frontend behaviour

| Control | State |
|---|---|
| CSP | `script-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com`; **no `unsafe-inline`** |
| Inline event handlers | Blocked by CSP |
| CSRF | Header sent by `helpers.js` on writes |
| Session | Cookie-based, same-origin fetch credentials |
| XSS | Server stores raw text; client escapes at render with `escapeHTML` |
| External scripts | Chart.js/jsPDF from jsDelivr with SRI |
| Secrets in JS | None |

### Known CSP breakage

`pages/lists.php` contains two inline `onclick` close buttons at lines 59 and 92. Because the
CSP forbids inline script, these handlers do not fire. The dead
`partials/lists_section.php` contains three more. This is a real UI defect, documented and
preserved; it is not fixed in Phase 1.

The correct pattern used elsewhere is a `data-*` attribute plus an event listener in the module.

---

## 8. Guest mode

There is **no guest mode** anywhere in `public/assets/js` or `pages/`. Every module assumes an
authenticated session and an `API` base. The Flutter client may add a local-only guest mode,
but it must:

- never call these endpoints without a session;
- never store guest data on the server;
- make the local/server boundary explicit in the UI.

---

## 9. Frontend debt relevant to the migration

1. `pages/lists.php` inline handlers are CSP-blocked.
2. `partials/lists_section.php` is dead and should not be ported.
3. `app.js` and `expenses.js` are large mixed-concern modules; they are the behavioural
   reference, not a code template.
4. The settlement UI has no path to `settle.php` / `settle_all.php`.
5. The budget page depends on a field embedded in summary rather than the dedicated endpoint.
6. Charts and PDFs have no backend contract; they must be reimplemented.
7. Error handling assumes the `{ok:false,error}` shape but must also tolerate `success`/`code`
   and non-200 business errors.
8. `graphify-out/` is stale (2026-06-03) and must not be used as a behavioural authority.
