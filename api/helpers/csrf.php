<?php
/**
 * CSRF Protection Helper (Hardened)
 * 
 * Provides centralized CSRF token generation and verification
 * for all state-changing API endpoints.
 * 
 * Security model:
 * - Session-bound tokens (256-bit cryptographically secure)
 * - Double-submit cookie defense (cookie + header verification)
 * - Automatic token rotation (12-hour lifetime)
 * - Custom header verification (prevents simple form attacks)
 * - Timing-attack resistant comparison
 * - Automatic logging of failed attempts
 */

require_once __DIR__ . '/logger.php';

// Token rotation interval (12 hours)
define('CSRF_TOKEN_LIFETIME', 43200);

/**
 * Generate a new CSRF token for the current session
 * 
 * Also sets a double-submit cookie for additional defense.
 * 
 * @return string The generated token (64 hex characters)
 */
function generateCsrfToken(): string {
    $token = bin2hex(random_bytes(32)); // 256 bits
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_created_at'] = time();
    
    // Set double-submit cookie (readable by JavaScript for header inclusion)
    setcookie(
        'csrf_token',
        $token,
        [
            'expires' => 0,              // Session cookie
            'path' => '/',
            'domain' => '',
            // Only sent over HTTPS when the request itself is HTTPS.
            'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                        || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443,
            'httponly' => false,         // Must be readable by JavaScript
            'samesite' => 'Lax'
        ]
    );
    
    return $token;
}

/**
 * Get the current session's CSRF token, generating one if needed
 * 
 * Automatically rotates token if older than CSRF_TOKEN_LIFETIME.
 * 
 * @return string The CSRF token
 */
function getCsrfToken(): string {
    // Check if token exists and is still valid
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_created_at'])) {
        return generateCsrfToken();
    }
    
    // Rotate token if older than 12 hours
    if (time() - $_SESSION['csrf_created_at'] > CSRF_TOKEN_LIFETIME) {
        logMessage('INFO', 'CSRF token auto-rotated due to age', [
            'user_id' => $_SESSION['user_id'] ?? null,
            'token_age' => time() - $_SESSION['csrf_created_at']
        ]);
        return generateCsrfToken();
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * Rotate the CSRF token (call on login/logout)
 * 
 * @return string The new token
 */
function rotateCsrfToken(): string {
    return generateCsrfToken();
}

/**
 * Verify CSRF token from request headers and cookie (double-submit)
 * 
 * Terminates execution with 403 if verification fails.
 * Logs all failed attempts for security monitoring.
 * 
 * @return void
 */
function verifyCsrf(): void {
    // Check session token exists
    if (!isset($_SESSION['csrf_token'])) {
        logMessage('WARNING', 'CSRF verification failed: session token missing', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? null
        ]);
        
        recordCsrfFailure();
        
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'CSRF token missing from session'
        ]);
        exit;
    }

    // Request token: X-CSRF-Token header (any SAPI), with explicit
    // $_SERVER and POST-body fallbacks so native form posts keep working.
    $headers = [];
    if (function_exists('getallheaders')) {
        $rawHeaders = getallheaders();
        if (is_array($rawHeaders)) {
            // Normalize header keys to lowercase for case-insensitive lookup
            $headers = array_change_key_case($rawHeaders, CASE_LOWER);
        }
    }

    $headerToken = (string) ($headers['x-csrf-token']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN']
        ?? ($_POST['csrf_token'] ?? '')));

    // Check request token exists
    if ($headerToken === '') {
        logMessage('WARNING', 'CSRF verification failed: request token missing', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? null
        ]);
        
        recordCsrfFailure();
        
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'CSRF token missing from request'
        ]);
        exit;
    }

    $sessionToken = $_SESSION['csrf_token'];

    // Timing-attack resistant comparison (session token vs header token)
    if (!hash_equals($sessionToken, $headerToken)) {
        logMessage('WARNING', 'CSRF verification failed: token mismatch', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? null,
            'token_length' => strlen($headerToken)
        ]);
        
        recordCsrfFailure();
        
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Invalid CSRF token'
        ]);
        exit;
    }
    
    // Double-submit cookie verification (optional but recommended)
    if (isset($_COOKIE['csrf_token'])) {
        $cookieToken = $_COOKIE['csrf_token'];
        
        if (!hash_equals($sessionToken, $cookieToken)) {
            logMessage('WARNING', 'CSRF verification failed: cookie mismatch', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'user_id' => $_SESSION['user_id'] ?? null
            ]);
            
            recordCsrfFailure();
            
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'error' => 'CSRF cookie mismatch'
            ]);
            exit;
        }
    }

    // Verification successful - no logging needed for normal operation
}

/**
 * Verify CSRF token only for state-changing HTTP methods
 * 
 * Automatically determines if verification is needed based on request method.
 * Safe methods (GET, HEAD, OPTIONS) are exempt.
 * 
 * @return void
 */
function verifyCsrfIfNeeded(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    // Only verify for state-changing methods
    $mutatingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
    
    if (in_array($method, $mutatingMethods, true)) {
        verifyCsrf();
    }
}

/**
 * Record CSRF failure for rate monitoring
 * 
 * Tracks failures in session for potential IP blocking.
 * 
 * @return void
 */
function recordCsrfFailure(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    global $conn;

    // Also persist the failure by IP. Session-only counters are trivially
    // bypassed by an attacker who simply drops cookies, so cookieless/failed
    // CSRF attempts must be visible in rate_limits as well.
    if ($conn instanceof mysqli
        && function_exists('checkRateLimit')
        && function_exists('recordRateLimit')) {
        checkRateLimit($conn, $ip, 'csrf_fail', 50, 600);
        recordRateLimit($conn, $ip, 'csrf_fail', 600);
    }

    // Initialize failure tracking in session
    if (!isset($_SESSION['csrf_failures'])) {
        $_SESSION['csrf_failures'] = [];
    }
    
    // Clean old failures (older than 10 minutes)
    $cutoff = time() - 600;
    $_SESSION['csrf_failures'] = array_filter(
        $_SESSION['csrf_failures'],
        function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        }
    );
    
    // Record this failure
    $_SESSION['csrf_failures'][] = time();
    
    // Check if threshold exceeded (50 failures in 10 minutes)
    if (count($_SESSION['csrf_failures']) >= 50) {
        logMessage('CRITICAL', 'CSRF failure threshold exceeded - possible attack', [
            'ip' => $ip,
            'failure_count' => count($_SESSION['csrf_failures']),
            'user_id' => $_SESSION['user_id'] ?? null
        ]);
        
        // Optional: Trigger additional security measures
        // - Block IP temporarily
        // - Send alert notification
        // - Increase rate limiting
    }
}
