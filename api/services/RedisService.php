<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/redis.php';

/**
 * RedisService: Reusable wrapper around RedisClient for distributed coordination.
 *
 * Functions:
 * - get($key)
 * - set($key, $value, $ttl = null)
 * - del($key)
 * - exists($key)
 * - setnx($key, $value, $ttl)
 * - publish($channel, $message)
 * - increment($key, $delta = 1)
 * - lpush($key, $value)
 * - rpop($key)
 * - lrange($key, $start, $end)
 * - hset($key, $field, $value)
 * - hget($key, $field)
 *
 * Handles connection fallback gracefully.
 */
class RedisService
{
    /**
     * @var RedisClient|null
     */
    private static ?RedisClient $instance = null;

    /**
     * Initialize singleton instance.
     */
    public static function initialize(): void
    {
        if (self::$instance === null) {
            self::$instance = new RedisClient();
        }
    }

    /**
     * Get Redis client instance.
     */
    public static function getInstance(): ?RedisClient
    {
        self::initialize();
        return self::$instance;
    }

    /**
     * Check if Redis is available.
     */
    public static function isAvailable(): bool
    {
        self::initialize();
        return self::$instance?->isConnected() ?? false;
    }

    /**
     * Get a value from Redis.
     */
    public static function get(string $key): ?string
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return null;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return null;
        }

        try {
            $value = $redis->get($key);
            return $value !== false ? $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Set a value in Redis with optional TTL.
     */
    public static function set(string $key, string $value, ?int $ttl = null): bool
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return false;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return false;
        }

        try {
            if ($ttl !== null) {
                $redis->setex($key, $ttl, $value);
            } else {
                $redis->set($key, $value);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Delete a key from Redis.
     */
    public static function del(string $key): bool
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return false;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return false;
        }

        try {
            $redis->del($key);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if key exists in Redis.
     */
    public static function exists(string $key): bool
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return false;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return false;
        }

        try {
            return (bool) $redis->exists($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Set a key only if it doesn't exist (SET NX).
     *
     * Returns true if set, false if key already exists or Redis unavailable.
     */
    public static function setnx(string $key, string $value, ?int $ttl = null): bool
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return false;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return false;
        }

        try {
            if ($ttl !== null) {
                // Preferred atomic form: SET key value NX EX ttl
                $result = $redis->set($key, $value, ['NX', 'EX' => $ttl]);
                if ($result === true || $result === 'OK') {
                    return true;
                }

                return false;
            }

            $result = $redis->setnx($key, $value);
            return $result === true || (int) $result === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Publish a message to a channel.
     */
    public static function publish(string $channel, string $message): bool
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return false;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return false;
        }

        try {
            $redis->publish($channel, $message);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Increment a numeric value (for counters/metrics).
     */
    public static function increment(string $key, int $delta = 1): ?int
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return null;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return null;
        }

        try {
            return (int) $redis->incrBy($key, $delta);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Push value to list (left push).
     */
    public static function lpush(string $key, string $value): bool
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return false;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return false;
        }

        try {
            $redis->lPush($key, $value);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Pop value from list (right pop).
     */
    public static function rpop(string $key): ?string
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return null;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return null;
        }

        try {
            $value = $redis->rPop($key);
            return $value !== false ? $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get range from list.
     *
     * @param int $start
     * @param int $end  (-1 for last element)
     * @return array<string>
     */
    public static function lrange(string $key, int $start, int $end): array
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return [];
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return [];
        }

        try {
            $items = $redis->lRange($key, $start, $end);
            return is_array($items) ? $items : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Set hash field.
     */
    public static function hset(string $key, string $field, string $value): bool
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return false;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return false;
        }

        try {
            $redis->hSet($key, $field, $value);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get hash field.
     */
    public static function hget(string $key, string $field): ?string
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return null;
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return null;
        }

        try {
            $value = $redis->hGet($key, $field);
            return $value !== false ? $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get all hash fields.
     *
     * @return array<string, string>
     */
    public static function hgetall(string $key): array
    {
        self::initialize();
        if (!self::$instance?->isConnected()) {
            return [];
        }

        $redis = self::$instance->getRedis();
        if (!$redis) {
            return [];
        }

        try {
            $result = $redis->hGetAll($key);
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get health snapshot.
     */
    public static function getHealthSnapshot(): array
    {
        self::initialize();
        if (!self::$instance) {
            return ['connected' => false];
        }

        return self::$instance->getHealthSnapshot();
    }
}
