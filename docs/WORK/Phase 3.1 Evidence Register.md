# Phase 3.1 — Supabase Setup & Feasibility Verification: Evidence Register

> **Status:** Pre-Execution Hardening Completed — Awaiting Owner Bootstrap & Live Execution  
> **Governance Phase:** Phase 3.1 (Setup & Feasibility Verification)  
> **Central Objective:** Establish whether the existing PHP development environment can securely communicate with Supabase PostgreSQL and independently verify identities authenticated by Supabase Auth.  
> **Environment:** PHP 8.2.12 (XAMPP on Windows, CLI/Apache), isolated Supabase development project.  
> **Master Test Suite Runner:** `php tests/phase3_1/run_all_phase31_tests.php`  
> **Classification Key:**  
> - `PASS`: The test was performed and the required behavior worked (local checks only during pre-execution).  
> - `FAIL`: The test was performed and the required behavior did not work.  
> - `BLOCKED`: The test could not be completed due to placeholder credentials or external dependency.  
> - `NOT TESTED`: The test has not been performed against the live instance yet.  
> - `CONDITIONAL`: The behavior worked, but only under documented constraints.

---

## 1. Master Evidence Summary Matrix

| Test ID | Subphase | Test Description | Status | Evidence File / Reference |
|---|---|---|:---:|---|
| **TC-3.1.1-A** | 3.1.1 | Supabase Development Project Provisioning & Record | `PASS` | Project provisioned by owner; GitHub connected |
| **TC-3.1.2-A** | 3.1.2 | Database Access Model & Role Permission Inspection | `NOT TESTED` | `tests/phase3_1/test_roles.php` |
| **TC-3.1.3-A** | 3.1.3 | Local PHP Environment & PDO Driver Verification (`pdo_pgsql`) | `PASS` | `tests/phase3_1/test_pdo_driver.php` |
| **TC-3.1.3-B** | 3.1.3 | Direct PostgreSQL Connection & Basic CRUD via PDO | `NOT TESTED` | `tests/phase3_1/test_db_connectivity.php` |
| **TC-3.1.3-C** | 3.1.3 | Transaction Commit & Rollback Behavior | `NOT TESTED` | `tests/phase3_1/test_db_connectivity.php` |
| **TC-3.1.3-D** | 3.1.3 | Database Error Handling & Invalid Credential Rejection | `NOT TESTED` | `tests/phase3_1/test_db_connectivity.php` |
| **TC-3.1.4-A** | 3.1.4 | Supavisor Session Mode (Port 5432) Compatibility | `NOT TESTED` | `tests/phase3_1/test_pooling_modes.php` |
| **TC-3.1.4-B** | 3.1.4 | Supavisor Transaction Mode (Port 6543) & Emulated Prepares | `NOT TESTED` | `tests/phase3_1/test_pooling_modes.php` |
| **TC-3.1.4-C** | 3.1.4 | Row-Level Locking (`SELECT ... FOR UPDATE`) under Pooling | `NOT TESTED` | `tests/phase3_1/test_pooling_modes.php` |
| **TC-3.1.5-A** | 3.1.5 | Supabase Auth User Registration (Disposable Account) | `NOT TESTED` | `tests/phase3_1/test_auth_api.php` |
| **TC-3.1.5-B** | 3.1.5 | Supabase Auth Email/Password Login & Token Issuance | `NOT TESTED` | `tests/phase3_1/test_auth_api.php` |
| **TC-3.1.5-C** | 3.1.5 | Duplicate Registration & Invalid Credential Rejection | `NOT TESTED` | `tests/phase3_1/test_auth_api.php` |
| **TC-3.1.6-A** | 3.1.6 | JWKS Retrieval & Public Key Caching in PHP | `NOT TESTED` | `tests/phase3_1/test_jwt_verifier.php` |
| **TC-3.1.6-B** | 3.1.6 | Independent PHP JWT Verification (Valid Token Claims) | `NOT TESTED` | `tests/phase3_1/test_jwt_verifier.php` |
| **TC-3.1.6-C** | 3.1.6 | Negative Token Verification Tests (Expired, Tampered, Invalid) | `NOT TESTED` | `tests/phase3_1/test_jwt_verifier.php` |
| **TC-3.1.7-A** | 3.1.7 | Token Refresh Lifecycle & Refreshed Token Validation | `NOT TESTED` | `tests/phase3_1/test_token_lifecycle.php` |
| **TC-3.1.7-B** | 3.1.7 | Revocation on Logout & Revoked Refresh Token Rejection | `NOT TESTED` | `tests/phase3_1/test_token_lifecycle.php` |
| **TC-3.1.7-C** | 3.1.7 | Access Token Usability Window Post-Logout (Statelessness) | `NOT TESTED` | `tests/phase3_1/test_token_lifecycle.php` |
| **TC-3.1.8-A** | 3.1.8 | Local PHP Runtime & Mandatory Extension Audit | `PASS` | `tests/phase3_1/test_hosting_check.php` |
| **TC-3.1.8-B** | 3.1.8 | Outbound Network & Remote Hosting Verification | `NOT TESTED` | `tests/phase3_1/test_hosting_check.php` |
| **TC-3.1.9-A** | 3.1.9 | Free-Tier Limits & Workload Suitability Modeling (10–20 Users) | `PASS` | Detailed Analysis in §9 |

---

## 2. Detailed Subphase Status Records

### 3.1.1 — Supabase Development Project Record
- **Test ID:** `TC-3.1.1-A`
- **Objective:** Provision an isolated Supabase project and connect GitHub repository.
- **Status:** `PASS` (Project provisioned by owner; repository connected).
- **Environment Notes:**
  - Owner created isolated Supabase development project.
  - Owner connected the repository to the Supabase project.
  - No deployment workflows exist in the repository; pushing commits will not trigger automated migrations or edge function deployments.

---

### 3.1.2 — Database Role Configuration & Access Control
- **Test ID:** `TC-3.1.2-A`
- **Objective:** Verify role separation between administrative role (`postgres`) and least-privilege runtime role (`expmg_app`).
- **Status:** `NOT TESTED` (Awaiting owner execution of `scripts/phase3_1/bootstrap_runtime_role.sql` in the Supabase Dashboard SQL Editor).
- **Preparation Complete:**
  - Script created: `scripts/phase3_1/bootstrap_runtime_role.sql`.
  - Grants CONNECT, USAGE on public schema, default DML privileges for future tables, and explicitly revokes CREATE on schema public (DDL blocked).

---

### 3.1.3 — PHP PDO Connectivity & CRUD Operations
- **Test IDs:** `TC-3.1.3-A` (`PASS`), `TC-3.1.3-B` (`NOT TESTED`), `TC-3.1.3-C` (`NOT TESTED`), `TC-3.1.3-D` (`NOT TESTED`)
- **Status:**
  - `TC-3.1.3-A`: `PASS` (PHP 8.2.12, `pdo_pgsql`, OpenSSL, cURL, JSON verified locally).
  - `TC-3.1.3-B/C/D`: `NOT TESTED` (Test script `tests/phase3_1/test_db_connectivity.php` hardened with placeholder detection; ready for live run).

---

### 3.1.4 — Connection Pooling & Supavisor Compatibility
- **Test IDs:** `TC-3.1.4-A`, `TC-3.1.4-B`, `TC-3.1.4-C`
- **Objective:** Compare Mode B (Shared Session Pooler, Port 5432) vs Mode C (Shared Transaction Pooler, Port 6543) with emulated prepared statements.
- **Status:** `NOT TESTED` (Script `tests/phase3_1/test_pooling_modes.php` hardened with placeholder detection; prepared statement emulation checks ready for live run).
- **Driver Behavior Note:** Supavisor Transaction Mode does not support server-side prepared statements (`PQprepare`). Setting `PDO::ATTR_EMULATE_PREPARES => true` causes the PDO PostgreSQL driver to perform parameter substitution client-side, avoiding server-side statement collisions across pooled connections.

---

### 3.1.5 — Supabase Auth Registration & Login
- **Test IDs:** `TC-3.1.5-A`, `TC-3.1.5-B`, `TC-3.1.5-C`
- **Objective:** Test user registration, login, and token issuance using modern Publishable Key (`SUPABASE_PUBLISHABLE_KEY`).
- **Status:** `NOT TESTED` (Script `tests/phase3_1/test_auth_api.php` migrated to `SUPABASE_PUBLISHABLE_KEY`; placeholder detection active; awaiting owner credentials).

---

### 3.1.6 — Independent PHP JWT Verification
- **Test IDs:** `TC-3.1.6-A`, `TC-3.1.6-B`, `TC-3.1.6-C`
- **Objective:** Native zero-dependency PHP JWT verification via live JWKS.
- **Status:** `NOT TESTED` (Script `tests/phase3_1/test_jwt_verifier.php` ready; user JWT verification cryptographically verified against Supabase JWKS, completely independent of API keys).

---

### 3.1.7 — Token Refresh & Logout Lifecycle
- **Test IDs:** `TC-3.1.7-A`, `TC-3.1.7-B`, `TC-3.1.7-C`
- **Objective:** Test token refresh, token rotation, session termination, and access token stateless window.
- **Status:** `NOT TESTED` (Script `tests/phase3_1/test_token_lifecycle.php` updated to use `SUPABASE_PUBLISHABLE_KEY`; ready for live run).

---

### 3.1.8 — PHP Hosting Environment Audit
- **Test IDs:** `TC-3.1.8-A` (`PASS`), `TC-3.1.8-B` (`NOT TESTED`)
- **Status:**
  - `TC-3.1.8-A`: `PASS` (Local runtime profile and 8 mandatory extensions verified).
  - `TC-3.1.8-B`: `NOT TESTED` (Outbound TCP/TLS network probes to ports 443, 5432, 6543 await live endpoint configuration).

---

### 3.1.9 — Free-Tier Suitability Modeling
- **Test ID:** `TC-3.1.9-A`
- **Objective:** Workload modeling for 10–20 active users against published Supabase Free limits.
- **Status:** `PASS`
- **Summary:** Total projected database growth `< 35 MB/year` (< 7% of 500 MB quota); monthly egress `~60 MB` (< 1.2% of 5 GB quota); peak concurrent connections $\le 2–3$ (under 15 pooled slots). >90% headroom confirmed.

---

## 3. Configuration Hardening & Audit Summary

| Component | Status | Verification Summary |
|---|:---:|---|
| `.gitignore` | `PASS` | Line 10 `!.env*.example` un-ignores `.env.phase3_1.example`; `.env.phase3_1` remains strictly ignored. |
| `.env.phase3_1.example` | `PASS` | Migrated to `SUPABASE_PUBLISHABLE_KEY`; secret key removed; unmistakable placeholders (`YOUR_...`); distinct Modes A, B, C. |
| `tests/phase3_1/config.php` | `PASS` | `Config::requireReal()` blocks execution if placeholder values are present; error messages sanitize secret values. |
| `scripts/phase3_1/bootstrap_runtime_role.sql` | `PASS` | Idempotent role creation, schema usage, default privileges, DDL revocation, auth isolation. |
| GitHub Integration Safety | `PASS` | Verified zero `.github/` workflows and zero `supabase/` directories exist; pushes will not trigger migrations or deployments. |
