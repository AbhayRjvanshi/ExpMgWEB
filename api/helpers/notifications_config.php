<?php

require_once __DIR__ . '/../../config/env.php';

/**
 * Notification backend configuration.
 *
 * BACKEND OPTIONS
 *  - 'file'  — use per-user JSON files in data/notifications/ (default, no Redis required)
 *  - 'redis' — use Redis-backed ephemeral store (requires api/helpers/redis.php and Redis server)
 *
 * The desired backend is read from the NOTIFICATIONS_BACKEND env var,
 * falling back to 'file' when unset or invalid.
 */
if (!defined('NOTIFICATIONS_BACKEND')) {
    $backend = strtolower((string) env('NOTIFICATIONS_BACKEND', 'file'));
    if (!in_array($backend, ['file', 'redis'], true)) {
        $backend = 'file';
    }
    define('NOTIFICATIONS_BACKEND', $backend);
}

