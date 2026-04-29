<?php
chdir(__DIR__ . '/..' . '/..');
require 'config/db.php';
require 'api/helpers/outbox.php';
require_once 'api/helpers/redis.php';
require 'config/idempotency.php';

function scalarCount(mysqli $conn, string $sql): int {
    $res = $conn->query($sql);
    if (!$res) {
        return -1;
    }
    $row = $res->fetch_assoc();
    return (int) ($row['c'] ?? 0);
}

$report = [];
$dbCounts = [
    'expenses' => scalarCount($conn, 'SELECT COUNT(*) AS c FROM expenses'),
    'lists' => scalarCount($conn, 'SELECT COUNT(*) AS c FROM lists'),
    'groups' => scalarCount($conn, 'SELECT COUNT(*) AS c FROM groups'),
    'outbox_processing' => scalarCount($conn, "SELECT COUNT(*) AS c FROM outbox_events WHERE status = 'processing'"),
    'outbox_retryable' => scalarCount($conn, "SELECT COUNT(*) AS c FROM outbox_events WHERE status = 'retryable'"),
    'outbox_pending' => scalarCount($conn, "SELECT COUNT(*) AS c FROM outbox_events WHERE status = 'pending'"),
    'outbox_dead' => scalarCount($conn, "SELECT COUNT(*) AS c FROM outbox_events WHERE status = 'dead'"),
];

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

$health = [
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

$report['db_counts'] = $dbCounts;
$report['health'] = $health;

echo json_encode($report, JSON_PRETTY_PRINT), PHP_EOL;
