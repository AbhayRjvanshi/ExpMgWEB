# API Contract Specification

| Field | Value |
|---|---|
| Status | Specification — Phase 2 |
| Date | 2026-11-15 |
| Purpose | State the contract rules for both API generations: which endpoints exist, which are frozen, which are proposed, and the validation and domain rules every write must satisfy |
| Requirements | AP-01, AP-11, SE-05, PR-14, PR-15, PR-16, PR-18, PR-20 |
| Authority for v1 behaviour | [`API_CONTRACT.md`](../API_CONTRACT.md) is the frozen behavioural reference. This document classifies and preserves it; it does not restate it |
| Rule | No v1 request, response, status code, or error string changes in this phase or by this design |

---

## 1. Scope

| Topic | Section |
|---|---|
| The endpoint inventory: existing versus proposed | §2 |
| Transport and base URL | §3 |
| Envelopes, CSRF, rate limits, idempotency (pointers) | §4 |
| The shared validation contract | §6 |
| List and item rules | §7 |
| Settlement invariants | §8 |
| Notifications | §9 |
| The change policy | §10 |

Detail is not duplicated: v1 behaviour → [`API_CONTRACT.md`](../API_CONTRACT.md); new endpoints →
[`SYNC_API_SPECIFICATION.md`](SYNC_API_SPECIFICATION.md); errors → [`API_ERROR_HANDLING.md`](API_ERROR_HANDLING.md).

---

## 2. The endpoint inventory (AP-01)

**Requirement:** AP-01 "existing v1 endpoints keep their exact contracts". **Acceptance criterion 15**
of `WORK/PHASE_2_DESIGN_PLAN.md` §11: existing endpoints are distinguished from proposed ones.

### 2.1 Classification

| Class | Count | Definition | May change? |
|---|---|---|---|
| **FROZEN** | 37 | Existing v1 endpoints whose behaviour is asserted by the suite or the legacy UI | **No.** Any change requires an owner-approved contract change and a new baseline capture |
| **DEPRECATED** | 2 | `settle.php`, `settle_all.php` — unilateral admin settlement endpoints deprecated (UQ-10 Option B approved); respond with `410 Gone` | Deprecated by owner approval; legacy web UI has zero references; normal 3-step workflow (`calculate.php` → `confirm.php`) is authoritative |
| **UNGUARDED-BY-DECISION** | 1 | `logout.php` — no auth, no CSRF, no method guard (KD-31, PR-22) | No. Changing it is an approved change, not a cleanup |
| **CLI-ONLY** | 1 | `workers/process_outbox.php` — 403 over HTTP | No |
| **PROPOSED v2** | 10 | New capabilities under `/api/v2/` (not implemented) | They do not exist yet; their contracts are in §`SYNC_API_SPECIFICATION.md` |

**Totals:** 37 + 2 + 1 + 1 = 41 files in `api/` excluding `bootstrap.php`, of which **40 are HTTP
endpoints** and 1 is CLI-only. That matches the verified tree and `API_CONTRACT.md` §9.1.

> [!NOTE]
> **Owner-Approved Exception to Frozen v1 Surface (UQ-10 Option B):**
> Existing v1 endpoints are normally frozen against status code and response modifications (D-31, PR-23). The project owner has specifically approved deprecating the two administrative settlement endpoints (`api/settlements/settle.php` and `api/settlements/settle_all.php`) to return HTTP `410 Gone` as an explicit, narrow exception (UQ-10 Option B). Dependency verification confirmed that the legacy website UI has zero references to either endpoint and the collaborative 3-step workflow (`calculate.php` → `confirm.php`) covers 100% of legitimate settlement operations. When implemented post-UQ-20, **both** `tests/run_tests.php` Section 17 and `scripts/pentest/penetration_test.php` (cases S07–S11) will be updated to verify HTTP 410. Actual modifications remain strictly on hold until formal Phase 2 sign-off (UQ-20).

### 2.2 Existing HTTP endpoints (FROZEN unless noted)

| # | Endpoint | Method | Auth | Authorisation | Class |
|---|---|---|---|---|---|
| 1 | `api/login.php` | POST | public | credentials + CSRF + IP limit | FROZEN (public) |
| 2 | `api/signup.php` | POST | public | CSRF + IP limit | FROZEN (public) |
| 3 | `api/logout.php` | any | none | none | **UNGUARDED-BY-DECISION** |
| 4 | `api/expenses/list.php` | GET | yes | own rows | FROZEN |
| 5 | `api/expenses/create.php` | POST | yes | owner (personal) / member (group) | FROZEN |
| 6 | `api/expenses/update.php` | POST | yes | owner / admin | FROZEN |
| 7 | `api/expenses/delete.php` | POST | yes | owner / admin | FROZEN |
| 8 | `api/expenses/summary.php` | GET | yes | own rows | FROZEN |
| 9 | `api/expenses/categories.php` | GET | yes | any authenticated | FROZEN |
| 10 | `api/expenses/unpriced.php` | GET | yes | own lists / group lists | FROZEN |
| 11 | `api/expenses/price_unpriced.php` | POST | yes | list owner / admin; lock | FROZEN |
| 12 | `api/groups/create.php` | POST | yes | ≤ 5 groups | FROZEN |
| 13 | `api/groups/join.php` | POST | yes | valid code, ≤ 10 members, ≤ 5 groups | FROZEN |
| 14 | `api/groups/leave.php` | POST | yes | own membership | FROZEN |
| 15 | `api/groups/delete.php` | POST | yes | admin | FROZEN |
| 16 | `api/groups/details.php` | GET | yes | member | FROZEN |
| 17 | `api/groups/user_groups.php` | GET | yes | own memberships | FROZEN |
| 18 | `api/groups/remove_member.php` | POST | yes | admin; cannot remove an admin | FROZEN |
| 19 | `api/lists/create.php` | POST | yes | owner / admin | FROZEN |
| 20 | `api/lists/delete.php` | POST | yes | owner / admin | FROZEN |
| 21 | `api/lists/details.php` | GET | yes | owner / member | FROZEN |
| 22 | `api/lists/user_lists.php` | GET | yes | own lists | FROZEN |
| 23 | `api/lists/add_item.php` | POST | yes | owner / admin | FROZEN |
| 24 | `api/lists/remove_item.php` | POST | yes | owner / admin | FROZEN |
| 25 | `api/lists/check_item.php` | POST | yes | owner / admin; lock; 600 s window | FROZEN (§7) |
| 26 | `api/budgets/get.php` | GET | yes | own budget | FROZEN |
| 27 | `api/budgets/set.php` | POST | yes | own budget | FROZEN |
| 28 | `api/settlements/calculate.php` | GET | yes | member | FROZEN |
| 29 | `api/settlements/confirm.php` | POST | yes | member; transaction + `FOR UPDATE` | FROZEN (§8) |
| 30 | `api/settlements/settle.php` | POST | yes | **deprecated** | **DEPRECATED (410 Gone)** |
| 31 | `api/settlements/settle_all.php` | POST | yes | **deprecated** | **DEPRECATED (410 Gone)** |
| 32 | `api/settlements/post_calculate.php` | POST | yes | member | FROZEN |
| 33 | `api/settlements/post_confirm.php` | POST | yes | member | FROZEN |
| 34 | `api/settlements/history.php` | GET | yes | member | FROZEN |
| 35 | `api/settlements/details.php` | GET | yes | member | FROZEN |
| 36 | `api/notifications/count.php` | GET | yes | own notifications (heartbeat bucket) | FROZEN |
| 37 | `api/notifications/list.php` | GET | yes | own notifications; read-only | FROZEN |
| 38 | `api/notifications/history.php` | GET | yes | own notifications; read-only | FROZEN |
| 39 | `api/notifications/read.php` | POST | yes | recipient | FROZEN |
| 40 | `api/system/health.php` | GET | yes | any authenticated | FROZEN |

**Plus:** `api/workers/process_outbox.php` (CLI-only, 403 over HTTP).

### 2.3 Proposed v2 endpoints (not implemented)

| # | Endpoint | Method | Purpose |
|---|---|---|---|
| 1 | `/api/v2/auth/session.php` | GET | Session status + CSRF bootstrap (closes F-01) |
| 2 | `/api/v2/devices/register.php` | POST | Register this installation (D-34, UQ-17) |
| 3 | `/api/v2/devices/list.php` | GET | List the caller's devices |
| 4 | `/api/v2/devices/revoke.php` | POST | Revoke a device and purge session (D-25, UQ-04) |
| 5 | `/api/v2/sync/bootstrap.php` | GET | Full, paged initial sync |
| 6 | `/api/v2/sync/pull.php` | GET | Incremental changes by cursor |
| 7 | `/api/v2/sync/push.php` | POST | Apply queued offline operations (batch ≤ 100) |
| 8 | `/api/v2/sync/state.php` | GET | Server-side sync state (digests, cursor validity) |
| 9 | `/api/v2/reminders/ack.php` | POST | Acknowledge reminder receipt; reset 24h timer (D-35, UQ-18) |
| 10 | `/api/v2/groups/archive.php` | POST | Initiate group archival (D-26, UQ-07) |
| 11 | `/api/v2/groups/restore.php` | POST | Restore archived group within 3 months (D-26, UQ-07) |
| 12 | `/api/v2/groups/permanent_delete.php` | POST | Permanently delete group after 6 months (D-26, UQ-07) |
| 13 | `/api/v2/import/guest.php` | POST | Guest-data import (two-tier duplicate check) |
| 14 | `/api/v2/import/status.php` | GET | Import status and verification data |

Full contracts: [`SYNC_API_SPECIFICATION.md`](SYNC_API_SPECIFICATION.md). **None of these exists.**

### 2.4 What "frozen" means

| Frozen | Not frozen |
|---|---|
| Request field names and accepted values | Internal refactors that preserve behaviour |
| Response field names, types, and shapes | Adding a new v2 endpoint |
| HTTP status codes for each condition | Documentation |
| Error strings | Adding an index or a database object that does not change a response |
| Pagination defaults and ceilings | Logging changes that add no fields |

**Permitted exceptions (additive, behaviour-preserving):**

| Change | Condition |
|---|---|
| An added `uuid` bind in an `INSERT` | Required by the schema migration (`DATABASE_MIGRATION_STRATEGY.md` §5.2); no readable field changes |
| An added log line | No response change; no secret logged |
| An added index | No response change; `EXPLAIN` parity checked |
| The middleware setting actor context | After the existing checks; no response change (`BACKEND_COEXISTENCE.md` §3.5) |

---

## 3. Transport and base URL

| Aspect | Rule |
|---|---|
| Scheme | HTTPS in any non-local deployment; the session cookie's `Secure` flag follows |
| Base URL | Relative to the deployment root; the client configures one base and appends paths |
| Methods | `GET` for reads, `POST` for writes; no `PATCH`/`PUT`/`DELETE` verbs in the product |
| Encoding | `application/x-www-form-urlencoded` for v1 POSTs (matching `formData` in the legacy client); v2 accepts JSON bodies |
| CORS | **None.** Same-origin only (D-08) |
| Compression | Server-configured; no API dependency |
| Response size | Bounded by page sizes and batch limits; no endpoint returns an unbounded collection |

---

## 4. Envelopes, CSRF, rate limits, idempotency

| Topic | Authority |
|---|---|
| Response envelope | [`API_ERROR_HANDLING.md`](API_ERROR_HANDLING.md) §2; [`API_CONTRACT.md`](../API_CONTRACT.md) §3 |
| CSRF contract | [`API_CONTRACT.md`](../API_CONTRACT.md) §4; [`../api/AUTHENTICATION_AND_AUTHORIZATION.md`](AUTHENTICATION_AND_AUTHORIZATION.md) §5 |
| Rate limiting | [`API_ARCHITECTURE.md`](API_ARCHITECTURE.md) §8 |
| Idempotency | [`API_ARCHITECTURE.md`](API_ARCHITECTURE.md) §6; [`API_CONTRACT.md`](../API_CONTRACT.md) §6 |
| Pagination | [`API_ARCHITECTURE.md`](API_ARCHITECTURE.md) §7 |

**Not restated here.** Duplicating a contract in two documents is how the two disagree.

---

## 5. Write-path semantics

| Rule | Statement |
|---|---|
| Statements are prepared, never interpolated | `prepare` → `bind_param` → `execute`; `close()` afterwards; the statement object is checked before binding |
| Multi-step writes are transactional | `begin_transaction()` … `commit()`, with `rollback()` on any failure path |
| A response is emitted only after `commit()` | A success response before the commit would report work that may not exist |
| Rows affected are checked where a precondition exists | An `UPDATE … WHERE version = ?` that affects zero rows is a conflict, not a success (`../database/VERSIONING_AND_CONFLICT_RESOLUTION.md` §4.1) |
| Locking reads are used where a race exists | `FOR UPDATE` in `confirm.php` and `price_unpriced.php` today; the same pattern for the same operations in v2 (PR-05) |
| No `GET` performs a write | KD-07's fix is preserved: notification GETs are read-only |
| A trigger failure fails the request | A write that is not change-logged would desynchronise clients permanently (D-13) |
| Partial multi-record failure is surfaced per record | A v2 push reports per-operation outcomes; a v1 endpoint either fully succeeds or reports one failure |
| A busy/duplicate-key error is mapped, not leaked | A `1062` on `uuid` is `409 DUPLICATE_ID`; a deadlock is retried once, then reported |

---

## 6. The shared validation contract (AP-11, SE-05)

**Requirements:** AP-11 "validation rules are shared, not re-implemented"; SE-05 "input validation is
server-side and shared".

### 6.1 The rule

| Rule | Statement |
|---|---|
| Validation happens on the server, always | Client-side validation is UX only (AC-6) |
| Both surfaces use the same validators | `helpers/validator.php` plus the `api/domain/` helpers once extracted (D-30) |
| A v2 write and a v1 write reject the same input identically | Same rule, same message semantics |
| Validation happens before any database write | Including before a transaction begins where possible |
| A validation failure never partially applies | Multi-record inputs are validated as a whole before the first write |

### 6.2 Field rules

| Field | Rule | Source |
|---|---|---|
| Amount | Numeric, `> 0`, ≤ 99 999 999.00, rounded to 2 decimals | §6.3 |
| Date | A real calendar date (`validateDate()`), never a bare regex — `2024-02-30` must fail | `AGENTS.md` |
| Month | `YYYY-MM` with a real month range (1–12) | `validator.php` |
| String | `trim()`, then `mb_strlen()` against the column limit **before** insert | `AGENTS.md` |
| Enum | Exactly one of the allowed values (`type`, `role`, `priority`) | `AGENTS.md` |
| Identifier | An integer id where v1 expects one; a UUID where v2 expects one; never a raw string in SQL | `AGENTS.md` |
| Boolean-ish | `0`/`1`; a truthy string is not silently accepted | `AGENTS.md` |
| Unknown fields | Ignored, not rejected | `ACCESS_CONTROL.md` §7.2 |

### 6.3 Money (PR-20)

**Requirement:** PR-20 "money is rounded to 2 dp and bounded 0.01…99 999 999.00".

| Aspect | v1 (frozen) | v2 (proposed) |
|---|---|---|
| Accepted input | A numeric string or number | A decimal string |
| Rounding | Two decimals on cast and storage | Two decimals; more than two decimals is a validation failure, not a silent round |
| Lower bound | `> 0` for expenses, prices, and budgets | Same |
| Upper bound | 99 999 999.00 | Same |
| Storage | `DECIMAL(12,2)` | Same |
| Wire format | Mixed: some endpoints emit `"24.50"`, others `24.5` (finding F-06) | Always a string with exactly two decimals (`"24.50"`) |
| Client handling | Parse defensively | Parse to integer cents (D-21) |
| Negative amounts | Rejected by `CHECK (amount > 0)` and by validation | Same |
| Zero | Rejected for amounts, prices, and budgets; **valid** for `settlements.amount` (`0.00` marker, PR-08) | Same |
| Settlement amounts | `>= 0`, no upper bound enforced in the schema | Same; the calculation endpoint's output is what the client displays |

| Rule | Statement |
|---|---|
| The upper bound is a business rule, not a type limit | `DECIMAL(12,2)` would hold 9 999 999 999.99; the 99 999 999.00 ceiling is deliberate |
| The ceiling is enforced on every write path | `DATABASE_AUDIT.md` records that `check_item.php` confirm mode historically lacked it; the sync path must enforce it through the shared helper |
| A rounded value is never silently different from what the user typed | v2 rejects a third decimal instead of rounding, so the wire value always matches the intent |
| The v1 mixed format is documented, not fixed | Changing it would break the frozen contract; the Flutter client parses both |

### 6.4 Validation message rules

| Rule | Statement |
|---|---|
| A message names the field and the rule | "Amount must be greater than zero", not "Invalid input" |
| Messages are not localised in the API | The client localises; the API's English strings are for logs and support |
| A permission refusal is not phrased as a validation error | Different causes get different messages (`../security/ACCESS_CONTROL.md` §9) |
| A refusal never echoes the submitted value for a sensitive field | No passwords, tokens, or secrets in a response body |

---

## 7. List and item rules (PR-14, PR-15, PR-16)

**Requirements:** PR-14 (600-second window), PR-15 (`needs_confirm` + `paid_by`), PR-16
(`checked_at` conversion).

### 7.1 Checking a priced item (PR-12/PR-13 with PR-15)

| Step | Behaviour |
|---|---|
| Precondition | The item has a `price`; the item is not already checked; the linked list is writable by the caller |
| Personal list | The expense is created immediately; `list_items.expense_id` is stored; `is_checked = 1`; `checked_at = NOW()` |
| Group list | The endpoint returns `needs_confirm: true` **and does not create the expense**. The caller repeats the call with `paid_by` |
| Confirmation call | `paid_by` must be an active member of the group; the expense is created with the group's context; the item is linked |
| Notification | `reference_id` is the **expense id**, never the group id (`AGENTS.md`) |
| Settlement lock | Checked before creating the expense; a locked period refuses the check (PR-03) |
| Partial failure | Impossible: the item update and the expense insert are in one transaction |

### 7.2 Unchecking (PR-14)

| Rule | Statement |
|---|---|
| Window | Unchecking is permitted only within **600 seconds** of `checked_at` |
| Who measures it | The **server**, against its own clock. A client countdown is advisory |
| Deletion target | The expense identified by `list_items.expense_id` — exactly. Matching by note + date + amount is forbidden (PR-13, the v1.3 bug) |
| Null link | If `expense_id` is null, the uncheck clears the flag and deletes nothing (the linked expense was already removed) |
| After the window | Refused with the existing error; the item stays checked |
| Group items | Follow the same rule; the expense deletion is subject to the settlement lock |

### 7.3 The `checked_at` conversion (PR-16)

**Requirement:** PR-16 "`checked_at` is converted to a date before use as `expense_date`".

| Rule | Statement |
|---|---|
| `list_items.checked_at` is a **datetime** | It drives the 600-second window |
| Any use as a date must convert | `date('Y-m-d', strtotime($item['checked_at']))` |
| Forbidden | Using the raw datetime string as `expense_date`; it breaks date comparisons (`AGENTS.md`, the v1.3 fix) |
| Applies to | The check path, the pricing path (`price_unpriced.php`), and any v2 push that creates an expense from an item |
| Time zone | The server's configured zone, consistently (SY-20) |

### 7.4 Item fields and ordering

| Field | Rule |
|---|---|
| `description` | Required, trimmed, ≤ 255 chars |
| `category` | Must resolve to an existing category |
| `priority` | One of `high`, `moderate`, `low` |
| `price` | Nullable; if set, §6.3 bounds apply |
| Ordering | Deterministic: priority band, then creation time (matching `idx_list_priority`) |
| Group list items | Read-only offline; writable online by an admin |

---

## 8. Settlement invariants (preserved)

The full statements are in
[`../database/DATA_OWNERSHIP.md`](../database/DATA_OWNERSHIP.md) §4.4, §5.6 and
[`../database/DATABASE_SCHEMA_SPECIFICATION.md`](../database/DATABASE_SCHEMA_SPECIFICATION.md) §7.2.
The API-level obligations:

| # | Obligation |
|---|---|
| 1 | Every balance aggregate groups by `paid_by` (PR-01) and filters `is_post_settlement = 0` (PR-02) |
| 2 | `confirm.php` keeps its transaction and `SELECT … FOR UPDATE` (PR-05); a bare `INSERT` is a regression |
| 3 | A period closes only when every active member has a confirmation row for the server-computed period (PR-04) |
| 4 | A group expense dated inside a settled period is refused on create, update, delete, and price (PR-03) |
| 5 | `settle.php` / `settle_all.php` are deprecated (respond with HTTP 410 Gone; UQ-10 Option B) |
| 6 | `0.00` marker settlements are valid and advance the lock (PR-08) |
| 7 | Late expenses (`is_post_settlement = 1`) never enter a normal aggregate; they use the post-settlement flow (PR-06) |
| 8 | The client never computes a settlement result for display; it displays the endpoint's output |

**v2 obligation:** the sync push path calls the same helpers, so obligations 1–7 hold for pushed
writes too (`BACKEND_COEXISTENCE.md` §6).

---

## 9. Notifications (PR-18)

**Requirement:** PR-18 "notification `reference_id` is never a navigation key".

| Rule | Statement |
|---|---|
| `reference_id` points to the entity that caused the notification | An expense id, per `AGENTS.md` — never a group id |
| It is **not** a navigation key | The client must not build a "go to this record" link from it: the meaning varies by producer (`DATABASE_AUDIT.md` §2.9) |
| There is no `event_id` column | `list.php`/`history.php` synthesise `event_id = id` for compatibility; the column does not exist |
| A notification may reference a deleted entity | The reference is not a foreign key and is not validated on read |
| `type` is a free-form varchar | The client renders a generic notification when it does not recognise the type |
| Read-only GETs | `list.php` and `history.php` perform no writes (KD-07 fix) |
| Retention | 7 days, by `scripts/cleanup_notifications.php` |
| Sync | Not synchronised (D-19) |

**Client obligation:** tapping a notification navigates by an explicit, producer-supplied route when
one exists in a future version; until then it opens the notifications list only. Guessing a target
from `reference_id` is forbidden.

---

## 10. The contract change policy

| Change | Requirement |
|---|---|
| A v1 behavioural change | An owner-approved decision, a migration note, and a new test-baseline capture |
| A v1 additive field | **Not permitted** — "additive" is still a contract change for a frozen client |
| A new v2 endpoint | Documented in §`SYNC_API_SPECIFICATION.md` §4 with all 15 attributes before implementation |
| A v2 field addition | Allowed; the client ignores unknown fields |
| A v2 field removal or semantic change | A new version or an approved breaking change (`API_VERSIONING.md` §6) |
| Removing a v1 endpoint | Only at the Phase 6 cutover (`MIGRATION_PRESERVATION_PLAN.md` §7) |

---

## 11. Status of architectural questions

| UQ | Status | Decision & Policy |
|---|---|---|
| UQ-10 — settlement admin operations | `APPROVED` | Option B approved: `settle.php` and `settle_all.php` formally deprecated (HTTP 410 Gone) as an owner-approved narrow exception to frozen v1 contract; collaborative 3-step workflow authoritative; post-UQ-20 test planning covers both `run_tests.php` and `penetration_test.php`; actual changes on hold until UQ-20 |
| UQ-01 — guest data import | `APPROVED` | Option C: Skip preserves guest partition; dry run with two-tier duplicate check |
| UQ-11 — group expense provenance | `APPROVED` | Distinguish deliberate conversion (`type='personal'`) from detached group expenses (`type='group'`, `is_detached=1`) |

---

## 12. Verification

| Requirement | Method | Pass condition |
|---|---|---|
| AP-01 v1 contracts unchanged | `CONTRACT-TEST` | Every §2.2 endpoint returns the documented shape and status for a positive and a negative case |
| §2 distinguishes existing from proposed | `DOC-REVIEW` | Accept criterion 15; the two tables are disjoint and complete (40 HTTP + 1 CLI) |
| AP-11 / SE-05 shared validation | `UNIT-TEST` | The same invalid input is refused identically through v1 and through a v2 write |
| PR-14 600-second window | `CONTRACT-TEST` | Uncheck succeeds at 599 s and fails at 601 s (server clock) |
| PR-15 `needs_confirm` and `paid_by` | `CONTRACT-TEST` | A group check without `paid_by` returns `needs_confirm` and creates nothing; with a non-member payer it is refused |
| PR-16 `checked_at` conversion | `UNIT-TEST` | An expense created from a checked item carries the date part of `checked_at`, not the datetime |
| PR-18 `reference_id` | `DOC-REVIEW` | §9 states the rule; no client code constructs a route from it |
| PR-20 money bounds | `UNIT-TEST` | 0, negative, 100 000 000.00, and three-decimal inputs behave per §6.3 |
| §8 settlement invariants | `CONTRACT-TEST` | Each of the eight obligations has a test; the settlement suite passes unchanged |
