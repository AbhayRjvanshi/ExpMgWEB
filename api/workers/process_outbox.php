<?php

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../helpers/outbox.php';
require_once __DIR__ . '/../services/SystemOrchestrator.php';

if (php_sapi_name() !== 'cli') {
    requireAuth();
}

$limit = 50;
if (php_sapi_name() === 'cli') {
    global $argv;
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $limit = (int) $argv[1];
    }
} else {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
}

if ($limit < 1) {
    $limit = 1;
}
validatePositive($limit, 'limit');

try {
    $orchestrator = new SystemOrchestrator();
    $mode = $orchestrator->getSystemMode();
    $concurrency = $orchestrator->getConcurrencyLimit();
    $adaptiveLimit = max(1, $concurrency * 10);
    $limit = min($limit, $adaptiveLimit);

    if ($orchestrator->shouldFallback() && php_sapi_name() !== 'cli') {
        apiError('System overloaded, try again later.', 503);
    }

    $beforeStats = outboxStats($conn);
    $events = outboxClaimDueEvents($conn, $limit);

    $processed = 0;
    $sent = 0;
    $failed = 0;
    $errors = [];

    foreach ($events as $event) {
        $processed++;
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : null;
        $outboxId = (int) ($event['id'] ?? 0);
        $eventType = (string) ($event['event_type'] ?? 'unknown');
        $retryCount = (int) ($event['retry_count'] ?? 0);
        $eventId = outboxEventIdFromPayload($payload ?? []) ?? (string) $outboxId;
        $startedAt = microtime(true);

        try {
            if (!$payload || !outboxDispatchNotificationPayload($conn, $payload)) {
                throw new RuntimeException('Unsupported outbox payload.');
            }

            outboxMarkSent($conn, $outboxId);
            outboxLogSuccess($eventId, $eventType, (int) ((microtime(true) - $startedAt) * 1000), $retryCount);
            $sent++;
        } catch (Throwable $e) {
            outboxLogFailure('Outbox event replay failed', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'retry_count' => $retryCount,
            ], $e);

            outboxMarkFailed($conn, $outboxId, $eventType, $e->getMessage(), $retryCount);
            $failed++;
            $errors[] = [
                'id' => $outboxId,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'retry_count' => $retryCount,
                'error' => $e->getMessage(),
            ];
        }
    }

    $afterStats = outboxStats($conn);

    $result = [
        'ok' => true,
        'mode' => $mode,
        'concurrency_limit' => $concurrency,
        'effective_limit' => $limit,
        'processed' => $processed,
        'sent' => $sent,
        'failed' => $failed,
        'queue_pressure' => $afterStats['queue_pressure'] ?? 0,
        'retry_after' => $afterStats['retry_after'] ?? 0,
        'before' => $beforeStats,
        'after' => $afterStats,
        'errors' => $errors,
    ];

    apiResponse($result, 200);
} catch (Throwable $e) {
    logMessage('ERROR', 'Outbox worker failed', [
        'error' => $e->getMessage(),
    ]);
    apiError('Internal server error.', 500);
}
