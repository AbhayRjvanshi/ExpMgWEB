# Unresolved Questions Register

| Field | Value |
|---|---|
| Document status | Decision consolidation register — updated with authoritative owner decisions |
| Date opened | 2026-11-15 |
| Date updated | 2026-11-18 |
| Phase | 2 |
| Authority | Project Owner Decisions (UQ-01 through UQ-19); UQ-20 explicitly deferred |

This document records the official resolution and status of all twenty architectural and product
questions identified during Phase 2. The project owner's instructions are authoritative and supersede
all earlier agent recommendations.

---

## 1. Status vocabulary

| Status | Meaning |
|---|---|
| `APPROVED` | Project owner has formally decided and approved the policy or architecture |
| `APPROVED WITH PROVISIONAL POLICY` | Approved as provisional guidance, subject to storage and recovery testing |
| `APPROVED — AUDIT REQUIRED` | Policy direction approved; technical dependency audit completed |
| `NOT APPROVED` | Explicitly deferred, withheld, or rejected by the project owner (UQ-20) |
| `BLOCKING` | Implementation cannot start until resolved (0 items remaining) |
| `OPEN` | Awaiting owner input (0 items remaining) |

---

## 2. Register

### UQ-01 — Guest-data merge into an existing account

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | D-04, D-22, `synchronization/GUEST_DATA_MIGRATION.md` §7 |

**Question.** A user has guest data on device A, then signs in to an account that already exists
(possibly with data from device B). What happens to the guest data?

**Owner-Approved Decision:**
A user with existing guest data must be allowed to sign into an existing registered account without
being forced to merge or discard their guest data immediately. The user must have the option to:
1. **Import guest data into the registered account now.**
2. **Skip the import and continue using the registered account.**
3. **Return to guest mode later.**
4. **Import the guest data at a later time from application settings.**

**Required Behavior when Skipping ("Skip for now"):**
- Preserve the guest data in its local guest partition (`account_id = 'guest'`).
- Do not delete or overwrite the guest data.
- Switch the active application context to the registered account (`account_id = :user_uuid`).
- Keep guest and registered account data logically separate in Drift.
- Allow the user to resume the guest partition after logging out or switching back to guest mode.
- Provide an explicit later import option in settings.

**Granular Two-Tier Duplicate Detection (Import Workflow):**
Monthly budget warnings alone are not sufficient duplicate detection. The import workflow requires:
1. **Dry-Run Inventory:** Client submits guest inventory to `POST /api/v2/import/guest.php?dry_run=true`.
2. **Tier 1 — Exact UUID Duplicates (`exact_duplicate`):** Records whose client-generated UUID already exists in the target account. Automatically de-duplicated and skipped from re-creation (`would_skip`).
3. **Tier 2 — Heuristic Duplicate Candidates (`ambiguous_candidate`):** Records with a different UUID that match substantive financial attributes (e.g. same `expense_date`, same `amount`, and matching/similar `note` or category; or list item description in the same list).
4. **Mandatory User Confirmation:** Ambiguous candidates are **never** silently merged, duplicated, or dropped. The preview presents an interactive reconciliation screen where the user explicitly chooses:
   - *"Import as Separate Record"* (creates a distinct record with its guest UUID).
   - *"Skip"* (discards local guest duplicate, links to existing account row).
5. **Idempotent Batch Import:** Applied through an atomic, idempotent batch process (`dry_run=false`).
6. **Relationship Preservation:** Preserves all category, list-to-item, and item-to-expense links.
7. **Verification Before Deletion:** The client verifies migration success (count match + SHA-256 digest match); local guest records are deleted or cleaned up **only** after successful server confirmation and explicit completion.

---

### UQ-02 — Account deletion, data erasure and historical attribution

| Field | Value |
|---|---|
| Status | `APPROVED` (Approved with owner clarification) |
| Blocks | Nothing (Policy approved) |
| Related | D-23, `database/DATA_OWNERSHIP.md` §9, `security/SECURITY_ARCHITECTURE.md` §9.4, UQ-07 |

**Question.** What must happen to a user's data and financial relationships when they delete their account?

**Owner-Approved Decision & Clarification:**
Account deletion is strictly blocked while any group balance remains unsettled, including credits. Credit forfeiture is strictly prohibited. Personal account data is permanently purged and credentials revoked. The deleted
user's original username is retained in historical group records while those records exist.

**1. Outstanding-Balance Gate (Pre-Flight):**
- Account deletion is strictly blocked if the user has **any unsettled group balance**, whether debit (user owes money) OR credit (user is owed money).
- All relevant group obligations must be settled before deletion becomes eligible.
- **Financial forfeiture of credits is prohibited.** A user cannot forfeit positive balances to bypass the settlement requirement.
- **Sole Group Administrator Procedure (Two Choices Before Deletion):** If the user is the sole administrator of any active group with other members, deletion is blocked until administrative responsibility is transferred under one of two owner-approved choices:
  1. *Manual Successor Designation:* The departing administrator explicitly selects an existing active group member to succeed them as administrator via `POST /api/v2/groups/transfer_admin.php`.
  2. *Automatic Succession:* If the administrator does not manually designate a successor, the system allows automatic transfer of the group administrator role to the earliest-joined remaining member of that group (`SELECT user_id FROM group_members WHERE group_id = ? AND user_id != ? ORDER BY joined_at ASC, id ASC LIMIT 1`).
  Deleting a financially active group as a substitute for transferring responsibility is prohibited. (If a 1-member group with 0 balance, it must be formally archived/closed prior to account deletion).

**2. Personal Account Data Purge:**
Atomic hard-deletion of purely personal records:
- Login credentials (passwords, password salts, active session cookies).
- Email address and personal profile metadata.
- Personal expenses (`group_id IS NULL`).
- Personal shopping lists and private list items.
- Personal monthly budgets (`budgets` table).
- User notifications and personal activity logs.
- Remember-device credentials and active sessions in `app_sessions`.
- Device synchronization authorizations in `sync_devices` marked `revoked_at = NOW(6)`.
- Personal cached data on device wiped upon account deletion.

**3. Historical Group Records & Original Username Preservation:**
Historical records in multi-user groups remain preserved during the normal group lifecycle, including archival:
- Group expenses in which the user participated (as payer or split participant).
- Group settlements (`payer_id`, `payee_id`, `settled_by`).
- Settlement confirmations (`settlement_confirmations`).
- Historical group membership attribution necessary to verify past period closures.
- **Original Username Retained:** The user's original username is preserved in historical financial records, accompanied by a clear contextual badge: `"This user deleted their account."`. **The username is NOT replaced with a generic "Deleted User" label or pseudonym.**
- The deleted account is completely deactivated: cannot authenticate, join groups, participate in future settlements, or appear in participant-selection interfaces.

**4. Group Archival Lifecycle Interaction (UQ-07):**
Historical group records and deleted users' original usernames are preserved during the normal group lifecycle, including archival. Once a group has completed six full months in the archived state, its administrator may independently initiate a permanent-deletion request (UQ-07). If permanent group deletion is executed, the group and all its associated historical records are permanently destroyed as specified in UQ-07.

---

### UQ-03 — Session lifetime and remember-this-device credentials

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | D-09, D-24, `security/SESSION_MANAGEMENT.md` §4, `api/AUTHENTICATION_AND_AUTHORIZATION.md` §4 |

**Question.** What is the session lifetime and credential mechanism across Website and Native clients?

**Owner-Approved Decision:**
Adopt a separate remember-this-device credential system. Do not treat the ordinary PHP session cookie
and persistent remember-device credentials as the same mechanism.

**1. Website Behavior:**
- Ordinary authenticated web session expires after **7 days of inactivity**.
- The inactivity timer resets on qualifying authenticated user activity.

**2. Native Application Behavior (Mobile & Desktop):**
- Adopt a **30-day inactivity limit** for remember-device credentials.
- Persistent per-device credentials stored securely using platform-appropriate mechanisms (Android KeyStore, iOS Keychain, Windows DPAPI, macOS Keychain, Linux libsecret).
- Secure credential renewal while the user remains active; credential rotation where appropriate.
- Expiration after 30 days of inactivity.
- Immediate invalidation when the device is revoked.
- Secure recovery after application restart; no storage of raw reusable credentials in ordinary application preferences.
- Do not implement a universal fixed 24-hour login requirement for native clients.

**3. Architectural Distinctions Maintained:**
- **Ordinary authenticated session:** PHP session cookie (`PHPSESSID`), used for immediate API requests; subject to 7-day inactivity on Web.
- **Remember-device credential:** Long-lived cryptographic token on native clients (30-day inactivity), used to obtain/refresh authenticated sessions without re-entering passwords.
- **Device identity:** Stable UUIDv7 assigned at device registration.
- **Device synchronization authorization:** Server-managed sync privilege gated by `sync_devices.revoked_at`.
- *Note:* The earlier 30-day sliding PHP session is superseded by this separate remember-device credential architecture.

---

### UQ-04 — Full device and session revocation

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | D-25, `security/SESSION_MANAGEMENT.md` §6, `security/ACCESS_CONTROL.md` |

**Question.** What access is terminated when a user revokes a device?

**Owner-Approved Decision:**
The application must provide full device and session revocation. A registered user can view all
devices on which their account currently has active sessions and select "Log out from this device".

**Required Revocation Behavior:**
Revoking a device immediately:
1. Invalidates that device's authenticated session in the database-backed `app_sessions` registry.
2. Revokes its remember-device credentials.
3. Revokes its synchronization permissions (`sync_devices.revoked_at = NOW(6)`).
4. Prevents further authorized v1 API access using the revoked session (returns `401 Unauthorized`).
5. Prevents further authorized v2 API access (returns `401 Unauthorized` or `403 DEVICE_REVOKED`).
6. Updates the active-device list to show the device as revoked.
7. Requires fresh authentication before the device can be used again.

**Offline Limitation Acknowledged:**
An offline device cannot be remotely forced to erase local storage while disconnected. The system
maintains an honest architectural distinction between:
- Immediate server-side credential invalidation.
- Denial of access when the device reconnects.
- Local data protection via OS hardware encryption (SQLCipher/KeyStore/Keychain).
- Local cleanup when the device next receives a valid revocation response.

---

### UQ-05 — Batch synchronization, rate limits and monitoring

| Field | Value |
|---|---|
| Status | `APPROVED (PROVISIONAL)` |
| Blocks | Nothing (Rate limits approved; batch limits proposed defaults subject to load testing) |
| Related | D-28, `api/API_ARCHITECTURE.md` §8, `api/SYNC_API_SPECIFICATION.md` §3 |

**Question.** What are the rate limits, batch sizes, and monitoring requirements for synchronization?

**Owner-Approved Decision (HTTP Sync Rate Limits):**
- **120 synchronization API requests per user per minute** via dedicated `api_sync` bucket.
- **240 burst requests per device.**
- Rate limits count HTTP transport requests, not the number of records contained within a batch.
- Limits are configurable via server environment and monitored in production.

**Proposed Initial Defaults (Batch Payload Limits — Subject to Phase 5 Load Testing):**
The synchronization engine must support batch synchronization to avoid one HTTP request per change:
- **Default Maximum Records per Batch:** 100 records (`PROPOSED` initial configuration default).
- **Default Maximum Payload Size:** 1 MB / 1,048,576 bytes (`PROPOSED` initial configuration default).
- Payloads exceeding these limits return HTTP `413 PAYLOAD_TOO_LARGE`.
- Both batch limits are configurable server-side (`SYNC_BATCH_MAX_RECORDS`, `SYNC_BATCH_MAX_BYTES`) and subject to load testing during Phase 5.
- Rate limits and batch sizes are distinct: a client sending 1 request containing 100 records consumes 1 request against its 120/min limit.

**Batch Synchronization Engine Requirements:**
- Batch upload (`POST /api/v2/sync/push.php`) and batch download (`GET /api/v2/sync/pull.php`).
- Request compression (gzip/brotli) where supported.
- Per-record and per-batch idempotency via client UUIDv7 and `batch_uuid`.
- Partial batch failure handling (atomic per batch or validated per entity).
- Exponential backoff with jitter on retry.

**Monitoring Metrics:**
The system must monitor: batch size distribution, records pushed/pulled, sync latency, request throughput, 429 rate-limit rejections, 413 payload rejections, retry rates, and conflict frequencies.

---

### UQ-06 — PHP and Laravel coexistence model

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | D-03, D-18, `architecture/BACKEND_COEXISTENCE.md` |

**Question.** What is the backend technology stack for the synchronization engine and service layer?

**Owner-Approved Decision:**
Retain the existing procedural PHP backend. The current migration and synchronization architecture
uses native PHP 8 and the existing PHP environment. The shared domain/service layer will be developed
within the existing backend structure.

Laravel remains an optional technology for justified future use when a specific new feature or
selectively migrated module has a meaningful technical reason to adopt it.
- Do not rewrite the backend in Laravel.
- Do not introduce Laravel unnecessarily into the sync engine.
- Do not duplicate business logic.
- Preserve backward compatibility and common API contracts.

---

### UQ-07 — Group archival lifecycle and historical record management

| Field | Value |
|---|---|
| Status | `APPROVED` (Approved with owner clarification) |
| Blocks | Nothing (Archival lifecycle and permanent deletion workflow approved) |
| Related | D-26, `database/DATA_OWNERSHIP.md` §8, `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.4, UQ-02, UQ-11 |

**Question.** How are groups with financial history retired, and what is the group archival lifecycle?

**Owner-Approved Decision & Clarification:**
Group history is preserved through archival. Restoration is permitted only within 3 calendar months from
the actual archive timestamp. Once a group has remained archived for 6 full months, its administrator may
independently initiate a permanent-deletion request. Deletion is not automatic.

**1. 4-State Archival Lifecycle:**
- **State 1 — `ACTIVE`:** Normal operational state. Members can record expenses, manage lists, and execute settlements.
- **State 2 — `ARCHIVE_PENDING`:** Temporary transition state while pending calculations, unconfirmed settlements, or offline sync queues settle.
- **State 3 — `ARCHIVED` (Fully Archived):**
  - Read-only historical state. No new expenses, lists, or settlements can be created.
  - **3-Month Restoration Window:** Within three calendar months (90 days) of entering `ARCHIVED` status, an authorized administrator can restore the group to `ACTIVE` (`POST /api/v2/groups/restore.php`).
  - Former members can review historical ledgers and export statements.
- **State 4 — `PERMANENTLY_ARCHIVED`:**
  - Reached after three calendar months in `ARCHIVED` state.
  - Group restoration is permanently barred.
  - Historical records and original usernames (including of deleted users) remain preserved unless the administrator initiates permanent deletion after 6 months.

**2. Permanent-Deletion Workflow Specification:**
Once a group has completed six full months in the archived state, permanent deletion may be initiated under the following strict workflow:
- **Eligibility:**
  - The group must have remained continuously in the archived state for at least **six full calendar months** (`archived_at <= NOW() - INTERVAL 6 MONTH`).
  - Any permanent-deletion request submitted before six full months have elapsed is strictly rejected with HTTP `409 CONFLICT` (`GROUP_NOT_ELIGIBLE_FOR_DELETION`).
  - Permanent deletion is **never automatic** and is never triggered by a background cron job.
- **Authorized Administrator (No Member Fallback):**
  - Only a verified group administrator (`role = 'admin'` in `group_members` at archival) can submit a permanent-deletion request.
  - Requests from regular members, non-members, or former members are strictly rejected with HTTP `403 FORBIDDEN`. There is strictly no last-member fallback.
- **Explicit Confirmation & Re-Authentication:**
  - Initiated via `POST /api/v2/groups/permanent_delete.php`.
  - Requires explicit confirmation payload:
    - `group_id`: integer identifier.
    - `confirm_group_name`: exact string match of the group name.
    - `password`: administrator account password (re-authentication).
    - `confirm_acknowledgement`: explicit acknowledgement that all historical records, settlements, and member ledgers will be permanently destroyed.
- **Exact Data Scope across the 9 Data Categories:**
  Executed within a single atomic database transaction:
  1. *Group Container & Metadata (Cat 1):* Hard-deleted from `groups` table.
  2. *Group Membership History (Cat 2):* Hard-deleted from `group_members`.
  3. *Personal Group Lists & Items (Cat 3):* Hard-deleted from `lists` and `list_items` scoped to `group_id`.
  4. *Expenses (Cat 4):* All group expenses (`expenses` where `group_id = G`) are hard-deleted. (Not converted to personal expenses).
  5. *Settlements (Cat 5):* All settlements (`settlements` where `group_id = G`) are hard-deleted.
  6. *Settlement Confirmations (Cat 6):* All historical rows in `settlement_confirmations` where `group_id = G` are hard-deleted.
  7. *Receipts & Attachments (Cat 7):* Physical files/blobs linked to group expenses are permanently purged from file storage.
  8. *Historical Username Attribution (Cat 8):* Ceases to exist for this group because underlying expense and settlement records are destroyed.
  9. *Synchronization Tombstones (Cat 9):* Tombstone records are generated for each former member in `sync_change_log` and `sync_tombstones`.
- **Synchronization Propagation:**
  - The deletion transaction emits a group tombstone (`entity_type = 'group'`, `entity_uuid = group_uuid`) scoped to the `user_id` of each former member of the group.
  - On the next sync pull (`GET /api/v2/sync/pull.php`), each member's client receives the tombstone and executes a cascade purge in its local Drift database: removing the group, all group lists, items, expenses, and settlements.
  - Offline devices retain local data until reconnecting, at which point the server delivers the tombstone and triggers the local purge.

---

### UQ-08 — Offline CRUD operations

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Offline CRUD and online-only membership operations approved) |
| Related | D-16, D-17, `synchronization/OFFLINE_DATA_MANAGEMENT.md` §2, §4 |

**Question.** Which entities and operations are permitted offline?

**Owner-Approved Decision (Six Offline Entity Types):**
Offline access supports full CRUD (Create, Read, Update, Delete) locally in Drift for the following six entities:
1. **Personal expenses:** Create, edit, delete.
2. **Group expenses:** Create/draft locally using cached membership; edit/delete (admin); revalidated against settlement lock upon push.
3. **Personal budgets:** Set monthly budget amounts.
4. **Personal shopping lists:** Create, rename, delete list container.
5. **Group shopping lists:** Create, rename, delete list container.
6. **Shopping-list items:** Create, edit description/price/priority, delete.

**Owner-Approved Strictly Online-Only Operations:**
The following operations remain strictly online-only:
1. **Settlement confirmations** (`POST /api/v2/settlements/confirm.php`): Requires atomic `SELECT … FOR UPDATE` transaction across all member confirmations and closes the period.
2. **Shopping-list item checking/unchecking** (`POST /api/v2/lists/check_item.php`): Automatically converts list item into an expense (or deletes linked expense) and enforces settlement-lock window.

**Owner-Approved Online-Only Operations (Group Membership Mutations):**
- Adding/removing members and other group membership changes (group creation, join via code, leaving group, removing a member, group deletion) are **owner-approved strictly online-only operations**.
- *Rationale:* Group membership controls cryptographic data visibility, authorization boundaries, and database integrity constraints (max 10 members per group, max 5 groups per user). Allowing offline membership mutations risks split-brain authorization states and over-capacity errors on sync push.

**Settlement Calculation Distinction:**
Settlement calculation (`GET /api/v2/settlements/calculate.php`) is a dynamic, read-only aggregation of current unsettled expenses. It requires fresh server state and is distinct from the multi-party settlement confirmation process.

---

### UQ-09 — Synchronization data retention

| Field | Value |
|---|---|
| Status | `APPROVED (PROVISIONAL)` |
| Blocks | Nothing (Provisional policy approved) |
| Related | `database/DATABASE_DESIGN.md` §4.3, `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.3 |

**Question.** What are the retention periods for change logs, tombstones, and batch records?

**Owner-Approved Retention Policy & Provisional GC Framework:**
The following retention values and policies are established:
- **Synchronization change log (`sync_change_log`):** **90 days only** (owner-approved provisional schedule).
- **Synchronization tombstones (`sync_tombstones`):** **No fixed expiry** (owner-approved with future GC design). Develop a safe garbage collection (GC) mechanism based on synchronization acknowledgements, device revocation, and forced resynchronization.
- **Completed synchronization batches (`sync_batches`):** **45 days after completion** (updated from 30 days by owner decision).
- **Guest-import audit records (`guest_import_batches`):** **Lifetime retention**, linked to the user account (**APPROVED policy**, not provisional; explicitly approved by project owner).

*Constraint:* The lifetime retention of guest-import audit records is **APPROVED policy**. The operational pruning and GC schedules for change logs (90 days), tombstones (safe GC framework without fixed expiry), and completed sync batches (45 days) remain `APPROVED (PROVISIONAL)` pending future operational GC design during Phase 5 load testing. No automated destructive pruning jobs may run without prior operational review.

---

### UQ-10 — Administrative settlement operations deprecation (Option B Approved)

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved; actual code/test edits on hold until UQ-20) |
| Related | `api/API_CONTRACT_SPECIFICATION.md`, `testing/ARCHITECTURE_VALIDATION_PLAN.md`, D-17 |

**Question.** Should administrative settlement operations (`settle.php`, `settle_all.php`) be retained or deprecated?

**Owner-Approved Decision (Option B Confirmed):**
The project owner has confirmed that dependency verification and documentation conditions are satisfied; **Option B (Deprecate and Remove) remains fully approved**. Both `api/settlements/settle.php` and `api/settlements/settle_all.php` are formally designated as deprecated and will respond with `410 Gone` once implemented.

**Owner-Approved Narrow Exception to Frozen v1 Contract:**
Existing v1 endpoints are normally frozen against status code and response modifications (D-31, PR-23). The project owner has specifically approved deprecating these two administrative settlement endpoints to return HTTP `410 Gone` as a narrow, owner-approved exception to the frozen v1 contract.

**Implementation Boundary:**
This approval settles the architectural and policy decision. **Actual PHP endpoint modifications and test suite refactoring remain strictly on hold until formal Phase 2 sign-off (UQ-20).**

**Dependency Verification & Architectural Findings:**

1. **Dependency Verification:**
   - **Legacy Web Application (`public/assets/js/`):** Comprehensive automated grep and AST inspection across all legacy frontend files (`app.js`, `helpers.js`, `lists.js`) and page templates (`pages/*.php`, `public/*.php`) confirms **zero references** to `settle.php` or `settle_all.php`. The legacy website has never called these endpoints; all UI-initiated settlements execute via the collaborative confirmation workflow.
   - **Supported Integrations:** No third-party integrations, external webhooks, or secondary clients exist that call `settle.php` or `settle_all.php`.
   - **Test Suite Callers:** The only callers in the repository are `tests/run_tests.php` (Sections 17, 27, 28) and `scripts/pentest/penetration_test.php`.

2. **Complete Coverage by the Normal Three-Step Settlement Workflow:**
   - The normal 3-step settlement workflow (`calculate.php` → `confirm.php` with atomic ledger closure upon consensus) covers 100% of legitimate settlement operations:
     - `calculate.php`: Dynamically computes pairwise transfer debts using `paid_by` expense balances and `is_post_settlement = 0` filtering.
     - `confirm.php`: Multi-party consensus ceremony where each member explicitly confirms their computed share.
     - Atomic Closure: When all active group members confirm, `confirm.php` atomically locks the period (`SELECT … FOR UPDATE`), writes immutable settlement transfer records, clears confirmation rows, and locks historical expenses.
     - `post_calculate.php` / `post_confirm.php`: Handles late or incremental adjustments without breaking closed periods.
   - `settle.php` and `settle_all.php` were uncollaborative v1 shortcuts that bypassed member consensus. Their removal eliminates unilateral force-settling and enforces ledger integrity.

3. **Target Deprecation Contract (HTTP 410 Gone):**
   - Both endpoints will reject all requests (POST/GET) with HTTP status `410 Gone`:
     ```json
     {
       "ok": false,
       "error": "Endpoint deprecated. Use collaborative settlement workflow (calculate.php -> confirm.php)."
     }
     ```
   - No database queries or transactions will be executed.
   - Both endpoints are excluded from Flutter native clients and v2 API surfaces.

4. **Testing & Phase 3 Implementation Plan (Both Test Files):**
   - `docs/testing/ARCHITECTURE_VALIDATION_PLAN.md` test cases `T-AUTH-10` and `T-AUTH-11` are updated to assert HTTP `410 Gone`.
   - Following UQ-20 sign-off and during Phase 3/4 implementation, **both** existing test suites must be updated:
     1. `tests/run_tests.php` (Sections 17, 27, 28) to verify that non-admin and admin callers receive HTTP `410 Gone`, and test setup will simulate settlements using multi-session `confirm.php` calls.
     2. `scripts/pentest/penetration_test.php` (test cases S07–S11) to assert HTTP `410 Gone` on administrative settlement calls.

---

### UQ-11 — Current classification versus historical expense provenance

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | D-26, `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.4.4, §7.3 |

**Question.** How are deliberate conversions distinguished from group-deletion detachments?

**Owner-Approved Decision:**
Deliberate group-to-personal conversion and group archival/detachment are different business events:
1. **Deliberate User Conversion:** User intentionally converts a group expense to personal. Current classification becomes `personal` (`type = 'personal'`, `group_id = NULL`). Historical group provenance is retained in change-log audit history (`move_out`). Participates in personal budgets and analytics. UI shows: *"Converted from [Group Name]"*.
2. **Group Archival or Detachment:** Historical group expenses must **not** be rewritten to personal expenses. Original group classification and provenance remain (`type = 'group'`, `group_id = NULL`, `is_detached = 1`). Historical group context remains accessible. Segregated in UI under *"Detached Group Expenses"*; does not distort personal discretionary budgets.
3. **Database Constraint:** Schema allows both valid states:
   ```sql
   CHECK (
       (type = 'personal' AND group_id IS NULL) OR
       (type = 'group' AND group_id IS NOT NULL) OR
       (type = 'group' AND group_id IS NULL AND is_detached = 1)
   )
   ```

---

### UQ-12 — Synchronization conflict resolution and Needs Attention

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | `synchronization/CONFLICT_RESOLUTION.md` §6, §8 |

**Question.** How are synchronization conflicts managed in the UI and engine?

**Owner-Approved Decision:**
The application must provide a dedicated "Needs Attention" area in both Flutter and Web:
- Preserve the pending local change; explain the conflict with server and local versions side by side.
- Safe automatic merging evaluated for non-financial metadata (e.g. category name de-duplication).
- **Strictly Prohibit Automatic Merging on Financial Fields:** Never automatically merge monetary amounts, payer identity, group ownership, expense splits, settlement states, confirmations, membership, or permissions. Never resolve financial conflicts using "last-write-wins" timestamps.
- Allow the user to resolve the conflict immediately or defer resolution without losing data.

---

### UQ-13 — Notification architecture

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | D-19, `architecture/TARGET_ARCHITECTURE.md` |

**Question.** Is the notification feed included in the offline synchronization store?

**Owner-Approved Decision:**
Notifications remain server-backed and are excluded from the general expense/list offline sync store.
Notification delivery and retrieval remain a separate architectural capability. The pending-work
reminder functionality (UQ-18) uses this approved server-backed notification architecture.

---

### UQ-14 — Platform release sequence

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | D-01, D-08, D-14, `deployment/DEPLOYMENT_ARCHITECTURE.md` §4.4 |

**Question.** What is the release sequencing across Mobile, Desktop, and Web?

**Owner-Approved Decision:**
The application targets all six platforms with a staged release:
1. **Release 1 — Native Platforms:** Android, iOS, Windows, macOS, Linux.
   - Shared architectural foundation: native SQLite via Drift (`sqlite3` Dart FFI), full offline ACID durability, hardware-backed secure storage.
   - Subject to platform verification gates (KeyStore, Keychain, DPAPI, libsecret, WorkManager, share sheets).
2. **Release 2 — Flutter Web:**
   - Released after: CanvasKit/WASM CSP verification (spike A-WEB-1), IndexedDB/OPFS storage quota behavior, same-origin deployment (`/ExpMgWEB/app/`), service-worker scope validation.
   - The existing PHP/JavaScript website remains available and functional with zero interruption.

---

### UQ-15 — Same-origin hosting

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | D-08, `deployment/DEPLOYMENT_ARCHITECTURE.md` §4 |

**Question.** How is the Flutter Web client deployed relative to the API?

**Owner-Approved Decision:**
Use the same-origin deployment model as the default architecture (`/ExpMgWEB/app/`). Do not introduce
cross-origin/CORS configuration unless a demonstrated deployment requirement arises.

---

### UQ-16 — Guest feature package

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | D-04, `synchronization/GUEST_DATA_MIGRATION.md` §3 |

**Question.** What is the exact feature boundary for offline guest mode?

**Owner-Approved Decision:**
Guest mode remains local-only and makes zero network/API requests:
- **Available to guest users:** Personal expenses, categories (read-only defaults), personal budgets, personal shopping lists and items, calendar, analytics.
- **Unavailable to guest users:** Theme customization (guests receive standard default appearance; customization controls hidden), groups, group settlements, notifications, sharing, cloud backup, multi-device synchronization, data export (CSV, JSON, PDF).
- **Export Educational Prompt:** Tapping export displays: *"Account Required to Export Data"*. Full export becomes available after registration and guest-data migration.

---

### UQ-17 — Device registration requirement

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | `api/SYNC_API_SPECIFICATION.md` §4.2, `security/ACCESS_CONTROL.md` |

**Question.** Must a device register before synchronizing?

**Owner-Approved Decision:**
Device registration is mandatory before the first synchronization. The device registry tracks:
stable UUIDv7, device name, platform, active status, last sync timestamp, and diagnostic metadata.
Server verifies device identity and ownership before permitting upload or download of synchronized data.

---

### UQ-18 — Pending offline issues and account-wide reminders

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved; platform limitations explicitly constrained) |
| Related | D-35, `synchronization/CONFLICT_RESOLUTION.md` §6, `api/SYNC_API_SPECIFICATION.md` §5 |

**Question.** How are users reminded of skipped or unresolved offline/sync issues, and what receipt guarantees can be made?

**Owner-Approved Requirement (Account-Wide Reset & Explicit App-Level Acknowledgement):**
- When a user selects "Skip for now" on a conflict/unresolved issue, a reminder is scheduled for 24 hours later.
- When ANY authorized device receives and acknowledges the reminder, the 24-hour reminder countdown resets **account-wide** across all user devices.
- **Explicit app-level acknowledgement (`POST /api/v2/reminders/ack.php`) is the owner-approved measurable definition of successful receipt** (distinguishing actual app receipt from server queuing, push gateway dispatch, or provider acceptance).

**Delivery Lifecycle Stages & Honesty Boundary:**
To prevent false architectural promises, notification lifecycle is separated into six stages:
1. **Notification Generated (Server):** Cron/worker evaluates overdue reminders and generates reminder event.
2. **Notification Queued (Server):** Dispatch job enters outbound message queue.
3. **Provider Accepted (Server-to-Gateway):** Push provider (APNs, FCM, WNS) accepts payload. (*Proves provider custody; does NOT prove device receipt*).
4. **Device Delivery Indication (Gateway-to-Device):** OS push socket receives packet. (*Highly variable across platforms*).
5. **Application Acknowledgement (`POST /api/v2/reminders/ack.php`):** The app or background extension executes and sends an API acknowledgement to the server with `reminder_id` and `device_uuid`. (*Cryptographic proof of receipt*).
6. **User Interaction:** User taps notification. (*Proof of human viewing*).

**Platform Delivery Capabilities & Architectural Constraints:**
The architecture acknowledges explicit OS-level limitations:
- **Android:** FCM data messages wake app background services, BUT aggressive OEM power management (Samsung OneUI, Xiaomi MIUI) frequently terminates background services and suppresses background receipts until user opens app.
- **iOS / macOS:** APNs does NOT guarantee background execution. Under Low Power Mode or disabled Background App Refresh, silent pushes are dropped by iOS. Acknowledgement is guaranteed only when the Notification Service Extension runs or user brings app to foreground.
- **Windows / Linux:** Desktop platforms lack universal standardized background push handlers when the app is closed. Delivery acknowledgement occurs when the app is running or next launched.
- **Web:** Service worker push is subject to browser process lifecycle and mobile browser suspension.

**Acknowledgement Protocol:**
- Client calls `POST /api/v2/reminders/ack.php` with `reminder_id`, `device_uuid`, and client timestamp upon receiving or displaying the reminder.
- Server validates session, records receipt in `reminder_acknowledgements`, and resets the 24-hour schedule account-wide.
- No false guarantees: The implementation guarantees account-wide reset upon receipt of the API acknowledgement; it does not claim to bypass OS-level background push restrictions.

---

### UQ-19 — Backward compatibility with the existing website

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Owner decision approved) |
| Related | D-31, `architecture/BACKEND_COEXISTENCE.md` |

**Question.** Must the legacy PHP/JS website remain operational and compatible with modern clients?

**Owner-Approved Decision:**
Backward compatibility with the existing PHP/JavaScript website is mandatory:
- Legacy website remains fully functional throughout modernization.
- Two-way interoperability: data created or updated in Flutter is visible and usable in the legacy UI; changes in the legacy UI sync to Flutter.
- Preserves existing data meaning, business rules, expense calculations, group relationships, settlements, and permissions.

---

### UQ-20 — Formal Phase 2 approval

| Field | Value |
|---|---|
| Status | `APPROVED` |
| Blocks | Nothing (Formally approved by project owner on 2026-11-18) |
| Related | `WORK/PHASE_2_READINESS_REPORT.md` |

**Question.** Does the project owner grant formal approval to close Phase 2?

**Owner-Approved Decision & Approval Boundary:**
**APPROVED.** The project owner formally approved UQ-20 and accepted the Phase 2 architecture and technical documentation baseline on 2026-11-18.

**Scope of UQ-20 Formal Approval:**
Formal sign-off authorizes:
1. **Acceptance of documentation:** Formal acceptance of the Phase 2 architecture and technical documentation baseline.
2. **Git baseline commit & tag:** Committing and tagging the accepted documentation baseline in Git (`phase2-architecture-approved`).
3. **Execution of verification spikes:** Execution of the three planned verification spikes (`A-DB-3`, `A-DB-4`, `A-WEB-1`), strictly within their documented scope on isolated/disposable environments.

**Explicit Exclusions — What UQ-20 Does NOT Authorize:**
Formal Phase 2 sign-off does **NOT** authorize:
1. **Application implementation:** Writing or modifying production PHP endpoints, services, or Flutter client application code.
2. **Database modifications:** Applying database schema changes, DDL modifications, or running migrations (`schema.sql`, `migration_v*.sql`).
3. **Production deployment:** Deploying any target architecture component or modern service to production environments.

Each of these activities remains strictly gated and requires separate, explicit owner authorization following Phase 3 spike evaluation.

---

## 3. Summary

| Status | Count | Items |
|---|---:|---|
| `APPROVED` | 18 | UQ-01, UQ-02, UQ-03, UQ-04, UQ-06, UQ-07, UQ-08, UQ-10, UQ-11, UQ-12, UQ-13, UQ-14, UQ-15, UQ-16, UQ-17, UQ-18, UQ-19, UQ-20 |
| `APPROVED (PROVISIONAL)` | 2 | UQ-05, UQ-09 |
| `NOT APPROVED` | 0 | — |
| `BLOCKING` | 0 | — |
| `OPEN` | 0 | — |

**All 20 architectural questions resolved. Phase 2 documentation baseline formally approved.**| UQ-01 | Guest-data merge into an existing account | `APPROVED` | Option C: Non-blocking interactive choice with dry-run preview and two-tier duplicate detection |
| UQ-02 | Account deletion, data erasure and historical attribution | `APPROVED` | Balance gate (debit & credit block deletion; no forfeiture); original username retained in historical group records |
| UQ-03 | Session lifetime and remember-this-device credentials | `APPROVED` | Option C: 7-day sliding web sessions; 30-day hardware-backed native remember credentials |
| UQ-04 | Full device and session revocation | `APPROVED` | Option B: Full database-backed revocation via `app_sessions` across v1 and v2 |
| UQ-05 | Batch synchronization, rate limits and monitoring | `APPROVED (PROVISIONAL)` | 120 req/min user and 240 burst device approved; batch limits 100 recs / 1 MB are proposed initial defaults |
| UQ-06 | PHP and Laravel coexistence model | `APPROVED` | Model M-1: Procedural PHP 8.2 backend retained with `api/domain/`; Laravel optional for future |
| UQ-07 | Group archival lifecycle and historical record management | `APPROVED` | 4-state lifecycle, 3m restore window, 6m admin-initiated permanent deletion workflow specified |
| UQ-08 | Offline CRUD operations and online boundaries | `APPROVED` | Offline CRUD for 6 entities approved; settlement confirmations, list checking, and group membership mutations approved online-only |
| UQ-09 | Synchronization data retention | `APPROVED (PROVISIONAL)` | 90-day sync logs, tombstones no fixed expiry (future safe GC), 45-day batches (provisional); lifetime guest audit (APPROVED policy) |
| UQ-10 | Administrative settlement operations deprecation | `APPROVED` | Option B approved; settle.php and settle_all.php deprecated (HTTP 410 Gone); code/test changes on hold until UQ-20 |
| UQ-11 | Orphaned-expense remediation and classification | `APPROVED` | Deliberate conversion (`type='personal'`) vs detached group expense (`type='group', is_detached=1`) |
| UQ-12 | Conflict presentation and resolution | `APPROVED` | Dedicated "Needs Attention" UI; metadata safe auto-merge; strictly no financial auto-merge |
| UQ-13 | Notification synchronization | `APPROVED` | Excluded from offline sync store; dynamic server-backed retrieval |
| UQ-14 | Platform release sequencing | `APPROVED` | Native-First Strategy: Release 1 Mobile/Desktop (FFI SQLite), Release 2 Web post A-WEB-1 |
| UQ-15 | Same-origin hosting confirmation | `APPROVED` | Confirmed: `/ExpMgWEB/app/` same-origin hosting on Apache |
| UQ-16 | Guest feature package and export restrictions | `APPROVED` | Personal features approved; theme customization disabled; data export strictly prohibited |
| UQ-17 | Mandatory device registration | `APPROVED` | Mandatory registration before sync via `POST /api/v2/devices/register.php` |
| UQ-18 | Pending offline issues and account-wide reminders | `APPROVED` | Account-wide 24h reset upon app ack via `POST /api/v2/reminders/ack.php`; platform delivery constraints explicit |
| UQ-19 | Legacy web application two-way parity | `APPROVED` | Mandatory two-way compatibility between legacy PHP/JS web SPA and native Drift clients |
| UQ-20 | Formal Phase 2 sign-off and implementation gate | `APPROVED` | Formally approved by project owner on 2026-11-18; authorizes doc acceptance, baseline commit/tag, and 3 spikes |
