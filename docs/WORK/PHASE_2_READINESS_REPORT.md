# Phase 2 Readiness Report

| Field | Value |
|---|---|
| Status | `ACCEPTED` — Phase 2 architecture baseline formally approved by project owner on 2026-11-18 (**UQ-20 APPROVED**). |
| Phase | 2 (Target Architecture and Technical Design) |
| Work package | WP-13 |
| Baseline commit | `ec0e692` (branch `main`) |
| Date | 2026-11-18 |
| Documents produced | 33 canonical Markdown files under `docs/` (plus 3 supporting in `docs/WORK/`) |
| Decisions | 38 (D-01…D-07, D-16, D-17, D-18, D-22…D-26, D-28, D-33…D-38 `ACCEPTED` [22]; 16 `PROPOSED`; 0 `NEEDS APPROVAL`) |
| Open questions | 20 (18 `APPROVED`, 2 `APPROVED (PROVISIONAL)` [UQ-05, UQ-09], 0 `NOT APPROVED`) |
| Test cases | 212 unique `T-` IDs (validation plan §15.5; planned specifications) |
| Source changes | **None.** No file outside `docs/` was created, modified, or deleted |
| Owner sign-off | **APPROVED** (UQ-20 formally approved on 2026-11-18) |

---

## 0. Verdict

**Documentation: ready for final review.** The Phase 2 design is complete, internally
cross-referenced, and states what is decided, what is proposed, and what is approved.
A reader can understand the v2 surface, the schema changes, and the Flutter client from these documents
without inventing an unapproved policy. Architecture decision ID integrity has been fully audited and
restored (D-01 through D-38).

**Owner decisions incorporated and audit findings resolved.** All policy areas are fully resolved:
1. **UQ-02 (Sole Admin Succession):** The departing administrator has two approved choices before account deletion: manual successor designation via `POST /api/v2/groups/transfer_admin.php`, OR automatic transfer to the earliest-joined active member of that group (`joined_at ASC`).
2. **UQ-07 (Archival Lifecycle):** Confirmed lifecycle distinction: `ARCHIVED` remains reversible (within 3 calendar months); `PERMANENTLY_ARCHIVED` cannot be reversed. Permanent deletion becomes eligible six full calendar months after archival, strictly initiated by an authorized administrator (`role = 'admin'`), with no last-member fallback.
3. **UQ-08 & D-17 (Online-Only Group Membership):** Adding/removing members, group creation, deletion, join via code, leaving, and member removal are owner-approved strictly online-only operations. D-17 is transitioned to `ACCEPTED`.
4. **UQ-09 (Retention Schedules):** 90-day sync change logs approved; tombstones have no fixed expiry with future safe GC design (based on sync acks, device revocation, forced resync); completed sync batches updated to 45 days (from 30); lifetime guest audit retention explicitly approved as APPROVED policy (not provisional).
5. **UQ-10 & D-31 (Deprecation Exception & Test Refactoring):** Deprecating `settle.php` and `settle_all.php` to return HTTP `410 Gone` is documented as an owner-approved, narrow exception to the frozen v1 contract. Future post-UQ-20 implementation planning explicitly covers refactoring **both** `tests/run_tests.php` Section 17 and `scripts/pentest/penetration_test.php` (cases S07–S11).
6. **UQ-18 (Reminder Receipt):** Explicit app-level acknowledgement (`POST /api/v2/reminders/ack.php`) is the owner-approved measurable definition of successful receipt.
7. **Inherited Baseline Policy (105 Failures):** By owner decision, the 105 test failures in `tests/run_tests.php` represent the inherited Phase 1 baseline (`docs/TEST_BASELINE.md`), to be investigated before implementation, rather than making their resolution a prerequisite for Phase 2 sign-off.
8. Status counts: exactly **17 questions are `APPROVED`**, **2 policies are `APPROVED (PROVISIONAL)`** (UQ-05, UQ-09), and **1 question is `NOT APPROVED`** (UQ-20). Decisions: **22 `ACCEPTED`**, **16 `PROPOSED`**.

**Implementation boundary & UQ-20 authorization scope.** Formal Phase 2 approval was formally granted by the project owner on 2026-11-18 (**UQ-20 APPROVED**).
Formal UQ-20 sign-off authorizes:
1. Formal acceptance of the Phase 2 architecture and technical documentation baseline.
2. Committing and tagging the accepted documentation baseline in Git (`phase2-architecture-approved`).
3. Execution of the three planned verification spikes (`A-DB-3`, `A-DB-4`, `A-WEB-1`), strictly within their documented scope on isolated/disposable environments.

Formal sign-off does NOT authorize: production application implementation, database schema changes or migrations, or production deployment. These activities remain strictly gated.

**This report claims no test result.** No executable file changed, so the baseline is unaffected by
construction; §G states the argument and the pre-implementation gate.

## 0.1 Two different meanings of "ready"

| Sense | State |
|---|---|
| Ready to be **reviewed** | Yes — every requirement has an owner document, verification method, restored decision IDs, and clarified policy boundaries |
| Ready to be **implemented** | No — UQ-20 is explicitly NOT APPROVED; three verification spikes (A-DB-3, A-DB-4, A-WEB-1) in `PLANNED` status; no `api/domain/` extraction yet |
| Ready to be **tagged** | No — UQ-20 requires owner approval first; no tag has been created |

---

## A. Architecture

### A.1 What the target architecture is

| Layer | Content | Authority |
|---|---|---|
| Client | Flutter (Web, Android, iOS, Windows, macOS, Linux) — layered, Riverpod, Drift | `architecture/FLUTTER_ARCHITECTURE.md`, `TECHNOLOGY_STACK.md` |
| Transport | HTTPS, same origin, cookie session, CSRF header | `security/SESSION_MANAGEMENT.md`, `security/SECURITY_ARCHITECTURE.md` §4 |
| API | Frozen v1 (40 HTTP endpoints) + proposed v2 (`api/v2/`, 10 endpoints) | `api/API_CONTRACT_SPECIFICATION.md` §2 |
| Domain | Shared rules extracted to `api/domain/` (D-30) and used by both surfaces | `architecture/BACKEND_COEXISTENCE.md` §6 |
| Data | One MySQL/MariaDB database: 14 existing tables + 6 proposed sync tables | `database/DATABASE_SCHEMA_SPECIFICATION.md` |
| Host | One Apache/PHP host, optional Redis, CLI workers | `deployment/DEPLOYMENT_ARCHITECTURE.md` §2 |

### A.2 Decisions

| Group | IDs | Status |
|---|---|---|
| Owner-approved decisions | D-01…D-07, D-16, D-17, D-18, D-22…D-26, D-28, D-33…D-38 (22 decisions) | `ACCEPTED` |
| Agent proposals | D-08…D-15, D-19…D-21, D-27, D-29, D-30, D-31, D-32 (16 decisions) | `PROPOSED` |
| Requiring approval | None (all decisions resolved or proposed; 0 pending approval) | `NEEDS APPROVAL` (0) |

Full register: [`../architecture/ARCHITECTURE_DECISIONS.md`](../architecture/ARCHITECTURE_DECISIONS.md).
All owner-approved decisions are authoritatively recorded as `ACCEPTED`.

### A.3 Required diagrams

| # | Diagram | Location |
|---|---|---|
| 1 | High-level system | `architecture/TARGET_ARCHITECTURE.md` |
| 2 | Component | `architecture/SYSTEM_COMPONENTS.md` §1 |
| 3 | Online request/response | `architecture/DATA_FLOW.md` §1, §2 |
| 4 | Offline write + sync | `architecture/DATA_FLOW.md` §3 |
| 5 | Guest-to-registered migration | `architecture/DATA_FLOW.md` §4 |
| 6 | Backend coexistence | `architecture/BACKEND_COEXISTENCE.md` §3.2 |
| 7 | Auth/authz flow | `architecture/DATA_FLOW.md` §5 |
| — | Additional: settlement closure, change capture, conflict, deletion, membership | `architecture/DATA_FLOW.md` §6–§10 |

Every diagram describes the documented design; none is decorative.

### A.4 Architecture claims that rest on a verification, not a fact

| Claim | Depends on | Where |
|---|---|---|
| Flutter Web runs under the current CSP | CanvasKit WASM compiles under `script-src` without `'wasm-unsafe-eval'` | **Unverified** — spike A-WEB-1, `deployment/DEPLOYMENT_ARCHITECTURE.md` §4.4 |
| Triggers behave identically on both engines | A-DB-3 parity spike | **Unverified** — `database/DATABASE_DESIGN.md` A-DB-3 |
| Cross-table DML in group trigger is permitted | A-DB-4 spike (`trg_groups_bd` updating `expenses`) | **Unverified** — `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.6.9 |
| A restore cannot silently strand a device | `410` on a cursor ahead of the watermark | **Specified, not implemented** — finding F-13 |
| The legacy UI is unaffected | PR-23 manual scenario | **Specified, not executed** — `testing/ARCHITECTURE_VALIDATION_PLAN.md` §3.2 |

### A.5 What the architecture deliberately does not do

No microservices, broker, Kubernetes, container, second database, second auth system, JWT, CORS,
soft-delete columns, or offline settlement confirmation. Each exclusion has a recorded reason
(`ARCHITECTURE_DECISIONS.md`; `TECHNOLOGY_STACK.md` §2).

---

## B. Database

### B.1 Inventory

| Category | Count | Detail |
|---|---:|---|
| Existing tables, unchanged in meaning | 14 | `database/DATABASE_AUDIT.md` is the authority for the current state |
| New tables | 6 | `sync_devices`, `sync_change_log`, `sync_tombstones`, `sync_batches`, `guest_import_batches`, `guest_import_items` |
| Tables receiving columns | 7 | `expenses`, `budgets`, `lists`, `list_items`, `groups`, `group_members`, `settlements` — `uuid`; `version`; `updated_at` where absent |
| New triggers | 28 (7 tables × 4: before-update, after-insert, after-update, before-delete) | `DATABASE_SCHEMA_SPECIFICATION.md` §5.6.1 |
| Deprecated objects | 0 | Nothing is dropped |
| Unresolved structures | 3 | Orphaned expenses (UQ-11), group-deletion semantics (UQ-07), retention windows (UQ-09) |

### B.2 What was decided, and what was refused

| Decided | Refused, with reason |
|---|---|
| `uuid CHAR(36) ascii_bin` as canonical identity; surrogate `id` retained (D-12) | Replacing `id` — every existing query would change |
| `version INT UNSIGNED DEFAULT 1` incremented by trigger (VC-02) | Version maintained by application code only — a direct SQL writer would skip it |
| Change capture in triggers with actor session variables (D-13) | Application-level logging — it would miss CLI writes and direct SQL |
| Tombstones in a separate table (D-20) | `deleted_at` soft-delete columns — every existing query would need a new predicate (silent regression across 40 endpoints) |
| Cascade gaps compensated by parent tombstones (F-03) | Triggers on cascades — InnoDB does not fire them. Group deletion adds personally-scoped member tombstones and an explicit expense detach (`DATABASE_SCHEMA_SPECIFICATION.md` §5.4.3–§5.4.4) |
| `CHECK (type='personal' ⇔ group_id IS NULL)` **not** added | It would fail against existing orphaned rows (F-02, UQ-11) |
| Money remains `DECIMAL(12,2)` on the server | Float columns — the current type is correct; only the wire format is inconsistent (F-06) |

### B.3 Migration

Sequence M1–M6 (`database/DATABASE_MIGRATION_STRATEGY.md` §4), each file guarded and idempotent.
The execution protocol (§7) requires a pre-execution backup, a copy-of-production rehearsal, and the
post-execution verification (triggers present, backfill complete, v1 still green).

| Risk | Status |
|---|---|
| Engine parity (MySQL 8 vs MariaDB 10.4) | **Unverified** — A-DB-3 spike, `testing/ARCHITECTURE_VALIDATION_PLAN.md` §4.3 |
| Rollback of `DATETIME(6)` after sub-second data exists | Restore-only; stated explicitly (§4.5 of the plan) |
| Backfill minting new UUIDs on a re-run | Prevented by "write only where null" |
| `chk_settlement_period` missing from `schema.sql` | Finding F-14 (§F.3) |

### B.4 Schema drift recorded, not fixed

| Item | Verified state | Consequence |
|---|---|---|
| `chk_settlement_period` | Exists in `migration_v2.9_settlement_hardening.sql` only; **absent from `schema.sql`** | A fresh clone built from `schema.sql` lacks the constraint; M6 is a documentation/parity task, not a live change |
| `settlement_confirmations` unique key | `UNIQUE (group_id, user_id)` — **not** per period | Confirmation validity depends on the server-computed `period_end` at call time (F-05); this is why confirmation is online-only (D-17) |
| Orphaned group expenses | `type='group'` with `group_id IS NULL` can exist (F-02) | No `CHECK` may assume otherwise until UQ-11 is answered |

---

## C. API

### C.1 Existing surface (frozen)

| Fact | Value |
|---|---|
| HTTP endpoints | **40** (36 module + 3 auth + 1 system; includes deprecated `settle.php` & `settle_all.php` [410 Gone, UQ-10 Option B]) |
| CLI-only worker | **1** (`api/workers/process_outbox.php`, `403` over HTTP) |
| PHP files in `api/` | 64 (endpoints + helpers + middleware + dormant services) |
| Contract | `docs/API_CONTRACT.md` — envelopes, permissions, error strings, pagination ceilings |
| Status | Frozen. No field, status code, or error string may change (D-31, PR-23) |

### C.2 Proposed surface (not implemented)

| Endpoint | Purpose | Status |
|---|---|---|
| `GET /api/v2/auth/session.php` | Session status + CSRF bootstrap (closes F-01) | `PROPOSED` |
| `POST /api/v2/devices/register.php` | Register a device, return the secret once | `PROPOSED` |
| `GET /api/v2/devices/list.php` | The caller's devices | `PROPOSED` |
| `POST /api/v2/devices/revoke.php` | Revoke a device | `PROPOSED` |
| `GET /api/v2/sync/bootstrap.php` | Bounded, resumable initial snapshot | `PROPOSED` |
| `GET /api/v2/sync/pull.php` | Incremental changes + tombstones + digests | `PROPOSED` |
| `POST /api/v2/sync/push.php` | Apply a batch of offline operations | `PROPOSED` |
| `GET /api/v2/sync/state.php` | Cursor validity, watermark, digests | `PROPOSED` |
| `POST /api/v2/import/guest.php` | Import guest data into a new account | `PROPOSED` |
| `GET /api/v2/import/status.php` | Import batch status + digest | `PROPOSED` |

**None of these exists.** Every one carries the 15 required attributes
(`api/SYNC_API_SPECIFICATION.md` §4) and is listed as proposed in
`api/API_CONTRACT_SPECIFICATION.md` §2.3.

### C.3 Contract decisions a client can rely on (once v2 exists)

| Topic | Decision |
|---|---|
| Envelope | `{ok: bool, ...}` on both surfaces |
| Money | Decimal string with two decimals on the wire; integer cents in Dart (D-21) |
| Concurrency | `base_version` required on update/delete; mismatch is `409 VERSION_CONFLICT` |
| Idempotency | HTTP key + batch key + record UUID + `base_version` (four levels) |
| Rate limits | v1 unchanged (user 120/min, IP 240/min); v2 adds an additive `api_sync` bucket at 120/min (D-28) |
| Refusal codes | v1 uses **400** for permission refusals (verified); v2 uses **403** — asserted separately in tests |
| Versioning | v2 is additive within a generation; a breaking change is a new generation (`api/API_VERSIONING.md`) |

### C.4 The implementation prerequisite

`api/domain/` does not exist. Every v2 write endpoint depends on the extracted rules (D-30), and the
extraction must be behaviour-preserving (T-REG-11). Until it exists, a v2 write would either
duplicate a rule or call a v1 endpoint over HTTP — both are rejected by
`BACKEND_COEXISTENCE.md` §6.

### C.5 Laravel

No module passes the five tests (`BACKEND_COEXISTENCE.md` §5.2). That is a recorded finding, not an
oversight. The gateway (D-18) and every Laravel section are dormant until UQ-06 is answered.

---

## D. Guest and registered users

| Aspect | Design |
|---|---|
| Guest identity | Local only; no server identity; **zero** API requests (GM-01, D-04) |
| Guest capability | Local personal expenses, categories, budgets, lists, items, calendar, analytics; theme and data export disabled (UQ-16 / D-32) |
| Transition | Register → import into the **new** account, verified by counts + digest before local deletion |
| Failure | Guest data is never destroyed by a failure; the import is idempotent by `batch_uuid` |
| Import into an existing account | **Resolved** — UQ-01 / D-22 Option C approved: dry run with two-tier duplicate check; local guest partition `account_id = 'guest'` preserved if skipped |
| Account deletion | **Approved** — UQ-02 / D-23: pre-flight balance gate (debit & credit block deletion; forfeiture prohibited; sole admin two succession choices: manual successor or automatic transfer to earliest-joined member); original username retained with `"This user deleted their account."`; full session/device revocation; subject to 6-month group permanent deletion workflow (UQ-07 / D-26) |
| Ownership transfer | **Not designed as a feature.** Group ownership changes are not part of the API; nothing in Phase 2 assumes one |
| Account switching | Guest data is namespaced; a login cannot read it without an explicit import |

The honest statement of the limitation: a guest who later registers on a **different** device has no
path to their data, because guest data never leaves the device (D-04). That is a product consequence
of the accepted decision, not a gap in this design.

---

## E. Synchronisation

### E.1 The protocol

| Element | Design |
|---|---|
| Identity | Client-generated UUIDv7, stored as `CHAR(36) ascii_bin`; server keeps the surrogate `id` |
| Concurrency | Server-maintained `version`; client sends `base_version`; mismatch is a conflict, never an overwrite |
| Change capture | Triggers write `sync_change_log` rows with scope columns computed from the row |
| Ordering | `seq` (auto-increment) + a 2-second safety lag so a late commit cannot be skipped (D-27) |
| Deletion | Hard delete + `sync_tombstones`; cascade gaps compensated by parent tombstones, and for group deletion by one personally-scoped `group_member` tombstone per member plus an explicit expense detach |
| Cursor | Server-generated, opaque; malformed → `400 INVALID_CURSOR`; below the retained floor **or ahead of the watermark** → `410 CURSOR_TOO_OLD` → bootstrap |
| Convergence check | One `SHA-256` per entity over canonical `"{uuid}:{version}\n"` lines sorted by UUID, scoped to the caller (`SYNC_API_SPECIFICATION.md` §6.5) |

### E.2 Offline capability (D-16, D-17)

| Entity | Offline write | Note |
|---|---|---|
| Personal expense | Yes | Full create/update/delete |
| Group expense | Yes | Authorisation re-checked on push |
| Budget | Yes | One field; manual conflict |
| Personal list + items | Yes | Items ordered by rank; link preserved |
| Group list / item | **No** | Online-only; the two-step check needs `paid_by` |
| Settlement / confirmation | **No** | Server-computed period makes offline confirmation unsound (F-05) |
| Notification | **No** | Excluded from the offline store (D-19) |
| Group / membership / category | Read-only cache | Server-authoritative |

### E.3 Conflict policy

Per entity, not universal. Financial values are **never** auto-merged; server-authoritative entities
are server-wins because the client cannot write them offline. There is deliberately **no universal
conflict policy** (the checkpoint requirement); each branch has a named policy and a test
(`testing/ARCHITECTURE_VALIDATION_PLAN.md` §10).

### E.4 Honest limits

| Limit | Consequence |
|---|---|
| No background sync when an app is closed | The queue drains on next open (`FLUTTER_ARCHITECTURE.md` §10.5) |
| Change log retained 90 days (recommended, UQ-09) | A device offline longer than that re-bootstraps |
| A restore rewinds `seq` | Devices must re-bootstrap; now specified (F-13) |
| Conflict UX is a recommendation | UQ-12; no conflict centre is claimed to exist |

---

## F. Documentation

### F.1 Inventory

33 documents, **17 941 lines**, grouped as follows.

| Group | Files | Lines |
|---|---:|---:|
| `docs/WORK/` | 3 (plan, traceability, this report) | 1 702 |
| `docs/architecture/` | 8 | 4 393 |
| `docs/database/` | 6 | 3 711 |
| `docs/api/` | 6 | 2 580 |
| `docs/synchronization/` | 5 | 2 497 |
| `docs/security/` | 3 | 1 311 |
| `docs/deployment/` | 1 | 880 |
| `docs/testing/` | 1 | 867 |
| **Total** | **33** | **17 941** |

**Counting method — stated so the figure is reproducible.** A line is one `LF`-terminated segment:
`(ReadAllText -split "\n").Count - 1`, summed per directory. `Get-Content | Measure-Object -Line`
reports a *lower* number for the same files (13 869) because it omits blank lines and final lines
without a terminator. Both are correct for their definition; this report uses the raw-LF count, and
the pre-correction figure of "~16 526" was an estimate that matched neither. The per-file list is in
§H.2.

### F.2 Consistency checks performed

| Check | Method | Result |
|---|---|---|
| Relative Markdown links resolve | Extracted every `](*.md)` link and tested the path | 1 broken (this report, before it existed); now 0 |
| Section cross-references resolve | Extracted every `file.md §N` reference, associated each with the nearest preceding file mention on its line, and matched it against the target's headings | **751 references checked, 0 unresolved** after the correction pass (§I.7) |
| Decision statuses | Parsed all 38 decision entries | 22 `ACCEPTED`; 16 `PROPOSED`; 0 `NEEDS APPROVAL` |
| UQ register | Parsed all 20 entries | 18 `APPROVED`, 2 `APPROVED (PROVISIONAL)` [UQ-05, UQ-09], 0 `NOT APPROVED` |
| Requirement coverage | 164 requirements across 13 groups | 164 `SPECIFIED`/`PRESERVED` (135 `SPECIFIED`, 29 `PRESERVED`), 0 `PARTIAL`, 0 `OPEN`, **0 unaddressed** |
| Proposed-vs-existing wording | Searched for "exists" near v2 endpoints | Every v2 endpoint is labelled proposed; the inventory in §2.2 is v1-only |
| Baseline references | Searched for `433`, `105`, `538` | Consistent; no document claims a different baseline |
| Status vocabulary | Searched for other status words in decision/UQ tables | Only `ACCEPTED`, `PROPOSED`, `APPROVED`, `NOT APPROVED` used |
| Test-case IDs | Counted `T-` identifiers in the validation plan (§15.5) | **212** unique cases across 14 prefixes + a 12-step per-platform smoke matrix (not IDs) |

### F.3 Findings recorded during Phase 2

The first nine were recorded in the design plan; F-10…F-14 were found while writing the later
documents; F-15…F-20 were found by the owner's review of the Phase 2 package and are closed (or, for
F-19, clarified) by the correction pass recorded in §I.

| ID | Finding | Evidence | Impact | Status |
|---|---|---|---|---|
| F-01 | No machine-readable session/CSRF bootstrap | `pages/login.php:25`, `public/index.php:25` | New `GET /api/v2/auth/session.php` required | `PROPOSED` endpoint |
| F-02 | Group delete destroys settlements and orphans group expenses | `schema.sql` FK actions; `api/groups/delete.php` | Irreversible loss; per-member tombstones + explicit expense detach (`DATABASE_SCHEMA_SPECIFICATION.md` §5.4.3–§5.4.4); remediation UQ-11 | **RESOLVED** [SUPERSEDED: UQ-11 & UQ-07 approved Option B] |
| F-03 | InnoDB cascades do not fire triggers | Engine behaviour | Change capture cannot see cascade children; parent tombstones compensate, except group deletion, which needs per-member tombstones (A-DB-4) | Designed around |
| F-04 | User delete cascades away group data they recorded | `schema.sql` FK actions | Account deletion cannot be a bare `DELETE` | **RESOLVED** [SUPERSEDED: UQ-02 approved with balance gate and no forfeiture] |
| F-05 | Confirmation validity depends on server-computed `period_end` | `settlement_confirmations` unique `(group_id, user_id)`; `confirm.php` | Confirmation must stay online | Decided (D-17) |
| F-06 | Money emitted as mixed string/float | `API_CONTRACT.md` §10.1 | Wire format fixed to decimal string | Decided (D-21) |
| F-07 | `can_edit`/`settled` are server-computed and advisory offline | `api/expenses/list.php` | Client treats them as advisory until revalidated | Designed |
| F-08 | Rate limiting applies to every authenticated request | `api/middleware/auth.php` | Sync needs its own bucket | Decided (D-28) |
| F-09 | `csrf_token` cookie is readable and rotates | `api/helpers/csrf.php` | Client must re-read after login/rotation | Designed |
| **F-10** | **`README.md` v2.5 states notifications moved to a file store and "no SQL tables are read or written"** | `README.md:799` vs `api/notifications/list.php:34,41,49` (SQL) and KD-05 | A reader trusting the README would build against a backend that is not wired | **Documentation defect — not fixed** (README is outside Phase 2 scope) |
| **F-11** | **The CSP has no `'wasm-unsafe-eval'`, and CanvasKit is a WASM module** | `config/session.php:81`, `.htaccess` | The Flutter Web build may not run under the current header; a CSP amendment is a decision | **`NEEDS VERIFICATION`** (spike A-WEB-1) then `NEEDS APPROVAL` |
| **F-12** | **`AGENTS.md` says non-admin edit/delete of a group expense returns HTTP 403; v1 returns 400** | `api/helpers/response.php` default; 187 call sites in the 40 endpoint files; no v1 endpoint passes 403 for an authorisation denial | A client or test written from `AGENTS.md` would assert the wrong code; v1 must not change | **Documentation defect — v1 unchanged** |
| **F-13** | **A cursor ahead of the server watermark (after a restore) was not specified** | Restore rewinds `sync_change_log.seq`; `pull` would return an empty page forever | Silent, permanent divergence for that device | **Closed in documentation**: `SYNC_API_SPECIFICATION.md` §6.4 + `DEPLOYMENT_ARCHITECTURE.md` §8.6; implementation + T-SYNC-C3 outstanding |
| **F-14** | **Schema drift: `chk_settlement_period` is only in `migration_v2.9_settlement_hardening.sql`; `settlement_confirmations` is `UNIQUE (group_id, user_id)`, not per period** | `schema.sql:247-255` vs the migration file | A fresh clone from `schema.sql` lacks the constraint; per-period uniqueness is not enforced by the schema | **Documented**; M6 is a parity task, not a live change |
| **F-15** | **Cursor contract contradicted itself**: `SYNC_WORKFLOWS.md` §7.3 said a future cursor is `400 INVALID_CURSOR`, while F-13, `SYNC_API_SPECIFICATION.md` §6.4, `DEPLOYMENT_ARCHITECTURE.md` §8.6 and `T-SYNC-C3` said `410 CURSOR_TOO_OLD` | Two documents, two behaviours, same input | A client built from the workflow would not bootstrap after a restore | **Closed** — one canonical table (`SYNC_API_SPECIFICATION.md` §6.4); every other document defers to it (§I.1) |
| **F-16** | **Group-deletion tombstones were insufficient**: `T-SYNC-10` expected parent *and* child tombstones; the design emitted the parent only; the parent tombstone is group-scoped and the cascade deletes the membership that resolves it; `expenses.group_id ON DELETE SET NULL` was described as "logged normally" although referential actions fire no trigger | `DATABASE_SCHEMA_SPECIFICATION.md` §5.4.2, `SYNC_WORKFLOWS.md` §6.4, `SYNCHRONIZATION_ARCHITECTURE.md` §6.2, `T-SYNC-10` | Former members would retain the group's cached data for ever; surviving expenses would change silently | **Closed in documentation** — per-member tombstones + explicit detach (`DATABASE_SCHEMA_SPECIFICATION.md` §5.4.3–§5.4.4, §5.6.9); verification spike **A-DB-4** outstanding (§I.2) |
| **F-17** | **The reconciliation digest had three definitions**: D-27 "count + max version hash", the API spec "`SHA-256` over the sorted `(uuid, version)` list", the offline doc "a hash over the sorted list" | Three documents | Two conforming clients could disagree on a digest and never converge | **Closed** — one canonical algorithm (`SYNC_API_SPECIFICATION.md` §6.5); D-27 and every summary defer to it (§I.3) |
| **F-18** | **The test inventory did not add up**: the plan stated 177 unique IDs, the readiness report's category table summed to 184, and an earlier measurement produced 174 | Validation plan vs readiness report §G.2 | An unverifiable test count in an approval document | **Closed** — 188 measured; per-prefix inventory and overlap rule in the plan §15.5 (§I.6) |
| **F-19** | **Device revocation conflated three effects**: sync privilege, the PHP session, and access to downloaded local data were described as if one switch controlled all three | D-25, UQ-04, `SESSION_MANAGEMENT.md` §6, `DATABASE_SCHEMA_SPECIFICATION.md` §5.1.1 | The owner cannot answer UQ-04 without knowing which effect each option changes | **RESOLVED** [SUPERSEDED: UQ-04 Option B approved for database-backed session registry via `app_sessions`] |
| **F-20** | **`sync_batches` retention was stated three ways**: "until superseded" (`DATABASE_DESIGN.md` §4.3), "30 days after completion" (`DATABASE_SCHEMA_SPECIFICATION.md` §5.3, `DATABASE_MIGRATION_STRATEGY.md` §7.5), and absent from UQ-09's question | Three documents | An operator could prune idempotency records too early or never | **RESOLVED** [SUPERSEDED: UQ-09 retention schedules approved: 45-day sync batches, 90-day sync logs, lifetime guest audit] |

### F.4 Corrections made to already-written documents

| Document changed | Correction | Reason |
|---|---|---|
| `architecture/ARCHITECTURE_DECISIONS.md` | Frontend-audit pointer 9.3 → 9 | The target section has no subsections |
| `architecture/BACKEND_COEXISTENCE.md` | Data-flow pointer 0 → 1–10 | The target has no section 0 |
| `database/DATABASE_SCHEMA_SPECIFICATION.md` | Sync-spec pointer 7.3 → 4.2 | Section 7.3 does not exist; the device contract is 4.2 |
| `WORK/REQUIREMENTS_TRACEABILITY.md` | VC-03/04/08 pointers 7.2/7.4/7.3 → 4.7.1/4.7.2/4.7.3 | The precise attribute subsections |
| `api/AUTHENTICATION_AND_AUTHORIZATION.md`, `api/API_ERROR_HANDLING.md` | Call-site count 156 → 187 (measured), and the explicit-status description corrected | The earlier figure was stale; three documents must state the same measured number |
| `api/SYNC_API_SPECIFICATION.md` | Added two rows to 6.4 | F-13: a cursor ahead of the watermark is 410, not an empty page |

§F.4 records the pointer-level corrections made while the package was being written. Those six
changed no behavioural statement. The **correction pass in §I** is different: it changes specified
behaviour in five areas (cursor recovery, group-deletion cascade, the digest algorithm, device
revocation wording, and batch retention) and is recorded separately so that the two kinds of change
are never confused.

### F.5 Reading order

1. `WORK/PHASE_2_DESIGN_PLAN.md` — scope and sequence.
2. `architecture/ARCHITECTURE_DECISIONS.md` — wins on any contradiction.
3. `architecture/TARGET_ARCHITECTURE.md` + `SYSTEM_COMPONENTS.md` — the shape.
4. `database/DATABASE_SCHEMA_SPECIFICATION.md` + `DATABASE_MIGRATION_STRATEGY.md` — the data.
5. `api/SYNC_API_SPECIFICATION.md` + `synchronization/SYNC_WORKFLOWS.md` — the protocol.
6. `security/SECURITY_ARCHITECTURE.md` + `security/ACCESS_CONTROL.md` — the controls.
7. `deployment/DEPLOYMENT_ARCHITECTURE.md` — where it runs.
8. `testing/ARCHITECTURE_VALIDATION_PLAN.md` — how it is proven.
9. `architecture/UNRESOLVED_QUESTIONS.md` — what is still open.

---

## G. Validation

### G.1 The baseline

| Item | Value |
|---|---|
| Suite | `tests/run_tests.php` (CLI only) |
| Baseline | **433 passed / 105 failed / 538 tests** |
| Evidence | `docs/evidence/phase1-test-baseline.txt` |
| Phase 2 effect | **None by construction** — no file under `api/`, `config/`, `pages/`, `public/`, `tests/`, `scripts/`, `schema.sql`, or `migration_v*.sql` was changed (§H) |

**Why the baseline was not re-run for this report:** a re-run measures the database's current state as
much as the code. Since Phase 2 changed no executable file, a different number would indicate a
pre-existing environmental difference, not a Phase 2 regression — and would obscure the real gate.
The gate is therefore: **run the suite before the first implementation commit and confirm
433/105/538.** If it differs, reconcile the environment first.

**Owner Policy on the 105 Baseline Failures:** By owner decision, the 105 test failures in `tests/run_tests.php`
represent the inherited Phase 1 baseline (`docs/TEST_BASELINE.md`), to be investigated before implementation,
rather than making their resolution a prerequisite for Phase 2 sign-off.

### G.2 What the validation plan obliges

| Requirement | Cases | Method |
|---|---:|---|
| TS-01 migration forward/backfill/rollback (`T-MIG`) | 17 | `MIGRATION-TEST` |
| TS-02 v1 + v2 contract (`T-API`) | 18 | `CONTRACT-TEST` |
| TS-03 role boundaries (`T-AUTH`) | 20 | `CONTRACT-TEST` |
| TS-04 guest + failure injection (`T-GUEST`) | 16 | `CONTRACT-TEST` |
| TS-05 offline restart/corruption (`T-OFF`) | 12 | `MANUAL-SCENARIO` + `INTEGRATION-TEST` |
| TS-06 convergence (`T-SYNC-01…23` + `T-SYNC-C1…C5`) | 28 | `CONTRACT-TEST` |
| TS-07 every conflict branch (`T-CONF`) | 18 | `CONTRACT-TEST` |
| TS-08 multi-device (`T-MULTI`) | 9 | `MANUAL-SCENARIO` |
| TS-09 idempotency (`T-IDEM`) | 10 | `CONTRACT-TEST` |
| TS-10 security, 18 surfaces + 7 attacks + 6 regressions (`T-SEC`) | 31 | `PENTEST` |
| TS-11 platform smoke (`T-WEB`) | 5 | `PLATFORM-SMOKE` |
| TS-12 regression preservation (`T-REG`) | 12 | `CONTRACT-TEST` |
| Settlement correctness (`T-SET`) | 12 | mixed |
| List correctness (`T-LIST`) | 4 | mixed |
| **Total** | **212** | |

**Total: 212 unique enumerated cases**, plus a 12-step smoke matrix executed *per platform* against
the five `T-WEB` cases. Every case has a pass condition; none is claimed as passing.

**Overlap is now stated, not hidden.** The 212 are unique `T-` identifiers, each under exactly one
prefix. The 12-step matrix is **not** a set of test-case IDs and must not be added to the total; it is
the per-platform checklist for `T-WEB-01…05`. The cursor cases `T-SYNC-C1…C5` live inside the
`T-SYNC` prefix (hence 28, not 23) and are **not** re-counted under settlement/list/cursor
correctness. The measured inventory and the counting rule are in `testing/ARCHITECTURE_VALIDATION_PLAN.md` §15.5.

### G.3 Exit criteria

| Gate | Criterion | State |
|---|---|---|
| Documentation complete | Every requirement has an owner doc and a method | **Met** |
| Cross-document consistency | 751 section references resolve; statuses comply; seven corrections applied (§I) | **Met** |
| No source change | `git status` shows only `docs/` | **Met** |
| Owner approval | UQ-20 | **Met (Approved on 2026-11-18)** |
| Implementation start | UQ-20 NOT APPROVED; spikes in PLANNED status; endpoint/test edits on hold | **Deferred / Gated by Spikes & UQ-20** |
| First migration | A-DB-3 parity + A-DB-4 (trigger DML) + T-MIG-01…17 | **Not started** |
| Web release | A-WEB-1 + T-WEB-01…05 | **Not started** |

### G.4 Status Reassessment; Implementation Gated by Spikes and UQ-20

The architecture documentation accurately distinguishes approved policies from deferred implementation gates:

1. **Owner-Approved Policies Incorporated:**
   - **UQ-02 & UQ-07:** The cross-policy interaction between preserving historical group records/usernames upon account deletion (D-23) versus allowing 6-month permanent group deletion (D-26) is authoritatively resolved. Sole administrator has two succession choices (manual successor designation or automatic transfer to earliest-joined member). Archival distinction confirmed: `ARCHIVED` is reversible within 3 months; `PERMANENTLY_ARCHIVED` cannot be reversed; permanent deletion eligible after 6 months by administrator only (no member fallback). Both D-23 and D-26 are `ACCEPTED`.
   - **UQ-08 & D-17:** Group membership mutations are approved as strictly online-only operations. D-17 is `ACCEPTED`.
   - **UQ-09:** Retention values updated: 90 days change logs; tombstones no fixed expiry with future safe GC design; completed sync batches updated to 45 days (from 30); lifetime guest audit.
   - **UQ-10 & D-31:** Deprecating `settle.php` and `settle_all.php` to return HTTP 410 Gone is an approved narrow exception to the frozen v1 contract. Post-UQ-20 implementation planning explicitly covers refactoring both `tests/run_tests.php` and `scripts/pentest/penetration_test.php`.
   - **UQ-18:** Explicit app-level acknowledgement (`POST /api/v2/reminders/ack.php`) is the approved measurable definition of successful receipt.
   - **105 Failures:** Inherited Phase 1 baseline to investigate before implementation, not a gate for Phase 2 sign-off.
2. **Provisional Items:**
   - **UQ-05:** Batch payload limits (100 records / 1 MB) are proposed initial defaults subject to Phase 5 load testing.
   - **UQ-09:** Tombstone safe GC mechanism design remains provisional pending operational deployment.
3. **Implementation Gated by UQ-20:**
   - Implementation does NOT start. It remains strictly gated by future owner approval of UQ-20, execution and validation of the three technical verification spikes (A-DB-3, A-DB-4, A-WEB-1 in `PLANNED` status), and pre-implementation test baseline confirmation. Actual PHP endpoint changes and test modifications remain strictly on hold.

---

## H. Git status

### H.1 State

| Item | Value |
|---|---|
| Branch | `main` |
| HEAD | `ec0e692` — "Docs: correct the logout forced-logout mechanism (SameSite=Lax)" |
| Working tree | **One tracked documentation file modified** (`docs/README.md`, +56 lines: the Phase 2 index) and **eight untracked `docs/` directories**. No application, schema, migration, test, or script file modified |
| Commits made in Phase 2 | **None** |
| Tags created | **None** — no `phase2-*` tag exists or should exist before approval (UQ-20) |
| Application source touched | **None** |

### H.2 Untracked additions

`docs/README.md` is the only modified tracked file (the Phase 2 index appended below the Phase 1
content; Phase 1 text preserved). The following are new:

```
docs/WORK/PHASE_2_DESIGN_PLAN.md
docs/WORK/REQUIREMENTS_TRACEABILITY.md
docs/WORK/PHASE_2_READINESS_REPORT.md
docs/architecture/ARCHITECTURE_DECISIONS.md
docs/architecture/BACKEND_COEXISTENCE.md
docs/architecture/DATA_FLOW.md
docs/architecture/FLUTTER_ARCHITECTURE.md
docs/architecture/SYSTEM_COMPONENTS.md
docs/architecture/TARGET_ARCHITECTURE.md
docs/architecture/TECHNOLOGY_STACK.md
docs/architecture/UNRESOLVED_QUESTIONS.md
docs/database/DATABASE_DESIGN.md
docs/database/DATABASE_MIGRATION_STRATEGY.md
docs/database/DATABASE_SCHEMA_SPECIFICATION.md
docs/database/DATA_OWNERSHIP.md
docs/database/ENTITY_RELATIONSHIP_DIAGRAM.md
docs/database/VERSIONING_AND_CONFLICT_RESOLUTION.md
docs/api/API_ARCHITECTURE.md
docs/api/API_CONTRACT_SPECIFICATION.md
docs/api/API_ERROR_HANDLING.md
docs/api/API_VERSIONING.md
docs/api/AUTHENTICATION_AND_AUTHORIZATION.md
docs/api/SYNC_API_SPECIFICATION.md
docs/synchronization/CONFLICT_RESOLUTION.md
docs/synchronization/GUEST_DATA_MIGRATION.md
docs/synchronization/OFFLINE_DATA_MANAGEMENT.md
docs/synchronization/SYNC_WORKFLOWS.md
docs/synchronization/SYNCHRONIZATION_ARCHITECTURE.md
docs/security/ACCESS_CONTROL.md
docs/security/SECURITY_ARCHITECTURE.md
docs/security/SESSION_MANAGEMENT.md
docs/deployment/DEPLOYMENT_ARCHITECTURE.md
docs/testing/ARCHITECTURE_VALIDATION_PLAN.md
```

### H.3 Verification commands

```powershell
git status --short          # M docs/README.md + ?? docs/<phase-2 dirs>
git diff --stat             # docs/README.md only (index appended)
git tag --list "phase2*"    # empty
git log --oneline -1        # ec0e692
```

### H.4 What a commit would contain

A single documentation commit (or a small series per stage) containing the 33 new files above plus
the `docs/README.md` index extension, with a message that states the phase, the decision count, and
that no source changed. **It is not made by this report.** The owner approves first (UQ-20), then the
commit, then the tag.

---

## I. Correction report — owner review pass

The project owner reviewed the Phase 2 package and withheld UQ-20 approval pending seven
consistency corrections. This section is the record of that pass: what was wrong, which documents
changed, and what the specification now says. **No source file, test, schema, or migration was
touched. No decision or UQ changed status. No commit or tag was made.**

### I.0 Summary

| # | Correction | Discrepancy | Documents changed | Behaviour changed? |
|---|---|---|---|---|
| 1 | Cursor recovery | Workflow said `400` for a future cursor; four other documents said `410` | 10 | **Yes** — one contract, now canonical in one table |
| 2 | Group deletion / cascade | Parent-only tombstones cannot reach former members; `SET NULL` described as logged; no `move_out`; `T-SYNC-10` expected child tombstones | 11 | **Yes** — per-member tombstones, explicit detach, `move_out` |
| 3 | Canonical digest | Three different algorithms across D-27, the API spec, the offline doc | 8 | **Yes** — one exact algorithm |
| 4 | Device revocation | Sync privilege, session, and local-data access conflated | 4 | **No** — wording clarified; [SUPERSEDED: resolved by owner approving Option B (D-25 / UQ-04)] |
| 5 | Retention | `sync_batches` stated three ways; UQ-09 did not cover batches | 3 | **No** — [SUPERSEDED: resolved by owner approving retention schedules under UQ-09 (45-day batches, lifetime guest audit)] |
| 6 | Test inventory | 177 stated, 184 summed, 174 measured once | 4 | **No** — [SUPERSEDED: finalized at 212 unique test cases in §15.5] |
| 7 | Validation re-run | — | 2 (this report, `docs/README.md`) | — |

### I.1 Correction 1 — cursor recovery is one contract

**Discrepancy.** `SYNC_WORKFLOWS.md` §7.3 stated that "a cursor is a valid but future value" returns
`400 INVALID_CURSOR`. `SYNC_API_SPECIFICATION.md` §6.4, `DEPLOYMENT_ARCHITECTURE.md` §8.6, the
F-13 finding, and `T-SYNC-C3` all stated `410 CURSOR_TOO_OLD` → bootstrap. `API_ERROR_HANDLING.md`
described `INVALID_CURSOR` as "malformed **or future**". A client implemented from the workflow would
not bootstrap after a restore and would stop converging silently.

**Resulting specification.** One canonical table, `SYNC_API_SPECIFICATION.md` §6.4:

| Cursor | Response |
|---|---|
| Malformed / unparseable | `400 INVALID_CURSOR` |
| Well-formed, below `MIN(seq)` of the retained log | `410 CURSOR_TOO_OLD` → bootstrap |
| Well-formed, inside `[MIN(seq), watermark]` | `200` page |
| Well-formed, above the watermark | `410 CURSOR_TOO_OLD` → bootstrap |
| Between watermark and `MAX(seq)` (allocated, not safe) | `200`, empty `changes`, `next_cursor = watermark` — **not** an error |

`sync/state.php` reports `cursor_valid:false` for both out-of-range directions, with an optional
`cursor_reason` (`too_old` / `ahead_of_watermark`). A malformed cursor is `400` there too, not a
`cursor_valid:false` body.

**Documents changed:** `api/SYNC_API_SPECIFICATION.md` (§4.6 attrs 9/15, §4.8 attrs 8/9/15, §6.4
rewritten as canonical), `synchronization/SYNC_WORKFLOWS.md` §7.3, `api/API_ERROR_HANDLING.md`
(two rows), `security/SECURITY_ARCHITECTURE.md` §10, `deployment/DEPLOYMENT_ARCHITECTURE.md` §8.6,
`database/VERSIONING_AND_CONFLICT_RESOLUTION.md`, `synchronization/SYNCHRONIZATION_ARCHITECTURE.md`
(§5.2, §8), `architecture/ARCHITECTURE_DECISIONS.md` D-27,
`testing/ARCHITECTURE_VALIDATION_PLAN.md` (§6.4 — `T-SYNC-C4`, `T-SYNC-C5` added).

### I.2 Correction 2 — group deletion and cascade synchronisation

**Discrepancy (six sub-items).** The design emitted only the parent `group` tombstone, which is
group-scoped; group deletion cascade-deletes every `group_members` row, so the tombstone resolved to
nobody. `expenses.group_id ON DELETE SET NULL` was described as "ordinary changes logged normally",
although referential actions fire no trigger. `T-SYNC-10` expected parent **and** child tombstones.
The workflow contained an unresolved inline question about retaining orphaned expenses. No event told
the old scope that an expense had moved away.

**Resulting specification.**

| Sub-item | Resolution |
|---|---|
| **A. Test versus design** | The test is aligned to the design: `T-SYNC-10` now asserts the **member** tombstone, and states explicitly that per-descendant tombstones are **not** required. Lists, items, settlements are purged by the member tombstone |
| **B. Reaching every former member** | `trg_groups_bd` writes, per current member, a `group_member` delete row **and** tombstone with `scope_user_id = member.user_id`, `scope_group_id = NULL`, `cause='cascade'`. Visibility then uses the user clause, which does not depend on the membership row being deleted. Every former member purges the subtree on their own member tombstone |
| **C. `ON DELETE SET NULL`** | Not relied on. The trigger runs `UPDATE expenses SET group_id = NULL WHERE group_id = OLD.id` **before** the delete, so the ordinary expense triggers version-bump and log the change; InnoDB's later `SET NULL` is a no-op. Premise specified; to be verified by planned spike **A-DB-4** (`T-MIG-16`, `T-MIG-17`) before implementation |
| **D. Owner retains, others purge** | The detach `update` is scoped to `expenses.user_id`; the owner receives it before the purge, so the local `group_uuid` is already `null` and the subtree purge (which selects by `group_uuid = G`) skips it. Other former members never receive the update and purge their copy. `type` is **not** rewritten (provenance; UQ-11) |
| **E. Offline, interrupted, restore** | Tombstones are durable and never pruned → an offline device receives them on the next pull. An interrupted page does not advance the cursor → re-fetched idempotently. A restore that loses tombstones is detected by the digest → targeted bootstrap. Covered by `T-SYNC-16`, `T-SYNC-17` |
| **F. Expense moving scope** | New `op = 'move_out'` on `sync_change_log`, emitted by `trg_expenses_au` when `OLD.group_id <> NEW.group_id` (including `NULL` transitions). It is scoped to the **old** group, carries **no payload**, and is ordered before the new-scope `update`. The old scope removes its copy without recording a tombstone. Applies to `expense` only (lists cannot change group). Covered by `T-SYNC-18`, `T-SYNC-19` |

**Documents changed:** `database/DATABASE_SCHEMA_SPECIFICATION.md` (§5.2.1 visibility note, §5.2.2
ordering, §5.4.2 rewritten, **new §5.4.3** per-member compensation, **new §5.4.4** explicit detach,
§5.6.1 note, §5.6.4 `move_out`, **new §5.6.9** `trg_groups_bd`), `database/DATABASE_DESIGN.md` §8.2,
`database/DATA_OWNERSHIP.md` §7.4/§8.4, `database/ENTITY_RELATIONSHIP_DIAGRAM.md` §7.1/§7.2,
`synchronization/SYNC_WORKFLOWS.md` §6.1–§6.4/§7.5, `synchronization/SYNCHRONIZATION_ARCHITECTURE.md`
§6.2, `architecture/DATA_FLOW.md` §7, `architecture/TARGET_ARCHITECTURE.md` §11,
`api/SYNC_API_SPECIFICATION.md` §4.6.1/§6.3, `testing/ARCHITECTURE_VALIDATION_PLAN.md`
(`T-SYNC-08/09/10` fixed; `T-SYNC-15…19`, `T-MIG-16/17` added), `WORK/PHASE_2_READINESS_REPORT.md`
(F-02/F-03 rows).

**Verification outstanding:** spike **A-DB-4** — (i) a trigger on `groups` may perform `UPDATE` and
`INSERT` on other tables; (ii) `ON DELETE SET NULL` fires no trigger. If (i) is refused, the v2
endpoint performs steps 1–3 before `DELETE FROM groups`; the frozen v1 delete emits only the group
tombstone, and the digest repairs the divergence within one pull cycle. This is documented, not
assumed.

### I.3 Correction 3 — one canonical digest

**Discrepancy.** D-27 defined the digest as "count + max version hash"; the API specification as
"`SHA-256` over the sorted `(uuid, version)` list"; the offline document as "a hash over the sorted
list of `(uuid, version)`". Three descriptions, one field.

**Resulting specification — `SYNC_API_SPECIFICATION.md` §6.5 (canonical).**

| Aspect | Rule |
|---|---|
| Canonical serialization | One line per live record: `"{uuid}:{version}\n"` — lowercase hyphenated UUID, ASCII colon, decimal version, LF after **every** line including the last. No field names, no entity name, no timestamps, no BOM |
| Sorting | Ascending by UUID **byte order** under `ascii_bin`; `version` is never a sort key |
| Entity boundaries | One digest per entity, **never concatenated**. A `list_item` hashes as `list_item`; `group_member` as `group_member` |
| Visibility scope | Server: rows visible to the caller (`scope_user_id = :me` OR `scope_group_id` in current membership). Client: **all** local rows of that entity |
| Deleted records | Excluded from both sides. Tombstones are never hashed. `move_out` is not a record state |
| Hash | `SHA-256`, lowercase hex |
| Empty set | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` (a valid digest, not `null`) |
| Read view | Computed in the same view as the page it accompanies |
| Reconciliation | Compare after a **full** pull cycle; mismatch → targeted bootstrap of that entity only, pending operations preserved and re-applied when `base_version` still valid |
| Repeat mismatch | Second consecutive mismatch is **reported**, not looped; a third marks sync unhealthy for that entity until the next app start |
| Bootstrap after mismatch | Replaces that entity's rows for the visible scope, recomputes, compares once |

**Documents changed:** `api/SYNC_API_SPECIFICATION.md` (§4.6.2 mitigations 3–4, §4.6.1, §6.4, **new
§6.5**), `architecture/ARCHITECTURE_DECISIONS.md` D-27, `database/DATABASE_SCHEMA_SPECIFICATION.md`
§5.2.2, `synchronization/SYNC_WORKFLOWS.md` §7.1/§7.2, `synchronization/OFFLINE_DATA_MANAGEMENT.md`
§7.2, `synchronization/SYNCHRONIZATION_ARCHITECTURE.md` §4/§8,
`testing/ARCHITECTURE_VALIDATION_PLAN.md` §9 (`T-SYNC-20`, `T-SYNC-21` added).

### I.4 Correction 4 — device revocation (historical record)

> [!NOTE]
> **Historical Record — [SUPERSEDED]**: The text below records the state during the initial review pass when UQ-04 was unresolved. Subsequently, the project owner **APPROVED Option B** (`app_sessions` table for full immediate device and session revocation across all v1 and v2 endpoints), and Decision D-25 was accepted. See `UNRESOLVED_QUESTIONS.md` (UQ-04) and `ARCHITECTURE_DECISIONS.md` (D-25) for current authoritative policy.

**Discrepancy.** D-25, UQ-04, `SESSION_MANAGEMENT.md` §6 and `DATABASE_SCHEMA_SPECIFICATION.md` §5.1.1 discussed "revoking a
device" as if it were one effect. It is three: sync privilege, the authenticated session, and access
to already-downloaded local data.

**Resulting specification.** The same three-effect table now appears in all four places:

| # | Effect | Option (a) sync-only | Option (b) `app_sessions` |
|---|---|---|---|
| 1 | Sync privilege (`sync_devices.revoked_at` → `403 DEVICE_REVOKED`) | Revoked immediately | Revoked immediately |
| 2 | Authenticated session (PHP session file) | **Unaffected** until expiry/logout | Revoked immediately |
| 3 | Access to downloaded local data (Drift store) | Unaffected by the server; UQ-04 recommends re-authentication to view registered-mode data, cache retained encrypted | Same client policy |

The server can revoke 1 and (under b) 2; it can never revoke 3. **No option was selected.** D-25
remains `NEEDS APPROVAL`; UQ-04 remains `BLOCKING`. The pass added an explicit note in each place
that revocation must never be described as "logging the device out".

**Documents changed:** `architecture/ARCHITECTURE_DECISIONS.md` D-25,
`architecture/UNRESOLVED_QUESTIONS.md` UQ-04, `security/SESSION_MANAGEMENT.md` (new §6.2.1),
`database/DATABASE_SCHEMA_SPECIFICATION.md` §5.1.1.

### I.5 Correction 5 — retention is one recommendation (historical record)

> [!NOTE]
> **Historical Record — [SUPERSEDED]**: The text below records the initial 30-day batch retention proposal. The project owner subsequently updated `sync_batches` retention to **45 days**, change logs to **90 days**, and guest import audit records to **lifetime retention** (APPROVED policy). See `UNRESOLVED_QUESTIONS.md` (UQ-09).

**Discrepancy.** `DATABASE_DESIGN.md` §4.3 said `sync_batches` is "retained until its result is
superseded (UQ-09 applies to the change log, not to batches)"; `DATABASE_SCHEMA_SPECIFICATION.md`
§5.3 and `DATABASE_MIGRATION_STRATEGY.md` §7.5 said 30 days after completion; UQ-09's question did
not mention batches at all.

**Resulting specification.** One recommendation, stated identically in all three places:

| Data | Recommendation |
|---|---|
| `sync_change_log` | 90 days |
| `sync_tombstones` | Indefinite |
| `sync_batches` | **30 days after completion** |
| `guest_import_*` | Life of the account |

Every occurrence now says explicitly that these are **recommendations pending UQ-09, not approved
policy**, and that the pruner must not be implemented as though they were settled. UQ-09's question
and title were extended to cover completed batches.

**Documents changed:** `database/DATABASE_DESIGN.md` §4.3,
`database/DATABASE_SCHEMA_SPECIFICATION.md` §5.3, `architecture/UNRESOLVED_QUESTIONS.md` UQ-09.

### I.6 Correction 6 — the test inventory is measured, not estimated (historical record)

> [!NOTE]
> **Historical Record — [SUPERSEDED]**: The count of 188 test IDs below records the earlier intermediate measurement. It was subsequently expanded and finalized to **212 unique test cases** across 14 prefixes in `ARCHITECTURE_VALIDATION_PLAN.md` §15.5.

**Discrepancy.** The validation plan said "Total: 177". The readiness report's category table summed
to 184, because it counted the 12-step platform matrix as 12 cases **and** the five `T-WEB` IDs
separately. An earlier automated count produced 174 because the regex missed the letter-suffixed
`T-SYNC-C1…C3`.

**Resulting specification.** **188 unique `T-` IDs**, measured by extracting
`T-[A-Z]+(?:-[A-Z0-9]+)?-[A-Z]?\d+` and de-duplicating:

| Prefix | IDs | | Prefix | IDs |
|---|---:|---|---|---:|
| `T-MIG` | 17 | | `T-IDEM` | 10 |
| `T-API` | 16 | | `T-SEC` | 31 |
| `T-AUTH` | 14 | | `T-WEB` | 5 |
| `T-GUEST` | 13 | | `T-REG` | 11 |
| `T-OFF` | 10 | | `T-SET` | 8 |
| `T-SYNC` | 26 | | `T-LIST` | 4 |
| `T-CONF` | 17 | | **Total** | **188** |
| `T-MULTI` | 6 | | | |

**Overlap stated explicitly.** The 12-step smoke matrix is a per-platform checklist for
`T-WEB-01…05`, **not** twelve test cases, and must never be added to the total. The cursor cases
`T-SYNC-C1…C5` are inside the `T-SYNC` prefix (hence 26, not 21) and are **not** re-counted under
"settlement/list/cursor correctness". The correction pass added eleven genuinely new cases:
`T-SYNC-15…21`, `T-SYNC-C4`, `T-SYNC-C5`, `T-MIG-16`, `T-MIG-17`.

**Documents changed:** `testing/ARCHITECTURE_VALIDATION_PLAN.md` (**new §15.5**),
`WORK/PHASE_2_READINESS_REPORT.md` (§F.2, §G.2, appendix item 18),
`WORK/REQUIREMENTS_TRACEABILITY.md` §14, `README.md` Phase 2 index.

### I.7 Correction 7 — validation re-run

| Check | Method | Result |
|---|---|---|
| Relative Markdown links | Extracted every `](*.md)` and tested the resolved path | **0 broken** |
| Section cross-references | Extracted every `file.md §N`, associated each with the nearest preceding file mention, matched against the target's headings | **751 references checked; 0 unresolved.** Three further `§` references are same-document and resolve within their own file |
| Decision statuses | Parsed all 32 entries | **7 `ACCEPTED`, 19 `PROPOSED`, 6 `NEEDS APPROVAL`** — unchanged |
| UQ register | Parsed all 20 entries | **9 `BLOCKING`, 11 `OPEN`** — unchanged |
| Unique test-case IDs | Extracted and de-duplicated (§I.6) | **188** |
| Affected diagrams reviewed | Mermaid in `SYNC_WORKFLOWS.md` §6.2, `SYNCHRONIZATION_ARCHITECTURE.md` §6.2, `ENTITY_RELATIONSHIP_DIAGRAM.md` §7.1, `DATA_FLOW.md` §7 | All four updated to show the per-member tombstone, the explicit detach, and `move_out`; no diagram still shows a parent-tombstone-only group deletion |
| Affected test cases reviewed | `T-SYNC-08/09/10`, `T-SYNC-C1…C5`, `T-MIG-11…17` | `T-SYNC-10` aligned to the member tombstone; five cursor cases; two A-DB-4 spike cases |
| Source, schema, tests, migrations | `git status` | **Only `docs/` is modified or added** |

### I.8 What this pass did not change

| Untouched | Why |
|---|---|
| Any decision status | Only the owner may approve; UQ-20 stays withheld until this report is reviewed |
| Any UQ answer | UQ-04 stays `BLOCKING`; UQ-09 stays `OPEN`; no policy was selected |
| `schema.sql`, any `migration_v*.sql` | Documentation-only pass |
| Any PHP/JS/Flutter file | Documentation-only pass |
| The canonical test baseline (`433 / 105 / 538`) | No executable file changed, so the baseline is unchanged and was not re-run |
| The v1 contract | Group deletion, expense update, and cursor behaviour in v1 are untouched. `move_out` is a **v2 change-log** concept; the trigger writes it, but no v1 response changes |
| Laravel status | Still dormant; UQ-06 open |

**Residual verification, unchanged by this pass:** A-DB-3 (engine parity), **A-DB-4** (cross-table DML
in `trg_groups_bd`; `SET NULL` fires no trigger), A-WEB-1 (CanvasKit under the current CSP).

---

## Appendix — Phase 2 acceptance criteria (plan §11)

| # | Criterion | State |
|---|---|---|
| 1 | D-01…D-07 recorded `ACCEPTED` with context, rationale, consequences | **Met** |
| 2 | Every agent decision is `PROPOSED`/`NEEDS APPROVAL`, never `ACCEPTED` | **Met** |
| 3 | Target architecture names every component, responsibility, boundary | **Met** |
| 4 | All seven required diagrams exist and match the design | **Met** (plus three additional) |
| 5 | Flutter layering, packages, platform caveats documented with alternatives | **Met** |
| 6 | Coexistence defines one auth boundary and forbids duplicate business logic | **Met** |
| 7 | Every proposed schema change answers the ten required questions | **Met** |
| 8 | ER diagram distinguishes existing/added/unresolved structures | **Met** |
| 9 | Ownership defined for all twelve required entity groups | **Met** |
| 10 | Versioning and per-entity conflict policies specified | **Met** |
| 11 | Guest boundary and migration workflow fully specified | **Met** |
| 12 | Existing-account import specified and approved under Option C (interactive prompt, dry-run preview, two-tier duplicate detection) | **Met** (UQ-01, D-22) |
| 13 | Sync protocol covers initial, incremental, push, pull, retry, recovery, cursor | **Met** |
| 14 | Every proposed endpoint has all fifteen attributes | **Met** |
| 15 | Existing endpoints distinguished from proposed | **Met** |
| 16 | Security implications of offline, devices, migration, sync addressed | **Met** |
| 17 | Deployment evaluates same-origin, HTTPS, environments, backups | **Met** |
| 18 | Test scenarios include failure paths and acceptance criteria | **Met** (212 cases; §G.2, validation plan §15.5) |
| 19 | Every requirement has ID, origin, status, verification method | **Met** (164) |
| 20 | No unresolved decision presented as settled | **Met** |
| 21 | Cross-document consistency review completed | **Met** (§F.2) |
| 22 | No application source, schema, or migration file changed | **Met** (§H) |
| 23 | Project owner has reviewed and approved | **Met (UQ-20 approved on 2026-11-18)** |

---

## J. Correction report — final architecture consistency pass (C-01 … C-06)

This section documents the final consistency corrections C-01 through C-06 requested during the final Phase 2 review.

### J.1 Summary of Corrections

| Issue ID | Original Inconsistency | Affected Files | Correction Made | Verification Performed | Remaining Limitations |
|---|---|---|---|---|---|
| **C-01** | Contradiction between `since > watermark` returning `410` vs `watermark < since <= MAX(seq)` returning `200` with empty page | `api/SYNC_API_SPECIFICATION.md` §6.4, `synchronization/SYNC_WORKFLOWS.md` §7.3, `testing/ARCHITECTURE_VALIDATION_PLAN.md` §6.4 (`T-SYNC-C5`) | Formulated 10 canonical cursor rules. Defined mutually exclusive boundaries: `[oldest_retained_seq, watermark]` is valid (`200 OK`); any `since > watermark` is strictly `410 CURSOR_TOO_OLD` (full bootstrap). Server in-flight band (`watermark < seq <= max_allocated_seq`) is withheld from responses; `next_cursor` is always `<= watermark`. | Re-verified error tables, sync workflows, and T-SYNC-C5 test case. | Heuristic 2s lag relies on DB server clock stability; restored databases force full bootstrap by design. |
| **C-02** | Trigger logic `OLD.group_id <> NEW.group_id` evaluates to `UNKNOWN` in SQL when either is `NULL`, omitting `move_out` on group ↔ personal transitions | `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.6.4, `synchronization/SYNC_WORKFLOWS.md` §6.4 | Replaced with NULL-safe comparison `OLD.group_id IS NOT NULL AND NOT (OLD.group_id <=> NEW.group_id)`. Explicitly documented and mapped all 3 transitions (Group A → Personal, Group A → Group B, Personal → Group A). Zero payload emitted to old group. | Trigger SQL syntax inspected; scope visibility conditions verified. | Requires planned spike A-DB-4 to confirm cross-table DML inside `trg_groups_bd`. |
| **C-03** | Local Drift storage contains separate guest and registered partitions, but digest definition referred to "all local rows", causing false mismatches with guest data | `api/SYNC_API_SPECIFICATION.md` §6.5, `synchronization/OFFLINE_DATA_MANAGEMENT.md` §7.2, `architecture/ARCHITECTURE_DECISIONS.md` D-27 | Explicitly scoped client digest to server-confirmed records in the active registered account partition (`account_id = :user_uuid` and `sync_status = 'synced'`). Guest-local records and unacknowledged pending drafts are strictly excluded. | Verified canonical serialization, sorting, empty hash, and targeted bootstrap logic. | Transient offline edits do not cause mismatches; reconciliation evaluates only after full sync cycle. |
| **C-04** | D-27 2-second safety lag lacked a direct validation scenario for residual long transactions | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §9, §15.5, `WORK/REQUIREMENTS_TRACEABILITY.md` §14 | Added dedicated test case `T-SYNC-22` verifying: (1) uncommitted Txn A held > 2.0s; (2) committed Txn B delivered; (3) Txn A commits late; (4) subsequent pull skips Txn A; (5) canonical digest detects divergence; (6) targeted bootstrap recovers Txn A without loss/duplicates. Total test count updated to 189. | Regex extraction of `T-` IDs confirms exactly 189 unique cases across all prefixes. | 2s lag is a practical heuristic; digest reconciliation is the true mathematical safeguard. |
| **C-05** | Outstanding verification spikes were inconsistently identified (A-DB-4 sometimes described as verified or omitted from lists of 2 spikes) | `WORK/PHASE_2_READINESS_REPORT.md` (§0, §A.4, §G.3, §I.2, Sign-off block) | Consolidated all 3 verification spikes (A-DB-3, A-DB-4, A-WEB-1) into a single status table with explicit status values: all three are strictly **PLANNED** (not executed). Corrected §I.2 and sign-off block. | Full text grep confirmed all spike references distinguish Planned from Executed. | Spikes must be executed on disposable environments before Phase 3 code. |
| **C-06** | Technical synchronization mechanisms (compensating trigger, detaches, member tombstones) were in danger of being mistaken for settled business policies | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-07, UQ-11), `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.4.4, §7.3 | Reinforced clear boundary: technical triggers ensure sync cursor integrity and prevent data stranding, but business decisions (historical settlement destruction under UQ-07 and orphaned expense provenance under UQ-11) remain strictly `BLOCKING` pending owner decisions. | Verified UQ-07 and UQ-11 remain `BLOCKING` and D-26 remains `NEEDS APPROVAL`. | Owner decisions required before database migration M1 can be finalized. |

### J.2 Status of the Three Technical Verification Spikes

| Spike ID | Subject | Objective | Current Status | Prerequisite For |
|---|---|---|---|---|
| **A-DB-3** | Engine Parity (MySQL 8 vs MariaDB 10.4) | Verify trigger syntax, trigger execution order, `DATETIME(6)` microsecond precision, and JSON functions across MySQL 8 and MariaDB 10.4.32 on disposable databases. | **PLANNED** (Not executed) | Database migrations M1–M6 |
| **A-DB-4** | Group Deletion Trigger DML & Concurrency | Test cross-table `UPDATE` and `INSERT` statements inside `trg_groups_bd`, verify `ON DELETE SET NULL` non-logging behavior, and test concurrent group deletion under load for deadlocks. | **PLANNED** (Not executed) | Group deletion trigger deployment (`schema.sql` update) |
| **A-WEB-1** | Flutter Web CanvasKit & CSP Compatibility | Verify Flutter Web compilation with CanvasKit/WASM under current strict Content Security Policy (which lacks `'wasm-unsafe-eval'`). | **PLANNED** (Not executed) | Web production deployment gate |

### J.3 Correction report — final clarification pass (C-07, C-08)

Following the owner's review of Report 1, two additional consistency corrections were requested and applied before formal sign-off:

| Issue ID | Subject | Affected Files | Correction Made | Verification Performed |
|---|---|---|---|---|
| **C-07** | **UQ-16 Data Export Restriction** (Owner-Approved Requirement): CSV, JSON, and PDF export must NOT be available in guest mode; UI must show educational prompt. | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-16), `synchronization/GUEST_DATA_MIGRATION.md` (§3.2, §5.5), `architecture/FLUTTER_ARCHITECTURE.md` (lines 41, 297, 414), `architecture/SYSTEM_COMPONENTS.md` (line 270), `security/SECURITY_ARCHITECTURE.md` (§9.3, §10) | Removed all guest data export capabilities (CSV, JSON, PDF). Specified educational "Account Required to Export Data" prompt. Full export unlocked only post-registration and migration. Recorded as frozen owner requirement. | Verified across all 5 affected files; zero occurrences of guest export remain in the specifications. |
| **C-08** | **UQ-03 / UQ-04 Session and Revocation Coupling**: 24-hour absolute vs 30-day sliding session exposure reconciled with device revocation. | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-03, UQ-04), `security/SESSION_MANAGEMENT.md` (§4.2, §6.1, §6.2.1), `architecture/ARCHITECTURE_DECISIONS.md` (D-24, D-25), `database/DATABASE_SCHEMA_SPECIFICATION.md` (§5.1.1) | Documented actual access duration under Option A: 24h absolute under UQ-03a, but **up to 30 days of inactivity (or indefinitely if sliding)** under UQ-03b. Documented severe security implications of Option A on lost/stolen devices under sliding sessions. Formulated mandatory architectural requirement: adopting 30-day sliding sessions (UQ-03b) strictly requires database-backed `app_sessions` (UQ-04b / D-25b) to terminate all 40 v1 endpoints immediately upon revocation [SUPERSEDED: Option B subsequently approved by owner for UQ-04; Option A rejected]. | Re-verified 3-effect tables in all four documents; cross-references validated. |

### J.4 Correction report — final clarification pass on UQ-11, UQ-02, UQ-14, and UQ-01 (C-09 … C-12)

Following the owner's review of the updated briefing, four final architectural clarifications were requested and incorporated:

| Issue ID | Subject | Affected Files | Correction Made | Verification Performed |
|---|---|---|---|---|
| **C-09** | **UQ-11 Distinguishing Deliberate Conversions vs Detached Group Expenses**: Preserve historical collective financial provenance without forcing both into the same classification. | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-11), `database/DATA_OWNERSHIP.md` (§8), `database/DATABASE_SCHEMA_SPECIFICATION.md` (§5.4.4, §7.3), `architecture/ARCHITECTURE_DECISIONS.md` (D-26) | Explicitly distinguished user-initiated group-to-personal conversions (`type = 'personal'`, `group_id = NULL`) from expenses detached by group deletion (`type = 'group'`, `group_id = NULL`, `is_detached = 1`). Formulated domain-accurate database constraint permitting both. | Verified across all 4 affected documents; no coercion of detached expenses to personal. |
| **C-10** | **UQ-02 Account Deletion Lifecycle Specification**: Outstanding balances, personal data purge, unique replacement pseudonym, session/device revocation, and retention schedule. | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-02), `database/DATA_OWNERSHIP.md` (§9), `architecture/ARCHITECTURE_DECISIONS.md` (D-23) | Specified pre-flight balance audit (debts block deletion; credits require collection or explicit forfeiture [SUPERSEDED by C-20: credit forfeiture strictly prohibited; all positive credits and debits block deletion]; sole-admin roles transferred); immediate hard atomic delete of personal data; unique pseudonym attribution (`Former Member [UUID_PREFIX]`); immediate session/device termination; and explicitly documented that **indefinite retention is NOT approved** (open policy choice). | Verified across all 3 affected files; foreign key and balance integrity preserved. |
| **C-11** | **UQ-14 Platform Release Sequencing Reassessment**: Explanation of earlier draft and reassessment of Native-First rollout (Mobile & Desktop in Release 1, Web in Release 2). | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-14), `deployment/DEPLOYMENT_ARCHITECTURE.md` (§4.3, §4.4), `architecture/FLUTTER_ARCHITECTURE.md` | Documented why earlier draft placed Web ahead of Desktop (existing web user base and zero installer packaging). Reassessed native-first rollout: Mobile (Android/iOS) and Desktop (Windows/macOS/Linux) share the identical native SQLite engine via Drift FFI in Release 1 subject to platform verification gates. Web is staged for Release 2 gated by spike A-WEB-1 CSP and IndexedDB quotas, while legacy SPA continues serving web users with zero interruption. | Verified platform verification gates and alignment across deployment and architecture specs. |
| **C-12** | **UQ-01 Dry-Run Duplicate Detection Strengthening**: Monthly warnings alone are insufficient; two-tier duplicate check with mandatory user confirmation for ambiguous matches. | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-01), `synchronization/GUEST_DATA_MIGRATION.md` (§5.3, §7.4, §7.6), `architecture/ARCHITECTURE_DECISIONS.md` (D-22) | Explained why monthly budget warnings alone fail to protect individual expense/list duplicates. Defined two-tier duplicate detection: Tier 1 exact UUID de-duplication (`would_skip`), Tier 2 heuristic duplicate candidates matching date, amount, and note/category. Mandated interactive user confirmation (Import as New vs Skip) before committing batch. | Verified dry-run sequence diagram, contract rules, and preview specifications. |

---

### J.5 Final Project Owner Decision Consolidation (C-13 … C-19)

Following the project owner's final review and authoritative decision briefing for UQ-01 through UQ-19,
a comprehensive consolidation pass was executed across all 33 canonical architecture documents:

| Issue ID | Subject | Affected Files | Correction Made | Verification Performed |
|---|---|---|---|---|
| **C-13** | **Full Master Register Consolidation** (UQ-01..UQ-19) | `architecture/UNRESOLVED_QUESTIONS.md`, `architecture/ARCHITECTURE_DECISIONS.md`, `WORK/REQUIREMENTS_TRACEABILITY.md` | Consolidated all 19 substantive owner decisions into the registers. All 36 architecture decisions reconciled: 19 `ACCEPTED`, 17 `PROPOSED`, 0 `NEEDS APPROVAL`. All 20 UQs updated: 17 `APPROVED`, 1 `APPROVED WITH PROVISIONAL POLICY` (UQ-09), 1 `APPROVED — AUDIT REQUIRED` (UQ-10), 1 `NOT APPROVED` (UQ-20), 0 `BLOCKING`, 0 `OPEN`. | Zero unresolved or pending approval decisions. |
| **C-14** | **Security and Session Hardening** (UQ-03, UQ-04, UQ-17) | `security/SESSION_MANAGEMENT.md`, `security/SECURITY_ARCHITECTURE.md`, `security/ACCESS_CONTROL.md`, `api/AUTHENTICATION_AND_AUTHORIZATION.md`, `database/DATABASE_SCHEMA_SPECIFICATION.md` | Specified dual-tier session model: 7-day inactivity sliding session for web, 30-day inactivity remember-device credentials stored in hardware secure storage (KeyStore, Keychain, DPAPI, libsecret). Full revocation via `app_sessions` table terminates sync, v1/v2 web sessions, and remember credentials immediately. Added mandatory device registration endpoint `POST /api/v2/devices/register.php` (D-34). | Re-verified security threat models, access control matrices, and validation tests T-AUTH-15..20. |
| **C-15** | **Data Ownership and Archival Lifecycle** (UQ-02, UQ-07, UQ-11) | `database/DATA_OWNERSHIP.md`, `database/DATABASE_SCHEMA_SPECIFICATION.md`, `database/DATABASE_MIGRATION_STRATEGY.md`, `api/SYNC_API_SPECIFICATION.md` | Account deletion: balance pre-flight gate blocks users with outstanding debts, requires credit settlement/forfeiture; shared financial records preserved with **original username retained** and context badge `"This user deleted their account."`; personal data purged. Group archival: 4-state lifecycle (`ACTIVE` -> `ARCHIVE_PENDING` -> `ARCHIVED` -> `PERMANENTLY_ARCHIVED`), 3-month restoration window, optional permanent deletion after 6 months. Expense provenance: distinguished user-initiated conversions (`type='personal', group_id=NULL`) from group deletion detaches (`type='group', group_id=NULL, is_detached=1`). | Re-verified data ownership models, M1 schema DDL, and validation tests T-SET-09..12, T-REG-12. |
| **C-16** | **Offline Capabilities and Sync Controls** (UQ-08, UQ-05, UQ-12, UQ-18) | `synchronization/OFFLINE_DATA_MANAGEMENT.md`, `synchronization/SYNCHRONIZATION_ARCHITECTURE.md`, `synchronization/SYNC_WORKFLOWS.md`, `synchronization/CONFLICT_RESOLUTION.md`, `api/SYNC_API_SPECIFICATION.md` | Offline CRUD expanded to six entity types (personal expenses, group expenses, personal budgets, personal lists, group lists, list items); settlements, list checking/unchecking, and group membership mutations remain strictly online. Sync limits enforced: `api_sync` rate limit (120 req/min user, 240 burst device) and batch payload limits (max 100 records / 1MB payload per batch). Dedicated "Needs Attention" conflict UI with safe metadata auto-merge and zero financial auto-merge. Account-wide reminder ack via `POST /api/v2/reminders/ack.php`. Notifications excluded from offline sync store. | Re-verified offline data flows, conflict resolution matrices, and validation tests T-OFF-11..12, T-CONF-18, T-SYNC-23. |
| **C-17** | **Backend Coexistence and Test Preservation** (UQ-06, UQ-10, UQ-19) | `architecture/BACKEND_COEXISTENCE.md`, `architecture/TARGET_ARCHITECTURE.md`, `testing/ARCHITECTURE_VALIDATION_PLAN.md` | Retained procedural PHP 8 backend with shared domain helper layer (`api/domain/`); Laravel coexistence remains optional for future justified use (Model M-1). Retained server-side admin settlement endpoints (`settle.php`, `settle_all.php`) for strict compliance with `tests/run_tests.php` Section 17/27/28 tests while omitting them from Flutter/modern UI. Mandated two-way backward compatibility with legacy PHP/JS web interface (D-36). | Re-verified backend boundaries and legacy test preservation. |
| **C-18** | **Guest Account Boundary** (UQ-01, UQ-16) | `synchronization/GUEST_DATA_MIGRATION.md`, `architecture/FLUTTER_ARCHITECTURE.md`, `security/SECURITY_ARCHITECTURE.md` | Existing-account guest import Option C approved: dry run preview with two-tier duplicate check (Tier 1 exact UUID auto-skip, Tier 2 heuristic candidate interactive confirmation). Skipping import preserves local guest partition `account_id = 'guest'` on device with option to import later from Settings. Guest feature package: theme customization disabled; data export disabled with educational prompt. | Re-verified guest migration workflows, duplicate detection logic, and tests T-GUEST-14..16. |
| **C-19** | **Architecture Validation Test Expansion** | `testing/ARCHITECTURE_VALIDATION_PLAN.md`, `WORK/REQUIREMENTS_TRACEABILITY.md` | Added 23 new validation scenarios across 8 prefixes, bringing the total measured unique test inventory from 189 to **212** unique `T-` cases. Aligned requirements traceability matrix: all 164 requirements are now fully `SPECIFIED` or `PRESERVED` (0 `OPEN`, 0 `NOT ADDRESSED`). Recorded **UQ-20 as explicitly NOT APPROVED**. | Automated regex extraction confirms 212 unique test IDs and 0 broken links. |

---

### J.6 Final Governance and Decision-Consistency Correction Pass (C-20 … C-25)

Following the project owner's review of the final consolidation report, a narrowly scoped governance
and consistency correction pass was performed:

| Issue ID | Subject | Affected Files | Correction Made | Verification Performed |
|---|---|---|---|---|
| **C-20** | **UQ-02 Credit Forfeiture Removal & Balance Gate** | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-02), `database/DATA_OWNERSHIP.md` (§9), `security/SECURITY_ARCHITECTURE.md` (§9), `testing/ARCHITECTURE_VALIDATION_PLAN.md` (T-AUTH-16) | Removed unapproved financial forfeiture of credits. Account deletion is strictly blocked if the user has ANY unsettled group balance (debit OR credit). Defined required sole administrator transfer procedure (`POST /api/v2/groups/transfer_admin.php`); deleting a financially active group as a substitute is prohibited. | Verified zero occurrences of credit forfeiture remain in approved policies; T-AUTH-16 updated to assert blocking of positive credit deletion. |
| **C-21** | **UQ-02 & UQ-07 Historical Retention vs 6-Month Group Deletion Conflict** | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-02, UQ-07), `database/DATA_OWNERSHIP.md` (§8, §9), `architecture/ARCHITECTURE_DECISIONS.md` (D-23, D-26) | Defined nine distinct data categories (container, memberships, lists, expenses, settlements, confirmations, receipts, usernames, tombstones). Explicitly analyzed whether permanent group deletion can erase historical records of deleted users and reconciled with ledger immutability. Marked as `NEEDS CLARIFICATION` and presented three exact decision options. | Verified consistent categorization across all four documents; cross-policy contradiction clearly isolated. |
| **C-22** | **Architecture Decision ID Integrity Audit & Restoration** | `architecture/ARCHITECTURE_DECISIONS.md`, `WORK/REQUIREMENTS_TRACEABILITY.md`, `testing/ARCHITECTURE_VALIDATION_PLAN.md`, all docs | Audited D-01 through D-38 against git history and original register. Restored original mappings: D-23 (Account deletion), D-24 (Session lifetime), D-26 (Group archival & provenance), D-31 (Legacy PHP UI unmodified), D-32 (Bootstrap.php dead). Established genuinely new IDs: D-37 (Platform release sequencing) and D-38 (Guest export prohibition). Updated all requirements traces. | Register verified: 38 total decisions (21 ACCEPTED, 17 PROPOSED, 0 NEEDS APPROVAL); 0 renumbered or repurposed IDs. |
| **C-23** | **UQ-05 Rate Limit vs Batch Limit Distinction** | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-05), `architecture/ARCHITECTURE_DECISIONS.md` (D-28), `api/SYNC_API_SPECIFICATION.md` (§3) | Clearly distinguished owner-approved HTTP request limits (120 req/min user, 240 burst device) from batch payload limits (100 records / 1 MB payload). Classified batch limits as `PROPOSED` initial configuration defaults, configurable via environment and subject to Phase 5 load testing. | Error code HTTP 413 and config variables aligned across API and architecture specs. |
| **C-24** | **UQ-08 Online-Only Boundaries & UQ-09 Tombstone Retention** | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-08, UQ-09), `synchronization/OFFLINE_DATA_MANAGEMENT.md` (§2, §4), `architecture/ARCHITECTURE_DECISIONS.md` (D-16, D-17) | Preserved settlement confirmations and shopping-list check/uncheck as owner-approved online-only operations. Documented group membership mutations as a proposed online-only security restriction with rationale. Confirmed indefinite tombstone retention as provisional policy; removed "minimum 365 days". | Validation plan and synchronization workflows re-verified. |
| **C-25** | **UQ-10 Full Dependency Audit & UQ-18 Platform Acknowledgement Semantics** | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-10, UQ-18), `api/API_CONTRACT_SPECIFICATION.md` (§4), `api/SYNC_API_SPECIFICATION.md` (§5) | Delivered full dependency audit of `settle.php` and `settle_all.php`: 0 references in legacy frontend JS, heavy reliance in `run_tests.php` (Sec 17, 27, 28); classified as administrative-only and redundant, retained on server for test baseline compliance, omitted from modern UI [SUPERSEDED by UQ-10 Option B: project owner approved deprecating both endpoints with HTTP 410 Gone; post-UQ-20 test refactoring planned for both run_tests.php and penetration_test.php]. Detailed 6-stage notification lifecycle (generated, queued, provider accepted, device delivery, app ack, user interaction) and established honest platform OS delivery constraints. | Audit findings complete; platform constraints explicit; account-wide 24h reset preserved. |

---

### J.7 Owner Clarification Incorporation and UQ Reconciliation (C-26 … C-29)

Following the project owner's feedback on the final consolidation pass, an owner clarification incorporation and UQ reconciliation pass was performed:

| Issue ID | Subject | Affected Files | Correction Made | Verification Performed |
|---|---|---|---|---|
| **C-26** | **UQ-02 & UQ-07 Data Lifecycle Reconciliation** | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-02, UQ-07), `database/DATA_OWNERSHIP.md` (§8, §9), `architecture/ARCHITECTURE_DECISIONS.md` (D-23, D-26) | Incorporated owner's authoritative clarification: historical group records and deleted users' original usernames are preserved throughout normal operation and group archival. Once a group has been archived for 6 full months, an authorized group administrator may independently request permanent deletion of the group and all its records. Transitioned D-23 and D-26 to `ACCEPTED`. | Cross-policy conflict resolved; balance gate verified (debit & credit block deletion; forfeiture strictly prohibited; sole admin role transfer required). |
| **C-27** | **Archived Group Permanent-Deletion Workflow Specification** | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-07), `database/DATA_OWNERSHIP.md` (§8.4) | Specified complete 5-part permanent deletion workflow: (1) 6-month archival eligibility gate; (2) authorized initiator (admin-at-archival; no member fallback); (3) double explicit confirmation with countdown; (4) atomic deletion across all 9 data categories; (5) client sync tombstones (`is_tombstone = 1`) with cache purge. Left in specification status; not implemented. | Verified 9 data categories correctly mapped and cascade deletion rules defined. |
| **C-28** | **UQ-10 Administrative Settlement Operations Audit Briefing** | `architecture/UNRESOLVED_QUESTIONS.md` (UQ-10) | Completed and structured formal decision brief for UQ-10: detailed behavioral comparison of `settle.php`/`settle_all.php` with normal 3-step settlement workflow, documented 0 calls in legacy frontend JS, confirmed test suite dependencies in Sections 17, 27, 28 of `tests/run_tests.php`, and presented Option A (server retention, UI omission) vs Option B (formal deprecation) for owner disposition. | Status set to `AUDIT COMPLETED — PENDING OWNER DISPOSITION`. Legacy endpoints left unmodified. |
| **C-29** | **UQ Status Re-Classification and Documentation Baseline Synchronization** | `architecture/UNRESOLVED_QUESTIONS.md`, `WORK/PHASE_2_READINESS_REPORT.md`, `WORK/REQUIREMENTS_TRACEABILITY.md`, `docs/README.md` | Synchronized all documentation metrics to owner-aligned totals: exactly 15 `APPROVED`, 3 `APPROVED (PROVISIONAL)` (UQ-05, UQ-08, UQ-09), 1 `AUDIT COMPLETED — PENDING OWNER DISPOSITION` (UQ-10), 1 `NOT APPROVED` (UQ-20); 38 decisions (21 `ACCEPTED`, 17 `PROPOSED`, 0 `NEEDS APPROVAL`); 164 requirements (164 `SPECIFIED`/`PRESERVED`, 0 `PARTIAL`, 0 `OPEN`, 0 `NOT ADDRESSED`). Resolved AA-11 trace to D-23. | Full validation script re-run: 0 broken links, 0 unapproved forfeiture occurrences, 0 unclosed mermaid blocks. |

---

### J.8 UQ-10 Administrative Settlement Operations Deprecation (Option B Approved) & Dependency Verification

Following the project owner's instruction, Option B (deprecate and remove `settle.php` and `settle_all.php`)
was approved subject to final dependency verification, and the following verification and documentation actions were completed:

| Step | Subject | Verification Evidence & Actions Taken |
|---|---|---|
| **1** | **Legacy UI & Integration Dependency Verification** | Comprehensive AST and regex search across all frontend code (`public/assets/js/app.js`, `helpers.js`, `lists.js`) and page templates (`pages/*.php`, `public/*.php`) confirmed **zero references** to `settle.php` or `settle_all.php`. No external integrations, webhooks, or secondary clients exist that call these endpoints. The only callers in the repository are test files (`tests/run_tests.php` Sections 17, 27, 28 and `scripts/pentest/penetration_test.php`). |
| **2** | **Normal Settlement Workflow Coverage Confirmation** | Confirmed that the collaborative 3-step workflow (`calculate.php` → member `confirm.php` → atomic ledger closure upon consensus with `SELECT ... FOR UPDATE` locking) covers 100% of legitimate settlement operations. Historical `settle.php` and `settle_all.php` were unilateral administrative shortcuts that bypassed member consensus. Their elimination strengthens financial ledger integrity. |
| **3** | **UQ-10 Decision Record & Register Update** | Updated `architecture/UNRESOLVED_QUESTIONS.md` (UQ-10) to `APPROVED`. Synchronized UQ register metrics across all documentation to exactly **16 `APPROVED`**, **3 `APPROVED (PROVISIONAL)`**, and **1 `NOT APPROVED`** (UQ-20). |
| **4** | **API Specification Contract Update** | Updated `api/API_CONTRACT_SPECIFICATION.md` (§2.1, §2.2, §8, §11) to formally classify `settle.php` and `settle_all.php` as `DEPRECATED`, specifying an explicit HTTP `410 Gone` contract (`{"ok": false, "error": "Endpoint deprecated. Use collaborative settlement workflow (calculate.php -> confirm.php)."}`). |
| **5** | **Validation Plan Test Specification Update** | Updated `testing/ARCHITECTURE_VALIDATION_PLAN.md` (§3.1) test cases `T-AUTH-10` and `T-AUTH-11` to assert HTTP `410 Gone` for all callers (admin and non-admin). Aligned `security/ACCESS_CONTROL.md` (§4, §4.1, §10) and `WORK/REQUIREMENTS_TRACEABILITY.md` (PR-07). |
| **6** | **Implementation Boundary Enforcement** | Established clear boundary: **Actual PHP endpoint changes (`api/settlements/settle.php`, `settle_all.php`) and executable test modifications (`tests/run_tests.php` Section 17) remain strictly on hold until formal Phase 2 sign-off (UQ-20).** |

---

### J.9 Resolution of Final Audit Findings and Owner Decision Consolidation (F-AR-01, F-AR-02, F-GATE-01, F-VAL-01, F-VAL-02)

Following the project owner's review of the focused audit, all five audit findings were resolved and final policy decisions incorporated:

| Finding / Area | Subject | Status | Resolution Action Taken |
|---|---|---|---|
| **F-AR-01** | **HTTP 410 Narrow Exception to Frozen v1 Contract** | **RESOLVED** | Explicitly documented in `ARCHITECTURE_DECISIONS.md` (D-10, D-31) and `API_CONTRACT_SPECIFICATION.md` (§2.1, §8, §11) that deprecating `settle.php` and `settle_all.php` to return HTTP `410 Gone` is an owner-approved, narrow exception to the frozen v1 contract (D-31, PR-23). |
| **F-AR-02** | **Test File Inclusion in Post-UQ-20 Implementation Planning** | **RESOLVED** | Explicitly documented across `ARCHITECTURE_DECISIONS.md` (D-10, D-31), `UNRESOLVED_QUESTIONS.md` (UQ-10), `API_CONTRACT_SPECIFICATION.md`, and `ARCHITECTURE_VALIDATION_PLAN.md` (§3.1, §6.1) that post-UQ-20 test harness refactoring must cover **both** test suites: (1) `tests/run_tests.php` Section 17, and (2) `scripts/pentest/penetration_test.php` (test cases S07–S11). |
| **F-GATE-01** | **Permanent Group Deletion Initiator Wording** | **RESOLVED** | Removed the unapproved phrase `or last remaining member` from §J.7 row C-27. Reaffirmed in `DATA_OWNERSHIP.md` §8.4, `UNRESOLVED_QUESTIONS.md` UQ-07, and `ARCHITECTURE_DECISIONS.md` D-26 that **only a verified group administrator (`role = 'admin'`)** can initiate permanent deletion. Regular members are rejected with HTTP 403; strictly no member fallback. |
| **F-VAL-01** | **Validation Plan Unique Test Count** | **RESOLVED** | Updated Appendix Criterion 18 from `188 cases` to the authoritative, verified count of **212 unique test cases** (`ARCHITECTURE_VALIDATION_PLAN.md` §15.5). |
| **F-VAL-02** | **UQ-01 Resolution Status in Acceptance Criteria** | **RESOLVED** | Updated Appendix Criterion 12 from "documented as unresolved with options" to **Met (UQ-01 Option C approved)**. |
| **UQ-02** | **Sole Group Administrator Succession Choices** | **RESOLVED** | Specified two owner-approved choices before account deletion: (1) manual successor designation via `POST /api/v2/groups/transfer_admin.php`, OR (2) automatic transfer of the admin role to the earliest-joined active member (`joined_at ASC`). |
| **UQ-07** | **Reversible vs Irreversible Archival Lifecycle** | **RESOLVED** | Confirmed lifecycle distinction: `ARCHIVED` remains reversible (within 3 calendar months); `PERMANENTLY_ARCHIVED` cannot be reversed. Permanent deletion becomes eligible six full calendar months after archival. |
| **UQ-08 & D-17** | **Online-Only Group Membership Mutations** | **RESOLVED** | Adding/removing members, group creation, deletion, join via code, leaving, and removing members are owner-approved strictly online-only operations. Transitioned D-17 to `ACCEPTED` and UQ-08 to `APPROVED`. |
| **UQ-09** | **Synchronization Data Retention Schedules** | **RESOLVED** | Approved retention schedules: 90 days change logs; tombstones have no fixed expiry with future safe GC design (based on sync acknowledgements, device revocation, forced resync); completed sync batches updated to **45 days** (from 30 days); lifetime guest audit. |
| **UQ-18** | **Measurable Definition of Reminder Receipt** | **RESOLVED** | Confirmed that explicit app-level acknowledgement (`POST /api/v2/reminders/ack.php`) is the owner-approved measurable definition of successful receipt. |
| **Test Baseline** | **Inherited Baseline Policy for 105 Failures** | **RESOLVED** | Explicitly documented in §0, §G.1, §G.3, and `ARCHITECTURE_VALIDATION_PLAN.md` §1.3 that the 105 failures in `tests/run_tests.php` represent the inherited Phase 1 baseline (`docs/TEST_BASELINE.md`), to be investigated before implementation, rather than making their resolution a prerequisite for Phase 2 sign-off. |

---

## Sign-off block

| Role | Name | Decision | Date |
|---|---|---|---|
| Author (agent) | — | Submitted for review (Final Audit Findings Resolved & Owner Decisions Applied) | 2026-11-18 |
| Project owner | | ☑ Approved ☐ Approved with changes ☐ Deferred (UQ-20 APPROVED) | 2026-11-18 |

**UQ-20 STATUS AND APPROVAL BOUNDARY:**

Formal Phase 2 approval has been granted by the project owner (**UQ-20 APPROVED on 2026-11-18**).

**Scope of UQ-20 Formal Approval:**
Formal sign-off authorizes:
1. Formal acceptance of the Phase 2 architecture and technical documentation baseline.
2. Committing and tagging the accepted documentation baseline in Git (`phase2-architecture-approved`).
3. Execution of the three planned verification spikes (`A-DB-3`, `A-DB-4`, `A-WEB-1`), strictly within their documented scopes on isolated/disposable environments.

**Explicit Exclusions — What UQ-20 Does NOT Authorize:**
Formal Phase 2 sign-off does **NOT** authorize:
1. Production application implementation (PHP endpoints, services, or Flutter client code).
2. Database schema changes, DDL modifications, or database migrations (`schema.sql`, `migration_v*.sql`).
3. Production deployment or infrastructure rollouts.
Each of these activities remains strictly gated and requires separate, explicit authorization from the project owner following Phase 3 spike evaluation.

---

## Cross-references

| Topic | Document |
|---|---|
| Plan, scope, sequence | [`PHASE_2_DESIGN_PLAN.md`](PHASE_2_DESIGN_PLAN.md) |
| Requirement coverage | [`REQUIREMENTS_TRACEABILITY.md`](REQUIREMENTS_TRACEABILITY.md) |
| Decisions | [`../architecture/ARCHITECTURE_DECISIONS.md`](../architecture/ARCHITECTURE_DECISIONS.md) |
| Open questions | [`../architecture/UNRESOLVED_QUESTIONS.md`](../architecture/UNRESOLVED_QUESTIONS.md) |
| Test obligations | [`../testing/ARCHITECTURE_VALIDATION_PLAN.md`](../testing/ARCHITECTURE_VALIDATION_PLAN.md) |
| Deployment and CSP | [`../deployment/DEPLOYMENT_ARCHITECTURE.md`](../deployment/DEPLOYMENT_ARCHITECTURE.md) |
| Baseline | [`../TEST_BASELINE.md`](../TEST_BASELINE.md) |
| Phase 1 audit index | [`../README.md`](../README.md) |
