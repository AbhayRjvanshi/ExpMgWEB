<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/helpers/outbox.php';
require_once __DIR__ . '/../api/helpers/redis.php';

function processOnce(mysqli $conn, int $limit = 20): array
{
    $events = outboxClaimDueEvents($conn, $limit);
    $sent = 0;
    $failed = 0;

    foreach ($events as $event) {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : null;
        $outboxId = (int) ($event['id'] ?? 0);
        $retryCount = (int) ($event['retry_count'] ?? 0);
        $eventType = (string) ($event['event_type'] ?? 'unknown');

        try {
            if (!$payload || !outboxDispatchNotificationPayload($conn, $payload)) {
                throw new RuntimeException('Forced validation failure for outbox dispatch');
            }
            outboxMarkSent($conn, $outboxId);
            $sent++;
        } catch (Throwable $e) {
            outboxLogFailure('Validation replay failed', [
                'event_id' => outboxEventIdFromPayload($payload ?? []) ?? (string) $outboxId,
                'event_type' => $eventType,
                'retry_count' => $retryCount,
            ], $e);
            outboxMarkFailed($conn, $outboxId, $eventType, $e->getMessage(), $retryCount);
            $failed++;
        }
    }

    return ['claimed' => count($events), 'sent' => $sent, 'failed' => $failed];
}

$forcedFailPayload = [
    'mode' => 'invalid-mode',
    'event' => [
        'event_id' => 'validation-fail-' . time(),
        'type' => 'validation_fail',
        'message' => 'Intentional outbox failure test',
        'group_id' => 0,
        'actor_id' => 0,
        'ref_id' => 0,
        'ts' => time(),
    ],
];

$successPayload = [
    'mode' => 'users',
    'user_ids' => [1],
    'event' => [
        'event_id' => 'validation-ok-' . time(),
        'type' => 'validation_success',
        'message' => 'Intentional outbox success test',
        'group_id' => 0,
        'actor_id' => 0,
        'ref_id' => 0,
        'ts' => time(),
    ],
];

outboxQueueEvent($conn, 'notification.validation.fail', $forcedFailPayload);
outboxQueueEvent($conn, 'notification.validation.ok', $successPayload);

$run1 = processOnce($conn, 20);
$run2 = processOnce($conn, 20);

$stats = outboxStats($conn);

// Redis fallback smoke check using an invalid host override.
putenv('REDIS_HOST=127.0.0.1');
putenv('REDIS_PORT=6390');
$redis = new RedisClient();
$redisHealth = $redis->getHealthSnapshot();

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'run1' => $run1,
    'run2' => $run2,
    'outbox_stats' => $stats,
    'redis_connected' => $redisHealth['connected'] ?? null,
], JSON_PRETTY_PRINT);
