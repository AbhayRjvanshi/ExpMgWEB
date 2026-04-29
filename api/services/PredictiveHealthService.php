<?php

declare(strict_types=1);

require_once __DIR__ . '/MetricsService.php';
require_once __DIR__ . '/HealthService.php';

/**
 * PredictiveHealthService: Shift from reactive to proactive health management.
 *
 * Calculates a health score (0-100) based on:
 * - Request latency
 * - Error rate
 * - Retry count
 * - Queue pressure
 *
 * States:
 * - Score 80-100: healthy      (normal operation)
 * - Score 60-80:  caution      (monitor closely)
 * - Score 40-60:  degraded     (limit concurrency, reduce load)
 * - Score <40:    critical     (fallback mode, circuit break)
 *
 * Adaptive actions based on score:
 * - Increase retry delay as score drops
 * - Reduce concurrency when degraded
 * - Prioritize critical requests first
 * - Trigger fallback earlier
 *
 * Functions:
 * - calculateScore() -> int (0-100)
 * - getState() -> string (healthy|caution|degraded|critical)
 * - getAdaptiveConfig() -> array
 * - shouldCircuitBreak() -> bool
 * - shouldReduceConcurrency() -> bool
 * - getRecommendedRetryDelay($retryCount) -> int (milliseconds)
 */
class PredictiveHealthService
{
    /**
     * Weights for score calculation.
     */
    private const WEIGHT_LATENCY = 0.25;
    private const WEIGHT_ERROR_RATE = 0.35;
    private const WEIGHT_RETRIES = 0.20;
    private const WEIGHT_QUEUE = 0.20;

    /**
     * Thresholds for state transitions.
     */
    private const THRESHOLD_HEALTHY = 80;
    private const THRESHOLD_CAUTION = 60;
    private const THRESHOLD_DEGRADED = 40;

    /**
     * Calculate health score (0-100) based on current metrics.
     *
     * Formula:
     * score = 100
     *   - (latency_weight * normalized_latency)
     *   - (error_weight * error_rate)
     *   - (retry_weight * normalized_retries)
     *   - (queue_weight * queue_pressure)
     *
     * @return int (0-100)
     */
    public static function calculateScore(): int
    {
        $metrics = MetricsService::getMetrics();

        $score = 100.0;

        // Latency component: 0-100ms is good, >500ms is bad
        $latency = $metrics['average_latency_ms'] ?? 0;
        $normalizedLatency = min(100, ($latency / 500) * 100);
        $score -= self::WEIGHT_LATENCY * $normalizedLatency;

        // Error rate component: directly subtract error rate (0-100)
        $errorRate = $metrics['error_rate'] ?? 0;
        $score -= self::WEIGHT_ERROR_RATE * $errorRate;

        // Retry component: normalize by expected rate
        // If we're retrying a lot, that's bad
        $retries = $metrics['retry_count'] ?? 0;
        $requests = $metrics['request_count'] ?? 0;
        $retryRate = $requests > 0 ? min(100, ($retries / $requests) * 100) : 0;
        $score -= self::WEIGHT_RETRIES * $retryRate;

        // Queue pressure component: 0-100% (0 items good, 100+ items bad)
        $queueLen = $metrics['queue_length'] ?? 0;
        $queuePressure = min(100, ($queueLen / 100) * 100);
        $score -= self::WEIGHT_QUEUE * $queuePressure;

        return max(0, (int) round($score));
    }

    /**
     * Backward-compatible alias used by the orchestrator.
     *
     * @return int
     */
    public static function getScore(): int
    {
        return self::calculateScore();
    }

    /**
     * Get health state based on score.
     *
     * @return string "healthy", "caution", "degraded", or "critical"
     */
    public static function getState(): string
    {
        $score = self::calculateScore();

        if ($score >= self::THRESHOLD_HEALTHY) {
            return 'healthy';
        } elseif ($score >= self::THRESHOLD_CAUTION) {
            return 'caution';
        } elseif ($score >= self::THRESHOLD_DEGRADED) {
            return 'degraded';
        } else {
            return 'critical';
        }
    }

    /**
     * Get adaptive configuration recommendations based on health.
     *
     * @return array
     */
    public static function getAdaptiveConfig(): array
    {
        $score = self::calculateScore();
        $state = self::getState();

        return [
            'score' => $score,
            'state' => $state,
            'config' => [
                'max_concurrency' => self::getMaxConcurrency($score),
                'retry_delay_ms' => self::getBaseRetryDelay($score),
                'timeout_ms' => self::getTimeout($score),
                'circuit_break' => self::shouldCircuitBreak(),
                'prioritize_critical' => $score < self::THRESHOLD_CAUTION,
                'enable_fallback' => $score < self::THRESHOLD_DEGRADED,
                'reduce_payload_size' => $score < self::THRESHOLD_CAUTION,
            ],
        ];
    }

    /**
     * Should circuit breaker be activated?
     *
     * @return bool
     */
    public static function shouldCircuitBreak(): bool
    {
        $score = self::calculateScore();
        return $score < self::THRESHOLD_DEGRADED;
    }

    /**
     * Should concurrency be reduced?
     *
     * @return bool
     */
    public static function shouldReduceConcurrency(): bool
    {
        $score = self::calculateScore();
        return $score < self::THRESHOLD_CAUTION;
    }

    /**
     * Get maximum allowed concurrent requests.
     *
     * @param int|null $score Optional score (defaults to current)
     * @return int
     */
    public static function getMaxConcurrency(?int $score = null): int
    {
        $score = $score ?? self::calculateScore();

        // Scale: healthy=50, caution=30, degraded=15, critical=5
        if ($score >= self::THRESHOLD_HEALTHY) {
            return 50;
        } elseif ($score >= self::THRESHOLD_CAUTION) {
            return 30;
        } elseif ($score >= self::THRESHOLD_DEGRADED) {
            return 15;
        } else {
            return 5;
        }
    }

    /**
     * Get base retry delay in milliseconds.
     *
     * @param int|null $score Optional score (defaults to current)
     * @return int
     */
    public static function getBaseRetryDelay(?int $score = null): int
    {
        $score = $score ?? self::calculateScore();

        // Scale: healthy=100, caution=250, degraded=500, critical=2000
        if ($score >= self::THRESHOLD_HEALTHY) {
            return 100;
        } elseif ($score >= self::THRESHOLD_CAUTION) {
            return 250;
        } elseif ($score >= self::THRESHOLD_DEGRADED) {
            return 500;
        } else {
            return 2000;
        }
    }

    /**
     * Get request timeout in milliseconds.
     *
     * @param int|null $score Optional score (defaults to current)
     * @return int
     */
    public static function getTimeout(?int $score = null): int
    {
        $score = $score ?? self::calculateScore();

        // Scale: healthy=5000, caution=3000, degraded=2000, critical=1000
        if ($score >= self::THRESHOLD_HEALTHY) {
            return 5000;
        } elseif ($score >= self::THRESHOLD_CAUTION) {
            return 3000;
        } elseif ($score >= self::THRESHOLD_DEGRADED) {
            return 2000;
        } else {
            return 1000;
        }
    }

    /**
     * Get recommended retry delay for a specific retry attempt.
     *
     * Uses exponential backoff with jitter: delay = baseDelay * (2 ^ retryCount) + random(0, baseDelay)
     *
     * @param int $retryCount Starting from 0
     * @return int Milliseconds
     */
    public static function getRecommendedRetryDelay(int $retryCount): int
    {
        $baseDelay = self::getBaseRetryDelay();
        $exponentialDelay = $baseDelay * pow(2, $retryCount);
        $withJitter = $exponentialDelay + random_int(0, $baseDelay);

        // Cap at 30 seconds
        return min(30000, $withJitter);
    }

    /**
     * Get full diagnostic report.
     *
     * @return array
     */
    public static function getDiagnostics(): array
    {
        $metrics = MetricsService::getMetrics();
        $score = self::calculateScore();
        $state = self::getState();
        $config = self::getAdaptiveConfig();

        return [
            'timestamp' => time(),
            'metrics' => $metrics,
            'health' => [
                'score' => $score,
                'state' => $state,
            ],
            'adaptive_config' => $config['config'],
            'recommendations' => self::getRecommendations($score),
        ];
    }

    /**
     * Get human-readable recommendations.
     *
     * @param int|null $score
     * @return array
     */
    private static function getRecommendations(?int $score = null): array
    {
        $score = $score ?? self::calculateScore();
        $recommendations = [];

        if ($score >= self::THRESHOLD_HEALTHY) {
            $recommendations[] = 'System is healthy. Normal operation.';
        } elseif ($score >= self::THRESHOLD_CAUTION) {
            $recommendations[] = 'System entering caution zone. Monitor closely.';
            $recommendations[] = 'Consider reducing new requests if trend continues.';
        } elseif ($score >= self::THRESHOLD_DEGRADED) {
            $recommendations[] = 'System degraded. Enabling circuit breaker.';
            $recommendations[] = 'Prioritizing critical operations only.';
            $recommendations[] = 'Activating fallback mechanisms.';
        } else {
            $recommendations[] = 'System critical. Emergency mode activated.';
            $recommendations[] = 'Only essential operations proceeding.';
            $recommendations[] = 'All fallbacks engaged.';
        }

        return $recommendations;
    }
}
