# Technology Stack

| Field | Value |
|---|---|
| Status | Specification — Phase 2 |
| Date | 2026-11-15 |
| Rule | No package is selected because it is popular. Each entry states purpose, necessity, alternatives, platform support, and maintenance risk |

Version numbers are deliberately **not** pinned here. Pin them in `pubspec.yaml` at implementation
time and record the resolved versions in the readiness checklist. A version quoted in a design
document is stale the moment it is written.

---

## 1. Stack summary

| Tier | Technology | Status |
|---|---|---|
| Client | Flutter + Dart | Accepted (D-01) |
| Client state | Riverpod | Proposed (D-15) |
| Client routing | `go_router` | Proposed |
| Client HTTP | `dio` + cookie manager | Proposed |
| Client local DB | Drift over SQLite | Proposed (D-14) |
| Client secure storage | `flutter_secure_storage` | Proposed |
| Client charts | `fl_chart` | Proposed |
| Client PDF | `pdf` + `printing` | Proposed |
| Backend (existing) | PHP 8 procedural, `mysqli` | Accepted (D-02) |
| Backend (new) | PHP 8 procedural under `api/v2/` | Proposed (D-10) |
| Backend (optional) | Laravel behind an auth gateway | Needs approval (D-18) |
| Database | MySQL 8 / MariaDB 10.4 | Accepted (D-02) |
| Cache/locks | Redis (optional) | Existing |
| Web server | Apache (XAMPP locally, LAMP in production) | Existing |
| Background jobs | PHP CLI scripts | Existing pattern |

---

## 2. What is explicitly **not** adopted

| Technology | Why not |
|---|---|
| Node.js / npm runtime for the product | The current frontend has no build step; adding one for the legacy UI is unjustified. Flutter's build is separate and self-contained. |
| A JavaScript SPA rewrite | The legacy UI is frozen (D-31). |
| GraphQL | No requirement; the existing REST surface plus cursor sync covers the product. Adds a schema layer, a resolver layer, and a second authorisation surface. |
| gRPC / protobuf | Browser support and PHP tooling are poor; JSON over HTTPS is sufficient at this scale. |
| Kafka / RabbitMQ | D-29. |
| Kubernetes / Docker orchestration | D-29. |
| MongoDB / a document store | D-02; the domain is relational. |
| Serverless functions | The host is Apache + PHP; no requirement. |
| JWT / OAuth2 | D-09. |
| CRDT library (e.g. Yjs, Automerge) | D-11; over-engineered for admin-gated financial records. |
| Firebase / Supabase | Would introduce a second backend and a second identity system, violating D-02/D-03. |

---

## 3. Backend technology decisions

| Concern | Decision | Rationale |
|---|---|---|
| Language | PHP 8+ procedural | Existing; `AGENTS.md` "Why No Framework" |
| DB access | `mysqli` prepared statements only | Existing security posture (`SECURITY_AUDIT.md` §3.4) |
| Routing | File-per-endpoint under `api/` and `api/v2/` | Existing pattern; no router to misconfigure |
| Session | PHP session files + hardened cookie | D-09 |
| Change capture | MySQL triggers | D-13 |
| Background work | CLI scripts with a SAPI guard | Existing pattern (`scripts/`, `workers/`) |
| Optional framework | Laravel, behind a gateway | D-03, D-18 |
| Dependency management | Composer **only** if Laravel is adopted; never in `api/v2/` | Preserves zero-boot-time endpoints |

### 3.1 Why v2 is procedural PHP, not Laravel

The sync endpoints must reuse the exact rules that v1 enforces. Those rules live in procedural
endpoints and will be extracted into procedural helpers (D-30). Putting v2 behind Laravel would
mean either (a) duplicating the rules in Eloquent, violating D-03, or (b) calling procedural
helpers from a framework, which is Laravel's cost without its benefit.

**Laravel is justified for a module when the module is new, self-contained, and needs framework
facilities** — queues, scheduled jobs, an admin console, mail, or a rich validation layer. None of
the current 40 endpoints qualify.

---

## 4. Flutter package evaluation

Legend for **Platforms**: A = Android, I = iOS, W = Web, WD = Windows, M = macOS, L = Linux.

### 4.1 State management and DI

| Field | Value |
|---|---|
| Package | `flutter_riverpod` (with `riverpod_annotation` if codegen is used) |
| Purpose | State containers, dependency injection, async state modelling |
| Why needed | Sync state is asynchronous and derived (connectivity × queue depth × conflicts). The app needs compile-safe DI and testable state without a widget tree. |
| Alternatives | **Bloc** — excellent, more boilerplate per form; **Provider** — no compile-time safety, weaker async modelling; **`get_it` + `ChangeNotifier`** — manual lifecycle, easy to leak |
| Platforms | A I W WD M L (pure Dart) |
| Maintenance | High activity; large ecosystem |
| Risk | Provider API churn between major versions; mitigated by pinning and keeping providers in one layer |
| Decision | D-15 |

### 4.2 Routing

| Field | Value |
|---|---|
| Package | `go_router` |
| Purpose | Declarative routing, deep links, nested navigation, web URL support |
| Why needed | Six platforms, web URLs, and an auth redirect gate. A hand-rolled navigator would re-implement redirect logic per screen. |
| Alternatives | `Navigator` 2.0 directly (verbose); `auto_route` (codegen-heavy, similar capability) |
| Platforms | A I W WD M L |
| Maintenance | Maintained under the Flutter organisation |
| Risk | Web URL behaviour differs subtly from native; covered by platform smoke tests |
| Decision | Proposed |

### 4.3 HTTP and cookies

| Field | Value |
|---|---|
| Package | `dio` + `dio_cookie_manager` + `cookie_jar` (persisted via a small file-backed store) |
| Purpose | HTTP client with interceptors and a real cookie jar |
| Why needed | The API authenticates with a `PHPSESSID` cookie and requires `X-CSRF-Token`, `Idempotency-Key`, and `Retry-After` handling. This must exist in exactly one place. |
| Alternatives | `http` — no interceptors, manual cookie handling, manual multipart; `chopper` — codegen overhead for one client |
| Platforms | A I W WD M L. **Web caveat:** the browser owns cookies; `dio` cannot set `HttpOnly` cookies and the cookie jar is not authoritative on web. |
| Maintenance | High activity |
| Risk | Web cookie handling must rely on the browser's own jar; the client must read the `csrf_token` cookie from `document.cookie` on web and from the jar on native. Abstracted behind one `SessionCredentials` interface. |
| Decision | Proposed |

### 4.4 Local database

| Field | Value |
|---|---|
| Package | `drift` + `drift_flutter` + `sqlite3_flutter_libs`; `sqlite3` (WASM) for web |
| Purpose | Typed relational local store with migrations |
| Why needed | The domain is relational; the change queue, cursors, and conflict records need transactions; analytics need aggregates |
| Alternatives | `isar` (fast, weaker relational model, web/maintenance uncertainty); `hive` (key-value only); `sqflite` (no web/desktop parity); `objectbox` (native binary size) |
| Platforms | A I WD M L natively; W via SQLite-WASM (requires `sqlite3.wasm` + worker assets served alongside the app) |
| Maintenance | Active, widely used |
| Risk | Web WASM setup is the single most fragile part of the client build. Verify first in a spike (UQ-14). |
| Decision | D-14 |

### 4.5 Encryption at rest (optional)

| Field | Value |
|---|---|
| Package | `sqlcipher_flutter_libs` |
| Purpose | SQLCipher-backed encrypted SQLite |
| Why needed | Cached registered-user financial data and guest data are sensitive; device theft should not expose them |
| Alternatives | Rely on OS full-disk encryption; encrypt only selected columns |
| Platforms | A I WD M L. **Not available on Web.** |
| Maintenance | Active but narrower |
| Risk | Adds native build complexity; must be validated per platform. If it proves unstable, fall back to OS-level encryption and document the gap. |
| Decision | Proposed, conditional on platform verification (`SECURITY_ARCHITECTURE.md` §5) |

### 4.6 Secure storage

| Field | Value |
|---|---|
| Package | `flutter_secure_storage` |
| Purpose | Store the device UUID, guest identity, and any refresh material |
| Why needed | These are identity values; `SharedPreferences` is plaintext |
| Alternatives | `shared_preferences` (no protection); platform channels written by hand (duplicated effort) |
| Platforms | A I WD M L; W uses WebCrypto with real limitations |
| Maintenance | Active |
| Risk | Web "secure" storage is only as strong as the origin's isolation. Documented; no credential material beyond identifiers is stored there on web. |
| Decision | Proposed |

### 4.7 Identity generation

| Field | Value |
|---|---|
| Package | `uuid` |
| Purpose | Generate UUIDv7 for records, devices, and import batches |
| Why needed | D-12 requires client-generated identity |
| Alternatives | Hand-rolled generator (risk of weak randomness); `crypto` + manual formatting |
| Platforms | A I W WD M L (pure Dart) |
| Maintenance | Active |
| Risk | v7 support depends on the resolved version. **If unavailable, use v4** — the design needs uniqueness, not ordering. Verify in the spike. |
| Decision | Proposed |

### 4.8 Connectivity

| Field | Value |
|---|---|
| Package | `connectivity_plus` |
| Purpose | Detect network availability changes to trigger sync |
| Why needed | Sync must start on reconnect without user action |
| Alternatives | Poll on a timer only (wasteful, slow to react); rely on failed requests (delays recovery) |
| Platforms | A I W WD M L |
| Maintenance | Active |
| Risk | Connectivity is a hint, not a guarantee: "connected to Wi-Fi" does not mean "server reachable". Sync must still handle failure. |
| Decision | Proposed |

### 4.9 Formatting and localisation

| Field | Value |
|---|---|
| Package | `intl` |
| Purpose | Date and number formatting, locale-aware currency display |
| Why needed | The current UI formats currency and dates client-side |
| Alternatives | Hand-rolled formatting (breaks for locales) |
| Platforms | A I W WD M L |
| Maintenance | Flutter-team maintained |
| Risk | Low |
| Decision | Proposed |

### 4.10 Charts

| Field | Value |
|---|---|
| Package | `fl_chart` |
| Purpose | Category breakdown and daily spend charts (AN-1, AN-2) |
| Why needed | Replaces Chart.js, which cannot run in Flutter natively |
| Alternatives | `syncfusion_flutter_charts` (licensing considerations); `graphic` (different grammar); custom `CustomPainter` (high effort for two chart types) |
| Platforms | A I W WD M L |
| Maintenance | Active |
| Risk | Visual parity with the current palette must be checked manually |
| Decision | Proposed |

### 4.11 PDF export

| Field | Value |
|---|---|
| Package | `pdf` + `printing` (or `share_plus` + `path_provider` for save/share) |
| Purpose | Expense report and summary PDFs (AN-4, AN-5) |
| Why needed | Replaces jsPDF |
| Alternatives | Server-side PDF (rejected: invents a feature); `pdfx` (viewer, not generator) |
| Platforms | A I W WD M L. **Web caveat:** "printing" opens a browser dialog; saving is a download. |
| Maintenance | Active |
| Risk | Font embedding for non-Latin characters; verify with real notes |
| Decision | Proposed |

### 4.12 Code generation

| Field | Value |
|---|---|
| Package | `build_runner`, `freezed`, `json_serializable`, `drift_dev` |
| Purpose | Immutable models, JSON mapping, Drift schema |
| Why needed | Hand-written `fromJson`/`toJson` for ~10 entities with nullable fields is error-prone |
| Alternatives | Hand-written mapping (verbose, easy to drift from the contract) |
| Platforms | Build-time only |
| Maintenance | Active |
| Risk | Build times; mitigated by scoping generators to the data layer |
| Decision | Proposed |

### 4.13 Background execution

| Field | Value |
|---|---|
| Package | `workmanager` (Android; iOS best-effort) |
| Purpose | Attempt sync when the app is not foregrounded |
| Why needed | Users expect offline changes to upload without opening the app |
| Alternatives | Foreground-only sync (simpler, honest); platform-specific native code (duplicated) |
| Platforms | A I only. **Web, Windows, macOS, Linux: not supported.** |
| Maintenance | Active but platform-limited |
| Risk | iOS background execution is opportunistic and may not run. **The design must not depend on it.** |
| Decision | Proposed as *best-effort only*; foreground sync is the guarantee (`FLUTTER_ARCHITECTURE.md` §10.4) |

### 4.14 Deliberately rejected packages

| Package | Reason |
|---|---|
| `decimal` | Unnecessary once money is integer cents (D-21) |
| `get_it` | Riverpod provides DI (D-15) |
| `sqflite` | No web/desktop parity (D-14) |
| `hive` | Key-value only; no joins or aggregates |
| `firebase_*` | Second backend and identity system |
| Any analytics SDK | Not required; adds data-egress and privacy obligations |
| Any crash-reporting SDK | Optional; if adopted it must not capture financial payloads (`SECURITY_ARCHITECTURE.md` §8.3) |

---

## 5. Verification tasks before implementation

These are spike tasks, not Phase 2 work.

| # | Task | Pass condition |
|---|---|---|
| V-1 | Drift + SQLite-WASM on Flutter Web | Read/write a 10 000-row table; survives reload |
| V-2 | `dio` cookie persistence across app restarts on Android, iOS, Windows | Session survives a cold start |
| V-3 | CSRF bootstrap without HTML scraping | `GET /api/v2/auth/session.php` returns a usable token |
| V-4 | `uuid` v7 availability in the resolved package version | v7 generated; fallback to v4 documented |
| V-5 | SQLCipher on Android, iOS, Windows | Encrypted DB opens; unencrypted tool cannot read it |
| V-6 | `flutter_secure_storage` on Windows and Linux | Value survives restart; libsecret/DPAPI present |
| V-7 | `fl_chart` renders the two required chart types with the project palette | Visual match approved |
| V-8 | `pdf` embeds a font covering the notes actually used | No missing glyphs |
| V-9 | `workmanager` behaviour on a real Android device and a real iPhone | Documented actual behaviour, not assumed |

---

## 6. Compatibility and maintenance policy

1. **Pin exact versions** in `pubspec.yaml` and commit `pubspec.lock`.
2. **One major upgrade per release**, with the platform smoke suite run before merge.
3. **No package may be added without** a row in §4 explaining purpose, alternative, platforms, and
   risk.
4. **No transitive dependency may be trusted for security-critical behaviour** (cookies, CSRF,
   crypto) without a source review.
5. **Abandoned packages** (no release in 12 months, open security issues) are not adopted.
