<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/../../api/helpers/outbox.php';
require_once __DIR__ . '/../../api/helpers/redis.php';

$baseUrl = 'http://localhost/ExpMgWEB/api';
$runId = 'phase5v2_' . date('Ymd_His');

function ensureStressUsers(string $baseUrl, mysqli $conn, int $count, string $runId): array {
    $users = [];

    for ($i = 0; $i < $count; $i++) {
        $email = sprintf('stress_%s_%02d@exptest.local', $runId, $i);
        $password = 'Stress123!';
        $username = sprintf('stress_%s_%02d', substr($runId, -10), $i);

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
            continue;
        }

        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $uid = (int) (($stmt->get_result()->fetch_assoc()['id'] ?? 0));
        $stmt->close();

        $users[] = [
            'id' => $uid,
            'cookie' => 'PHPSESSID=' . $sid . '; csrf_token=' . $csrf,
            'csrf' => $csrf,
            'email' => $email,
        ];
    }

    return $users;
}

function sendConcurrentCustom(array $jobs, int $concurrency): array {
    $mh = curl_multi_init();
    $handles = [];
    $next = 0;
    $statusCounts = [];
    $latencies = [];
    $errors = 0;

    $spawn = function (int $idx) use (&$handles, $mh, $jobs): void {
        $job = $jobs[$idx];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => (string) $job['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query((array) ($job['data'] ?? [])),
            CURLOPT_HTTPHEADER => (array) ($job['headers'] ?? []),
            CURLOPT_COOKIE => (string) ($job['cookie'] ?? ''),
        ]);
        $handles[(int) $ch] = ['handle' => $ch, 'started' => microtime(true)];
        curl_multi_add_handle($mh, $ch);
    };

    for (; $next < min($concurrency, count($jobs)); $next++) {
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

            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;
            if ($info['result'] !== CURLE_OK) {
                $errors++;
            }
            if ($meta) {
                $latencies[] = (int) round((microtime(true) - $meta['started']) * 1000);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$key]);

            if ($next < count($jobs)) {
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
        $p95 = (int) $latencies[(int) floor((count($latencies) - 1) * 0.95)];
    }

    return ['status_counts' => $statusCounts, 'errors' => $errors, 'p95_ms' => $p95, 'count' => count($jobs)];
}

function processOutboxOnce(mysqli $conn, int $limit = 250): array {
    $events = outboxClaimDueEvents($conn, $limit);
    $sent = 0;
    $failed = 0;

    foreach ($events as $event) {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : null;
        $outboxId = (int) ($event['id'] ?? 0);
        $eventType = (string) ($event['event_type'] ?? 'unknown');
        $retryCount = (int) ($event['retry_count'] ?? 0);
        $eventId = outboxEventIdFromPayload($payload ?? []) ?? (string) $outboxId;
        $start = microtime(true);

        try {
            if (!$payload || !outboxDispatchNotificationPayload($conn, $payload)) {
                throw new RuntimeException('Unsupported outbox payload.');
            }
            outboxMarkSent($conn, $outboxId);
            outboxLogSuccess($eventId, $eventType, (int) round((microtime(true) - $start) * 1000), $retryCount);
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
    $users = ensureStressUsers($baseUrl, $conn, 8, $runId);
    if (count($users) < 3) {
        throw new RuntimeException('Unable to prepare enough authenticated stress users.');
    }

    // Reset limiter window before each scenario for clean signal.
    $conn->query('TRUNCATE TABLE rate_limits');

    // A: Burst storm
    $notePrefixA = 'stressA_' . $runId;
    $beforeA = dbSingleInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note LIKE ?', 's', [$notePrefixA . '%']);
    $jobsA = [];
    for ($i = 0; $i < 480; $i++) {
        $u = $users[$i % count($users)];
        $jobsA[] = [
            'url' => $baseUrl . '/expenses/create.php',
            'cookie' => $u['cookie'],
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-CSRF-Token: ' . $u['csrf'],
                'Idempotency-Key: burst-' . $runId . '-' . $i,
            ],
            'data' => [
                'amount' => (string) (10 + ($i % 40)),
                'category_id' => '1',
                'note' => $notePrefixA . '-' . $i,
                'expense_date' => date('Y-m-d'),
                'type' => 'personal',
            ],
        ];
    }
    $a = sendConcurrentCustom($jobsA, 32);
    $afterA = dbSingleInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note LIKE ?', 's', [$notePrefixA . '%']);
    $dupA = dbSingleInt($conn, 'SELECT COUNT(*) FROM (SELECT note, COUNT(*) c FROM expenses WHERE note LIKE ? GROUP BY note HAVING c > 1) t', 's', [$notePrefixA . '%']);
    $results['Burst'] = [
        'result' => (($dupA === 0) && (($a['status_counts'][200] ?? 0) > 0)) ? 'PASS' : 'FAIL',
        'notes' => 'Multi-user burst write storm',
        'metrics' => [
            'requests' => $a['count'],
            'created_rows' => $afterA - $beforeA,
            'duplicate_notes' => $dupA,
            'p95_ms' => $a['p95_ms'],
            'status_counts' => $a['status_counts'],
        ],
    ];

    $conn->query('TRUNCATE TABLE rate_limits');

    // B: Duplicate intent attack
    $u = $users[0];
    $noteB = 'stressB_' . $runId;
    $beforeB = dbSingleInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note = ?', 's', [$noteB]);
    $jobsB = [];
    for ($i = 0; $i < 40; $i++) {
        $jobsB[] = [
            'url' => $baseUrl . '/expenses/create.php',
            'cookie' => $u['cookie'],
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-CSRF-Token: ' . $u['csrf'],
                'Idempotency-Key: dup-' . $runId,
            ],
            'data' => [
                'amount' => '77',
                'category_id' => '1',
                'note' => $noteB,
                'expense_date' => date('Y-m-d'),
                'type' => 'personal',
            ],
        ];
    }
    $b = sendConcurrentCustom($jobsB, 20);
    $afterB = dbSingleInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note = ?', 's', [$noteB]);
    $results['Duplicate'] = [
        'result' => (($afterB - $beforeB) === 1) ? 'PASS' : 'FAIL',
        'notes' => 'Same payload + same idempotency key repeated',
        'metrics' => [
            'requests' => $b['count'],
            'created_rows' => $afterB - $beforeB,
            'status_counts' => $b['status_counts'],
        ],
    ];

    // C: Outbox flood
    $floodType = 'stress.flood.' . $runId;
    $floodCount = 2000;
    for ($i = 0; $i < $floodCount; $i++) {
        outboxQueueEvent($conn, $floodType, [
            'mode' => 'users',
            'user_ids' => [$users[$i % count($users)]['id']],
            'event' => [
                'event_id' => $floodType . '.' . $i,
                'type' => 'stress_flood',
                'message' => 'flood',
                'group_id' => 0,
                'actor_id' => $users[0]['id'],
                'ref_id' => $i,
                'ts' => time(),
            ],
        ]);
    }
    $drainStart = microtime(true);
    $rounds = 0;
    while ($rounds < 120) {
        $rounds++;
        processOutboxOnce($conn, 300);
        $pending = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status IN ("pending","retryable","processing")', 's', [$floodType]);
        if ($pending === 0) {
            break;
        }
    }
    $drainTime = round(microtime(true) - $drainStart, 2);
    $stuckFlood = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status = "processing"', 's', [$floodType]);
    $results['Outbox'] = [
        'result' => ($stuckFlood === 0) ? 'PASS' : 'FAIL',
        'notes' => 'Flood and drain with retry-capable worker loop',
        'metrics' => [
            'enqueued' => $floodCount,
            'drain_time_seconds' => $drainTime,
            'stuck_processing' => $stuckFlood,
        ],
    ];

    // D: Failure injection
    $failType = 'stress.fail.' . $runId;
    for ($i = 0; $i < 150; $i++) {
        outboxQueueEvent($conn, $failType, [
            'mode' => 'invalid_mode',
            'event' => [
                'event_id' => $failType . '.' . $i,
                'type' => 'stress_fail',
                'message' => 'forced fail',
                'group_id' => 0,
                'actor_id' => $users[0]['id'],
                'ref_id' => $i,
                'ts' => time(),
            ],
        ]);
    }
    $maxRetry = 0;
    for ($r = 0; $r < 7; $r++) {
        processOutboxOnce($conn, 300);
        $maxRetry = max($maxRetry, dbSingleInt($conn, 'SELECT COALESCE(MAX(retry_count),0) FROM outbox_events WHERE event_type = ?', 's', [$failType]));
        $stmt = $conn->prepare('UPDATE outbox_events SET next_attempt_at = NOW() WHERE event_type = ? AND status = "retryable"');
        $stmt->bind_param('s', $failType);
        $stmt->execute();
        $stmt->close();
    }
    $deadFail = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status = "dead"', 's', [$failType]);
    $results['Failure'] = [
        'result' => ($deadFail > 0 && $maxRetry >= 5) ? 'PASS' : 'FAIL',
        'notes' => 'Forced failure path with accelerated retry windows',
        'metrics' => [
            'max_retry_observed' => $maxRetry,
            'dead_events' => $deadFail,
        ],
    ];

    // E: Redis failure simulation
    putenv('REDIS_HOST=127.0.0.1');
    putenv('REDIS_PORT=6390');
    $redis = new RedisClient();
    $rh = $redis->getHealthSnapshot();
    $results['Redis Fail'] = [
        'result' => (($rh['connected'] ?? true) === false) ? 'PASS' : 'FAIL',
        'notes' => 'Unreachable Redis endpoint fallback check',
        'metrics' => [
            'connected' => $rh['connected'] ?? null,
            'error' => $rh['error'] ?? null,
        ],
    ];

    $conn->query('TRUNCATE TABLE rate_limits');

    // F: Queue pressure storm (health + essential request continuity)
    $pressureType = 'stress.pressure.' . $runId;
    for ($i = 0; $i < 1200; $i++) {
        outboxQueueEvent($conn, $pressureType, [
            'mode' => 'users',
            'user_ids' => [$users[$i % count($users)]['id']],
            'event' => [
                'event_id' => $pressureType . '.' . $i,
                'type' => 'stress_pressure',
                'message' => 'pressure',
                'group_id' => 0,
                'actor_id' => $users[0]['id'],
                'ref_id' => $i,
                'ts' => time(),
            ],
        ]);
    }

    $healthHigh = httpRequest('GET', $baseUrl . '/system/health.php', [], $users[0]['cookie']);
    $essential = httpRequest('GET', $baseUrl . '/expenses/categories.php', [], $users[0]['cookie']);
    $hs = $healthHigh['json']['status'] ?? 'unknown';
    $results['Queue Storm'] = [
        'result' => (in_array($hs, ['warning', 'degraded', 'critical'], true) && (($essential['json']['ok'] ?? false) === true)) ? 'PASS' : 'FAIL',
        'notes' => 'Pressure raised while essential endpoint remained reachable',
        'metrics' => [
            'health_status_under_pressure' => $hs,
            'queue_pressure' => $healthHigh['json']['queue_pressure'] ?? null,
            'essential_ok' => $essential['json']['ok'] ?? false,
            'health_http' => $healthHigh['status'],
            'essential_http' => $essential['status'],
        ],
    ];

    // Drain pressure queue.
    for ($i = 0; $i < 100; $i++) {
        $step = processOutboxOnce($conn, 300);
        if (($step['processed'] ?? 0) === 0) {
            break;
        }
    }

    // G: Health oscillation
    $h1 = httpRequest('GET', $baseUrl . '/system/health.php', [], $users[0]['cookie']);
    $oscType = 'stress.osc.' . $runId;
    for ($i = 0; $i < 800; $i++) {
        outboxQueueEvent($conn, $oscType, [
            'mode' => 'users',
            'user_ids' => [$users[$i % count($users)]['id']],
            'event' => [
                'event_id' => $oscType . '.' . $i,
                'type' => 'stress_osc',
                'message' => 'osc',
                'group_id' => 0,
                'actor_id' => $users[0]['id'],
                'ref_id' => $i,
                'ts' => time(),
            ],
        ]);
    }
    $h2 = httpRequest('GET', $baseUrl . '/system/health.php', [], $users[0]['cookie']);
    for ($i = 0; $i < 100; $i++) {
        $step = processOutboxOnce($conn, 300);
        if (($step['processed'] ?? 0) === 0) {
            break;
        }
    }
    $h3 = httpRequest('GET', $baseUrl . '/system/health.php', [], $users[0]['cookie']);

    $seq = [
        $h1['json']['status'] ?? 'unknown',
        $h2['json']['status'] ?? 'unknown',
        $h3['json']['status'] ?? 'unknown',
    ];
    $results['Health Oscillation'] = [
        'result' => ($seq[0] !== $seq[1] && $seq[1] !== $seq[2]) ? 'PASS' : 'FAIL',
        'notes' => 'Observed status transition with pressure and recovery',
        'metrics' => [
            'status_sequence' => $seq,
            'http_sequence' => [$h1['status'], $h2['status'], $h3['status']],
        ],
    ];

    $totalRequests = ($results['Burst']['metrics']['requests'] ?? 0) + ($results['Duplicate']['metrics']['requests'] ?? 0);
    $success2xx = (int) (($results['Burst']['metrics']['status_counts'][200] ?? 0) + ($results['Duplicate']['metrics']['status_counts'][200] ?? 0));
    $error2xx = $totalRequests - $success2xx;
    $deadAll = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE status = "dead"');
    $stuckAll = dbSingleInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE status = "processing"');

    $summary = [
        'run_id' => $runId,
        'timestamp' => date('c'),
        'results' => $results,
        'core_metrics' => [
            'total_requests' => $totalRequests,
            'success_rate' => $totalRequests > 0 ? round(($success2xx / $totalRequests) * 100, 2) : 0,
            'error_rate' => $totalRequests > 0 ? round(($error2xx / $totalRequests) * 100, 2) : 0,
            'retry_count_max_observed' => $results['Failure']['metrics']['max_retry_observed'] ?? 0,
            'outbox_drain_time_seconds' => $results['Outbox']['metrics']['drain_time_seconds'] ?? 0,
            'dead_events_count' => $deadAll,
            'duplicate_write_count' => $results['Burst']['metrics']['duplicate_notes'] ?? -1,
            'stuck_processing_rows' => $stuckAll,
        ],
    ];

    $report = __DIR__ . '/phase5_report_' . $runId . '.json';
    file_put_contents($report, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo "Phase 5 stress run complete\n";
    echo "Report: $report\n\n";
    echo "Test        | Result | Notes\n";
    echo "------------|--------|-------------------------\n";
    foreach ($results as $name => $row) {
        echo str_pad($name, 11) . ' | ' . str_pad((string) $row['result'], 6) . ' | ' . ($row['notes'] ?? '') . "\n";
    }

    echo "\nCore Metrics:\n";
    foreach ($summary['core_metrics'] as $k => $v) {
        echo '- ' . $k . ': ' . (is_array($v) ? json_encode($v) : $v) . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Stress harness failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
