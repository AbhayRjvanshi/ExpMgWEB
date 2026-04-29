<?php

/**
 * Environment bootstrap.
 *
 * - Loads environment variables from a .env file in development if
 *   vlucas/phpdotenv is available.
 * - Leaves production/staging free to inject env vars via the OS or
 *   platform (Docker, Kubernetes, cloud secret manager, etc.).
 * - Provides safe defaults for local development when variables are
 *   missing, without overwriting already-defined values.
 */

$projectRoot = dirname(__DIR__);

// Optional Composer autoload (needed for vlucas/phpdotenv)
$autoload = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;

    // Load .env if Dotenv is available
    if (class_exists('Dotenv\\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable($projectRoot);
        // safeLoad(): don't throw if .env is missing (e.g., in production)
        $dotenv->safeLoad();
    }
}

/**
 * Helper: get an environment value with an optional default.
 */
function env(string $key, $default = null) {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    return $default;
}

// Set commonly used defaults for local development if not provided.
if (env('APP_ENV') === null) {
    $_ENV['APP_ENV'] = 'development';
}
if (env('APP_DEBUG') === null) {
    $_ENV['APP_DEBUG'] = '1';
}

// Database defaults (safe for local XAMPP-style setups)
if (env('DB_HOST') === null) {
    $_ENV['DB_HOST'] = '127.0.0.1';
}
if (env('DB_USER') === null) {
    $_ENV['DB_USER'] = 'root';
}
if (env('DB_PASS') === null) {
    $_ENV['DB_PASS'] = '';
}
if (env('DB_NAME') === null) {
    $_ENV['DB_NAME'] = 'ExpMgWEB';
}

// Notifications / Redis defaults
if (env('NOTIFICATIONS_BACKEND') === null) {
    $_ENV['NOTIFICATIONS_BACKEND'] = 'file';
}
if (env('RATE_LIMIT_BACKEND') === null) {
    $_ENV['RATE_LIMIT_BACKEND'] = 'db';
}
if (env('IDEMPOTENCY_BACKEND') === null) {
    $_ENV['IDEMPOTENCY_BACKEND'] = 'file';
}
if (env('REDIS_HOST') === null) {
    $_ENV['REDIS_HOST'] = '127.0.0.1';
}
if (env('REDIS_PORT') === null) {
    $_ENV['REDIS_PORT'] = '6379';
}

