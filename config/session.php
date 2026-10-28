<?php
/**
 * config/session.php — Single source of truth for secure session startup.
 *
 * PHP only honours session_set_cookie_params() / session.use_strict_mode
 * BEFORE session_start() runs. Protected API endpoints therefore call
 * ensureSecureSessionStarted() (via api/middleware/auth.php); entrypoints that
 * must keep their own literal session_start() call call
 * configureSecureSessionCookie() immediately above it.
 */

if (!function_exists('expmgIsHttps')) {
    /**
     * Best-effort HTTPS detection (load balancers usually forward
     * X-Forwarded-Proto; XAMPP serves plain HTTP).
     */
    function expmgIsHttps(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    }
}

if (!function_exists('configureSecureSessionCookie')) {
    /**
     * Harden session cookie flags. No-op when a session is already active
     * (PHP would ignore the new parameters anyway).
     */
    function configureSecureSessionCookie(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // Reject client-supplied, uninitialised session IDs.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => expmgIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('ensureSecureSessionStarted')) {
    /**
     * Configure hardened cookie flags and start the session exactly once.
     */
    function ensureSecureSessionStarted(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        configureSecureSessionCookie();
        session_start();
    }
}

if (!function_exists('expmgSendSecurityHeaders')) {
    /**
     * Emit the runtime security headers (CSP + clickjacking/MIME/referrer).
     *
     * Kept in one place because api/bootstrap.php is still not included by any
     * endpoint: api/middleware/auth.php sends them for every API response and
     * public/index.php sends them for the SPA shell.
     */
    function expmgSendSecurityHeaders(): void {
        if (headers_sent()) {
            return;
        }

        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}
