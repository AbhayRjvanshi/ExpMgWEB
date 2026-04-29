<?php

declare(strict_types=1);

require_once __DIR__ . '/RedisService.php';

/**
 * MetricsService: Collects and stores system metrics for predictive health.
 *
 * Tracks (per rolling window, e.g., last 60 seconds):
 * - Request latency (P50, P95, P99)
 * - Error rate (%)
 * - Retry count
 * - Queue length
 * - Success rate (%)
 *
 * Keys:
 * - metrics:latency:list      -> list of recent latencies
 * - metrics:errors:count      -> error counter
 * - metrics:retries:count     -> retry counter
 * - metrics:queue:length      -> current queue size
 * - metrics:requests:count    -> total request count
 *
 * Functions:
 * - recordLatency($ms) -> void
 * - recordError($errorType) -> void
 * - recordRetry() -> void
 * - recordRequest() -> void
 * - getMetrics() -> array
 * - calculateHealth() -> array (with score 0-100 and state)
 */
class MetricsService
{
    /**
     * Rolling window TTL: 60 seconds.
     * Metrics are kept for 1 minute to calculate rates.
     */
    private const METRICS_WINDOW = 60;

    /**
     * Max latency samples to keep in rolling window.
     */
    private const MAX_LATENCY_SAMPLES = 100;

    /**
     * Record request latency (in milliseconds).
     *
     * @param float $ms
     * @return void
     */
    public static function recordLatency(float $ms): void
    {
        if (!RedisService::isAvailable()) {
            return;
        }

        $key = 'metrics:latency:list';
        RedisService::lpush($key, (string) $ms);

        // Trim to max samples, expire after window
        $redis = RedisService::getInstance();
        if ($redis && $redis->isConnected()) {
            $redisObj = $redis->getRedis();
            if ($redisObj) {
                try {
                    $redisObj->lTrim($key, 0, self::MAX_LATENCY_SAMPLES - 1);
                    $redisObj->expire($key, self::METRICS_WINDOW);
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }
    }

    /**
     * Record an error.
     *
     * @param string $errorType (optional label for categorization)
     * @return void
     */
    public static function recordError(string $errorType = 'general'): void
    {
        if (!RedisService::isAvailable()) {
            return;
        }

        $key = 'metrics:errors:count';
        RedisService::increment($key);

        // Expire counter after window
        $redis = RedisService::getInstance();
        if ($redis && $redis->isConnected()) {
            $redisObj = $redis->getRedis();
            if ($redisObj) {
                try {
                    $redisObj->expire($key, self::METRICS_WINDOW);
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }
    }

    /**
     * Record a retry attempt.
     *
     * @return void
     */
    public static function recordRetry(): void
    {
        if (!RedisService::isAvailable()) {
            return;
        }

        $key = 'metrics:retries:count';
        RedisService::increment($key);

        $redis = RedisService::getInstance();
        if ($redis && $redis->isConnected()) {
            $redisObj = $redis->getRedis();
            if ($redisObj) {
                try {
                    $redisObj->expire($key, self::METRICS_WINDOW);
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }
    }

    /**
     * Record a successful request.
     *
     * @return void
     */
    public static function recordRequest(): void
    {
        if (!RedisService::isAvailable()) {
            return;
        }

        $key = 'metrics:requests:count';
        RedisService::increment($key);

        $redis = RedisService::getInstance();
        if ($redis && $redis->isConnected()) {
            $redisObj = $redis->getRedis();
            if ($redisObj) {
                try {
                    $redisObj->expire($key, self::METRICS_WINDOW);
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }
    }

    /**
     * Set current queue length.
     *
     * @param int $length
     * @return void
     */
    public static function setQueueLength(int $length): void
    {
        if (!RedisService::isAvailable()) {
            return;
        }

        $key = 'metrics:queue:length';
        RedisService::set($key, (string) $length, self::METRICS_WINDOW);
    }

    /**
     * Get all current metrics.
     *
     * @return array{
     *   average_latency_ms: float,
     *   p95_latency_ms: float,
     *   p99_latency_ms: float,
     *   error_count: int,
     *   request_count: int,
     *   error_rate: float,
     *   retry_count: int,
     *   queue_length: int
     * }
     */
    public static function getMetrics(): array
    {
        if (!RedisService::isAvailable()) {
            return [
                'average_latency_ms' => 0,
                'p95_latency_ms' => 0,
                'p99_latency_ms' => 0,
                'error_count' => 0,
                'request_count' => 0,
                'error_rate' => 0.0,
                'retry_count' => 0,
                'queue_length' => 0,
            ];
        }

        $latencies = RedisService::lrange('metrics:latency:list', 0, -1);
        $errors = (int) (RedisService::get('metrics:errors:count') ?? 0);
        $retries = (int) (RedisService::get('metrics:retries:count') ?? 0);
        $requests = (int) (RedisService::get('metrics:requests:count') ?? 0);
        $queueLen = (int) (RedisService::get('metrics:queue:length') ?? 0);

        // Calculate latency percentiles
        $latencyValues = array_map('floatval', $latencies);
        sort($latencyValues);

        $avgLatency = count($latencyValues) > 0 ? array_sum($latencyValues) / count($latencyValues) : 0;

        $p95 = 0;
        $p99 = 0;

        if (count($latencyValues) > 0) {
            $p95Index = (int) (0.95 * (count($latencyValues) - 1));
            $p99Index = (int) (0.99 * (count($latencyValues) - 1));
            $p95 = $latencyValues[$p95Index] ?? 0;
            $p99 = $latencyValues[$p99Index] ?? 0;
        }

        $errorRate = $requests > 0 ? ($errors / $requests) * 100 : 0;

        return [
            'average_latency_ms' => round($avgLatency, 2),
            'p95_latency_ms' => round($p95, 2),
            'p99_latency_ms' => round($p99, 2),
            'error_count' => $errors,
            'request_count' => $requests,
            'error_rate' => round($errorRate, 2),
            'retry_count' => $retries,
            'queue_length' => $queueLen,
        ];
    }

    /**
     * Clear all metrics (for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        if (!RedisService::isAvailable()) {
            return;
        }

        RedisService::del('metrics:latency:list');
        RedisService::del('metrics:errors:count');
        RedisService::del('metrics:retries:count');
        RedisService::del('metrics:requests:count');
        RedisService::del('metrics:queue:length');
    }
}
