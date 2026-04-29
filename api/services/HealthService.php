<?php

declare(strict_types=1);

require_once __DIR__ . '/RedisService.php';

/**
 * HealthService: Distributed health coordination.
 *
 * Each node publishes health metrics every 5-10 seconds.
 * System aggregates all node health into a global status.
 *
 * Node health keys: system:health:{node_id}
 * Global health key: system:global_health
 *
 * Payload:
 * {
 *   "status": "healthy | degraded | down",
 *   "latency_ms": number,
 *   "error_rate": number (0-100),
 *   "queue_pressure": number (0-100),
 *   "timestamp": unix,
 *   "node_id": string
 * }
 *
 * Functions:
 * - publishNodeHealth($status, $metrics) -> bool
 * - getGlobalHealth() -> array
 * - getNodeHealth($nodeId) -> array|null
 * - getAllNodeHealth() -> array
 * - aggregateGlobalHealth() -> array
 */
class HealthService
{
    /**
     * Node health TTL: 60 seconds.
     * Nodes fall out of aggregation if they don't report for 1 minute.
     */
    private const NODE_HEALTH_TTL = 60;

    /**
     * Global health aggregation interval: 30 seconds.
     */
    private const GLOBAL_HEALTH_TTL = 30;

    /**
     * Get node ID for this process.
     *
     * Default: hostname:pid
     */
    public static function getNodeId(): string
    {
        static $nodeId = null;

        if ($nodeId === null) {
            $hostname = gethostname() ?: 'unknown';
            $pid = getmypid();
            $nodeId = "{$hostname}_{$pid}";
        }

        return $nodeId;
    }

    /**
     * Publish health metrics for this node.
     *
     * @param string $status  "healthy", "degraded", or "down"
     * @param array  $metrics {latency_ms: int, error_rate: float, queue_pressure: int, ...}
     * @return bool
     */
    public static function publishNodeHealth(string $status, array $metrics = []): bool
    {
        if (!RedisService::isAvailable()) {
            return false;
        }

        $nodeId = self::getNodeId();
        $key = "system:health:{$nodeId}";

        $payload = [
            'status' => $status,
            'latency_ms' => $metrics['latency_ms'] ?? 0,
            'error_rate' => $metrics['error_rate'] ?? 0.0,
            'queue_pressure' => $metrics['queue_pressure'] ?? 0,
            'timestamp' => time(),
            'node_id' => $nodeId,
        ];

        $json = json_encode($payload);
        return RedisService::set($key, $json, self::NODE_HEALTH_TTL);
    }

    /**
     * Get health status for a specific node.
     *
     * @param string $nodeId
     * @return array|null
     */
    public static function getNodeHealth(string $nodeId): ?array
    {
        if (!RedisService::isAvailable()) {
            return null;
        }

        $key = "system:health:{$nodeId}";
        $json = RedisService::get($key);

        return $json ? json_decode($json, true) : null;
    }

    /**
     * Get all active node health reports.
     *
     * @return array<string, array>
     */
    public static function getAllNodeHealth(): array
    {
        if (!RedisService::isAvailable()) {
            return [];
        }

        // Scan for all system:health:* keys
        $redis = RedisService::getInstance();
        if (!$redis || !$redis->isConnected()) {
            return [];
        }

        $redisObj = $redis->getRedis();
        if (!$redisObj) {
            return [];
        }

        $nodes = [];
        try {
            $cursor = 0;
            do {
                $keys = $redisObj->scan($cursor, 'MATCH', 'system:health:*');
                if ($keys === false) {
                    break;
                }

                // $keys is [cursor, [keys...]]
                if (is_array($keys) && count($keys) > 1) {
                    $cursor = (int) $keys[0];
                    $matchedKeys = $keys[1] ?? [];

                    foreach ($matchedKeys as $key) {
                        $json = RedisService::get($key);
                        if ($json) {
                            $data = json_decode($json, true);
                            if (is_array($data) && isset($data['node_id'])) {
                                $nodes[$data['node_id']] = $data;
                            }
                        }
                    }
                }
            } while ($cursor !== 0);
        } catch (\Throwable $e) {
            // Fallback: scan not available, return empty
        }

        return $nodes;
    }

    /**
     * Aggregate all node health into a global status.
     *
     * Returns:
     * {
     *   "status": "healthy | degraded | critical",
     *   "timestamp": unix,
     *   "nodes": {...},
     *   "metrics": {
     *     "active_nodes": int,
     *     "degraded_nodes": int,
     *     "down_nodes": int,
     *     "average_latency_ms": float,
     *     "average_error_rate": float,
     *     "max_queue_pressure": int
     *   }
     * }
     *
     * @return array
     */
    public static function aggregateGlobalHealth(): array
    {
        $allNodes = self::getAllNodeHealth();

        $activeCount = count($allNodes);
        $degradedCount = 0;
        $downCount = 0;

        $totalLatency = 0;
        $totalErrorRate = 0.0;
        $maxQueuePressure = 0;

        foreach ($allNodes as $node) {
            if ($node['status'] === 'degraded') {
                $degradedCount++;
            } elseif ($node['status'] === 'down') {
                $downCount++;
            }

            $totalLatency += $node['latency_ms'] ?? 0;
            $totalErrorRate += $node['error_rate'] ?? 0.0;
            $maxQueuePressure = max($maxQueuePressure, $node['queue_pressure'] ?? 0);
        }

        $avgLatency = $activeCount > 0 ? $totalLatency / $activeCount : 0;
        $avgErrorRate = $activeCount > 0 ? $totalErrorRate / $activeCount : 0.0;

        // Determine global status
        if ($downCount >= $activeCount * 0.5) {
            $globalStatus = 'critical';
        } elseif ($degradedCount >= $activeCount * 0.3 || $avgErrorRate > 20) {
            $globalStatus = 'degraded';
        } else {
            $globalStatus = 'healthy';
        }

        $result = [
            'status' => $globalStatus,
            'timestamp' => time(),
            'nodes' => $allNodes,
            'metrics' => [
                'active_nodes' => $activeCount,
                'degraded_nodes' => $degradedCount,
                'down_nodes' => $downCount,
                'average_latency_ms' => round($avgLatency, 2),
                'average_error_rate' => round($avgErrorRate, 2),
                'max_queue_pressure' => $maxQueuePressure,
            ],
        ];

        return $result;
    }

    /**
     * Cache and return global health.
     *
     * Recalculates every N seconds.
     *
     * @return array
     */
    public static function getGlobalHealth(): array
    {
        if (!RedisService::isAvailable()) {
            return [
                'status' => 'unknown',
                'timestamp' => time(),
                'nodes' => [],
                'metrics' => [
                    'active_nodes' => 0,
                    'degraded_nodes' => 0,
                    'down_nodes' => 0,
                    'average_latency_ms' => 0,
                    'average_error_rate' => 0,
                    'max_queue_pressure' => 0,
                ],
            ];
        }

        $cacheKey = 'system:global_health_cache';
        $cached = RedisService::get($cacheKey);

        if ($cached) {
            return json_decode($cached, true) ?: self::aggregateGlobalHealth();
        }

        $globalHealth = self::aggregateGlobalHealth();
        $json = json_encode($globalHealth);
        RedisService::set($cacheKey, $json, self::GLOBAL_HEALTH_TTL);

        return $globalHealth;
    }

    /**
     * Determine system readiness for critical operations.
     *
     * Returns false if too many nodes are down or degraded.
     *
     * @param float $acceptableDegradationPercent  Default 30 (allow 30% degradation)
     * @return bool
     */
    public static function isSystemReady(float $acceptableDegradationPercent = 30): bool
    {
        $globalHealth = self::getGlobalHealth();
        $status = $globalHealth['status'] ?? 'unknown';

        if ($status === 'critical') {
            return false;
        }

        $metrics = $globalHealth['metrics'] ?? [];
        $activeNodes = $metrics['active_nodes'] ?? 0;
        $degradedNodes = $metrics['degraded_nodes'] ?? 0;

        if ($activeNodes === 0) {
            return false;
        }

        $degradationPercent = ($degradedNodes / $activeNodes) * 100;

        return $degradationPercent <= $acceptableDegradationPercent;
    }
}
