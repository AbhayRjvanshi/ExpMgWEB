# Quick Start: Integration Guide

## Immediate Next Steps

### 1. Verify Environment Setup

```bash
# 1. Check Redis is available
php -r "require 'api/helpers/redis.php'; \$r = new RedisClient(); echo \$r->isConnected() ? 'Redis: OK' : 'Redis: OFFLINE';"

# 2. Run test suite (optional)
php api/tests/distributed_system_test.php
```

### 2. Add to API Bootstrap

File: `api/bootstrap.php`

```php
<?php
// Add these requires AFTER existing includes
require_once __DIR__ . '/services/RedisService.php';
require_once __DIR__ . '/services/LockService.php';
require_once __DIR__ . '/services/CacheService.php';
require_once __DIR__ . '/services/HealthService.php';
require_once __DIR__ . '/services/MetricsService.php';
require_once __DIR__ . '/services/PredictiveHealthService.php';

// Initialize Redis on bootstrap
RedisService::initialize();
?>
```

### 3. Add Metrics to Existing Request Handler

File: `api/bootstrap.php` (wrap request execution)

```php
$startTime = microtime(true);

try {
    // Existing request handling code
    
    MetricsService::recordRequest();
    
} catch (Throwable $e) {
    MetricsService::recordError(get_class($e));
    throw $e;
} finally {
    $elapsedMs = (microtime(true) - $startTime) * 1000;
    MetricsService::recordLatency($elapsedMs);
}
```

### 4. Apply Cache to GET Endpoints (Example)

File: `api/budgets/get.php`

**Before:**
```php
$budget = BudgetService()->get($userId, $month);
return apiSuccess($budget);
```

**After:**
```php
$cacheKey = "budgets:{$userId}:{$month}";
$budget = CacheService::remember($cacheKey, 300, function () {
    return BudgetService()->get($userId, $month);
});
return apiSuccess($budget);
```

### 5. Add Locking to Critical Operations

File: `api/expenses/create.php`

```php
// Wrap critical expense creation in lock
LockService::withLock("lock:expense:user:{$userId}", function () {
    // Check idempotency
    $existing = checkIdempotency($idempotencyKey);
    if ($existing) {
        return $existing;
    }
    
    // Create expense
    $expense = ExpenseService()->create($data);
    
    // Invalidate cache
    CacheService::forget("expenses:{$userId}:*");
    
    return $expense;
}, 10);
```

### 6. Set Up Health Publisher (Background Task)

Create: `scripts/publish_health.php`

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/services/HealthService.php';
require_once __DIR__ . '/../api/services/MetricsService.php';
require_once __DIR__ . '/../api/services/PredictiveHealthService.php';

// Run forever, publish health every 5 seconds
while (true) {
    try {
        $metrics = MetricsService::getMetrics();
        $score = PredictiveHealthService::calculateScore();
        $state = PredictiveHealthService::getState();
        
        $queuePressure = min(100, ($metrics['queue_length'] / 100) * 100);
        
        HealthService::publishNodeHealth($state, [
            'latency_ms' => $metrics['average_latency_ms'],
            'error_rate' => $metrics['error_rate'],
            'queue_pressure' => (int) $queuePressure,
        ]);
        
        // Log health transition if needed
        static $lastState = null;
        if ($state !== $lastState) {
            logMessage('INFO', "Health state changed: $lastState → $state", [
                'score' => $score,
                'node' => HealthService::getNodeId(),
            ]);
            $lastState = $state;
        }
        
        sleep(5);
    } catch (Throwable $e) {
        logMessage('ERROR', 'Health publisher error', ['error' => $e->getMessage()]);
        sleep(5);  // Backoff
    }
}
?>
```

**Run as background service:**

**Windows (using NSSM or Task Scheduler):**
```bash
# Using NSSM
nssm install "ExpMgWEB-Health" "C:\xampp\php\php.exe" "C:\xampp\htdocs\ExpMgWEB\scripts\publish_health.php"
nssm start "ExpMgWEB-Health"
```

**Linux (using supervisor or systemd):**
```ini
# /etc/supervisor/conf.d/expmgweb-health.conf
[program:expmgweb-health]
command=php /var/www/ExpMgWEB/scripts/publish_health.php
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/expmgweb-health.log
```

### 7. Add Cache Invalidation

When updating data, invalidate related cache:

```php
// After user update
CacheService::forget("user:{$userId}");
CacheService::forget("user_groups:{$userId}:*");

// After expense update
CacheService::forget("expenses:{$userId}:*");
CacheService::forget("expense_summary:{$userId}:*");

// After group update
CacheService::forget("group:{$groupId}");
CacheService::forget("user_groups:*");

// After category update
CacheService::forget("categories");
```

### 8. Use Adaptive Health in Request Handler

Optionally adapt request behavior:

```php
// Before processing request
$config = PredictiveHealthService::getAdaptiveConfig();
$maxConcurrency = $config['config']['max_concurrency'];
$timeout = $config['config']['timeout_ms'];

// Use $timeout for request timeout
// Use $maxConcurrency for concurrency limiting
// Use circuit_break flag to reject non-essential requests
```

---

## Testing the Integration

```bash
# Run simple cache test
php -r "
require 'api/bootstrap.php';
CacheService::set('test', 'works', 10);
echo CacheService::get('test') ? 'Cache: OK' : 'Cache: FAIL';
"

# Run lock test
php -r "
require 'api/bootstrap.php';
if (LockService::acquireLock('test', 5)) {
    echo 'Lock: OK';
    LockService::releaseLock('test');
} else {
    echo 'Lock: FAIL';
}
"

# Run health test
php -r "
require 'api/bootstrap.php';
HealthService::publishNodeHealth('healthy', ['latency_ms' => 50, 'error_rate' => 0.5, 'queue_pressure' => 5]);
\$health = HealthService::getGlobalHealth();
echo isset(\$health['status']) ? 'Health: OK' : 'Health: FAIL';
"
```

---

## Monitoring & Debugging

### View Redis Keys

```bash
# Connect to Redis
redis-cli

# View all keys
KEYS *

# View specific service keys
KEYS system:health:*       # All node health
KEYS cache:*               # All cached values
KEYS lock:*                # All active locks
KEYS metrics:*             # All metrics

# View key details
GET system:global_health_cache
LRANGE metrics:latency:list 0 -1
```

### Check Health Status

```php
$global = HealthService::getGlobalHealth();
echo json_encode($global, JSON_PRETTY_PRINT);
```

### Monitor Metrics in Real-time

```php
while (true) {
    $metrics = MetricsService::getMetrics();
    $score = PredictiveHealthService::calculateScore();
    
    echo "\n[" . date('H:i:s') . "]";
    echo " Score: " . $score;
    echo " | Latency: " . $metrics['average_latency_ms'] . "ms";
    echo " | Errors: " . $metrics['error_rate'] . "%";
    echo " | Queue: " . $metrics['queue_length'];
    
    sleep(1);
}
```

---

## Common Issues

### Redis Not Connecting

Check `.env`:
```
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
```

Start Redis:
```bash
# Docker
docker run -d -p 6379:6379 redis:latest

# Windows (if installed)
redis-server.exe

# Linux
service redis-server start
```

### Cache Hit Rate Too Low

- Increase TTL values (300-600 seconds for stable data)
- Add more GET endpoints to cache layer
- Check cache keys match your query patterns

### Locks Taking Too Long

- Reduce lock TTL if operations are fast
- Check for deadlocks in critical sections
- Review concurrency settings

---

## Performance Metrics to Track

| Metric | Target | Tool |
|--------|--------|------|
| Cache hit rate | >70% | `CacheService::remember` usage |
| Avg latency | <100ms | `MetricsService::getMetrics()` |
| Error rate | <2% | `MetricsService::getMetrics()` |
| Health score | >80 | `PredictiveHealthService::calculateScore()` |
| Lock contention | <5% | `LockService::isLocked()` monitoring |

---

## Done ✓

You now have:

✅ Robust Redis-backed coordination layer  
✅ Distributed locking preventing race conditions  
✅ System-wide health awareness  
✅ Intelligent caching reducing DB load  
✅ Metrics collection for real-time insights  
✅ Predictive health scoring for proactive management  
✅ Comprehensive test suite  
✅ Graceful fallback when Redis is unavailable  

**Next:** Integrate into request handlers and monitor performance!
