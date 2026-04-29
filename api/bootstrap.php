<?php
/**
 * API Bootstrap Layer
 * 
 * Centralized request initialization that enforces security policies
 * before any endpoint logic runs.
 * 
 * This file should be included at the top of EVERY API endpoint:
 *   require_once __DIR__ . '/bootstrap.php';
 * 
 * Security enforcement order:
 * 1. Session initialization
 * 2. CSRF protection (for state-changing methods)
 * 3. Content-Security-Policy headers (XSS mitigation)
 * 
 * This ensures security cannot be accidentally bypassed.
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session cookies
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,         // Set to true in production with HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
}

// Load CSRF protection
require_once __DIR__ . '/helpers/csrf.php';
require_once __DIR__ . '/helpers/response.php';

// Ensure CSRF token exists
getCsrfToken();

// Enforce CSRF protection for state-changing requests
// This runs BEFORE authentication, protecting unauthenticated endpoints too
// (e.g., password reset, email verification)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$mutatingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

if (in_array($method, $mutatingMethods, true)) {
    // Check if this is a public endpoint that should skip CSRF
    // (login and signup need special handling since they don't have tokens yet)
    $publicEndpoints = [
        '/api/login.php',
        '/api/signup.php'
    ];
    
    $requestUri = $_SERVER['SCRIPT_NAME'] ?? '';
    $isPublicEndpoint = false;
    
    foreach ($publicEndpoints as $endpoint) {
        if (strpos($requestUri, $endpoint) !== false) {
            $isPublicEndpoint = true;
            break;
        }
    }
    
    // Verify CSRF for all non-public endpoints
    if (!$isPublicEndpoint) {
        verifyCsrf();
    }
}

// Set Content-Security-Policy headers (XSS mitigation)
// This prevents inline scripts and restricts resource loading
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'");

// Set additional security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
