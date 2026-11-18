# Security Architecture

| Field | Value |
|---|---|
| Status | Specification — Phase 2 |
| Date | 2026-11-15 |
| Purpose | State the security posture of the target system: what is preserved, what is added by offline storage and sync, and how each risk is controlled |
| Requirements | SE-01…SE-13, PR-19 |
| Decisions | D-08, D-09, D-14, D-19, D-20, D-23, D-25, D-28 |
| Rule | No existing control is weakened. Every new surface inherits the existing controls rather than inventing its own |

---

## 1. Scope

| Topic | Section |
|---|---|
| The threat model and assets | §2 |
| Existing controls, and the proof they are not weakened | §3 |
| Transport | §4 |
| Local data at rest | §5 |
| Device compromise and theft | §6 |
| Injection, storage, and output encoding | §7 |
| Logging and sensitive data | §8 |
| Privacy, retention, deletion, and export | §9 |
| The new attack surface introduced by sync | §10 |

Companions: [`SESSION_MANAGEMENT.md`](SESSION_MANAGEMENT.md),
[`ACCESS_CONTROL.md`](ACCESS_CONTROL.md),
[`../api/AUTHENTICATION_AND_AUTHORIZATION.md`](../api/AUTHENTICATION_AND_AUTHORIZATION.md),
[`../SECURITY_AUDIT.md`](../SECURITY_AUDIT.md) (the Phase 1 audit of the current system).

---

## 2. The threat model

### 2.1 Assets

| Asset | Where it lives | Sensitivity |
|---|---|---|
| Credentials (password hash) | `users.password` (bcrypt) | High |
| Session cookie | The client's cookie jar | High — it is a bearer credential |
| Device secret | Client secure storage; a hash server-side | Medium — continuity only, no data access by itself |
| Financial records | MySQL; the client's local store | Medium–High — personal data |
| Group membership and roles | MySQL | Medium |
| The change log and tombstones | MySQL | Medium — row snapshots |
| Guest data | The device only | Medium — never server-side |

### 2.2 Adversaries considered

| # | Adversary | Capability | Primary controls |
|---|---|---|---|
| T1 | Network attacker | Reads and modifies traffic | HTTPS, `Secure` cookies, `HttpOnly`, certificate validation |
| T2 | Cross-site attacker | Serves a page that targets the API | CSRF token, `SameSite=Lax`, CSP `connect-src 'self'`, no CORS |
| T3 | Malicious authenticated user | Owns an account, manipulates requests | Server-side authorisation, ownership scoping, ID-manipulation tests |
| T4 | Thief with a stolen device | Has the locked or unlocked device | OS lock, 24-hour session expiry, device revocation, optional SQLCipher |
| T5 | Local data examiner | Reads the device's storage | SQLCipher where available; documented gap on Web |
| T6 | Malicious insider with database access | Reads/writes the database | Parameterised queries, least-privilege accounts, the change log as an audit trail |
| T7 | Supply-chain attacker | Compromises a package | Pinned dependencies, a small dependency set, no unused packages |
| T8 | Denial of service | Floods the API | Rate limits per user/IP/surface, bounded payloads, bounded batch sizes |

### 2.3 Out of scope

| Not in scope | Reason |
|---|---|
| A compromised server host | If the host is owned, nothing in the application model survives; the deployment section states the hardening requirements |
| A compromised operating system on a client | The device owner is trusted; SQLCipher protects only the file at rest |
| End-to-end encryption of group data | Not a requirement; the server must compute balances |
| Nation-state traffic analysis | Out of scope for a household expense application |

---

## 3. Existing controls are not weakened (SE-13)

**Requirement:** SE-13 "existing security controls are not weakened by the new design".

| # | Control | Current behaviour | Effect of this design |
|---|---|---|---|
| 1 | Hardened session cookie | `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, `path=/`, `use_strict_mode`, cookies-only | **Unchanged.** `config/session.php` is not edited (D-32) |
| 2 | Session rotation at login | `session_regenerate_id(true)` | **Unchanged** |
| 3 | 24-hour absolute expiry | `login_time` enforced per request | **Unchanged** (D-24 open, not silently altered) |
| 4 | CSRF on every write | `verifyCsrf()` with a header-first token and an IP failure gate | **Unchanged.** v2 uses the same helper (AP-09) |
| 5 | Authentication gate on every protected endpoint | `requireAuth()` | **Extended to v2.** No v2 endpoint skips it |
| 6 | Rate limiting | 120/min user, 240/min IP, action buckets, `login` 5/15 min, `signup` 3/h | **Preserved and extended** with `api_sync` (additive, D-28) |
| 7 | Prepared statements only | `mysqli` everywhere | **Preserved** (§7.1) |
| 8 | Read-only notification GETs | No write on `GET` | **Preserved** (KD-07) |
| 9 | Least-privilege DB account | `expmg_app` with DML only | **Preserved**; trigger creation requires an elevated migration account |
| 10 | Webroot lockdown | `.htaccess` denies dotfiles, dumps, docs, logs, tests, internal dirs | **Preserved** |
| 11 | CLI-only gates | Workers and the test runner return 403 over HTTP | **Preserved**; new CLI scripts follow the pattern |
| 12 | No CORS anywhere | Zero `Access-Control-*` headers | **Preserved and relied upon** (D-08) |
| 13 | CSP | `default-src 'self'` with a documented allowlist | **Unchanged**; the Web build must fit it (§4.4) |
| 14 | No anonymous API | Every endpoint except login/signup/logout requires a session | **Preserved**; guest mode is local-only (AA-02) |
| 15 | Output escaping | Raw text is stored; escaping happens at render time | **Preserved** (§7.2, PR-19) |

### 3.1 The verification obligation

`SE-13` is verified by `DOC-REVIEW` plus the existing suite: the baseline stays
`433 passed / 105 failed / 538 tests`, and any security-related assertion changing count is a
stop-the-line finding.

### 3.2 What the design explicitly does **not** do

| Not done | Why |
|---|---|
| Add CORS headers | Enables a class of attack with no product benefit (D-08) |
| Introduce tokens or a second credential | A second revocation and CSRF story (D-09) |
| Relax the CSP for the Flutter Web build | If a build needs an eval-like facility, that is a decision, not a silent relaxation |
| Weaken `SameSite` to `None` | Only needed for cross-origin, which is forbidden |
| Disable `HttpOnly` so JavaScript can read the session | Never |
| Cache authorisation decisions | Stale-authority bugs (`ACCESS_CONTROL.md` §8) |
| Store a password for silent re-authentication | A new credential store (D-24 anti-pattern) |

---

## 4. Transport security (SE-01)

**Requirement:** SE-01 "transport security requirements are stated per platform".

### 4.1 Per platform

| Platform | Transport | Certificate validation | Requirements |
|---|---|---|---|
| Flutter Web | HTTPS (same origin as the API) | The browser | The deployment must serve HTTPS; `Secure` cookies; the CSP already permits `self` only |
| Android | HTTPS | Platform trust store; **no** custom `badCertificateCallback` in release | Android 7+ trusts user CAs by default; certificate pinning is **not** required (a pinned cert breaks on rotation with no operational process to manage it) |
| iOS | HTTPS | Platform trust store; **no** custom `badCertificateCallback` | App Transport Security is left enabled (no arbitrary-loads exception) |
| Windows / macOS / Linux | HTTPS | Platform trust store | No exceptions |
| Local development | HTTP is permitted on `localhost`/XAMPP | — | The cookie's `Secure` flag follows `expmgIsHttps()`, so HTTP works locally and HTTPS is enforced in production |

| Rule | Statement |
|---|---|
| HTTPS is mandatory in every non-local environment | DP-04 |
| A client must never disable certificate validation | Not even behind a "development" flag in a release build |
| Cleartext traffic is refused by the client | No HTTP fallback; a failed TLS handshake is an error, not a downgrade |
| The API base URL is configuration | DP-02; it is never hardcoded and never user-editable in a shipped build |

### 4.2 What transport protects

| Protected | Not protected |
|---|---|
| The session cookie in transit | Data at rest on the device (§5) |
| Credentials during login | A compromised client process |
| Financial payloads in transit | A malicious server (out of scope) |
| Device secrets in transit | — |

### 4.3 Login transport specifics

| Aspect | Rule |
|---|---|
| Credentials are sent only in a `POST` body over TLS | Never in a URL, never in a query string |
| The server never logs a password | §8 |
| Login failures are generic | No account-existence disclosure (`api/login.php` behaviour, verified) |
| The login IP limit applies before the credential check | 5 / 15 min |

### 4.4 CSP compatibility for Flutter Web

| Rule | Statement |
|---|---|
| The Web build must load under the existing CSP | `default-src 'self'`, `script-src 'self'` plus the existing CDN allowlist, `connect-src 'self'` |
| CanvasKit/WASM assets must be served same-origin | A CDN default is a CSP failure and a third-party dependency |
| If a relaxation is required, it is a decision | With a threat assessment, not an inline change |
| Verification | A browser-console-clean smoke test (§`../testing/ARCHITECTURE_VALIDATION_PLAN.md` §14) |

---

## 5. Local data at rest (SE-02, SE-03)

**Requirements:** SE-02 "local data at rest is protected where the platform allows"; SE-03
"credentials and tokens are stored in platform secure storage".

### 5.1 What is stored where

| Data | Store | Protection |
|---|---|---|
| Session cookie | Platform cookie jar | OS sandbox; `HttpOnly` (native clients honour the flag as a convention, not a browser enforcement) |
| Device secret | Platform secure storage (Keystore / Keychain / DPAPI / libsecret) | Hardware-backed where available |
| CSRF token | **Memory only** | Never persisted |
| Entity data (expenses, lists, budgets) | Drift/SQLite | SQLCipher where available (§5.2) |
| Queue, cursor, conflicts | Drift/SQLite | Same as entity data |
| Guest data | Drift/SQLite, guest scope | Same as entity data |

| Rule | Statement |
|---|---|
| A credential is never in the SQLite database | Including the cookie, the CSRF token, and the device secret |
| A credential is never in a log | §8 |
| The CSRF token is memory-only | Re-obtained from `auth/session.php` on start-up |

### 5.2 Per-platform protection (SE-02)

| Platform | Encryption at rest | Notes |
|---|---|---|
| Android | SQLCipher, key in Keystore | Recommended for the release build |
| iOS | SQLCipher, key in Keychain | Recommended |
| Windows | SQLCipher, key protected by DPAPI | Recommended |
| macOS | SQLCipher, key in Keychain | Recommended |
| Linux | SQLCipher, key in the platform secret store (libsecret) or a user-only file | Documented caveat: a headless environment may have no secret store |
| **Web** | **Cannot use SQLCipher** | The browser sandbox and origin isolation are the protection. Stated plainly, not glossed (UQ-14) |

| Rule | Statement |
|---|---|
| The key is never derived from a weak value | No "derived from the device id" or a hardcoded key |
| Losing the key loses the local cache | Acceptable: the server is the source of truth; unsent queue entries would be lost, which is why the app warns before wiping |
| Encryption is not a substitute for the OS lock | Both are expected |
| The design must work without encryption on Web | Not as a fallback, but as the documented model for that platform |

### 5.3 Credential storage (SE-03)

| Credential | Storage | Rule |
|---|---|---|
| Session cookie | Cookie jar | Persisted only if the user stays signed in; cleared on logout |
| Device secret | Secure storage | Never written to the local database; never logged; never synchronised |
| CSRF token | Memory | Re-fetched per session |
| Password | **Not stored, ever** | No silent re-authentication (D-24) |
| Guest data | No credential involved | Guest mode has no session |

| Rule | Statement |
|---|---|
| Secure storage is the only place for the device secret | A plain `SharedPreferences`/`localStorage` write is a defect |
| A release build never ships a hardcoded secret | No API keys exist in the product; if one is ever added, it is not embedded in the client |
| A debug build's insecure storage is still a defect in shared code | The storage abstraction must not silently downgrade |

### 5.4 What encryption does and does not achieve

| Achieves | Does not achieve |
|---|---|
| A stolen file cannot be read without the key | An unlocked, running app can read its own data |
| A backup file is not readable | A rooted/jailbroken device with a live key can be read |
| Defence in depth against a filesystem-level thief | Protection against a malicious client build (the user controls their own data anyway) |

---

## 6. Device compromise and theft (SE-09)

**Requirement:** SE-09 "device compromise and stolen-device behaviour is defined".

### 6.1 The scenarios

| Scenario | What an attacker has | Controls | Outcome |
|---|---|---|---|
| Theft, device locked | The hardware | OS lock; SQLCipher key in the platform keystore | Data unreadable without the OS credential |
| Theft, device unlocked mid-session | A live session and a live app | 24-hour absolute session expiry; **device revocation** (sync stops); the owner changes their password (future) | Time-bounded access; the attacker cannot sync after revocation |
| Theft, app closed | Stored data; a persisted cookie | OS lock; SQLCipher; session expiry | Unreadable at rest; the session dies within 24 hours |
| Lost browser session (Web) | A live cookie in a browser profile | `HttpOnly`; 24-hour expiry; **no** local encryption | Bounded by expiry; documented gap |
| A second-hand device not wiped | `app_private` storage | OS-level app data removal on uninstall | Documented in the deployment/user guidance |

### 6.2 What the user can do

| Action | Effect | Available? |
|---|---|---|
| Revoke a device | That device cannot push or pull (`403 DEVICE_REVOKED`), its active sessions are destroyed (`app_sessions`), and remember credentials revoked | Yes, `/api/v2/devices/revoke.php` (D-25 Option b) |
| Sign out on this device | Session destroyed; local data retained | Yes (`logout.php`) |
| Change password | Destroys all other active sessions via `app_sessions` | Future endpoint; foundation provided by D-25 |
| Delete the account | Full account deletion with balance audit and personal data purge | Approved (D-23, UQ-02) |
| Wipe local data | Removes the local cache and queue | Yes (the app's clear-data action) |

### 6.3 The honest limitations

| Limitation | Statement |
|---|---|
| Revocation scope | D-25 Option (b) immediately invalidates sync and deletes sessions from `app_sessions`. Both v1 and v2 calls fail immediately |
| Web has no encryption at rest | SQLCipher is unavailable in the browser (UQ-14) |
| Offline devices cannot be remotely commanded | If a revoked device is offline, local cache is protected only by OS locks and local timeout until next network contact |
| Re-registration requires full login | A revoked device cannot resume sync without fresh user credentials and password authentication |
| Guest data has no backup | Losing the device loses it (D-04) — disclosed in the UI |

### 6.4 Recommended user guidance (documentation, not code)

| Situation | Guidance |
|---|---|
| Lost device | Revoke it, then sign in elsewhere; change nothing else until the deletion policy exists |
| Shared computer | Do not stay signed in; sign out; the session expires within 24 hours regardless |
| Selling a device | Sign out, then uninstall or clear app data |
| Guest on a borrowed device | Do not enter real financial data; guest data cannot be recovered |

---

## 7. Injection, storage, and output encoding (PR-19, SE-04)

**Requirements:** PR-19 "raw text is stored; escaping happens at render time only"; SE-04 "SQL
injection prevention is preserved (prepared statements only)".

### 7.1 SQL injection (SE-04)

| Rule | Statement |
|---|---|
| Every query is a prepared statement | `prepare` → `bind_param` → `execute`; the statement is checked before binding; `close()` afterwards |
| No variable is interpolated into SQL | Including table names derived from input, `LIMIT` values, `ORDER BY` clauses, and `IN (...)` lists |
| `LIMIT`/`OFFSET` come from integers | `(int)` cast plus `parsePagination()`'s own bound; never a raw string |
| `IN` lists are built from repeated placeholders | Never by joining values |
| The change-capture triggers use `JSON_OBJECT` | No string concatenation in the trigger body |
| The `sql_mode` is identical across stacks | Otherwise a `CHECK` could be bypassed (`BACKEND_COEXISTENCE.md` §4.2) |
| Dynamic ordering is mapped, not interpolated | A whitelist maps a client value to a fixed column name |
| A migration/backfill script uses literals and bound values | Never a shell variable |
| Verification | `STATIC-CHECK`: a grep for `$conn->query(` with a variable, and for a string-concatenated `WHERE`, must return zero results |

### 7.2 Output encoding and stored text (PR-19)

**Requirement:** PR-19 "raw text is stored; escaping at render time only".

| Rule | Statement |
|---|---|
| MySQL stores **raw** text | No HTML entities in the database |
| Escaping happens at render time | `escapeHTML()` in the web client; Flutter renders text as text, never as markup |
| Why | Write-time escaping double-escapes on an edit round-trip, breaks `LIKE` search, and corrupts exports |
| The three suite assertions that expect entities in the column remain rejected | A documented policy decision (`docs/TEST_BASELINE.md`) |
| The Flutter client never renders untrusted text as HTML | There is no HTML rendering path for user data |
| A note containing `<script>` is stored verbatim and displayed verbatim | Which is exactly what a user typing it expects |
| A notification message containing a user's name is escaped at render time | The web UI escapes; the Flutter UI renders text |
| JSON responses are not HTML-escaped | `json_encode` handles the wire format; a client must not assume HTML safety |
| Verification | `CONTRACT-TEST`: store `O'Brien` and `<script>`, read it back, and assert the stored bytes are raw; then verify each UI escapes on render |

### 7.3 Other injection surfaces

| Surface | Control |
|---|---|
| Command injection | No shell execution in the application path; CLI scripts take no user input for command construction |
| `eval`, dynamic includes from input | Never |
| Path traversal | No endpoint takes a filesystem path from a client |
| JSON injection into a log line | Structured logging; the message and context are encoded, not concatenated |
| CSV formula injection in an export | An export prefixes a leading `=`, `+`, `-`, or `@` in a text cell with a quote (if an export is ever added) |
| Trigger-side injection | Values are read from `NEW`/`OLD`; the actor variables are set with bound parameters |

---

## 8. Logging and sensitive data (SE-07)

**Requirement:** SE-07 "sensitive data is not logged". **Effect requirement:** no secret in a
response (§`../api/API_ERROR_HANDLING.md` §2.3).

### 8.1 Never logged

| Item | Where it could leak |
|---|---|
| Passwords (plaintext or hash) | A login handler; a validation error |
| Session ids / cookie values | A request dump; a debug log |
| CSRF tokens | A request dump |
| Device secrets | A registration handler; a sync request dump |
| Full request bodies for authenticated writes | A debug log |
| Financial amounts paired with a user's identity in a client log | Client crash reporting (§8.3) |

### 8.2 Server logging rules

| Rule | Statement |
|---|---|
| `logMessage(level, message, context)` | The context carries endpoint, method, user id, IP, and a reason |
| A failure logs a code and an endpoint, not a payload | The `error` string is safe by construction (it is what the client sees) |
| A permission refusal is INFO/WARNING, not ERROR | Volume control; it is expected behaviour |
| A `500`, a trigger failure, or a batch failure is ERROR | With the endpoint and the batch key, not the payload |
| A CSRF failure records the IP and the endpoint | Existing behaviour, with the submitted token length only |
| A log file is never web-readable | `.htaccess` denies `*.log` and `logs/` (KD-09) |
| Log retention is bounded | The deployment section states it; a log is not an archive |

### 8.3 Crash reporting and client telemetry (SE-07)

| Rule | Statement |
|---|---|
| Crash reporting is optional | If adopted, it must not capture financial payloads, notes, or credentials |
| No analytics SDK is required | The product has no analytics requirement |
| A crash report carries: app version, platform, a stack trace, a sync state summary (queue size, conflict count, last error code) | Enough to diagnose, nothing sensitive |
| User-identifying data is opt-in or absent | A user id may be included only with consent |
| A stack trace never contains a payload | Any exception that includes a payload in its message is a defect |
| Verification | `DOC-REVIEW` plus a review of the chosen SDK's default collection before adoption |

### 8.4 Client logging

| Aspect | Rule |
|---|---|
| Release builds log counts, codes, batch keys, and timings | Never payload contents |
| Debug builds may log more locally | But the same code must not leak in release; the logging call sites are the same |
| A local log file is bounded and rotated | And cleared on an account switch |

---

## 9. Privacy, retention, deletion, and export

### 9.1 Data inventory

| Data | Purpose | Sensitive? | Retention |
|---|---|---|---|
| Account (username, email, password hash) | Authentication | Yes | Life of the account (D-23 open) |
| Expenses, lists, budgets, settlements | The product | Yes (financial) | Life of the account |
| Group membership | Collaboration | Yes | Life of the membership |
| Notifications | Informing the user | Medium | 7 days |
| Change log | Sync + diagnostics | Medium (row snapshots) | 90 days (UQ-09) |
| Tombstones | Deletion propagation | Low | Indefinite (UQ-09) |
| Sync batches | Idempotency + diagnostics | Medium (payloads) | 45 days |
| Guest import audit | Verification + audit | Low | Life of the account |
| Rate limits | Abuse prevention | Medium (IP addresses) | Action-aware |
| Logs | Diagnostics + security | Medium | Bounded (§8.2) |

### 9.2 Retention

| Rule | Statement |
|---|---|
| Every retained item has a stated window | §9.1; a window that is not written down is not a policy |
| Retention is enforced by a script, not by intention | `cleanup_notifications.php`, `cleanupRateLimits()`, the proposed `prune_sync_log.php` |
| A retention change is a decision | UQ-09 |
| Backups have their own window | `../deployment/DEPLOYMENT_ARCHITECTURE.md` §8 |

### 9.3 Data export (SE-11)

**Requirement:** SE-11 "data export is defined or explicitly excluded".

| Aspect | Status |
|---|---|
| **Server-side export endpoint** | **Not provided.** No requirement, and an export endpoint is a new bulk-data surface needing its own authorisation and rate-limit design |
| Client-side PDF | **Provided.** Analytics and PDF generation are client-side (`MIGRATION_PRESERVATION_PLAN.md` §4.2); the file is the user's own and outside the application's control |
| CSV export of an expense list | **Not provided** in the current UI; if added, it is client-side generation from local data, with CSV-injection protection (§7.3) |
| "Download my data" (a machine-readable account export) | **Explicitly excluded** from this phase. If a legal or product requirement appears, it is a new decision with its own security design (scoping, rate limiting, and a delivery mechanism that does not weaken authentication) |
| Guest data export | **Explicitly disabled** (D-38, UQ-16). The guest interface presents an educational prompt ("Account Required to Export Data"); full CSV, JSON, and PDF export is unlocked only upon registration and migration |
| What this means for a user leaving | There is no bulk export today; the PDF and the visible records are what exist. Stated plainly rather than implied |

### 9.4 Deletion

| Aspect | Authority | Policy & Execution |
|---|---|---|
| Group deletion | D-26, UQ-07 (`../database/DATA_OWNERSHIP.md` §8) | 4-state archival lifecycle: `ACTIVE` -> `ARCHIVE_PENDING` -> `ARCHIVED` -> `PERMANENTLY_ARCHIVED`. 3-month restoration window; optional permanent deletion after 6 months by admin confirmation |
| Account deletion | D-23, UQ-02 (`../database/DATA_OWNERSHIP.md` §9) | Pre-flight balance gate (any unsettled balance, debit or credit, blocks deletion; financial forfeiture prohibited; sole admin role transferred); personal data purged; shared records preserved with original username retained and context badge `"This user deleted their account."`; immediate session/device revocation |
| Local data on an account switch | Wiped (`../synchronization/OFFLINE_DATA_MANAGEMENT.md` §5.2) | Switching accounts purges registered user cache |
| Deleting a device | Revocation, not deletion of data | D-25 Option (b) revokes sync and invalidates sessions immediately |
| Guest data | Deleted only after a verified import, or explicitly by the user | If import is skipped, guest partition is preserved |

| Rule | Statement |
|---|---|
| The server's records are the authoritative copy | A local wipe is not a deletion request |
| Deletion is never a side effect of another action | Logging out, revoking, or expiring a session never deletes data |

---

## 10. The new attack surface introduced by sync (SE-12)

**Requirement:** SE-12 "new attack surface introduced by sync is enumerated".

| # | New surface | Threat | Control | Requirement |
|---|---|---|---|---|
| 1 | `/api/v2/` endpoints | Unauthenticated access | `requireAuth()` on every endpoint except session status; CSRF on writes | AA-01, AP-09 |
| 2 | The change log | Reading another user's changes | Scope columns computed in the trigger; every pull is scope-filtered | SY-15 |
| 3 | Scope manipulation | A client claims a wider scope | Scope is never taken from the client | SE-06 |
| 4 | Cursor manipulation | Skipping or replaying changes | Server-supplied cursors; `400 INVALID_CURSOR` for malformed input; `410 CURSOR_TOO_OLD` for a cursor below the retained floor **or above the watermark** (`SYNC_API_SPECIFICATION.md` §6.4) | SY-17 |
| 5 | Device identity | Impersonating another device | `device_uuid` + `device_secret`; scoped to one user; revocation | D-25 |
| 6 | Push payloads | Injecting ownership or flags | `user_id`, `type`, `is_post_settlement`, `created_by` ignored/overridden | SE-06 |
| 7 | Batch replay | Applying an operation twice | `batch_key` + `request_hash`; per-record `uuid` + `base_version` | VC-10 |
| 8 | Bulk payloads | Resource exhaustion | Max 100 records or 1MB payload per batch (`413 Payload Too Large`), bounded page sizes | AP-08, D-28 |
| 9 | The rate-limit bucket | Sync traffic starving the app | A dedicated `api_sync` bucket (120 req/min user, 240 burst device), additive to the existing ones | D-28 |
| 10 | Local storage | Reading a device's data | SQLCipher where available; documented Web gap | SE-02 |
| 11 | Guest import | Importing into an account the user does not own | Session-scoped; validated like any write; idempotent; two-tier duplicate check | SE-06, GM-07, D-22 |
| 12 | Tombstones | Inferring deletions of others' records | Same scope filtering as the change log | SY-15 |
| 13 | Diagnostics endpoints | Leaking counts of others' data | Digests computed over the caller's scope only | SY-18 |
| 14 | Trigger-created rows | Bypassing application validation | Triggers only *record*; they never validate or mutate business values except `version`/`updated_at` | D-13 |
| 15 | Actor session variables | Attributing a write to another user | Set only by the middleware from the session; never from a header other than the validated device id | D-13 |
| 16 | Revoked-device bypass | Re-registering to escape revocation | `403 DEVICE_REVOKED`; revoked devices cannot sync or authenticate; re-registration requires fresh login | D-25, D-34 |
| 17 | Offline queue | A crafted local queue bypassing the UI's online-only restrictions | The server rejects online-only operations; the client's queue is a convenience, not a control | D-16, D-17 |
| 18 | Conflict resolution | "Keep mine" used as a forced overwrite | A resolution is a new write with a fresh `base_version`; all rules re-validated | CR-4, CR-5 |

### 10.1 The rule for every new surface

| Rule | Statement |
|---|---|
| A new endpoint inherits every existing control by default | Authentication, CSRF, rate limiting, prepared statements, logging rules |
| An exemption requires a decision | Never a code comment or a bypass flag |
| Every surface is listed in §10 | An unlisted surface is an undocumented risk (the §10 table is part of the acceptance criteria) |
| Every surface has a PENTEST case | §13 of the validation plan |

---

## 11. Status of architectural questions

| UQ | Status | Security decision & policy |
|---|---|---|
| UQ-02 — account deletion/retention | `APPROVED` | Balance pre-flight gate, personal data purged, shared records preserved with original username retained and context badge, immediate session revocation (D-23) |
| UQ-03 — session lifetime | `APPROVED` | Option C: 7-day inactivity web sessions; 30-day inactivity remember credentials in native secure storage (D-24, D-33) |
| UQ-04 — device revocation model | `APPROVED` | Option B: Full revocation via `app_sessions` table; terminates sync and authenticated sessions immediately (D-25) |
| UQ-14 — Web as a first-class target | `APPROVED` | Staged rollout: Release 1 Native (Android, iOS, Windows, macOS, Linux); Release 2 Flutter Web post A-WEB-1 validation |
| UQ-15 — cross-origin API access | `APPROVED` | Same-origin hosting confirmed (`/ExpMgWEB/app/`); no CORS permitted (D-08) |
| UQ-09 — retention windows | `APPROVED WITH PROVISIONAL POLICY` | 90-day sync change log, indefinite tombstones, 45-day batches, account-life guest import audit |

---

## 12. Verification

| Requirement | Method | Pass condition |
|---|---|---|
| SE-01 transport per platform | `DOC-REVIEW` | §4.1 covers all six platforms and forbids validation bypass |
| SE-02 local protection | `PLATFORM-SMOKE` | SQLCipher works on each native platform; the Web gap is documented |
| SE-03 credentials in secure storage | `PLATFORM-SMOKE` | The device secret is in Keystore/Keychain/DPAPI/libsecret and never in the database |
| SE-04 SQL injection prevention | `STATIC-CHECK` | Zero interpolated queries; the existing grep gate passes |
| SE-07 sensitive data not logged | `DOC-REVIEW` + `PENTEST` | No log line contains a credential; crash reporting captures no payload |
| SE-09 device compromise defined | `DOC-REVIEW` | §6 has a scenario, a control, and an honest limitation for each |
| SE-11 export defined or excluded | `DOC-REVIEW` | §9.3 states server-side export is excluded; client-side export is available post-registration (guest export disabled per owner decision) |
| SE-12 attack surface enumerated | `PENTEST` | All 18 rows have a test; no unlisted surface exists |
| SE-13 controls not weakened | `DOC-REVIEW` + suite baseline | `433 / 105 / 538` unchanged; every control in §3 is intact |
| PR-19 raw text stored | `CONTRACT-TEST` | Entities are not written; the value round-trips byte-identically; each UI escapes on render |
| §4.4 CSP compatibility | `PLATFORM-SMOKE` | The Web build runs console-clean under the real CSP |
