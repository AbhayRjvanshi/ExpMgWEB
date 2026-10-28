<?php
/**
 * api/middleware/auth.php — Centralized authentication middleware.
 *
 * Provides requireAuth() function for protecting API endpoints.
 * Enforces:
 *   - Session validation (user_id must be set)
 *   - 24-hour session expiration via login_time
 *   - CSRF token verification on state-changing requests
 *   - IP-based rate limiting on API calls
 *
 * Usage:
 *   require_once __DIR__ . '/../middleware/auth.php';
 *   if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
 *       requireAuth();
 *   }
 */

// Secure session bootstrap. MUST run before any session_start(): cookie
// params and session.use_strict_mode are only honoured while no session is
// active. Every protected endpoint includes this file before touching
// $_SESSION, so the hardened flags apply universally.
require_once __DIR__ . '/../../config/session.php';
ensureSecureSessionStarted();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../helpers/rate_limiter.php';
require_once __DIR__ . '/../helpers/csrf.php';

// Security headers for EVERY response produced by a protected endpoint —
// including the 401/403/429 exits below, which never reach the body of
// requireAuth(). (api/bootstrap.php remains unwired.)
expmgSendSecurityHeaders();

/**
 * Require authentication for current request.
 *
 * Returns HTTP 401 Unauthorized and terminates if:
 *   - No session user_id
 *   - Session expired (>24 hours from login_time)
 *   - CSRF token missing/invalid on POST/PUT/PATCH/DELETE
 * Returns HTTP 429 Too Many Requests and terminates if:
 *   - Per-user (120/min) or per-IP (240/min) API rate limit exceeded
 *
 * Ordering contract (performance-critical):
 *   1. All session-dependent validation happens FIRST while the session
 *      lock is still held (user_id check, 24h expiry which may call
 *      session_destroy(), CSRF which reads $_SESSION['csrf_token']).
 *   2. session_write_close() then releases PHP's exclusive flock on the
 *      session file immediately, so the concurrent AJAX requests of a
 *      logged-in user execute in parallel instead of serially. $_SESSION
 *      values remain readable in memory for the rest of the request.
 *   3. Only DB-backed rate limiting runs after the lock is released.
 *
 * @param bool $recordUsage Pass false to skip recording rate_limits usage
 *                          rows (used by lightweight heartbeat polling).
 * @return void
 */
function requireAuth(bool $recordUsage = true): void {
    global $conn;

    // ---- 1. Session-dependent checks (hold the session lock) ----
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '') {
        logMessage('WARNING', 'Authentication failed: Unauthorized (no session user_id)', [
            'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Authentication required']);
        exit;
    }

    $userId = (int) $_SESSION['user_id'];

    // 24-hour session expiration. session_destroy() requires an ACTIVE
    // session, so this must run before session_write_close().
    if (isset($_SESSION['login_time'])) {
        $loginTime = (int) $_SESSION['login_time'];
        $now = time();
        $sessionAge = $now - $loginTime;

        if ($sessionAge > 86400) { // 86400 seconds = 24 hours
            session_destroy();

            logMessage('WARNING', 'Session expired after 24 hours', [
                'user_id' => $userId,
                'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Session expired']);
            exit;
        }
    }

    // CSRF verification for state-changing requests (reads $_SESSION['csrf_token']).
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        verifyCsrfToken();
    }

    // ---- 2. Release the exclusive session file lock NOW ----
    // Everything below no longer writes $_SESSION, so concurrent requests
    // from the same user run in parallel instead of queueing on flock(LOCK_EX).
    session_write_close();

    // ---- 3. Rate limiting (DB-only; does not touch $_SESSION) ----
    // Per-user API limit: 120 requests per 60 seconds.
    // Per-IP API limit: 240 requests per 60 seconds (burst allowance).
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userKey = 'user:' . $userId;
    $ipKey = 'ip:' . $ip;

    if (is_object(rateLimiterRedis())) {
        // Redis backend: granular per-key checks (cheap Redis round-trips).
        if (!checkRateLimit($conn, $userKey, 'api_user', 120, 60)) {
            $retryAfter = rateLimitRetryAfter($conn, $userKey, 'api_user', 60);
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: ' . $retryAfter);
            echo json_encode([
                'ok' => false,
                'error' => 'Too many requests. Rate limited.',
                'retry_after' => $retryAfter,
                'limited_by' => 'user'
            ]);
            exit;
        }
        if (!checkRateLimit($conn, $ipKey, 'api_ip', 240, 60)) {
            $retryAfter = rateLimitRetryAfter($conn, $ipKey, 'api_ip', 60);
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: ' . $retryAfter);
            echo json_encode([
                'ok' => false,
                'error' => 'Too many requests. Rate limited.',
                'retry_after' => $retryAfter,
                'limited_by' => 'ip'
            ]);
            exit;
        }
        if ($recordUsage) {
            recordRateLimit($conn, $userKey, 'api_user', 60);
            recordRateLimit($conn, $ipKey, 'api_ip', 60);
        }
    } else {
        // DB backend: one batched probe + one multi-row INSERT (2 queries
        // instead of the previous 6) with identical limiting semantics.
        $verdict = requireAuthRateLimitBatch($conn, $userKey, $ipKey, 120, 240, 60, $recordUsage);
        if (!$verdict['allowed']) {
            $retryAfter = $verdict['retry_after'];
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: ' . $retryAfter);
            echo json_encode([
                'ok' => false,
                'error' => 'Too many requests. Rate limited.',
                'retry_after' => $retryAfter,
                'limited_by' => $verdict['limited_by']
            ]);
            exit;
        }
    }
}

/**
 * Verify CSRF token for POST/PUT/PATCH/DELETE requests.
 *
 * Thin delegating wrapper. The canonical implementation lives in
 * api/helpers/csrf.php::verifyCsrf(), which supports the X-CSRF-Token header,
 * the $_SERVER fallback, a POST-body token, and the double-submit cookie —
 * and records failures by IP in rate_limits.
 *
 * Returns HTTP 403 Forbidden if token is missing or invalid.
 *
 * @return void
 */
function verifyCsrfToken(): void {
    verifyCsrf();
}
