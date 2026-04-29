<?php

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/logger.php';

/**
 * Lightweight Redis client wrapper for the ephemeral notification system.
 *
 * Keys:
 *  - notifications:user:{user_id}      — list of JSON-encoded events (LPUSH, LTRIM 0 49)
 *  - notification_rate:{group_id}      — per-group rate counter (INCR, EXPIRE 60)
 *  - notification_event:{event_id}     — dedup marker (SETNX, EXPIRE short TTL)
 */

class RedisClient
{
    /** @var object|null */
    private $redis;

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var string|null */
    private $connectionError = null;

    public function __construct()
    {
        $this->host = (string) env('REDIS_HOST', '127.0.0.1');
        $this->port = (int) env('REDIS_PORT', 6379);

        if (!class_exists('Redis')) {
            $this->redis = null;
            $this->connectionError = 'Redis extension is not available.';
            return;
        }

        $timeout = 1.5;

        $redisClass = 'Redis';
        $this->redis = new $redisClass();
        try {
            $this->redis->connect($this->host, $this->port, $timeout);
            $password = env('REDIS_PASSWORD');
            if ($password !== null && $password !== '') {
                $this->redis->auth($password);
            }
        } catch (\Throwable $e) {
            $this->redis = null;
            $this->connectionError = $e->getMessage();
            if (function_exists('logMessage')) {
                logMessage('WARNING', '[REDIS_FALLBACK] reason="connection_failed"', [
                    'host' => $this->host,
                    'port' => $this->port,
                    'error' => $this->connectionError,
                    'connected' => false,
                ]);
            }
        }
    }

    public function isConnected(): bool
    {
        return is_object($this->redis);
    }

    public function getRedis(): ?object
    {
        return $this->redis;
    }

    public function getConnectionError(): ?string
    {
        return $this->connectionError;
    }

    public function getHealthSnapshot(): array
    {
        return [
            'connected' => $this->isConnected(),
            'host' => $this->host,
            'port' => $this->port,
            'error' => $this->connectionError,
            'memory' => $this->getMemoryStats(),
        ];
    }

    // ----- Notification list operations -----

    public function publishNotification(int $userId, array $event): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        $key = "notifications:user:{$userId}";
        $payload = json_encode($event, JSON_UNESCAPED_UNICODE);
        $this->redis->lPush($key, $payload);
        // Keep at most 50
        $this->redis->lTrim($key, 0, 49);
        // 3 days TTL
        $this->redis->expire($key, 259200);
        return true;
    }

    public function getNotifications(int $userId, int $limit = 50): array
    {
        if (!$this->isConnected()) {
            return [];
        }
        $key = "notifications:user:{$userId}";
        $items = $this->redis->lRange($key, 0, $limit - 1);
        $events = [];
        foreach ($items as $raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
        return $events;
    }

    public function getUnreadCount(int $userId): int
    {
        if (!$this->isConnected()) {
            return 0;
        }
        $key = "notifications:user:{$userId}";
        return (int) $this->redis->lLen($key);
    }

    public function consumeNotification(int $userId, string $eventId): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        $key = "notifications:user:{$userId}";
        $items = $this->redis->lRange($key, 0, -1);
        foreach ($items as $raw) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (($decoded['event_id'] ?? null) === $eventId) {
                // Remove first matching occurrence
                $this->redis->lRem($key, $raw, 1);
                return true;
            }
        }
        return false;
    }

    public function clearAllNotifications(int $userId): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        $key = "notifications:user:{$userId}";
        $this->redis->del($key);
        return true;
    }

    // ----- Group rate limiting -----

    public function isRateLimited(int $groupId): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        $key = "notification_rate:{$groupId}";
        $count = (int) $this->redis->get($key);
        return $count >= 20;
    }

    public function incrementRateLimit(int $groupId): void
    {
        if (!$this->isConnected()) {
            return;
        }
        $key = "notification_rate:{$groupId}";
        $this->redis->incr($key);
        $this->redis->expire($key, 60);
    }

    // ----- Event deduplication -----

    public function isEventSeen(string $eventId): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        $key = "notification_event:{$eventId}";
        return (bool) $this->redis->exists($key);
    }

    public function markEventSeen(string $eventId, int $ttlSeconds): void
    {
        if (!$this->isConnected()) {
            return;
        }
        $key = "notification_event:{$eventId}";
        $this->redis->set($key, 1, $ttlSeconds);
    }

    // ----- Memory stats -----

    public function getMemoryStats(): array
    {
        if (!$this->isConnected()) {
            return [];
        }
        $info = $this->redis->info('memory');
        return $info ?: [];
    }
}

/**
 * Helper to obtain a shared RedisClient instance.
 */
function getRedis(): RedisClient
{
    static $client = null;
    if ($client === null) {
        $client = new RedisClient();
    }
    return $client;
}

