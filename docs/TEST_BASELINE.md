# Test Baseline

**This file is the canonical test record for the project.** Any other document that quotes a
different total or pass/fail count is stale.

---

## 1. Canonical command

```powershell
# From C:\xampp\htdocs\ExpMgWEB
php tests/run_tests.php
```

The canonical suite is **`tests/run_tests.php`**. The `run_tests.php` in the repository root is a
different, stale copy and must not be used for baselines.

Requirements:

- XAMPP Apache + MySQL running on default ports.
- The `ExpMgWEB` database reachable with the configured credentials.
- CLI SAPI (the runner returns 403 over HTTP).

---

## 2. Current baseline

Captured 2026-11-15 against code state `66dedc1` (the splash `data-redirect` fix on top of the
audited snapshot `5ed8de3`). No PHP, frontend, schema, migration, or test file has changed since
`66dedc1` — every later commit in this audit touches Markdown and `Issues.txt` only — so the
same suite result applies to the current HEAD:

```
RESULTS: 433 passed, 105 failed out of 538 tests
```

Full captured output:
[`evidence/phase1-test-baseline.txt`](evidence/phase1-test-baseline.txt) — UTF-8, 757 lines

When reading the capture: it contains **433** `PASS:` lines but **210** `FAIL:` lines, because
every failure is printed twice — once inside its section and once again in the trailing
`Failed tests:` summary. 210 printed lines = 105 distinct failures.

The capture is plain UTF-8 with no BOM. Re-capture it with raw byte redirection on Windows;
`>` in PowerShell writes UTF-16LE and makes the file unreadable to `git diff`/`grep`:

```powershell
cmd /c "php tests\run_tests.php > docs\evidence\phase1-test-baseline.txt 2>&1"
```

| Metric | Value |
|---|---:|
| Total tests | 538 |
| Passed | 433 |
| Failed | 105 |
| Sections | 35 |
| Exit status | non-zero (failures present) |

The 538 total (rather than the historical 539) is documented variance: one conditional test
block does not execute on the rebuilt database.

---

## 3. Failure breakdown

All 105 failures are known and expected. There are **no unknown failures**.

| Section | Failures | Cause | Status |
|---|---:|---|---|
| 28 — Service Layer | 88 | `ExpenseService`, `GroupService`, `SettlementService`, `ListService`, `BudgetService`, `NotificationService` do not exist (KD-06) | Deferred |
| 34 — Ephemeral Notification System | 11 | File/Redis notification store and `NotificationService` built but unwired (KD-05) | Deferred |
| 31 — Logging & Observability | 1 | `31j` scans the same missing service files for `logger.php` | Deferred with KD-06 |
| 32 — Pagination & Query Performance | 2 | `32u`, `32v` scan the same missing services for `LIMIT`/`array_slice` and `paginationMeta` | Deferred with KD-06 |
| 9 / 22 / 26 — write-time escaping | 3 | Assertions expect HTML entities stored in MySQL; rejected by design | Rejected |
| **Total** | **105** | | |

### 3.1 Section 28 — 88 failures

The suite expects a business service layer that was planned in v1.7 but never built. The 88
assertions cover:

- six `Service file exists` checks;
- six `declares class` checks;
- six constructor checks;
- five `stores conn as property` checks;
- 32 `includes …Service.php` checks across endpoints;
- 32 `has no raw SQL` checks across endpoints;
- one `NotificationService uses notification store abstraction` check.

Do **not** create stubs to satisfy these. Section 28 is specification for a future refactor, not
a cosmetic gate. The refactor happens after the Flutter migration, with characterization tests
written first.

### 3.2 Section 34 — 11 failures

| Assertion | Why it fails |
|---|---|
| `34b` NotificationService uses notification store | Service does not exist |
| `34c` NotificationService has consume() | Service does not exist |
| `34k` Consumed notification is removed | Consumer not implemented |
| `34o` history.php uses NotificationService | `history.php` is raw MySQL |
| `34o` history.php no raw SQL | same |
| `34r` ExpenseService uses notification_store | service missing |
| `34r` ListService uses notification_store | service missing |
| `34r` SettlementService uses notification_store | service missing |
| `34r` GroupService uses notification_store | service missing |
| `34x` Frontend sends event_id | frontend still keyed on numeric ids |
| `34x` Frontend tracks lastSeenEventId | same |

The ephemeral notification system is dormant. Producers and consumers both use MySQL. Wiring
one side only would create an inconsistent system, so this stays deferred.

### 3.3 Section 31/32 — 3 failures

`31j`, `32u`, and `32v` scan the missing service files. They fail for the same KD-06 reason.
They are not independent regressions.

### 3.4 Section 9/22/26 — 3 policy-rejected assertions

```
Special chars preserved in DB
XSS group name escaped in DB
XSS script tag escaped in DB
```

These expect HTML entities such as `O&#039;Brien` or `&lt;script&gt;` to be stored in MySQL.
That is explicitly rejected:

- write-time escaping double-escapes on edit round-trips;
- it breaks `LIKE` search;
- it corrupts exports.

Raw text is stored through prepared statements and escaped at render time. These three
assertions are policy failures, not product failures.

### 3.5 Section 30 — now passing

The historical baseline listed 7 section-30 failures (response helper adoption, 405, 400
validation). At this snapshot the module endpoints include `response.php` and method guards, and
**section 30 has zero failures**. The remaining inconsistencies (some raw `json_encode` paths,
some 400-instead-of-405 cases) are documented in `API_CONTRACT.md` but are no longer test
failures.

This is the main reason the baseline moved from 427/112/539 to 433/105/538.

---

## 4. Historical drift

| Source | Claim | Reality at this snapshot |
|---|---|---|
| `README.md` | 427 / 112 / 539 | 433 / 105 / 538 |
| `AGENTS.md` | 427 / 112 / 539 | 433 / 105 / 538 |
| `Issues.txt` | 427 / 112 / 539 | 433 / 105 / 538 |
| `AGENTS.md` | 7 section-30 failures | 0 |
| `PENTEST/FINAL_OUTCOME.md` | 433 / 105 / 538 | matches |
| Older June audit | 539 tests | 538 today |

The README/AGENTS/Issues counts are updated in Phase 1 to point here instead of duplicating
numbers.

---

## 5. Reproducing and verifying

```powershell
# 1. Confirm the audited CODE state. This must print 66dedc1.
#    Do NOT expect HEAD to be 66dedc1: HEAD advances with the documentation
#    commits, which change no code. This command ignores them.
git log -1 --format='%h %s' -- api config pages public schema.sql tests/run_tests.php
# expect: 66dedc1

# 2. See where HEAD actually is (docs commits sit on top of 66dedc1)
git log --oneline -3

# 3. Confirm services are running
#    Apache and MySQL must be up in XAMPP.

# 4. Run the suite
php tests/run_tests.php

# 5. Confirm the summary line
#    RESULTS: 433 passed, 105 failed out of 538 tests

# 6. Compare the failure set
#    Any failure outside the catalogue in §3 is a real regression.
```

Useful checks against the saved capture:

```powershell
# Count unique failures from the saved run
Get-Content docs/evidence/phase1-test-baseline.txt |
  Where-Object { $_ -match 'FAIL:' } |
  Sort-Object -Unique |
  Measure-Object

# List service/pagination-only failures
Select-String -Path docs/evidence/phase1-test-baseline.txt -Pattern 'FAIL: (Service|3[124])'
```

The suite seeds its own users and data. Running it against a database with unrelated data may
change counts in data-dependent assertions; use the documented baseline database state or a
clean rebuild from `schema.sql`.

---

## 6. What the suite covers (35 sections)

The runner prints 34 of the 35 section headers as `── N. Title ──`. Section 28 is the
exception: it prints `── Section 28: Service Layer ──`. So a `grep` for the numbered pattern
over the capture legitimately returns 34 hits, not 35 — section 28 is present, just formatted
differently (`tests/run_tests.php:1586`).

| Section | Area |
|---:|---|
| 1 | Schema validation |
| 2 | Auth: signup/login/logout |
| 3 | Categories |
| 4 | Personal expense CRUD |
| 5 | Expense summary |
| 6 | Budgets |
| 7 | Groups |
| 8 | Group expenses |
| 9 | Notifications (includes policy-rejected escaping assertion) |
| 10 | Settlement calculation |
| 11 | Settlement confirmation flow |
| 12 | Settlement lock |
| 13 | Post-settlement late expenses |
| 14 | Personal lists |
| 15 | Group lists |
| 16 | Unpriced items + pricing |
| 17 | `settle.php` admin check |
| 18 | `settle_all` admin flow |
| 19 | Group leave + delete |
| 20 | Settlement helpers unit test |
| 21 | Unauthenticated access |
| 22 | SQL injection protection (includes policy assertion) |
| 23 | UI file integrity |
| 24 | PHP syntax check (all files) |
| 25 | Prepared statements verification |
| 26 | Input validation layer (includes policy assertion) |
| 27 | Authentication middleware |
| 28 | Service layer (planned, missing) |
| 29 | DB constraints, indexes, query optimization |
| 30 | Centralized error handling |
| 31 | Logging and observability |
| 32 | Pagination and query performance |
| 33 | Rate limiting |
| 34 | Ephemeral notification system |
| 35 | System health and predictive health |

Sections 1–8, 10–21, 23–25, 27, 29, 30, 33, and 35 pass completely. Sections **9, 22, and 26
each contain one policy-rejected failing assertion**, so they do not pass in full. The remaining
failures are confined to 28, 34, the three scans in 31/32, and those three policy assertions.

---

## 7. Standalone distributed suite

```powershell
php tests/distributed_system_test.php
```

This is **not** part of `tests/run_tests.php`. It reports **21/25**. The four known failures are:

- Redis aggregation;
- readiness;
- cache-pattern invalidation;
- latency score.

These exercise the dormant distributed stack (KD-11) and are not migration blockers.

---

## 8. Baseline rules for the Flutter migration

1. The PHP suite is the regression gate while the backend is frozen.
2. The Flutter client must not require backend changes to pass its own tests.
3. Characterization tests for Flutter should be added under `tests/` (or a new client test
   suite) without weakening existing assertions.
4. Any change that moves the count must be explained in this file.
5. A "fix" that only makes section 28/34 green by adding stubs is forbidden.
6. A change that makes a policy-rejected assertion pass by storing HTML entities is forbidden.
7. Section 30 is currently green; do not regress it while touching response paths.
8. Record the exact command, commit, date, and summary line for every future baseline.

Recommended baseline record format:

```
Commit: <sha>
Date: <YYYY-MM-DD>
Command: php tests/run_tests.php
Result: 433 passed, 105 failed out of 538 tests
Failure set: 88 section 28, 11 section 34, 31j, 32u, 32v, 3 policy
Evidence: docs/evidence/<file>.txt
```

---

## 9. Evidence file

[`evidence/phase1-test-baseline.txt`](evidence/phase1-test-baseline.txt) is the full console
output captured for this audit. It includes:

- every PASS/FAIL line;
- PHP warnings for the missing service files;
- the `RESULTS: 433 passed, 105 failed out of 538 tests` summary;
- the duplicate failure summary printed at the end.

The duplicate listing is why a naive `FAIL:` line count shows 210 while the unique failure set
is 105. Count **unique** failure lines, or trust the `RESULTS` line.
