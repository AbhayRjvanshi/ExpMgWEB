<?php

declare(strict_types=1);

require_once __DIR__ . '/RedisService.php';

/**
 * CacheService: Efficient caching with TTL and cache-or-compute pattern.
 *
 * Key design:
 * - user:{id}                          -> User profile
 * - group:{id}                         -> Group details
 * - user_groups:{user_id}              -> User's groups (paginated: {page})
 * - expenses:{user_id}:{page}          -> Expenses list (paginated)
 * - expense_summary:{user_id}:{month}  -> Monthly summary
 * - categories                         -> All categories
 * - budgets:{user_id}:{month}          -> Budget for month
 *
 * Rules:
 * - Never cache sensitive data without filtering
 * - Never cache write responses
 * - TTL: 60-300 seconds (configurable)
 * - Always provide $callback for dynamic data
 *
 * Functions:
 * - get($key) -> mixed|null
 * - set($key, $value, $ttl) -> bool
 * - delete($key) -> bool
 * - remember($key, $ttl, $callback) -> mixed
 * - forget($pattern) -> int (count of deleted keys)
 */
class CacheService
{
    /**
     * Default cache TTL: 120 seconds.
     */
    private const DEFAULT_TTL = 120;

    /**
     * Cache key prefix to avoid collisions with other apps.
     */
    private const CACHE_PREFIX = 'cache:';

    /**
     * Get a value from cache.
     *
     * Returns null if not cached or cache unavailable.
     *
     * @param string $key
     * @return mixed|null
     */
    public static function get(string $key): ?string
    {
        if (!RedisService::isAvailable()) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . $key;
        $cached = RedisService::get($cacheKey);

        if ($cached === null) {
            return null;
        }

        // Attempt to decode JSON; if it fails, return raw string
        $decoded = json_decode($cached, true);

        return is_array($decoded) ? json_encode($decoded) : $cached;
    }

    /**
     * Set a value in cache with TTL.
     *
     * @param string $key
     * @param mixed  $value (will be JSON-encoded)
     * @param int    $ttl   Seconds (default 120)
     * @return bool
     */
    public static function set(string $key, $value, int $ttl = self::DEFAULT_TTL): bool
    {
        if (!RedisService::isAvailable()) {
            return false;
        }

        $cacheKey = self::CACHE_PREFIX . $key;

        if (is_string($value)) {
            $stored = $value;
        } else {
            $stored = json_encode($value);
        }

        return RedisService::set($cacheKey, $stored, $ttl);
    }

    /**
     * Delete a cache key.
     *
     * @param string $key
     * @return bool
     */
    public static function delete(string $key): bool
    {
        if (!RedisService::isAvailable()) {
            return false;
        }

        $cacheKey = self::CACHE_PREFIX . $key;
        return RedisService::del($cacheKey);
    }

    /**
     * Cache-or-compute pattern: get from cache or run callback.
     *
     * If key is cached, returns cached value.
     * Otherwise, runs $callback, caches the result, and returns it.
     * Callback should return serializable value.
     *
     * @param string   $key
     * @param int      $ttl
     * @param callable $callback function(): mixed
     * @return mixed
     */
    public static function remember(string $key, int $ttl = self::DEFAULT_TTL, callable $callback)
    {
        if (RedisService::isAvailable()) {
            $cached = self::get($key);
            if ($cached !== null) {
                return json_decode($cached, true) ?: $cached;
            }
        }

        $result = call_user_func($callback);

        if (RedisService::isAvailable()) {
            self::set($key, $result, $ttl);
        }

        return $result;
    }

    /**
     * Delete cache keys matching a pattern.
     *
     * Pattern uses wildcards: "expenses:123:*"
     *
     * Returns number of keys deleted.
     *
     * @param string $pattern
     * @return int
     */
    public static function forget(string $pattern): int
    {
        if (!RedisService::isAvailable()) {
            return 0;
        }

        $redis = RedisService::getInstance();
        if (!$redis || !$redis->isConnected()) {
            return 0;
        }

        $redisObj = $redis->getRedis();
        if (!$redisObj) {
            return 0;
        }

        $deleted = 0;
        $matchPattern = self::CACHE_PREFIX . $pattern;

        try {
            $cursor = 0;
            do {
                $keys = $redisObj->scan($cursor, 'MATCH', $matchPattern);
                if ($keys === false) {
                    break;
                }

                if (is_array($keys) && count($keys) > 1) {
                    $cursor = (int) $keys[0];
                    $matchedKeys = $keys[1] ?? [];

                    foreach ($matchedKeys as $key) {
                        if (RedisService::del(substr($key, strlen(self::CACHE_PREFIX)))) {
                            $deleted++;
                        }
                    }
                }
            } while ($cursor !== 0);
        } catch (\Throwable $e) {
            // Scan not available
        }

        return $deleted;
    }

    /**
     * Check if cache is available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return RedisService::isAvailable();
    }

    /**
     * Get cache statistics.
     *
     * @return array
     */
    public static function getStats(): array
    {
        $health = RedisService::getHealthSnapshot();

        return [
            'available' => $health['connected'] ?? false,
            'host' => $health['host'] ?? 'unknown',
            'port' => $health['port'] ?? 'unknown',
            'memory' => $health['memory'] ?? 'unknown',
        ];
    }
}
