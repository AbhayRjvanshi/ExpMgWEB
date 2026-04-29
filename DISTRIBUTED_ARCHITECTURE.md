# Distributed Architecture Implementation Guide

## Overview

This document describes the complete distributed-aware, cache-optimized, and predictive-health driven architecture upgrade for the ExpMgWEB backend system. All components are Redis-backed and gracefully degrade when Redis is unavailable.

## Architecture Components

### 1. Redis Integration (`api/services/RedisService.php`)

**Purpose:** Centralized Redis access layer providing fail-safe operations.

**Key Features:**
- Singleton pattern for efficient connection management
- Automatic fallback when Redis is unavailable
- All operations return sensible defaults on connection failure
- Support for basic operations (get, set, del, exists, setnx)
- Support for advanced structures (lists, hashes, counters)

**Usage:**
```php
require_once __DIR__ . '/services/RedisService.php';

// Check availability
if (RedisService::isAvailable()) {
    RedisService::set('key', 'value', 120); // 120s TTL
    $val = RedisService::get('key');
    RedisService::del('key');
}
```

**Environment Variables:**
```
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null  (optional)
```

---

### 2. Distributed Locking (`api/services/LockService.php`)

**Purpose:** Prevent duplicate execution across multiple nodes using Redis SET NX EX.

**Key Features:**
- Lock TTL-based expiration (prevents deadlocks)
- Callback-based lock management with cleanup
- Retry logic with exponential backoff
- Fail-safe design (returns false if Redis unavailable)

**Lock Key Patterns:**
```
lock:job:{job_id}
lock:expense:{id}
lock:outbox:worker
lock:settlement:{group_id}
```

**Usage:**
```php
// Simple acquire/release
if (LockService::acquireLock('lock:expense:123', 30)) {
    try {
        // Do critical work
    } finally {
        LockService::releaseLock('lock:expense:123');
    }
}

// Callback pattern (auto cleanup)
LockService::withLock('lock:expense:123', function () {
    // Do critical work (lock auto-released after)
}, 30);

// Retry with timeout
$result = LockService::withLockRetry(
    'lock:settlement:456',
    function () { /* work */ },
    $maxAttempts = 5,
    $delayMs = 100,
    $ttl = 30
);
```

---

### 3. Distributed Health System (`api/services/HealthService.php`)

**Purpose:** Coordinate system-wide health awareness across all nodes.

**Key Features:**
- Each node publishes health every 5-10 seconds
- Global health aggregated from active nodes
- System readiness calculation
- Health state: healthy | degraded | critical

**Health Payload:**
```json
{
  "status": "healthy | degraded | down",
  "latency_ms": 50.5,
  "error_rate": 2.3,
  "queue_pressure": 15,
  "timestamp": 1704067200,
  "node_id": "hostname_pid"
}
```

**Redis Keys:**
```
system:health:{node_id}          → Individual node health
system:global_health_cache       → Aggregated global state
```

**Usage:**
```php
// Publish this node's health (call every 5-10 seconds)
HealthService::publishNodeHealth('degraded', [
    'latency_ms' => 75.5,
    'error_rate' => 5.2,
    'queue_pressure' => 25,
]);

// Get global system health
$global = HealthService::getGlobalHealth();
// {
//   "status": "degraded",
//   "timestamp": 1704067200,
//   "metrics": {
//     "active_nodes": 3,
//     "degraded_nodes": 1,
//     "down_nodes": 0,
//     "average_latency_ms": 60.2,
//     "average_error_rate": 2.8,
//     "max_queue_pressure": 25
//   }
// }

// Check if system is ready for critical operations
if (HealthService::isSystemReady(acceptableDegradation: 30)) {
    // System can handle requests
}
```

---

### 4. Caching Layer (`api/services/CacheService.php`)

**Purpose:** Reduce database load and improve response times with intelligent caching.

**Key Features:**
- Cache-or-compute pattern (remember)
- Pattern-based invalidation
- Safe JSON serialization
- TTL-based expiration

**Cache Key Design:**
```
cache:user:{id}                    → User profile (TTL: 120s)
cache:group:{id}                   → Group details (TTL: 120s)
cache:user_groups:{user_id}:{page} → User's groups list (TTL: 60s)
cache:expenses:{user_id}:{page}    → Expenses list (TTL: 120s)
cache:categories                   → All categories (TTL: 300s)
```

**Usage:**
```php
// Simple set/get
CacheService::set('user:123', ['id' => 123, 'name' => 'John'], ttl: 120);
$user = CacheService::get('user:123');

// Cache-or-compute (preferred pattern)
$user = CacheService::remember('user:123', 120, function () {
    // This callback runs ONLY if cache is empty
    return getUserFromDB(123);
});

// Invalidate by pattern
CacheService::forget('expenses:123:*');    // All pages of user 123
CacheService::forget('cache:*');           // Everything

// Check availability
if (CacheService::isAvailable()) {
    // Use cache
}
```

---

### 5. Predictive Health System (`api/services/MetricsService.php` & `api/services/PredictiveHealthService.php`)

**Purpose:** Shift from reactive to proactive system behavior using adaptive health scoring.

**Metrics Tracked (60-second rolling window):**
- Request latency (P50, P95, P99)
- Error rate (%)
- Retry count
- Queue length
- Success rate (%)

**Health Score Formula:**
```
score = 100
  - (0.25 * normalized_latency)
  - (0.35 * error_rate)
  - (0.20 * retry_rate)
  - (0.20 * queue_pressure)

States:
- 80-100:  healthy      (normal operation)
- 60-80:   caution      (monitor, may reduce load)
- 40-60:   degraded     (limit concurrency, use fallback)
- <40:     critical     (emergency mode, circuit break)
```

**Adaptive Actions:**

| Score | Status | Max Concurrency | Retry Delay | Timeout | Actions |
|-------|--------|-----------------|-------------|---------|---------|
| 80-100 | Healthy | 50 | 100ms | 5000ms | Normal operation |
| 60-80 | Caution | 30 | 250ms | 3000ms | Monitor, consider load reduction |
| 40-60 | Degraded | 15 | 500ms | 2000ms | Reduce concurrency, use fallback |
| <40 | Critical | 5 | 2000ms | 1000ms | Circuit break, essentials only |

**Usage:**
```php
// Record metrics (call during request handling)
MetricsService::recordRequest();
MetricsService::recordLatency($ms);
// On error:
MetricsService::recordError('database_timeout');
// On retry:
MetricsService::recordRetry();

// Get current metrics
$metrics = MetricsService::getMetrics();
// {
//   "average_latency_ms": 65.5,
//   "p95_latency_ms": 120.2,
//   "p99_latency_ms": 250.8,
//   "error_count": 3,
//   "request_count": 100,
//   "error_rate": 3.0,
//   "retry_count": 5,
//   "queue_length": 12
// }

// Get health score and recommendations
$score = PredictiveHealthService::calculateScore();  // 0-100
$state = PredictiveHealthService::getState();  // healthy|caution|degraded|critical

// Get adaptive configuration
$config = PredictiveHealthService::getAdaptiveConfig();
// {
//   "score": 75,
//   "state": "caution",
//   "config": {
//     "max_concurrency": 30,
//     "retry_delay_ms": 250,
//     "timeout_ms": 3000,
//     "circuit_break": false,
//     "prioritize_critical": false,
//     "enable_fallback": false,
//     "reduce_payload_size": false
//   }
// }

// Adaptive retry delay (exponential backoff with jitter)
for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
    $delayMs = PredictiveHealthService::getRecommendedRetryDelay($attempt);
    usleep($delayMs * 1000);
    // retry...
}

// Full diagnostics
$diag = PredictiveHealthService::getDiagnostics();
```

---

## Integration Points

### Request Handler

Add metrics recording to request lifecycle:

```php
// At request start
$startTime = microtime(true);

try {
    // Process request
    MetricsService::recordRequest();
    
    // ... handle request ...
    
} catch (Throwable $e) {
    MetricsService::recordError($e->getMessage());
    throw $e;
} finally {
    $elapsedMs = (microtime(true) - $startTime) * 1000;
    MetricsService::recordLatency($elapsedMs);
}
```

### GET Endpoints (Caching)

```php
// Before: Raw DB query
$result = DB->query("SELECT * FROM users WHERE id = ?");

// After: With cache
$result = CacheService::remember("user:$id", 120, function () {
    return DB->query("SELECT * FROM users WHERE id = ?");
});
```

### Critical Operations (Locking)

```php
// Expense creation
LockService::withLock("lock:expense:$userId", function () {
    // Process expense creation
}, ttl: 10);

// Settlement calculation
LockService::withLock("lock:settlement:$groupId", function () {
    // Calculate settlement
}, ttl: 30);
```

### Outbox Processing (Distributed)

```php
// Prevent multiple nodes from processing outbox simultaneously
if (LockService::acquireLock('lock:outbox:worker', 60)) {
    try {
        // Process outbox
        processOutbox();
    } finally {
        LockService::releaseLock('lock:outbox:worker');
    }
}
```

### Health Coordination (Background Task)

Example background task to run every 5 seconds:

```php
// scripts/health_publisher.php
while (true) {
    $metrics = MetricsService::getMetrics();
    $state = PredictiveHealthService::getState();
    
    HealthService::publishNodeHealth($state, [
        'latency_ms' => $metrics['average_latency_ms'],
        'error_rate' => $metrics['error_rate'],
        'queue_pressure' => min(100, ($metrics['queue_length'] / 100) * 100),
    ]);
    
    sleep(5);
}
```

---

## Testing

Run the comprehensive test suite:

```bash
php api/tests/distributed_system_test.php
```

**Test Coverage:**
- ✓ Redis connection and basic operations
- ✓ Lock acquisition, prevention, and retry
- ✓ Node health publishing and global aggregation
- ✓ Cache set/get, remember pattern, pattern invalidation
- ✓ Metrics collection and health scoring
- ✓ Adaptive configuration generation
- ✓ Failure injection (Redis unavailable, high latency)
- ✓ Graceful degradation

**Prerequisites:**
- Redis server running on `REDIS_HOST:REDIS_PORT`
- PHP Redis extension enabled (or tests gracefully skip)

---

## Deployment Checklist

- [ ] Redis server configured and running
- [ ] Environment variables set (`.env` file):
  ```
  REDIS_HOST=redis-server.local
  REDIS_PORT=6379
  REDIS_PASSWORD=secure-password
  ```
- [ ] Redis extension installed in PHP
- [ ] All service files created and readable
- [ ] Cache TTL values reviewed and adjusted if needed
- [ ] Lock key patterns documented
- [ ] Health publishing task configured (cron or background worker)
- [ ] Metrics recording integrated into request handlers
- [ ] Test suite passing with Redis available
- [ ] Monitoring dashboard tracking health scores and state transitions

---

## Performance Impact

**Expected Improvements:**
- GET endpoints: 50-70% faster (cached reads)
- Duplicate write prevention: ~99% reduction in retry storms
- Distributed execution: No more race conditions in multi-node setups
- Proactive health management: Fewer cascading failures

**Resource Usage:**
- Redis memory: ~50-100MB (depends on key count)
- Metrics overhead: <1% CPU per node
- Lock contention: Minimal if TTLs are tuned correctly

---

## Troubleshooting

### Redis Connection Fails

1. Check Redis is running: `redis-cli ping`
2. Verify environment variables
3. Services gracefully fall back to DB (no crashes)
4. Check logs for connection errors

### Cache Not Working

1. Verify Redis is connected
2. Check cache keys don't have sensitive data
3. Verify TTL values are appropriate
4. Monitor Redis memory usage

### High Lock Contention

1. Reduce lock TTL if operations are fast
2. Increase concurrency limits if safe
3. Review lock key granularity
4. Consider using lock retry with backoff

### Health Score Stuck at Low Value

1. Check metrics are being recorded
2. Verify latency is actually acceptable
3. Review error rate calculation
4. Consider tuning weights in PredictiveHealthService

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────┐
│                  Request Handler                         │
├─────────────────────────────────────────────────────────┤
│ 1. Check Predictive Health → adapt concurrency           │
│ 2. Check/Acquire Lock for critical ops                   │
│ 3. Try cache for GET endpoints                           │
│ 4. Execute with metrics collection                       │
│ 5. Update global health (background)                     │
└────────────────┬────────────────────────────────────────┘
                 │
        ┌────────▼────────┐
        │   Redis Layer   │
        ├─────────────────┤
        │ • Cache         │
        │ • Locks         │
        │ • Metrics       │
        │ • Health        │
        └────────────────┘
```

---

## Next Steps

1. **Enable Redis in development:**
   ```bash
   docker run -d -p 6379:6379 redis:latest
   ```

2. **Integrate metrics into request handlers:**
   - Modify `api/bootstrap.php` to record metrics
   - Add cache layer to GET endpoints
   - Add locks to critical operations

3. **Set up health publisher:**
   - Create background task
   - Run every 5-10 seconds
   - Publish to Redis

4. **Monitor system:**
   - Track health score trends
   - Alert on state transitions
   - Measure cache hit rate

5. **Performance tuning:**
   - Adjust cache TTLs
   - Fine-tune health score weights
   - Optimize lock key patterns

---

## Files Summary

| File | Purpose |
|------|---------|
| `api/services/RedisService.php` | Centralized Redis access |
| `api/services/LockService.php` | Distributed locking |
| `api/services/HealthService.php` | Health coordination |
| `api/services/CacheService.php` | Cache layer |
| `api/services/MetricsService.php` | Metrics collection |
| `api/services/PredictiveHealthService.php` | Health scoring & adaptive config |
| `api/tests/distributed_system_test.php` | Comprehensive test suite |

---

**Architecture Version:** 1.0  
**Created:** April 2, 2026  
**Last Updated:** April 2, 2026
