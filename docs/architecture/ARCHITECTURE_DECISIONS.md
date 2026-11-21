# Architecture Decision Register

| Field | Value |
|---|---|
| Document status | Living register — updated as decisions are made |
| Date opened | 2026-11-15 |
| Phase | 2 |
| Owner authority | Only the project owner may change a decision to `ACCEPTED` |

This register is the single source of truth for architectural decisions. If any other Phase 2
document contradicts a decision recorded here, **this document wins** and the other document has
a defect.

---

## 1. Status vocabulary

| Status | Meaning | May implementation proceed? |
|---|---|---|
| `ACCEPTED` | Confirmed by the project owner | Yes |
| `PROPOSED` | Recommended by the architecture work, technically justified, not yet owner-confirmed | No — pending owner confirmation |
| `NEEDS APPROVAL` | A genuine policy choice with business consequences; the agent must not decide it | No |
| `DEFERRED` | Acknowledged, deliberately postponed; not required for the current phase | Not applicable |
| `SUPERSEDED` | Replaced by a later decision; kept for history | Follow the superseding decision |

**Rule:** an agent may never promote its own `PROPOSED` decision to `ACCEPTED`. Only the project
owner can do that.

---

## 2. Decision index

| ID | Title | Status | Date |
|---|---|---|---|
| D-01 | Flutter + Dart is the application frontend | `ACCEPTED` | 2026-11-15 |
| D-02 | Retain the PHP backend and MySQL database | `ACCEPTED` | 2026-11-15 |
| D-03 | Selective Laravel adoption | `ACCEPTED` | 2026-11-15 |
| D-04 | Guest mode is limited and local-only | `ACCEPTED` | 2026-11-15 |
| D-05 | Multi-device synchronisation is first-class | `ACCEPTED` | 2026-11-15 |
| D-06 | Versioning and optimistic concurrency | `ACCEPTED` | 2026-11-15 |
| D-07 | Documentation-first development | `ACCEPTED` | 2026-11-15 |
| D-08 | Flutter Web deploys same-origin with the API | `PROPOSED` | 2026-11-15 |
| D-09 | Dual authentication model: Bearer JWTs for Flutter, BFF sessions for legacy web | `PROPOSED` (MODIFIED) | 2026-11-20 |
| D-10 | A dedicated v2 API surface for new capabilities | `PROPOSED` | 2026-11-15 |
| D-11 | Server-authoritative sync: version + change log + tombstones + cursor | `PROPOSED` | 2026-11-15 |
| D-12 | Client-generated UUIDv7 is the canonical record identity | `PROPOSED` | 2026-11-15 |
| D-13 | Change capture by database triggers, with parent-tombstone compensation for cascades | `PROPOSED` | 2026-11-15 |
| D-14 | Drift (SQLite) is the Flutter local store | `PROPOSED` | 2026-11-15 |
| D-15 | Riverpod is the Flutter state-management and DI mechanism | `PROPOSED` | 2026-11-15 |
| D-16 | Offline-write capability matrix covers six entity types | `ACCEPTED` | 2026-11-18 |
| D-17 | Online-only operation boundaries (settlement confirmation, list checking, group mutations) | `ACCEPTED` | 2026-11-18 |
| D-18 | Procedural PHP backend retained; Laravel remains optional for future justified use | `ACCEPTED` | 2026-11-18 |
| D-19 | Notifications are excluded from the offline sync store | `PROPOSED` | 2026-11-15 |
| D-20 | Hard delete plus a tombstone table; no soft-delete columns | `PROPOSED` | 2026-11-15 |
| D-21 | Money crosses the wire as a decimal string; Dart holds integer cents | `PROPOSED` | 2026-11-15 |
| D-22 | Guest-data merge into an existing account | `ACCEPTED` | 2026-11-18 |
| D-23 | Account deletion and data-retention policy | `ACCEPTED` | 2026-11-18 |
| D-24 | Session lifetime for native clients | `ACCEPTED` | 2026-11-18 |
| D-25 | Device revocation model via database session registry | `ACCEPTED` | 2026-11-18 |
| D-26 | Group archival lifecycle and historical expense provenance | `ACCEPTED` | 2026-11-18 |
| D-27 | Sync cursor safety: safety lag plus reconciliation checksum | `PROPOSED` | 2026-11-15 |
| D-28 | Batch synchronization and sync API rate limits | `ACCEPTED` | 2026-11-18 |
| D-29 | No microservices, no Kubernetes, no message broker | `PROPOSED` | 2026-11-15 |
| D-30 | Domain rules are extracted once and reused by v1 and v2 | `PROPOSED` | 2026-11-15 |
| D-31 | The legacy PHP UI remains online-only and unmodified | `PROPOSED` | 2026-11-15 |
| D-32 | `api/bootstrap.php` stays dead; `config/session.php` remains the session owner | `PROPOSED` | 2026-11-15 |
| D-33 | Separate remember-device credential mechanism for native platforms | `ACCEPTED` | 2026-11-18 |
| D-34 | Mandatory device registration and registry before synchronization | `ACCEPTED` | 2026-11-18 |
| D-35 | Account-wide 24-hour reminders with receipt-based schedule reset | `ACCEPTED` | 2026-11-18 |
| D-36 | Mandatory two-way backward compatibility with legacy web interface | `ACCEPTED` | 2026-11-18 |
| D-37 | Native-first platform release sequencing (Release 1 Mobile/Desktop, Release 2 Web) | `ACCEPTED` | 2026-11-18 |
| D-38 | Guest mode feature restrictions and export prohibition | `ACCEPTED` | 2026-11-18 |
---

## 3. Confirmed decisions (project owner)

### D-01 — Flutter + Dart is the application frontend

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-15 |
| Decided by | Project owner |

**Context.** The application frontend is currently a PHP-rendered SPA shell with hand-written
ES2020 modules and no build step (`FRONTEND_AUDIT.md`). It cannot serve offline use or native
platforms.

**Decision.** Flutter and Dart are the frontend technology for the modernised application. Target
platforms are Android, iOS, Web, Windows, macOS, and Linux. Platform support must be evaluated
against actual Flutter capabilities and the project's dependency choices; no platform may be
claimed as fully supported until its requirements have been verified.

**Rationale.** One codebase and one behavioural implementation across six platforms; a single
domain layer prevents the divergence that already exists between `app.js`, `expenses.js`,
`groups.js`, and `lists.js`.

**Consequences.**
- The existing JS modules become a *behavioural reference*, not a code template
  (`FRONTEND_AUDIT.md` §9).
- Every frontend-only feature (calendar, charts, PDF, theme, sorting) must be reimplemented.
- Two frontends exist during the transition; both must remain contract-compatible with the same
  API.
- Web support brings a hard constraint: Flutter Web is a browser app and inherits the API's
  same-origin and cookie rules (see D-08, D-09).

**Affected components.** Flutter app, API contract, deployment, testing.
**Related documents.** `architecture/FLUTTER_ARCHITECTURE.md`, `architecture/TECHNOLOGY_STACK.md`,
`deployment/DEPLOYMENT_ARCHITECTURE.md`.

---

### D-02 — Retain the PHP backend and MySQL database

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-15 |
| Decided by | Project owner |

**Context.** 40 HTTP endpoints, 14 tables, and 538 tests encode the product's business rules.
The application is not being discarded.

**Decision.** Keep the existing procedural PHP backend and the MySQL database. Existing PHP APIs
and business logic remain operational throughout the transition. MySQL remains the central
persistent store for registered-user data. The architecture must support the Flutter client
without an unnecessary backend rewrite.

**Rationale.** The rules that matter most — `paid_by` settlement maths, `is_post_settlement`
filtering, the settlement lock, the three-step confirmation gate — are already implemented and
characterised by tests. A rewrite would re-introduce exactly the defects that Phase 1 documented.

**Consequences.**
- The backend must gain additive capabilities (IDs, versions, change log, tombstones, sync
  endpoints) without changing existing endpoint behaviour.
- The 105 known test failures stay exactly as they are (`TEST_BASELINE.md`); no "fix" is
  authorised by this decision.
- MySQL stays. No document may propose replacing it.

**Affected components.** Backend, database, API, deployment.
**Related documents.** `architecture/BACKEND_COEXISTENCE.md`,
`database/DATABASE_MIGRATION_STRATEGY.md`, `MIGRATION_PRESERVATION_PLAN.md`.

---

### D-03 — Selective Laravel adoption

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-15 |
| Decided by | Project owner |

**Context.** The backend has no framework, no ORM, and no Composer autoload in endpoints. Some
future work (queues, scheduled jobs, an admin console, richer reporting) would benefit from
framework facilities.

**Decision.** Laravel is optional and selective:
- Continue procedural PHP where it remains suitable.
- Introduce Laravel for new features only when its capabilities provide a meaningful advantage.
- Migrate selected existing modules only where there is a clear technical or maintenance benefit.
- Do not migrate the entire backend.
- Do not rewrite working PHP modules merely to adopt Laravel.

Both environments must share database access, one authentication/authorisation model, one set of
API contracts, and one implementation of each business rule.

**Rationale.** The current backend is small, auditable, and deployable on any LAMP host. A full
framework migration is the highest-risk change available and buys nothing the product currently
needs.

**Consequences.**
- A coexistence model is mandatory before any Laravel code is written (D-18, `NEEDS APPROVAL`).
- "No duplicate business logic" is a hard constraint, not a preference: the same settlement rule
  must not exist twice.
- This decision does **not** authorise Laravel for the sync engine. The sync engine must reuse
  the same rules as the legacy endpoints, which argues for procedural PHP there (see
  `BACKEND_COEXISTENCE.md` §6).

**Affected components.** Backend, deployment, security, testing.
**Related documents.** `architecture/BACKEND_COEXISTENCE.md`.

---

### D-04 — Guest mode is limited and local-only

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-15 |
| Decided by | Project owner |

**Context.** The current application has **no guest mode at all**; every screen except splash,
login, and signup requires an authenticated session (`FEATURE_INVENTORY.md` AUTH-8).

**Decision.** Guest mode is a limited mode. Guests use only the features in the guest package,
with data stored locally on the device. Guests must not reach registered-user functionality. When
a guest attempts a registered feature, registration becomes mandatory; the account is created;
guest data is migrated into that account; the migrated records become registered-user data; and
the application verifies successful migration **before** deleting the original guest data.

Migration must preserve record contents, relationships, relevant timestamps, identifiers where
appropriate, expense/group relationships, list/expense relationships, and other business
invariants. Guest data must never be silently discarded because registration or migration failed.

**Rationale.** Guest mode gives a usable product with no account, while keeping the server free of
anonymous data and anonymous API paths — which the existing security model forbids.

**Consequences.**
- No new anonymous endpoint may be created for guest mode.
- A guest package must be defined explicitly, because "guest can use some features" is not a
  specification. Defined in `synchronization/GUEST_DATA_MIGRATION.md` §3.
- Guest data has no server backup; device loss means data loss. The UI must say so.
- Guest migration is a distinct workflow with its own failure, retry, idempotency, and
  verification design.
- Creating a new account and logging into an existing account are **different scenarios**. The
  merge policy for the latter is `NEEDS APPROVAL` (D-22).

**Affected components.** Flutter, API, database, security.
**Related documents.** `synchronization/GUEST_DATA_MIGRATION.md`,
`security/SECURITY_ARCHITECTURE.md` §6.

---

### D-05 — Multi-device synchronisation is first-class

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-15 |
| Decided by | Project owner |

**Context.** `DATABASE_AUDIT.md` §7 establishes that the current schema is **not** sync-ready: no
client IDs, no cursor, no tombstones, no row versions, and several tables without `updated_at`.
Phase 1 explicitly deferred this to a dedicated design.

**Decision.** Registered users must be able to access and modify their data across multiple
devices, online and offline. Required capabilities: multi-device access; offline create, modify,
and delete; local persistence of pending changes; upload on reconnect; retrieval of other
devices' changes; concurrent-modification detection; conflict resolution; deletion propagation;
interrupted-sync recovery; safe retry; and visible sync status.

Synchronisation is a first-class capability, not "upload local rows when the network returns".
The complete data lifecycle must be designed.

**Rationale.** The product owner requires it, and the current architecture cannot provide it.

**Consequences.**
- The database needs additive structures: UUIDs, versions, microsecond timestamps, a change log,
  tombstones, device records, and durable sync idempotency.
- New endpoints are required; the existing 40 cannot express versioned writes or deltas.
- The client needs a real local database with a change queue, not key-value storage.
- Every business invariant must be re-checked server-side on push. A sync path that trusts the
  client would be a security regression.
- **Supersedes** the Phase 1 statement that shared data is "online-first"
  (`MIGRATION_PRESERVATION_PLAN.md` §5) **for the Flutter client only**. The legacy PHP UI remains
  online-only (D-31).

**Affected components.** Everything.
**Related documents.** `synchronization/*`, `database/*`, `api/SYNC_API_SPECIFICATION.md`.

---

### D-06 — Versioning and optimistic concurrency

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-15 |
| Decided by | Project owner |

**Context.** Today the last write silently wins. `expenses.updated_at` has one-second granularity,
so timestamps cannot even order writes reliably within a second.

**Decision.** Use record versioning and timestamps. The baseline mechanism is **version-based
optimistic concurrency control**. Timestamps support auditing, synchronisation, and ordering, but
timestamps alone must never be treated as proof that concurrent modification did not occur.

The design must cover record identifiers, versions, server-managed timestamps, client timestamps
where necessary, update detection, concurrent-update detection, conflict representation, per-entity
conflict policies, retry behaviour, deletion handling, out-of-order events, clock skew, and
duplicate requests. Last-write-wins may be evaluated per data type but must not be applied
universally.

Synchronisation must never silently violate settlement or ownership rules.

**Rationale.** Financial records cannot be merged on a "latest timestamp wins" basis; a lost
update is a wrong balance.

**Consequences.**
- Every syncable row gains `version INT UNSIGNED NOT NULL DEFAULT 1`, incremented on update.
- Push requests carry `base_version`; a mismatch is a conflict, not an overwrite.
- Different entities get different policies (`database/VERSIONING_AND_CONFLICT_RESOLUTION.md`).
- Server clock is authoritative; client clocks are advisory only.
- Legacy v1 writes still succeed without a version check (existing behaviour preserved), but they
  bump the version, so sync clients see the change.

**Affected components.** Database, API, Flutter.
**Related documents.** `database/VERSIONING_AND_CONFLICT_RESOLUTION.md`,
`synchronization/CONFLICT_RESOLUTION.md`.

---

### D-07 — Documentation-first development

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-15 |
| Decided by | Project owner |

**Context.** Phase 1 produced a frozen behavioural reference. Phase 2 must produce the target
design before code exists.

**Decision.** Phase 2 deliverables are Markdown specifications under `docs/`. They must be
detailed, internally consistent, source-derived, explicit about assumptions and unresolved
questions, suitable for implementation by another developer or coding agent, version-controlled,
cross-referenced, and maintained as the architectural source of truth. Implementation does not
start before review and approval.

**Rationale.** A coding agent without a fixed design invents decisions during implementation, and
those inventions are inconsistent and untraceable.

**Consequences.**
- `docs/` becomes authoritative for the target system; `docs/*_AUDIT.md` remain authoritative for
  current behaviour.
- Every document must state what is fact (verified from source) and what is proposal.
- Divergence between implementation and documentation is a defect in the implementation.

**Affected components.** All.
**Related documents.** `docs/README.md`.

---

## 4. Agent-proposed decisions

These are technically justified recommendations produced during Phase 2. They are **not**
owner-approved.

### D-08 — Flutter Web deploys same-origin with the API

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** Verified: the codebase contains **no** `Access-Control-*` header anywhere
(`grep -i "access-control\|cors"` over all `*.php` returns zero matches). The SPA CSP sets
`connect-src 'self'` (`config/session.php:81`). The session cookie is `SameSite=Lax`
(`config/session.php:50`). Therefore a Flutter Web build served from a different origin cannot
call the API at all: the browser will block the request at CORS preflight and, even if CORS were
added, `SameSite=Lax` would not attach the session cookie to a cross-site `fetch`.

**Decision.** Flutter Web is deployed **same-origin** with the API, in a subdirectory of the same
host (for example `/ExpMgWEB/app/`), and the API base URL is a compile-time configuration value
that defaults to a same-origin relative path.

**Rationale.** Same-origin deployment requires **zero backend change**, preserves the existing
CSRF/session model exactly, and satisfies the current CSP unchanged. Adding CORS plus
`SameSite=None; Secure` would be a security-relevant backend change with no product benefit.

**Consequences.**
- Native platforms (Android/iOS/desktop) are unaffected: CORS is a browser concept and they use
  an absolute API base URL.
- Flutter Web must not be hosted on a separate domain, a CDN origin, or a different port.
- If a separate origin is ever required, it becomes a new decision requiring CORS design,
  `SameSite=None`, and an approved security review. Recorded as UQ-15.
- The web build needs its own cache-busting and service-worker scope handling under the
  subdirectory.

**Affected components.** Deployment, Flutter Web, security.
**Related documents.** `deployment/DEPLOYMENT_ARCHITECTURE.md` §4,
`security/SESSION_MANAGEMENT.md` §5.

---

### D-09 — Dual authentication model: Bearer JWTs for Flutter, BFF sessions for legacy web

| Field | Value |
|---|---|
| Status | `PROPOSED` (MODIFIED in Phase 3.0) |
| Date recorded | 2026-11-15 (Updated 2026-11-20) |
| Decision owner | Architecture work (agent) |

**Context.** The backend historically authenticated exclusively through a PHP session cookie
(`config/session.php`, `api/middleware/auth.php`). Phase 3 introduces Supabase Auth (GoTrue) for
identity management and authentication across clients.

**Historical Formulation (2026-11-15, Superseded).** All platforms authenticate with the same
PHP session cookie and the same `X-CSRF-Token` requirement. No JWT or bearer-token scheme was to
be introduced.

**Reconciled Decision (2026-11-20, Phase 3.0).** A **Dual Authentication Model** is adopted:
1. **Flutter Clients (Mobile, Desktop, Web):** Authenticate with Supabase Auth to obtain standard
   RS256/ES256 JWT access tokens and long-lived refresh tokens. Client requests transmit
   `Authorization: Bearer <access_token>`, cryptographically verified by PHP (`api/middleware/auth.php`)
   using cached Supabase JWKS.
2. **Legacy PHP/JavaScript Website:** Preserves its existing cookie-based session and CSRF
   mechanisms through a PHP **Backend-For-Frontend (BFF) session bridge**. `api/login.php`
   authenticates against Supabase Auth, stores the session securely in `$_SESSION`, and issues the
   hardened `PHPSESSID` cookie. The legacy SPA frontend remains 100% cookie-driven and unmodified.

**Rationale.**
- Provides standard token-based authentication for modern Flutter native and web clients without
  cookie handling quirks.
- Fully preserves the legacy web application without requiring a frontend rewrite.
- Eliminates the need for client-side JWT handling in the legacy JavaScript codebase.
- Preserves canonical CSRF protection (`verifyCsrf()`) for cookie-authenticated web sessions.

**Trade-offs & Constraints.**
- Flutter HTTP clients must explicitly intercept 401 `TOKEN_EXPIRED` responses and execute token
  refresh with idempotency protection on retries.
- Immediate device revocation requires application-level registry enforcement (`sync_clients`), as
  Supabase refresh-token revocation does not immediately invalidate already-issued access JWTs.

**Affected components.** API, Flutter, Legacy Web, Supabase Auth, Security.
**Related documents.** `docs/architecture/PHASE_3_0_ARCHITECTURE_BLUEPRINT.md` §4,
`docs/architecture/TARGET_ARCHITECTURE.md`.

---

### D-10 — A dedicated v2 API surface for new capabilities

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** Existing endpoints are individual PHP files with no router
(`API_CONTRACT.md` §1). Their paths carry no version. Changing their behaviour would break the
legacy UI and the frozen contract.

**Decision.** New capabilities live under `/api/v2/`. The existing 40 endpoints remain the v1
surface, frozen. Both share the same session, CSRF, database, and business rules.

**Rationale.** Path-based versioning needs no router, works with the existing Apache layout, and
lets the client detect capability by URL rather than by parsing payloads. Header-based versioning
was rejected because there is no request pipeline to read it centrally.

**Consequences.**
- Two endpoint families coexist during transition; `API_VERSIONING.md` defines the compatibility
  rules.
- No v1 endpoint is removed or changed without an approved decision and a new baseline capture, **with the single owner-approved narrow exception of deprecating administrative settlement endpoints `settle.php` and `settle_all.php` to return HTTP `410 Gone` (UQ-10 Option B)**.
- Post-UQ-20 implementation requires updating both `tests/run_tests.php` Section 17 and `scripts/pentest/penetration_test.php` (cases S07–S11) in lockstep.
- The v2 surface must not re-implement v1 logic (D-30).

**Affected components.** API, Flutter, deployment.
**Related documents.** `api/API_VERSIONING.md`, `api/API_ARCHITECTURE.md`.

---

### D-11 — Server-authoritative sync: version + change log + tombstones + cursor

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** Four candidate sync mechanisms were evaluated.

| Option | Description | Verdict |
|---|---|---|
| A | Client replays v1 endpoint calls from a queue | Rejected: cannot detect conflicts, cannot pull deltas, cannot propagate deletions |
| B | Per-record `updated_at` polling ("give me everything newer than T") | Rejected: second-granularity ties, no deletion visibility, unbounded queries |
| C | Full server change log + per-record version + tombstones + monotonic cursor | **Selected** |
| D | CRDT / operational transform per field | Rejected: unjustifiable complexity for a small financial dataset with admin-gated group records |

**Decision.** Option C. The server keeps an ordered change log of every write to a syncable
entity, each row carrying `version`, a monotonic `seq`, and a full row snapshot (or a delete
marker). Clients pull with a cursor over `seq`. Deletions are durable tombstones. Every write is
version-checked.

**Rationale.** It gives exact conflict detection (version), exact deletion propagation
(tombstone), bounded incremental pulls (cursor), and recoverability (a stale cursor triggers a
bootstrap). It also works with the existing `mysqli` prepared-statement style and needs no broker.

**Consequences.**
- Additive schema only; existing queries are untouched.
- The change log must be pruned; clients older than the retention window must re-bootstrap.
- The `seq` cursor has a known concurrency hazard (D-27) that is mitigated, not ignored.

**Affected components.** Database, API, Flutter.
**Related documents.** `synchronization/SYNCHRONIZATION_ARCHITECTURE.md`,
`database/DATABASE_SCHEMA_SPECIFICATION.md` §5.

---

### D-12 — Client-generated UUIDv7 is the canonical record identity

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** All primary keys are `INT UNSIGNED AUTO_INCREMENT`. A client cannot create a row
offline and reference it from another offline row without an identifier.

**Decision.** Every syncable entity gains a `uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin
NOT NULL` column with a unique index. The client generates the UUID before the record is created.
`id` remains the internal surrogate key and is never the wire identity for new records.

**Rationale.**
- Client-generated identity removes the entire ID-remapping problem from sync.
- UUIDv7 is time-ordered, which keeps index inserts local and makes debugging chronological.
- `CHAR(36)` is readable in SQL during support work; `BINARY(16)` would save ~20 bytes per row on
  a dataset of thousands of rows — not worth the debugging cost.

**Trade-offs.**
- `CHAR(36)` unique indexes are larger than integer keys.
- A client bug could generate a duplicate; the unique index turns that into a clean `409`
  instead of silent corruption.
- If the chosen Dart `uuid` package version lacks v7, v4 is acceptable: the design depends on
  uniqueness, not ordering. Verified at implementation time (see
  `architecture/TECHNOLOGY_STACK.md` §5).

**Affected components.** Database, API, Flutter.
**Related documents.** `database/DATABASE_SCHEMA_SPECIFICATION.md` §3.1.

---

### D-13 — Change capture by database triggers, with parent-tombstone compensation

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** Two mechanisms can populate the change log.

| Mechanism | Coverage | Risk |
|---|---|---|
| Application-level writes in each endpoint | Only the endpoints someone remembers to change (~20 write sites across 40 files) | Silent missed changes — the worst class of sync bug |
| Database triggers | Every direct `INSERT`/`UPDATE`/`DELETE` regardless of writer | Invisible logic; **does not fire on FK cascade actions** (finding F-03) |

**Decision.** Use `AFTER INSERT` / `AFTER UPDATE` / **`BEFORE DELETE`** triggers on the seven syncable
tables to write `sync_change_log` rows, with `BEFORE UPDATE` triggers maintaining `version` and
`updated_at`. (`BEFORE DELETE`, not `AFTER DELETE`, because `AFTER DELETE` cannot read the deleted row
and so cannot record `last_version` or the diagnostic snapshot — `DATABASE_SCHEMA_SPECIFICATION.md`
§5.6.2.) The acting user, device, and a suppression flag are passed to the connection as
session variables (`@expmg_actor_user_id`, `@expmg_device_uuid`, `@expmg_sync_suppress`) set once
in `api/middleware/auth.php`.

Cascade-deleted children are **not** captured by triggers. They are covered by the protocol rule:
a parent tombstone obliges the client to purge that parent's entire local subtree. That rule is
**not sufficient for group deletion**, which destroys the membership rows the parent's scope depends
on; `trg_groups_bd` therefore also writes one personally-scoped `group_member` tombstone per current
member and detaches surviving expenses explicitly (`DATABASE_SCHEMA_SPECIFICATION.md` §5.4.3–§5.4.4).

**Rationale.** Triggers guarantee that a future developer cannot forget to log a change. The
cascade gap is real but bounded: every cascade in this schema originates from deleting a group or
a user, and both are single, identifiable parent events — with the group case requiring the
per-member compensation above.

**Consequences.**
- Migrations and backfills must set `@expmg_sync_suppress = 1` to avoid emitting thousands of
  false changes.
- Triggers must be version-controlled in `schema.sql` and every migration that touches a
  triggered table.
- Trigger failures fail the originating write — acceptable, because a write that is not logged
  would desynchronise clients permanently.
- The alternative (application-level) is documented as the fallback if trigger debugging proves
  unmaintainable.

**Affected components.** Database, API middleware.
**Related documents.** `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.6,
`synchronization/SYNCHRONIZATION_ARCHITECTURE.md` §6.

---

### D-14 — Drift (SQLite) is the Flutter local store

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** Candidate local stores: Drift/SQLite, Isar, Hive, sqflite, ObjectBox.

**Decision.** Drift over SQLite.

**Rationale.**
- The domain is relational (expenses reference groups, categories, lists, items). A relational
  store with foreign keys and joins matches the server schema and makes reconciliation a query,
  not application code.
- Drift generates typed queries and has a real migration system, which a sync engine needs.
- It supports all six target platforms, including web via SQLite-WASM.
- Hive is key-value: joins and aggregate queries for analytics would be hand-written.
- Isar's future maintenance and web story are less certain.
- `sqflite` is mobile-only and has no web/desktop parity.

**Trade-offs.**
- Web requires SQLite-WASM assets and a one-time `sqlite3.wasm`/worker setup; verified at
  implementation time.
- SQLCipher encryption (for at-rest protection of cached financial data) is unavailable on web.
  Documented per platform in `security/SECURITY_ARCHITECTURE.md` §5.

**Affected components.** Flutter.
**Related documents.** `architecture/FLUTTER_ARCHITECTURE.md` §6,
`synchronization/OFFLINE_DATA_MANAGEMENT.md`.

---

### D-15 — Riverpod is the Flutter state-management and DI mechanism

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** Candidates: Riverpod, Bloc, Provider, `get_it` + `ChangeNotifier`.

**Decision.** Riverpod for state and dependency injection, with a strict layer boundary
(presentation → application → domain → data).

**Rationale.** Sync state is inherently asynchronous and derived (connectivity × queue depth ×
conflict count); Riverpod's async providers and `AsyncValue` model that directly and are testable
without a widget tree. Bloc is a defensible alternative but adds event/state boilerplate for
every small form. Provider lacks compile-time safety. Mixing two systems is forbidden.

**Consequences.** No `setState`-driven global state; no service locator; providers are the only
composition root.

**Affected components.** Flutter.
**Related documents.** `architecture/FLUTTER_ARCHITECTURE.md` §3.

---

### D-16 — Offline-write capability matrix covers six entity types

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-08) |

**Context.** D-05 requires offline create/modify/delete. It does **not** require every operation
to work offline. Allowing everything offline would make settlement invariants and access controls unenforceable client-side.

**Decision.** Offline write capability is approved for **six entity types**:

| Entity / operation | Offline read | Offline write | Reason |
|---|---|---|---|
| Personal expense | Yes | Create, edit, delete | Owner-only; validated against settlement lock upon push |
| Group expense | Yes | Create (drafted locally), edit (admin), delete (admin) | Server validates membership, payer, and settlement lock upon push |
| Personal budget | Yes | Set (month, amount) | Single-user scalar record |
| Personal list | Yes | Create, rename, delete | Owner-only list container |
| Group list | Yes | Create, rename, delete | Group-scoped list container; collaborative sync |
| List item | Yes | Add, edit, remove | Personal and group list items; **check/uncheck strictly excluded** |
| List check / uncheck | — | **No** (D-17) | Auto-converts to financial expense; strictly online-only |
| Group membership mutations | Yes (read) | **No** (D-17) | Proposed online-only security restriction (join codes, 10-member ceiling) |
| Settlement calculate / confirm / settle | Yes (read) | **No** (D-17) | Financial closure requires server-authoritative period calculation |
| Notification read / mark read | Cached only | **No** (D-19) | Excluded from offline sync store; fetched live from REST |
| Categories | Yes | **No** | Server-seeded shared taxonomy |

**Rationale.** The matrix keeps every invariant that cannot be checked offline on the server, while still delivering the offline experience that matters: recording expenses, lists, and budgets.

**Affected components.** Flutter, API, Database.
**Related documents.** `synchronization/OFFLINE_DATA_MANAGEMENT.md` §2, `architecture/UNRESOLVED_QUESTIONS.md` UQ-08.

---

### D-17 — Online-only operation boundaries (settlement confirmation, list checking, group mutations)

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-15 |
| Decision owner | Project owner (see UQ-08) |

**Context.** Certain operations mutate shared collaborative state or financial ledgers across boundaries that an offline client cannot safely verify:

1. **Settlement confirmation** (`api/settlements/confirm.php`) requires an atomic `SELECT … FOR UPDATE` transaction across `settlement_confirmations` and closes the financial period for all group members. (*Owner-approved online-only requirement*).
2. **Checking/unchecking a priced list item** (`api/lists/check_item.php`) auto-creates or deletes a linked financial expense. In group lists, this charges other members. An offline check could create an expense within an already-settled period. (*Owner-approved online-only requirement*).
3. **Group membership mutations** (group creation, joining via join code, leaving group, removing a member, deleting a group, and adding/removing members). (*Owner-approved online-only operation per UQ-08*).

**Decision & Security Invariants (Owner-Approved).**
- Settlement confirmation and shopping-list item checking/unchecking are strictly online-only operations.
- Group membership mutations are **owner-approved strictly online-only operations**:
  - *Rationale:* Group membership controls authorization boundaries, cryptographic access, and group size limits (maximum 10 members per group, max 5 groups per user). Permitting offline joins or invites risks split-brain authorization states, over-capacity membership violations, and orphan data attribution.
- Settlement calculations (`calculate.php`) remain dynamic read-only queries requiring live server aggregation.

**Affected components.** Flutter, API, Security.
**Related documents.** `synchronization/OFFLINE_DATA_MANAGEMENT.md` §4, `synchronization/CONFLICT_RESOLUTION.md` §5, `architecture/UNRESOLVED_QUESTIONS.md` UQ-08.

---

### D-18 — Procedural PHP backend retained; Laravel remains optional for future justified use

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-15 |
| Decision owner | Project owner (see UQ-06) |

**Context.** D-03 authorises selective Laravel. Laravel cannot share a PHP file-based session
without a bridge. Three models were evaluated in `BACKEND_COEXISTENCE.md` §4.

**Recommendation.** Consistent with D-03: Laravel remains available as an approved option for
justified future use (e.g. complex reporting or queue orchestration), but is **not adopted without a
meaningful use case**. The Phase 2/3 sync engine and domain logic are built in native PHP 8. If and when
Laravel is introduced for an approved future use case, it will run behind the legacy-PHP authentication gateway
(evaluating HMAC-signed context headers without sharing session files). Until such a justified use case arises,
Laravel remains dormant.

**Why not shared sessions.** A shared session store (DB or Redis) requires changing
`config/session.php`, matching serialisation formats between two frameworks, and re-testing every
authentication assertion. It is more invasive and more fragile than a signed header.

**Why not tokens for v2 only.** Two credential systems means two revocation models, two CSRF
stories, and a user who can be logged in to one surface and not the other. That violates D-03's
"consistent authentication across both".

**Consequences if approved.** Extra loopback hop per Laravel request; a shared secret in `.env`;
the gateway becomes a security-critical file and needs its own tests.

**Affected components.** Backend, deployment, security.
**Related documents.** `architecture/BACKEND_COEXISTENCE.md` §4-§6.

---

### D-19 — Notifications are excluded from the offline sync store

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** Notifications are server-generated, per-user, and pruned after seven days by
`scripts/cleanup_notifications.php`. `reference_id` is polymorphic and not a navigation key
(`FEATURE_INVENTORY.md` §10).

**Decision.** Notifications are fetched live when online, cached in memory only, and are not part
of the Drift store or the change log. `is_read` is written through the existing v1 endpoint.

**Rationale.** Persisting an entity with a seven-day server lifetime creates a class of
guaranteed-stale local data and a second, pointless tombstone stream. Offline users do not need
notifications they cannot act on.

**Consequences.** The bell shows "unavailable offline" rather than stale counts.

**Affected components.** Flutter, API.
**Related documents.** `synchronization/OFFLINE_DATA_MANAGEMENT.md` §5.

---

### D-20 — Hard delete plus a tombstone table; no soft-delete columns

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** Two ways to make deletions visible to offline clients: `deleted_at` columns on every
table, or a separate tombstone table.

**Decision.** Keep hard deletes in the operational tables. Record deletions in
`sync_tombstones` (and as `op='delete'` rows in `sync_change_log`).

**Rationale.** Soft-delete columns would force `WHERE deleted_at IS NULL` into **every existing
query** across 40 endpoints — a change with a large, silent regression surface against a frozen
backend. A tombstone table leaves every existing query byte-identical.

**Trade-offs.** No undelete, no built-in audit of deleted content (the change log carries the last
snapshot, retained 90 days).

**Affected components.** Database.
**Related documents.** `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.4.

---

### D-21 — Money crosses the wire as a decimal string; Dart holds integer cents

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** `DECIMAL(12,2)` in MySQL; JSON responses emit `"24.50"` in some endpoints and
`24.5` in others (finding F-06). Dart's `double` cannot represent all 2-decimal values exactly, and
settlement maths sums many values.

**Decision.** In v2 and sync payloads, every monetary value is a JSON **string** with exactly two
decimals (`"24.50"`). The Flutter client parses to `int` cents and performs all arithmetic in
integer cents. v1 responses are unchanged.

**Rationale.** Eliminates float drift in aggregation and makes the wire format unambiguous.

**Consequences.** The client has one money type (`Money(int cents)`) and one formatter. Any
`double` in a money path is a review defect.

**Affected components.** API, Flutter.
**Related documents.** `api/API_CONTRACT_SPECIFICATION.md` §6.3.

---

### D-22 — Guest-data merge into an existing account

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-15 |
| Decision owner | Project owner (see UQ-01) |

**Context.** D-04 defines migration for a **newly created** account. It does not define what
happens when a user with existing guest data signs in to an account they already own, or creates a
second account.

**Options and consequences.** Analysed in `synchronization/GUEST_DATA_MIGRATION.md` §7.

**Recommendation.** Explicit, user-confirmed import with a dry-run preview, two-tier duplicate detection, and preserved guest partition.
Signing into an existing account must **never force discarding local guest data**. If the user declines import,
the guest partition (`account_id = 'guest'`) is preserved on device, allowing the user to return to it upon logout
or import it later from settings.
Monthly warnings alone are insufficient to prevent duplicate financial entries. If import is chosen, a dry-run preview surfaces:
1. Exact UUID duplicates (skipped automatically from re-creation).
2. Heuristic duplicate candidates (matching date, amount, note/category), requiring **mandatory user confirmation** to either import as new or skip.
The import executes as an idempotent batch.

**Why not automatic merge.** Silently injecting a second device's guest expenses into a shared
group ledger changes other members' balances without their knowledge, and silent expense merges cause double-counting.

**Affected components.** Flutter, API, database.
**Related documents.** `synchronization/GUEST_DATA_MIGRATION.md` §7, `architecture/UNRESOLVED_QUESTIONS.md` UQ-01.

---

### D-23 — Account deletion and data-retention policy

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-02) |

**Context.** Finding F-04: `DELETE FROM users` cascades to `expenses.user_id`,
`settlements.payer_id/payee_id/settled_by`, `lists.user_id`, `group_members`, and
`notifications`. Deleting one user therefore destroys other members' financial history.

**Decision & Lifecycle Rules (Owner-Approved).**
1. **Pre-flight balance gate:** Account deletion is strictly blocked if the user has **any unsettled group balance**, whether debit (user owes money) OR credit (user is owed money). All relevant group obligations must be settled before deletion becomes eligible. **Financial forfeiture of credits is unapproved and prohibited.**
2. **Hybrid Group Administrator Succession Procedure (Owner-Approved 2026-11-20):** When an account deletion is initiated, the system identifies every group where the departing user is an administrator. For each group independently:
   - *Co-Administrator Handling:* If a group already has one or more other active co-administrators (`role = 'admin'` where `user_id != departing_admin_id`, `ui.is_deleted = false`, active profile in `user_accounts`, and no in-flight account deletion in `outbox_events`), **succession appointment is bypassed for that group**; existing co-administrator(s) maintain uninterrupted governance. The review summary confirms: `"Co-administrator [Username] already holds admin privileges. Succession not required."`
   - *Manual Option Offered:* If the departing administrator is the sole active administrator, offer the option to manually select their successor from eligible remaining group members.
   - *Automatic Successor Displayed:* Concurrently display the member who would become administrator under the automatic succession rule.
   - *Appoint Manual Selection:* If the administrator explicitly selects someone, appoint that selected member.
   - *Appoint Automatic Successor:* If the administrator skips or declines manual selection, automatically appoint the earliest-joined eligible remaining group member.
   - *Pre-Confirmation Review:* The departing administrator must review the succession outcomes across all their administered groups before confirming account deletion.
   - *Eligibility & Deterministic Selection (Schema Invariants):* Eligible members must be active group members (`user_id != departing_admin_id`, `ui.is_deleted = false`, profile exists in `user_accounts`, `joined_at IS NOT NULL`, `role = 'member'`, and no in-flight deletion in `outbox_events`). Primary ordering is earliest `joined_at ASC`; deterministic tie-breaker is lowest surrogate primary key `gm.id ASC`, guaranteeing 100% deterministic, reproducible selection without hypothetical schema columns.
   - *Zero-Member Group Policy (Owner Decision 2026-11-20 - Option 2 Approved):* If an administered group has no eligible remaining members (the departing administrator is the sole member, or all others have departed/deleted), the system displays this in the pre-confirmation review and **automatically transitions the zero-member group to `ARCHIVED` status** upon account deletion completion (with zero balance verified), preserving its historical ledger under standard archival rules (D-26). Deleting an active group as a substitute for succession remains prohibited.
3. **Personal data purge:** Purely personal records (personal expenses, personal lists, personal budgets, personal notifications) are permanently hard-deleted in one atomic transaction; local cache on device is wiped.
4. **Shared financial preservation & original username attribution:** Shared group expenses, settlements, and confirmations are retained to preserve mathematical balance integrity for surviving members. In historical group records, the user's **original username is preserved** accompanied by a clear context badge: `"This user deleted their account."` (the username is NOT replaced with generic "Deleted User" or a pseudonym).
5. **Immediate session & device revocation:** All active PHP web sessions are destroyed; database sessions (`app_sessions`) deleted; remember-device credentials revoked; all `sync_clients` / `sync_devices` rows marked `is_revoked = true` / `revoked_at = NOW(6)`. In accordance with the standardized security contract, revoked devices are strictly rejected with **HTTP 403 Forbidden (`DEVICE_REVOKED`)** on all sync and v2 endpoints (preventing client interceptors from executing automatic token-refresh loops), while destroyed web sessions produce HTTP 401 Unauthenticated on legacy v1 endpoints.
6. **Group archival lifecycle interaction (D-26):** Historical group records and deleted users' original usernames are preserved during the normal group lifecycle, including archival. Once a group has completed six full months in the archived state, its administrator may independently initiate a permanent-deletion request (D-26). If executed, all group records are permanently purged.

**Affected components.** Database, API, Flutter, Security.
**Related documents.** `database/DATA_OWNERSHIP.md` §9, `security/SECURITY_ARCHITECTURE.md` §9, `architecture/UNRESOLVED_QUESTIONS.md` UQ-02, D-26.

---

### D-24 — Session lifetime for native clients

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-03) |

**Context.** Legacy session lifetime was 24 hours absolute from `login_time` (`api/middleware/auth.php:82-101`), causing frequent forced logouts on mobile.

**Decision (Option C Approved).**
1. **Web Sessions:** Retain standard 7-day inactivity sliding session cookie (`SameSite=Lax`, `HttpOnly`, `Secure`).
2. **Native Mobile/Desktop Clients:** Implement a separate remember-device credential mechanism (D-33). Native clients store high-entropy refresh tokens in OS hardware-backed secure storage (Android KeyStore, iOS Keychain, Windows DPAPI, macOS Keychain, Linux libsecret) with a 30-day inactivity limit. No 30-day sliding PHP cookie is used.
3. **Database-backed Revocation Coupling:** Fully coupled with D-25 (`app_sessions`). Revoking a device destroys active sessions across both legacy v1 and modern v2 endpoints immediately.

**Affected components.** API, Flutter, Security.
**Related documents.** `security/SESSION_MANAGEMENT.md` §4, `architecture/UNRESOLVED_QUESTIONS.md` UQ-03, `architecture/ARCHITECTURE_DECISIONS.md` D-33.

---

### D-25 — Device revocation model via database session registry

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-04) |

**Context.** Sessions are stored in PHP session files, making them unenumerable without database tracking. Revocation must terminate both sync and web API access.

**Decision (Option B Approved).**
1. **Full Database-Backed Revocation:** Introduce the `app_sessions` table to track all active web and native sessions.
2. **Scope of Revocation:**
   - **Sync privilege:** `sync_devices.revoked_at` is set, blocking sync endpoints with `403 DEVICE_REVOKED`.
   - **API & Web access:** Linked `app_sessions` rows are deleted, immediately terminating authentication across all 40 legacy v1 HTTP endpoints and modern v2 endpoints.
   - **Remember credentials:** Stored remember-device refresh tokens linked to the device are revoked.
   - **Local data:** Offline client locks local database upon receiving revocation confirmation or reconnection.

**Affected components.** Database, API, Security, Flutter.
**Related documents.** `security/SESSION_MANAGEMENT.md` §6, `security/ACCESS_CONTROL.md` §6, `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.1, `architecture/UNRESOLVED_QUESTIONS.md` UQ-04.

---

### D-26 — Group archival lifecycle and historical expense provenance

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-07, UQ-11) |

**Context.** Finding F-02: Deleting a group cascades away settlement history and orphans group expenses.

**Decision & Architectural Rules (Owner-Approved).**
1. **4-State Archival Lifecycle:** Groups transition through `ACTIVE` → `ARCHIVE_PENDING` → `ARCHIVED` → `PERMANENTLY_ARCHIVED`.
   - **3-Month Restoration Window:** An archived group can be restored to `ACTIVE` by an admin within 3 calendar months (90 days) from the actual archive timestamp (`POST /api/v2/groups/restore.php`).
   - **Permanent Deletion Eligibility:** Only after remaining archived for **six full calendar months** may an administrator independently initiate a permanent-deletion request (`POST /api/v2/groups/permanent_delete.php`). Deletion is **not automatic**.
2. **Permanent-Deletion Workflow Specification:**
   - **Eligibility:** At least 6 full calendar months in `ARCHIVED` state (`archived_at <= NOW() - INTERVAL 6 MONTH`). Early requests rejected with `409 CONFLICT`. Never automatic.
   - **Lifecycle Distinction Confirmed:** `ARCHIVED` remains reversible (within 3 calendar months); `PERMANENTLY_ARCHIVED` cannot be reversed. Permanent deletion becomes eligible six full calendar months after archival.
   - **Authorization (No Member Fallback):** Only a verified group administrator (`role = 'admin'` in `group_members` at archival) can submit a permanent-deletion request with account password confirmation. Requests from regular members, non-members, or former members are strictly rejected with HTTP `403 FORBIDDEN`. There is strictly no last-member fallback.
   - **Scope:** Hard-deletes group container, memberships, lists/items, expenses, settlements, confirmations, and receipts across all 9 data categories. Historical username attribution ceases with record deletion.
   - **Sync Propagation:** Server emits per-member tombstones in `sync_change_log` to all former members, triggering a local cascade purge in downstream Drift databases upon next sync pull.
3. **Expense Provenance Distinction (UQ-11):**
   - User-initiated conversion: Sets `type = 'personal'`, `group_id = NULL`, and logs a `move_out` sync event.
   - Detached group expense (from group deletion): Retains `type = 'group'`, sets `group_id = NULL`, and sets `is_detached = 1`.
   - Database constraint enforced: `CHECK ((type = 'personal' AND group_id IS NULL) OR (type = 'group' AND (group_id IS NOT NULL OR is_detached = 1)))`.

**Affected components.** Database, API, Synchronization.
**Related documents.** `database/DATA_OWNERSHIP.md` §8, `database/DATABASE_SCHEMA_SPECIFICATION.md` §§5.4, 7.3, `architecture/UNRESOLVED_QUESTIONS.md` UQ-07, UQ-11.

---

### D-27 — Sync cursor safety: safety lag plus reconciliation checksum

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** `AUTO_INCREMENT` values are allocated before commit. Transaction A can take
`seq = 100`, transaction B take `seq = 101`, B commit first, and a client that reads at that moment
advances its cursor to 101 — permanently skipping A's change.

**Decision.** Three mitigations, all required:
1. The pull query returns only changes with `seq <= watermark`, where `watermark` is the highest
   `seq` whose row has `changed_at <= now() - 2 s`. Two seconds exceeds the longest expected
   write transaction in this application.
2. The client never advances its cursor past the server-supplied `next_cursor`.
3. Every pull response carries a per-entity `digest` computed by the single canonical algorithm
   defined in `api/SYNC_API_SPECIFICATION.md` §6.5 (scoped to the server-confirmed baseline state in the
   active registered account partition, hashing `{uuid}:{server_version}` for live/edited/pending-delete
   rows while strictly excluding guest records and new unconfirmed drafts). The client compares digests
   during periodic reconciliation and forces a targeted bootstrap of any entity whose digest differs after
   a full pull cycle.

**Cursor validity (part of this decision).** A cursor is placeable only inside
`[oldest_retained_cursor, watermark]`. A **malformed** cursor is `400 INVALID_CURSOR`; a
**well-formed** cursor outside that range — below the retained floor **or above the watermark** — is
`410 CURSOR_TOO_OLD` and forces a bootstrap. A cursor above the watermark is never answered with an
empty page: an empty page would silently hide a `sync_change_log` restore or `seq` rewind. The
canonical state table is `api/SYNC_API_SPECIFICATION.md` §6.4.

**Rationale.** (1) closes the common race, (3) detects the residual case. Together they make the
protocol correct without serialising all writes.

**Trade-offs.** Up to two seconds of sync latency; a small reconciliation cost.

**Affected components.** API, Flutter.
**Related documents.** `api/SYNC_API_SPECIFICATION.md` §6.4 and §6.5,
`synchronization/SYNC_WORKFLOWS.md` §7.

---

### D-28 — Batch synchronization and sync API rate limits

| Field | Value |
|---|---|
| Status | `ACCEPTED` (Rate limits owner-approved; batch limits proposed defaults) |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-05) |

**Context.** The existing rate limiter enforces 60 requests per minute per IP. A sync client pulling and pushing changes requires an appropriate quota model that distinguishes HTTP transport limits from batch payload limits.

**Decision & Quota Model.**
1. **Sync API Request Limits (Owner-Approved):**
   - Dedicated `api_sync` bucket keyed by `user_id`: **120 requests/minute per user**.
   - Burst allowance: **240 requests/minute per device**.
   - Failed requests and backoff retries use adaptive client exponential backoff.
2. **Batch Payload Limits (Proposed Initial Defaults, Subject to Load Testing):**
   - Maximum records per batch: **100 records**.
   - Maximum batch payload size: **1 MB (1,048,576 bytes)**.
   - Configurable via server environment variables (`SYNC_BATCH_MAX_RECORDS`, `SYNC_BATCH_MAX_BYTES`).
   - Oversized batches return HTTP `413 PAYLOAD_TOO_LARGE`.

**Affected components.** API, Security, Synchronization.
**Related documents.** `api/SYNC_API_SPECIFICATION.md` §3, `security/ACCESS_CONTROL.md` §8, `architecture/UNRESOLVED_QUESTIONS.md` UQ-05.

---

### D-29 — No microservices, no Kubernetes, no message broker

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** Sync could be built with an external queue (Kafka/RabbitMQ) or a separate service.

**Decision.** No. One Apache/PHP deployment, one MySQL database, optional Redis. The change log
lives in MySQL; background work uses the existing CLI-worker pattern.

**Rationale.** The dataset is small (a household or small group: tens of users, thousands of
rows). A broker adds an operational dependency that XAMPP/LAMP hosting cannot satisfy and that no
requirement justifies. `DATABASE_AUDIT.md` §7 already establishes that the dormant outbox is not a
sync mechanism.

**Affected components.** Deployment, backend.
**Related documents.** `deployment/DEPLOYMENT_ARCHITECTURE.md` §2.

---

### D-30 — Domain rules are extracted once and reused by v1 and v2

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** Business rules live inline in endpoints. The only shared helper is
`api/settlements/settlement_helpers.php`. v2 write endpoints must enforce the same rules as v1.
Copying them would create two implementations (forbidden by D-03).

**Decision.** Before any v2 write endpoint is implemented, the rules it needs are extracted into
procedural helpers under `api/domain/` (for example `expense_rules.php`,
`settlement_lock.php`, `membership.php`, `money.php`), with characterization tests written
**first** against current behaviour. v1 endpoints are then refactored to call the same helpers.

**Rationale.** A sync endpoint that re-implements the settlement lock will eventually disagree
with the v1 endpoint, and the disagreement will be discovered as a wrong balance.

**Consequences.** This is a backend change requiring its own approved phase; it is a hard
dependency of D5 in the design plan. KD-06 (the missing service layer) is **not** this work — KD-06
is an OOP service extraction, this is narrow procedural rule reuse.

**Affected components.** Backend, tests.
**Related documents.** `architecture/BACKEND_COEXISTENCE.md` §7.

---

### D-31 — The legacy PHP UI remains online-only and unmodified

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** D-05 supersedes "online-first" for the Flutter client. The PHP SPA still exists and
must keep working.

**Decision.** The PHP UI is not given offline capability, is not migrated to the new v2 surface,
and continues to use v1 exactly as documented in `API_CONTRACT.md`. It is retired only at the
cutover defined in `MIGRATION_PRESERVATION_PLAN.md` §7 Phase 6.

**Rationale.** Touching the PHP UI doubles the regression surface for zero product benefit during
migration.

**Narrow Exception to Frozen v1 Surface (Owner-Approved):**
Although v1 endpoints are strictly frozen against status code and response modifications (PR-23), the project owner has specifically approved deprecating the two administrative settlement endpoints (`api/settlements/settle.php` and `api/settlements/settle_all.php`) to return HTTP `410 Gone` (UQ-10 Option B). Dependency verification confirmed that the legacy website UI has zero references to either endpoint and the collaborative 3-step workflow (`calculate.php` → `confirm.php`) covers 100% of legitimate settlement operations.

**Post-UQ-20 Implementation Planning (Both Test Files):**
When implemented following UQ-20 sign-off, **both** existing test suites must be updated in lockstep:
1. `tests/run_tests.php` (Sections 17, 27, 28) to verify HTTP 410 Gone and use multi-session confirmations for test settlements.
2. `scripts/pentest/penetration_test.php` (test cases S07–S11) to assert HTTP 410 Gone on administrative settlement calls.

**Affected components.** Frontend (legacy), deployment, API.
**Related documents.** `architecture/BACKEND_COEXISTENCE.md` §3, `api/API_CONTRACT_SPECIFICATION.md` §2.1, UQ-10.

---

### D-32 — `api/bootstrap.php` stays dead; `config/session.php` remains the session owner

| Field | Value |
|---|---|
| Status | `PROPOSED` |
| Date recorded | 2026-11-15 |
| Decision owner | Architecture work (agent) |

**Context.** KD-02: `api/bootstrap.php` duplicates session hardening and CSP and is included by
zero files. Adding it to endpoints would double-send headers.

**Decision.** Do not activate it. `config/session.php` + `api/middleware/auth.php` remain the
single session/header owner. Deleting `bootstrap.php` is safe but is not part of Phase 2.

**Rationale.** The working path is already centralised; activating the duplicate creates a
header-ordering bug with no upside.

**Affected components.** Backend.
**Related documents.** `TECHNICAL_DEBT.md` KD-02, `security/SESSION_MANAGEMENT.md` §2.

---

---

### D-33 — Separate remember-device credential mechanism for native platforms

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-03) |

**Context.** Native platforms require frictionless re-authentication without relying on long-lived sliding browser cookies.

**Decision.**
Native mobile and desktop clients implement a separate "remember this device" credential mechanism:
1. High-entropy refresh credentials generated upon explicit user login.
2. Stored exclusively in OS hardware-backed secure storage (Android KeyStore, iOS Keychain, Windows DPAPI, macOS Keychain, Linux libsecret).
3. 30-day inactivity expiration window.
4. Seamless token exchange via `POST /api/v2/auth/refresh.php` producing fresh 7-day API session tokens.

**Affected components.** Flutter, API, Security.
**Related documents.** `security/SESSION_MANAGEMENT.md` §4, `architecture/UNRESOLVED_QUESTIONS.md` UQ-03.

---

### D-34 — Mandatory device registration and registry before synchronization

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-17) |

**Context.** Devices must be uniquely identifiable and enumerable to enforce access control, session revocation, and synchronization watermarks.

**Decision.**
1. Every client device must register via `POST /api/v2/devices/register.php` before initiating its first sync pull or push.
2. Server assigns a canonical `device_uuid` and records device metadata (platform, OS version, app version, push token).
3. Device rows link directly to `app_sessions` to support immediate, comprehensive device revocation (D-25).

**Affected components.** API, Database, Synchronization.
**Related documents.** `api/SYNC_API_SPECIFICATION.md` §4, `security/SESSION_MANAGEMENT.md` §6, `architecture/UNRESOLVED_QUESTIONS.md` UQ-17.

---

### D-35 — Account-wide 24-hour reminders with receipt-based schedule reset

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-18) |

**Context.** Multi-device users should not receive redundant expense entry reminders across multiple devices once acknowledged.

**Decision.**
1. A reminder schedule operates on an account-wide 24-hour countdown.
2. Upon receipt and processing of a reminder by any authorized device client, the client calls `POST /api/v2/reminders/ack.php`.
3. Receipt of this acknowledgment resets the 24-hour countdown account-wide across all user devices.
4. The system acknowledges explicit platform-specific delivery constraints (iOS Low Power Mode, Android OEM battery optimizations).

**Affected components.** API, Synchronization, Flutter.
**Related documents.** `api/SYNC_API_SPECIFICATION.md` §5, `architecture/UNRESOLVED_QUESTIONS.md` UQ-18.

---

### D-36 — Mandatory two-way backward compatibility with legacy web interface

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-19) |

**Context.** The existing PHP/JS web SPA remains in active production while Flutter native apps roll out.

**Decision.**
1. Full two-way data compatibility is mandatory.
2. Expenses, lists, and settlements created or updated in the legacy web UI must generate change-log entries and synchronize seamlessly to native Drift clients.
3. Offline sync edits pushed from native clients must be immediately reflected in legacy web queries upon page refresh or fetch.

**Affected components.** API, Database, Frontend (legacy).
**Related documents.** `architecture/TARGET_ARCHITECTURE.md` §5, `architecture/BACKEND_COEXISTENCE.md` §3, `architecture/UNRESOLVED_QUESTIONS.md` UQ-19.

---

### D-37 — Native-first platform release sequencing (Release 1 Mobile/Desktop, Release 2 Web)

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-14) |

**Context.** Deploying to six platforms simultaneously introduces disparate browser, desktop, and mobile validation risks.

**Decision.**
1. **Release 1 (Native First):** Android, iOS, Windows, macOS, and Linux. All five platforms share the identical native Drift SQLite FFI engine, native secure credential storage, and identical sync behaviors.
2. **Release 2 (Flutter Web):** Staged for secondary release, strictly gated by the execution of technical verification spike `A-WEB-1` (CanvasKit strict CSP compliance and IndexedDB storage persistence).
3. **Interim Web Service:** The existing PHP/JS web SPA continues serving web users without interruption during Release 1.

**Affected components.** Deployment, Flutter.
**Related documents.** `deployment/DEPLOYMENT_ARCHITECTURE.md` §4, `architecture/UNRESOLVED_QUESTIONS.md` UQ-14.

---

### D-38 — Guest mode feature restrictions and export prohibition

| Field | Value |
|---|---|
| Status | `ACCEPTED` |
| Date recorded | 2026-11-18 |
| Decision owner | Project owner (see UQ-16) |

**Context.** Guest mode is designed for immediate evaluation of single-user ledger capabilities, not for unauthenticated data extraction or customization.

**Decision.**
1. **Included Features:** Local personal expenses, categories, budgets, personal lists, items, calendar view, and local analytics.
2. **Disabled Features:** Theme customization is disabled (fixed to system theme).
3. **Export Prohibition (Owner-Approved):** CSV, JSON, and PDF data exports are strictly disabled in guest mode. Attempting to export presents an educational prompt (`"Account Required to Export Data"`). Export capabilities unlock only post-registration and data migration.

**Affected components.** Flutter, Security, Synchronization.
**Related documents.** `synchronization/GUEST_DATA_MIGRATION.md` §3.2, `security/SECURITY_ARCHITECTURE.md` §9.3, `architecture/UNRESOLVED_QUESTIONS.md` UQ-16.


## 5. Supersession notes

| Superseded statement | Source | Superseded by | Scope |
|---|---|---|---|
| "Shared data is online-first" | `MIGRATION_PRESERVATION_PLAN.md` §5 | D-05 | Flutter client only; the legacy PHP UI stays online-only |
| "A future sync phase must design IDs, cursors, tombstones, and conflicts explicitly" | `DATABASE_AUDIT.md` §7.3 | D-11, D-12, D-20, D-27 | This phase *is* that design |
| "Guest mode … must never attempt to merge into the server without a future, explicit import design" | `DATABASE_AUDIT.md` §7.3 | D-04, D-22, `GUEST_DATA_MIGRATION.md` | Import design now exists; the existing-account policy is still open |

---

## 6. Decision change procedure

1. Any change to an `ACCEPTED` decision requires a new decision entry that supersedes it; the old
   entry is retained with status `SUPERSEDED`.
2. A `PROPOSED` decision becomes `ACCEPTED` only by explicit project-owner confirmation.
3. A `NEEDS APPROVAL` decision must not be implemented. If implementation is blocked, the
   blocking decision is escalated, not guessed.
4. Every change to this register must update the affected documents in the same commit.
