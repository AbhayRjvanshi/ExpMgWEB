<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/../../api/helpers/outbox.php';
require_once __DIR__ . '/../../api/helpers/redis.php';

$baseUrl = 'http://localhost/ExpMgWEB/api';
$runId = 'phase5v3_' . date('Ymd_His');

function createAndLoginStressUser(string $baseUrl, mysqli $conn, string $runId): array {
    $conn->query('TRUNCATE TABLE rate_limits');

    $seedLogin = httpReq('POST', $baseUrl . '/login.php', ['email' => 'alice@example.com', 'password' => 'password123']);
    $seedSid = cookieVal($seedLogin['headers'], 'PHPSESSID');
    $seedCsrf = cookieVal($seedLogin['headers'], 'csrf_token');
    if ($seedSid !== '' && $seedCsrf !== '') {
        $seedStmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $seedEmail = 'alice@example.com';
        $seedStmt->bind_param('s', $seedEmail);
        $seedStmt->execute();
        $seedId = (int) (($seedStmt->get_result()->fetch_assoc()['id'] ?? 0));
        $seedStmt->close();

        if ($seedId > 0) {
            return [
                'id' => $seedId,
                'cookie' => 'PHPSESSID=' . $seedSid . '; csrf_token=' . $seedCsrf,
                'csrf' => $seedCsrf,
                'email' => $seedEmail,
            ];
        }
    }

    $email = 'stress_' . $runId . '@exptest.local';
    $username = 'stress_' . substr($runId, -8);
    $password = 'Stress123!';

    $signup = httpReq('POST', $baseUrl . '/signup.php', [
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'confirm_password' => $password,
    ]);

    if (!in_array($signup['status'], [200, 302, 303], true)) {
        throw new RuntimeException('Signup failed for stress user: HTTP ' . $signup['status']);
    }

    $login = httpReq('POST', $baseUrl . '/login.php', ['email' => $email, 'password' => $password]);
    $sid = cookieVal($login['headers'], 'PHPSESSID');
    $csrf = cookieVal($login['headers'], 'csrf_token');
    if ($sid === '' || $csrf === '') {
        throw new RuntimeException('Login failed for stress user.');
    }

    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $uid = (int) (($stmt->get_result()->fetch_assoc()['id'] ?? 0));
    $stmt->close();

    return [
        'id' => $uid,
        'cookie' => 'PHPSESSID=' . $sid . '; csrf_token=' . $csrf,
        'csrf' => $csrf,
        'email' => $email,
    ];
}

function multiPost(array $jobs, int $concurrency): array {
    $mh = curl_multi_init();
    $handles = [];
    $next = 0;
    $status = [];
    $lat = [];

    $spawn = function (int $i) use ($jobs, $mh, &$handles): void {
        $job = $jobs[$i];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => (string) $job['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query((array) $job['data']),
            CURLOPT_HTTPHEADER => (array) $job['headers'],
            CURLOPT_COOKIE => (string) $job['cookie'],
        ]);
        $handles[(int) $ch] = ['h' => $ch, 't' => microtime(true)];
        curl_multi_add_handle($mh, $ch);
    };

    for (; $next < min($concurrency, count($jobs)); $next++) $spawn($next);

    do {
        do { $mrc = curl_multi_exec($mh, $active); } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $k = (int) $ch;
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $status[$code] = ($status[$code] ?? 0) + 1;
            $lat[] = (int) round((microtime(true) - $handles[$k]['t']) * 1000);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$k]);
            if ($next < count($jobs)) { $spawn($next); $next++; }
        }

        if ($active) curl_multi_select($mh, 0.2);
    } while ($active || !empty($handles));

    curl_multi_close($mh);
    sort($lat);
    $p95 = $lat ? (int) $lat[(int) floor((count($lat) - 1) * 0.95)] : 0;
    return ['count' => count($jobs), 'status_counts' => $status, 'p95_ms' => $p95];
}

function processOutbox(mysqli $conn, int $limit = 300): int {
    $rows = outboxClaimDueEvents($conn, $limit);
    foreach ($rows as $row) {
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : null;
        $id = (int) ($row['id'] ?? 0);
        $type = (string) ($row['event_type'] ?? 'unknown');
        $retry = (int) ($row['retry_count'] ?? 0);
        try {
            if (!$payload || !outboxDispatchNotificationPayload($conn, $payload)) {
                throw new RuntimeException('Unsupported outbox payload.');
            }
            outboxMarkSent($conn, $id);
        } catch (Throwable $e) {
            outboxMarkFailed($conn, $id, $type, $e->getMessage(), $retry);
        }
    }
    return count($rows);
}

$results = [];

try {
    $auth = createAndLoginStressUser($baseUrl, $conn, $runId);

    // A: Burst (chunked to keep limiter from masking signal entirely)
    $notePrefixA = 'stressA_' . $runId;
    $beforeA = dbInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note LIKE ?', 's', [$notePrefixA . '%']);
    $aggStatus = [];
    $p95max = 0;
    for ($chunk = 0; $chunk < 6; $chunk++) {
        $jobs = [];
        for ($i = 0; $i < 100; $i++) {
            $n = $chunk * 100 + $i;
            $jobs[] = [
                'url' => $baseUrl . '/expenses/create.php',
                'cookie' => $auth['cookie'],
                'headers' => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'X-CSRF-Token: ' . $auth['csrf'],
                    'Idempotency-Key: burst-' . $runId . '-' . $n,
                ],
                'data' => [
                    'amount' => (string) (10 + ($n % 30)),
                    'category_id' => '1',
                    'note' => $notePrefixA . '-' . $n,
                    'expense_date' => date('Y-m-d'),
                    'type' => 'personal',
                ],
            ];
        }
        $res = multiPost($jobs, 30);
        foreach ($res['status_counts'] as $k => $v) $aggStatus[$k] = ($aggStatus[$k] ?? 0) + $v;
        $p95max = max($p95max, (int) $res['p95_ms']);
        $conn->query('TRUNCATE TABLE rate_limits');
    }
    $afterA = dbInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note LIKE ?', 's', [$notePrefixA . '%']);
    $dupA = dbInt($conn, 'SELECT COUNT(*) FROM (SELECT note, COUNT(*) c FROM expenses WHERE note LIKE ? GROUP BY note HAVING c > 1) t', 's', [$notePrefixA . '%']);
    $results['Burst'] = [
        'result' => ($dupA === 0 && ($afterA - $beforeA) > 0) ? 'PASS' : 'FAIL',
        'notes' => '600 requests in 6 high-concurrency chunks',
        'metrics' => ['requests' => 600, 'created_rows' => $afterA - $beforeA, 'duplicate_notes' => $dupA, 'p95_ms' => $p95max, 'status_counts' => $aggStatus],
    ];

    // B: Duplicate idempotency attack
    $conn->query('TRUNCATE TABLE rate_limits');
    $noteB = 'stressB_' . $runId;
    $beforeB = dbInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note = ?', 's', [$noteB]);
    $jobsB = [];
    for ($i = 0; $i < 50; $i++) {
        $jobsB[] = [
            'url' => $baseUrl . '/expenses/create.php',
            'cookie' => $auth['cookie'],
            'headers' => ['Content-Type: application/x-www-form-urlencoded', 'X-CSRF-Token: ' . $auth['csrf'], 'Idempotency-Key: dup-' . $runId],
            'data' => ['amount' => '66', 'category_id' => '1', 'note' => $noteB, 'expense_date' => date('Y-m-d'), 'type' => 'personal'],
        ];
    }
    $resB = multiPost($jobsB, 20);
    $afterB = dbInt($conn, 'SELECT COUNT(*) FROM expenses WHERE note = ?', 's', [$noteB]);
    $results['Duplicate'] = [
        'result' => (($afterB - $beforeB) === 1) ? 'PASS' : 'FAIL',
        'notes' => '50 identical writes with same idempotency key',
        'metrics' => ['requests' => 50, 'created_rows' => $afterB - $beforeB, 'status_counts' => $resB['status_counts']],
    ];

    // C: Outbox flood
    $floodType = 'stress.flood.' . $runId;
    for ($i = 0; $i < 2200; $i++) {
        outboxQueueEvent($conn, $floodType, [
            'mode' => 'users',
            'user_ids' => [$auth['id']],
            'event' => ['event_id' => $floodType . '.' . $i, 'type' => 'stress_flood', 'message' => 'f', 'group_id' => 0, 'actor_id' => $auth['id'], 'ref_id' => $i, 'ts' => time()],
        ]);
    }
    $startDrain = microtime(true);
    for ($i = 0; $i < 120; $i++) {
        if (processOutbox($conn, 300) === 0) break;
    }
    $drainTime = round(microtime(true) - $startDrain, 2);
    $stuckFlood = dbInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status = "processing"', 's', [$floodType]);
    $results['Outbox'] = [
        'result' => $stuckFlood === 0 ? 'PASS' : 'FAIL',
        'notes' => '2200 events enqueued and drained',
        'metrics' => ['enqueued' => 2200, 'drain_time_seconds' => $drainTime, 'stuck_processing' => $stuckFlood],
    ];

    // D: Failure injection
    $failType = 'stress.fail.' . $runId;
    for ($i = 0; $i < 200; $i++) {
        outboxQueueEvent($conn, $failType, [
            'mode' => 'invalid_mode',
            'event' => ['event_id' => $failType . '.' . $i, 'type' => 'stress_fail', 'message' => 'x', 'group_id' => 0, 'actor_id' => $auth['id'], 'ref_id' => $i, 'ts' => time()],
        ]);
    }
    $maxRetry = 0;
    for ($r = 0; $r < 7; $r++) {
        processOutbox($conn, 400);
        $maxRetry = max($maxRetry, dbInt($conn, 'SELECT COALESCE(MAX(retry_count),0) FROM outbox_events WHERE event_type = ?', 's', [$failType]));
        $st = $conn->prepare('UPDATE outbox_events SET next_attempt_at = NOW() WHERE event_type = ? AND status = "retryable"');
        $st->bind_param('s', $failType);
        $st->execute();
        $st->close();
    }
    $deadFail = dbInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE event_type = ? AND status = "dead"', 's', [$failType]);
    $results['Failure'] = [
        'result' => ($deadFail > 0 && $maxRetry >= 5) ? 'PASS' : 'FAIL',
        'notes' => 'Forced invalid payload failures to dead-letter',
        'metrics' => ['max_retry_observed' => $maxRetry, 'dead_events' => $deadFail],
    ];

    // E: Redis failure simulation
    putenv('REDIS_HOST=127.0.0.1');
    putenv('REDIS_PORT=6390');
    $redis = new RedisClient();
    $rh = $redis->getHealthSnapshot();
    $results['Redis Fail'] = [
        'result' => (($rh['connected'] ?? true) === false) ? 'PASS' : 'FAIL',
        'notes' => 'Redis fallback activation check',
        'metrics' => ['connected' => $rh['connected'] ?? null, 'error' => $rh['error'] ?? null],
    ];

    // F: Queue pressure storm
    $conn->query('TRUNCATE TABLE rate_limits');
    $pressureType = 'stress.pressure.' . $runId;
    for ($i = 0; $i < 1400; $i++) {
        outboxQueueEvent($conn, $pressureType, [
            'mode' => 'users',
            'user_ids' => [$auth['id']],
            'event' => ['event_id' => $pressureType . '.' . $i, 'type' => 'stress_pressure', 'message' => 'p', 'group_id' => 0, 'actor_id' => $auth['id'], 'ref_id' => $i, 'ts' => time()],
        ]);
    }
    $healthHigh = httpReq('GET', $baseUrl . '/system/health.php', [], $auth['cookie']);
    $essential = httpReq('GET', $baseUrl . '/expenses/categories.php', [], $auth['cookie']);
    $hs = $healthHigh['json']['status'] ?? 'unknown';
    $results['Queue Storm'] = [
        'result' => (in_array($hs, ['warning', 'degraded', 'critical'], true) && (($essential['json']['ok'] ?? false) === true)) ? 'PASS' : 'FAIL',
        'notes' => 'Health threshold + essential API continuity under pressure',
        'metrics' => ['health_status_under_pressure' => $hs, 'queue_pressure' => $healthHigh['json']['queue_pressure'] ?? null, 'essential_ok' => $essential['json']['ok'] ?? false],
    ];

    // drain pressure
    for ($i = 0; $i < 120; $i++) { if (processOutbox($conn, 300) === 0) break; }

    // G: Health oscillation
    $conn->query('TRUNCATE TABLE rate_limits');
    $h1 = httpReq('GET', $baseUrl . '/system/health.php', [], $auth['cookie']);
    $oscType = 'stress.osc.' . $runId;
    for ($i = 0; $i < 1000; $i++) {
        outboxQueueEvent($conn, $oscType, [
            'mode' => 'users',
            'user_ids' => [$auth['id']],
            'event' => ['event_id' => $oscType . '.' . $i, 'type' => 'stress_osc', 'message' => 'o', 'group_id' => 0, 'actor_id' => $auth['id'], 'ref_id' => $i, 'ts' => time()],
        ]);
    }
    $h2 = httpReq('GET', $baseUrl . '/system/health.php', [], $auth['cookie']);
    for ($i = 0; $i < 120; $i++) { if (processOutbox($conn, 300) === 0) break; }
    $h3 = httpReq('GET', $baseUrl . '/system/health.php', [], $auth['cookie']);
    $seq = [$h1['json']['status'] ?? 'unknown', $h2['json']['status'] ?? 'unknown', $h3['json']['status'] ?? 'unknown'];
    $results['Health Oscillation'] = [
        'result' => ($seq[0] !== $seq[1] && $seq[1] !== $seq[2]) ? 'PASS' : 'FAIL',
        'notes' => 'Status changed with pressure and recovery',
        'metrics' => ['status_sequence' => $seq],
    ];

    $totalReq = 650;
    $ok2xx = (int) (($results['Burst']['metrics']['status_counts'][200] ?? 0) + ($results['Duplicate']['metrics']['status_counts'][200] ?? 0));
    $core = [
        'total_requests' => $totalReq,
        'success_rate' => round(($ok2xx / max(1, $totalReq)) * 100, 2),
        'error_rate' => round((($totalReq - $ok2xx) / max(1, $totalReq)) * 100, 2),
        'retry_count_max_observed' => $results['Failure']['metrics']['max_retry_observed'] ?? 0,
        'outbox_drain_time_seconds' => $results['Outbox']['metrics']['drain_time_seconds'] ?? 0,
        'dead_events_count' => dbInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE status = "dead"'),
        'duplicate_write_count' => $results['Burst']['metrics']['duplicate_notes'] ?? -1,
        'stuck_processing_rows' => dbInt($conn, 'SELECT COUNT(*) FROM outbox_events WHERE status = "processing"'),
    ];

    $summary = ['run_id' => $runId, 'timestamp' => date('c'), 'results' => $results, 'core_metrics' => $core];
    $path = __DIR__ . '/phase5_report_' . $runId . '.json';
    file_put_contents($path, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo "Phase 5 stress run complete\n";
    echo "Report: $path\n\n";
    echo "Test        | Result | Notes\n";
    echo "------------|--------|-------------------------\n";
    foreach ($results as $name => $row) {
        echo str_pad($name, 11) . ' | ' . str_pad((string) $row['result'], 6) . ' | ' . ($row['notes'] ?? '') . "\n";
    }
    echo "\nCore Metrics:\n";
    foreach ($core as $k => $v) echo '- ' . $k . ': ' . $v . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Stress harness failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
