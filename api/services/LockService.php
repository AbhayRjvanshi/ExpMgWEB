<?php

declare(strict_types=1);

require_once __DIR__ . '/RedisService.php';

/**
 * LockService: Distributed locking using Redis SET NX EX.
 *
 * Prevents duplicate execution across multiple nodes.
 *
 * Key patterns:
 * - lock:job:{job_id}
 * - lock:expense:{id}
 * - lock:outbox:worker
 * - lock:settlement:{group_id}
 *
 * Functions:
 * - acquireLock($key, $ttl = 30) -> bool
 * - releaseLock($key) -> bool
 * - isLocked($key) -> bool
 * - withLock($key, $callback, $ttl = 30) -> mixed (runs callback if lock acquired)
 */
class LockService
{
    /**
     * Default lock TTL in seconds.
     * Prevents deadlocks if a process crashes.
     */
    private const DEFAULT_TTL = 30;

    /**
     * File lock handles used when Redis is unavailable.
     *
     * @var array<string, resource>
     */
    private static array $fileLocks = [];

    /**
     * Acquire a distributed lock.
     *
     * Uses Redis SET NX EX for atomic lock acquisition.
     * Returns true if lock acquired, false if already held.
     *
     * @param string $key    Lock key (e.g., "lock:expense:123")
     * @param int    $ttl    Lock TTL in seconds
     * @return bool
     */
    public static function acquireLock(string $key, int $ttl = self::DEFAULT_TTL): bool
    {
        if (!RedisService::isAvailable()) {
            return self::acquireFileLock($key);
        }

        $lockValue = uniqid('lock_', true);
        $locked = RedisService::setnx($key, $lockValue, $ttl);

        return $locked;
    }

    /**
     * Release a distributed lock.
     *
     * @param string $key
     * @return bool
     */
    public static function releaseLock(string $key): bool
    {
        if (!RedisService::isAvailable()) {
            return self::releaseFileLock($key);
        }

        return RedisService::del($key);
    }

    /**
     * Check if a lock is currently held.
     *
     * @param string $key
     * @return bool
     */
    public static function isLocked(string $key): bool
    {
        if (!RedisService::isAvailable()) {
            return isset(self::$fileLocks[$key]);
        }

        return RedisService::exists($key);
    }

    /**
     * Acquire a lock and execute a callback.
     *
     * If lock cannot be acquired, callback is not executed.
     * Lock is released after callback completes (even on exception).
     *
     * @param string   $key
     * @param callable $callback
     * @param int      $ttl
     * @return mixed|null  Callback result, or null if lock not acquired
     * @throws \Throwable  Re-throws exceptions from callback
     */
    public static function withLock(string $key, callable $callback, int $ttl = self::DEFAULT_TTL)
    {
        if (!self::acquireLock($key, $ttl)) {
            return null;
        }

        try {
            return call_user_func($callback);
        } finally {
            self::releaseLock($key);
        }
    }

    /**
     * Acquire lock with timeout and retry logic.
     *
     * Attempts to acquire lock up to $maxAttempts times,
     * waiting $delayMs between attempts.
     *
     * @param string $key
     * @param int    $maxAttempts
     * @param int    $delayMs    Delay between attempts in milliseconds
     * @param int    $ttl
     * @return bool
     */
    public static function acquireLockWithRetry(
        string $key,
        int $maxAttempts = 5,
        int $delayMs = 100,
        int $ttl = self::DEFAULT_TTL
    ): bool {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if (self::acquireLock($key, $ttl)) {
                return true;
            }

            if ($attempt < $maxAttempts - 1) {
                usleep($delayMs * 1000);
            }
        }

        return false;
    }

    /**
     * Acquire a lock using a local file descriptor when Redis is unavailable.
     */
    private static function acquireFileLock(string $key): bool
    {
        if (isset(self::$fileLocks[$key])) {
            return true;
        }

        $dir = self::getFileLockDir();
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }

        $path = $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.lock';
        $handle = @fopen($path, 'c+');
        if (!$handle) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        self::$fileLocks[$key] = $handle;
        return true;
    }

    /**
     * Release a local file-based lock.
     */
    private static function releaseFileLock(string $key): bool
    {
        if (!isset(self::$fileLocks[$key])) {
            return true;
        }

        $handle = self::$fileLocks[$key];
        unset(self::$fileLocks[$key]);

        if (!is_resource($handle)) {
            return false;
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
        return true;
    }

    /**
     * Directory used for local fallback lock files.
     */
    private static function getFileLockDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'locks';
    }

    /**
     * Acquire lock and run callback with retry.
     *
     * @param string   $key
     * @param callable $callback
     * @param int      $maxAttempts
     * @param int      $delayMs
     * @param int      $ttl
     * @return mixed|null
     * @throws \Throwable
     */
    public static function withLockRetry(
        string $key,
        callable $callback,
        int $maxAttempts = 5,
        int $delayMs = 100,
        int $ttl = self::DEFAULT_TTL
    ) {
        if (!self::acquireLockWithRetry($key, $maxAttempts, $delayMs, $ttl)) {
            return null;
        }

        try {
            return call_user_func($callback);
        } finally {
            self::releaseLock($key);
        }
    }
}
