# Requirements Traceability Matrix

| Field | Value |
|---|---|
| Document status | Consolidated with project-owner decisions (UQ-01 through UQ-19); ready for review |
| Date | 2026-11-18 |
| Purpose | Prove that every requirement has an owner document and a verification method, and expose any requirement the architecture has not addressed |

**How to use this matrix.** A requirement with no authoritative document is a defect in Phase 2. A
requirement with status `NOT ADDRESSED` must be resolved or explicitly descoped before approval.

---

## 1. Status and verification vocabulary

| Status | Meaning |
|---|---|
| `SPECIFIED` | The architecture defines the behaviour precisely |
| `PARTIAL` | Defined, but a named open question or dependency remains |
| `OPEN` | Depends on a `NEEDS APPROVAL` decision (see `UNRESOLVED_QUESTIONS.md`) |
| `PRESERVED` | Existing behaviour, unchanged by this design |
| `DEFERRED` | Deliberately out of Phase 2 scope |
| `NOT ADDRESSED` | **Defect** — must be fixed before approval |

| Verification method | Meaning |
|---|---|
| `DOC-REVIEW` | Cross-document consistency review |
| `CONTRACT-TEST` | Automated test against the API contract |
| `UNIT-TEST` | Automated test of a domain rule |
| `MIGRATION-TEST` | Test run against a disposable database copy |
| `MANUAL-SCENARIO` | Scripted manual walkthrough with expected results |
| `STATIC-CHECK` | Grep/lint/schema inspection |
| `PENTEST` | Security probe suite |
| `PLATFORM-SMOKE` | Build-and-run on a named platform |

---

## 2. Phase 1 preservation requirements (PR)

Origin: `docs/FEATURE_INVENTORY.md`, `docs/MIGRATION_PRESERVATION_PLAN.md` §3.

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| PR-01 | Settlement maths uses `paid_by`, never `user_id` or `created_by` | `PRESERVED` | API, DB | `database/DATA_OWNERSHIP.md` §4; `database/VERSIONING_AND_CONFLICT_RESOLUTION.md` §7 | `UNIT-TEST`, `CONTRACT-TEST` |
| PR-02 | Normal settlement aggregates exclude `is_post_settlement = 1` | `PRESERVED` | API, DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §7.2 | `UNIT-TEST` |
| PR-03 | A group expense dated on/before the latest `period_end` is rejected on create/update/delete/price | `PRESERVED` | API | `synchronization/CONFLICT_RESOLUTION.md` §5.2 | `CONTRACT-TEST` |
| PR-04 | A period closes only when all active members confirm | `PRESERVED` | API, DB | `database/VERSIONING_AND_CONFLICT_RESOLUTION.md` §7.2 | `CONTRACT-TEST` |
| PR-05 | `confirm.php` keeps its transaction + `SELECT … FOR UPDATE` | `PRESERVED` | API | `api/SYNC_API_SPECIFICATION.md` §5.1 | `STATIC-CHECK`, `CONTRACT-TEST` |
| PR-06 | Late expenses use the post-settlement flow only | `PRESERVED` | API | `database/DATABASE_SCHEMA_SPECIFICATION.md` §7.2 | `CONTRACT-TEST` |
| PR-07 | `settle.php` / `settle_all.php` deprecated (HTTP 410 Gone; UQ-10 Option B) | `SPECIFIED` | API | `api/API_CONTRACT_SPECIFICATION.md` §2.1, `security/ACCESS_CONTROL.md` §4 | `CONTRACT-TEST` |
| PR-08 | `0.00` marker settlements remain valid and advance the lock | `PRESERVED` | DB, API | `database/DATABASE_SCHEMA_SPECIFICATION.md` §3.11 | `MIGRATION-TEST` |
| PR-09 | Personal expenses are owner-only for edit/delete | `PRESERVED` | API | `database/DATA_OWNERSHIP.md` §4.1 | `CONTRACT-TEST` |
| PR-10 | Group expenses are admin-only for edit/delete | `PRESERVED` | API | `database/DATA_OWNERSHIP.md` §4.2 | `CONTRACT-TEST` |
| PR-11 | A personal expense never carries a `group_id` | `PRESERVED` | API, DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §7.3 | `CONTRACT-TEST` |
| PR-12 | A priced list item creates exactly one linked expense | `PRESERVED` | API, DB | `synchronization/OFFLINE_DATA_MANAGEMENT.md` §6 | `CONTRACT-TEST` |
| PR-13 | Uncheck deletes by exact `expense_id`, never by note/date/amount | `PRESERVED` | API | `synchronization/OFFLINE_DATA_MANAGEMENT.md` §6 | `CONTRACT-TEST` |
| PR-14 | Uncheck is allowed only within 600 seconds | `PRESERVED` | API | `api/API_CONTRACT_SPECIFICATION.md` §7 | `CONTRACT-TEST` |
| PR-15 | Group check returns `needs_confirm` and requires `paid_by` | `PRESERVED` | API | `api/API_CONTRACT_SPECIFICATION.md` §7 | `CONTRACT-TEST` |
| PR-16 | `checked_at` is converted to a date before use as `expense_date` | `PRESERVED` | API | `api/API_CONTRACT_SPECIFICATION.md` §7 | `UNIT-TEST` |
| PR-17 | Max 5 groups per user; max 10 members per group | `PRESERVED` | API | `database/DATA_OWNERSHIP.md` §6 | `CONTRACT-TEST` |
| PR-18 | Notification `reference_id` is never a navigation key | `PRESERVED` | API, Flutter | `api/API_CONTRACT_SPECIFICATION.md` §9 | `DOC-REVIEW` |
| PR-19 | Raw text stored; escaping at render time only | `PRESERVED` | API, Flutter | `security/SECURITY_ARCHITECTURE.md` §7 | `CONTRACT-TEST` |
| PR-20 | Money is rounded to 2 dp and bounded 0.01…99 999 999.00 | `PRESERVED` | API, Flutter | `api/API_CONTRACT_SPECIFICATION.md` §6.3 | `UNIT-TEST` |
| PR-21 | Success is determined by `ok`, not HTTP 200 | `PRESERVED` | API, Flutter | `api/API_ERROR_HANDLING.md` §3 | `CONTRACT-TEST` |
| PR-22 | `logout.php` keeps current unguarded behaviour until an approved change | `PRESERVED` | API | `security/SESSION_MANAGEMENT.md` §7 | `DOC-REVIEW` |
| PR-23 | The legacy PHP UI keeps working unchanged | `PRESERVED` | Legacy frontend | `architecture/BACKEND_COEXISTENCE.md` §3 | `MANUAL-SCENARIO` |
| PR-24 | The 538-test suite result stays at 433/105/538 unless an approved change moves it | `PRESERVED` | Tests | `docs/TEST_BASELINE.md` | `CONTRACT-TEST` |
| PR-25 | No feature in `FEATURE_INVENTORY.md` is removed or silently simplified | `SPECIFIED` | All | `architecture/TARGET_ARCHITECTURE.md` §8 | `DOC-REVIEW` |

---

## 3. Confirmed decisions (D)

Origin: project owner, §4 of the Phase 2 brief.

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| D-01 | Flutter + Dart is the frontend for six platforms; platform support is verified not assumed | `SPECIFIED` | Flutter | `architecture/FLUTTER_ARCHITECTURE.md` §2, §10 | `PLATFORM-SMOKE`, `DOC-REVIEW` |
| D-02 | PHP backend and MySQL retained; MySQL is the central registered-user store | `SPECIFIED` | Backend, DB | `architecture/TARGET_ARCHITECTURE.md` §3 | `DOC-REVIEW` |
| D-03 | Laravel selective; one auth model; no duplicate business logic | `SPECIFIED` | Backend | `architecture/BACKEND_COEXISTENCE.md` §4-§6 | `DOC-REVIEW` (resolved by UQ-06, D-18) |
| D-04 | Guest mode limited and local-only; migration verifies before deleting | `SPECIFIED` | Flutter, API | `synchronization/GUEST_DATA_MIGRATION.md` | `MANUAL-SCENARIO` |
| D-05 | Multi-device sync with offline create/modify/delete and conflict resolution | `SPECIFIED` | All | `synchronization/SYNCHRONIZATION_ARCHITECTURE.md` | `CONTRACT-TEST` |
| D-06 | Version-based optimistic concurrency; timestamps not sole proof | `SPECIFIED` | DB, API | `database/VERSIONING_AND_CONFLICT_RESOLUTION.md` | `CONTRACT-TEST` |
| D-07 | Documentation-first; `docs/` is authoritative; no implementation pre-approval | `SPECIFIED` | All | `docs/README.md` | `DOC-REVIEW` |

---

## 4. Guest access and migration (GM)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| GM-01 | Guest mode makes no API request of any kind | `SPECIFIED` | Flutter | `synchronization/GUEST_DATA_MIGRATION.md` §3.1 | `MANUAL-SCENARIO` |
| GM-02 | Guest feature package is explicitly enumerated | `SPECIFIED` | Flutter | `synchronization/GUEST_DATA_MIGRATION.md` §3.2 | `DOC-REVIEW` (resolved by UQ-16, D-38) |
| GM-03 | Guest users cannot reach registered-only functionality | `SPECIFIED` | Flutter | `security/ACCESS_CONTROL.md` §6 | `MANUAL-SCENARIO` |
| GM-04 | Guest data is device-local and isolated from any account | `SPECIFIED` | Flutter | `synchronization/OFFLINE_DATA_MANAGEMENT.md` §3 | `MANUAL-SCENARIO` |
| GM-05 | Registration becomes mandatory at the registered boundary | `SPECIFIED` | Flutter | `synchronization/GUEST_DATA_MIGRATION.md` §4 | `MANUAL-SCENARIO` |
| GM-06 | Guest data migrates into a **new** account with relationships preserved | `SPECIFIED` | API, DB | `synchronization/GUEST_DATA_MIGRATION.md` §5 | `CONTRACT-TEST` |
| GM-07 | Migration is idempotent under retry and duplicate submission | `SPECIFIED` | API, DB | `synchronization/GUEST_DATA_MIGRATION.md` §6.3 | `CONTRACT-TEST` |
| GM-08 | Migration verifies success before local guest data is deleted | `SPECIFIED` | Flutter, API | `synchronization/GUEST_DATA_MIGRATION.md` §6.5 | `MANUAL-SCENARIO` |
| GM-09 | Guest data is never silently discarded on failure | `SPECIFIED` | Flutter | `synchronization/GUEST_DATA_MIGRATION.md` §6.6 | `MANUAL-SCENARIO` |
| GM-10 | Interrupted migration is resumable without duplication | `SPECIFIED` | Flutter, API | `synchronization/GUEST_DATA_MIGRATION.md` §6.4 | `CONTRACT-TEST` |
| GM-11 | Existing-account import is documented as a separate policy | `SPECIFIED` | Product | `synchronization/GUEST_DATA_MIGRATION.md` §7 | `DOC-REVIEW` (resolved by UQ-01, D-22) |
| GM-12 | Partial migration is representable and reported to the user | `SPECIFIED` | API, Flutter | `synchronization/GUEST_DATA_MIGRATION.md` §6.4 | `CONTRACT-TEST` |

---

## 5. Multi-device and synchronisation (SY)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| SY-01 | A user can read their data from multiple devices | `SPECIFIED` | All | `synchronization/SYNCHRONIZATION_ARCHITECTURE.md` §3 | `CONTRACT-TEST` |
| SY-02 | Records can be created offline | `SPECIFIED` | Flutter | `synchronization/OFFLINE_DATA_MANAGEMENT.md` §4 | `MANUAL-SCENARIO` |
| SY-03 | Records can be modified offline | `SPECIFIED` | Flutter | `synchronization/OFFLINE_DATA_MANAGEMENT.md` §4 | `MANUAL-SCENARIO` |
| SY-04 | Records can be deleted offline | `SPECIFIED` | Flutter | `synchronization/OFFLINE_DATA_MANAGEMENT.md` §4.4 | `MANUAL-SCENARIO` |
| SY-05 | Pending changes persist across app restarts | `SPECIFIED` | Flutter | `synchronization/OFFLINE_DATA_MANAGEMENT.md` §5 | `MANUAL-SCENARIO` |
| SY-06 | Pending changes upload on reconnect, in dependency order | `SPECIFIED` | Flutter, API | `synchronization/SYNC_WORKFLOWS.md` §4 | `CONTRACT-TEST` |
| SY-07 | Changes from other devices are retrieved incrementally | `SPECIFIED` | API | `api/SYNC_API_SPECIFICATION.md` §6 | `CONTRACT-TEST` |
| SY-08 | Concurrent modification is detected | `SPECIFIED` | DB, API | `database/VERSIONING_AND_CONFLICT_RESOLUTION.md` §4 | `CONTRACT-TEST` |
| SY-09 | Conflicts are resolved by a defined per-entity policy | `SPECIFIED` | API, Flutter | `synchronization/CONFLICT_RESOLUTION.md` §3 | `CONTRACT-TEST` |
| SY-10 | Deletions propagate to offline devices | `SPECIFIED` | DB, API | `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.4 | `CONTRACT-TEST` |
| SY-11 | Interrupted sync recovers without loss or duplication | `SPECIFIED` | Flutter, API | `synchronization/SYNC_WORKFLOWS.md` §8 | `CONTRACT-TEST` |
| SY-12 | Failed operations retry safely | `SPECIFIED` | Flutter, API | `synchronization/SYNC_WORKFLOWS.md` §9 | `CONTRACT-TEST` |
| SY-13 | Sync status is visible to the user | `SPECIFIED` | Flutter | `architecture/FLUTTER_ARCHITECTURE.md` §8 | `MANUAL-SCENARIO` |
| SY-14 | Sync never silently violates a settlement or ownership rule | `SPECIFIED` | API | `synchronization/CONFLICT_RESOLUTION.md` §5 | `CONTRACT-TEST` |
| SY-15 | A device cannot sync another user's data by manipulating IDs | `SPECIFIED` | API | `security/ACCESS_CONTROL.md` §7 | `PENTEST` |
| SY-16 | Initial bootstrap is bounded and resumable | `SPECIFIED` | API, Flutter | `api/SYNC_API_SPECIFICATION.md` §6.2 | `CONTRACT-TEST` |
| SY-17 | Cursor cannot advance past a change the client has not applied | `SPECIFIED` | Flutter | `synchronization/SYNC_WORKFLOWS.md` §7.3 | `CONTRACT-TEST` |
| SY-18 | Sync cursor gap hazard is mitigated and detected | `SPECIFIED` | API | `api/SYNC_API_SPECIFICATION.md` §6.4 | `CONTRACT-TEST` |
| SY-19 | Group membership change triggers targeted bootstrap or purge | `SPECIFIED` | Flutter | `synchronization/SYNC_WORKFLOWS.md` §6 | `CONTRACT-TEST` |
| SY-20 | Clock skew never determines record ordering | `SPECIFIED` | All | `database/VERSIONING_AND_CONFLICT_RESOLUTION.md` §6 | `UNIT-TEST` |

---

## 6. Versioning and conflict (VC)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| VC-01 | Every syncable row carries a server-maintained version | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §4 | `MIGRATION-TEST` |
| VC-02 | Version increments on every update regardless of writer | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.6 | `MIGRATION-TEST` |
| VC-03 | Push carries `base_version`; mismatch is a conflict | `SPECIFIED` | API | `api/SYNC_API_SPECIFICATION.md` §4.7.1 | `CONTRACT-TEST` |
| VC-04 | Conflict response contains both versions | `SPECIFIED` | API | `api/SYNC_API_SPECIFICATION.md` §4.7.2 | `CONTRACT-TEST` |
| VC-05 | Last-write-wins is applied only where justified | `SPECIFIED` | API | `synchronization/CONFLICT_RESOLUTION.md` §4 | `DOC-REVIEW` |
| VC-06 | Financial records never auto-merge | `SPECIFIED` | API, Flutter | `synchronization/CONFLICT_RESOLUTION.md` §5 | `CONTRACT-TEST` |
| VC-07 | Settlement and confirmation rows are server-only | `SPECIFIED` | API | `synchronization/CONFLICT_RESOLUTION.md` §5.1 | `CONTRACT-TEST` |
| VC-08 | Deletion is version-checked (a stale delete cannot erase a newer edit) | `SPECIFIED` | API | `api/SYNC_API_SPECIFICATION.md` §4.7.3 | `CONTRACT-TEST` |
| VC-09 | Out-of-order changes are applied idempotently | `SPECIFIED` | Flutter | `synchronization/SYNC_WORKFLOWS.md` §7.4 | `CONTRACT-TEST` |
| VC-10 | Duplicate push requests are safe | `SPECIFIED` | API, DB | `api/SYNC_API_SPECIFICATION.md` §8 | `CONTRACT-TEST` |
| VC-11 | Conflict records are retained for diagnosis | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.5 | `MIGRATION-TEST` |

---

## 7. Database (DB)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| DB-01 | Stable record identity independent of the server sequence | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §3.1 | `MIGRATION-TEST` |
| DB-02 | Ownership is explicit on every entity | `SPECIFIED` | DB | `database/DATA_OWNERSHIP.md` | `DOC-REVIEW` |
| DB-03 | Device registration is persisted | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.1 | `MIGRATION-TEST` |
| DB-04 | Change tracking is ordered and queryable | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.2 | `MIGRATION-TEST` |
| DB-05 | Tombstones persist beyond change-log retention | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.4 | `MIGRATION-TEST` |
| DB-06 | Durable idempotency for sync batches | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.7 | `MIGRATION-TEST` |
| DB-07 | Guest import is tracked per batch and per record | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.8 | `MIGRATION-TEST` |
| DB-08 | Every schema change documents ten impact answers | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §6 | `DOC-REVIEW` |
| DB-09 | Migration sequence is additive and rollback-capable | `SPECIFIED` | DB | `database/DATABASE_MIGRATION_STRATEGY.md` §4 | `MIGRATION-TEST` |
| DB-10 | Old application versions keep working during migration | `SPECIFIED` | DB, API | `database/DATABASE_MIGRATION_STRATEGY.md` §6 | `MIGRATION-TEST` |
| DB-11 | Existing queries are not modified by the sync schema | `SPECIFIED` | DB | `database/DATABASE_MIGRATION_STRATEGY.md` §5 | `STATIC-CHECK` |
| DB-12 | Required indexes are specified with justification | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §8 | `STATIC-CHECK` |
| DB-13 | Transaction boundaries are defined per write path | `SPECIFIED` | DB, API | `api/SYNC_API_SPECIFICATION.md` §9 | `CONTRACT-TEST` |
| DB-14 | `type`/`group_id` consistency remediation is decided | `SPECIFIED` | DB | `database/DATABASE_SCHEMA_SPECIFICATION.md` §7.3 | `DOC-REVIEW` (resolved by UQ-11, D-26) |

---

## 8. API (AP)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| AP-01 | Existing v1 endpoints keep their exact contracts | `PRESERVED` | API | `api/API_CONTRACT_SPECIFICATION.md` §2 | `CONTRACT-TEST` |
| AP-02 | New capabilities live on a versioned v2 surface | `SPECIFIED` | API | `api/API_VERSIONING.md` §3 | `DOC-REVIEW` |
| AP-03 | Response envelope rules are documented for both generations | `PRESERVED` | API | `api/API_ERROR_HANDLING.md` §2 | `CONTRACT-TEST` |
| AP-04 | Every proposed endpoint specifies all fifteen required attributes | `SPECIFIED` | API | `api/SYNC_API_SPECIFICATION.md` §4 | `DOC-REVIEW` |
| AP-05 | Pagination ceilings are preserved and sync uses cursors | `SPECIFIED` | API | `api/API_ARCHITECTURE.md` §7 | `CONTRACT-TEST` |
| AP-06 | Idempotency semantics are defined for both surfaces | `SPECIFIED` | API | `api/SYNC_API_SPECIFICATION.md` §8 | `CONTRACT-TEST` |
| AP-07 | Error codes are stable and machine-readable in v2 | `SPECIFIED` | API | `api/API_ERROR_HANDLING.md` §4 | `CONTRACT-TEST` |
| AP-08 | Rate limiting is defined per surface | `SPECIFIED` | API | `api/API_ARCHITECTURE.md` §8 | `CONTRACT-TEST` |
| AP-09 | CSRF requirements are identical on both surfaces | `SPECIFIED` | API | `api/AUTHENTICATION_AND_AUTHORIZATION.md` §5 | `CONTRACT-TEST` |
| AP-10 | Session bootstrap is available to non-browser clients | `SPECIFIED` | API | `api/SYNC_API_SPECIFICATION.md` §4.1 | `CONTRACT-TEST` |
| AP-11 | Validation rules are shared, not re-implemented | `SPECIFIED` | API | `api/API_CONTRACT_SPECIFICATION.md` §6 | `UNIT-TEST` |
| AP-12 | API versioning and deprecation policy is stated | `SPECIFIED` | API | `api/API_VERSIONING.md` §6 | `DOC-REVIEW` |

---

## 9. Authentication, authorisation, ownership (AA)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| AA-01 | Every protected endpoint requires an authenticated session | `PRESERVED` | API | `api/AUTHENTICATION_AND_AUTHORIZATION.md` §3 | `CONTRACT-TEST` |
| AA-02 | There is no anonymous API access, including for guest mode | `PRESERVED` | API | `security/ACCESS_CONTROL.md` §6 | `PENTEST` |
| AA-03 | CSRF is required on every write except `logout.php` | `PRESERVED` | API | `api/AUTHENTICATION_AND_AUTHORIZATION.md` §5 | `CONTRACT-TEST` |
| AA-04 | Cookie-session auth works on all six platforms | `SPECIFIED` | Flutter, API | `security/SESSION_MANAGEMENT.md` §3 | `PLATFORM-SMOKE` |
| AA-05 | Session expiry, renewal, and 401 handling are defined | `SPECIFIED` | API, Flutter | `security/SESSION_MANAGEMENT.md` §4 | `CONTRACT-TEST` |
| AA-06 | Multi-device sessions are supported without a second auth system | `SPECIFIED` | API | `security/SESSION_MANAGEMENT.md` §5 | `DOC-REVIEW` |
| AA-07 | Device registration and revocation are specified | `SPECIFIED` | API, DB | `security/SESSION_MANAGEMENT.md` §6 | `DOC-REVIEW`, `CONTRACT-TEST` (resolved by UQ-04/D-25, UQ-17/D-34) |
| AA-08 | Ownership is defined for all twelve entity groups | `SPECIFIED` | All | `database/DATA_OWNERSHIP.md` | `DOC-REVIEW` |
| AA-09 | Creator and owner are distinguished where they differ | `SPECIFIED` | All | `database/DATA_OWNERSHIP.md` §3 | `DOC-REVIEW` |
| AA-10 | Access after leaving a group is defined | `SPECIFIED` | API | `database/DATA_OWNERSHIP.md` §7 | `CONTRACT-TEST` |
| AA-11 | Account deletion and retention are defined | `SPECIFIED` | All | `database/DATA_OWNERSHIP.md` §9 | `DOC-REVIEW` (resolved by UQ-02, D-23 with owner clarification) |
| AA-12 | Disabled/disabled-account behaviour is defined | `SPECIFIED` | All | `database/DATA_OWNERSHIP.md` §10 | `DOC-REVIEW` (resolved by UQ-02, D-23) |
| AA-13 | Ownership metadata is kept separate from sync metadata | `SPECIFIED` | DB | `database/DATA_OWNERSHIP.md` §2 | `DOC-REVIEW` |

---

## 10. Security (SE)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| SE-01 | Transport security requirements are stated per platform | `SPECIFIED` | Deployment | `security/SECURITY_ARCHITECTURE.md` §4 | `DOC-REVIEW` |
| SE-02 | Local data at rest is protected where the platform allows | `SPECIFIED` | Flutter | `security/SECURITY_ARCHITECTURE.md` §5 | `PLATFORM-SMOKE` |
| SE-03 | Credentials and tokens are stored in platform secure storage | `SPECIFIED` | Flutter | `security/SECURITY_ARCHITECTURE.md` §5.3 | `PLATFORM-SMOKE` |
| SE-04 | SQL injection prevention is preserved (prepared statements only) | `PRESERVED` | API | `security/SECURITY_ARCHITECTURE.md` §7.1 | `STATIC-CHECK` |
| SE-05 | Input validation is server-side and shared | `SPECIFIED` | API | `api/API_CONTRACT_SPECIFICATION.md` §6 | `UNIT-TEST` |
| SE-06 | Sync authorisation never trusts client-supplied ownership | `SPECIFIED` | API | `security/ACCESS_CONTROL.md` §7 | `PENTEST` |
| SE-07 | Sensitive data is not logged | `SPECIFIED` | API, Flutter | `security/SECURITY_ARCHITECTURE.md` §8 | `DOC-REVIEW` |
| SE-08 | Rate limiting covers every surface including sync | `SPECIFIED` | API | `api/API_ARCHITECTURE.md` §8 | `CONTRACT-TEST` |
| SE-09 | Device compromise and stolen-device behaviour is defined | `SPECIFIED` | Flutter, API | `security/SECURITY_ARCHITECTURE.md` §6 | `DOC-REVIEW` |
| SE-10 | Guest data isolation is enforced | `SPECIFIED` | Flutter | `security/ACCESS_CONTROL.md` §6 | `MANUAL-SCENARIO` |
| SE-11 | Data export is defined or explicitly excluded | `SPECIFIED` | Flutter | `security/SECURITY_ARCHITECTURE.md` §9.3 | `DOC-REVIEW` |
| SE-12 | New attack surface introduced by sync is enumerated | `SPECIFIED` | All | `security/SECURITY_ARCHITECTURE.md` §10 | `PENTEST` |
| SE-13 | Existing security controls are not weakened by the new design | `SPECIFIED` | All | `security/SECURITY_ARCHITECTURE.md` §3 | `DOC-REVIEW` |

---

## 11. PHP/Laravel coexistence (CO)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| CO-01 | Procedural PHP remains valid where suitable | `SPECIFIED` | Backend | `architecture/BACKEND_COEXISTENCE.md` §2 | `DOC-REVIEW` |
| CO-02 | Laravel is justified per module, not globally | `SPECIFIED` | Backend | `architecture/BACKEND_COEXISTENCE.md` §5 | `DOC-REVIEW` |
| CO-03 | One authentication boundary across both | `SPECIFIED` | Backend | `architecture/BACKEND_COEXISTENCE.md` §4 | `DOC-REVIEW` (resolved by UQ-06, D-18) |
| CO-04 | Shared database access with one source of truth | `SPECIFIED` | Backend | `architecture/BACKEND_COEXISTENCE.md` §4.2 | `DOC-REVIEW` |
| CO-05 | No duplicate business logic | `SPECIFIED` | Backend | `architecture/BACKEND_COEXISTENCE.md` §6 | `DOC-REVIEW` |
| CO-06 | Module migration is incremental and reversible | `SPECIFIED` | Backend | `architecture/BACKEND_COEXISTENCE.md` §8 | `DOC-REVIEW` |
| CO-07 | No gateway/microservice is assumed necessary | `SPECIFIED` | Backend | `architecture/BACKEND_COEXISTENCE.md` §4.3 | `DOC-REVIEW` |

---

## 12. Flutter and cross-platform (FL)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| FL-01 | Layered architecture is defined and enforced | `SPECIFIED` | Flutter | `architecture/FLUTTER_ARCHITECTURE.md` §3 | `DOC-REVIEW` |
| FL-02 | Every significant package is justified with alternatives | `SPECIFIED` | Flutter | `architecture/TECHNOLOGY_STACK.md` §4 | `DOC-REVIEW` |
| FL-03 | Platform support is verified per feature, not assumed | `SPECIFIED` | Flutter | `architecture/FLUTTER_ARCHITECTURE.md` §10 | `PLATFORM-SMOKE` |
| FL-04 | Local persistence choice is justified | `SPECIFIED` | Flutter | `architecture/FLUTTER_ARCHITECTURE.md` §6 | `DOC-REVIEW` |
| FL-05 | Auth, guest, and sync state are represented explicitly | `SPECIFIED` | Flutter | `architecture/FLUTTER_ARCHITECTURE.md` §8 | `DOC-REVIEW` |
| FL-06 | Error handling reproduces v1 semantics | `SPECIFIED` | Flutter | `architecture/FLUTTER_ARCHITECTURE.md` §7 | `CONTRACT-TEST` |
| FL-07 | Charts, PDF, theme, sorting are reimplemented locally | `SPECIFIED` | Flutter | `architecture/FLUTTER_ARCHITECTURE.md` §9 | `MANUAL-SCENARIO` |
| FL-08 | Flutter Web deployment constraint is documented | `SPECIFIED` | Deployment | `deployment/DEPLOYMENT_ARCHITECTURE.md` §4 | `PLATFORM-SMOKE` |
| FL-09 | Background sync limits per platform are stated honestly | `SPECIFIED` | Flutter | `architecture/FLUTTER_ARCHITECTURE.md` §10.4 | `DOC-REVIEW` |
| FL-10 | Web as a first-class target is decided | `SPECIFIED` | Product | `deployment/DEPLOYMENT_ARCHITECTURE.md` §4.3 | `DOC-REVIEW` (resolved by UQ-14, D-37) |

---

## 13. Deployment and operations (DP)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| DP-01 | Development, test, staging, production are defined | `SPECIFIED` | Deployment | `deployment/DEPLOYMENT_ARCHITECTURE.md` §3 | `DOC-REVIEW` |
| DP-02 | API base URL is environment-configured, not hardcoded | `SPECIFIED` | Flutter | `deployment/DEPLOYMENT_ARCHITECTURE.md` §5 | `DOC-REVIEW` |
| DP-03 | Same-origin hosting is the baseline for web | `SPECIFIED` | Deployment | `deployment/DEPLOYMENT_ARCHITECTURE.md` §4 | `DOC-REVIEW` |
| DP-04 | HTTPS is mandatory outside local development | `SPECIFIED` | Deployment | `deployment/DEPLOYMENT_ARCHITECTURE.md` §6 | `DOC-REVIEW` |
| DP-05 | Database backup and recovery are specified | `SPECIFIED` | Deployment | `deployment/DEPLOYMENT_ARCHITECTURE.md` §8 | `DOC-REVIEW` |
| DP-06 | Logging and monitoring cover sync health | `SPECIFIED` | Deployment | `deployment/DEPLOYMENT_ARCHITECTURE.md` §9 | `DOC-REVIEW` |
| DP-07 | Laravel deployment requirements are stated if adopted | `SPECIFIED` | Deployment | `deployment/DEPLOYMENT_ARCHITECTURE.md` §7 | `DOC-REVIEW` |
| DP-08 | No infrastructure beyond LAMP/MySQL/optional Redis | `SPECIFIED` | Deployment | `deployment/DEPLOYMENT_ARCHITECTURE.md` §2 | `DOC-REVIEW` |

---

## 14. Testing (TS)

| ID | Requirement | Status | Components | Authoritative doc | Verification |
|---|---|---|---|---|---|
| TS-01 | Migration tests cover forward, backfill, and rollback | `SPECIFIED` | DB | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §4 | `MIGRATION-TEST` |
| TS-02 | API contract tests cover v1 and v2 | `SPECIFIED` | API | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §5 | `CONTRACT-TEST` |
| TS-03 | Auth and authorisation tests cover every role boundary | `SPECIFIED` | API | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §6 | `CONTRACT-TEST` |
| TS-04 | Guest-mode and guest-migration tests include failure injection | `SPECIFIED` | Flutter, API | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §7 | `CONTRACT-TEST` |
| TS-05 | Offline storage tests cover restart and corruption | `SPECIFIED` | Flutter | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §8 | `MANUAL-SCENARIO` |
| TS-06 | Sync tests cover convergence under concurrent edits | `SPECIFIED` | All | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §9 | `CONTRACT-TEST` |
| TS-07 | Conflict tests cover every policy branch | `SPECIFIED` | All | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §10 | `CONTRACT-TEST` |
| TS-08 | Multi-device tests use two or more independent clients | `SPECIFIED` | All | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §11 | `MANUAL-SCENARIO` |
| TS-09 | Idempotency tests cover replay and payload mismatch | `SPECIFIED` | API | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §12 | `CONTRACT-TEST` |
| TS-10 | Security tests cover the new sync surface | `SPECIFIED` | API | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §13 | `PENTEST` |
| TS-11 | Cross-platform smoke tests are defined per platform | `SPECIFIED` | Flutter | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §14 | `PLATFORM-SMOKE` |
| TS-12 | Existing feature regression tests are preserved unchanged | `SPECIFIED` | Tests | `testing/ARCHITECTURE_VALIDATION_PLAN.md` §3 | `CONTRACT-TEST` |

**Case counts are not tracked here.** This table traces *requirements* to a document and a method; it
does not enumerate test cases. The measured inventory is **212 unique `T-` IDs**, itemised per prefix
in `testing/ARCHITECTURE_VALIDATION_PLAN.md` §15.5 and summarised in
`WORK/PHASE_2_READINESS_REPORT.md` §G.2. The 12-step platform smoke matrix is a per-platform
checklist for `T-WEB-01…05`, **not** twelve test cases, and must never be added to the 189.

---

## 15. Coverage summary

| Section | Requirements | `SPECIFIED`/`PRESERVED` | `OPEN` | `NOT ADDRESSED` |
|---|---:|---:|---:|---:|
| PR — Phase 1 preservation | 25 | 25 | 0 | **0** |
| D — Confirmed decisions | 7 | 7 | 0 | **0** |
| GM — Guest | 12 | 12 | 0 | **0** |
| SY — Synchronisation | 20 | 20 | 0 | **0** |
| VC — Versioning/conflict | 11 | 11 | 0 | **0** |
| DB — Database | 14 | 14 | 0 | **0** |
| AP — API | 12 | 12 | 0 | **0** |
| AA — Auth/ownership | 13 | 13 | 0 | **0** |
| SE — Security | 13 | 13 | 0 | **0** |
| CO — Coexistence | 7 | 7 | 0 | **0** |
| FL — Flutter | 10 | 10 | 0 | **0** |
| DP — Deployment | 8 | 8 | 0 | **0** |
| TS — Testing | 12 | 12 | 0 | **0** |
| **Total** | **164** | **164** | **0** | **0** |

**All 164 requirements are fully specified or preserved.** All architectural questions (UQ-01 through UQ-19) have received authoritative owner decisions or provisional approvals. UQ-10 Option B deprecation is approved conditional on dependency verification; UQ-20 is explicitly NOT APPROVED. Zero requirements remain PARTIAL, OPEN, or NOT ADDRESSED.

---

## 16. Requirements that were checked and deliberately descoped

| Candidate requirement | Why it is not in the architecture |
|---|---|
| Push notifications for sync conflicts | No push infrastructure exists and none is justified (D-29); conflicts surface in-app |
| Real-time collaborative editing | No requirement; the product is a ledger, not a document editor |
| Server-side PDF/export endpoint | `FEATURE_INVENTORY.md` AN-4/AN-5 are frontend-only; adding an endpoint invents a feature |
| Full audit log of every field change exposed to users | The change log is infrastructure, not a product feature; exposing it is a new feature and out of scope |
| Per-field merge (CRDT) | Rejected in D-11; complexity unjustified for this data |
| OAuth / social login | Not requested; adds a provider dependency and a new identity path |
| Multi-tenant organisation accounts | Not in `FEATURE_INVENTORY.md`; inventing it would be scope expansion |
| Replacing MySQL | Explicitly forbidden by D-02 |
