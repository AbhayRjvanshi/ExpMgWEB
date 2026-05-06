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

// Ensure session is started before checking auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../helpers/rate_limiter.php';

/**
 * Require authentication for current request.
 *
 * Returns HTTP 401 Unauthorized and terminates if:
 *   - No session user_id
 *   - Session expired (>24 hours from login_time)
 *   - CSRF token missing/invalid on POST/PUT/PATCH/DELETE
 *   - Rate limit exceeded on API calls
 *
 * On success, populates:
 *   - $_SESSION['user_id']
 *   - $_SESSION['login_time']
 *
 * @return void
 */
function requireAuth(): void {
    global $conn;
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '') {
        logMessage('WARNING', 'Authentication failed: no session user_id', [
            'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $userId = (int) $_SESSION['user_id'];

    // ---------- Rate limiting check ----------
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userKey = 'user:' . $userId;
    $ipKey = 'ip:' . $ip;
    
    // Per-user API limit: 120 requests per 60 seconds
    if (!checkRateLimit($conn, $userKey, 'api_user', 120, 60)) {
        $retryAfter = rateLimitRetryAfter($conn, $userKey, 'api_user', 60);
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . $retryAfter);
        echo json_encode([
            'ok' => false,
            'error' => 'Rate limited',
            'retry_after' => $retryAfter,
            'limited_by' => 'user'
        ]);
        exit;
    }
    
    // Per-IP API limit: 240 requests per 60 seconds (allows burst but prevents network abuse)
    if (!checkRateLimit($conn, $ipKey, 'api_ip', 240, 60)) {
        $retryAfter = rateLimitRetryAfter($conn, $ipKey, 'api_ip', 60);
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . $retryAfter);
        echo json_encode([
            'ok' => false,
            'error' => 'Rate limited',
            'retry_after' => $retryAfter,
            'limited_by' => 'ip'
        ]);
        exit;
    }

    // Check session expiration (24 hours from login_time)
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

    // For state-changing requests, verify CSRF token
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        verifyCsrfToken();
    }

    // Count successful authenticated API usage so the per-user/IP limits can actually trigger.
    recordRateLimit($conn, $userKey, 'api_user', 60);
    recordRateLimit($conn, $ipKey, 'api_ip', 60);
}

/**
 * Verify CSRF token for POST/PUT/PATCH/DELETE requests.
 *
 * Checks:
 *   - X-CSRF-Token header matches session token
 *   - Or CSRF token in POST data matches session token (fallback)
 *
 * Returns HTTP 403 Forbidden if token is missing or invalid.
 *
 * @return void
 */
function verifyCsrfToken(): void {
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (!$sessionToken) {
        logMessage('WARNING', 'CSRF verification failed: no session token', [
            'user_id' => $_SESSION['user_id'] ?? null,
            'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'CSRF token missing']);
        exit;
    }

    // Try header first (recommended for AJAX)
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    // Try POST data as fallback
    $postToken = $_POST['csrf_token'] ?? '';

    // Try cookie for double-submit defense
    $cookieToken = $_COOKIE['csrf_token'] ?? '';

    $tokenValid = false;

    // Check header against session token (timing-attack resistant)
    if ($headerToken && hash_equals($sessionToken, $headerToken)) {
        $tokenValid = true;
    }
    // Check POST data against session token
    elseif ($postToken && hash_equals($sessionToken, $postToken)) {
        $tokenValid = true;
    }
    // Check double-submit cookie against session token
    elseif ($cookieToken && hash_equals($sessionToken, $cookieToken)) {
        $tokenValid = true;
    }

    if (!$tokenValid) {
        logMessage('WARNING', 'CSRF verification failed: token mismatch', [
            'user_id' => $_SESSION['user_id'] ?? null,
            'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']);
        exit;
    }
}
