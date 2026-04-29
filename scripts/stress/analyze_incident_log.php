<?php
$logPath = __DIR__ . '/../../logs/app.log';
if (!file_exists($logPath)) {
    fwrite(STDERR, "Log file not found: $logPath\n");
    exit(1);
}

$summary = [
    'total_lines' => 0,
    'json_lines' => 0,
    'api_rate_limit_exceeded' => ['count' => 0, 'first' => null, 'last' => null, 'max_retry_after' => 0],
    'login_rate_limit_exceeded' => ['count' => 0, 'first' => null, 'last' => null],
    'health_snapshot' => [
        'count' => 0,
        'status_counts' => ['ok' => 0, 'warning' => 0, 'degraded' => 0, 'critical' => 0],
        'first_critical' => null,
        'last_critical' => null,
        'first_ok' => null,
        'first_ok_with_redis' => null,
        'max_queue_pressure' => 0,
        'redis_disconnected_snapshots' => 0,
        'redis_connected_snapshots' => 0,
    ],
    'redis_fallback_connection_failed' => ['count' => 0, 'first' => null, 'last' => null],
    'db_connect_errors' => ['count' => 0, 'first' => null, 'last' => null],
    'outbox_skip_locked_errors' => ['count' => 0, 'first' => null, 'last' => null],
];

$fh = fopen($logPath, 'r');
if ($fh === false) {
    fwrite(STDERR, "Failed to open log file\n");
    exit(1);
}

while (($line = fgets($fh)) !== false) {
    $summary['total_lines']++;
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $entry = json_decode($line, true);
    if (!is_array($entry)) {
        continue;
    }

    $summary['json_lines']++;
    $time = (string) ($entry['time'] ?? '');
    $message = (string) ($entry['message'] ?? '');
    $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];

    if ($message === 'API rate limit exceeded') {
        $summary['api_rate_limit_exceeded']['count']++;
        if ($summary['api_rate_limit_exceeded']['first'] === null) {
            $summary['api_rate_limit_exceeded']['first'] = $time;
        }
        $summary['api_rate_limit_exceeded']['last'] = $time;
        $retryAfter = (int) ($context['retry_after'] ?? 0);
        if ($retryAfter > $summary['api_rate_limit_exceeded']['max_retry_after']) {
            $summary['api_rate_limit_exceeded']['max_retry_after'] = $retryAfter;
        }
    }

    if ($message === 'Login rate limit exceeded') {
        $summary['login_rate_limit_exceeded']['count']++;
        if ($summary['login_rate_limit_exceeded']['first'] === null) {
            $summary['login_rate_limit_exceeded']['first'] = $time;
        }
        $summary['login_rate_limit_exceeded']['last'] = $time;
    }

    if ($message === 'System health snapshot') {
        $summary['health_snapshot']['count']++;
        $status = (string) ($context['status'] ?? '');
        if (!isset($summary['health_snapshot']['status_counts'][$status])) {
            $summary['health_snapshot']['status_counts'][$status] = 0;
        }
        $summary['health_snapshot']['status_counts'][$status]++;

        $qp = (int) ($context['queue_pressure'] ?? 0);
        if ($qp > $summary['health_snapshot']['max_queue_pressure']) {
            $summary['health_snapshot']['max_queue_pressure'] = $qp;
        }

        $redisConnected = (bool) ($context['redis_connected'] ?? false);
        if ($redisConnected) {
            $summary['health_snapshot']['redis_connected_snapshots']++;
        } else {
            $summary['health_snapshot']['redis_disconnected_snapshots']++;
        }

        if ($status === 'critical') {
            if ($summary['health_snapshot']['first_critical'] === null) {
                $summary['health_snapshot']['first_critical'] = $time;
            }
            $summary['health_snapshot']['last_critical'] = $time;
        }

        if ($status === 'ok' && $summary['health_snapshot']['first_ok'] === null) {
            $summary['health_snapshot']['first_ok'] = $time;
        }
        if ($status === 'ok' && $redisConnected && $summary['health_snapshot']['first_ok_with_redis'] === null) {
            $summary['health_snapshot']['first_ok_with_redis'] = $time;
        }
    }

    if (strpos($message, '[REDIS_FALLBACK]') !== false) {
        $reason = (string) ($context['reason'] ?? '');
        $err = (string) ($context['error'] ?? '');
        if (strpos($message, 'connection_failed') !== false || stripos($err, 'connect') !== false || $reason === 'connection_failed') {
            $summary['redis_fallback_connection_failed']['count']++;
            if ($summary['redis_fallback_connection_failed']['first'] === null) {
                $summary['redis_fallback_connection_failed']['first'] = $time;
            }
            $summary['redis_fallback_connection_failed']['last'] = $time;
        }
    }

    if (stripos($message, 'Database connection failed') !== false || stripos($message, 'mysqli') !== false || stripos($message, 'SQLSTATE') !== false) {
        $summary['db_connect_errors']['count']++;
        if ($summary['db_connect_errors']['first'] === null) {
            $summary['db_connect_errors']['first'] = $time;
        }
        $summary['db_connect_errors']['last'] = $time;
    }

    if ($message === 'Outbox claim with lock failed' && isset($context['error']) && strpos((string) $context['error'], 'SKIP LOCKED') !== false) {
        $summary['outbox_skip_locked_errors']['count']++;
        if ($summary['outbox_skip_locked_errors']['first'] === null) {
            $summary['outbox_skip_locked_errors']['first'] = $time;
        }
        $summary['outbox_skip_locked_errors']['last'] = $time;
    }
}

fclose($fh);

echo json_encode($summary, JSON_PRETTY_PRINT), PHP_EOL;
