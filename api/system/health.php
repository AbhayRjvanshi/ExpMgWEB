<?php

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../helpers/redis.php';
require_once __DIR__ . '/../helpers/rate_limiter.php';
require_once __DIR__ . '/../helpers/outbox.php';
require_once __DIR__ . '/../../config/idempotency.php';

if (php_sapi_name() !== 'cli') {
    requireAuth();
}

try {
    $redisClient = getRedis();
    $redisHealth = $redisClient->getHealthSnapshot();
    $outboxHealth = outboxStats($conn);

    $queuePressure = (int) ($outboxHealth['queue_pressure'] ?? 0);
    $redisConnected = (bool) ($redisHealth['connected'] ?? false);
    $outboxAvailable = (bool) ($outboxHealth['available'] ?? false);

    $status = 'ok';
    if ($queuePressure > 80) {
        $status = 'critical';
    } elseif ($queuePressure > 50 || !$outboxAvailable) {
        $status = 'degraded';
    } elseif ($queuePressure > 25 || !$redisConnected) {
        $status = 'warning';
    }

    $retryAfter = (int) ($outboxHealth['retry_after'] ?? 0);
    if ($retryAfter < 1 && $status === 'warning') {
        $retryAfter = 5;
    }
    if ($retryAfter < 1 && $status === 'degraded') {
        $retryAfter = 15;
    }
    if ($retryAfter < 1 && $status === 'critical') {
        $retryAfter = 30;
    }

    $result = [
        'ok' => true,
        'status' => $status,
        'queue_pressure' => $queuePressure,
        'retry_after' => $retryAfter,
        'redis' => $redisHealth,
        'rate_limit_backend' => rateLimiterBackend(),
        'idempotency_backend' => env('IDEMPOTENCY_BACKEND', 'file'),
        'notifications_backend' => defined('NOTIFICATIONS_BACKEND') ? NOTIFICATIONS_BACKEND : 'file',
        'outbox' => $outboxHealth,
    ];

    logMessage($status === 'ok' ? 'INFO' : 'WARNING', 'System health snapshot', [
        'status' => $status,
        'queue_pressure' => $queuePressure,
        'retry_after' => $retryAfter,
        'redis_connected' => $redisConnected,
        'outbox_available' => $outboxAvailable,
    ]);

    logMessage($status === 'ok' ? 'INFO' : 'WARNING', '[HEALTH] status update', [
        'status' => $status,
        'queue_pressure' => $queuePressure,
        'retry_after' => $retryAfter,
        'redis_connected' => $redisConnected,
        'outbox_available' => $outboxAvailable,
        'endpoint' => $_SERVER['REQUEST_URI'] ?? '/api/system/health.php',
        'status_code' => 200
    ]);

    apiResponse($result, 200);
} catch (Throwable $e) {
    logMessage('ERROR', 'System health check failed', [
        'error' => $e->getMessage(),
    ]);
    apiError('Internal server error.', 500);
}
