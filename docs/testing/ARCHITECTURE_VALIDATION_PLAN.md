# Architecture Validation Plan

| Field | Value |
|---|---|
| Status | `PROPOSED` — Phase 2 target design. No test in this document is implemented. |
| Phase | 2 (Target Architecture and Technical Design) |
| Work package | WP-12 |
| Requirements | TS-01 … TS-12, SE-13, PR-23, CO-I-6, FL-03, FL-08, DB-09 |
| Decisions used | D-13, D-16, D-17, D-21, D-27, D-28, D-30, D-31 |
| Open questions | UQ-01, UQ-02, UQ-03, UQ-04, UQ-06, UQ-07, UQ-11, UQ-14, UQ-16 |
| Supersedes | Nothing. Phase 1 documents the baseline; it does not plan validation. |

This document states **how each architectural claim is proven**, including the failure paths, and what
"passed" means. It exists because most of Phase 2 is a claim about behaviour that does not exist yet:
without a test obligation, "the design handles it" is an assertion.

The one thing it does not do is change the current suite. The canonical suite stays exactly as it is
(§1.3).

---

## 1. Scope and principles

### 1.1 What this plan covers

| Area | Section |
|---|---|
| Backend coexistence and regression preservation | §3 |
| Database migration, backfill, rollback, engine parity | §4 |
| API contracts (v1 and v2) | §5 |
| Backend correctness and authorisation | §6 |
| Guest mode and guest import | §7 |
| Offline storage | §8 |
| Sync convergence | §9 |
| Conflict resolution | §10 |
| Multi-device | §11 |
| Idempotency | §12 |
| Security | §13 |
| Platform smoke (including Web/CSP) | §14 |
| Coverage and exit criteria | §15 |

### 1.2 What this plan does not cover

| Not covered | Why |
|---|---|
| Load and capacity testing | No requirement states a throughput target; the dataset is household-scale (`DEPLOYMENT_ARCHITECTURE.md` §2.3) |
| Penetration testing of the legacy surface from scratch | Already performed ([`../../PENTEST/FINAL_OUTCOME.md`](../../PENTEST/FINAL_OUTCOME.md)); §13.3 re-runs the controls that Phase 2 could weaken |
| Store review, signing, accessibility certification | Product/legal work |
| Tests for an unresolved policy | UQ-01, UQ-02, UQ-04, UQ-07, UQ-11, UQ-16 have no decided behaviour to assert (§15.3) |
| Testing the optional Laravel module | Dormant until UQ-06; only the gateway contract is testable (§5.5) |

### 1.3 The baseline is a fixed point (SE-13, CO-I-6)

| Fact | Value |
|---|---|
| Canonical suite | `tests/run_tests.php` (CLI only; `403` over HTTP) |
| Baseline | **433 passed / 105 failed / 538 tests** |
| Authority | [`docs/TEST_BASELINE.md`](../TEST_BASELINE.md) |
| Rule | Any Phase 2 implementation step must leave this number unchanged unless a baseline update is explicitly reviewed and recorded |

| Rule | Statement |
|---|---|
| New tests are **not** merged into `run_tests.php` during Phase 2 | A new suite that changes the denominator would hide a regression in the 433 |
| New suites live in their own directory (`tests/v2/`, `PROPOSED`) and report separately | The canonical number keeps its meaning |
| The 105 known failures are not "fixed" to make a number look better | Each has a recorded cause (`docs/TEST_BASELINE.md`); three are rejected by policy (write-time HTML escaping) |
| Status of 105 baseline failures for Phase 2 sign-off | **Inherited Baseline:** The 105 failures are treated as an inherited Phase 1 baseline to investigate before implementation, rather than making their resolution a prerequisite for Phase 2 sign-off |
| A test that passes only because a feature is absent is not a pass | The 88 service-layer failures (KD-06) are the example |

### 1.4 Verification methods

The six labels below are used across every Phase 2 document; three further methods appear where they
are the honest instrument.

| Method | What it means | Where it runs |
|---|---|---|
| `DOC-REVIEW` | Two documents are read against each other and against the source | Review, no execution |
| `STATIC-CHECK` | A grep/AST assertion over the tree (for example "no interpolated SQL", "no `session_start()` in an endpoint") | CLI |
| `UNIT-TEST` | A function-level test of a domain helper | PHP CLI |
| `CONTRACT-TEST` | An HTTP-level assertion on envelope, status, error string, and side effect | HTTP against a test database |
| `INTEGRATION-TEST` | Multi-component behaviour including the database, triggers, and transactions | HTTP/CLI + MySQL |
| `MIGRATION-TEST` | Forward, backfill, idempotent re-run, and rollback of a schema change | CLI + a scratch database |
| `PENTEST` | An adversarial attempt with a defined expected refusal | HTTP, scripted |
| `MANUAL-SCENARIO` | A scripted human walkthrough with observable pass conditions | A real browser/app |
| `PLATFORM-SMOKE` | The same short script on each supported platform | Devices/emulators |

### 1.5 Environment requirements

| Requirement | Detail |
|---|---|
| Host | XAMPP (Apache + MySQL/MariaDB) as in `DEPLOYMENT_ARCHITECTURE.md` §1.2 |
| Database | A **separate** test database; never production (§3.1 of the deployment doc) |
| Two independent clients | Two devices/emulators (or a device and a browser profile) — TS-08 requires independent clients, not two tabs |
| Clock control | The client clock is untrusted (SY-20); tests must be able to shift a device clock |
| Network fault injection | A proxy or an in-app toggle that can drop, delay, and duplicate requests |
| A second engine | MySQL 8 and MariaDB 10.4 for the parity spike (§4.3) |
| A real browser with DevTools | For the CSP console-clean check (§14.2) |

### 1.6 Evidence and reporting

| Rule | Statement |
|---|---|
| Every test case has an ID | `T-<area>-<nn>`; referenced by the requirement it proves |
| Every case states a pass condition | "Works" is not a pass condition |
| A failure records the observed value, not just "failed" | Especially for money, status codes, and counts |
| A skipped case names the blocking question | For example "blocked by UQ-01" |
| Evidence is attached to the release record | §5.3 of the deployment doc |

---

## 2. Test levels and what each one can prove

| Level | Proves | Cannot prove |
|---|---|---|
| `STATIC-CHECK` | A property of the source (no interpolated SQL, no session start, no v1 file touched) | Runtime behaviour |
| `UNIT-TEST` | A rule in isolation | That the endpoint calls the rule |
| `CONTRACT-TEST` | The wire contract and the authorisation outcome | Convergence under concurrency |
| `INTEGRATION-TEST` | Transactions, triggers, cascades, and the change log | A real device's storage behaviour |
| `MIGRATION-TEST` | The schema can move forward and back without losing meaning | Application behaviour on the new schema |
| `PENTEST` | That a specific attack is refused | That no other attack exists |
| `MANUAL-SCENARIO` | That a human-visible flow still works | That it works at scale |
| `PLATFORM-SMOKE` | That the build runs on that platform | Deep behavioural parity |

**Honest limitation:** no level above proves convergence under arbitrary interleavings. §9 uses
deterministic interleavings plus a randomised soak; a randomised soak that passes is evidence, not a
proof.

---

## 3. Backend coexistence and regression preservation (PR-23, TS-12)

**Requirements:** PR-23 "the legacy PHP UI keeps working unchanged"; TS-12 "existing feature
regression tests are preserved unchanged"; CO-I-4, CO-I-6.

### 3.1 The canonical suite (T-REG-01 … T-REG-04)

| ID | Case | Pass condition | Method |
|---|---|---|---|
| T-REG-01 | Run `php tests/run_tests.php` before a coexistence step | `433 passed / 105 failed / 538 tests` | `CONTRACT-TEST` |
| T-REG-02 | Run it again after the step | The same four numbers; no test flips from pass to fail | `CONTRACT-TEST` |
| T-REG-03 | Run it twice in a row | Second run has the same result (the suite cleans up after itself) | `CONTRACT-TEST` |
| T-REG-04 | Confirm no test file was edited | `git diff --stat tests/` is empty for the step | `STATIC-CHECK` |

### 3.2 The PR-23 manual scenario (T-REG-05)

Executed exactly as `BACKEND_COEXISTENCE.md` §3.6 specifies:

| # | Step | Pass condition |
|---|---|---|
| 1 | Log in through `pages/login.php`; open every legacy page | Every page renders; zero console errors |
| 2 | Create, edit, delete a personal expense; create and check a priced list item | Each action succeeds; the list check creates exactly one expense and the uncheck deletes exactly that one |
| 3 | Create a group; join with a second account; add a group expense; calculate and confirm a settlement | Balances match a hand calculation; confirmation succeeds for every member |
| 4 | Repeat steps 1–3 **with `api/v2/` present and the sync tables populated** | Identical behaviour |
| 5 | Compare a recorded v1 response body for each call | Byte-identical to the pre-Phase-2 capture, except documented additive fields (`API_VERSIONING.md` §3) |

| Rule | Statement |
|---|---|
| Step 4 is the whole point | Coexistence is only proven when the new surface exists |
| Step 5 uses captured bodies, not memory | A baseline capture is taken before the first v2 endpoint exists |
| A new `4xx` is a failure | Even a "correct" one: the legacy UI must never start receiving it |

### 3.3 Static coexistence checks (T-REG-06 … T-REG-10)

| ID | Check | Pass condition |
|---|---|---|
| T-REG-06 | `git diff --name-only` for a v2 step | Zero files under `public/assets/js/`, `pages/`, `public/index.php` |
| T-REG-07 | No `session_start()` in any endpoint | Zero matches in `api/**` (test 27b already enforces this) |
| T-REG-08 | No `$conn->query(` with a variable | Zero matches (`SECURITY_ARCHITECTURE.md` §7.1) |
| T-REG-09 | No v1 error string changed | The captured string list from `API_CONTRACT.md` is unchanged |
| T-REG-10 | No v1 status code changed | The captured status list is unchanged, including the v1 **400** for permission refusals (`API_ERROR_HANDLING.md` §4.2) |

### 3.4 Domain extraction equivalence (D-30, T-REG-11)

Extracting `api/domain/` is a prerequisite for v2 writes, and it is the most likely source of a silent
v1 change.

| ID | Case | Pass condition |
|---|---|---|
| T-REG-11 | Characterisation test: for each extracted rule (expense create/update/delete, list check/uncheck, settlement calculation), record the v1 response and DB effect **before** extraction | After extraction, the same input produces the same response and the same rows |
| T-REG-12 | Two-way backward compatibility with legacy web interface: mutations via legacy web fire triggers for Flutter clients; mutations pushed by Flutter native devices are immediately visible in legacy web interface without regression | Full two-way visibility and baseline suite passes at 433/105/538 (D-36, UQ-19) |

| Rule | Statement |
|---|---|
| Extraction is behaviour-preserving by definition | A change in behaviour is a bug, not a refactor |
| Each helper is extracted and verified separately | One commit per rule, with T-REG-01/02 after each |

### 3.5 Acceptance for §3

§3 passes when T-REG-01…T-REG-11 pass and the PR-23 scenario has been run once against a deployment
that contains both surfaces and populated sync tables.

---

## 4. Database migration, backfill, rollback, and engine parity (TS-01, DB-09, A-DB-3)

**Requirement:** TS-01 "migration tests cover forward, backfill, and rollback".
**Sequence:** `DATABASE_MIGRATION_STRATEGY.md` §4 (M1–M6).

### 4.1 Per-step obligations

| Step | Forward | Backfill | Rollback |
|---|---|---|---|
| M1 sync foundation tables | Tables, keys, indexes created on an empty database | n/a (new, empty) | `DROP TABLE` is safe while no client has written |
| M2 record versions | `version` + `updated_at` added | Every existing row gets `version = 1` and a non-null `updated_at` | Drop the columns; data unchanged |
| M3 timestamp precision | `DATETIME(6)` conversion | Existing values preserved to the second | Reversible only if no sub-second value exists; otherwise a restore (§4.5) |
| M4 record identities | `uuid` added + unique index | Every existing row gets a generated UUIDv7; no duplicates; no nulls | Drop the column |
| M5 change capture | Triggers created | n/a — the log starts empty; existing rows are bootstrapped by the client, not logged retroactively | Drop the triggers |
| M6 settlement-period parity | Documentation/schema parity only | n/a | n/a (no live object changes) |

### 4.2 Test cases (T-MIG-01 … T-MIG-10)

| ID | Case | Pass condition | Method |
|---|---|---|---|
| T-MIG-01 | Apply the full sequence to a copy of production data | No error; all six steps applied in order | `MIGRATION-TEST` |
| T-MIG-02 | Re-run every migration file | Idempotent: no error, no duplicate object, no data change (each file is guarded) | `MIGRATION-TEST` |
| T-MIG-03 | Backfill correctness (M2) | `COUNT(*) WHERE version IS NULL OR version <> 1` = 0 | `MIGRATION-TEST` |
| T-MIG-04 | Identity backfill (M4) | `COUNT(*) WHERE uuid IS NULL` = 0; `COUNT(DISTINCT uuid) = COUNT(*)` | `MIGRATION-TEST` |
| T-MIG-05 | Trigger capture (M5) | Insert/update/delete on each captured table appends exactly one change row with the right `op` and `scope_*` | `INTEGRATION-TEST` |
| T-MIG-06 | Trigger suppression | With `@expmg_sync_suppress = 1`, a write appends no change row | `INTEGRATION-TEST` |
| T-MIG-07 | Trigger does not validate or mutate business values | A write that violates an application rule still fails in PHP; the trigger never "fixes" a value except `version`/`updated_at` | `INTEGRATION-TEST` |
| T-MIG-08 | Rollback each step in reverse order | Each reverse step leaves the previous schema intact | `MIGRATION-TEST` |
| T-MIG-09 | v1 still works on the migrated schema | T-REG-01/02 plus the PR-23 scenario | `CONTRACT-TEST` |
| T-MIG-10 | A pre-migration backup restores to the pre-migration schema | `SHOW TRIGGERS` matches the pre-migration set | `MIGRATION-TEST` |

### 4.3 Engine parity spike (A-DB-3, A-DB-4, T-MIG-11 … T-MIG-17)

The design assumes MySQL 8 and MariaDB 10.4 behave the same for the objects in
`DATABASE_SCHEMA_SPECIFICATION.md` §5.6. That assumption is verified **before** the migration runs on
the target engine.

| ID | Check | Pass condition | Both engines |
|---|---|---|---|
| T-MIG-11 | All triggers create without error | Same object list in `SHOW TRIGGERS` | MySQL 8, MariaDB 10.4 |
| T-MIG-12 | `JSON_OBJECT`/`JSON_ARRAY` inside a trigger | Produces the same JSON shape (key order is not asserted) | Both |
| T-MIG-13 | User variables (`@expmg_actor_user_id`, `@expmg_device_uuid`, `@expmg_sync_suppress`) are readable inside a trigger | Same values observed | Both |
| T-MIG-14 | `CHECK` constraints are enforced on insert **and** update | A violating statement fails on both | Both |
| T-MIG-15 | FK cascade + trigger interaction | A cascade that deletes child rows does **not** fire the child table's trigger (F-03); the design compensates with parent tombstones and, for group deletion, per-member tombstones | Both |
| T-MIG-16 | **A-DB-4 (i): cross-table DML inside a trigger** | A `BEFORE DELETE` trigger on `groups` may `UPDATE expenses` and `INSERT` into `sync_change_log`/`sync_tombstones`; if either engine refuses, the endpoint-side fallback (`DATABASE_SCHEMA_SPECIFICATION.md` §5.4.4) is adopted before the migration is written | Both |
| T-MIG-17 | **A-DB-4 (ii): `ON DELETE SET NULL` fires no trigger** | Deleting a group whose `expenses.group_id` would be nulled by the referential action produces **no** `expenses` change-log row and **no** version bump on that path. The test confirms the premise of the explicit-detach design (§5.4.4); if the premise is false, the explicit detach is still correct but the justification is recorded as engine-dependent | Both |

| Rule | Statement |
|---|---|
| A difference is a finding, not a footnote | It changes the schema or the compensating rule before the migration is applied |
| The spike runs on disposable databases | Never on the only copy of the data |
| `sql_mode` must match across the two engines | A `CHECK` can be bypassed by a permissive mode (`BACKEND_COEXISTENCE.md` §4.2) |

### 4.4 Backfill determinism

| Rule | Statement |
|---|---|
| UUIDs generated during backfill are recorded | A re-run must not mint new identities; the column is only written where null |
| Backfill is batched | A single transaction over every row holds locks for the duration |
| Backfill is re-runnable | Interrupted mid-way, it resumes without duplicating |

### 4.5 Rollback reality

| Situation | Allowed action |
|---|---|
| No sub-second timestamps written yet (M3) | Reverse the conversion |
| Sub-second timestamps written (M3) | A restore from the pre-migration backup is the only lossless reverse |
| New UUIDs referenced by a client (M4) | Dropping the column discards identities a client may hold; requires a client purge |
| Change log contains rows (M5) | Dropping triggers is safe; the log table can be kept or dropped, but a client cursor must then be invalidated (`DEPLOYMENT_ARCHITECTURE.md` §8.6) |

### 4.6 Acceptance for §4

All ten migration cases pass on a copy of production data, and the five parity checks pass on both
engines before the first migration is applied to staging.

---

## 5. API contract tests (TS-02)

**Requirement:** TS-02 "API contract tests cover v1 and v2".

### 5.1 v1 — the frozen contract (T-API-01 … T-API-06)

| ID | Case | Pass condition |
|---|---|---|
| T-API-01 | Endpoint inventory | 40 HTTP endpoints + 1 CLI worker exist; each is listed in `API_CONTRACT_SPECIFICATION.md` §2.2 |
| T-API-02 | Envelope | Every response is `{ok: bool, ...}`; no bare array; no `success` key |
| T-API-03 | Pagination ceilings | `expenses/list` default 20 / max 50 (date mode), 500/1000 (month mode); `notifications/list` default 30 / max 50; the feed endpoints 500/1000 |
| T-API-04 | Error strings | The captured strings are unchanged |
| T-API-05 | Status codes | Permission refusal is **400** with `ok:false` (v1 behaviour, not 403); method mismatch behaviour is unchanged |
| T-API-06 | Auth/CSRF | A request without a session is 401; a POST without `X-CSRF-Token` is refused |

### 5.2 v2 — the proposed contract (T-API-07 … T-API-12)

| ID | Case | Pass condition |
|---|---|---|
| T-API-07 | Attribute completeness | Each of the 10 proposed endpoints documents all 15 attributes (`SYNC_API_SPECIFICATION.md` §4) |
| T-API-08 | Error vocabulary | Only the documented codes appear; each has a documented trigger |
| T-API-09 | Money on the wire | Amounts are decimal strings (`"24.50"`, `"0.00"`), never floats (PR-20, D-21) |
| T-API-10 | Envelope + CSRF | Same envelope as v1; CSRF required on every v2 write |
| T-API-11 | Pagination bounds | A hostile `page_size` is refused with `413` (bootstrap/pull) rather than clamped silently |
| T-API-12 | Capability detection | The degradation matrix in `API_VERSIONING.md` §5.1 is honoured: an older client gets a documented refusal, not a malformed response |

### 5.3 v1/v2 compatibility (T-API-13)

| ID | Case | Pass condition |
|---|---|---|
| T-API-13 | A v2 write is visible through v1, and a v1 write is visible through v2 | Same row, same values, correct `version`, one change-log entry |
| T-API-17 | Sync rate-limit enforcement: dedicated `api_sync` bucket (120 req/min user, 240 req/min burst per device) | Requests exceeding threshold receive `429 RATE_LIMITED` with `retry_after` header (D-28, UQ-05) |
| T-API-18 | Batch synchronization payload limits: requests exceeding 100 records or 1MB payload size | Refused with `413 Payload Too Large` / `TOO_MANY_RECORDS` without processing (D-28, UQ-05) |

### 5.4 The gateway contract (conditional, T-API-14)

Only if D-18 is approved. The gateway is testable even though the module is not.

| ID | Case | Pass condition |
|---|---|---|
| T-API-14 | A request with no session is refused before the gateway | 401, no forwarding |
| T-API-15 | A valid session produces the signed header set | Signature verifies; timestamp within 60 s |
| T-API-16 | A replayed signature after 60 s is refused | 401 at the module boundary |

### 5.5 Acceptance for §5

T-API-01…T-API-13 pass; T-API-14…T-API-16 are `DEFERRED` while UQ-06 is open.

---

## 6. Backend correctness and authorisation conformance (TS-03, VC-14, SY-16)

**Requirement:** TS-03 "auth and authorisation tests cover every role boundary". This section also
carries three correctness obligations that other documents place here: the `0.00` settlement marker
(VC-14), the stale-cursor bootstrap (SY-16), and the `type`/`group_id` provenance rule (F-02).

### 6.1 Role-boundary matrix (T-AUTH-01 … T-AUTH-14)

| ID | Actor | Action | Expected |
|---|---|---|---|
| T-AUTH-01 | Anonymous | Any v1 read | 401 |
| T-AUTH-02 | Anonymous | Any v1 write | 401 |
| T-AUTH-03 | Owner | Edit/delete own personal expense | Success |
| T-AUTH-04 | Non-owner | Edit/delete another user's personal expense | Refused (v1: 400 + `ok:false`; v2: 403) |
| T-AUTH-05 | Group member (non-admin) | Create a group expense | Success |
| T-AUTH-06 | Group member (non-admin) | Edit/delete a group expense | Refused |
| T-AUTH-07 | Group admin | Edit/delete a group expense | Success |
| T-AUTH-08 | Non-member | Read a group's expenses/details | Refused; indistinguishable from a non-existent group |
| T-AUTH-09 | Non-member | Confirm a settlement | Refused |
| T-AUTH-10 | Any caller (member or admin) | POST/GET `api/settlements/settle.php` | Refused with HTTP 410 Gone: `{"ok": false, "error": "Endpoint deprecated. Use collaborative settlement workflow (calculate.php -> confirm.php)."}` (UQ-10 Option B) |
| T-AUTH-11 | Any caller (member or admin) | POST/GET `api/settlements/settle_all.php` | Refused with HTTP 410 Gone: `{"ok": false, "error": "Endpoint deprecated. Use collaborative settlement workflow (calculate.php -> confirm.php)."}` (UQ-10 Option B) |

> [!NOTE]
> **UQ-10 Deprecation Implementation Planning (Both Test Suites):**
> Deprecating `settle.php` and `settle_all.php` to return HTTP 410 Gone is an owner-approved narrow exception to the frozen v1 contract. When implemented post-UQ-20, **both** existing test suites must be updated: (1) `tests/run_tests.php` Section 17, and (2) `scripts/pentest/penetration_test.php` (cases S07–S11). Actual modifications to endpoints and test files remain strictly on hold until formal Phase 2 sign-off (UQ-20).
| T-AUTH-12 | Member | Confirm their own confirmation row | Success; a second confirmation by the same user is idempotent |
| T-AUTH-13 | Any user | Read another user's notifications | Refused; the count is scoped |
| T-AUTH-14 | Device of user A | Push a record owned by user B | Refused (`403`/`409`); no row is written |
| T-AUTH-15 | Indebted user | Delete account while owing money in any group | Strictly blocked by pre-flight balance gate (D-23, UQ-02) |
| T-AUTH-16 | Creditor user | Delete account with positive credit balance | Strictly blocked by pre-flight balance gate (forfeiture prohibited); user must settle credits before deletion (D-23, UQ-02) |
| T-AUTH-17 | Surviving members | View historical shared expenses/settlements of deleted user | Original username displayed with `"This user deleted their account."` badge (D-23, UQ-02) |
| T-AUTH-18 | Deleted user | Any authenticated request following account deletion | Refused: sessions destroyed and `sync_devices.revoked_at` set (D-23, UQ-02) |
| T-AUTH-19 | Web browser user | Web session inactivity exceeding 7 days | Refused with `401 Session expired` via `app_sessions` verification (D-24, UQ-03) |
| T-AUTH-20 | Native client | Native remember-device credential exchange (within 30 days inactivity) | Exchanges stored credential for fresh session without raw password entry (D-24, D-33, UQ-03) |

| Rule | Statement |
|---|---|
| Every role in `ACCESS_CONTROL.md` §1 appears at least once | A role with no test is an untested boundary |
| The refusal must not disclose the row | Same error shape whether the row is foreign or absent |
| v1 and v2 status codes are asserted separately | v1 is frozen at 400; v2 uses 403 (`ACCESS_CONTROL.md` §9) |

### 6.2 Settlement invariants (T-SET-01 … T-SET-06)

| ID | Case | Pass condition |
|---|---|---|
| T-SET-01 | Calculation uses `paid_by` | A hand-computed balance matches for a 3-member group |
| T-SET-02 | `is_post_settlement = 0` filter | A late expense is excluded from the closed period and included in the post-settlement flow |
| T-SET-03 | Settlement lock | An edit/delete inside a settled period is refused (v1 400, v2 403/409) |
| T-SET-04 | Three-step confirmation | The period closes only when every active member has a `settlement_confirmations` row |
| T-SET-05 | Confirmation race | Two simultaneous confirmations produce one row and no duplicate settlement (`FOR UPDATE` retained) |
| T-SET-06 | Group deletion with settlements | Behaviour follows UQ-07; until decided, the test asserts only that no expense is silently lost (F-02) |

### 6.3 The `0.00` marker (VC-14, T-SET-07)

| ID | Case | Pass condition |
|---|---|---|
| T-SET-07 | Close a period where all balances are equal | A `0.00` settlement row exists; a subsequent edit inside that period is **still refused**; the row is present in the change log and in a pull with a `uuid` and `version` |

| Rule | Statement |
|---|---|
| The client must not filter zero-amount settlements | A client-side `amount > 0` filter is a defect (`DATABASE_SCHEMA_SPECIFICATION.md` §3.11) |
| The marker advances `MAX(period_end)` | That is the lock; deleting the marker unlocks the period |

### 6.4 Cursor and bootstrap (SY-16, T-SYNC-C1 … T-SYNC-C5)

| ID | Case | Pass condition |
|---|---|---|
| T-SYNC-C1 | Bootstrap is bounded and resumable | A hostile `page_size` is refused; a resume with `after_uuid` loses nothing and duplicates nothing |
| T-SYNC-C2 | A cursor older than the retained log | `410 CURSOR_TOO_OLD`; the client bootstraps; no partial page is applied |
| T-SYNC-C3 | A cursor **ahead** of the server watermark (post-restore) | `410 CURSOR_TOO_OLD`, not an empty page (`SYNC_API_SPECIFICATION.md` §6.4, `DEPLOYMENT_ARCHITECTURE.md` §8.6) |
| T-SYNC-C4 | A **malformed** cursor | `400 INVALID_CURSOR` — distinct from `410`; `sync/state.php` also returns `400`, not `cursor_valid:false` |
| T-SYNC-C5 | Server has in-flight records between watermark and `MAX(seq)` | Client pulling with valid cursor `since <= watermark` receives changes only up to `watermark` (or empty `changes` with `next_cursor = watermark` if up to date); in-flight records are not leaked or prematurely acknowledged; any client presenting `since > watermark` receives `410 CURSOR_TOO_OLD` (T-SYNC-C3) |

### 6.5 `type` versus `group_id` (F-02, T-SET-08)

| ID | Case | Pass condition |
|---|---|---|
| T-SET-08 | An existing orphaned expense (`type='group'`, `group_id IS NULL`) is read, edited, and pulled | It is treated as not attached to a group; no code infers `type` from `group_id` or the reverse; no `CHECK` constraint rejects the existing row |
| T-SET-09 | 4-state group archival lifecycle: `ACTIVE` -> `ARCHIVE_PENDING` -> `ARCHIVED` -> `PERMANENTLY_ARCHIVED` | Valid transitions succeed; illegal operations on archived groups are rejected (D-26, UQ-07) |
| T-SET-10 | Group restoration within 3 calendar months | Admin restores archived group within 90 days; restoration past deadline is rejected (D-26, UQ-07) |
| T-SET-11 | Permanent group deletion eligibility | Hard deletion permitted only after 6 calendar months from archive date with explicit admin confirmation (D-26, UQ-07) |
| T-SET-12 | Expense provenance preservation: deliberate conversion vs detached group expense | Deliberate conversion sets `type='personal'`, `group_id=NULL`; detached expense retains `type='group'`, `is_detached=1`, satisfying `chk_expense_group_consistency` (D-26, UQ-11) |

| Rule | Statement |
|---|---|
| No migration adds a `CHECK` that existing rows violate | A non-deployable migration (`DATABASE_SCHEMA_SPECIFICATION.md` §7.3) |
| The rule appears in the code-review checklist | It is a review obligation, not only a test |

### 6.6 List-to-expense conversion (PR-12 … PR-16, T-LIST-01 … T-LIST-04)

| ID | Case | Pass condition |
|---|---|---|
| T-LIST-01 | Check a priced personal item | Exactly one expense is created; `expense_id` is stored immediately |
| T-LIST-02 | Uncheck | The expense identified by `expense_id` is deleted; no other expense is touched |
| T-LIST-03 | Check a group item | `needs_confirm: true`; the expense is created only after `paid_by` is supplied |
| T-LIST-04 | `checked_at` conversion | The expense date equals `date('Y-m-d', strtotime(checked_at))`, including a near-midnight timestamp |

### 6.7 Acceptance for §6

Every matrix row has a result; T-SET-07 and T-SYNC-C3 … T-SYNC-C5 pass; T-SET-06 is recorded as blocked by UQ-07
rather than marked passed.

---

## 7. Guest mode and guest import (TS-04)

**Requirement:** TS-04 "guest-mode and guest-migration tests include failure injection".

### 7.1 Guest mode makes no request (GM-01, T-GUEST-01)

| ID | Case | Pass condition |
|---|---|---|
| T-GUEST-01 | Use every guest feature with the network disabled and a proxy logging all traffic | Zero HTTP requests to the application origin; the app is fully usable |

| Rule | Statement |
|---|---|
| "Zero" includes analytics, crash reporting, and font/CDN fetches | Any request is a finding |
| Guest data is in its own namespace | A later login cannot read it without an explicit import (§3 of the offline doc) |

### 7.2 Import happy path (GM-06, GM-08, T-GUEST-02 … T-GUEST-05)

| ID | Case | Pass condition |
|---|---|---|
| T-GUEST-02 | Import lists, items, expenses, budgets | Counts match; UUIDs are preserved; `expense_date` is preserved; totals are equal |
| T-GUEST-03 | Category mapping by name | An unknown category name does not invent a category; it is reported |
| T-GUEST-04 | Digest verification | Server digest equals the client digest; `state` reaches `verified` only then |
| T-GUEST-05 | Local data is deleted only after `verified` | Before that, the guest data is intact |

### 7.3 Failure injection (GM-09, GM-10, GM-12, T-GUEST-06 … T-GUEST-13)

| ID | Injected failure | Pass condition |
|---|---|---|
| T-GUEST-06 | Network drops mid-batch | The batch is atomic; a resend with the same `batch_uuid` applies once |
| T-GUEST-07 | App killed mid-import | On relaunch, migrated records are skipped locally; the server treats a resend as `duplicate` |
| T-GUEST-08 | One record fails validation | The batch is `partial`; every other record is applied; the failure is listed with a reason |
| T-GUEST-09 | Session expires mid-import | 401; after re-login the import resumes with the same `batch_uuid`; no duplicate |
| T-GUEST-10 | Server `500` during commit | Nothing is applied; the client retries; the digest is not accepted |
| T-GUEST-11 | Digest mismatch | The import is **not** verified; guest data is retained; the mismatching entity is named |
| T-GUEST-12 | Import exceeds the record cap (100) | Refused with a documented error; the client splits the import and the total is preserved |
| T-GUEST-13 | Rate limit reached | A documented `429` with retry guidance; no partial verification |
| T-GUEST-14 | Exact UUID duplicate skip during guest import into existing account | Dry run and import automatically skip records whose client UUID already exists in registered account (D-22, UQ-01) |
| T-GUEST-15 | Heuristic duplicate candidate confirmation | Records matching on date, amount, and note/category require user confirmation; user choice ("Import as New" vs "Skip") is faithfully executed (D-22, UQ-01) |
| T-GUEST-16 | Preserved guest partition accessibility after skipping import | When user declines/skips import, guest partition (`account_id = 'guest'`) remains intact locally and is fully accessible upon logout (D-22, UQ-01) |

### 7.4 What cannot be tested yet

| Scenario | Status |
|---|---|
| Import into an **existing** account | `BLOCKING` — UQ-01. No behaviour is decided, so no assertion exists. The dry-run preview endpoint is testable only after the policy is chosen (`GUEST_DATA_MIGRATION.md` §7) |
| Group participation from guest data | `BLOCKING` — UQ-16 (guest package) |

### 7.5 Acceptance for §7

T-GUEST-01…T-GUEST-13 pass on a device with a controllable network; §7.4 is reported as blocked, not
as passed.

---

## 8. Offline storage (TS-05)

**Requirement:** TS-05 "offline storage tests cover restart and corruption".

| ID | Case | Pass condition |
|---|---|---|
| T-OFF-01 | Kill the app with a non-empty queue; relaunch | Every queued operation is still present, in dependency order |
| T-OFF-02 | Kill the app mid-transaction | No partially applied local write; the database is consistent |
| T-OFF-03 | Corrupt the local database file (truncate bytes) | The app detects the failure, offers recovery, and never silently drops the queue without telling the user |
| T-OFF-04 | Local schema migration (v1 → v2 of the local schema) | Existing rows are preserved; the migration is transactional |
| T-OFF-05 | Storage full / quota exceeded | The write fails visibly; no silent data loss |
| T-OFF-06 | Web storage eviction (clear site data, then reopen) | The app re-bootstraps from the server; unsynced local-only writes are reported as lost, not silently discarded |
| T-OFF-07 | Queue bound exceeded | The app refuses further offline writes with a clear message rather than growing unbounded |
| T-OFF-08 | Money round-trip | `"24.50"` → `2450` → `"24.50"`; a sum of 100 rows is exact (no float drift) |
| T-OFF-09 | Guest namespace isolation | A login cannot read guest rows without an explicit import |
| T-OFF-10 | Notifications are absent from the local store | No notification table exists locally (D-19) |
| T-OFF-11 | Offline CRUD on group lists and list items | Group lists (create, rename, delete) and list items (add, edit, remove) can be created and mutated offline, enqueuing for sync (D-16, UQ-08) |
| T-OFF-12 | Online-only settlement and list check/uncheck | UI controls for settlements and list check/uncheck are disabled offline; requests made without network are blocked locally (D-16, D-17, UQ-08) |

| Rule | Statement |
|---|---|
| A corruption test must assert the **user-visible** outcome | "The app did not crash" is not enough; silent loss is a failure |
| The queue is the only source of unsent work | A test that recreates the queue from entity state is testing the wrong thing |

---

## 9. Sync convergence (TS-06)

**Requirement:** TS-06 "sync tests cover convergence under concurrent edits".

**Definition used throughout:** two clients have converged when, for every entity in the shared scope,
they hold the same `uuid`, the same `version`, and the same business values, and their reconciliation
digests match (`SYNC_API_SPECIFICATION.md` §6.5).

| ID | Case | Pass condition |
|---|---|---|
| T-SYNC-01 | Client A creates offline; client B pulls | B sees the record with A's UUID and `version = 1` |
| T-SYNC-02 | Both edit the same record from the same base version | One succeeds; the other receives a conflict, resolves, and both converge |
| T-SYNC-03 | Create a list and its items in one batch | Dependency order is honoured (list before item; expense before item link) |
| T-SYNC-04 | Duplicate change delivery | Applying the same change twice is a no-op; `version` does not advance twice |
| T-SYNC-05 | Out-of-order delivery | A change for `version = 3` arriving after `4` does not roll the record back |
| T-SYNC-06 | Push interrupted after commit, before response | A retry with the same `batch_key` returns the stored result; no second write |
| T-SYNC-07 | Pull interrupted mid-page | The cursor does not advance past the last applied record |
| T-SYNC-08 | Delete then pull | A tombstone removes the record locally and any children (subtree purge) |
| T-SYNC-09 | Membership removed (leave / removed by an admin) | The member's own `group_member` tombstone purges their cached group data on the next sync; no further group writes are accepted |
| T-SYNC-10 | **Group deletion cascade** | Every current member receives a **personally scoped** `group_member` tombstone (`scope_user_id = member.user_id`, `cause='cascade'`) in addition to the `group` tombstone, and purges the subtree from that member tombstone. The test does **not** require per-descendant tombstones — lists, items and settlements are purged by the member tombstone (`DATABASE_SCHEMA_SPECIFICATION.md` §5.4.3) |
| T-SYNC-11 | Two devices create with the same UUID (deliberate collision) | Second is `duplicate`; the server's record is adopted |
| T-SYNC-12 | Randomised soak: N clients, M operations, random delays and drops | Final digests match on every client; no lost acknowledged write |
| T-SYNC-13 | Change-log pruning runs during a sync | No visible change is lost; a cursor that becomes too old gets `410` |
| T-SYNC-14 | Clock skew on a client | Business dates are preserved; `created_at`/`updated_at` are server-generated (SY-20) |
| T-SYNC-15 | **Group deletion retains the owner's expenses** | An expense owned by the deleting member survives as a detached row (`group_id = NULL`); the same expense is purged from every other former member's cache. The detach is logged by an ordinary `update`, not by `ON DELETE SET NULL` (§5.4.4) |
| T-SYNC-16 | **Group deletion after a long offline period** | A device offline for longer than the change-log retention still receives the durable member tombstone and purges the subtree (tombstones are never pruned) |
| T-SYNC-17 | **Group deletion with interrupted sync** | A page carrying the detach `update` and the member tombstone that fails to apply leaves the cursor unadvanced; the re-fetch produces the same outcome (no double purge, no lost detach) |
| T-SYNC-18 | **Expense moves between groups** | The old group's members receive `move_out` (no payload) then nothing else; the new group's members receive the `update`; the old copy is removed without a tombstone (§5.6.4) |
| T-SYNC-19 | **Expense moves from group to personal** | The old group's members receive `move_out`; the owner receives the `update` with `group_id = NULL`; the row survives only for the owner |
| T-SYNC-20 | **Canonical digest conformance** | The server's digest for an entity equals `SHA-256` of the canonical `"{uuid}:{version}\n"` lines sorted by UUID byte order; the empty entity equals `e3b0c442…b855`; an entity with one row differs from the empty digest; the digest is per entity, never concatenated |
| T-SYNC-21 | **Digest mismatch repair** | An induced divergence (an extra local row, a stale version, or a deleted record not purged) produces a mismatch → **targeted** bootstrap of that entity only, with pending operations preserved; a second consecutive mismatch is reported, not looped |
| T-SYNC-22 | **Two-second safety lag verification and residual transaction race** (C-04) | Direct validation of D-27 safety lag and digest recovery: (1) Txn A begins and inserts an expense row into `sync_change_log` (`seq = 100`); (2) Txn A is held open; (3) Txn B begins, inserts an expense (`seq = 101`), and commits immediately; (4) Client performs pull within 2.0s: server evaluates `watermark = MAX(seq) WHERE changed_at <= NOW(6) - 2s`; because Txn B is < 2.0s old, `seq = 101` is withheld behind watermark, preventing client from advancing past uncommitted Txn A; (5) Fault injection: Txn A is held open for > 2.5s, allowing Txn B to age past 2.0s; client pulls and receives Txn B, advancing cursor to `101`; (6) Txn A subsequently commits at `seq = 100`; (7) Subsequent client pull with `since = 101` queries `seq > 101` and cannot observe `seq = 100`; (8) At the end of the pull cycle, client and server compute canonical digests (§6.5): hashes diverge because client lacks Txn A; (9) Client detects digest mismatch and triggers targeted bootstrap for `expense`, recovering the missing record without data loss or duplicate entries. Confirms: the 2-second lag is a pragmatic heuristic that mitigates concurrent commit interleaving under normal load, while canonical digest reconciliation provides the authoritative mathematical safety net when transactions exceed the 2-second threshold. |
| T-SYNC-23 | **Account-wide 24-hour reminder receipt and reset** | Acknowledging a reminder via `POST /api/v2/reminders/ack.php` on device A resets the 24-hour reminder countdown account-wide, suppressing redundant reminders on device B on subsequent pull (D-35, UQ-18) |

| Rule | Statement |
|---|---|
| "Converged" is asserted by digest, not by eyeballing | §6.5 of the API specification is the only digest definition |
| The digest comparison runs only after a full pull cycle | A mid-cycle comparison legitimately differs |
| A soak failure must reproduce deterministically | Otherwise the seed and the operation log are captured for replay |
| A lost acknowledged write is the worst failure class | It fails the release even if the digests later match by accident |

---

## 10. Conflict resolution (TS-07)

**Requirement:** TS-07 "conflict tests cover every policy branch".

### 10.1 One case per policy branch

The policy table is `CONFLICT_RESOLUTION.md` §3 and `VERSIONING_AND_CONFLICT_RESOLUTION.md` §7.

| ID | Entity / branch | Expected |
|---|---|---|
| T-CONF-01 | Personal expense, both edited | Manual; "keep mine" writes against the current version; "keep theirs" discards the queue entry |
| T-CONF-02 | Group expense, both edited | Manual; the merge suggestion pre-fills but never auto-applies |
| T-CONF-03 | Expense delete vs edit | Manual unless the tombstone is clearly newer |
| T-CONF-04 | Budget (one field) | Two-option choice; no silent overwrite |
| T-CONF-05 | Personal list name | Manual |
| T-CONF-06 | Personal list delete | Delete-wins-if-newer, else manual |
| T-CONF-07 | Personal list item | Manual with field merge offered |
| T-CONF-08 | Group list / item | Server-wins; the client cannot write them offline |
| T-CONF-09 | Group, group member | Server-wins |
| T-CONF-10 | Settlement / confirmation | Never synchronised; a pushed settlement is refused |
| T-CONF-11 | Record the user can no longer write (permission revoked) | Conflict is not offered; the local copy is purged and the user is told |
| T-CONF-12 | Settlement lock conflict | Refused with a domain error, not a merge prompt |

### 10.2 Resolution mechanics

| ID | Case | Pass condition |
|---|---|---|
| T-CONF-13 | "Keep mine" | Sent as a new write with a fresh `base_version`; all rules re-validated; a second conflict is possible but bounded (`CONFLICT_RESOLUTION.md` §6.2) |
| T-CONF-14 | "Keep theirs" with a pending local edit | The local edit is discarded only after an explicit choice; the queue entry is removed |
| T-CONF-15 | Merge suggestion | Values are combined only for fields that are safe to combine; amount and payer are never merged |
| T-CONF-16 | Conflict count and badge | The unresolved count is accurate and drops to zero only when every conflict is resolved |
| T-CONF-17 | Conflict survives a restart | The conflict and both sides persist locally |
| T-CONF-18 | Dedicated "Needs Attention" area | Conflicting records and permanently failed writes surface in non-modal Needs Attention UI; safe auto-merge suggested for metadata, strictly disabled for financial values (D-28, UQ-12, UQ-18) |

### 10.3 Acceptance for §10

Every branch in the policy table has a case, and no branch auto-resolves a financial value.

---

## 11. Multi-device (TS-08)

**Requirement:** TS-08 "multi-device tests use two or more independent clients".

| ID | Case | Pass condition |
|---|---|---|
| T-MULTI-01 | Three clients (two mobile, one Web) under one account | All converge; each has its own device record |
| T-MULTI-02 | Concurrent edits from two clients on one record | Exactly one wins; the other resolves; both converge |
| T-MULTI-03 | Device revoked while it has a queue | Sync is refused (`403 DEVICE_REVOKED`); the queue is retained and the user is told; re-registration policy is UQ-04 |
| T-MULTI-04 | Session expires on one device only | The other device is unaffected (sessions are per-device) |
| T-MULTI-05 | Same account, Web and native | No CORS/cookie difference; the Web client's cookie and the native cookie jar behave the same |
| T-MULTI-06 | Device list | A user sees exactly their own devices; another user's device is never listed |
| T-MULTI-07 | Database-backed session and device revocation | Revoking device sets `sync_devices.revoked_at = NOW(6)` and purges matching `app_sessions` rows (D-25, UQ-04) |
| T-MULTI-08 | Universal API rejection following device revocation | Revoked device receives `403 DEVICE_REVOKED` on v2 sync endpoints and `401 Session expired/revoked` on all 40 v1 endpoints (D-25, UQ-04) |
| T-MULTI-09 | Offline device behavior post-revocation | Revoked device offline retains local encrypted cache until local timeout or network reconnect; cannot push/pull on reconnect (D-25, UQ-04) |

| Rule | Statement |
|---|---|
| "Independent clients" means separate storage | Two browser tabs share IndexedDB and do not count |
| Device revocation fully tested | Under approved Option B (`app_sessions`), revocation terminates both sync and authenticated sessions immediately across all endpoints |

---

## 12. Idempotency (TS-09)

**Requirement:** TS-09 "idempotency tests cover replay and payload mismatch".

| ID | Level | Case | Pass condition |
|---|---|---|---|
| T-IDEM-01 | HTTP (v1) | Repeat a POST with the same `Idempotency-Key` and body | The stored response is returned; exactly one row exists |
| T-IDEM-02 | HTTP (v1) | Same key, different body | Refused; no second write |
| T-IDEM-03 | Batch (v2) | Same `batch_key` + `request_hash` | Stored per-operation outcomes returned; no second write |
| T-IDEM-04 | Batch (v2) | Same `batch_key`, different hash | `409 BATCH_KEY_REUSED` |
| T-IDEM-05 | Record | Same `uuid` created twice | `409 DUPLICATE_ID`; the server record is adopted |
| T-IDEM-06 | Record update | Stale `base_version` | `409 CONFLICT`; no partial field update |
| T-IDEM-07 | Import | Same `batch_uuid` submitted five times | Exactly one row per guest record |
| T-IDEM-08 | Import item | Same `(batch_id, entity, local_ref)` resubmitted | Idempotent; no duplicate |
| T-IDEM-09 | Concurrency | Two identical requests in parallel | One wins; the other returns the same result or a documented conflict, never two rows |
| T-IDEM-10 | Expiry | A replay after the idempotency TTL | Treated as a new operation (documented), and the record-level guards still prevent a duplicate |

| Rule | Statement |
|---|---|
| Idempotency never skips authorisation | A replay is re-authorised (`SYNC_API_SPECIFICATION.md` §8) |
| A hash mismatch is an error, not a silent replay | Reporting the old result would hide a client bug |

---

## 13. Security testing (TS-10, SE-12)

**Requirement:** TS-10 "security tests cover the new sync surface"; SE-12 "the new attack surface is
enumerated". Each of the 18 surfaces in `SECURITY_ARCHITECTURE.md` §10 has one case here.

### 13.1 The 18 new surfaces (T-SEC-01 … T-SEC-18)

| ID | Surface | Attack | Expected |
|---|---|---|---|
| T-SEC-01 | `/api/v2/` endpoints | Call without a session | 401 on every endpoint except session status |
| T-SEC-02 | Change log | Pull with another user's cursor / no cursor | Only the caller's scope; no foreign row |
| T-SEC-03 | Scope | Send a scope parameter | Ignored; scope is computed server-side |
| T-SEC-04 | Cursor | Tamper with the cursor value | `400 INVALID_CURSOR`; no data leak |
| T-SEC-05 | Device identity | Use a device UUID that belongs to another user | Not found; treated as unregistered; no cross-user access |
| T-SEC-06 | Push payload | Send `user_id`, `is_post_settlement`, `created_by`, `type` | Ignored/overridden; a contradictory `type`/`group_id` is refused |
| T-SEC-07 | Batch replay | Replay a captured push | Stored result; no second write |
| T-SEC-08 | Bulk payload | 501 operations; a 10 MB body | `413`/documented refusal; no partial application |
| T-SEC-09 | Rate-limit bucket | Exhaust `api_sync` | `429`; the normal buckets are unaffected |
| T-SEC-10 | Local storage | Inspect the device database | SQLCipher where supported; the Web gap is documented, not denied |
| T-SEC-11 | Guest import | Import into a session that is not the owner | Refused; the import is session-scoped |
| T-SEC-12 | Tombstones | Pull deletions of foreign rows | None; the same scope filter applies |
| T-SEC-13 | Diagnostics | Call state/health as a non-owner | Only the caller's counts/digests |
| T-SEC-14 | Trigger-created rows | Write a value the trigger "should" fix | The trigger records only; it never validates or rewrites business values |
| T-SEC-15 | Actor session variables | Set `@expmg_actor_user_id` from a header | Ignored; only the middleware sets it from the session |
| T-SEC-16 | Revoked device | Re-register after revocation | `403 DEVICE_REVOKED`; re-registration is UQ-04 |
| T-SEC-17 | Offline queue | Craft a queue containing an online-only operation | Server refuses it; the client queue is not a control |
| T-SEC-18 | Conflict resolution | Resolve with "keep mine" to force an overwrite | It is a new write with a fresh `base_version`; all rules re-validated |

### 13.2 ID-manipulation attacks (T-SEC-19 … T-SEC-25)

Each row of `ACCESS_CONTROL.md` §7.3 is a case.

| ID | Attack | Expected |
|---|---|---|
| T-SEC-19 | Push an update for another user's expense UUID | Refused; no content disclosed |
| T-SEC-20 | Pull with a cursor expecting another user's changes | Scope intersected; nothing foreign |
| T-SEC-21 | Register a device UUID owned by another user | Treated as unregistered |
| T-SEC-22 | Guess another user's `batch_key` | Not found; operations still authorisation-checked |
| T-SEC-23 | Enumerate group ids | Non-member sees the same response as a non-existent group |
| T-SEC-24 | Manipulate `list_item.expense_id` to delete a foreign expense | Impossible through any write path; uncheck deletes only the linked expense |
| T-SEC-25 | Read another user's change-log rows via any endpoint | No such endpoint; any response is scoped |

### 13.3 Existing controls regression (T-SEC-26 … T-SEC-31)

| ID | Control | Pass condition |
|---|---|---|
| T-SEC-26 | CSRF | A POST without `X-CSRF-Token` is refused; the IP gate still returns `429` after 50 failures / 10 min |
| T-SEC-27 | Session | Cookie is `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS; strict mode; id regenerated on login |
| T-SEC-28 | Security headers | `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, CSP present on every response |
| T-SEC-29 | Rate limits | Login 5/15 min; user 120/min; IP 240/min; `api_sync` 120/min (D-28), additive |
| T-SEC-30 | SQL injection | The static gate (T-REG-08) plus a payload attempt on every v2 input |
| T-SEC-31 | Stored text | `O'Brien` and `<script>` round-trip byte-identically; each UI escapes at render |

### 13.4 Acceptance for §13

Every one of T-SEC-01…T-SEC-31 has a result; a single cross-user read or write fails the release.

---

## 14. Platform smoke and the Web CSP check (TS-11, FL-03, FL-08, A-WEB-1)

**Requirement:** TS-11 "cross-platform smoke tests are defined per platform".

### 14.1 Per-platform smoke script

Run the same script on each target. Each row is a pass/fail per platform.

| # | Step | Web | Android | iOS | Windows | macOS | Linux |
|---|---|---|---|---|---|---|---|
| 1 | Cold start; no crash | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| 2 | Guest mode: create an expense with the network off | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| 3 | Register; import guest data; verify digest | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| 4 | Kill and relaunch; session persists | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| 5 | Create offline; sync; see it on a second client | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| 6 | Conflict appears, resolve both ways | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| 7 | Charts render with the app's own data | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| 8 | PDF export opens/shares | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| 9 | Theme switch; text scales without clipping | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| 10 | Local storage encrypted where supported | n/a (documented gap) | ☐ | ☐ | ☐ | ☐ | ☐ |
| 11 | Background/foreground: queue drains on resume | ☐ (tab only) | ☐ | ☐ | ☐ | ☐ | ☐ |
| 12 | Logout clears local data; guest data unaffected | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |

### 14.2 Web: console-clean under the real CSP (T-WEB-01 … T-WEB-05)

| ID | Case | Pass condition |
|---|---|---|
| T-WEB-01 | Load the Web build with the **production** CSP (not a relaxed dev header) | Zero CSP violations in the console; zero failed requests |
| T-WEB-02 | CanvasKit loads from the same origin (A-WEB-1) | No third-party asset request; if WASM compilation is blocked, the required directive is documented as a decision (`DEPLOYMENT_ARCHITECTURE.md` §4.4) |
| T-WEB-03 | Service worker does not intercept `/api/` | A login and a sync request are never served from cache |
| T-WEB-04 | A new release invalidates the old worker | After a deploy, a reload runs the new build; no stale asset mix |
| T-WEB-05 | Cookie behaviour | The session cookie is sent on same-origin requests and is invisible to JavaScript |

### 14.3 Native specifics

| Platform | Extra check |
|---|---|
| Android | Release build refuses cleartext; no `badCertificateCallback` in release; debug-only network config is not in `main/` |
| iOS | ATS enabled; no arbitrary-loads exception in release |
| Desktop | Local database path is per-user; no world-readable file |

### 14.4 Acceptance for §14

The Web column is fully green (including T-WEB-01…T-WEB-05) before a Web build is called releasable;
a native platform with a red row is documented as unsupported for that feature rather than shipped as
"working".

---

## 15. Coverage, exit criteria, and what is blocked

### 15.1 Requirement coverage

| Requirement | Section | Method |
|---|---|---|
| TS-01 migration forward/backfill/rollback | §4 | `MIGRATION-TEST` |
| TS-02 v1 and v2 contract | §5 | `CONTRACT-TEST` |
| TS-03 role boundaries | §6.1 | `CONTRACT-TEST` |
| TS-04 guest + failure injection | §7 | `CONTRACT-TEST` |
| TS-05 offline restart/corruption | §8 | `MANUAL-SCENARIO` + `INTEGRATION-TEST` |
| TS-06 convergence | §9 | `CONTRACT-TEST` |
| TS-07 every conflict branch | §10 | `CONTRACT-TEST` |
| TS-08 two or more independent clients | §11 | `MANUAL-SCENARIO` |
| TS-09 idempotency replay/mismatch | §12 | `CONTRACT-TEST` |
| TS-10 security of the new surface | §13 | `PENTEST` |
| TS-11 per-platform smoke | §14 | `PLATFORM-SMOKE` |
| TS-12 existing regression tests unchanged | §3 | `CONTRACT-TEST` |
| VC-14 `0.00` marker | §6.3 | `INTEGRATION-TEST` |
| SY-16 bounded/resumable bootstrap | §6.4 | `CONTRACT-TEST` |
| SE-13 controls not weakened | §3, §13.3 | `DOC-REVIEW` + baseline |
| PR-23 legacy UI unchanged | §3.2 | `MANUAL-SCENARIO` |
| A-DB-3 engine parity | §4.3 | `MIGRATION-TEST` |

### 15.2 Exit criteria

| Gate | Criterion |
|---|---|
| **Phase 2 (documentation)** | This plan exists, every requirement has a method, and no test is claimed as passing |
| **Implementation start** | The nine `BLOCKING` UQs are answered; otherwise §15.3 applies |
| **First v2 endpoint** | T-REG-01/02 and T-API-01…T-API-06 pass |
| **First migration** | T-MIG-01…T-MIG-17 pass on a copy of production data |
| **Sync beta** | T-SYNC-01…T-SYNC-19 and T-IDEM-01…T-IDEM-10 pass; T-CONF-01…T-CONF-17 pass |
| **Security sign-off** | T-SEC-01…T-SEC-31 pass; no open cross-user finding |
| **Release** | Web column green; every native row either green or documented as unsupported |

### 15.3 Resolution of previously open test areas by owner decisions

All architectural questions governing previously blocked test areas have been resolved by authoritative project owner decisions:

| Test area | Decision | Authoritative specification | Validation cases |
|---|---|---|---|
| Existing-account import | Option C (D-22, UQ-01) | Dry run with two-tier duplicate detection (exact UUID skip, heuristic confirmation) and preserved guest partition | `T-GUEST-14`, `T-GUEST-15`, `T-GUEST-16` |
| Account deletion & retention | D-23 (UQ-02) | Balance pre-flight gate, personal data hard purge, shared records preserved with original username retained and context badge | `T-AUTH-15`…`T-AUTH-18` |
| Session lifetime & remember credentials | Option C (D-24, D-33, UQ-03) | 7-day inactivity web sessions; 30-day inactivity remember credentials in native hardware secure storage | `T-AUTH-19`, `T-AUTH-20` |
| Device revocation model | Option B (D-25, UQ-04) | Full revocation via `app_sessions` database table immediately invalidating sync and authenticated sessions | `T-MULTI-07`, `T-MULTI-08`, `T-MULTI-09` |
| Laravel coexistence | D-18 (UQ-06) | Procedural PHP 8 backend retained; Laravel remains dormant until future justified need under Model M-1 | `T-API-14`…`T-API-16` (deferred) |
| Group archival lifecycle | D-26 (UQ-07) | 4-state archival lifecycle: `ACTIVE` -> `ARCHIVE_PENDING` -> `ARCHIVED` -> `PERMANENTLY_ARCHIVED` (3m restore, 6m deletion) | `T-SET-09`, `T-SET-10`, `T-SET-11` |
| Retention windows | D-27, UQ-09 | 90-day sync change log, indefinite tombstones, 30-day batches, account-life guest import audit | `T-SYNC-C2`, `T-SYNC-21`, `T-SYNC-22` |
| Expense provenance | D-26 (UQ-11) | Distinguish deliberate conversion (`type='personal'`) from detached group expenses (`type='group'`, `is_detached=1`) | `T-SET-12` |
| Platform rollout sequencing | D-37 (UQ-14) | Release 1 Native (Android, iOS, Windows, macOS, Linux); Release 2 Flutter Web post A-WEB-1 validation | `T-WEB-01`…`T-WEB-05` |
| Guest feature package | D-04, UQ-16 | Personal expenses, categories, budgets, lists, items, calendar, analytics. Theme customization disabled; export disabled | `T-GUEST-01`…`T-GUEST-05` |
| Rate limits & batch sizes | D-28 (UQ-05) | Dedicated `api_sync` bucket (120 req/min user, 240 burst device); max 100 records / 1MB payload per batch | `T-API-17`, `T-API-18` |
| Offline capability matrix | D-16 (UQ-08) | Six entity types offline; settlement confirmation and list check/uncheck online-only | `T-OFF-11`, `T-OFF-12` |
| Conflict notification surface | D-28, UQ-12 | Dedicated non-modal "Needs Attention" area; safe auto-merge for metadata; strictly no auto-merge for financial values | `T-CONF-18` |
| Reminder reset schedule | D-35 (UQ-18) | Account-wide 24-hour reminder countdown resets upon any device acknowledgement | `T-SYNC-23` |
| Legacy UI compatibility | D-36 (UQ-19) | Mandatory two-way compatibility between legacy PHP/JS web interface and Flutter sync engine | `T-REG-12` |

### 15.4 Regression policy

| Rule | Statement |
|---|---|
| The baseline number is a release gate | `433 / 105 / 538` |
| A new failure in the canonical suite blocks the step | Even if a new suite passes |
| A baseline change is a reviewed event with a written cause | `docs/TEST_BASELINE.md` is updated in the same change |
| A test may be deleted only with a recorded reason | Never to make a number go up |

### 15.5 Test inventory — counted, not estimated

The unique test-case count is **212**, measured directly from this document by extracting every
`T-…` identifier and de-duplicating it. The per-prefix breakdown is the authoritative inventory:

| Prefix | Unique IDs | Section | Requirement |
|---|---:|---|---|
| `T-MIG` | 17 | §4 | TS-01 |
| `T-API` | 18 | §5 | TS-02 |
| `T-AUTH` | 20 | §6.1 | TS-03 |
| `T-GUEST` | 16 | §7 | TS-04 |
| `T-OFF` | 12 | §8 | TS-05 |
| `T-SYNC` | 28 | §9 (01–23) and §6.4 (C1–C5) | TS-06 and SY-16 |
| `T-CONF` | 18 | §10 | TS-07 |
| `T-MULTI` | 9 | §11 | TS-08 |
| `T-IDEM` | 10 | §12 | TS-09 |
| `T-SEC` | 31 | §13 | TS-10 |
| `T-WEB` | 5 | §14 | TS-11 |
| `T-REG` | 12 | §3 | TS-12 |
| `T-SET` | 12 | §6.2, §6.3, §6.5 | VC-14, PR-04, UQ-07, UQ-11 |
| `T-LIST` | 4 | §6.6 | PR-12…PR-16 |
| **Total** | **212** | | |

**Overlap rule — stated explicitly.** The 212 are unique identifiers; each belongs to exactly one
prefix. Two things are **not** counted as test cases and must not be added to the total:

| Item | Why it is not a `T-` case |
|---|---|
| The **12-step platform smoke matrix** in §14 | It is a checklist executed *per platform* against the five `T-WEB-01…05` cases. Counting the matrix rows as 12 cases and the `T-WEB` IDs as 5 double-counts the same work |
| The `T-SYNC-C1…C5` cursor cases | They are inside the `T-SYNC` prefix (hence 28, not 23). An earlier draft listed them again under "settlement/list/cursor correctness", which is the overlap this section removes |

**Verification count formula:** Any future count must extract `T-[A-Z]+(?:-[A-Z0-9]+)?-[A-Z]?\d+`
(the trailing `[A-Z]?` is required to catch `T-SYNC-C1…C5`), de-duplicate, and never add matrix rows. Exact count across this document is verified at **212**.

> [!IMPORTANT]
> **Execution Status of the 212 Validation Cases:** The 212 test cases specified across this document are **planned architectural test specifications** designed to validate the target Flutter client and v2 API during Phase 3 and Phase 4 implementation. They have **NOT been executed**, as neither the Flutter client nor the v2 sync API endpoints exist during Phase 2.
>
> They are strictly distinguished from the **538 tests in `tests/run_tests.php`** (the inherited Phase 1 regression baseline: 433 passed, 105 failed), which have been executed against the running legacy PHP backend. The 105 inherited failures are not a prerequisite for Phase 2 sign-off, but represent an inherited baseline to be investigated before implementation begins.

---

## 16. Cross-references

| Topic | Document |
|---|---|
| Canonical baseline and failure catalogue | [`../TEST_BASELINE.md`](../TEST_BASELINE.md) |
| Frozen v1 contract | [`../API_CONTRACT.md`](../API_CONTRACT.md) |
| Coexistence rules and the PR-23 scenario source | [`../architecture/BACKEND_COEXISTENCE.md`](../architecture/BACKEND_COEXISTENCE.md) §3 |
| Migration sequence and execution protocol | [`../database/DATABASE_MIGRATION_STRATEGY.md`](../database/DATABASE_MIGRATION_STRATEGY.md) §4, §7 |
| Trigger set and the `0.00` marker | [`../database/DATABASE_SCHEMA_SPECIFICATION.md`](../database/DATABASE_SCHEMA_SPECIFICATION.md) §3.11, §5.6 |
| Sync contract and cursor rules | [`../api/SYNC_API_SPECIFICATION.md`](../api/SYNC_API_SPECIFICATION.md) §6.4 |
| Conflict policies | [`../synchronization/CONFLICT_RESOLUTION.md`](../synchronization/CONFLICT_RESOLUTION.md) §3; [`../database/VERSIONING_AND_CONFLICT_RESOLUTION.md`](../database/VERSIONING_AND_CONFLICT_RESOLUTION.md) §7 |
| Guest import and failure modes | [`../synchronization/GUEST_DATA_MIGRATION.md`](../synchronization/GUEST_DATA_MIGRATION.md) §6 |
| Offline store and integrity | [`../synchronization/OFFLINE_DATA_MANAGEMENT.md`](../synchronization/OFFLINE_DATA_MANAGEMENT.md) §5, §7 |
| Attack surface (18 surfaces) | [`../security/SECURITY_ARCHITECTURE.md`](../security/SECURITY_ARCHITECTURE.md) §10 |
| ID-manipulation attacks | [`../security/ACCESS_CONTROL.md`](../security/ACCESS_CONTROL.md) §7.3 |
| Deployment, CSP, restore hazard | [`../deployment/DEPLOYMENT_ARCHITECTURE.md`](../deployment/DEPLOYMENT_ARCHITECTURE.md) §4.4, §8.6 |
| Requirement traceability | [`../WORK/REQUIREMENTS_TRACEABILITY.md`](../WORK/REQUIREMENTS_TRACEABILITY.md) |
| Open questions | [`../architecture/UNRESOLVED_QUESTIONS.md`](../architecture/UNRESOLVED_QUESTIONS.md) |
