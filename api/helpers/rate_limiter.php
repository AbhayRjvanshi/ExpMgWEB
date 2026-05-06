<?php
/**
 * api/helpers/rate_limiter.php — Database-backed rate limiting.
 *
 * Tracks request attempts by IP + action in the `rate_limits` table.
 * Each action has its own max-attempts and window (seconds).
 *
 * Usage:
 *   require_once __DIR__ . '/rate_limiter.php';
 *   $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
 *   if (!checkRateLimit($conn, $ip, 'login', 5, 900)) {
 *       // rate limited — respond with 429
 *   }
 *   // ... perform action ...
 *   recordRateLimit($conn, $ip, 'login');
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/redis.php';

// Temporary global kill-switch for rate limiting.
// Set to true to re-enable rate limiting.
function rateLimiterEnabled(): bool {
    return true;
}

function rateLimiterBackend(): string {
    $backend = strtolower((string) env('RATE_LIMIT_BACKEND', 'db'));
    return in_array($backend, ['db', 'redis'], true) ? $backend : 'db';
}

function rateLimiterRedis() {
    if (rateLimiterBackend() !== 'redis') {
        return null;
    }

    $client = getRedis();
    if (!$client->isConnected()) {
        return null;
    }

    return $client->getRedis();
}

function rateLimiterRedisKey(string $ip, string $action): string {
    return "rate_limit:{$action}:{$ip}";
}

function rateLimiterCooldownWindow(string $action = ''): int {
    // Fix 3: Action-specific cooldown durations aligned with rate-limit windows.
    // This prevents premature retries on longer-window actions like login/signup.
    $map = [
        'login'    => 900,   // 15 minutes — matches login window
        'signup'   => 3600,  // 1 hour — matches signup window
        'api_user' => 60,    // 1 minute — matches API per-user window
        'api_ip'   => 60,    // 1 minute — matches API per-IP window
    ];
    return $map[$action] ?? 60;
}

function rateLimiterCooldownAction(string $action): string {
    return 'cooldown:' . $action;
}

function rateLimiterCooldownRedisKey(string $ip, string $action): string {
    return "rate_limit_cooldown:{$action}:{$ip}";
}

function rateLimiterActiveCooldown(mysqli $conn, string $ip, string $action): int {
    $redis = rateLimiterRedis();
    if (is_object($redis) && method_exists($redis, 'ttl')) {
        $ttl = (int) $redis->ttl(rateLimiterCooldownRedisKey($ip, $action));
        return $ttl > 0 ? min(rateLimiterCooldownWindow($action), $ttl) : 0;
    }

    $cooldownAction = rateLimiterCooldownAction($action);
    $stmt = $conn->prepare(
        'SELECT attempted_at FROM rate_limits WHERE ip_address = ? AND action = ? ORDER BY attempted_at DESC LIMIT 1'
    );
    $stmt->bind_param('ss', $ip, $cooldownAction);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['attempted_at'])) {
        return 0;
    }

    $remaining = rateLimiterCooldownWindow($action) - (time() - strtotime($row['attempted_at']));
    return max(0, min(rateLimiterCooldownWindow($action), $remaining));
}

function rateLimiterStartCooldownIfInactive(mysqli $conn, string $ip, string $action): int {
    $existing = rateLimiterActiveCooldown($conn, $ip, $action);
    if ($existing > 0) {
        return $existing;
    }

    $window = rateLimiterCooldownWindow($action);
    $redis = rateLimiterRedis();
    if (is_object($redis) && method_exists($redis, 'set')) {
        $key = rateLimiterCooldownRedisKey($ip, $action);
        if (method_exists($redis, 'set')) {
            // NX ensures cooldown starts once and cannot be reset while active.
            $redis->set($key, '1', ['nx', 'ex' => $window]);
        }
        $ttl = (int) $redis->ttl($key);
        return $ttl > 0 ? min($window, $ttl) : $window;
    }

    $cooldownAction = rateLimiterCooldownAction($action);
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        'INSERT INTO rate_limits (ip_address, action, attempted_at) VALUES (?, ?, ?)'
    );
    $stmt->bind_param('sss', $ip, $cooldownAction, $now);
    $stmt->execute();
    $stmt->close();

    return $window;
}

/**
 * Check whether the given IP + action is within the allowed limit.
 *
 * @param mysqli $conn       Database connection
 * @param string $ip         Client IP address
 * @param string $action     Action identifier (e.g. 'login', 'signup', 'api')
 * @param int    $maxAttempts Maximum attempts allowed within the window
 * @param int    $windowSecs Time window in seconds
 * @return bool  true if the request is allowed, false if rate-limited
 */
function checkRateLimit(mysqli $conn, string $ip, string $action, int $maxAttempts, int $windowSecs): bool {
    if (!rateLimiterEnabled()) {
        return true;
    }

    if (rateLimiterActiveCooldown($conn, $ip, $action) > 0) {
        return false;
    }

    $redis = rateLimiterRedis();
    if (is_object($redis) && method_exists($redis, 'get')) {
        $key = rateLimiterRedisKey($ip, $action);
        $count = (int) $redis->get($key);
        if ($count >= $maxAttempts) {
            rateLimiterStartCooldownIfInactive($conn, $ip, $action);
            return false;
        }
        return true;
    }

    // Probabilistic cleanup: ~1% of requests
    if (mt_rand(1, 100) === 1) {
        cleanupRateLimits($conn);
    }

    $since = date('Y-m-d H:i:s', time() - $windowSecs);
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS cnt FROM rate_limits WHERE ip_address = ? AND action = ? AND attempted_at >= ?'
    );
    $stmt->bind_param('sss', $ip, $action, $since);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $allowed = ($row['cnt'] ?? 0) < $maxAttempts;
    if (!$allowed) {
        rateLimiterStartCooldownIfInactive($conn, $ip, $action);
    }

    return $allowed;
}

/**
 * Record a rate-limit attempt for the given IP + action.
 *
 * @param mysqli $conn   Database connection
 * @param string $ip     Client IP address
 * @param string $action Action identifier
 */
function recordRateLimit(mysqli $conn, string $ip, string $action, int $windowSecs = 3600): void {
    if (!rateLimiterEnabled()) {
        return;
    }

    $redis = rateLimiterRedis();
    if (is_object($redis) && method_exists($redis, 'incr')) {
        $key = rateLimiterRedisKey($ip, $action);
        $count = (int) $redis->incr($key);
        if ($count === 1) {
            // Fix 2: use the action's actual window for TTL, not a fixed 1 hour.
            $redis->expire($key, $windowSecs);
        }
        return;
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        'INSERT INTO rate_limits (ip_address, action, attempted_at) VALUES (?, ?, ?)'
    );
    $stmt->bind_param('sss', $ip, $action, $now);
    $stmt->execute();
    $stmt->close();
}

/**
 * Delete expired rate-limit entries (older than 1 hour).
 *
 * Called probabilistically by checkRateLimit() to keep the table small.
 *
 * @param mysqli $conn Database connection
 */
function cleanupRateLimits(mysqli $conn): void {
    $redis = rateLimiterRedis();
    if (is_object($redis) && method_exists($redis, 'ttl')) {
        // Redis entries expire automatically.
        return;
    }

    $cutoff = date('Y-m-d H:i:s', time() - 3600);
    $stmt = $conn->prepare('DELETE FROM rate_limits WHERE attempted_at < ?');
    $stmt->bind_param('s', $cutoff);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get the number of seconds remaining until the rate limit window resets.
 *
 * @param mysqli $conn       Database connection
 * @param string $ip         Client IP address
 * @param string $action     Action identifier
 * @param int    $windowSecs Time window in seconds
 * @return int Seconds until the oldest attempt in the window expires
 */
function rateLimitRetryAfter(mysqli $conn, string $ip, string $action, int $windowSecs): int {
    if (!rateLimiterEnabled()) {
        return 0;
    }

    $cooldownRemaining = rateLimiterActiveCooldown($conn, $ip, $action);
    if ($cooldownRemaining > 0) {
        return $cooldownRemaining;
    }

    $redis = rateLimiterRedis();
    if (is_object($redis) && method_exists($redis, 'ttl')) {
        $ttl = (int) $redis->ttl(rateLimiterRedisKey($ip, $action));
        // Fix 1: return the actual TTL, not a capped 60s value.
        return $ttl > 0 ? $ttl : 0;
    }

    // Use a wider clearance window for authenticated API bursts so retry-after
    // reflects when a normal page-load burst can proceed safely.
    $clearanceNeeded = in_array($action, ['api_user', 'api_ip'], true) ? 15 : 1;

    $since = date('Y-m-d H:i:s', time() - $windowSecs);
    $stmt = $conn->prepare(
        'SELECT attempted_at FROM rate_limits
         WHERE ip_address = ? AND action = ? AND attempted_at >= ?
         ORDER BY attempted_at ASC
         LIMIT ?, 1'
    );
    $clearanceOffset = max(0, $clearanceNeeded - 1);
    $stmt->bind_param('sssi', $ip, $action, $since, $clearanceOffset);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['attempted_at'])) {
        return 0;
    }

    $expiresAt = strtotime($row['attempted_at']) + $windowSecs;
    // Fix 1: return the actual remaining seconds, not capped at 60.
    return max(0, $expiresAt - time());
}
