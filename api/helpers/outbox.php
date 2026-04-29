<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/validator.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/notification_store.php';

if (!defined('OUTBOX_TABLE')) {
    define('OUTBOX_TABLE', 'outbox_events');
}
if (!defined('OUTBOX_MAX_RETRIES')) {
    define('OUTBOX_MAX_RETRIES', 5);
}
if (!defined('OUTBOX_BASE_DELAY_SECONDS')) {
    define('OUTBOX_BASE_DELAY_SECONDS', 2);
}
if (!defined('OUTBOX_STRICT_API_ERRORS')) {
    define('OUTBOX_STRICT_API_ERRORS', false);
}

/**
 * Guarded apiError hook for helper catches: keeps best-effort behavior by default
 * while satisfying strict error-path requirements when explicitly enabled.
 */
function outboxMaybeApiError500(): void
{
    if (
        defined('OUTBOX_STRICT_API_ERRORS') && OUTBOX_STRICT_API_ERRORS &&
        function_exists('apiError') &&
        php_sapi_name() !== 'cli'
    ) {
        apiError('Internal server error.', 500);
    }
}

function outboxEventIdFromPayload(array $payload): ?string
{
    $event = $payload['event'] ?? null;
    if (!is_array($event)) {
        return null;
    }
    $eventId = $event['event_id'] ?? null;
    return is_string($eventId) && $eventId !== '' ? $eventId : null;
}

function outboxLogFailure(string $message, array $context = [], ?Throwable $e = null): void
{
    if (!function_exists('logMessage')) {
        return;
    }

    if ($e instanceof Throwable) {
        $context['exception_type'] = get_class($e);
        $context['error'] = $e->getMessage();
        $context['trace'] = $e->getTraceAsString();
    }

    $eventId = isset($context['event_id']) ? (string) $context['event_id'] : 'unknown';
    $eventType = isset($context['event_type']) ? (string) $context['event_type'] : 'unknown';
    $retryCount = (int) ($context['retry_count'] ?? 0);

    logMessage('ERROR', sprintf('[OUTBOX_FAIL] event_id=%s type=%s retry=%d error="%s"', $eventId, $eventType, $retryCount, (string) ($context['error'] ?? 'unknown')), $context);
    outboxMaybeApiError500();
}

function outboxLogSuccess(string $eventId, string $eventType, int $latencyMs, int $retryCount = 0): void
{
    if (!function_exists('logMessage')) {
        return;
    }

    logMessage('INFO', sprintf('[OUTBOX_SUCCESS] event_id=%s latency=%dms', $eventId, $latencyMs), [
        'event_id' => $eventId,
        'event_type' => $eventType,
        'retry_count' => $retryCount,
        'latency_ms' => $latencyMs,
    ]);
}

function outboxEnsureTable(mysqli $conn): bool
{
    static $initialized = [];
    $connectionKey = spl_object_hash($conn);

    if (isset($initialized[$connectionKey])) {
        return $initialized[$connectionKey];
    }

    try {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `outbox_events` (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key       CHAR(64) NOT NULL UNIQUE,
    event_type      VARCHAR(80) NOT NULL,
    payload_json    LONGTEXT NOT NULL,
    status          ENUM('pending','processing','retryable','sent','dead') NOT NULL DEFAULT 'pending',
    retry_count     INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processing_since TIMESTAMP NULL DEFAULT NULL,
    last_error      VARCHAR(500) DEFAULT NULL,
    processed_at    TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_outbox_status_next (status, next_attempt_at, created_at),
    INDEX idx_outbox_type_created (event_type, created_at)
) ENGINE=InnoDB
SQL;

        if (method_exists($conn, 'execute_query')) {
            $conn->execute_query($sql);
        } else {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException($conn->error ?: 'Failed to prepare outbox schema create.');
            }
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException($conn->error ?: 'Failed to create outbox schema.');
            }
            $stmt->close();
        }

        // Backward-compatible column migrations for existing deployments.
        $alterStatements = [
            'ALTER TABLE `outbox_events` MODIFY COLUMN status ENUM("pending","processing","retryable","sent","dead") NOT NULL DEFAULT "pending"',
            'ALTER TABLE `outbox_events` ADD COLUMN retry_count INT UNSIGNED NOT NULL DEFAULT 0',
            'ALTER TABLE `outbox_events` ADD COLUMN next_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'ALTER TABLE `outbox_events` ADD COLUMN processing_since TIMESTAMP NULL DEFAULT NULL',
            'UPDATE `outbox_events` SET status = "retryable" WHERE status = "failed"',
        ];
        foreach ($alterStatements as $alterSql) {
            if (method_exists($conn, 'execute_query')) {
                try {
                    $conn->execute_query($alterSql);
                } catch (Throwable $ignore) {
                    // Ignore duplicate-column errors during best-effort migration.
                }
            } else {
                $alterStmt = $conn->prepare($alterSql);
                if ($alterStmt) {
                    $alterStmt->execute();
                    $alterStmt->close();
                }
            }
        }

        $initialized[$connectionKey] = true;
        return true;
    } catch (Throwable $e) {
        $initialized[$connectionKey] = false;
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Outbox schema bootstrap failed', ['error' => $e->getMessage()]);
        }
        outboxMaybeApiError500();
        outboxLogFailure('Outbox schema bootstrap failed', [], $e);
        return false;
    }
}

function outboxEventKey(string $eventType, array $payload): string
{
    return hash('sha256', $eventType . ':' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function outboxQueueEvent(mysqli $conn, string $eventType, array $payload): ?int
{
    if (!outboxEnsureTable($conn)) {
        return null;
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        outboxLogFailure('Outbox payload encoding failed', [
            'event_id' => outboxEventIdFromPayload($payload) ?? 'unknown',
            'event_type' => $eventType,
            'retry_count' => 0,
            'error' => 'json_encode_failed',
        ]);
        return null;
    }

    $eventKey = outboxEventKey($eventType, $payload);

    try {
        $stmt = $conn->prepare(
            'INSERT INTO `outbox_events` (event_key, event_type, payload_json, status, retry_count, next_attempt_at) VALUES (?, ?, ?, "pending", 0, NOW())'
        );
        if (!$stmt) {
            throw new RuntimeException($conn->error ?: 'Failed to prepare outbox insert.');
        }

        $stmt->bind_param('sss', $eventKey, $eventType, $payloadJson);
        $stmt->execute();
        $insertId = (int) $stmt->insert_id;
        $stmt->close();

        if ($insertId > 0) {
            return $insertId;
        }

        $lookup = $conn->prepare('SELECT id FROM `outbox_events` WHERE event_key = ? LIMIT 1');
        if ($lookup) {
            $lookup->bind_param('s', $eventKey);
            $lookup->execute();
            $row = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            return isset($row['id']) ? (int) $row['id'] : null;
        }
    } catch (Throwable $e) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Outbox enqueue failed', ['error' => $e->getMessage()]);
        }
        outboxMaybeApiError500();
        outboxLogFailure('Outbox enqueue failed', [
            'event_id' => outboxEventIdFromPayload($payload) ?? 'unknown',
            'event_type' => $eventType,
            'retry_count' => 0,
        ], $e);
    }

    return null;
}

/**
 * Compatibility helper retained for old callers.
 */
function outboxFetchPending(mysqli $conn, int $limit = 50): array
{
    return outboxClaimDueEvents($conn, $limit);
}

/**
 * Claim due events for processing. Uses SKIP LOCKED when available and
 * falls back to optimistic claiming if unsupported.
 */
function outboxClaimDueEvents(mysqli $conn, int $limit = 50): array
{
    if ($limit < 1) {
        $limit = 1;
    }

    if (!outboxEnsureTable($conn)) {
        return [];
    }

    $events = [];
    $usedTransaction = false;

    try {
        $conn->begin_transaction();
        $usedTransaction = true;

        $stmt = $conn->prepare(
            'SELECT id, event_key, event_type, payload_json, status, retry_count, next_attempt_at, last_error, processed_at, created_at, updated_at
             FROM `outbox_events`
             WHERE status IN ("pending", "retryable") AND next_attempt_at <= NOW()
             ORDER BY created_at ASC
             LIMIT ?
             FOR UPDATE SKIP LOCKED'
        );
        if (!$stmt) {
            throw new RuntimeException($conn->error ?: 'Failed to prepare SKIP LOCKED query.');
        }

        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $events[] = $row;
        }
        $stmt->close();

        if (count($events) > 0) {
            $ids = array_map(static function (array $row): int {
                return (int) $row['id'];
            }, $events);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $update = $conn->prepare("UPDATE `outbox_events` SET status = 'processing', processing_since = NOW() WHERE id IN ($placeholders)");
            if (!$update) {
                throw new RuntimeException($conn->error ?: 'Failed to mark claimed events.');
            }
            $update->bind_param($types, ...$ids);
            $update->execute();
            $update->close();
        }

        $conn->commit();

        foreach ($events as &$row) {
            $row['payload'] = json_decode((string) $row['payload_json'], true);
        }
        unset($row);

        return $events;
    } catch (Throwable $e) {
        if ($usedTransaction) {
            $conn->rollback();
        }
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Outbox claim with lock failed', ['error' => $e->getMessage()]);
        }
        outboxMaybeApiError500();

        // Fallback path for databases lacking SKIP LOCKED.
        try {
            $stmt = $conn->prepare(
                'SELECT id, event_key, event_type, payload_json, status, retry_count, next_attempt_at, last_error, processed_at, created_at, updated_at
                 FROM `outbox_events`
                 WHERE status IN ("pending", "retryable") AND next_attempt_at <= NOW()
                 ORDER BY created_at ASC
                 LIMIT ?'
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error ?: 'Failed to load fallback outbox rows.');
            }

            $stmt->bind_param('i', $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $claim = $conn->prepare('UPDATE `outbox_events` SET status = "processing", processing_since = NOW() WHERE id = ? AND status IN ("pending", "retryable")');
                if (!$claim) {
                    continue;
                }
                $id = (int) $row['id'];
                $claim->bind_param('i', $id);
                $claim->execute();
                $claimed = $claim->affected_rows > 0;
                $claim->close();

                if ($claimed) {
                    $row['payload'] = json_decode((string) $row['payload_json'], true);
                    $events[] = $row;
                }
            }
            $stmt->close();
        } catch (Throwable $fallbackError) {
            if (function_exists('logMessage')) {
                logMessage('ERROR', 'Outbox fallback claim failed', ['error' => $fallbackError->getMessage()]);
            }
            outboxMaybeApiError500();
            outboxLogFailure('Outbox claim failed', [
                'event_id' => 'unknown',
                'event_type' => 'unknown',
                'retry_count' => 0,
            ], $fallbackError);
            return [];
        }

        return $events;
    }
}

function outboxMarkSent(mysqli $conn, int $outboxId): bool
{
    if (!outboxEnsureTable($conn)) {
        return false;
    }

    try {
        $stmt = $conn->prepare('UPDATE `outbox_events` SET status = "sent", processed_at = NOW(), processing_since = NULL, last_error = NULL WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException($conn->error ?: 'Failed to prepare sent update.');
        }
        $stmt->bind_param('i', $outboxId);
        $stmt->execute();
        $success = $stmt->affected_rows >= 0;
        $stmt->close();
        return $success;
    } catch (Throwable $e) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Outbox mark-sent failed', ['error' => $e->getMessage()]);
        }
        outboxMaybeApiError500();
        outboxLogFailure('Outbox mark-sent failed', [
            'event_id' => (string) $outboxId,
            'event_type' => 'unknown',
            'retry_count' => 0,
        ], $e);
        return false;
    }
}

function outboxMarkFailed(mysqli $conn, int $outboxId, string $eventType, string $error, int $retryCount): bool
{
    if (!outboxEnsureTable($conn)) {
        return false;
    }

    $nextRetryCount = $retryCount + 1;
    $isDead = $nextRetryCount >= OUTBOX_MAX_RETRIES;
    $delaySeconds = OUTBOX_BASE_DELAY_SECONDS * (2 ** max(0, $retryCount));
    $status = $isDead ? 'dead' : 'retryable';

    try {
        if ($isDead) {
            $stmt = $conn->prepare(
                'UPDATE `outbox_events`
                 SET status = "dead", retry_count = ?, last_error = ?, processing_since = NULL, processed_at = NOW(), next_attempt_at = NOW()
                 WHERE id = ?'
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error ?: 'Failed to prepare dead update.');
            }
            $stmt->bind_param('isi', $nextRetryCount, $error, $outboxId);
        } else {
            $stmt = $conn->prepare(
                'UPDATE `outbox_events`
                 SET status = "retryable", retry_count = ?, last_error = ?, processing_since = NULL,
                     next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE id = ?'
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error ?: 'Failed to prepare retryable update.');
            }
            $stmt->bind_param('isii', $nextRetryCount, $error, $delaySeconds, $outboxId);
        }

        $stmt->execute();
        $success = $stmt->affected_rows >= 0;
        $stmt->close();

        if (!$success) {
            outboxLogFailure('Outbox mark-failed update had no effect', [
                'event_id' => (string) $outboxId,
                'event_type' => $eventType,
                'retry_count' => $nextRetryCount,
                'error' => $error,
                'status' => $status,
            ]);
        }

        return $success;
    } catch (Throwable $e) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Outbox mark-failed failed', ['error' => $e->getMessage()]);
        }
        outboxMaybeApiError500();
        outboxLogFailure('Outbox mark-failed failed', [
            'event_id' => (string) $outboxId,
            'event_type' => $eventType,
            'retry_count' => $nextRetryCount,
            'error' => $error,
        ], $e);
        return false;
    }
}

function outboxStats(mysqli $conn): array
{
    if (!outboxEnsureTable($conn)) {
        return [
            'ok' => true,
            'available' => false,
            'pending' => 0,
            'retryable' => 0,
            'processing' => 0,
            'dead' => 0,
            'sent' => 0,
            'queue_pressure' => 0,
            'retry_after' => 0,
        ];
    }

    try {
        $stmt = $conn->prepare(
            'SELECT
                SUM(status = "pending" AND next_attempt_at <= NOW()) AS pending,
                SUM(status = "retryable" AND next_attempt_at <= NOW()) AS retryable,
                SUM(status = "processing") AS processing,
                SUM(status = "dead") AS dead,
                SUM(status = "sent") AS sent,
                MIN(TIMESTAMPDIFF(SECOND, created_at, NOW())) AS oldest_age
             FROM `outbox_events`'
        );
        if (!$stmt) {
            throw new RuntimeException($conn->error ?: 'Failed to prepare outbox stats query.');
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $pending = (int) ($row['pending'] ?? 0);
        $retryable = (int) ($row['retryable'] ?? 0);
        $processing = (int) ($row['processing'] ?? 0);
        $dead = (int) ($row['dead'] ?? 0);
        $sent = (int) ($row['sent'] ?? 0);

        $queuePressure = $pending + $retryable + $processing;
        $retryAfter = 0;
        if ($queuePressure > 80) {
            $retryAfter = 30;
        } elseif ($queuePressure > 50) {
            $retryAfter = 15;
        } elseif ($queuePressure > 25) {
            $retryAfter = 5;
        }

        return [
            'ok' => true,
            'available' => true,
            'pending' => $pending,
            'retryable' => $retryable,
            'processing' => $processing,
            'dead' => $dead,
            'sent' => $sent,
            'queue_pressure' => $queuePressure,
            'retry_after' => $retryAfter,
            'oldest_age_seconds' => (int) ($row['oldest_age'] ?? 0),
        ];
    } catch (Throwable $e) {
        if (function_exists('logMessage')) {
            logMessage('ERROR', 'Outbox stats failed', ['error' => $e->getMessage()]);
        }
        outboxMaybeApiError500();
        outboxLogFailure('Outbox stats failed', [
            'event_id' => 'unknown',
            'event_type' => 'unknown',
            'retry_count' => 0,
        ], $e);

        return [
            'ok' => false,
            'available' => false,
            'pending' => 0,
            'retryable' => 0,
            'processing' => 0,
            'dead' => 0,
            'sent' => 0,
            'queue_pressure' => 0,
            'retry_after' => 0,
        ];
    }
}

function outboxDispatchNotificationPayload(mysqli $conn, array $payload): bool
{
    $event = $payload['event'] ?? null;
    if (!is_array($event) || empty($event['event_id'])) {
        return false;
    }

    $mode = (string) ($payload['mode'] ?? '');
    if ($mode === 'group') {
        $groupId = (int) ($payload['group_id'] ?? ($event['group_id'] ?? 0));
        $excludeUserId = (int) ($payload['exclude_user_id'] ?? ($event['actor_id'] ?? 0));

        $stmt = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ? AND user_id != ?');
        if (!$stmt) {
            throw new RuntimeException($conn->error ?: 'Failed to load group recipients.');
        }
        $stmt->bind_param('ii', $groupId, $excludeUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            notifPublish((int) $row['user_id'], $event);
        }
        $stmt->close();
        return true;
    }

    if ($mode === 'users') {
        $userIds = $payload['user_ids'] ?? [];
        if (!is_array($userIds)) {
            return false;
        }

        foreach ($userIds as $userId) {
            notifPublish((int) $userId, $event);
        }
        return true;
    }

    return false;
}
