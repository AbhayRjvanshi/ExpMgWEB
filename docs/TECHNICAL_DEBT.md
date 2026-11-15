# Technical Debt

This is the current, source-verified debt register at snapshot `5ed8de3`. It supersedes the
debt table in `AGENTS.md` where they differ.

Debt is classified as:

- **Preserve** — intentional current behaviour; do not "fix" during Phase 1.
- **Deferred** — planned work with its own change surface; not a migration dependency.
- **Dead** — code with no live caller; deletion is safe but out of scope.
- **Doc drift** — documentation that no longer matches source.

---

## 1. Known debt register

| ID | Item | Current state | Class | Safe action |
|---|---|---|---|---|
| KD-01 | Hardcoded DB credentials | **Fixed** — `config/db.php` reads `env('DB_*')` | Done | — |
| KD-02 | `api/bootstrap.php` dead | Included by zero files; duplicates CSP/session hardening now in `config/session.php` | Dead | Delete, or make it the single front controller (would double-send headers if added to endpoints) |
| KD-03 | `lists.js` never loaded | **Fixed** — `pages/lists.php` loads it | Done | — |
| KD-04 | Idempotency unused | **Fixed** — 11 write endpoints wrapped; `helpers.js` sends per-intent keys | Done | — |
| KD-05 | Ephemeral notification system unwired | Producers and consumers still use MySQL; publisher/store dormant | Deferred | Wire producers **and** consumers together, or delete the abstraction |
| KD-06 | Business service layer missing | `ExpenseService`, `GroupService`, `SettlementService`, `ListService`, `BudgetService`, `NotificationService` do not exist | Deferred | Real implementation only; never stubs |
| KD-07 | DELETE on notification GET | **Fixed** — GETs are read-only; retention is CLI cleanup | Done | — |
| KD-08 | Orphan idempotency `.lock` files | Swept when older than 30 s | Preserve | Safe to delete stale locks manually |
| KD-09 | `logs/app.log` web-accessible | **Fixed** — web-denied | Done | Stronger fix is a `public/` docroot |
| KD-10 | `seed.sql` tracked with bcrypt hashes | **Fixed** — no longer tracked | Done | History rewrite optional |
| KD-11 | `api/services/*` dormant | Only tests/stress/CLI worker include them | Dead/Deferred | Wire deliberately or delete |
| KD-12 | Schema CHECK/index parity | **Fixed** in `schema.sql` + `migration_v1.8.sql` | Done | — |

Additions from this audit:

| ID | Item | Evidence | Class |
|---|---|---|---|
| KD-13 | `schema.sql` lacks `chk_settlement_period` | Constraint only in `migration_v2.9_settlement_hardening.sql` | Doc drift / schema drift |
| KD-14 | Root `run_tests.php` is a stale duplicate | Canonical suite is `tests/run_tests.php` | Dead |
| KD-15 | `partials/lists_section.php` dead | Not included by any live page | Dead |
| KD-16 | Inline `onclick` handlers in `pages/lists.php` | CSP blocks them; close buttons fail | Preserve / UI bug |
| KD-17 | `expenses/update.php` target-group authorization | Members can move expenses across groups / set arbitrary `paid_by` | Preserve / security debt |
| KD-18 | `groups/join.php` capacity race | Read-then-insert, no transaction | Preserve |
| KD-19 | `groups/create.php` non-atomic create | Group insert and admin membership insert are separate | Preserve |
| KD-20 | `post_confirm.php` no `FOR UPDATE` | Confirmation/finalize race | Preserve |
| KD-21 | `check_item.php` confirm price has no ceiling | Only `> 0`; schema CHECK may reject the expense insert | Preserve |
| KD-22 | Group check strands unconfirmed items | Item checked before popup; not visible to `unpriced.php` | Preserve |
| KD-23 | Notification `reference_id` is polymorphic | Expense ID on create, group/list ID elsewhere | Preserve (UI does not navigate) |
| KD-24 | `budgets/get.php` unused by UI | Budget read from `summary.php` | Preserve |
| KD-25 | `settle.php` / `settle_all.php` unused by UI | No frontend caller | Preserve (API completeness) |
| KD-26 | `settlements/details.php` does not filter `is_post_settlement` | Period report includes late expenses dated in the range | Preserve |
| KD-27 | `expenses/update.php` ineffective type check | `$type` already normalized before the check | Preserve |
| KD-28 | `graphify-out/` stale | Generated 2026-06-03 | Regenerate before navigation use |
| KD-29 | Docs quote 427/112/539 | Actual 433/105/538 | Fix docs (done in Phase 1) |
| KD-30 | `Issues.txt` claims `settle.php` is regex-only | Source uses `validateDate()` | Fix docs (done in Phase 1) |

---

## 2. Section 30 — response contract rollout

**Status: deferred, not rejected.**

The module folders now include `response.php` and use `apiResponse`/`apiError`/method guards,
but adoption is incomplete:

- Some endpoints still use raw `json_encode(['ok'=>false,…])` and return HTTP 200 for a handled
  business error.
- Some method checks return `apiResponse(['ok'=>false,'error'=>'Invalid method.'])` (HTTP 400)
  instead of `405 + Allow`.
- Validation errors are not uniformly 400.
- Not every endpoint has a `try/catch`; the global handler covers the rest.

Finishing section 30 means touching every `api/` file the suite scans — 39 files, comprising the
36 module endpoints, `system/health.php`, the CLI worker, and `helpers/outbox.php`, excluding
the 3 auth endpoints and the named helpers — then re-running the full suite. It has its own
regression surface and must not be bundled into the Flutter migration. The contract documents
the **effective** behaviour so the client can cope with both generations.

---

## 3. Service layer (KD-06)

The planned `api/services/ExpenseService.php`, `GroupService.php`, `SettlementService.php`,
`ListService.php`, `BudgetService.php`, and `NotificationService.php` do not exist. Section 28
of the suite contains 88 assertions that expect them.

Rules:

- Do not create stubs to make tests pass.
- Do not extract business logic out of endpoints during Phase 1.
- A real extraction happens only after the Flutter migration, with characterization tests
  written first.
- The stable API contract in `API_CONTRACT.md` is the behaviour those tests must pin.

---

## 4. Dormant distributed stack (KD-11)

Files with no HTTP-path caller:

```
api/services/RedisService.php
api/services/CacheService.php
api/services/LockService.php
api/services/HealthService.php
api/services/MetricsService.php
api/services/PredictiveHealthService.php
api/services/SystemOrchestrator.php
api/services/NotificationService_Redis.php
api/services/FileNotificationStore.php
api/services/NotificationStore.php
api/helpers/notification_publisher.php
api/helpers/notification_store.php
api/workers/process_outbox.php          (CLI only)
```

`api/helpers/outbox.php` is half-live: `outboxStats()` feeds the health endpoint, but nothing
enqueues events.

Do not design sync around this stack. It has no producer, no consumer on the request path, and
no payload contract.

---

## 5. Dead code

| File | Why dead | Risk of deletion |
|---|---|---|
| `api/bootstrap.php` | Never included | Low, but confirm no deploy script includes it |
| `partials/lists_section.php` | Not included | Low |
| `run_tests.php` (root) | Stale duplicate of `tests/run_tests.php` | Medium — scripts may call it; verify first |
| `graphify-out/` | Stale generated output | Low (regenerate, don't hand-edit) |

Deletion is **not** part of Phase 1. Recorded so nobody mistakes these files for live code.

---

## 6. Behavioural quirks that look like bugs but are contract

| Quirk | Why it must stay |
|---|---|
| `settlements.amount >= 0` | All-equal periods write `0.00` markers that advance the lock |
| `paid_by` can be NULL | Legacy rows exist; settlement code must tolerate it |
| Confirmation unique key is `(group_id, user_id)` | Count logic depends on one pending row per member |
| `period_end = max(last expense, today)` | UI and lock rely on it |
| `expenses/list.php` pagination differs by mode | 20/50 for date, 500/1000 for month/range |
| Notifications default 30/50 | UI expects 30 |
| `settle_all` clears all confirmations | Keeps post-conditions identical to `confirm.php` |
| Post-settlement is a separate table/flow | Mixing it into normal balances double-counts |
| Raw text in DB | Write-time escaping corrupts data |
| `apiResponse` returns 400 on `ok:false` | Client must read `ok`, not assume 200 |

---

## 7. Documentation drift found

| Document | Claim | Reality |
|---|---|---|
| `README.md` | 427/112/539 | 433/105/538 |
| `AGENTS.md` | 427/112/539 | 433/105/538 |
| `AGENTS.md` | "42 API files" | 64 PHP files under `api/`; 41 endpoint/auth/worker files = 40 HTTP endpoints (36 module + 3 auth + 1 system) + 1 CLI worker |
| `Issues.txt` | `settle.php:29` regex-only | `validateDate()` is used |
| `Issues.txt` | 427/112/539 | 433/105/538 |
| `graphify-out/GRAPH_REPORT.md` | June 2026 architecture | Stale; source is authority |
| `ExpMgWEB_Audit_Report.md` | June 2026 security status | Superseded by `PENTEST/FINAL_OUTCOME.md` |

Phase 1 corrects the live docs to point at [`TEST_BASELINE.md`](TEST_BASELINE.md). Older
reports are left in place as historical records.

---

## 8. Priority guidance

If a future phase is allowed to change backend code, the order should be:

1. **Critical data-loss/security only** — target-group authorization on expense update;
   `check_item.php` price ceiling; join capacity race.
2. **Correctness under concurrency** — `post_confirm.php` `FOR UPDATE`; group create
   transaction; stranded group list items.
3. **Client compatibility** — finish section 30 with a versioned contract.
4. **Service extraction** — KD-06 with characterization tests.
5. **Dead-code removal** — bootstrap, partial, root suite, stale graphify.
6. **Sync design** — only with a dedicated schema/ID/cursor/tombstone plan.

Nothing in this list is authorised during Phase 1.
