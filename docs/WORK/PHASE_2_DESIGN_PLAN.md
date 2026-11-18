# Phase 2 — Target Architecture and Technical Design Plan

| Field | Value |
|---|---|
| Phase | 2 — Target Architecture and Technical Design |
| Status | **DRAFT — awaiting project-owner review** |
| Date prepared | 2026-11-15 |
| Repository | `C:\xampp\htdocs\ExpMgWEB` (GitHub: `AbhayRjvanshi/ExpMgWEB`) |
| Branch at start | `main` |
| HEAD at start | `ec0e692` — "Docs: correct the logout forced-logout mechanism (SameSite=Lax)" |
| Last code-touching commit | `66dedc1` — splash `data-redirect` fix (verified via `git log -1 -- api config pages public schema.sql tests/run_tests.php`) |
| Working tree at start | clean |
| Phase 1 baseline | `433 passed / 105 failed / 538 tests` (`docs/TEST_BASELINE.md`) |
| Implementation authorised | **No.** Documentation only. |

This plan is the entry point for Phase 2. It records scope, confirmed decisions, work
packages, dependencies, risks, and the questions that need project-owner answers before
implementation can begin. It does not itself specify the architecture — each work package
produces its own document, and those documents are the specification.

---

## 1. Scope

### 1.1 In scope

Producing an implementation-ready, internally consistent architecture specification for:

1. A Flutter client targeting Android, iOS, Web, Windows, macOS, and Linux.
2. Continued operation of the existing procedural PHP backend (40 HTTP endpoints) during and
   after the migration.
3. Selective, justified Laravel adoption without creating a second implementation of any
   business rule.
4. A future database schema supporting record ownership, versioning, deletion propagation,
   guest-to-registered migration, and multi-device offline synchronisation.
5. Guest mode and guest-to-registered data migration.
6. Registered-user multi-device synchronisation, including offline writes and conflict
   resolution.
7. Authentication, authorisation, security, deployment, and testing specifications for the
   target system.

### 1.2 Out of scope (hard boundaries for Phase 2)

| Not permitted | Enforcement |
|---|---|
| Creating the Flutter project or installing packages | No `flutter create`, no `pubspec.yaml`, no `pub get` |
| Modifying any PHP source | `api/`, `config/`, `pages/`, `public/` untouched |
| Introducing Laravel into the repository | No `composer.json`, no `vendor/` |
| Modifying `schema.sql` or any `migration_v*.sql` | Schema changes appear only as specification |
| Creating executable SQL migrations | Migration SQL in documents is illustrative, non-executable |
| Altering the live database | No `ALTER`, no `INSERT`, no `UPDATE` |
| Implementing synchronisation | Protocol specified, not built |
| Adding product features | Every feature traces to `FEATURE_INVENTORY.md` |
| Creating a Phase 2 completion tag | Tag requires owner approval |

### 1.3 Change surface of this phase

Only files under `docs/` are added or modified. See §9 for the file manifest.

---

## 2. Objectives

| ID | Objective | Success measure |
|---|---|---|
| O-1 | Freeze the target architecture | A developer can name every component, its responsibility, and its boundary |
| O-2 | Make the database sync-capable **by design** | Every sync requirement maps to a named column, table, index, or transaction rule |
| O-3 | Preserve all existing behaviour | Every `FEATURE_INVENTORY.md` row has a preservation statement and a test scenario |
| O-4 | Remove guesswork from guest migration | A developer can implement migration from the document without inventing a merge policy |
| O-5 | Remove guesswork from conflict handling | Each entity has a named policy with a stated business justification |
| O-6 | Keep one authorisation boundary | No component trusts a client-supplied identity |
| O-7 | Surface every unresolved decision | Nothing architectural is silently assumed |
| O-8 | Remain implementable on the current stack | No infrastructure requirement that XAMPP/LAMP cannot satisfy |

---

## 3. Confirmed architecture decisions (project owner)

These are **requirements**, recorded as `ACCEPTED` in
[`../architecture/ARCHITECTURE_DECISIONS.md`](../architecture/ARCHITECTURE_DECISIONS.md). They are
not open for reinterpretation.

| ID | Decision |
|---|---|
| D-01 | Flutter + Dart is the new application frontend; six target platforms; platform support must be verified, not assumed |
| D-02 | Retain the existing PHP backend and MySQL database; MySQL remains the central store for registered-user data |
| D-03 | Laravel is optional and selective; procedural PHP stays where suitable; no whole-backend rewrite; no duplicate business logic |
| D-04 | Guest mode is limited and local-only; registration becomes mandatory at the registered-feature boundary; guest data migrates on account creation and is verified before local deletion |
| D-05 | Multi-device synchronisation is a first-class capability supporting offline create/modify/delete and conflict resolution |
| D-06 | Record versioning with optimistic concurrency; timestamps are for audit/ordering, never sole proof of non-concurrency |
| D-07 | Documentation-first; `docs/` is the architectural source of truth; no implementation before review |

Decisions the agent makes during Phase 2 are recorded as `PROPOSED` or `NEEDS APPROVAL`. They are
never presented as owner-approved.

---

## 4. Repository and requirements review (Stage 2A findings)

### 4.1 What was verified against source

| Claim | Verification | Result |
|---|---|---|
| 40 HTTP endpoints + 1 CLI worker | File census under `api/` | Confirmed |
| 14 tables, `schema.sql` is source of truth | `schema.sql` read in full | Confirmed |
| No CORS configuration exists | `grep -i "access-control\|cors"` across `*.php` | **Zero matches** — confirmed |
| Session cookie is `SameSite=Lax`, `HttpOnly`, `path=/` | `config/session.php:44-51` | Confirmed |
| No JSON session/CSRF bootstrap endpoint | Only `public/index.php:25` and `pages/login.php:25` call `getCsrfToken()` | **Confirmed gap** |
| All primary keys are `AUTO_INCREMENT` integers | `schema.sql` | Confirmed |
| `lists`, `list_items`, `settlements`, confirmations lack `updated_at` | `schema.sql` | Confirmed |
| No soft deletes, no tombstones, no change log | `schema.sql`, `DATABASE_AUDIT.md` §6 | Confirmed |
| `updated_at` has second granularity | `TIMESTAMP` defaults | Confirmed |
| Settlement finalisation deletes confirmation rows and inserts settlements | `api/settlements/confirm.php:119-140+` | Confirmed |
| Group deletion cascades | `api/groups/delete.php:63` + FK definitions | Confirmed, with consequences in §4.3 |
| No account-deletion feature exists | No endpoint, no UI | Confirmed |
| Idempotency is response-level, 900 s TTL, `$_POST`-bound | `config/idempotency.php` | Confirmed |

### 4.2 Source findings that materially change the design

These were **not** fully captured in Phase 1 and are load-bearing for Phase 2. They are recorded
as new debt entries and referenced throughout the design.

| ID | Finding | Evidence | Design impact |
|---|---|---|---|
| **F-01** | There is no machine-readable session bootstrap. A client cannot obtain a CSRF token except by loading a PHP HTML page (`pages/login.php`, `public/index.php`) or reading the `csrf_token` cookie those pages set. | `pages/login.php:25`, `public/index.php:25`; no `api/session.php` exists | Flutter needs a bootstrap endpoint. Specified as a **new proposed endpoint** (§WP-9). Until it exists, native clients must scrape HTML or inspect the cookie jar. |
| **F-02** | Deleting a group **destroys its settlement history** (`settlements.group_id ON DELETE CASCADE`) and **orphans its expenses** (`expenses.group_id ON DELETE SET NULL`, leaving `type='group'` with `group_id=NULL`). | `schema.sql:122,234`; `api/groups/delete.php` issues a bare `DELETE FROM groups` | A group delete is an irreversible financial-data loss event. Sync must propagate it as a parent tombstone; the client purges the group. Remediation is **NEEDS APPROVAL** (UQ-11). |
| **F-03** | MySQL/InnoDB **cascade actions do not fire triggers**. | MySQL documented behaviour | Trigger-based change capture cannot see cascade-deleted children. The protocol compensates with parent tombstones + client purge rules (§WP-8). |
| **F-04** | Deleting a user cascades away every expense they recorded (`expenses.user_id`), every settlement they participated in, and every list they created — including **group** data owned by other members. | `schema.sql:120,234-237,154` | Account deletion cannot be a bare `DELETE FROM users` in a multi-user financial app. **NEEDS APPROVAL** (UQ-12). |
| **F-05** | `settlement_confirmations` is keyed `(group_id, user_id)` and counts only rows whose `period_end` equals the freshly computed period end. | `schema.sql:255`; `api/settlements/confirm.php:115-137` | Confirmation validity depends on server-computed state at the moment of the call. Offline confirmation is unsound → **online-only** (D-17). |
| **F-06** | Money is `DECIMAL(12,2)` but JSON responses emit a mixture of strings and floats (`"24.50"` vs `24.5`). | `API_CONTRACT.md` §10.1, §9.4 | Sync payloads must fix one representation. Decimal strings on the wire, integer cents in Dart (D-21). |
| **F-07** | `expenses/list.php` returns server-computed `can_edit` and `settled`; these are the only authority for UI affordances. | `API_CONTRACT.md` §9.2 | Offline UI cannot recompute them safely. The client must treat them as advisory until revalidated. |
| **F-08** | The legacy rate limiter is per-user 120/min and per-IP 240/min, applied to **every** authenticated request. | `api/middleware/auth.php:117-193` | A chatty sync client will trip this. Sync needs its own bucket (D-27 / UQ-14). |
| **F-09** | `csrf_token` cookie is `HttpOnly=false` by design and is rotated on login and every 12 h. | `api/helpers/csrf.php:29-51` | Native clients can read the token from the cookie jar; the value must be re-read after login and after any 403. |

### 4.3 Preservation obligations carried from Phase 1

The following are non-negotiable and appear as acceptance criteria in
[`../testing/ARCHITECTURE_VALIDATION_PLAN.md`](../testing/ARCHITECTURE_VALIDATION_PLAN.md):

1. Settlement maths uses `paid_by`; normal aggregates exclude `is_post_settlement = 1`.
2. A group expense dated on or before the group's latest `period_end` is rejected on
   create/update/delete/price.
3. A period closes only when every active member has a confirmation row for the current period.
4. `0.00` marker settlements remain valid and advance the lock.
5. Late expenses use the post-settlement flow, never the normal one.
6. `settle.php` / `settle_all.php` remain admin-only. [SUPERSEDED by UQ-10 Option B: settle.php and settle_all.php deprecated with HTTP 410 Gone]
7. Personal expenses are owner-only; group expenses are admin-only for edit/delete.
8. A priced list item produces exactly one linked expense; uncheck deletes by exact `expense_id`
   within 600 s.
9. Group check returns `needs_confirm` and requires `paid_by`.
10. Max 5 groups per user; max 10 members per group.
11. Raw text stored; escaping at render time only.
12. Success is determined by `ok`, not by HTTP 200.
13. `logout.php` keeps its current (unguarded) behaviour until an approved change exists.

### 4.4 Contradictions and gaps found in the Phase 1 set

| # | Observation | Resolution in Phase 2 |
|---|---|---|
| 1 | Phase 1 §5 of `MIGRATION_PRESERVATION_PLAN.md` says shared data is "online-first" and that sync "requires its own design phase". Phase 2 **is** that phase, but D-05 now requires offline writes. | Resolved by D-05: offline writes are authorised for a defined subset of entities. The preservation plan's "online-first" statement is superseded **for the Flutter client only**; the legacy PHP UI stays online-only. Recorded as a supersession note in `ARCHITECTURE_DECISIONS.md`. |
| 2 | `DATABASE_AUDIT.md` §7.3 says guest mode "must never attempt to merge into the server without a future, explicit import design". | That design is `synchronization/GUEST_DATA_MIGRATION.md`. |
| 3 | Phase 1 does not define what happens to a group's expenses when the group is deleted. | New finding F-02; remediation deferred to UQ-11. |
| 4 | Phase 1 assumes the Flutter client can authenticate with a cookie session, but does not examine how a client obtains a CSRF token. | New finding F-01; bootstrap endpoint specified. |
| 5 | Phase 1 states "no CORS configuration; do not call the API from another origin" but does not evaluate Flutter Web. | Evaluated in `deployment/DEPLOYMENT_ARCHITECTURE.md`; same-origin is the baseline (D-08). |

---

## 5. Work packages

Each package has one authoritative document. Overlapping topics are written once and
cross-referenced, never duplicated.

| WP | Title | Authoritative deliverable(s) | Depends on | Status |
|---|---|---|---|---|
| WP-1 | Target system architecture | `architecture/TARGET_ARCHITECTURE.md`, `SYSTEM_COMPONENTS.md`, `DATA_FLOW.md` | WP-0 | Complete |
| WP-2 | Flutter application architecture | `architecture/FLUTTER_ARCHITECTURE.md`, `architecture/TECHNOLOGY_STACK.md` | WP-1 | Complete |
| WP-3 | Backend architecture and PHP/Laravel coexistence | `architecture/BACKEND_COEXISTENCE.md` | WP-1 | Complete |
| WP-4 | Database architecture and schema design | `database/DATABASE_DESIGN.md`, `DATABASE_SCHEMA_SPECIFICATION.md`, `ENTITY_RELATIONSHIP_DIAGRAM.md` | WP-1 | Complete |
| WP-5 | Record ownership and access control | `database/DATA_OWNERSHIP.md`, `security/ACCESS_CONTROL.md` | WP-4 | Complete |
| WP-6 | Authentication and session architecture | `api/AUTHENTICATION_AND_AUTHORIZATION.md`, `security/SESSION_MANAGEMENT.md` | WP-1, WP-4 | Complete |
| WP-7 | Guest-to-registered data migration | `synchronization/GUEST_DATA_MIGRATION.md` | WP-4, WP-6, WP-8 | Complete |
| WP-8 | Offline synchronisation architecture | `synchronization/SYNCHRONIZATION_ARCHITECTURE.md`, `OFFLINE_DATA_MANAGEMENT.md`, `SYNC_WORKFLOWS.md` | WP-4, WP-6 | Complete |
| WP-9 | API architecture and contracts | `api/API_ARCHITECTURE.md`, `API_CONTRACT_SPECIFICATION.md`, `SYNC_API_SPECIFICATION.md`, `API_VERSIONING.md`, `API_ERROR_HANDLING.md` | WP-4, WP-6, WP-8 | Complete |
| WP-10 | Security architecture | `security/SECURITY_ARCHITECTURE.md`, `database/VERSIONING_AND_CONFLICT_RESOLUTION.md` (policies) | WP-4…WP-9 | Complete |
| WP-11 | Deployment and environment architecture | `deployment/DEPLOYMENT_ARCHITECTURE.md` | WP-1, WP-3 | Complete |
| WP-12 | Testing and validation strategy | `testing/ARCHITECTURE_VALIDATION_PLAN.md` | all | Complete |
| WP-13 | Consistency review and readiness report | `WORK/PHASE_2_READINESS_REPORT.md` | all | Complete |

`WP-0` is this plan plus `ARCHITECTURE_DECISIONS.md`, `UNRESOLVED_QUESTIONS.md`, and
`REQUIREMENTS_TRACEABILITY.md`.

---

## 6. Dependencies and sequencing

### 6.1 Implementation dependencies created by this design

These are ordered. Each blocks the next; none is authorised yet.

```
D1  Session/CSRF bootstrap endpoint (F-01)
     └─ blocks: any non-browser client authentication
D2  Additive schema migration (uuid, version, updated_at precision,
    sync_devices, sync_change_log, sync_tombstones, sync_batches)
     └─ blocks: change capture, cursor sync, guest import
D3  Change-capture mechanism (triggers + actor context in auth middleware)
     └─ blocks: incremental pull
D4  Domain-rule extraction for the rules sync must reuse
    (settlement lock, paid_by validation, group membership, money/date)
     └─ blocks: v2 write endpoints without duplicate business logic
D5  v2 sync endpoints (push/pull/bootstrap/import)
     └─ blocks: Flutter sync engine
D6  Flutter local store + change queue
     └─ blocks: offline mode
D7  Guest migration client flow
     └─ blocks: guest-to-registered conversion
D8  Reconciliation + conflict UI
     └─ blocks: production release of offline mode
```

**Rule:** no Flutter sync code ships before D2–D5 are live in the target environment, because a
client that queues writes against a server with no idempotent push path will lose data.

### 6.2 What can proceed in parallel

- Flutter online-only parity work (Phases 3–5 of `MIGRATION_PRESERVATION_PLAN.md` §7) does **not**
  depend on D2–D5 and can start after Phase 2 approval.
- Guest **local-only** mode (no server contact) depends only on D6 and the local schema, not on
  the server.

---

## 7. Risks

| ID | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R-01 | Change-capture triggers are missed by cascade deletes (F-03) | Certain | High if unhandled | Parent-tombstone + client purge rule; reconciliation checksum |
| R-02 | Duplicate business logic between legacy endpoints and sync endpoints | High if unmanaged | High — divergence in settlement maths | D4 domain-rule extraction before D5; contract tests run against both paths |
| R-03 | Client-generated UUIDs collide or are reused after deletion | Low | Medium | UUIDv7 + `UNIQUE` + tombstone table rejects reuse |
| R-04 | Group delete destroys settlement history before clients sync | Certain today | High | Documented (F-02); remediation requires owner approval (UQ-11); clients purge on tombstone |
| R-05 | Account deletion cascades destroy shared financial records | Certain today | High | No deletion feature exists today; policy required before one is built (UQ-12) |
| R-06 | Rate limiter throttles sync traffic | Medium | Medium | Dedicated sync bucket (UQ-14) |
| R-07 | 24-hour absolute session forces daily re-login on mobile | Certain | Medium (UX) | Owner decision required (UQ-13); not silently changed |
| R-08 | Flutter Web cannot call the API cross-origin | Certain | High for web target | Same-origin deployment baseline (D-08); CORS would be a backend change |
| R-09 | SQLite/SQLCipher unavailable or degraded on Flutter Web | Medium | Medium | Web uses unencrypted IndexedDB-backed WASM SQLite; documented per-platform |
| R-10 | Conflict resolution UI is complex and can confuse users about money | Medium | High | Financial entities never auto-merge; conflicts surface as explicit decisions |
| R-11 | Cursor gaps under concurrent commits | Medium | Medium | Safety lag + reconciliation checksum (D-27) |
| R-12 | Migration of existing rows to `uuid`/`version` is long-running | Low | Low | Small dataset; batched backfill; nullable → backfill → constrain |
| R-13 | Freeze breach by an eager implementer | Medium | High | Every document states the boundary; readiness report lists prohibited actions |
| R-14 | Documentation drifts from source | Medium | Medium | Every doc cites source paths; readiness report includes a re-verification checklist |

---

## 8. Unresolved questions requiring project-owner input

Full register: [`../architecture/UNRESOLVED_QUESTIONS.md`](../architecture/UNRESOLVED_QUESTIONS.md).
Summary of the blocking ones:

| ID | Question | Blocks | Recommendation |
|---|---|---|---|
| UQ-01 | Guest-data merge policy for an **existing** account | Guest migration for existing users | Explicit user-confirmed merge with dry-run preview |
| UQ-02 | Account deletion and retention policy | Account deletion feature; F-04 remediation | Anonymise + block deletion while group obligations exist |
| UQ-03 | Session lifetime for mobile/desktop | Auth UX | Keep 24 h absolute for v1; add explicit "stay signed in" as a separate approved decision |
| UQ-04 | Device revocation behaviour | Multi-device security | Device list with per-device revoke; requires a session store |
| UQ-05 | Sync endpoint rate-limit bucket | Sync API | Separate `api_sync` bucket, 120/min/device |
| UQ-06 | Laravel coexistence model | Any Laravel work | Legacy-PHP auth gateway (no second auth system) |
| UQ-07 | Group-deletion semantics (F-02) | Data integrity | Refuse deletion when settlements exist, or archive the group |
| UQ-08 | Which operations are offline-capable | Flutter offline scope | Conservative matrix in D-16 |
| UQ-09 | Retention period for the change log and tombstones | Sync correctness | 90 days log, indefinite tombstones |
| UQ-10 | Whether to expose `settle.php`/`settle_all.php` in Flutter | Feature scope | Not in default UI [SUPERSEDED by UQ-10 Option B: deprecated with HTTP 410 Gone] |
| UQ-11 | Remediation of orphaned group expenses | Data integrity | Convert orphans to `personal` on group delete |
| UQ-12 | Multi-device conflict notification UX | UX | In-app conflict inbox, no push |
| UQ-13 | Notification sync | Sync scope | Exclude from offline store; fetch live |
| UQ-14 | Whether Flutter Web is a first-class target | Deployment | Yes, same-origin; verify SQLite-WASM and cookie behaviour |

---

## 9. Deliverables and file manifest

### 9.1 New files (all under `docs/`)

```
docs/WORK/PHASE_2_DESIGN_PLAN.md                     (this file)
docs/WORK/REQUIREMENTS_TRACEABILITY.md
docs/WORK/PHASE_2_READINESS_REPORT.md
docs/architecture/TARGET_ARCHITECTURE.md
docs/architecture/TECHNOLOGY_STACK.md
docs/architecture/SYSTEM_COMPONENTS.md
docs/architecture/DATA_FLOW.md
docs/architecture/FLUTTER_ARCHITECTURE.md
docs/architecture/BACKEND_COEXISTENCE.md
docs/architecture/ARCHITECTURE_DECISIONS.md
docs/architecture/UNRESOLVED_QUESTIONS.md
docs/database/DATABASE_DESIGN.md
docs/database/ENTITY_RELATIONSHIP_DIAGRAM.md
docs/database/DATABASE_SCHEMA_SPECIFICATION.md
docs/database/DATA_OWNERSHIP.md
docs/database/VERSIONING_AND_CONFLICT_RESOLUTION.md
docs/database/DATABASE_MIGRATION_STRATEGY.md
docs/api/API_ARCHITECTURE.md
docs/api/API_CONTRACT_SPECIFICATION.md
docs/api/SYNC_API_SPECIFICATION.md
docs/api/AUTHENTICATION_AND_AUTHORIZATION.md
docs/api/API_ERROR_HANDLING.md
docs/api/API_VERSIONING.md
docs/synchronization/SYNCHRONIZATION_ARCHITECTURE.md
docs/synchronization/OFFLINE_DATA_MANAGEMENT.md
docs/synchronization/SYNC_WORKFLOWS.md
docs/synchronization/CONFLICT_RESOLUTION.md
docs/synchronization/GUEST_DATA_MIGRATION.md
docs/security/SECURITY_ARCHITECTURE.md
docs/security/ACCESS_CONTROL.md
docs/security/SESSION_MANAGEMENT.md
docs/deployment/DEPLOYMENT_ARCHITECTURE.md
docs/testing/ARCHITECTURE_VALIDATION_PLAN.md
```

### 9.2 Modified files

```
docs/README.md    (Phase 1 index extended with a Phase 2 index; Phase 1 content preserved)
```

### 9.3 Files explicitly not touched

`api/**`, `config/**`, `pages/**`, `public/**`, `schema.sql`, `migration_v*.sql`, `tests/**`,
`scripts/**`, `AGENTS.md`, `README.md`, `PENTEST/**`, `data/**`, `logs/**`.

`AGENTS.md` is not modified because it is the agent-context file, not a Phase 2 deliverable. A
follow-up commit after approval may add a pointer to this directory.

---

## 10. Execution sequence

| Stage | Content | Output |
|---|---|---|
| 2A | Repository and requirements review; decisions; gaps | This plan, ADR, UQ register, RTM |
| 2B | Technology and system architecture | `TARGET_ARCHITECTURE`, `TECHNOLOGY_STACK`, `SYSTEM_COMPONENTS`, `DATA_FLOW`, `FLUTTER_ARCHITECTURE`, `BACKEND_COEXISTENCE` |
| 2C | Database and ownership design | `DATABASE_DESIGN`, `ENTITY_RELATIONSHIP_DIAGRAM`, `DATABASE_SCHEMA_SPECIFICATION`, `DATA_OWNERSHIP`, `VERSIONING_AND_CONFLICT_RESOLUTION`, `DATABASE_MIGRATION_STRATEGY` |
| 2D | Authentication and guest architecture | `AUTHENTICATION_AND_AUTHORIZATION`, `SESSION_MANAGEMENT`, `ACCESS_CONTROL`, `GUEST_DATA_MIGRATION` |
| 2E | Synchronisation architecture | `SYNCHRONIZATION_ARCHITECTURE`, `OFFLINE_DATA_MANAGEMENT`, `SYNC_WORKFLOWS`, `CONFLICT_RESOLUTION` |
| 2F | API architecture | `API_ARCHITECTURE`, `API_CONTRACT_SPECIFICATION`, `SYNC_API_SPECIFICATION`, `API_VERSIONING`, `API_ERROR_HANDLING` |
| 2G | Security, deployment, testing | `SECURITY_ARCHITECTURE`, `DEPLOYMENT_ARCHITECTURE`, `ARCHITECTURE_VALIDATION_PLAN` |
| 2H | Consistency review | `PHASE_2_READINESS_REPORT` |

---

## 11. Acceptance criteria for Phase 2

Phase 2 may be proposed as complete only when every item below is true.

| # | Criterion | Verified in |
|---|---|---|
| 1 | D-01…D-07 recorded as `ACCEPTED` with context, rationale, consequences | `ARCHITECTURE_DECISIONS.md` |
| 2 | Every agent-made decision is `PROPOSED` or `NEEDS APPROVAL`, never `ACCEPTED` | `ARCHITECTURE_DECISIONS.md` |
| 3 | Target architecture names every component, responsibility, and boundary | `TARGET_ARCHITECTURE.md`, `SYSTEM_COMPONENTS.md` |
| 4 | All seven required diagrams exist and match the written design | `DATA_FLOW.md`, `TARGET_ARCHITECTURE.md` |
| 5 | Flutter layering, packages, and platform caveats documented with alternatives | `FLUTTER_ARCHITECTURE.md`, `TECHNOLOGY_STACK.md` |
| 6 | PHP/Laravel coexistence defines one auth boundary and forbids duplicate business logic | `BACKEND_COEXISTENCE.md` |
| 7 | Every proposed schema change answers the ten required questions | `DATABASE_SCHEMA_SPECIFICATION.md` §6 |
| 8 | ER diagram distinguishes existing, added, and unresolved structures | `ENTITY_RELATIONSHIP_DIAGRAM.md` |
| 9 | Ownership is defined for all twelve required entity groups | `DATA_OWNERSHIP.md` |
| 10 | Versioning and per-entity conflict policies are specified | `VERSIONING_AND_CONFLICT_RESOLUTION.md`, `CONFLICT_RESOLUTION.md` |
| 11 | Guest feature boundary and migration workflow are fully specified | `GUEST_DATA_MIGRATION.md` |
| 12 | Existing-account import is documented as an unresolved policy with options | `GUEST_DATA_MIGRATION.md` §7, UQ-01 |
| 13 | Sync protocol covers initial, incremental, push, pull, retry, recovery, cursor | `SYNC_API_SPECIFICATION.md`, `SYNC_WORKFLOWS.md` |
| 14 | Every proposed endpoint has all fifteen required attributes | `SYNC_API_SPECIFICATION.md` §4 |
| 15 | Existing endpoints are distinguished from proposed ones | `API_CONTRACT_SPECIFICATION.md` §2 |
| 16 | Security implications of offline storage, devices, migration, sync are addressed | `SECURITY_ARCHITECTURE.md` |
| 17 | Deployment architecture evaluates same-origin, HTTPS, environments, backups | `DEPLOYMENT_ARCHITECTURE.md` |
| 18 | Test scenarios include failure paths and acceptance criteria | `ARCHITECTURE_VALIDATION_PLAN.md` |
| 19 | Every requirement has an ID, origin, status, and verification method | `REQUIREMENTS_TRACEABILITY.md` |
| 20 | No unresolved decision is presented as settled | `UNRESOLVED_QUESTIONS.md` |
| 21 | Cross-document consistency review completed | `PHASE_2_READINESS_REPORT.md` |
| 22 | No application source, schema, or migration file changed | `PHASE_2_READINESS_REPORT.md` §Git status |
| 23 | Project owner has reviewed and approved | **Outstanding** |

---

## 12. Terminology (canonical, used in every Phase 2 document)

| Term | Meaning |
|---|---|
| **Legacy API (v1)** | The existing 40 HTTP endpoints under `api/`. Frozen behaviour. |
| **Sync API (v2)** | The proposed endpoint surface under `api/v2/`. Not implemented. |
| **Entity** | A synchronisable record type: `expense`, `budget`, `list`, `list_item`, `group`, `group_member`, `settlement`. |
| **Syncable-writable** | Entities the client may create/modify offline: `expense`, `budget`, `list` (personal only), `list_item` (personal lists only). |
| **Server-authoritative** | Entities the client may read and cache but never write: `group`, `group_member`, `settlement`, `settlement_confirmation`, `notification`, `category`. |
| **Operation** | A named server action that is not a record write: check item, confirm settlement, join group, etc. Operations are never replayed from a field-level queue. |
| **Version** | Server-maintained integer per row, incremented on every update. |
| **Cursor** | Monotonic `sync_change_log.seq` watermark per device. |
| **Tombstone** | A durable record that an entity UUID was deleted. |
| **Guest mode** | Local-only application mode with no server contact. |
| **Registered mode** | Authenticated mode with server-authoritative data. |
| **Bootstrap** | The first, full synchronisation of a device. |
| **Reconciliation** | A periodic integrity check comparing local and server state. |

---

## 13. Next action

1. Project owner reviews this plan, the ADR register, and the unresolved-question register.
2. Owner answers UQ-01…UQ-14 (or defers specific ones).
3. Blocking decisions are updated from `NEEDS APPROVAL` to `ACCEPTED` or `DEFERRED`.
4. Only then does implementation planning (Phases 3–6) begin.

**Phase 2 is not complete until item 23 of §11 is satisfied.**
