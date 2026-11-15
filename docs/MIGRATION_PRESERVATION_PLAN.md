# Migration Preservation Plan

This is the contract between Phase 1 (freeze and document) and the Flutter migration. It states
what must remain true, what may change, and how the audit is accepted.

---

## 1. Objective

Build a Flutter client that reproduces the existing product's behaviour against the existing
PHP/MySQL backend, without removing features and without requiring a backend redesign.

Phase 1 produces the reference. It does not migrate code.

---

## 2. Freeze scope

### 2.1 Frozen (no functional change)

- `api/**/*.php`
- `config/**/*.php`
- `schema.sql` and every `migration_v*.sql`
- `pages/**/*.php`
- `public/**/*.php` and `public/assets/**`
- `.htaccess` and webroot rules
- Existing endpoint response shapes, status codes, permissions, and validation messages

### 2.2 Allowed to change

- `docs/**` (this audit set)
- `tests/**` (additional characterization tests, no weakening of existing assertions)
- `scripts/**` (audit/evidence tooling)
- Evidence files
- Root documentation that points at the canonical baseline

### 2.3 Exception rule

A change to frozen code is allowed only for a **critical security or data-loss defect** and only
with:

1. explicit user approval;
2. a written reproduction;
3. a targeted test;
4. a note in this directory explaining why the freeze was broken.

The known gaps in [`TECHNICAL_DEBT.md`](TECHNICAL_DEBT.md) are **not** automatic exceptions.

---

## 3. Non-negotiable behaviour invariants

These must hold in the PHP backend and in any Flutter implementation that touches the same data.

### 3.1 Authentication and access

1. Every module endpoint requires an authenticated session.
2. There is no anonymous API access.
3. Guest mode is Flutter-local only; it never calls the API.
4. Session lifetime is 24 hours absolute.
5. CSRF is required on every write, including login and signup.
6. Admin-only actions remain admin-only: `settle.php`, `settle_all.php`, group delete,
   member removal, group expense edit/delete.

### 3.2 Settlement

1. Settlement maths uses `paid_by`, never `user_id` or `created_by`.
2. Every normal settlement aggregate excludes `is_post_settlement = 1`.
3. A period closes only when all active members have confirmed.
4. `confirm.php` keeps its transaction + `SELECT … FOR UPDATE`.
5. Late expenses use `post_calculate.php` / `post_confirm.php`, not the normal flow.
6. A group expense dated on or before the latest `period_end` is rejected on
   create/update/delete/price.
7. `0.00` marker settlements remain valid and advance the lock.
8. `settle.php` validates real dates, rejects reversed ranges, and rejects a future
   `period_end`.

### 3.3 Expenses and lists

1. Personal expenses are owner-only; group expenses are admin-only for edit/delete.
2. A personal expense never carries a `group_id`.
3. A priced list item creates exactly one linked expense.
4. Uncheck deletes by exact `expense_id`, never by note/date/amount matching.
5. Uncheck is allowed only within 10 minutes.
6. Group check returns `needs_confirm` and requires `paid_by` to confirm.
7. `checked_at` is converted to a date before use as `expense_date`.

### 3.4 Data integrity

1. Max 5 groups per user; max 10 members per group.
2. Max 5 groups is checked before insert.
3. Notification `reference_id` is not treated as a navigation key.
4. Raw text is stored; escaping happens at render.
5. Money is rounded to 2 dp and bounded 0.01…99 999 999.00 on validated paths.

### 3.5 Contract

1. Success is determined by the `ok` field, not only the HTTP status.
2. Clients tolerate `success` and `code` keys.
3. Idempotency keys are per user intent and reused on retry.
4. 401 means re-authenticate; 429 means wait for `Retry-After`.

---

## 4. Flutter parity matrix

| Product area | PHP reference | Flutter requirement | API needed |
|---|---|---|---|
| Signup / login / logout | `api/signup.php`, `login.php`, `logout.php` | Native forms, same validation and error text | Yes |
| Session expiry | middleware 401 | Route to login on 401 | Yes |
| Home calendar | `app.js`, `expenses/list.php` | Rebuild calendar locally from rows | Yes |
| Expense CRUD | `expenses.js`, `expenses/*` | Full parity, including lock and permissions | Yes |
| Budget | `expenses.js`, `summary.budget` | Set limit, show utilisation | Yes |
| Groups | `groups.js`, `groups/*` | Full parity, including admin actions | Yes |
| Settlements | `expenses.js`, `settlements/*` | Calculate, confirm, history, post-settlement | Yes |
| Lists | `lists.js`, `lists/*` | Full parity, including confirm popup and 10-min window | Yes |
| Unpriced | `expenses.js`, `expenses/unpriced.php` | Price items, show post-settlement flag | Yes |
| Notifications | `notifications.js`, `notifications/*` | Poll count, list, history, mark read | Yes |
| Analytics | `expenses.js` + Chart.js | Reimplement charts locally | No new API |
| PDF export | `expenses.js` + jsPDF | Reimplement locally | No new API |
| Theme | `theme-init.js`, `styles.css` | Reproduce palette and system/light/dark | No |
| Sorting | page modules | Reproduce sort options | No |
| Guest mode | **absent** | Local-only store, no API calls, explicit boundary | No |

### 4.1 Guest mode rules

- Guest data lives only on the device.
- No API request is made while in guest mode.
- The app must state that guest data is not synced.
- Leaving guest mode must not silently upload data.
- Any future import is a separate, explicit feature.

### 4.2 Analytics and PDF

Both are frontend-only today. Flutter must reproduce the output from API rows/aggregates; no
backend endpoint is to be invented for them in Phase 1.

### 4.3 Features that must not be dropped

Everything in [`FEATURE_INVENTORY.md`](FEATURE_INVENTORY.md) is in scope. "The Flutter app does
not have it yet" is not a reason to remove it from the product definition.

---

## 5. Sync readiness

[`DATABASE_AUDIT.md`](DATABASE_AUDIT.md) §7 is a gap analysis only.

Summary:

- No client IDs, no change cursor, no tombstones, no row versions, no conflict policy.
- Several tables lack `updated_at`.
- The outbox is unwired.
- Offline **writes** cannot be safely merged today.

Decision for the migration:

1. Shared data is **online-first**.
2. Guest mode is **local-only**.
3. No sync schema change in Phase 1.
4. A future sync phase must design IDs, cursors, tombstones, and conflicts explicitly.

---

## 6. Deferred work

| Work | Why deferred |
|---|---|
| Section 30 response-contract rollout | Multi-endpoint change (the suite scans 39 `api/` files) with its own regression surface |
| KD-06 service layer | 88 tests; requires real implementation and characterization tests |
| KD-05 ephemeral notifications | Must wire producers and consumers together |
| KD-11 distributed services | No HTTP-path caller |
| Sync schema | Needs a dedicated design phase |
| Dead-code deletion | Not needed for migration; separate cleanup |
| Security gap fixes | Freeze; require explicit approval |

No stubs. No "make the test green" files.

---

## 7. Execution phases after sign-off

### Phase 2 — Client foundation

- Flutter project, routing, theme, API client with CSRF/idempotency/429 handling.
- Auth screens and session bootstrap.
- Contract tests against the live PHP backend.

### Phase 3 — Core parity

- Home/calendar, expenses, budgets, groups.
- Lists and list-to-expense conversion.
- Unpriced items.

### Phase 4 — Settlement parity

- Calculate, confirm, history, details, post-settlement.
- Characterization tests for `paid_by`, `is_post_settlement`, lock, and 3-step confirmation.

### Phase 5 — Analytics, PDF, notifications, guest mode

- Charts and PDF locally.
- Notification polling and history.
- Local-only guest mode.

### Phase 6 — Cutover

- Side-by-side verification.
- Freeze PHP UI, keep API.
- Only then consider service extraction.

---

## 8. Acceptance criteria for Phase 1

Phase 1 is complete when:

- [x] The repository state and snapshot commit are recorded.
- [x] The canonical test baseline is captured and reproducible.
- [x] All 40 HTTP endpoint contracts (36 module + 3 auth + 1 system) are documented from source,
      and the 1 CLI-only worker is documented separately as non-HTTP.
- [x] All 14 tables, constraints, and invariants are documented.
- [x] Every frontend feature and its API call map is documented.
- [x] Security status is reconciled with the latest penetration-test outcome.
- [x] Known debt and verified gaps are recorded without being fixed.
- [x] Guest mode is documented as absent and Flutter-local-only.
- [x] Sync readiness is a gap analysis with no schema change.
- [x] Root docs point to the canonical baseline instead of duplicate counts.
- [ ] Documentation reviewed and signed off by the user.
- [ ] `phase1-audit` tag created **after** sign-off.

---

## 9. Sign-off and tagging

The tag must not be created before review. When approved:

```powershell
git tag -a phase1-audit -m "Phase 1 audit: frozen PHP behaviour documented at 5ed8de3"
git show phase1-audit --stat
```

Recommended sign-off record (append to this file or a review note):

```
Reviewed by:
Date:
Snapshot confirmed: 5ed8de3 (docs at 66dedc1)
Baseline confirmed: 433 passed / 105 failed / 538 tests
Open exceptions approved: none | <list>
Tag authorised: yes/no
```

If review finds a documentation error, correct the document and re-run the baseline; do not
change application code to match the document.
