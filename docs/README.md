# ExpMgWEB — Phase 1 Audit Documentation

This directory is the **canonical Phase 1 audit record** for the ExpMgWEB PHP application,
written immediately before the Flutter client migration.

The purpose of these documents is to describe **what the running application actually does
today**, so the Flutter client can be built against a frozen contract without silently
changing behaviour. Nothing in this directory is a design proposal for the PHP app.

---

## Audit snapshot

| Item | Value |
|---|---|
| Application | ExpMgWEB (PHP 8 procedural + MySQL 8/MariaDB + vanilla JS SPA) |
| Audit snapshot commit | `5ed8de3` — "UI repair: restore markup eaten by the Phase 3 extraction" |
| Documentation produced at | `66dedc1` — splash redirect fix only (`public/splash.php`) |
| Snapshot date | 2026-11-15 |
| Backend surface | **40 HTTP endpoints** — 36 module + 3 auth + 1 system (`system/health.php`); plus 1 CLI-only worker (`workers/process_outbox.php`, not HTTP). 64 PHP files total |
| Database | 14 tables (`schema.sql` is the source of truth) |
| Test suite | `tests/run_tests.php` — **538 tests, 433 passed, 105 failed** |
| Canonical test record | [`TEST_BASELINE.md`](TEST_BASELINE.md) |
| Security status source | `PENTEST/FINAL_OUTCOME.md` (re-run 2026-11-01 at `105f4c0`) |

`5ed8de3` is the audited snapshot. `66dedc1` changes only the splash redirect attribute and
does not alter any API, database, or business rule described here.

The final annotated tag `phase1-audit` must only be created **after review and sign-off** of
this documentation set. See [`MIGRATION_PRESERVATION_PLAN.md`](MIGRATION_PRESERVATION_PLAN.md).

---

## Document map

| Document | What it answers |
|---|---|
| [`PROJECT_OVERVIEW.md`](PROJECT_OVERVIEW.md) | What the product is, its stack, runtime topology, request lifecycle, and design system |
| [`FEATURE_INVENTORY.md`](FEATURE_INVENTORY.md) | Every user-facing feature, where it lives, and its preservation status for Flutter |
| [`BACKEND_AUDIT.md`](BACKEND_AUDIT.md) | How the 40 HTTP endpoints and the 1 CLI worker are structured and guarded |
| [`DATABASE_AUDIT.md`](DATABASE_AUDIT.md) | All 14 tables, keys, constraints, invariants, and sync-readiness gaps |
| [`FRONTEND_AUDIT.md`](FRONTEND_AUDIT.md) | SPA shell, JavaScript modules, page routing, frontend→API call map, charts/PDF/theme |
| [`SECURITY_AUDIT.md`](SECURITY_AUDIT.md) | Current verified security posture and the controls the Flutter client must respect |
| [`TECHNICAL_DEBT.md`](TECHNICAL_DEBT.md) | Known debt register (KD-01…KD-30), dead code, deferred work, and deliberate quirks |
| [`MIGRATION_PRESERVATION_PLAN.md`](MIGRATION_PRESERVATION_PLAN.md) | The freeze rules, parity requirements, non-goals, and sign-off checklist |
| [`API_CONTRACT.md`](API_CONTRACT.md) | **Highest priority.** Exact endpoint contracts, envelopes, permissions, errors, pagination, and data flows |
| [`TEST_BASELINE.md`](TEST_BASELINE.md) | **Highest priority.** Canonical 433/105/538 baseline, failure catalogue, and how to reproduce |

Supporting evidence:

| File | Contents |
|---|---|
| [`evidence/phase1-test-baseline.txt`](evidence/phase1-test-baseline.txt) | Full captured output of the baseline suite run |

---

## How to verify this audit

```powershell
# From C:\xampp\htdocs\ExpMgWEB
git rev-parse --short HEAD
php tests/run_tests.php        # expect 433 passed / 105 failed / 538 tests
```

The audit is source-derived. Where an older document (README, AGENTS.md, Issues.txt, the June
audit report, `graphify-out/`) disagrees with this directory, **this directory and the source
code win**. Specifically:

- The suite total is **538**, not 539.
- The baseline is **433 passed / 105 failed**, not 427 / 112.
- `api/settlements/settle.php` now performs full calendar validation through `validateDate()`;
  the older "regex-only date check" claim in `Issues.txt` is stale.
- `graphify-out/graph.json` was generated 2026-06-03 and is stale. It may be used only as a
  navigation aid after regeneration; it is never an authority for behaviour.
- The backend has **40 HTTP endpoints** (36 module + 3 auth + 1 system) and **1 CLI-only
  worker**. Counts of "38 endpoints" or "41 endpoint files" in dated version-history sections
  of `README.md` describe earlier code states and are preserved as history; open
  `API_CONTRACT.md` §9.1 for the current classification.

---

## Freeze rules in force during Phase 1

1. No functional changes to `api/`, existing frontend files, `schema.sql`, or migrations.
2. Only documentation, tests, audit scripts, and evidence files may be added or changed.
3. Known security or data-loss defects are **documented**, not fixed, unless explicitly
   approved as a critical exception.
4. No guest-mode API access is introduced. The current PHP app has no guest mode at all;
   guest mode will be a Flutter-local-only behaviour.
5. Dormant infrastructure (`api/services/`, outbox worker, notification publisher/store) is
   **not** a sync design basis.
6. Section 30 contract work and the KD-06 service-layer refactor remain deferred. No stubs.

See [`MIGRATION_PRESERVATION_PLAN.md`](MIGRATION_PRESERVATION_PLAN.md) for the complete rules
and the sign-off gate.
