<?php

declare(strict_types=1);

foreach ([
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'ExpMgWEB',
] as $key => $value) {
    if (getenv($key) === false || getenv($key) === '') {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/../../api/helpers/outbox.php';
require_once __DIR__ . '/../../api/helpers/notification_store.php';
require_once __DIR__ . '/../../api/helpers/redis.php';

date_default_timezone_set('UTC');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$baseUrl = 'http://localhost/ExpMgWEB/api';
$runId = 'phase5_' . date('Ymd_His');
$reportPath = __DIR__ . '/phase5_clean_report.json';

$results = [];

function logTest(string $name, string $status, array $notes = []): void
{
    global $results;
    $results[] = [
        'test' => $name,
        'status' => $status,
        'notes' => $notes,
    ];
}

function cookieHeader(array $cookies): string
{
    $parts = [];
    foreach ($cookies as $name => $value) {
        if ($value === '') {
            continue;
        }
        $parts[] = $name . '=' . $value;
    }
    return implode('; ', $parts);
}

function bindParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') {
        return;
    }

    $refs = [];
    foreach ($params as $index => $value) {
        $refs[$index] = &$params[$index];
    }

    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function dbExec(mysqli $conn, string $sql, string $types = '', array $params = []): void
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error ?: 'Failed to prepare statement.');
    }

    bindParams($stmt, $types, $params);
    $stmt->execute();
    $stmt->close();
}

function resetRateLimits(mysqli $conn): void
{
    $conn->query('TRUNCATE TABLE rate_limits');
}

function cleanupArtifacts(mysqli $conn, string $notePrefix, string $eventPrefix, ?int $userId = null): void
{
    if ($notePrefix !== '') {
        dbExec($conn, 'DELETE FROM expenses WHERE note LIKE ?', 's', [$notePrefix . '%']);
    }

    if ($eventPrefix !== '') {
        dbExec($conn, 'DELETE FROM outbox_events WHERE event_type LIKE ?', 's', [$eventPrefix . '%']);
    }

    resetRateLimits($conn);

    if ($userId !== null && function_exists('notifConsumeAll')) {
        notifConsumeAll($userId);
    }
}

function resetOutboxTable(mysqli $conn): void
{
    $conn->query('TRUNCATE TABLE outbox_events');
}

function processOutboxBatch(mysqli $conn, int $limit = 100): array
{
    $claimed = outboxClaimDueEvents($conn, $limit);
    $processed = 0;
    $sent = 0;
    $failed = 0;

    foreach ($claimed as $event) {
        $processed++;
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : null;
        $outboxId = (int) ($event['id'] ?? 0);
        $eventType = (string) ($event['event_type'] ?? 'unknown');
        $retryCount = (int) ($event['retry_count'] ?? 0);

        try {
            $mode = (string) ($payload['mode'] ?? '');
            if ($mode === 'noop') {
                outboxMarkSent($conn, $outboxId);
                $sent++;
                continue;
            }

            if (!$payload || !outboxDispatchNotificationPayload($conn, $payload)) {
                throw new RuntimeException('Unsupported outbox payload.');
            }

            outboxMarkSent($conn, $outboxId);
            $sent++;
        } catch (Throwable $e) {
            outboxMarkFailed($conn, $outboxId, $eventType, $e->getMessage(), $retryCount);
            $failed++;
        }
    }

    return [
        'processed' => $processed,
        'sent' => $sent,
        'failed' => $failed,
    ];
}

function ensureAuthenticatedUser(string $baseUrl, mysqli $conn, string $runId): array
{
    resetRateLimits($conn);

    $seedEmail = 'alice@example.com';
    $seedPassword = 'password123';
    $seedId = (int) dbScalar($conn, 'SELECT id FROM users WHERE email = ? LIMIT 1', 's', [$seedEmail]);

    if ($seedId > 0) {
        $login = curlRequest('POST', $baseUrl . '/login.php', [
            'email' => $seedEmail,
            'password' => $seedPassword,
        ]);

        $seedSession = extractCookie($login['headers'], 'PHPSESSID');
        $seedCsrf = extractCookie($login['headers'], 'csrf_token');

        if (in_array($login['status'], [200, 302, 303], true) && $seedSession !== '' && $seedCsrf !== '') {
            return [
                'user_id' => $seedId,
                'email' => $seedEmail,
                'cookie' => cookieHeader([
                    'PHPSESSID' => $seedSession,
                    'csrf_token' => $seedCsrf,
                ]),
                'csrf' => $seedCsrf,
            ];
        }
    }

    $email = 'phase5_' . $runId . '@example.local';
    $username = 'phase5_' . substr($runId, -8);
    $password = 'Stress123!';

    $signup = curlRequest('POST', $baseUrl . '/signup.php', [
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'confirm_password' => $password,
    ]);

    if (!in_array($signup['status'], [200, 302, 303], true)) {
        throw new RuntimeException('Signup failed for phase 5 runner: HTTP ' . $signup['status']);
    }

    $login = curlRequest('POST', $baseUrl . '/login.php', [
        'email' => $email,
        'password' => $password,
    ]);

    $session = extractCookie($login['headers'], 'PHPSESSID');
    $csrf = extractCookie($login['headers'], 'csrf_token');
    if ($session === '' || $csrf === '') {
        throw new RuntimeException('Login failed for phase 5 runner.');
    }

    $userId = (int) dbScalar($conn, 'SELECT id FROM users WHERE email = ? LIMIT 1', 's', [$email]);

    return [
        'user_id' => $userId,
        'email' => $email,
        'cookie' => cookieHeader([
            'PHPSESSID' => $session,
            'csrf_token' => $csrf,
        ]),
        'csrf' => $csrf,
    ];
}

function requestHealth(string $baseUrl, array $auth): array
{
    return curlRequest('GET', $baseUrl . '/system/health.php', [], $auth['cookie']);
}

function requestOutboxWorker(string $baseUrl, array $auth, int $limit = 100): array
{
    return curlRequest('GET', $baseUrl . '/workers/process_outbox.php', [
        'limit' => (string) $limit,
    ], $auth['cookie']);
}

function createExpenseRequest(string $baseUrl, array $auth, string $idempotencyKey, string $note): array
{
    return curlRequest('POST', $baseUrl . '/expenses/create.php', [
        'amount' => '25',
        'category_id' => '1',
        'note' => $note,
        'expense_date' => date('Y-m-d'),
        'type' => 'personal',
    ], $auth['cookie'], [
        'X-CSRF-Token: ' . $auth['csrf'],
        'Idempotency-Key: ' . $idempotencyKey,
    ]);
}

function statusName(array $response): string
{
    return (string) ($response['json']['status'] ?? 'unknown');
}

function queueRowsForPrefix(mysqli $conn, string $prefix): int
{
    return (int) dbScalar($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type LIKE ?', 's', [$prefix . '%']);
}

function pendingRowsForPrefix(mysqli $conn, string $prefix): int
{
    return (int) dbScalar(
        $conn,
        'SELECT COUNT(*) FROM outbox_events WHERE event_type LIKE ? AND status IN ("pending", "retryable", "processing")',
        's',
        [$prefix . '%']
    );
}

function expenseRowsForPrefix(mysqli $conn, string $prefix): int
{
    return (int) dbScalar($conn, 'SELECT COUNT(*) FROM expenses WHERE note LIKE ?', 's', [$prefix . '%']);
}

function duplicateExpenseNotes(mysqli $conn, string $prefix): int
{
    return (int) dbScalar(
        $conn,
        'SELECT COUNT(*) FROM (
            SELECT note, COUNT(*) AS c
            FROM expenses
            WHERE note LIKE ?
            GROUP BY note
            HAVING c > 1
        ) AS duplicates',
        's',
        [$prefix . '%']
    );
}

function writeReport(string $path, array $payload): void
{
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

try {
    $auth = ensureAuthenticatedUser($baseUrl, $conn, $runId);

    cleanupArtifacts($conn, 'phase5_a_' . $runId, 'phase5.a.' . $runId, $auth['user_id']);
    $burstPrefix = 'phase5_a_' . $runId;
    $burstStatuses = [];
    for ($batch = 0; $batch < 12; $batch++) {
        resetRateLimits($conn);
        for ($index = 0; $index < 5; $index++) {
            $requestIndex = $batch * 5 + $index;
            $response = createExpenseRequest($baseUrl, $auth, 'burst-' . $runId . '-' . $requestIndex, $burstPrefix . '-' . $requestIndex);
            $burstStatuses[$response['status']] = ($burstStatuses[$response['status']] ?? 0) + 1;
        }
    }

    $burstCreated = expenseRowsForPrefix($conn, $burstPrefix);
    $burstDuplicates = duplicateExpenseNotes($conn, $burstPrefix);
    logTest('Burst', ($burstCreated === 60 && $burstDuplicates === 0) ? 'PASS' : 'FAIL', [
        'requests' => 60,
        'created_rows' => $burstCreated,
        'duplicate_notes' => $burstDuplicates,
        'status_counts' => $burstStatuses,
    ]);
    cleanupArtifacts($conn, $burstPrefix, '', $auth['user_id']);

    cleanupArtifacts($conn, 'phase5_b_' . $runId, 'phase5.b.' . $runId, $auth['user_id']);
    $duplicatePrefix = 'phase5_b_' . $runId;
    $duplicateStatuses = [];
    for ($attempt = 0; $attempt < 20; $attempt++) {
        resetRateLimits($conn);
        $response = createExpenseRequest($baseUrl, $auth, 'duplicate-' . $runId . '-same-key', $duplicatePrefix . '-same-note');
        $duplicateStatuses[$response['status']] = ($duplicateStatuses[$response['status']] ?? 0) + 1;
    }

    $duplicateCreated = expenseRowsForPrefix($conn, $duplicatePrefix);
    logTest('Duplicate', $duplicateCreated === 1 ? 'PASS' : 'FAIL', [
        'requests' => 20,
        'created_rows' => $duplicateCreated,
        'status_counts' => $duplicateStatuses,
        'idempotency_key' => 'duplicate-' . $runId . '-same-key',
    ]);
    cleanupArtifacts($conn, $duplicatePrefix, '', $auth['user_id']);

    cleanupArtifacts($conn, '', 'phase5.c.' . $runId, $auth['user_id']);
    $outboxPrefix = 'phase5.c.' . $runId;
    for ($index = 0; $index < 120; $index++) {
        outboxQueueEvent($conn, $outboxPrefix, [
            'mode' => 'users',
            'user_ids' => [$auth['user_id']],
            'event' => [
                'event_id' => $outboxPrefix . '.' . $index,
                'type' => 'phase5_outbox',
                'message' => 'phase5 outbox event',
                'group_id' => 0,
                'actor_id' => $auth['user_id'],
                'ref_id' => $index,
                'ts' => time(),
            ],
        ]);
    }

    $outboxStart = microtime(true);
    $outboxProcessed = 0;
    for ($round = 0; $round < 10; $round++) {
        $worker = requestOutboxWorker($baseUrl, $auth, 100);
        $workerProcessed = (int) ($worker['json']['processed'] ?? 0);
        $outboxProcessed += $workerProcessed;
        if ($workerProcessed === 0) {
            break;
        }
    }
    $outboxDrainSeconds = round(microtime(true) - $outboxStart, 2);
    $outboxRemaining = pendingRowsForPrefix($conn, $outboxPrefix);
    logTest('Outbox', $outboxRemaining === 0 ? 'PASS' : 'FAIL', [
        'enqueued' => 120,
        'processed' => $outboxProcessed,
        'drain_time_seconds' => $outboxDrainSeconds,
        'stuck_rows' => $outboxRemaining,
    ]);
    resetOutboxTable($conn);
    cleanupArtifacts($conn, '', '', $auth['user_id']);

    cleanupArtifacts($conn, '', 'phase5.d.' . $runId, $auth['user_id']);
    $failurePrefix = 'phase5.d.' . $runId;
    for ($index = 0; $index < 20; $index++) {
        outboxQueueEvent($conn, $failurePrefix, [
            'mode' => 'invalid_mode',
            'event' => [
                'event_id' => $failurePrefix . '.' . $index,
                'type' => 'phase5_failure',
                'message' => 'phase5 failure payload',
                'group_id' => 0,
                'actor_id' => $auth['user_id'],
                'ref_id' => $index,
                'ts' => time(),
            ],
        ]);
    }

    $failureMaxRetry = 0;
    for ($round = 0; $round < 6; $round++) {
        $worker = requestOutboxWorker($baseUrl, $auth, 100);
        $batchProcessed = (int) ($worker['json']['processed'] ?? 0);
        $failureMaxRetry = max($failureMaxRetry, (int) dbScalar($conn, 'SELECT COALESCE(MAX(retry_count), 0) FROM outbox_events WHERE event_type = ?', 's', [$failurePrefix]));
        dbExec($conn, 'UPDATE outbox_events SET next_attempt_at = NOW() WHERE event_type = ? AND status = "retryable"', 's', [$failurePrefix]);
        if ($batchProcessed === 0) {
            break;
        }
    }
    $failureDead = (int) dbScalar($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status = "dead"', 's', [$failurePrefix]);
    $failureRetryable = (int) dbScalar($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status = "retryable"', 's', [$failurePrefix]);
    logTest('Failure Injection', ($failureDead > 0 && $failureMaxRetry >= 5 && $failureRetryable === 0) ? 'PASS' : 'FAIL', [
        'max_retry_count' => $failureMaxRetry,
        'dead_rows' => $failureDead,
        'retryable_rows' => $failureRetryable,
    ]);
    resetOutboxTable($conn);
    cleanupArtifacts($conn, '', '', $auth['user_id']);

    $originalRedisHost = getenv('REDIS_HOST');
    $originalRedisPort = getenv('REDIS_PORT');
    putenv('REDIS_HOST=127.0.0.1');
    putenv('REDIS_PORT=6390');
    $redisClient = new RedisClient();
    $redisHealth = $redisClient->getHealthSnapshot();
    $redisPass = (($redisHealth['connected'] ?? true) === false);
    logTest('Redis Fallback', $redisPass ? 'PASS' : 'FAIL', [
        'connected' => $redisHealth['connected'] ?? null,
        'error' => $redisHealth['error'] ?? null,
        'host' => $redisHealth['host'] ?? null,
        'port' => $redisHealth['port'] ?? null,
    ]);
    if ($originalRedisHost !== false) {
        putenv('REDIS_HOST=' . $originalRedisHost);
    } else {
        putenv('REDIS_HOST');
    }
    if ($originalRedisPort !== false) {
        putenv('REDIS_PORT=' . $originalRedisPort);
    } else {
        putenv('REDIS_PORT');
    }

    resetOutboxTable($conn);
    cleanupArtifacts($conn, '', '', $auth['user_id']);
    $pressurePrefix = 'phase5.f.' . $runId;
    for ($index = 0; $index < 60; $index++) {
        outboxQueueEvent($conn, $pressurePrefix, [
            'mode' => 'invalid_mode',
            'event' => [
                'event_id' => $pressurePrefix . '.' . $index,
                'type' => 'phase5_pressure',
                'message' => 'phase5 pressure payload',
                'group_id' => 0,
                'actor_id' => $auth['user_id'],
                'ref_id' => $index,
                'ts' => time(),
            ],
        ]);
    }

    $pressureHealth = requestHealth($baseUrl, $auth);
    $categories = curlRequest('GET', $baseUrl . '/expenses/categories.php', [], $auth['cookie']);
    $pressureStatus = statusName($pressureHealth);
    $pressureQueue = (int) ($pressureHealth['json']['queue_pressure'] ?? 0);
    $queuePass = ($pressureStatus === 'degraded' && $pressureQueue >= 60 && (int) $categories['status'] === 200);
    logTest('Queue Storm', $queuePass ? 'PASS' : 'FAIL', [
        'health_status' => $pressureStatus,
        'queue_pressure' => $pressureQueue,
        'essential_api_http_status' => $categories['status'],
        'essential_api_ok' => (bool) ($categories['json']['ok'] ?? false),
    ]);
    resetOutboxTable($conn);
    cleanupArtifacts($conn, '', '', $auth['user_id']);

    resetOutboxTable($conn);
    cleanupArtifacts($conn, '', '', $auth['user_id']);
    $oscPrefix = 'phase5.g.' . $runId;

    $oscSnapshots = [];
    $oscSnapshots[] = requestHealth($baseUrl, $auth);

    for ($index = 0; $index < 30; $index++) {
        outboxQueueEvent($conn, $oscPrefix, [
            'mode' => 'invalid_mode',
            'event' => [
                'event_id' => $oscPrefix . '.30.' . $index,
                'type' => 'phase5_oscillation',
                'message' => 'phase5 oscillation payload',
                'group_id' => 0,
                'actor_id' => $auth['user_id'],
                'ref_id' => $index,
                'ts' => time(),
            ],
        ]);
    }
    $oscSnapshots[] = requestHealth($baseUrl, $auth);

    for ($index = 30; $index < 60; $index++) {
        outboxQueueEvent($conn, $oscPrefix, [
            'mode' => 'invalid_mode',
            'event' => [
                'event_id' => $oscPrefix . '.60.' . $index,
                'type' => 'phase5_oscillation',
                'message' => 'phase5 oscillation payload',
                'group_id' => 0,
                'actor_id' => $auth['user_id'],
                'ref_id' => $index,
                'ts' => time(),
            ],
        ]);
    }
    $oscSnapshots[] = requestHealth($baseUrl, $auth);

    for ($index = 60; $index < 90; $index++) {
        outboxQueueEvent($conn, $oscPrefix, [
            'mode' => 'invalid_mode',
            'event' => [
                'event_id' => $oscPrefix . '.90.' . $index,
                'type' => 'phase5_oscillation',
                'message' => 'phase5 oscillation payload',
                'group_id' => 0,
                'actor_id' => $auth['user_id'],
                'ref_id' => $index,
                'ts' => time(),
            ],
        ]);
    }
    $oscSnapshots[] = requestHealth($baseUrl, $auth);

    cleanupArtifacts($conn, '', $oscPrefix, $auth['user_id']);
    $oscSnapshots[] = requestHealth($baseUrl, $auth);

    $oscStatuses = array_map(static function (array $snapshot): string {
        return (string) ($snapshot['json']['status'] ?? 'unknown');
    }, $oscSnapshots);
    $oscQueuePressures = array_map(static function (array $snapshot): int {
        return (int) ($snapshot['json']['queue_pressure'] ?? 0);
    }, $oscSnapshots);

    $oscillationPass =
        count($oscStatuses) === 5 &&
        $oscQueuePressures[0] < 25 &&
        $oscQueuePressures[1] >= 25 &&
        $oscQueuePressures[2] >= 50 &&
        $oscQueuePressures[3] >= 80 &&
        $oscQueuePressures[4] < 25 &&
        $oscStatuses[3] === 'critical' &&
        $oscStatuses[4] !== 'critical';

    logTest('Health Oscillation', $oscillationPass ? 'PASS' : 'FAIL', [
        'status_sequence' => $oscStatuses,
        'queue_pressure_sequence' => $oscQueuePressures,
    ]);

    $report = [
        'run_id' => $runId,
        'created_at' => date('c'),
        'report_file' => $reportPath,
        'results' => $results,
        'core_metrics' => [
            'burst_created_rows' => $burstCreated,
            'duplicate_created_rows' => $duplicateCreated,
            'outbox_processed' => $outboxProcessed,
            'outbox_drain_time_seconds' => $outboxDrainSeconds,
            'failure_max_retry_count' => $failureMaxRetry,
            'failure_dead_rows' => $failureDead,
            'queue_pressure_observed' => $pressureQueue,
            'queue_health_status' => $pressureStatus,
            'health_status_sequence' => $oscStatuses,
            'health_queue_pressure_sequence' => $oscQueuePressures,
            'redis_connected' => $redisHealth['connected'] ?? null,
        ],
    ];

    writeReport($reportPath, $report);

    $failed = array_filter($results, static fn(array $row): bool => ($row['status'] ?? '') !== 'PASS');

    echo "Phase 5 deterministic run complete\n";
    echo 'Report: ' . $reportPath . "\n";

    foreach ($results as $row) {
        echo $row['test'] . ': ' . $row['status'] . "\n";
    }

    exit(empty($failed) ? 0 : 1);
} catch (Throwable $e) {
    $report = [
        'run_id' => $runId,
        'created_at' => date('c'),
        'report_file' => $reportPath,
        'results' => $results,
        'error' => $e->getMessage(),
    ];
    writeReport($reportPath, $report);
    fwrite(STDERR, 'Phase 5 runner failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}