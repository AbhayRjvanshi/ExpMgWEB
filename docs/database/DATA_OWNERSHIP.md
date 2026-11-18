# Data Ownership

| Field | Value |
|---|---|
| Status | Specification — Phase 2 |
| Date | 2026-11-15 |
| Purpose | Define, for every entity group, who owns a record, who may read it, who may change it, and what happens to it when a user or group relationship ends |
| Requirements | DB-02, AA-08…AA-13, PR-01, PR-09, PR-10, PR-17 (`WORK/REQUIREMENTS_TRACEABILITY.md`) |
| Rule | Ownership is a business concept. It is never inferred from synchronisation metadata (§2) |

---

## 1. Scope and the twelve entity groups

### 1.1 What this document decides

| Question | Section |
|---|---|
| How ownership metadata is kept separate from sync metadata | §2 |
| The difference between creator, owner, actor, and payer | §3 |
| Expense ownership and permissions | §4 |
| Ownership of budgets, lists, items, notifications, categories, groups, memberships | §5 |
| Group-size and per-user limits | §6 |
| What a removed or departed member can still see and do | §7 |
| What happens to data when a group is deleted | §8 |
| What happens to data when an account is deleted | §9 |
| What a disabled account may do | §10 |
| Records that have no business owner | §11 |
| The complete ownership matrix | §12 |

Endpoint-level enforcement is specified in
[`../security/ACCESS_CONTROL.md`](../security/ACCESS_CONTROL.md). This document defines **who owns
what**; that document defines **how each request is checked**.

### 1.2 The twelve entity groups (AA-08)

| # | Entity group | Tables | Owner concept |
|---|---|---|---|
| E1 | User account | `users` | The user themself; the system holds it |
| E2 | Category | `categories` | None — deployment-seeded, shared (recorded in §5.5, not an owned entity) |
| E3 | Group | `groups` | The creating user, with administrative authority delegated through membership |
| E4 | Group membership | `group_members` | The group's administrators |
| E5 | Expense | `expenses` | The recording user for personal expenses; the group's administrators for group expenses |
| E6 | Budget | `budgets` | The user |
| E7 | List | `lists` | The creating user for personal lists; the group's administrators for group lists |
| E8 | List item | `list_items` | The parent list's owner |
| E9 | Notification | `notifications` | The recipient user (read-only to them) |
| E10 | Settlement and confirmations | `settlements`, `settlement_confirmations`, `post_settlement_confirmations` | The group's administrators; the confirmations are the members' own rows |
| E11 | Device registration | `sync_devices` | The user who registered the device |
| E12 | Sync control records | `sync_change_log`, `sync_tombstones`, `sync_batches`, `guest_import_*` | The system; scoped to the user/group the change belongs to |

E2 is listed for completeness and marked as unowned in §5.5. E12 is listed because the question
"who may read a change-log row" has a real answer (§2.3).

---

## 2. Ownership metadata vs sync metadata (AA-13)

**Requirement:** AA-13 "ownership metadata is kept separate from sync metadata".

### 2.1 Two different questions

| Metadata class | Columns | Answers |
|---|---|---|
| **Ownership** | `user_id`, `group_id`, `created_by`, `paid_by`, `added_by`, `checked_by`, `settled_by`, `payer_id`, `payee_id`, `role` (in `group_members`), `sync_devices.user_id` | "Whose record is this, and who may change it?" |
| **Synchronisation** | `uuid`, `version`, `updated_at`, `sync_change_log.*`, `sync_tombstones.*`, `sync_batches.*` | "How does this record move between devices, and in what order?" |

### 2.2 Rules

| Rule | Statement | Why |
|---|---|---|
| AO-1 | An ownership decision never reads a sync column | A `version` is not an authorisation input |
| AO-2 | A sync decision never reads an ownership column to decide *whether* to transfer a change — only to decide *to whom* | Visibility filtering is a delivery concern, not a permission grant |
| AO-3 | Adding a sync column never changes a permission | `uuid` and `version` are not sent as authority by a client; the server resolves the row and applies §4–§10 |
| AO-4 | The `uuid` is an identifier, not a capability | Possession of a UUID grants nothing. Authorisation always passes through the session and `group_members` |
| AO-5 | Ownership columns are never rewritten by the sync layer | The sync layer writes business values as instructed by a validated request; the `user_id`/`group_id` of a record are set by the same rules as v1 |
| AO-6 | The client never chooses an owner | A v2 push does not carry `user_id`; the server sets it from the session. A client-supplied `user_id` is ignored, not validated-and-honoured |
| AO-7 | Retaining ownership when a member leaves is explicit, not incidental | §7 |

### 2.3 Who may read a change-log row

A change-log row is readable by exactly the users who could read the underlying record:

```
scope_user_id = :me
  OR scope_group_id IN (SELECT group_id FROM group_members WHERE user_id = :me)
```

| Rule | Statement |
|---|---|
| The scope is computed inside the trigger from the row | `DATABASE_SCHEMA_SPECIFICATION.md` §5.2.1 |
| Membership removal narrows future visibility | After leaving a group, a user no longer receives that group's subsequent changes (§7) |
| No endpoint exposes the change log to a client as a table | The pull endpoint returns *changes the caller may see*, never raw rows with arbitrary scope |
| A support operator reading the log directly has database access, not an API | Documented as an operational capability, not a product feature |

---

## 3. Creator, owner, actor, and payer (AA-09)

**Requirement:** AA-09 "creator and owner are distinguished where they differ".

### 3.1 The four roles

| Role | Column | Meaning | Example |
|---|---|---|---|
| **Owner** | `user_id` (expenses, budgets, lists, notifications), `groups.created_by` | The user the record belongs to. Determines personal-expense edit rights, list rights, and visibility | A personal expense's owner is the user who recorded it |
| **Creator / actor** | `created_by`, `added_by`, `checked_by`, `settled_by` | Who performed the action. **Never** a permission basis on its own | An admin records an expense on behalf of a member: `created_by = admin`, `user_id = member` |
| **Payer** | `paid_by` | Who actually paid the money for a group expense. The settlement grouping key (PR-01) | A member pays for a group dinner but another admin enters it |
| **Recipient** | `notifications.user_id` | The user a notification is addressed to (read-only to them) | An expense notification goes to the group's members |

### 3.2 Rules

| Rule | Statement |
|---|---|
| The owner is not always the creator | `expenses.user_id` (owner) and `expenses.created_by` (actor) differ whenever one user records an expense attributable to another |
| The creator is not the payer | `created_by ≠ paid_by` in general (PR-01) |
| Ownership governs personal edit rights; membership role governs group edit rights | §4 |
| `paid_by` governs **money**, never **permission** | A member who paid has no edit rights because of payment |
| `checked_by` is attribution only | It records which admin confirmed a group list item; it grants no rights |
| Nullable actor columns mean "the actor is gone" | `created_by`/`checked_by` are `ON DELETE SET NULL`; the record keeps its owner |

### 3.3 Worked examples

| Situation | `user_id` | `paid_by` | `created_by` | Who may edit? |
|---|---|---|---|---|
| Personal expense by its owner | owner | owner | owner | The owner |
| Group expense entered by an admin for themselves | admin | admin | admin | Any group admin |
| Group expense entered by admin A, paid by member B | admin A | member B | admin A | Any group admin |
| Personal expense created by checking a priced list item | item's list owner | list owner | list owner | The list owner |
| Group list item checked, confirmed with a payer | group member (the payer's `paid_by`) | chosen payer | the checking admin (`checked_by`) | Any group admin |
| Settlement row | — | `payer_id` (debtor) | `settled_by` (admin) | Any group admin, subject to the lock rules |

---

## 4. Expense ownership (PR-01, PR-09, PR-10)

### 4.1 Personal expenses — owner only (PR-09)

**Requirement:** PR-09 "personal expenses are owner-only for edit/delete".

| Operation | Permission |
|---|---|
| Create | The owner creates their own personal expense (`user_id = :me`, `group_id = NULL`, `type = 'personal'`) |
| Read | The owner |
| Update | The owner only. Any other user is refused (v1: HTTP **400** with `ok:false` and a frozen error string — verified; v2: `403 PERMISSION_DENIED`) |
| Delete | The owner only. Any other user is refused the same way |
| Settle | Not applicable — personal expenses are never in a settlement aggregate |

| Rule | Statement |
|---|---|
| There is exactly one owner | `expenses.user_id` is `NOT NULL` |
| A personal expense never carries a `group_id` | Enforced on the create path (PR-11); see §4.3 for the orphan case |
| Ownership transfers are not possible | **UNRESOLVED** — UQ-14. No endpoint moves a personal expense to another user, and none is proposed here |
| A personal expense can become a group expense | Only through the group-expense creation path, which sets `group_id` and `type` together. Not a post-hoc edit |

### 4.2 Group expenses — admin only (PR-10)

**Requirement:** PR-10 "group expenses are admin-only for edit/delete".

| Operation | Permission |
|---|---|
| Create | Any active member may create a group expense for the group (`group_members` row required). The payer must be an active member |
| Read | Every active member of the group |
| Update | A group `admin` only. A non-admin member is refused (v1: **400** `ok:false`, frozen string `Only the group admin can edit group expenses.` — verified; v2: `403 PERMISSION_DENIED`) |
| Delete | A group `admin` only, same convention |
| Settle | Admin only (`settle.php` / `settle_all.php` [SUPERSEDED by UQ-10 Option B: deprecated with HTTP 410 Gone]), and separately gated by settlement rules |

| Rule | Statement |
|---|---|
| "Admin" means `group_members.role = 'admin'` for that group | Never `groups.created_by` alone; a creator who is no longer a member is not a member |
| The owner of a group expense is the group, expressed through `group_id` | `expenses.user_id` still records the recording user and is preserved, but it does **not** grant edit rights |
| A member who can create cannot necessarily edit | Creation is member-level; editing is admin-level. This asymmetry is existing behaviour and is preserved |
| A former member loses all group expense rights | §7 |
| A non-member cannot read the expense | The group details endpoint scopes by membership |
| An admin cannot act on a group they have left | §7.3 |

### 4.3 `paid_by` — money, not permission (PR-01)

**Requirement:** PR-01 "settlement maths uses `paid_by`, never `user_id` or `created_by`".

| Rule | Statement |
|---|---|
| `paid_by` is the settlement grouping key | Every balance aggregate groups by `paid_by` |
| `paid_by` must be an active member of the group | Validated on create/update; this is a data-integrity rule, not a permission rule |
| `paid_by` is nullable | `ON DELETE SET NULL` when a user is genuinely deleted. An expense with a null payer is excluded from settlement maths by the aggregation's own semantics and is flagged by the reconciliation checks in §9 |
| A null `paid_by` never falls back to `user_id` | Falling back would silently invent a payer and corrupt balances |
| The payer has no edit rights because of payment | §3.2 |
| The sync path re-validates `paid_by` on every push | `BACKEND_COEXISTENCE.md` §6.1; a client cannot nominate a non-member payer |
| `uuid`/`version` never influence who is considered the payer | AO-1 |

### 4.4 Settlement lock — the ownership boundary in time

| Rule | Statement |
|---|---|
| A group expense dated inside a settled period (`expense_date` between the group's `MAX(period_end)`-derived range and the last settled `period_end`) is **immutable** | The endpoint checks the `settlements` table before edit and delete |
| The rule applies to admins too | Administrative authority does not unlock a closed period |
| The rule applies to the sync path | A queued offline edit that falls inside a period settled while the device was offline is rejected on push → `409` with the conflict shown to the user |
| The rule applies to delete | Deleting a settled expense is refused |
| The rule applies to `price_unpriced.php` | `expense_date` is derived from `checked_at` and is checked against the lock |
| The lock is the reason offline edits need conflict handling at all | `CONFLICT_RESOLUTION.md` §5 |
| `0.00` marker settlements advance the lock | PR-08; `DATABASE_SCHEMA_SPECIFICATION.md` §3.11 |

---

## 5. Ownership of the remaining entity groups

### 5.1 Group (E3)

| Aspect | Rule |
|---|---|
| Owner | `groups.created_by` — the user who created it |
| Authority | The creator is inserted as an `admin` in `group_members`; **all** subsequent checks read the membership row, not `created_by` |
| Read | Every active member |
| Update (name) | Admin only (existing behaviour) |
| Delete | Admin only; semantics are `NEEDS APPROVAL` — §8 |
| `join_code` | A shared secret for joining. Possession permits `join.php`; it grants no other right and no read access to group data before joining |
| Transfer of ownership | **UNRESOLVED** — UQ-14. `created_by` is immutable in the current design |
| Exactly one admin is not enforced by the database | `DATABASE_AUDIT.md` §2.4. The application keeps `created_by` and at least one admin consistent |

### 5.2 Group membership (E4)

| Aspect | Rule |
|---|---|
| Owner | The group's administrators; the row also belongs to the member it describes |
| Create (join) | Any authenticated user holding a valid `join_code`, subject to capacity and the 5-group limit (§6) |
| Create (add) | Not exposed; membership arises from create or join |
| Read | All active members (roster); each member also sees their own row |
| Update (role) | Admin only, where the feature exists |
| Delete (remove member) | Admin only |
| Delete (leave) | The member themself |
| Self-removal of the last admin | **UNRESOLVED** — UQ-14. Recommended: refuse, so a group cannot be orphaned |
| A member's row does not exist ⇒ no group rights | Every group check begins with a membership lookup |

### 5.3 List and list item (E7, E8)

| Aspect | Rule |
|---|---|
| Personal list owner | `lists.user_id` (creator). Only the owner may read, rename, delete, add items, or remove items |
| Group list owner | The group; only admins may create/delete the list and modify its items |
| Group list read | All active members |
| List item owner | The parent list's owner. There is no independent item ownership |
| `added_by` | Attribution only; another member's item in a shared list is still the list owner's record |
| Item → expense conversion | Requires the item's owner (personal) or an admin (group) — the same actor who may check the item |
| Group list item check | Returns `needs_confirm`; the caller must supply `paid_by`, which must be an active member (`AGENTS.md`) |
| Uncheck | Deletes exactly the linked expense by `expense_id`; permitted within the 600-second server-measured window |
| A group list becomes read-only offline | D-16; a list item's ownership is not transferable by a sync write |

### 5.4 Budget and notification (E6, E9)

| Aspect | Rule |
|---|---|
| Budget owner | `budgets.user_id` (the user). Unique per `(user_id, budget_month)` |
| Budget visibility | The owner only; never a group concept |
| Notification owner | `notifications.user_id` — the recipient |
| Notification rights | Read and mark-read only. A notification cannot be created, edited, or deleted through the API by its recipient |
| Notification `reference_id` | Points to the entity that caused it (an expense id, per `AGENTS.md`). It is **not** a permission: knowing a `reference_id` grants no access to the referenced row |
| Notifications in sync | Excluded entirely (D-19); no ownership question arises |

### 5.5 Category (E2) — the unowned entity

| Aspect | Rule |
|---|---|
| Owner | None. `categories` is shared, deployment-seeded data |
| Read | Every authenticated user |
| Create/edit/delete | Not exposed through the API |
| Sync | Not synchronised; every device seeds from `expenses/categories.php` on bootstrap |
| Referential protection | `expenses.category_id` is `ON DELETE RESTRICT`; `list_items.category_id` is `ON DELETE SET NULL` |

### 5.6 Settlement and confirmations (E10)

| Aspect | Rule |
|---|---|
| Settlement owner | The group; `settlements.group_id` |
| Who may trigger | Admin only (`settle.php`, `settle_all.php` [SUPERSEDED by UQ-10 Option B: deprecated with HTTP 410 Gone]); collaborative confirmation closes period |
| Who may confirm | Each active member, for themself. A member cannot confirm on another member's behalf |
| Confirmation ownership | `settlement_confirmations.user_id` is the confirming member; the row is keyed `(group_id, user_id)` |
| Post-settlement confirmations | Keyed `(group_id, user_id)` for the current late-expense round |
| Admin authority over confirmations | None. An admin cannot confirm for a member; the three-step gate is the members' own action (PR-04) |
| `settled_by` | Attribution of the admin who closed the period; not a permission |
| Settlement immutability | Settlements are never deleted except by cascade (group or user deletion). §8 and §9 govern those events |

---

## 6. Limits (PR-17)

**Requirement:** PR-17 "max 5 groups per user; max 10 members per group".

| Limit | Value | Enforced before | Notes |
|---|---|---|---|
| Groups per user | 5 | `INSERT` into `group_members` on create and join | Counts memberships, not groups created |
| Members per group | 10 | `INSERT` into `group_members` on create and join | `groups.max_members > 0` is the only schema backstop; the value itself is application-enforced |
| Budget months per user | 1 per `(user_id, budget_month)` | Unique index (upsert) | Not a limit; a key |
| Lists | No limit | — | Existing behaviour |
| Items per list | No limit | — | Existing behaviour |

| Rule | Statement |
|---|---|
| The limits are business rules, not ownership | They restrict *who may create a relationship*, not who may read existing data |
| The sync path enforces them identically | A queued offline group-join is **not** offline-capable (D-16), which removes the race entirely; see below |
| Concurrency | A join is a read-then-insert (`DATABASE_AUDIT.md` §4 records that `join.php` is untransacted). Two simultaneous joins can exceed 10 members. **This is existing behaviour and is not fixed here.** The sync design does not introduce a new path to it, because joins are online-only |
| Exceeding a limit | `ok: false` with the existing error string; no new error code |

---

## 7. Access after leaving, being removed, or being excluded (AA-10)

**Requirement:** AA-10 "access after leaving a group is defined".

### 7.1 The three ways a member stops being a member

| Event | Trigger | `group_members` row |
|---|---|---|
| Leave | `leave.php`, by the member | Deleted |
| Removed | `remove_member.php`, by an admin | Deleted |
| Group deleted | `delete.php`, by an admin | Cascade-deleted |

### 7.2 What changes immediately

| Capability | Before | After |
|---|---|---|
| Read the group's current data (expenses, lists, settlements, roster) | Yes | **No** |
| Read historical expenses they created or paid | Only through group endpoints, which now deny access | **No** |
| Create/edit/delete group expenses | Per role | **No** |
| Confirm a settlement | Yes | **No** (their pending confirmation row cascades away) |
| Receive future notifications for the group | Yes | **No** (existing notifications remain until retention) |
| Sync: receive future changes for the group | Yes | **No** — `scope_group_id` filtering uses `group_members`, so removal narrows the stream (§2.3) |
| Sync: keep already-downloaded local group data | n/a | **Yes**, until the client reconciles; see §7.4 |

### 7.3 What does **not** change

| Item | Statement |
|---|---|
| Their own expenses' financial effect | Historical settlement records that include them are unchanged. Removing a member does not rewrite past balances |
| `paid_by` references to them | Remain valid |
| The settlement lock | Unaffected; a settled period stays settled |
| Data they created in the group | Remains group property. Leaving does not remove expenses or list items |

### 7.4 The local-data question (must be decided, and is)

A member removed while offline still has the group's rows in their local store. Three options:

| Option | Verdict |
|---|---|
| Leave them indefinitely | Rejected: stale financial data on a device that is no longer entitled to it |
| Purge the group subtree on the next sync | **Selected** |
| Ask the user | Rejected for the removal case; a "keep a copy" feature would be a new product decision |

**Rule:** the client purges a group's subtree (group, members, lists, items, settlements) as soon as
it observes that it is no longer a member. The observation comes from a `group_member` tombstone for
its own user, or from the reconciliation digest. This is the same mechanism as a group tombstone
(`SYNC_WORKFLOWS.md` §6) and is **not** negotiable, because keeping the data would make the client
display balances the server no longer accepts.

**How the observation is guaranteed in each case:**

| Case | Event that reaches the member |
|---|---|
| Leave | The member's own `group_members` delete fires `trg_group_members_bd`; `scope_user_id` is the member, so they see it |
| Removed | The same trigger, same scope; the actor is the admin, the recipient is the removed member |
| **Group deleted** | The membership rows are cascade-deleted, so no child trigger fires and the `group` tombstone is group-scoped — invisible once membership is gone. `trg_groups_bd` therefore writes a **personally-scoped** `group_member` tombstone per current member (`scope_user_id = member.user_id`, `cause='cascade'`). See `DATABASE_SCHEMA_SPECIFICATION.md` §5.4.3 |
| Offline for the whole event | The tombstone is durable (never pruned), so it is delivered on the next pull regardless of how long the device was away |
| Interrupted sync | The tombstone is applied idempotently; a partially applied page does not advance the cursor, so it is re-delivered |
| Database restored to before the deletion | The reconciliation digest detects the client's surplus local rows and triggers a targeted bootstrap, which returns only the visible scope |

**What is retained, and by whom.** An expense the departing member *owns* (`expenses.user_id`) is not
the group's to destroy: the group-delete path detaches it (`group_id = NULL`) as an ordinary logged
update scoped to the owner, and the owner keeps it as a personal expense. Every **other** former
member's cached copy is purged with the subtree. See `DATABASE_SCHEMA_SPECIFICATION.md` §5.4.4.

**Export exception:** if the product later adds an export-before-leaving feature, the exported file
is the user's own copy and is outside the application's control. No such feature exists today.

### 7.5 The last-admin case

| Situation | Rule |
|---|---|
| The only admin leaves | **UNRESOLVED** — UQ-14. Recommended: refuse, requiring either an ownership transfer or group deletion first |
| The only admin is removed | Impossible through the API: only an admin may remove a member, and the actor is a member themself |
| The creator leaves | Permitted today; `groups.created_by` remains pointing at a non-member. Any code that treats `created_by` as authority is **wrong** (§4.2 rule) |

---

## 8. Group deletion and orphaned group expenses (D-26, UQ-07, UQ-11)

**Status: `ACCEPTED` (D-26, UQ-07, UQ-11). Requirements DB-14 and PR-21 are `SPECIFIED`.**

### 8.1 4-state group archival lifecycle (owner-approved)

The project owner approved the 4-state group archival lifecycle to prevent destructive cascades and preserve financial truth:

```
ACTIVE  ──>  ARCHIVE_PENDING  ──>  ARCHIVED  ──>  PERMANENTLY_ARCHIVED
  ^                                    │
  └──────────(within 3 months)─────────┘
```

| State | Permitted operations | Invariants & Transitions |
|---|---|---|
| `ACTIVE` | Normal CRUD, settlement calculation, confirmation | Default state for all operational groups |
| `ARCHIVE_PENDING` | Balance checks, settlement audit | Transition initiated by Group Admin (`POST /api/v2/groups/archive.php`); verifies all balances are settled |
| `ARCHIVED` | Read-only viewing of historical ledgers | Group closed. May be restored to `ACTIVE` by Admin within **3 calendar months** from actual archive timestamp (`POST /api/v2/groups/restore.php`) |
| `PERMANENTLY_ARCHIVED` | Deletion candidate after 6 months | Eligible for permanent deletion only after **6 full calendar months** from archive date by explicit admin confirmation (`POST /api/v2/groups/permanent_delete.php`). Never automatic |

### 8.2 Expense provenance: deliberate conversions vs detached group expenses (UQ-11)

The owner approved **Option A** distinguishing deliberate user conversions from detached group expenses:
1. **Deliberate conversion:** User moves an expense out of an active group (`type = 'personal'`, `group_id = NULL`, change-log `move_out`). Active personal expense with a provenance badge.
2. **Detached group expense:** Surviving expenses from a deleted group retain group classification (`type = 'group'`, `group_id = NULL`, `is_detached = 1`). Segregated in UI under "Detached Group Expenses"; never coerced into personal spending or personal budgets.

Database constraint enforces this domain truth:
`CHECK ((type = 'personal' AND group_id IS NULL AND is_detached = 0) OR (type = 'group' AND group_id IS NOT NULL AND is_detached = 0) OR (type = 'group' AND group_id IS NULL AND is_detached = 1))`.

### 8.3 Categorization of group data (9 distinct categories)

Group retention and deletion policies operate across nine distinct data categories:

| # | Data Category | Table & Schema Objects | Mutability & Lifecycle Scope |
|---|---|---|---|
| 1 | **Group Container & Metadata** | `groups` (id, name, join_code, created_by, timestamps) | Purged upon permanent group deletion |
| 2 | **Group Membership History** | `group_members` (user_id, role, joined_at) | Tracks historical participation; purged upon permanent group deletion |
| 3 | **Personal Group Lists & Items** | `lists`, `list_items` scoped by `group_id` | Non-financial collaborative lists; purged upon permanent group deletion |
| 4 | **Expenses** | `expenses` where `group_id IS NOT NULL` | Core financial ledger; preserved during archival; purged upon permanent deletion |
| 5 | **Settlements** | `settlements` (period_start, period_end, payer, payee, amount) | Bilateral debt discharge records; preserved during archival; purged upon permanent deletion |
| 6 | **Settlement Confirmations** | `settlement_confirmations` | Proof of mutual consensus; purged upon permanent deletion |
| 7 | **Receipts & Attachments** | Image blobs, scan metadata linked to group expenses | Supporting evidence; permanently purged upon permanent group deletion |
| 8 | **Historical Username Attribution** | `users.username` on records of deleted users | Preserved in group records while records exist; ceases when records are deleted |
| 9 | **Synchronization Tombstones** | `sync_tombstones`, `sync_change_log` | Deletion tracking emitted to former members for downstream client database cleanup |

### 8.4 Permanent-deletion workflow specification (owner clarification)

The project owner clarified the permanent-deletion policy for archived groups:
- **Eligibility Window:**
  - A group becomes eligible for permanent deletion **only after it has remained continuously in the archived state for six full calendar months** (`archived_at <= NOW() - INTERVAL 6 MONTH`).
  - Early requests (before 6 months) are strictly rejected with HTTP `409 CONFLICT` (`GROUP_NOT_ELIGIBLE_FOR_DELETION`).
  - Deletion is **not automatic** and is never triggered by a background cron job.
- **Authorization (No Member Fallback):**
  - Can only be initiated by an authorized administrator (`role = 'admin'` in `group_members` at archival).
  - Unauthorized requests from regular members, non-members, or former members are strictly rejected with HTTP `403 FORBIDDEN`. There is strictly no last-member fallback.
- **Explicit Confirmation & Re-Authentication:**
  - Initiated via `POST /api/v2/groups/permanent_delete.php`.
  - Requires: `group_id`, exact `confirm_group_name`, administrator `password` re-authentication, and explicit confirmation acknowledgement.
- **Exact Data Scope across the 9 Categories:**
  - Executed inside a single atomic database transaction.
  - Hard-deletes Categories 1, 2, 3, 4, 5, 6, and 7.
  - Category 8 (historical username attribution) ceases to exist for this group because the underlying expense and settlement records are deleted.
  - Category 9 (tombstones) is generated for each former member.
- **Synchronization Propagation to Downstream Clients:**
  - Server transaction emits a group tombstone (`entity_type = 'group'`, `entity_uuid = group_uuid`) scoped to the `user_id` of each former member.
  - On their next sync pull (`GET /api/v2/sync/pull.php`), each client receives the tombstone and executes a local cascade purge in its Drift SQLite database, removing the group, its lists, items, expenses, and settlements.
  - Offline devices retain local data until reconnecting, when the tombstone is delivered and triggers the local purge.

---

## 9. Account deletion and retention (D-23, AA-11, UQ-02)

**Status: `ACCEPTED` (D-23, UQ-02). Requirement AA-11 is `SPECIFIED`.**

### 9.1 The owner-approved account deletion lifecycle

The project owner approved account deletion with comprehensive balance protection and historical username retention:

| Step | Detail |
|---|---|
| 1. Outstanding-balance pre-flight gate | Pre-flight audit across all groups: if user has **ANY unsettled balance, whether debit (owes money) OR credit (is owed money)**, account deletion is **strictly blocked**. All group obligations must be settled before deletion becomes eligible. **Financial forfeiture of credits is unapproved and prohibited.** |
| 2. Sole administrator succession procedure (Two Choices) | A sole group administrator cannot delete their account without administrative succession under one of two owner-approved choices: (a) *Manual Successor:* explicitly designate an active group member as new administrator via `POST /api/v2/groups/transfer_admin.php`; OR (b) *Automatic Transfer:* allow automatic transfer of the administrator role to the earliest-joined active member of that group (`ORDER BY joined_at ASC, id ASC LIMIT 1`). Deleting a financially active group as a substitute for transferring responsibility is prohibited. (If a 1-person group with 0 balance, it must be formally archived/closed first). |
| 3. Personal data hard delete | Purely personal records (personal expenses, personal lists, personal budgets, personal notifications) are permanently deleted in one atomic transaction; local cache on device is wiped. |
| 4. Shared financial preservation & original username retention | Shared group expenses, settlements, and confirmations are retained to preserve mathematical balance integrity for surviving members during the normal group lifecycle and archival. In historical group records, the user's **original username is preserved** accompanied by a clear context badge: `"This user deleted their account."` (the username is NOT replaced with generic "Deleted User" or a pseudonym). |
| 5. Immediate revocation | All active PHP web sessions are destroyed; database sessions (`app_sessions`) deleted; remember credentials revoked; all `sync_devices` rows set to `revoked_at = NOW(6)` (immediate `401`/`403` on all v1 and v2 endpoints). |
| 6. Archival lifecycle interaction | Historical group records containing deleted users' original usernames are preserved throughout normal group lifecycle and archival. If an administrator subsequently initiates permanent group deletion after six full months in the archived state (§8.4), all records of that group are permanently purged. |

### 9.2 Consistency obligations

| Obligation | Detail |
|---|---|
| `users` table | Password set to unmatchable non-bcrypt sentinel; email set to unique deleted placeholder; original username retained |
| Historical display | Historical attribution retains the original username with badge `"This user deleted their account."` while group records exist |
| Sessions must be destroyed | All active sessions destroyed across all devices via `app_sessions` |
| Devices | All `sync_devices` rows marked `revoked_at = NOW(6)` |
| Change log | Retains past operations for 90 days (immutable log) |

---

## 10. Disabled / deactivated accounts (AA-12, UQ-02)

**Status: `OPEN`.** There is **no** disabled-account concept today: `users` has no status column,
and every authenticated request is permitted.

| Aspect | Required behaviour (to be approved) | Current |
|---|---|---|
| Login | Refused, with the same generic error as a wrong password (no account-state disclosure) | Not applicable |
| Existing session | Rejected on the next request | Not applicable |
| Sync push/pull | Rejected | Not applicable |
| Data | Retained; the account holder may be able to read it after reactivation | Not applicable |
| Devices | Retained but unable to sync | Not applicable |
| Groups | The user remains a member for settlement purposes; their confirmation may be required to close a period | Not applicable |
| Implementation | Needs a `users.status` or `disabled_at` column — **a schema change requiring its own decision, not specified here** | — |

**Why this is not specified to DDL level:** the behaviour depends on the deletion policy (UQ-02).
A disabled state is the middle ground between active and anonymised; defining its schema before the
policy is chosen would produce two conflicting designs.

**Interim rule:** nothing in the sync design may assume a disabled state exists. Every "is this user
allowed" check reads session validity and membership, exactly as today.

---

## 11. Records with no business owner

| Table | Who may read | Who may write | Notes |
|---|---|---|---|
| `rate_limits` | Operational access only | Application code (`recordRateLimit`) | Contains IP addresses — personal data by most definitions; retained no longer than the window requires |
| `outbox_events` | Operational access only | Nothing on the HTTP path (D-32, KD-11) | Dormant; not part of any design |
| `sync_change_log` | The users in scope (§2.3) | Triggers only | No UPDATE or DELETE except retention pruning |
| `sync_tombstones` | The users in scope | Triggers only | Never pruned |
| `sync_batches` | The owning user's own batch (support reads directly) | v2 push endpoint | Contains that user's submitted payloads |
| `guest_import_batches`, `guest_import_items` | The owning user (status endpoint) and support | v2 import endpoint | Contains local references and server UUIDs; no financial content |
| `categories` | Every authenticated user | Deployment only | §5.5 |

**Rule:** an operational table is never an authorisation source, and never a place where a business
invariant is enforced.

---

## 12. Ownership matrix (AA-08, all twelve groups)

| # | Entity | Owner | Read | Create | Update | Delete | Syncable | Sync-writable offline |
|---|---|---|---|---|---|---|---|---|
| E1 | User account | The user | Self | Public (signup) | Self (profile) | **Policy open** (§9) | No | No |
| E2 | Category | None — shared | All authenticated | Deployment | — | — | No | No |
| E3 | Group | `created_by`; authority via `group_members` | Members | Any authenticated user (≤5) | Admin | Admin; semantics open (§8) | Yes (read-only) | **No** |
| E4 | Group membership | Group admins; the member themself | Members | Join (code) / create | Admin (role) | Admin / self (leave) | Yes (read-only) | **No** |
| E5 | Expense (personal) | `user_id` | Owner | Owner | Owner | Owner | Yes | **Yes** |
| E5 | Expense (group) | `group_id`; authority via role | Members | Members | Admin | Admin | Yes | **Yes** (admin rights enforced server-side) |
| E6 | Budget | `user_id` | Owner | Owner | Owner | Owner (or never) | Yes | **Yes** |
| E7 | List (personal) | `user_id` | Owner | Owner | Owner | Owner | Yes | **Yes** |
| E7 | List (group) | `group_id`; authority via role | Members | Admin | Admin | Admin | Yes | **No** |
| E8 | List item | Parent list's owner | Per parent list | Owner / admin | Owner / admin | Owner / admin | Yes | Personal only (check/uncheck excluded) |
| E9 | Notification | `user_id` (recipient) | Recipient | Producers (server) | Recipient (`is_read`) | Retention | **No** (D-19) | No |
| E10 | Settlement | `group_id` | Members | Admin | — (immutable) | Cascade only | Yes (read-only) | **No** |
| E10 | Confirmations | `(group_id, user_id)` | Members | The member themself | — | Cascade / period close | **No** | **No** (D-17) |
| E11 | Device registration | `user_id` | Owner | Owner (register) | Server (`last_seen_at`) | Owner / server (revoke) | No | No |
| E12 | Sync control records | System, scoped | Per §2.3 | Server / triggers | Server | Retention only | No | No |

**Column reading:** "Syncable" = appears in the change log. "Sync-writable offline" = the client may
queue the operation offline (D-16).

---

## 13. Verification

| Requirement | Method | Pass condition |
|---|---|---|
| AA-08 twelve entity groups have ownership | `DOC-REVIEW` | §12 has a row for each of E1–E12 |
| AA-09 creator vs owner | `DOC-REVIEW` | §3 names all four roles and states they are not interchangeable |
| AA-10 access after leaving | `CONTRACT-TEST` | A removed member receives 403 from every group endpoint and stops receiving group changes |
| AA-11 account deletion defined | `DOC-REVIEW` (blocked by UQ-02) | §9 presents options and a recommendation, marked `NEEDS APPROVAL` |
| AA-12 disabled account behaviour defined | `DOC-REVIEW` (blocked by UQ-02) | §10 states the required behaviour and the schema gap |
| AA-13 metadata separation | `DOC-REVIEW` | §2 lists the two column classes and the seven rules |
| DB-02 ownership explicit on every entity | `DOC-REVIEW` | §12 has no "unknown" ownership entry |
| PR-01 `paid_by` is the settlement key | `UNIT-TEST` | Existing settlement tests; §4.3 states the rule and forbids fallback |
| PR-09 personal owner-only | `CONTRACT-TEST` | A second user editing a personal expense is refused with `ok:false` and the frozen string (v1: HTTP 400; v2: 403) |
| PR-10 group admin-only | `CONTRACT-TEST` | A non-admin member editing a group expense is refused with `ok:false` and `Only the group admin can edit group expenses.` (v1: HTTP 400; v2: 403) |
| PR-17 limits | `CONTRACT-TEST` | The 6th group and the 11th member are refused with the existing error strings |
| Offline purge on membership loss | `CONTRACT-TEST` | A device removed from a group purges the group subtree on the next sync |
