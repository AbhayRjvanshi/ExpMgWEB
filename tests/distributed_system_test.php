<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/RedisService.php';
require_once __DIR__ . '/../services/LockService.php';
require_once __DIR__ . '/../services/HealthService.php';
require_once __DIR__ . '/../services/CacheService.php';
require_once __DIR__ . '/../services/MetricsService.php';
require_once __DIR__ . '/../services/PredictiveHealthService.php';
require_once __DIR__ . '/../helpers/logger.php';

/**
 * Distributed System Test Suite
 *
 * Validates all distributed coordination features:
 * - Redis integration
 * - Distributed locking
 * - Health coordination
 * - Cache efficiency
 * - Metrics collection
 * - Predictive health scoring
 * - Failure injection
 *
 * Run: php api/tests/distributed_system_test.php
 */

class DistributedSystemTest
{
    private $results = [];
    private $passed = 0;
    private $failed = 0;
    private $tests = [];

    public function run(): void
    {
        echo "\n" . str_repeat('=', 80) . "\n";
        echo "Distributed System Test Suite\n";
        echo str_repeat('=', 80) . "\n\n";

        // Test Redis Integration
        $this->section('Redis Integration');
        $this->testRedisConnection();
        $this->testRedisBasicOps();

        // Test Distributed Locking
        $this->section('Distributed Locking');
        $this->testLockAcquisition();
        $this->testLockPrevention();
        $this->testLockRetry();

        // Test Health System
        $this->section('Distributed Health System');
        $this->testNodeHealthPublishing();
        $this->testGlobalHealthAggregation();

        // Test Cache Layer
        $this->section('Cache Layer');
        $this->testCacheSetGet();
        $this->testCacheRemember();
        $this->testCacheInvalidation();

        // Test Metrics & Predictive Health
        $this->section('Predictive Health System');
        $this->testMetricsCollection();
        $this->testHealthScoring();
        $this->testAdaptiveConfig();

        // Test Failure Injection
        $this->section('Failure Injection');
        $this->testRedisFallback();
        $this->testCacheMiss();
        $this->testHighLatency();

        // Summary
        $this->printSummary();
    }

    private function section(string $name): void
    {
        echo "\n── $name ──\n\n";
    }

    private function test(string $name, callable $callback): void
    {
        try {
            $callback();
            $this->passed++;
            echo "  ✓ $name\n";
        } catch (\Throwable $e) {
            $this->failed++;
            echo "  ✗ $name: " . $e->getMessage() . "\n";
        }
    }

    // ========== Redis Integration Tests ==========

    private function testRedisConnection(): void
    {
        $this->test('Redis connection', function () {
            $available = RedisService::isAvailable();
            if (!$available) {
                throw new \Exception('Redis unavailable');
            }
        });
    }

    private function testRedisBasicOps(): void
    {
        $this->test('SET and GET', function () {
            RedisService::set('test:key', 'test_value', 10);
            $val = RedisService::get('test:key');
            if ($val !== 'test_value') {
                throw new \Exception("Expected 'test_value', got '$val'");
            }
            RedisService::del('test:key');
        });

        $this->test('DEL removes key', function () {
            RedisService::set('test:key', 'value', 10);
            RedisService::del('test:key');
            $val = RedisService::get('test:key');
            if ($val !== null) {
                throw new \Exception('Key not deleted');
            }
        });

        $this->test('SETNX only sets if absent', function () {
            RedisService::del('test:lock');
            $first = RedisService::setnx('test:lock', 'value1', 10);
            $second = RedisService::setnx('test:lock', 'value2', 10);
            if (!$first || $second) {
                throw new \Exception('SETNX behavior incorrect');
            }
            RedisService::del('test:lock');
        });

        $this->test('EXISTS checks key', function () {
            RedisService::set('test:exists', 'value', 10);
            if (!RedisService::exists('test:exists')) {
                throw new \Exception('EXISTS failed');
            }
            RedisService::del('test:exists');
            if (RedisService::exists('test:exists')) {
                throw new \Exception('EXISTS should return false');
            }
        });
    }

    // ========== Distributed Locking Tests ==========

    private function testLockAcquisition(): void
    {
        $this->test('Acquire and release lock', function () {
            $key = 'lock:test:' . uniqid();
            $acquired = LockService::acquireLock($key, 5);
            if (!$acquired) {
                throw new \Exception('Failed to acquire lock');
            }
            if (!LockService::isLocked($key)) {
                throw new \Exception('Lock not registered');
            }
            LockService::releaseLock($key);
            if (LockService::isLocked($key)) {
                throw new \Exception('Lock not released');
            }
        });
    }

    private function testLockPrevention(): void
    {
        $this->test('Lock prevents duplicate execution', function () {
            $key = 'lock:prevent:' . uniqid();

            // First acquisition succeeds
            $first = LockService::acquireLock($key, 5);
            if (!$first) {
                throw new \Exception('First lock failed');
            }

            // Second acquisition fails (lock held)
            $second = LockService::acquireLock($key, 5);
            if ($second) {
                throw new \Exception('Second lock should fail');
            }

            LockService::releaseLock($key);
        });
    }

    private function testLockRetry(): void
    {
        $this->test('withLock executes callback', function () {
            $key = 'lock:callback:' . uniqid();
            $executed = false;

            $result = LockService::withLock($key, function () use (&$executed) {
                $executed = true;
                return 'success';
            }, 5);

            if (!$executed || $result !== 'success') {
                throw new \Exception('Callback not executed');
            }
        });

        $this->test('withLock returns null if locked', function () {
            $key = 'lock:locked:' . uniqid();

            // Acquire lock manually
            LockService::acquireLock($key, 5);

            // Try to run with lock (should return null)
            $result = LockService::withLock($key, function () {
                return 'should_not_execute';
            }, 5);

            LockService::releaseLock($key);

            if ($result !== null) {
                throw new \Exception('withLock should return null when locked');
            }
        });
    }

    // ========== Health System Tests ==========

    private function testNodeHealthPublishing(): void
    {
        $this->test('Publish node health', function () {
            $success = HealthService::publishNodeHealth('healthy', [
                'latency_ms' => 50,
                'error_rate' => 0.5,
                'queue_pressure' => 10,
            ]);

            if (!$success) {
                throw new \Exception('Failed to publish health');
            }

            $nodeId = HealthService::getNodeId();
            $health = HealthService::getNodeHealth($nodeId);

            if (!$health || $health['status'] !== 'healthy') {
                throw new \Exception('Health not stored correctly');
            }
        });
    }

    private function testGlobalHealthAggregation(): void
    {
        $this->test('Aggregate global health', function () {
            // Publish multiple node healths
            HealthService::publishNodeHealth('healthy', [
                'latency_ms' => 50,
                'error_rate' => 0.5,
                'queue_pressure' => 5,
            ]);

            $global = HealthService::getGlobalHealth();

            if (empty($global) || !isset($global['status'])) {
                throw new \Exception('Global health aggregation failed');
            }

            if ($global['metrics']['active_nodes'] < 1) {
                throw new \Exception('No active nodes in aggregation');
            }
        });

        $this->test('System readiness check', function () {
            HealthService::publishNodeHealth('healthy', [
                'latency_ms' => 50,
                'error_rate' => 0.5,
                'queue_pressure' => 5,
            ]);

            $ready = HealthService::isSystemReady(30);

            if (!$ready) {
                throw new \Exception('System should be ready');
            }
        });
    }

    // ========== Cache Tests ==========

    private function testCacheSetGet(): void
    {
        $this->test('Cache set and get', function () {
            $key = 'cache:test:' . uniqid();
            $value = ['user_id' => 123, 'name' => 'John'];

            CacheService::set($key, $value, 60);
            $cached = CacheService::get($key);

            if (!$cached) {
                throw new \Exception('Cache get returned null');
            }

            $decoded = json_decode($cached, true);
            if ($decoded['user_id'] !== 123) {
                throw new \Exception('Cached value mismatch');
            }

            CacheService::delete($key);
        });
    }

    private function testCacheRemember(): void
    {
        $this->test('Cache remember (cache-or-compute)', function () {
            $key = 'cache:remember:' . uniqid();
            $callCount = 0;

            // First call: cache miss, callback executed
            $result1 = CacheService::remember($key, 60, function () use (&$callCount) {
                $callCount++;
                return ['data' => 'computed'];
            });

            if ($callCount !== 1) {
                throw new \Exception('Callback should execute on cache miss');
            }

            // Second call: cache hit, callback not executed
            $result2 = CacheService::remember($key, 60, function () use (&$callCount) {
                $callCount++;
                return ['data' => 'should_not_compute'];
            });

            if ($callCount !== 1) {
                throw new \Exception('Callback should not execute on cache hit');
            }

            CacheService::delete($key);
        });
    }

    private function testCacheInvalidation(): void
    {
        $this->test('Cache invalidation by pattern', function () {
            $prefix = uniqid('cache:inv:');
            $key1 = $prefix . ':1';
            $key2 = $prefix . ':2';

            CacheService::set($key1, 'value1', 60);
            CacheService::set($key2, 'value2', 60);

            if (!CacheService::get($key1) || !CacheService::get($key2)) {
                throw new \Exception('Cache set failed');
            }

            $deleted = CacheService::forget($prefix . ':*');

            if ($deleted < 2) {
                throw new \Exception("Expected to delete 2+ keys, deleted $deleted");
            }

            if (CacheService::get($key1) || CacheService::get($key2)) {
                throw new \Exception('Cache keys not deleted');
            }
        });
    }

    // ========== Metrics & Predictive Health Tests ==========

    private function testMetricsCollection(): void
    {
        $this->test('Record latency', function () {
            MetricsService::reset();
            MetricsService::recordLatency(50.5);
            MetricsService::recordLatency(75.2);
            MetricsService::recordLatency(100.1);

            $metrics = MetricsService::getMetrics();
            if ($metrics['p95_latency_ms'] <= 0) {
                throw new \Exception('Latency not recorded');
            }
        });

        $this->test('Record errors and calculate error rate', function () {
            MetricsService::reset();

            for ($i = 0; $i < 10; $i++) {
                MetricsService::recordRequest();
            }
            for ($i = 0; $i < 3; $i++) {
                MetricsService::recordError();
            }

            $metrics = MetricsService::getMetrics();
            if ($metrics['error_rate'] < 20 || $metrics['error_rate'] > 40) {
                throw new \Exception('Error rate calculation off: ' . $metrics['error_rate']);
            }
        });

        $this->test('Record retries', function () {
            MetricsService::reset();
            MetricsService::recordRetry();
            MetricsService::recordRetry();
            MetricsService::recordRetry();

            $metrics = MetricsService::getMetrics();
            if ($metrics['retry_count'] !== 3) {
                throw new \Exception('Retry count incorrect');
            }
        });
    }

    private function testHealthScoring(): void
    {
        $this->test('Calculate health score', function () {
            MetricsService::reset();

            // Simulate healthy system
            for ($i = 0; $i < 20; $i++) {
                MetricsService::recordRequest();
                MetricsService::recordLatency(50);
            }

            $score = PredictiveHealthService::calculateScore();
            if ($score < 70) {
                throw new \Exception("Score too low for healthy system: $score");
            }
        });

        $this->test('Determine health state', function () {
            MetricsService::reset();

            // Simulate degraded system
            for ($i = 0; $i < 10; $i++) {
                MetricsService::recordRequest();
                MetricsService::recordError();
                MetricsService::recordLatency(300);
            }

            $state = PredictiveHealthService::getState();
            if ($state === 'healthy') {
                throw new \Exception("State should not be healthy for degraded system: $state");
            }
        });
    }

    private function testAdaptiveConfig(): void
    {
        $this->test('Get adaptive configuration', function () {
            MetricsService::reset();

            $config = PredictiveHealthService::getAdaptiveConfig();

            if (!isset($config['score'], $config['state'], $config['config'])) {
                throw new \Exception('Adaptive config missing fields');
            }

            if (!isset($config['config']['max_concurrency'])) {
                throw new \Exception('Max concurrency not in config');
            }
        });

        $this->test('Get recommended retry delay', function () {
            $delay0 = PredictiveHealthService::getRecommendedRetryDelay(0);
            $delay1 = PredictiveHealthService::getRecommendedRetryDelay(1);
            $delay2 = PredictiveHealthService::getRecommendedRetryDelay(2);

            if ($delay0 >= $delay1 || $delay1 >= $delay2) {
                throw new \Exception('Retry delays not increasing exponentially');
            }

            if ($delay0 <= 0 || $delay2 > 30000) {
                throw new \Exception('Retry delays out of bounds');
            }
        });
    }

    // ========== Failure Injection Tests ==========

    private function testRedisFallback(): void
    {
        $this->test('Services gracefully handle Redis unavailability', function () {
            // These should not throw even if Redis is down
            // They should return sensible defaults

            $lock = LockService::isLocked('nonexistent');
            $cached = CacheService::get('nonexistent');
            $metrics = MetricsService::getMetrics();

            // Should not throw, behavior is degraded but operational
            if (!is_array($metrics)) {
                throw new \Exception('Metrics should return array on fallback');
            }
        });
    }

    private function testCacheMiss(): void
    {
        $this->test('Cache miss handled gracefully', function () {
            $val = CacheService::get('definitely:nonexistent:' . uniqid());

            if ($val !== null) {
                throw new \Exception('Cache should return null on miss');
            }
        });
    }

    private function testHighLatency(): void
    {
        $this->test('High latency affects health score', function () {
            MetricsService::reset();

            // Record high latency
            for ($i = 0; $i < 10; $i++) {
                MetricsService::recordRequest();
                MetricsService::recordLatency(800); // Very high
            }

            $score = PredictiveHealthService::calculateScore();
            $state = PredictiveHealthService::getState();

            if ($score > 70) {
                throw new \Exception("Score should be low for high latency: $score");
            }

            if ($state === 'healthy') {
                throw new \Exception("State should not be healthy with high latency: $state");
            }
        });
    }

    // ========== Summary ==========

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;

        echo "\n" . str_repeat('=', 80) . "\n";
        echo "Summary: $this->passed/$total tests passed\n";
        echo str_repeat('=', 80) . "\n";

        if ($this->failed > 0) {
            echo "\n⚠ $this->failed test(s) failed\n";
            exit(1);
        } else {
            echo "\n✓ All tests passed!\n\n";
            exit(0);
        }
    }
}

// Run tests
$tester = new DistributedSystemTest();
$tester->run();
