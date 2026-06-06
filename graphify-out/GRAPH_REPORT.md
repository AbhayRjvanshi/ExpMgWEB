# Graph Report - .  (2026-06-03)

## Corpus Check
- 113 files · ~108,257 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 480 nodes · 835 edges · 92 communities (79 shown, 13 thin omitted)
- Extraction: 78% EXTRACTED · 22% INFERRED · 0% AMBIGUOUS · INFERRED: 185 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Frontend Logic and Helpers|Frontend Logic and Helpers]]
- [[_COMMUNITY_Stress Testing Runner|Stress Testing Runner]]
- [[_COMMUNITY_Outbox and Response Utilities|Outbox and Response Utilities]]
- [[_COMMUNITY_Notification Store and Storage|Notification Store and Storage]]
- [[_COMMUNITY_Redis Service Implementation|Redis Service Implementation]]
- [[_COMMUNITY_Health and System Orchestration|Health and System Orchestration]]
- [[_COMMUNITY_Rate Limiting and Auth|Rate Limiting and Auth]]
- [[_COMMUNITY_Env and Idempotency Config|Env and Idempotency Config]]
- [[_COMMUNITY_Redis Client Helpers|Redis Client Helpers]]
- [[_COMMUNITY_Distributed Integration Tests|Distributed Integration Tests]]
- [[_COMMUNITY_Redis Notification Service|Redis Notification Service]]
- [[_COMMUNITY_Lock Service and Concurrency|Lock Service and Concurrency]]
- [[_COMMUNITY_Cache Service|Cache Service]]
- [[_COMMUNITY_System Health Monitoring|System Health Monitoring]]
- [[_COMMUNITY_Metrics and Diagnostics|Metrics and Diagnostics]]
- [[_COMMUNITY_Documentation Concepts|Documentation Concepts]]
- [[_COMMUNITY_Project Unit Tests|Project Unit Tests]]
- [[_COMMUNITY_CSRF Protection|CSRF Protection]]
- [[_COMMUNITY_Composer and Dependencies|Composer and Dependencies]]
- [[_COMMUNITY_Stress Diagnostics|Stress Diagnostics]]
- [[_COMMUNITY_Community 23|Community 23]]
- [[_COMMUNITY_Community 24|Community 24]]
- [[_COMMUNITY_Community 70|Community 70]]

## God Nodes (most connected - your core abstractions)
1. `$()` - 53 edges
2. `RedisService` - 42 edges
3. `logMessage()` - 23 edges
4. `DistributedSystemTest` - 21 edges
5. `PredictiveHealthService` - 18 edges
6. `RedisClient` - 17 edges
7. `MetricsService` - 16 edges
8. `hide()` - 15 edges
9. `LockService` - 14 edges
10. `outboxLogFailure()` - 13 edges

## Surprising Connections (you probably didn't know these)
- `cleanupArtifacts()` --calls--> `notifConsumeAll()`  [INFERRED]
  scripts/stress/phase5_runner.php → api/helpers/notification_store.php
- `rateLimiterBackend()` --calls--> `env()`  [INFERRED]
  api/helpers/rate_limiter.php → config/env.php
- `cleanupTestData()` --calls--> `MetricsService`  [INFERRED]
  tests/run_tests.php → api/services/MetricsService.php
- `Durable Outbox Pattern` --conceptually_related_to--> `RedisService`  [INFERRED]
  README.md → DISTRIBUTED_ARCHITECTURE.md
- `processOnce()` --calls--> `outboxEventIdFromPayload()`  [INFERRED]
  scripts/outbox_validation.php → api/helpers/outbox.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Distributed Infrastructure Layer** — distributed_architecture_redisservice, distributed_architecture_lockservice, distributed_architecture_healthservice, distributed_architecture_cacheservice, distributed_architecture_metricsservice, distributed_architecture_predictivehealthservice [EXTRACTED 1.00]
- **Settlement Transparency System** — readme_per_member_settlement_confirmation, readme_greedy_debt_minimization_algorithm [INFERRED 0.95]

## Communities (92 total, 13 thin omitted)

### Community 0 - "Frontend Logic and Helpers"
Cohesion: 0.07
Nodes (66): bindEvents(), closeModal(), deleteExpense(), ensureCategoriesLoaded(), fmtCalendarMoney(), formatDateNice(), loadDayExpenses(), loadNotifications() (+58 more)

### Community 1 - "Stress Testing Runner"
Cohesion: 0.09
Nodes (36): mysqli_stmt, mysqli, mysqli, mysqli, bindParams(), cleanupArtifacts(), cookieHeader(), createExpenseRequest() (+28 more)

### Community 2 - "Outbox and Response Utilities"
Cohesion: 0.16
Nodes (31): mysqli, logMessage(), outboxClaimDueEvents(), outboxDispatchNotificationPayload(), outboxEnsureTable(), outboxEventIdFromPayload(), outboxEventKey(), outboxFetchPending() (+23 more)

### Community 3 - "Notification Store and Storage"
Cohesion: 0.13
Nodes (27): mysqli, mysqli, publishGroupNotification(), publishNotificationToUsers(), notifCheckGroupRate(), notifCleanupStaleFiles(), notifConsume(), notifConsumeAll() (+19 more)

### Community 6 - "Rate Limiting and Auth"
Cohesion: 0.32
Nodes (16): mysqli, checkRateLimit(), cleanupRateLimits(), rateLimiterActiveCooldown(), rateLimiterBackend(), rateLimiterCooldownAction(), rateLimiterCooldownRedisKey(), rateLimiterCooldownWindow() (+8 more)

### Community 7 - "Env and Idempotency Config"
Cohesion: 0.21
Nodes (14): env(), idempotencyBackend(), idempotencyBegin(), idempotencyBuildReplay(), idempotencyCacheKey(), idempotencyCleanupExpired(), idempotencyConflictResponse(), idempotencyEnsureDir() (+6 more)

### Community 10 - "Redis Notification Service"
Cohesion: 0.22
Nodes (3): mysqli, NotificationService, RedisNotificationService

### Community 16 - "Documentation Concepts"
Cohesion: 0.20
Nodes (10): Adaptive Health Scoring, CacheService, HealthService, LockService, MetricsService, PredictiveHealthService, RedisService, API Bootstrap Integration (+2 more)

### Community 17 - "Project Unit Tests"
Cohesion: 0.28
Nodes (5): apiGet(), apiPost(), cleanupTestData(), http(), mysqli

### Community 18 - "CSRF Protection"
Cohesion: 0.48
Nodes (6): generateCsrfToken(), getCsrfToken(), recordCsrfFailure(), rotateCsrfToken(), verifyCsrf(), verifyCsrfIfNeeded()

## Knowledge Gaps
- **20 isolated node(s):** `Throwable`, `mysqli`, `vlucas/phpdotenv`, `CSRF_TOKEN`, `expmgRequestErrors` (+15 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **13 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `logMessage()` connect `Outbox and Response Utilities` to `Notification Store and Storage`, `Rate Limiting and Auth`, `Env and Idempotency Config`, `Redis Notification Service`, `CSRF Protection`?**
  _High betweenness centrality (0.101) - this node is a cross-community bridge._
- **Why does `processOutboxBatch()` connect `Outbox and Response Utilities` to `Stress Testing Runner`?**
  _High betweenness centrality (0.028) - this node is a cross-community bridge._
- **Why does `env()` connect `Env and Idempotency Config` to `Rate Limiting and Auth`?**
  _High betweenness centrality (0.027) - this node is a cross-community bridge._
- **Are the 20 inferred relationships involving `$()` (e.g. with `bindEvents()` and `closeModal()`) actually correct?**
  _`$()` has 20 INFERRED edges - model-reasoned connections that need verification._
- **Are the 23 inferred relationships involving `RedisService` (e.g. with `.delete()` and `.forget()`) actually correct?**
  _`RedisService` has 23 INFERRED edges - model-reasoned connections that need verification._
- **Are the 22 inferred relationships involving `logMessage()` (e.g. with `getCsrfToken()` and `recordCsrfFailure()`) actually correct?**
  _`logMessage()` has 22 INFERRED edges - model-reasoned connections that need verification._
- **Are the 5 inferred relationships involving `PredictiveHealthService` (e.g. with `.getModeSnapshot()` and `.getSystemMode()`) actually correct?**
  _`PredictiveHealthService` has 5 INFERRED edges - model-reasoned connections that need verification._