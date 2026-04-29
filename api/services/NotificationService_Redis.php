<?php
require_once __DIR__ . '/../helpers/redis.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/NotificationStore.php';

/**
 * RedisNotificationService — Ephemeral notification system using Redis
 *
 * Notifications are temporary signals that disappear after consumption.
 * No permanent storage in MySQL — all notifications live in Redis with 3-day TTL.
 * 
 * Key Features:
 * - Immediate consumption (delete on read)
 * - Automatic expiration after 3 days
 * - Duplicate prevention
 * - Rate limiting
 * - Memory-bounded (max 50 notifications per user)
 */
class RedisNotificationService implements NotificationStore {

    private $redis;
    private $conn; // Keep for fetching user/group data

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        $this->redis = getRedis();
    }

    /**
     * Publish notification event to recipients
     * 
     * @param array $recipientIds Array of user IDs to notify
     * @param string $type Event type (e.g., 'expense_created', 'group_join')
     * @param string $message Human-readable message
     * @param int|null $actorId User who triggered the event
     * @param int|null $referenceId Related entity ID (expense_id, group_id, etc.)
     * @param int|null $groupId Group context (for rate limiting)
     * @return bool Success status
     */
    public function publish(
        array $recipientIds,
        string $type,
        string $message,
        ?int $actorId = null,
        ?int $referenceId = null,
        ?int $groupId = null
    ): bool {
        
        // Rate limiting (if group context exists)
        if ($groupId !== null && $this->redis->isRateLimited($groupId)) {
            logMessage('WARNING', 'Notification rate limit exceeded', [
                'group_id' => $groupId,
                'type' => $type
            ]);
            return false;
        }
        
        // Generate unique event ID (duplicate prevention)
        $eventId = $this->generateEventId($type, $referenceId, $actorId);
        
        // Check if event already published
        if ($this->redis->isEventSeen($eventId)) {
            logMessage('INFO', 'Duplicate notification event ignored', [
                'event_id' => $eventId,
                'type' => $type
            ]);
            return true; // Not an error - just skip
        }
        
        // Mark event as seen (1 hour TTL)
        $this->redis->markEventSeen($eventId, 3600);
        
        // Create event payload
        $event = [
            'event_id' => $eventId,
            'type' => $type,
            'message' => $message,
            'actor_id' => $actorId,
            'reference_id' => $referenceId,
            'group_id' => $groupId,
            'timestamp' => time()
        ];
        
        // Publish to all recipients
        $successCount = 0;
        foreach ($recipientIds as $userId) {
            // Don't notify the actor of their own action
            if ($actorId !== null && $userId === $actorId) {
                continue;
            }
            
            if ($this->redis->publishNotification($userId, $event)) {
                $successCount++;
            }
        }
        
        // Increment rate limit counter
        if ($groupId !== null) {
            $this->redis->incrementRateLimit($groupId);
        }
        
        logMessage('INFO', 'Notification published to recipients', [
            'type' => $type,
            'recipients' => count($recipientIds),
            'successful' => $successCount,
            'event_id' => $eventId
        ]);
        
        return $successCount > 0;
    }

    /**
     * Get unread notification count (for badge)
     * 
     * @param int $userId User ID
     * @return array Response with count and latest notification
     */
    public function getUnreadCount(int $userId): array {
        $count = $this->redis->getUnreadCount($userId);
        
        // Get latest notification
        $notifications = $this->redis->getNotifications($userId, 1);
        $latest = !empty($notifications) ? $notifications[0] : null;
        
        return [
            'ok' => true,
            'count' => $count,
            'latest' => $latest
        ];
    }

    /**
     * List user's notifications
     * 
     * @param int $userId User ID
     * @param int $limit Max notifications to return
     * @param int $page Page number (for pagination)
     * @return array Response with notifications
     */
    public function listNotifications(int $userId, int $limit = 30, int $page = 1): array {
        // Calculate offset
        $offset = ($page - 1) * $limit;
        
        // Get all notifications for user
        $allNotifications = $this->redis->getNotifications($userId, 100); // Get up to 100
        
        // Apply pagination
        $notifications = array_slice($allNotifications, $offset, $limit);
        
        // Total count
        $total = count($allNotifications);
        
        return [
            'ok' => true,
            'notifications' => $notifications,
            'unread_count' => $total, // All notifications in Redis are "unread"
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ];
    }

    /**
     * Consume (delete) a notification or all notifications for a user.
     * 
     * Called when user clicks notification or marks as read.
     * Notifications are immediately removed from Redis.
     * 
     * @param int         $userId  User ID
     * @param string|null $eventId Event ID to consume (ignored when $all = true)
     * @param bool        $all     Whether to clear all notifications for the user
     * @return array Response
     */
    public function consume(int $userId, ?string $eventId = null, bool $all = false): array {
        if ($all) {
            return $this->clearAll($userId);
        }

        if ($eventId === null || $eventId === '') {
            return ['ok' => false, 'error' => 'Event ID is required.'];
        }

        $success = $this->redis->consumeNotification($userId, $eventId);

        if ($success) {
            logMessage('INFO', 'Notification consumed', [
                'user_id'  => $userId,
                'event_id' => $eventId
            ]);
        }

        return [
            'ok'      => $success,
            'message' => $success ? 'Notification consumed' : 'Notification not found'
        ];
    }

    /**
     * Clear all notifications for a user
     * 
     * @param int $userId User ID
     * @return array Response
     */
    public function clearAll(int $userId): array {
        $success = $this->redis->clearAllNotifications($userId);
        
        return [
            'ok' => $success,
            'message' => $success ? 'All notifications cleared' : 'Failed to clear notifications'
        ];
    }

    /**
     * Generate unique event ID for duplicate prevention
     * 
     * @param string $type Event type
     * @param int|null $referenceId Reference entity ID
     * @param int|null $actorId Actor user ID
     * @return string Unique event ID
     */
    private function generateEventId(string $type, ?int $referenceId, ?int $actorId): string {
        // Include timestamp rounded to nearest second (prevents duplicates within 1 second)
        $timestamp = time();
        $data = "$type:$referenceId:$actorId:$timestamp";
        return hash('sha256', $data);
    }

    /**
     * Get notification statistics (for monitoring)
     * 
     * @return array Stats
     */
    public function getStats(): array {
        $memoryStats = $this->redis->getMemoryStats();
        
        return [
            'ok' => true,
            'redis_connected' => $this->redis->isConnected(),
            'memory' => $memoryStats
        ];
    }

    // ============================================================
    // LEGACY COMPATIBILITY METHODS (for gradual migration)
    // ============================================================

    /**
     * Mark as read (legacy method - now consumes notification)
     * 
     * @param int $userId User ID
     * @param string|null $eventId Event ID (if single notification)
     * @param bool $all Clear all notifications
     * @return array Response
     */
    public function markAsRead(int $userId, ?string $eventId = null, bool $all = false): array {
        if ($all) {
            return $this->clearAll($userId);
        }
        
        if ($eventId) {
            return $this->consume($userId, $eventId);
        }
        
        return ['ok' => true];
    }
}

// Backwards compatibility alias for older scripts that referenced
// NotificationService in this file directly.
class NotificationService extends RedisNotificationService {}
