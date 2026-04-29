<?php
/**
 * config/idempotency.php — Lightweight file-backed idempotency cache.
 *
 * Stores response snapshots by hashed key for write requests to prevent
 * duplicate execution during retries/double-submits.
 */

define('IDEMPOTENCY_DIR', __DIR__ . '/../data/idempotency');
define('IDEMPOTENCY_TTL_SECONDS', 900); // 15 minutes
define('IDEMPOTENCY_LOCK_TTL_SECONDS', 30);

require_once __DIR__ . '/../api/helpers/redis.php';

function idempotencyBackend(): string {
    $backend = strtolower((string) env('IDEMPOTENCY_BACKEND', 'file'));
    return in_array($backend, ['file', 'redis'], true) ? $backend : 'file';
}

function idempotencyRedis() {
    if (idempotencyBackend() !== 'redis') {
        return null;
    }

    $client = getRedis();
    if (!$client->isConnected()) {
        return null;
    }

    return $client->getRedis();
}

function idempotencyPayloadHash(): string {
    $payload = $_POST;
    ksort($payload);
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function idempotencyCacheKey(string $digest): string {
    return 'idempotency:response:' . $digest;
}

function idempotencyLockKey(string $digest): string {
    return 'idempotency:lock:' . $digest;
}

function idempotencyConflictResponse(array $ctx): array {
    return [
        'enabled' => true,
        'replay' => true,
        'status' => 409,
        'body' => [
            'ok' => false,
            'error' => 'Idempotency key reuse with different payload.',
        ],
        'cache_path' => $ctx['cache_path'] ?? null,
        'lock_handle' => $ctx['lock_handle'] ?? null,
        'redis' => $ctx['redis'] ?? null,
        'lock_key' => $ctx['lock_key'] ?? null,
        'lock_token' => $ctx['lock_token'] ?? null,
    ];
}

function idempotencyReleaseRedisLock($redis, ?string $lockKey, ?string $lockToken): void {
    if (!(is_object($redis) && method_exists($redis, 'eval')) || !$lockKey || !$lockToken) {
        return;
    }

    $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
    try {
        $redis->eval($script, [$lockKey, $lockToken], 1);
    } catch (Throwable $e) {
        $redis->del($lockKey);
    }
}

function idempotencyBuildReplay(array $cached, string $payloadHash, array $ctx): array {
    if (($cached['payload_hash'] ?? '') !== $payloadHash) {
        return idempotencyConflictResponse($ctx);
    }

    return [
        'enabled' => true,
        'replay' => true,
        'status' => (int) $cached['status'],
        'body' => is_array($cached['body']) ? $cached['body'] : ['ok' => false, 'error' => 'Invalid idempotency cache body.'],
        'cache_path' => $ctx['cache_path'] ?? null,
        'lock_handle' => $ctx['lock_handle'] ?? null,
        'redis' => $ctx['redis'] ?? null,
        'lock_key' => $ctx['lock_key'] ?? null,
        'lock_token' => $ctx['lock_token'] ?? null,
    ];
}

/**
 * Ensure idempotency data directory exists.
 */
function idempotencyEnsureDir(): void {
    if (!is_dir(IDEMPOTENCY_DIR)) {
        mkdir(IDEMPOTENCY_DIR, 0700, true);
    }
}

/**
 * Best-effort cleanup of expired cache files.
 */
function idempotencyCleanupExpired(): void {
    if (mt_rand(1, 100) !== 1) {
        return;
    }

    idempotencyEnsureDir();
    $cutoff = time() - IDEMPOTENCY_TTL_SECONDS;
    foreach (glob(IDEMPOTENCY_DIR . '/*.json') ?: [] as $path) {
        if (@filemtime($path) !== false && @filemtime($path) < $cutoff) {
            @unlink($path);
        }
    }
}

/**
 * Return normalized idempotency key from request headers.
 */
function idempotencyRequestKey(): string {
    $raw = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($raw === '' || strlen($raw) > 200) {
        return '';
    }
    return $raw;
}

/**
 * Begin idempotency handling for a write request.
 *
 * Returns null when key is missing (feature bypass), or a context array.
 */
function idempotencyBegin(int $userId, string $route, int $ttlSeconds = IDEMPOTENCY_TTL_SECONDS): ?array {
    $key = idempotencyRequestKey();
    if ($key === '') {
        return null;
    }

    $payloadHash = idempotencyPayloadHash();

    idempotencyEnsureDir();
    idempotencyCleanupExpired();

    $digest = hash('sha256', $userId . '|' . $route . '|' . $key);
    $redis = idempotencyRedis();

    if (is_object($redis) && method_exists($redis, 'set')) {
        $cacheKey = idempotencyCacheKey($digest);
        $lockKey = idempotencyLockKey($digest);
        $lockToken = bin2hex(random_bytes(16));

        $lockAcquired = false;
        try {
            $lockAcquired = (bool) $redis->set($lockKey, $lockToken, ['nx', 'ex' => IDEMPOTENCY_LOCK_TTL_SECONDS]);
        } catch (Throwable $e) {
            $lockAcquired = false;
        }

        if (!$lockAcquired) {
            for ($i = 0; $i < 3; $i++) {
                usleep(100000);
                $raw = $redis->get($cacheKey);
                if ($raw) {
                    $cached = json_decode($raw, true);
                    if (is_array($cached) && isset($cached['created_at'], $cached['status'], $cached['body'])) {
                        return idempotencyBuildReplay($cached, $payloadHash, ['redis' => null, 'lock_key' => null, 'lock_token' => null]);
                    }
                }
            }

            return [
                'enabled' => true,
                'replay' => true,
                'status' => 409,
                'body' => [
                    'ok' => false,
                    'error' => 'A request with this idempotency key is already in progress.',
                ],
                'redis' => null,
                'lock_key' => null,
                'lock_token' => null,
            ];
        }

        $raw = $redis->get($cacheKey);
        if ($raw) {
            $cached = json_decode($raw, true);
            if (is_array($cached) && isset($cached['created_at'], $cached['status'], $cached['body'])) {
                return idempotencyBuildReplay($cached, $payloadHash, [
                    'redis' => $redis,
                    'lock_key' => $lockKey,
                    'lock_token' => $lockToken,
                ]);
            }
        }

        return [
            'enabled' => true,
            'replay' => false,
            'redis' => $redis,
            'cache_key' => $cacheKey,
            'lock_key' => $lockKey,
            'lock_token' => $lockToken,
            'payload_hash' => $payloadHash,
            'created_at' => time(),
        ];
    }

    $cachePath = IDEMPOTENCY_DIR . '/' . $digest . '.json';
    $lockPath = IDEMPOTENCY_DIR . '/' . $digest . '.lock';

    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false) {
        return null;
    }

    if (!flock($lockHandle, LOCK_EX)) {
        fclose($lockHandle);
        return null;
    }

    if (file_exists($cachePath)) {
        $raw = file_get_contents($cachePath);
        $cached = $raw ? json_decode($raw, true) : null;

        if (is_array($cached) && isset($cached['created_at'], $cached['status'], $cached['body'])) {
            $expiresAt = ((int) $cached['created_at']) + $ttlSeconds;
            if ($expiresAt >= time()) {
                return idempotencyBuildReplay($cached, $payloadHash, [
                    'cache_path' => $cachePath,
                    'lock_handle' => $lockHandle,
                ]);
            }
        }

        @unlink($cachePath);
    }

    return [
        'enabled' => true,
        'replay' => false,
        'cache_path' => $cachePath,
        'lock_handle' => $lockHandle,
        'payload_hash' => $payloadHash,
        'created_at' => time(),
    ];
}

/**
 * Finalize idempotency context and store response when applicable.
 */
function idempotencyFinish(?array $ctx, int $status, array $body): void {
    if ($ctx === null) {
        return;
    }

    $redis = $ctx['redis'] ?? null;
    if (is_object($redis) && method_exists($redis, 'setex')) {
        try {
            if (!($ctx['replay'] ?? false) && !empty($ctx['cache_key'])) {
                $payload = [
                    'created_at' => (int) ($ctx['created_at'] ?? time()),
                    'status' => $status,
                    'body' => $body,
                    'payload_hash' => (string) ($ctx['payload_hash'] ?? ''),
                ];
                $redis->setex((string) $ctx['cache_key'], IDEMPOTENCY_TTL_SECONDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
        } finally {
            idempotencyReleaseRedisLock($redis, $ctx['lock_key'] ?? null, $ctx['lock_token'] ?? null);
        }
        return;
    }

    try {
        if (!($ctx['replay'] ?? false) && !empty($ctx['cache_path'])) {
            $payload = [
                'created_at' => (int) ($ctx['created_at'] ?? time()),
                'status' => $status,
                'body' => $body,
                'payload_hash' => (string) ($ctx['payload_hash'] ?? ''),
            ];
            file_put_contents($ctx['cache_path'], json_encode($payload), LOCK_EX);
        }
    } finally {
        if (!empty($ctx['lock_handle'])) {
            flock($ctx['lock_handle'], LOCK_UN);
            fclose($ctx['lock_handle']);
        }
    }
}
