<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/services/LockService.php';

$workerId = $argv[1] ?? ('worker_' . getmypid());
$key = $argv[2] ?? 'lock:test:multi_worker_collision';
$ttl = isset($argv[3]) ? (int) $argv[3] : 8;
$holdMs = isset($argv[4]) ? (int) $argv[4] : 1200;

$started = microtime(true);
$acquired = LockService::acquireLock($key, $ttl);

if (!$acquired) {
    echo json_encode([
        'worker' => $workerId,
        'pid' => getmypid(),
        'status' => 'skipped',
        'reason' => 'lock_not_acquired_or_redis_unavailable',
        'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

try {
    if ($holdMs < 1) {
        $holdMs = 1;
    }
    usleep($holdMs * 1000);
    echo json_encode([
        'worker' => $workerId,
        'pid' => getmypid(),
        'status' => 'executed',
        'key' => $key,
        'hold_ms' => $holdMs,
        'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    LockService::releaseLock($key);
}
