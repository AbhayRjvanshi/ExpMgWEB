<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/../../api/helpers/outbox.php';
require_once __DIR__ . '/../../api/helpers/redis.php';

$baseUrl = 'http://localhost/ExpMgWEB/api';
$runId = 'phase5_' . date('Ymd_His');

function nowMs(): int {
    return (int) round(microtime(true) * 1000);
}

function ensureStressUser(string $baseUrl): array {
    $email = 'stress_phase5@exptest.local';
    $password = 'Stress123!';
    $username = 'stress_phase5';

    httpRequest('POST', $baseUrl . '/signup.php', [
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'confirm_password' => $password,
    ]);

    $login = httpRequest('POST', $baseUrl . '/login.php', [
        'email' => $email,
        'password' => $password,
    ]);

    $sid = extractCookieValue($login['headers'], 'PHPSESSID');
    $csrf = extractCookieValue($login['headers'], 'csrf_token');

    if ($sid === '' || $csrf === '') {
        throw new RuntimeException('Failed to authenticate stress user.');
    }

    return [
        'cookie' => 'PHPSESSID=' . $sid . '; csrf_token=' . $csrf,
        'csrf' => $csrf,
        'email' => $email,
    ];
}

function apiPostAuthed(string $baseUrl, string $path, array $data, string $cookie, string $csrf, string $idempotencyKey): array {
    $headers = [
        'X-CSRF-Token: ' . $csrf,
        'Idempotency-Key: ' . $idempotencyKey,
    ];
    return httpRequest('POST', $baseUrl . '/' . ltrim($path, '/'), $data, $cookie, $headers);
}

function apiGetAuthed(string $baseUrl, string $path, array $params, string $cookie): array {
    return httpRequest('GET', $baseUrl . '/' . ltrim($path, '/'), $params, $cookie);
}

function sendConcurrentWrites(string $baseUrl, string $cookie, string $csrf, string $notePrefix, int $count, int $concurrency, ?string $forcedIdempotencyKey = null): array {
    $mh = curl_multi_init();
    $handles = [];
    $next = 0;
    $done = 0;
    $statusCounts = [];
    $errorCount = 0;
    $latencies = [];

    $spawn = function (int $i) use (&$handles, $mh, $baseUrl, $cookie, $csrf, $notePrefix, $forcedIdempotencyKey): void {
        $idempotencyKey = $forcedIdempotencyKey ?? ('stress-' . $notePrefix . '-' . $i);
        $payload = [
            'amount' => (string) (10 + ($i % 50)),
            'category_id' => '1',
            'note' => $notePrefix . '-' . $i,
            'expense_date' => date('Y-m-d'),
            'type' => 'personal',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/expenses/create.php',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-CSRF-Token: ' . $csrf,
                'Idempotency-Key: ' . $idempotencyKey,
            ],
            CURLOPT_COOKIE => $cookie,
        ]);
        $handles[(int) $ch] = ['handle' => $ch, 'started_ms' => nowMs()];
        curl_multi_add_handle($mh, $ch);
    };

    for (; $next < min($count, $concurrency); $next++) {
        $spawn($next);
    }

    do {
        do {
            $status = curl_multi_exec($mh, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = (int) $ch;
            $meta = $handles[$key] ?? null;
            $done++;

            $response = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;

            if ($info['result'] !== CURLE_OK) {
                $errorCount++;
            }

            if ($meta !== null) {
                $latencies[] = max(0, nowMs() - (int) $meta['started_ms']);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$key]);

            if ($next < $count) {
                $spawn($next);
                $next++;
            }
        }

        if ($active) {
            curl_multi_select($mh, 0.2);
        }
    } while ($active || !empty($handles));

    curl_multi_close($mh);

    sort($latencies);
    $p95 = 0;
    if (!empty($latencies)) {
        $idx = (int) floor((count($latencies) - 1) * 0.95);
        $p95 = (int) $latencies[$idx];
    }

    return [
        'count' => $count,
        'status_counts' => $statusCounts,
        'errors' => $errorCount,
        'p95_ms' => $p95,
    ];
}

function processOutboxOnce(mysqli $conn, int $limit = 200): array {
    $events = outboxClaimDueEvents($conn, $limit);
    $sent = 0;
    $failed = 0;

    foreach ($events as $event) {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : null;
        $outboxId = (int) ($event['id'] ?? 0);
        $eventType = (string) ($event['event_type'] ?? 'unknown');
        $retryCount = (int) ($event['retry_count'] ?? 0);
        $eventId = outboxEventIdFromPayload($payload ?? []) ?? (string) $outboxId;
        $started = microtime(true);

        try {
            if (!$payload || !outboxDispatchNotificationPayload($conn, $payload)) {
                throw new RuntimeException('Unsupported outbox payload.');
            }
            outboxMarkSent($conn, $outboxId);
            outboxLogSuccess($eventId, $eventType, (int) round((microtime(true) - $started) * 1000), $retryCount);
            $sent++;
        } catch (Throwable $e) {
            outboxLogFailure('Outbox event replay failed', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'retry_count' => $retryCount,
            ], $e);
            outboxMarkFailed($conn, $outboxId, $eventType, $e->getMessage(), $retryCount);
            $failed++;
        }
    }

    return ['processed' => count($events), 'sent' => $sent, 'failed' => $failed];
}

$results = [];

try {
    $auth = ensureStressUser($baseUrl);
    $cookie = $auth['cookie'];
    $csrf = $auth['csrf'];

    $userStmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $userStmt->bind_param('s', $auth['email']);
    $userStmt->execute();
    $userId = (int) (($userStmt->get_result()->fetch_assoc()['id'] ?? 0));
    $userStmt->close();

    if ($userId < 1) {
        throw new RuntimeException('Unable to resolve stress user id.');
    }

    // Test A: Burst Write Storm
    $notePrefixA = 'stressA_' . $runId;
    $beforeA = dbSingleInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note LIKE ?', 's', [$notePrefixA . '%']);
    $a = sendConcurrentWrites($baseUrl, $cookie, $csrf, $notePrefixA, 600, 40, null);
    $afterA = dbSingleInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note LIKE ?', 's', [$notePrefixA . '%']);
    $dupA = dbSingleInt($conn, 'SELECT COUNT(*) FROM (SELECT note, COUNT(*) c FROM expenses WHERE note LIKE ? GROUP BY note HAVING c > 1) t', 's', [$notePrefixA . '%']);
    $createdA = $afterA - $beforeA;
    $results['Burst'] = [
        'result' => ($dupA === 0 && $a['errors'] === 0) ? 'PASS' : 'FAIL',
        'notes' => 'Burst storm with concurrent writes',
        'metrics' => [
            'requests' => $a['count'],
            'created_rows' => $createdA,
            'duplicate_notes' => $dupA,
            'p95_ms' => $a['p95_ms'],
            'status_counts' => $a['status_counts'],
        ],
    ];

    // Test B: Duplicate Intent Attack
    $sameKey = 'dup-' . $runId;
    $notePrefixB = 'stressB_' . $runId;
    $beforeB = dbSingleInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note LIKE ?', 's', [$notePrefixB . '%']);
    $b = sendConcurrentWrites($baseUrl, $cookie, $csrf, $notePrefixB, 50, 25, $sameKey);
    $afterB = dbSingleInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note LIKE ?', 's', [$notePrefixB . '%']);
    $createdB = $afterB - $beforeB;
    $results['Duplicate'] = [
        'result' => ($createdB === 1) ? 'PASS' : 'FAIL',
        'notes' => 'Repeated same idempotency key 50 times',
        'metrics' => [
            'requests' => $b['count'],
            'created_rows' => $createdB,
            'status_counts' => $b['status_counts'],
        ],
    ];

    // Test C: Outbox Flood
    $floodType = 'stress.flood.' . $runId;
    $floodCount = 3000;
    for ($i = 0; $i < $floodCount; $i++) {
        outboxQueueEvent($conn, $floodType, [
            'mode' => 'users',
            'user_ids' => [$userId],
            'event' => [
                'event_id' => $floodType . '.' . $i,
                'type' => 'stress_flood',
                'message' => 'flood',
                'group_id' => 0,
                'actor_id' => $userId,
                'ref_id' => $i,
                'ts' => time(),
            ],
        ]);
    }

    $startDrain = microtime(true);
    $drainRounds = 0;
    $drained = 0;
    while ($drainRounds < 80) {
        $drainRounds++;
        $step = processOutboxOnce($conn, 250);
        $drained += $step['processed'];

        $pending = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status IN ("pending","retryable","processing")', 's', [$floodType]);
        if ($pending === 0) {
            break;
        }
    }
    $drainSeconds = round(microtime(true) - $startDrain, 2);
    $stuckProcessing = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status = "processing"', 's', [$floodType]);
    $deadFlood = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status = "dead"', 's', [$floodType]);
    $results['Outbox'] = [
        'result' => ($stuckProcessing === 0) ? 'PASS' : 'FAIL',
        'notes' => 'Flooded outbox and drained with worker loop',
        'metrics' => [
            'enqueued' => $floodCount,
            'drained_attempts' => $drained,
            'drain_time_seconds' => $drainSeconds,
            'stuck_processing' => $stuckProcessing,
            'dead_events' => $deadFlood,
        ],
    ];

    // Test D: Failure Injection
    $failType = 'stress.fail.' . $runId;
    $failCount = 200;
    for ($i = 0; $i < $failCount; $i++) {
        outboxQueueEvent($conn, $failType, [
            'mode' => 'invalid_mode',
            'event' => [
                'event_id' => $failType . '.' . $i,
                'type' => 'stress_fail',
                'message' => 'forced failure',
                'group_id' => 0,
                'actor_id' => $userId,
                'ref_id' => $i,
                'ts' => time(),
            ],
        ]);
    }

    $maxRetryObserved = 0;
    for ($round = 0; $round < 7; $round++) {
        processOutboxOnce($conn, 400);
        $maxRetryObserved = max($maxRetryObserved, dbSingleInt($conn, 'SELECT COALESCE(MAX(retry_count),0) FROM outbox_events WHERE event_type = ?', 's', [$failType]));
        $forceStmt = $conn->prepare('UPDATE outbox_events SET next_attempt_at = NOW() WHERE event_type = ? AND status = "retryable"');
        $forceStmt->bind_param('s', $failType);
        $forceStmt->execute();
        $forceStmt->close();
    }

    $deadFail = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status = "dead"', 's', [$failType]);
    $retryableRemaining = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status = "retryable"', 's', [$failType]);
    $results['Failure'] = [
        'result' => ($deadFail > 0 && $maxRetryObserved >= 5) ? 'PASS' : 'FAIL',
        'notes' => 'Forced dispatch failures and observed retry/dead transitions',
        'metrics' => [
            'forced_fail_events' => $failCount,
            'max_retry_observed' => $maxRetryObserved,
            'dead_events' => $deadFail,
            'retryable_remaining' => $retryableRemaining,
        ],
    ];

    // Test E: Redis Failure
    putenv('REDIS_HOST=127.0.0.1');
    putenv('REDIS_PORT=6390');
    $redisClient = new RedisClient();
    $snapshot = $redisClient->getHealthSnapshot();
    $results['Redis Fail'] = [
        'result' => (($snapshot['connected'] ?? true) === false) ? 'PASS' : 'FAIL',
        'notes' => 'Simulated Redis outage by using unreachable test port',
        'metrics' => [
            'connected' => $snapshot['connected'] ?? null,
            'error' => $snapshot['error'] ?? null,
        ],
    ];

    // Test F: Queue Pressure Storm (backend pressure proxy)
    $pressureType = 'stress.pressure.' . $runId;
    for ($i = 0; $i < 1200; $i++) {
        outboxQueueEvent($conn, $pressureType, [
            'mode' => 'users',
            'user_ids' => [$userId],
            'event' => [
                'event_id' => $pressureType . '.' . $i,
                'type' => 'stress_pressure',
                'message' => 'pressure',
                'group_id' => 0,
                'actor_id' => $userId,
                'ref_id' => $i,
                'ts' => time(),
            ],
        ]);
    }
    $healthHigh = apiGetAuthed($baseUrl, 'system/health.php', [], $cookie);
    $essential = apiGetAuthed($baseUrl, 'expenses/categories.php', [], $cookie);
    $results['Queue Storm'] = [
        'result' => (($essential['json']['ok'] ?? false) && in_array(($healthHigh['json']['status'] ?? 'ok'), ['warning', 'degraded', 'critical'], true)) ? 'PASS' : 'FAIL',
        'notes' => 'Pressure raised and essential endpoint checked for responsiveness',
        'metrics' => [
            'health_status_under_pressure' => $healthHigh['json']['status'] ?? 'unknown',
            'queue_pressure' => $healthHigh['json']['queue_pressure'] ?? null,
            'essential_ok' => $essential['json']['ok'] ?? false,
        ],
    ];

    // Drain pressure queue for next scenario.
    for ($i = 0; $i < 40; $i++) {
        $step = processOutboxOnce($conn, 300);
        if (($step['processed'] ?? 0) === 0) {
            break;
        }
    }

    // Test G: Health Oscillation
    $h1 = apiGetAuthed($baseUrl, 'system/health.php', [], $cookie);
    $oscType = 'stress.osc.' . $runId;
    for ($i = 0; $i < 900; $i++) {
        outboxQueueEvent($conn, $oscType, [
            'mode' => 'users',
            'user_ids' => [$userId],
            'event' => [
                'event_id' => $oscType . '.' . $i,
                'type' => 'stress_osc',
                'message' => 'osc',
                'group_id' => 0,
                'actor_id' => $userId,
                'ref_id' => $i,
                'ts' => time(),
            ],
        ]);
    }
    $h2 = apiGetAuthed($baseUrl, 'system/health.php', [], $cookie);
    for ($i = 0; $i < 40; $i++) {
        $step = processOutboxOnce($conn, 300);
        if (($step['processed'] ?? 0) === 0) {
            break;
        }
    }
    $h3 = apiGetAuthed($baseUrl, 'system/health.php', [], $cookie);

    $s1 = $h1['json']['status'] ?? 'unknown';
    $s2 = $h2['json']['status'] ?? 'unknown';
    $s3 = $h3['json']['status'] ?? 'unknown';

    $results['Health Oscillation'] = [
        'result' => ($s1 !== $s2 && $s3 !== 'unknown') ? 'PASS' : 'FAIL',
        'notes' => 'Observed health status transition under induced pressure then drain',
        'metrics' => [
            'status_sequence' => [$s1, $s2, $s3],
        ],
    ];

    // Global metrics summary
    $totalRequests = ($results['Burst']['metrics']['requests'] ?? 0) + ($results['Duplicate']['metrics']['requests'] ?? 0);
    $httpSuccess = (($results['Burst']['metrics']['status_counts'][200] ?? 0) + ($results['Duplicate']['metrics']['status_counts'][200] ?? 0));
    $httpErrors = $totalRequests - $httpSuccess;
    $maxQueue = dbSingleInt($conn, 'SELECT COALESCE(MAX(queue_pressure),0) FROM (SELECT ((status="pending") + (status="retryable") + (status="processing")) AS queue_pressure FROM outbox_events) t');
    $deadTotal = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE status = "dead"');
    $stuckTotal = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE status = "processing"');

    $summary = [
        'run_id' => $runId,
        'timestamp' => date('c'),
        'results' => $results,
        'core_metrics' => [
            'total_requests' => $totalRequests,
            'success_rate' => $totalRequests > 0 ? round(($httpSuccess / $totalRequests) * 100, 2) : 0,
            'error_rate' => $totalRequests > 0 ? round(($httpErrors / $totalRequests) * 100, 2) : 0,
            'retry_count_max_observed' => $results['Failure']['metrics']['max_retry_observed'] ?? 0,
            'max_queue_size_snapshot' => $maxQueue,
            'outbox_drain_time_seconds' => $results['Outbox']['metrics']['drain_time_seconds'] ?? 0,
            'dead_events_count' => $deadTotal,
            'duplicate_write_count' => $results['Burst']['metrics']['duplicate_notes'] ?? -1,
            'stuck_processing_rows' => $stuckTotal,
        ],
    ];

    $reportPath = __DIR__ . '/phase5_report_' . $runId . '.json';
    file_put_contents($reportPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo "Phase 5 stress run complete\n";
    echo "Report: " . $reportPath . "\n\n";
    echo "Test        | Result | Notes\n";
    echo "------------|--------|-------------------------\n";
    foreach ($results as $name => $row) {
        echo str_pad($name, 11) . ' | ' . str_pad((string) $row['result'], 6) . ' | ' . ($row['notes'] ?? '') . "\n";
    }
    echo "\nCore Metrics:\n";
    foreach ($summary['core_metrics'] as $k => $v) {
        echo '- ' . $k . ': ' . (is_array($v) ? json_encode($v) : (string) $v) . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Stress harness failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
