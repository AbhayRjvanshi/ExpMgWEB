# Rate Limiting Documentation (Current Project State)

Last updated: 2026-04-27

This document summarizes everything related to rate limiting in this project so far:
- what was implemented,
- what problems were faced and solved,
- how much is solved,
- what rate limiting does in this app,
- current state,
- and known remaining issues.

---

## 1) What We Did

## Version Milestones

- v2.2 (Upgrade 9): introduced DB-backed rate limiting via `rate_limits` table and helper functions.
- v2.7: shifted API limiting toward fairness (per-user protection) while keeping abuse guardrails.
- v2.10+: added backend switch support (`db` or `redis`) through environment config.

## Core Components Implemented

- Storage table:
  - `rate_limits` table created by `migration_v2.2.sql`.
  - Indexed by `(ip_address, action, attempted_at)` for efficient lookups.

- Limiter helper:
  - `api/helpers/rate_limiter.php`
  - Functions implemented:
    - `checkRateLimit(...)`
    - `recordRateLimit(...)`
    - `cleanupRateLimits(...)`
    - `rateLimitRetryAfter(...)`
  - Supports backend selection via `RATE_LIMIT_BACKEND`:
    - `db` (default)
    - `redis` (optional)

- Endpoint and middleware integration:
  - `api/login.php`: rate limits failed login attempts (`5 / 15 min` intent).
  - `api/signup.php`: rate limits signup attempts (`3 / 1 hr` intent).
  - `api/middleware/auth.php`: applies authenticated API limiting with dual keys:
    - per-user key: `user:{id}` + action `api_user` (`120 / 60 sec`)
    - per-IP key: `ip:{addr}` + action `api_ip` (`240 / 60 sec`)

- Client signaling:
  - On API throttle, middleware returns HTTP `429` with:
    - `Retry-After` header
    - JSON with `retry_after` and `limited_by` metadata.

- Operational visibility:
  - Rate-limit blocks are logged with structured context in app logs.
  - `api/system/health.php` exposes active limiter backend (`db` vs `redis`).

---

## 2) Problems Faced and Solved

## Problem A: No request throttling existed

- Risk:
  - brute-force login/signup,
  - burst traffic overload,
  - easy abuse by repeated retries.
- Solved by:
  - adding the `rate_limits` table and limiter helper (v2.2),
  - wiring login/signup/auth middleware checks.
- Result:
  - basic abuse control became active and testable.

## Problem B: API limiting fairness issues under shared IPs

- Risk:
  - one noisy client could affect others under same NAT/proxy.
- Solved by:
  - using both user-level and IP-level limits in auth middleware.
- Result:
  - better fairness than pure IP-only limiting, while retaining abuse guardrails.

## Problem C: DB hot path and scalability concerns

- Risk:
  - write-heavy `rate_limits` usage can become a bottleneck at scale.
- Solved by:
  - backend abstraction + optional Redis path (`RATE_LIMIT_BACKEND=redis`).
- Result:
  - project can run DB-first by default but has a scale path.

## Problem D: Lack of backoff guidance to clients

- Risk:
  - clients retry too aggressively and worsen overload.
- Solved by:
  - `Retry-After` + structured 429 payload from middleware.
- Result:
  - API clients/frontends can back off more intelligently.

---

## 3) How Much Was Solved?

Approximate status based on current code:

- Core protection coverage: **High**
  - Login, signup, and authenticated API flows are protected.

- Fairness improvement: **Medium-High**
  - User + IP dual-key API limiting is implemented.

- Scalability readiness: **Medium**
  - Redis backend exists, but behavior consistency issues remain (see open issues below).

- Accuracy/consistency of throttle semantics: **Medium-Low**
  - Retry timing and backend window behavior are not fully aligned to policy in all cases.

Overall maturity estimate: **about 75-85% complete** for production-grade rate limiting.

Reason for not calling it 100%:
- some policy-vs-implementation mismatches still exist,
- Redis path has window/cooldown consistency problems,
- tests/documentation are partially out of sync with current middleware behavior.

---

## 4) What Rate Limiting Does Here

In this project, rate limiting is used as a defensive control to:

- slow down brute-force login and signup abuse,
- cap authenticated API request bursts,
- preserve fairness among users,
- protect app/database resources under request spikes,
- provide deterministic 429 responses and retry guidance.

In short: it reduces abuse impact, improves stability, and supports graceful degradation.

---

## 5) Current State of Rate Limiting

## What is active now

- Login limiter is active in `api/login.php`.
- Signup limiter is active in `api/signup.php`.
- Authenticated API limiter is active in `api/middleware/auth.php` with dual-key checks.
- Global limiter switch (`rateLimiterEnabled()`) currently returns `true`.
- Backend switch is available (`RATE_LIMIT_BACKEND`):
  - default `db`, optional `redis`.

## Data and config state

- `rate_limits` table exists via schema/migration.
- Env default in `config/env.php` sets `RATE_LIMIT_BACKEND=db`.
- Health endpoint reports current `rate_limit_backend`.

---

## 6) Known Issues — All Resolved (v2.13)

All previously identified issues have been fixed. The system is now considered fully hardened.

## ~~Issue 1~~: Retry-after capped to 60 seconds — **FIXED**

- **Was:** `rateLimitRetryAfter(...)` returned `min(60, ...)` regardless of action window.
- **Fix:** Removed the `min(60, ...)` cap from both the Redis path and the DB path.
  `rateLimitRetryAfter` now returns the actual remaining window seconds
  (up to 900s for login, 3600s for signup, 60s for API).
- **Changed in:** `api/helpers/rate_limiter.php`

## ~~Issue 2~~: Redis backend used fixed 1-hour key TTL — **FIXED**

- **Was:** `recordRateLimit(...)` always set Redis key expiry to `3600` seconds.
- **Fix:** Added `$windowSecs` parameter to `recordRateLimit` (default `3600` for backward
  compat). All call sites now pass the correct window:
  - `login.php` → `900`
  - `signup.php` → `3600`
  - `auth.php` (api_user / api_ip) → `60`
- **Changed in:** `api/helpers/rate_limiter.php`, `api/login.php`, `api/signup.php`, `api/middleware/auth.php`

## ~~Issue 3~~: Cooldown was globally fixed at 60 seconds — **FIXED**

- **Was:** `rateLimiterCooldownWindow()` returned `60` for all actions.
- **Fix:** `rateLimiterCooldownWindow(string $action = '')` now accepts the action name
  and returns from a lookup map:
  - `login` → 900s, `signup` → 3600s, `api_user` / `api_ip` → 60s, fallback → 60s.
  All four internal call sites updated to pass `$action`.
- **Changed in:** `api/helpers/rate_limiter.php`

## ~~Issue 4~~: Tests used legacy `api` action name — **FIXED**

- **Was:** Section 33u inserted rows with `action = 'api'` but middleware uses `api_user`/`api_ip`.
- **Fix:** Test 33u now inserts 120 `api_user` rows (keyed as `user:{id}`) and 240 `api_ip`
  rows (keyed as `ip:{addr}`) for both IPv4 and IPv6 loopback — matching the exact key format
  the middleware builds at runtime.
- **Changed in:** `run_tests.php` (Section 33u)

## ~~Issue 5~~: Signup recorded attempts before validation — **FIXED**

- **Was:** `recordRateLimit` was called immediately after `checkRateLimit`, before validation.
  Form errors (typos, mismatched passwords) burned through the 3-per-hour limit.
- **Fix:** `recordRateLimit` moved to just before the DB insert (after all validation and
  uniqueness checks pass). Only valid-looking signup attempts consume quota.
- **Changed in:** `api/signup.php`

---

## 7) Practical Conclusion

- The project has a **real, functioning, fully hardened limiter system** in production code.
- All major abuse risks (brute-force login, mass signup, API flooding) are mitigated.
- The system is now **consistent across both DB and Redis backends**:
  - Retry-after values are accurate for all action windows.
  - Redis key TTLs match the action's actual rate window.
  - Cooldown windows are per-action, not globally fixed.
  - Tests exercise the actual middleware code paths.
  - Legitimate users are not penalized for form validation mistakes on signup.

---

## 8) Recommended Next Steps

All previously identified issues are resolved. The system is production-ready.

Potential future improvements (non-urgent):
- **IP allowlist:** Skip rate limiting for internal/monitoring IPs.
- **Distributed Redis:** For multi-server deployments, ensure Redis is shared across nodes.
- **Rate-limit metrics:** Expose block counts per action in the health endpoint for observability.

---

## 9) Quick Reference

- Limiter helper: `api/helpers/rate_limiter.php`
- Auth integration: `api/middleware/auth.php`
- Login integration: `api/login.php`
- Signup integration: `api/signup.php`
- Migration: `migration_v2.2.sql`
- Runtime backend config: `RATE_LIMIT_BACKEND` (in env)
- Health visibility: `api/system/health.php`
