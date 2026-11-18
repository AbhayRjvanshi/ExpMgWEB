# Data Flow

| Field | Value |
|---|---|
| Status | Specification — Phase 2 |
| Date | 2026-11-15 |
| Purpose | Show exactly how data moves for every architectural scenario |

Every diagram here corresponds to a written rule elsewhere. A diagram without a rule is
decoration and is not permitted (see `WORK/PHASE_2_DESIGN_PLAN.md` §2).

Diagrams in other documents: high-level system → `TARGET_ARCHITECTURE.md` §2; component →
`SYSTEM_COMPONENTS.md` §1; backend coexistence → `BACKEND_COEXISTENCE.md` §3.

---

## 1. Online read (Flutter, registered mode)

```mermaid
sequenceDiagram
    autonumber
    participant U as User
    participant UI as Flutter UI
    participant C as Controller
    participant R as Repository
    participant L as Local store
    participant A as API client
    participant S as v2/v1 endpoint
    participant D as MySQL

    U->>UI: open Expenses (month)
    UI->>C: loadMonth(2026-11)
    C->>R: watchMonth(2026-11)
    R->>L: SELECT expenses WHERE month
    L-->>R: cached rows (may be stale)
    R-->>C: rows + staleSince
    C-->>UI: render immediately
    Note over C,UI: UI shows cached data with a subtle "syncing" indicator
    R->>A: GET /api/v2/sync/pull?since=cursor (if online)
    A->>S: request + session cookie
    S->>D: change log query (visible scope)
    D-->>S: changes
    S-->>A: {changes, next_cursor, digests}
    A-->>R: parsed result
    R->>L: apply changes in one transaction, advance cursor
    L-->>R: applied
    R-->>C: new rows
    C-->>UI: re-render (no flash)
```

**Rules.**
- The UI never waits for the network to show data it already has.
- A failed pull does **not** clear local data and does **not** advance the cursor.
- `staleSince` is displayed only when the last successful pull is older than 5 minutes.

---

## 2. Online write (Flutter, registered mode)

```mermaid
sequenceDiagram
    autonumber
    participant UI as Flutter UI
    participant C as Controller
    participant R as ExpenseRepository
    participant L as Local store
    participant Q as Change queue
    participant A as API client
    participant S as v2 endpoint
    participant H as Domain helpers
    participant D as MySQL

    UI->>C: save(expense form)
    C->>R: create(draft)
    R->>R: validate locally (Money, date, category)
    R->>L: INSERT expense (pending, uuid=client uuid)
    R->>Q: enqueue(op=create, entity, uuid)
    L-->>R: committed
    R-->>C: optimistic record
    C-->>UI: show saved (state=pending)

    R->>A: POST /api/v2/sync/push {batch}
    A->>S: session + CSRF + Idempotency-Key
    S->>S: authenticate, authorise, rate limit
    S->>H: validate + apply rules
    H->>D: settlement lock, membership, payer checks
    D-->>H: ok
    H->>D: INSERT expense (+ trigger writes change log, version=1)
    D-->>S: server id, version, updated_at
    S-->>A: 200 {results:[{uuid, status:applied, id, version}]}
    A-->>R: result
    R->>L: mark record synced (server id, version), delete queue entry
    R-->>C: record synced
    C-->>UI: state=synced
```

**Rules.**
- The local write commits **before** the network call. The UI is never blocked by the network.
- The queue entry is deleted only after a confirmed server result.
- A `409` moves the record to `conflict`; it is not retried automatically.

---

## 3. Offline write and later synchronisation

```mermaid
sequenceDiagram
    autonumber
    participant U as User
    participant UI as Flutter UI
    participant R as Repository
    participant L as Local store
    participant Q as Change queue
    participant N as Connectivity
    participant SY as Sync engine
    participant A as API client
    participant S as v2 sync
    participant D as MySQL

    Note over U,D: Device is offline
    U->>UI: add expense 24.50 (today)
    UI->>R: create(draft)
    R->>L: INSERT expense(pending, uuid)
    R->>Q: enqueue(create)
    R-->>UI: saved locally
    U->>UI: edit budget
    UI->>R: setBudget(2026-11, 500.00)
    R->>L: UPSERT budget(pending)
    R->>Q: enqueue(upsert)
    R-->>UI: saved locally
    U->>UI: try "Confirm settlement"
    UI-->>U: disabled — "Requires connection"
    Note over UI: Online-only operations are never queued (D-17)

    Note over U,D: Connectivity returns
    N-->>SY: online
    SY->>Q: read pending ops ordered by dependency
    SY->>A: POST /api/v2/sync/push {batch_uuid, ops}
    A->>S: push
    S->>D: validate + apply each op in a transaction
    D-->>S: versions, change-log rows
    S-->>A: per-op results
    A-->>SY: results
    SY->>L: apply results, clear applied queue entries
    SY->>A: GET /api/v2/sync/pull?since=cursor
    A->>S: pull
    S->>D: changes visible to user
    D-->>S: rows + tombstones
    S-->>A: page
    A-->>SY: changes
    SY->>L: apply page in transaction, advance cursor
    SY-->>UI: state=synced, lastSync=now
```

**Rules.**
- Push happens **before** pull. This is safe because every push carries `base_version`; a push
  cannot overwrite a newer server state — it fails with a conflict instead.
- Dependency order is fixed: `budget`/`list` → `list_item` → `expense` (see
  `SYNC_WORKFLOWS.md` §4.2).
- The pull cursor advances only after the page is committed locally.

---

## 4. Guest-to-registered migration

```mermaid
sequenceDiagram
    autonumber
    participant U as User
    participant UI as Flutter UI
    participant G as Guest store
    participant C as Auth controller
    participant A as API client
    participant S as v2 import
    participant D as MySQL
    participant V as Verification

    U->>UI: tap "Create account" from a registered-only action
    UI->>UI: show what will migrate (counts, totals)
    U->>UI: submit registration
    UI->>C: register(email, password)
    C->>A: GET /api/v2/auth/session.php (obtain CSRF)
    A-->>C: csrf token
    C->>A: POST /api/signup.php (existing endpoint)
    A-->>C: 302 → login (or JSON in v2 mode)
    C->>A: POST /api/login.php
    A-->>C: 302 + session cookie + rotated CSRF
    C->>A: GET /api/v2/auth/session.php
    A-->>C: user + csrf + device registration
    Note over C,A: Authenticated. Guest data is still local and untouched.

    C->>G: inventory (counts per entity + checksum)
    G-->>C: inventory
    C->>A: POST /api/v2/import/guest {batch_uuid, inventory, records[≤100]}
    A->>S: import batch
    S->>D: validate + insert with client UUIDs (idempotent per batch_uuid)
    D-->>S: created / skipped_duplicate per record
    S-->>A: per-record results + server ids
    A-->>C: results
    C->>G: mark records as migrated (do NOT delete yet)

    C->>A: POST /api/v2/import/guest (next batch) …
    Note over C,A: repeat until inventory exhausted

    C->>A: GET /api/v2/import/status?batch_uuid
    A-->>C: {expected, created, skipped, failed}
    C->>V: compare server counts with local inventory
    alt all records accounted for and no failures
        V-->>C: verified
        C->>G: delete guest data (single transaction)
        C->>UI: "Your data is now in your account"
    else mismatch or failures
        V-->>C: not verified
        C->>UI: "Some data could not be imported — kept on this device"
        Note over C,G: Guest data is NEVER deleted on failure
    end
```

**Rules.**
- Deletion of guest data is a separate, final, verified step (D-04).
- For existing accounts (D-22, UQ-01), Option C applies: dry-run surfaces Tier 1 exact UUID skips and Tier 2 heuristic candidates; user can skip import for now, preserving the local guest partition (`account_id = 'guest'`).
- Each batch is idempotent by `batch_uuid`; a retry cannot duplicate records.
- A partial import leaves guest data intact and offers retry.

Full detail: `synchronization/GUEST_DATA_MIGRATION.md`.

---

## 5. Authentication and authorisation flow

```mermaid
sequenceDiagram
    autonumber
    participant App as Flutter app
    participant Boot as Secure storage
    participant API as PHP endpoint
    participant MW as Middleware
    participant DB as MySQL

    App->>Boot: read device uuid
    alt no device uuid
        App->>Boot: generate and store uuid
    end

    App->>API: GET /api/v2/auth/session.php
    API->>MW: ensureSecureSessionStarted()
    MW->>MW: session cookie present?
    alt no valid session
        MW-->>API: 401 {code:"UNAUTHENTICATED"}
        API-->>App: 401
        App->>App: route to login (guest data untouched)
    else valid session
        MW->>MW: login_time within 24 h?
        alt expired
            MW->>DB: session_destroy()
            MW-->>App: 401 {code:"SESSION_EXPIRED"}
        else valid
            MW->>DB: rate limit check (user, ip)
            MW->>MW: SET @expmg_actor_user_id, @expmg_device_uuid
            MW-->>API: authenticated context
            API-->>App: 200 {user, csrf_token, expires_at, server_time}
        end
    end

    Note over App,API: Every subsequent write
    App->>API: POST + cookie + X-CSRF-Token + Idempotency-Key
    API->>MW: requireAuth()
    MW->>MW: session → expiry → CSRF → rate limit
    MW-->>API: authorised
    API->>DB: authorise the specific record (ownership / membership / admin)
    alt not authorised
        API-->>App: 403 {code:"PERMISSION_DENIED"}
    else authorised
        API->>DB: perform write inside a transaction
        API-->>App: 200 {ok:true, ...}
    end
```

**Rules.**
- Two authorisation layers, both mandatory: **authentication** (who) at the middleware and
  **record authorisation** (may this user touch this row) in the endpoint/domain helper.
- A client-supplied `user_id`, `group_id`, `paid_by`, or cursor is never trusted; it is always
  re-derived or re-checked.
- The session cookie is never read by application code; only the session itself is.

Full detail: `api/AUTHENTICATION_AND_AUTHORIZATION.md`, `security/ACCESS_CONTROL.md`.

---

## 6. Settlement closure flow (online only)

```mermaid
sequenceDiagram
    autonumber
    participant UI as Flutter settlement screen
    participant A as API client
    participant S as settlements/confirm.php (v1)
    participant D as MySQL

    UI->>A: GET settlements/calculate.php?group_id=4
    A-->>UI: balances, period_start, period_end, confirmed_users
    UI->>UI: render "Confirm" with period_end shown
    UI->>A: POST settlements/confirm.php {group_id, expected_period_end, expected_total}
    A->>S: session + CSRF + Idempotency-Key
    S->>D: BEGIN
    S->>D: recompute period (last settlement, expenses, today)
    S->>S: compare with expected_period_end / expected_total
    alt period changed since the client calculated
        S->>D: ROLLBACK
        S-->>A: 409 {code:"PERIOD_CHANGED", current:{...}}
        A-->>UI: refresh calculation, ask user again
    else period unchanged
        S->>D: upsert confirmation (group_id, user_id)
        S->>D: SELECT … FOR UPDATE count vs active members
        alt all confirmed
            S->>D: insert settlements (or 0.00 marker)
            S->>D: delete settlement_confirmations for group
            S->>D: COMMIT
            S-->>A: {ok:true, all_settled:true}
        else not all
            S->>D: COMMIT confirmation
            S-->>A: {ok:true, all_settled:false, remaining:N}
        end
    end
```

**Rules.**
- `expected_period_end` and `expected_total` are **optional** request fields. When absent, the
  endpoint behaves exactly as today (v1 compatibility). When present and mismatched, the server
  refuses with `409 PERIOD_CHANGED`.
- The transaction and `SELECT … FOR UPDATE` are preserved (PR-05).
- The client never computes a settlement result; it only displays server output.

---

## 7. Change-capture flow (server-side, every write)

```mermaid
flowchart LR
    A["v1 endpoint write"] --> B["MySQL row change"]
    C["v2 endpoint write"] --> B
    D["CLI/admin write"] --> B
    B --> E{"BEFORE UPDATE trigger"}
    E -->|"version = version + 1<br/>updated_at = NOW(6)"| B
    B --> F{"AFTER INSERT/UPDATE trigger"}
    F -->|"@expmg_sync_suppress = 1"| G["no change-log row"]
    F -->|"normal"| H["INSERT sync_change_log<br/>seq, entity, uuid, op, version,<br/>payload snapshot, actor, device"]
    F -->|"expenses.group_id changed"| H2["move_out (old scope, no payload)<br/>then update (new scope)"]
    B -->|"DELETE"| I["INSERT sync_tombstones"]
    H --> J["pull endpoint reads by seq"]
    H2 --> J
    I --> J
    K["FK cascade delete"] -.->|"child triggers do NOT fire"| L["trg_groups_bd compensation"]
    L --> L1["per-member group_member tombstone<br/>scope_user_id = member"]
    L --> L2["explicit expense detach →<br/>ordinary triggers fire"]
    L1 --> M["client purges parent subtree"]
    L2 --> N["owner keeps detached expense"]
```

**Rules.**
- One write produces exactly one change-log row per affected row — except a group deletion, whose
  compensation rows are written deliberately by `trg_groups_bd` (one per member).
- Migrations and backfills set `@expmg_sync_suppress = 1`.
- A cascade delete is represented by the parent's tombstone **only while the parent still resolves
  visibility**. Group deletion destroys the membership rows, so it emits a personally-scoped
  `group_member` tombstone per member instead (`DATABASE_SCHEMA_SPECIFICATION.md` §5.4.3).
- An expense whose `group_id` changes emits `move_out` for the old scope before the new-scope
  `update`, so the old scope can remove its copy without receiving a payload it is not entitled to
  (§5.6.4).
- A surviving group expense is detached by an explicit `UPDATE`, never by `ON DELETE SET NULL`
  (which fires no trigger and would be an unlogged mutation).

Full detail: `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.4.3–§5.4.4 and §5.6.

---

## 8. Conflict detection and resolution flow

```mermaid
sequenceDiagram
    autonumber
    participant UI as Flutter UI
    participant SY as Sync engine
    participant L as Local store
    participant A as API client
    participant S as v2 push
    participant D as MySQL

    SY->>L: read queue entry (uuid, base_version=3, payload)
    SY->>A: POST /api/v2/sync/push
    A->>S: op
    S->>D: SELECT version FROM expenses WHERE uuid=?
    alt version = 3 (as expected)
        S->>D: UPDATE … WHERE uuid=? AND version=3
        S->>D: trigger bumps version to 4 + change-log row
        S-->>A: {status:"applied", version:4}
        A-->>SY: applied
        SY->>L: update record, clear queue entry
    else version = 4 (another device edited)
        S->>D: SELECT current row
        S-->>A: 409 {code:"VERSION_CONFLICT", server:{...}, client:{...}}
        A-->>SY: conflict
        SY->>L: mark record conflict, store both versions
        SY-->>UI: "Needs attention" badge
        UI->>UI: user chooses: keep mine / keep server / merge fields
        UI->>SY: resolution choice
        SY->>A: POST /api/v2/sync/push {resolution, base_version:4}
        A->>S: apply with the now-current base version
        S-->>A: applied
    end
```

**Rules.**
- A conflict never resolves itself for an expense, budget, or list record.
- The server always returns its current row so the user can compare.
- A resolution is a **new write** with the fresh `base_version`; it is not a forced overwrite.

Full detail: `synchronization/CONFLICT_RESOLUTION.md`.

---

## 9. Deletion propagation flow

```mermaid
sequenceDiagram
    autonumber
    participant D1 as Device 1
    participant S as Server
    participant D as MySQL
    participant D2 as Device 2 (offline)

    D1->>S: POST delete expense (base_version=5)
    S->>D: DELETE FROM expenses WHERE uuid=? AND version=5
    D->>D: AFTER DELETE trigger → change log op=delete + tombstone
    S-->>D1: {ok:true}
    D1->>D1: remove local row, clear queue entry

    Note over D2: offline — still shows the expense
    D2->>S: GET /api/v2/sync/pull?since=cursor
    S->>D: change log rows > cursor
    D-->>S: delete event for uuid
    S-->>D2: {changes:[{op:"delete", entity:"expense", uuid}]}
    D2->>D2: delete local row (or mark removed if referenced)
    D2->>D2: advance cursor
```

**Rules.**
- A delete is a tombstone event, not an absence. Absence cannot be distinguished from "not yet
  downloaded".
- If the local row has unsent changes, the delete wins only if the tombstone's version is greater
  than the local base version; otherwise the client surfaces a conflict.

Full detail: `database/DATABASE_SCHEMA_SPECIFICATION.md` §5.4,
`synchronization/SYNC_WORKFLOWS.md` §7.5.

---

## 10. Group membership transition flow

```mermaid
flowchart TB
    A["Membership changes on another device<br/>(join / leave / removed)"] --> B["Pull receives group_member change"]
    B --> C{"Event type"}
    C -->|"joined"| D["Targeted bootstrap:<br/>GET /api/v2/sync/bootstrap?scope=group&id=UUID"]
    D --> E["Insert group, members, expenses,<br/>lists, settlements for that group"]
    C -->|"left / removed"| F["Purge local subtree for that group"]
    F --> G["Group, members, group expenses,<br/>group lists and items, settlements"]
    G --> H["Personal data untouched"]
    C -->|"group deleted"| F
```

**Rules.**
- A membership transition is not a change-log replay; it is a scoped bootstrap or purge. This
  avoids retroactively exposing a group's history through a cursor.
- Purge is local only. Server data is unaffected (except for a genuine group delete).

---

## 11. Summary of flow guarantees

| Guarantee | Mechanism |
|---|---|
| No lost local write | Local commit precedes network; queue persists |
| No duplicate server write | `batch_uuid` + per-record idempotency + client UUID uniqueness |
| No silent overwrite | `base_version` precondition |
| No lost deletion | Durable tombstone |
| No partial apply | Per-page transaction; cursor advances only after commit |
| No stale financial closure | Online-only confirmation with `expected_period_end` |
| No anonymous data | Guest mode has no network path |
| No unauthorised sync | Server re-derives identity and re-checks ownership on every push |
