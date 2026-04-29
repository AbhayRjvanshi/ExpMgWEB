<?php
/**
 * notification_store.php — Ephemeral Notification Store (File-based).
 *
 * Drop-in replacement for Redis-backed notification storage.
 * Uses per-user JSON files under data/notifications/.
 *
 * Each notification is an event with:
 *   event_id  — SHA-256 dedup key
 *   type      — e.g. 'group_expense_add'
 *   message   — human-readable text
 *   group_id  — source group
 *   actor_id  — user who triggered the event
 *   ref_id    — relevant entity ID
 *   ts        — unix timestamp
 *
 * Constraints:
 *   - Max 50 notifications per user
 *   - 3-day TTL (259200 seconds)
 *   - Dedup via event_id within user file
 *   - 20 notifications/min/group rate limit
 */

define('NOTIF_DIR', __DIR__ . '/../../data/notifications');
define('NOTIF_MAX_PER_USER', 50);
define('NOTIF_TTL_SECONDS', 259200); // 3 days
define('NOTIF_RATE_LIMIT', 20);     // per group per minute

/**
 * Ensure the data directory exists.
 */
function notifEnsureDir(): void {
    if (!is_dir(NOTIF_DIR)) {
        mkdir(NOTIF_DIR, 0700, true);
    }
}

/**
 * Get the file path for a user's notification store.
 */
function notifFilePath(int $userId): string {
    return NOTIF_DIR . '/user_' . $userId . '.json';
}

/**
 * Rate-limit file path for a group.
 */
function notifRatePath(int $groupId): string {
    return NOTIF_DIR . '/rate_group_' . $groupId . '.json';
}

/**
 * Acquire a file lock for safe concurrent access.
 * Returns the lock file handle or false on failure.
 */
function notifLock(int $userId) {
    notifEnsureDir();
    $lockFile = NOTIF_DIR . '/user_' . $userId . '.lock';
    $fh = fopen($lockFile, 'c');
    if ($fh === false) return false;
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

/**
 * Release a file lock.
 */
function notifUnlock($fh): void {
    if ($fh) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Read all notifications for a user (raw array).
 */
function notifReadFile(int $userId): array {
    $path = notifFilePath($userId);
    if (!file_exists($path)) return [];
    $data = file_get_contents($path);
    if ($data === false || $data === '') return [];
    $arr = json_decode($data, true);
    return is_array($arr) ? $arr : [];
}

/**
 * Write notifications array for a user.
 */
function notifWriteFile(int $userId, array $notifs): void {
    notifEnsureDir();
    $path = notifFilePath($userId);
    file_put_contents($path, json_encode($notifs, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Purge expired notifications (older than TTL) from a user's list.
 */
function notifPurgeExpired(array &$notifs): void {
    $cutoff = time() - NOTIF_TTL_SECONDS;
    $notifs = array_values(array_filter($notifs, function ($n) use ($cutoff) {
        return ($n['ts'] ?? 0) >= $cutoff;
    }));
}

/**
 * Check group rate limit. Returns true if under limit.
 */
function notifCheckGroupRate(int $groupId): bool {
    notifEnsureDir();
    $path = notifRatePath($groupId);
    $now = time();
    $windowStart = $now - 60;

    $timestamps = [];
    if (file_exists($path)) {
        $data = file_get_contents($path);
        $timestamps = $data ? json_decode($data, true) : [];
        if (!is_array($timestamps)) $timestamps = [];
    }

    // Remove timestamps older than 1 minute
    $timestamps = array_values(array_filter($timestamps, function ($t) use ($windowStart) {
        return $t >= $windowStart;
    }));

    if (count($timestamps) >= NOTIF_RATE_LIMIT) {
        return false;
    }

    $timestamps[] = $now;
    file_put_contents($path, json_encode($timestamps), LOCK_EX);
    return true;
}

/**
 * Generate a dedup event_id from event properties.
 */
function notifEventId(string $type, int $actorId, int $refId, int $timestamp): string {
    return hash('sha256', "$type:$actorId:$refId:$timestamp");
}

/**
 * Publish a notification to a single user.
 *
 * @param int    $userId   Recipient user ID
 * @param array  $event    Notification event data
 * @return bool  True if stored, false if duplicate/rate-limited/error
 */
function notifPublish(int $userId, array $event): bool {
    $lock = notifLock($userId);
    if (!$lock) return false;

    try {
        $notifs = notifReadFile($userId);
        notifPurgeExpired($notifs);

        // Dedup check
        $eventId = $event['event_id'] ?? '';
        foreach ($notifs as $n) {
            if (($n['event_id'] ?? '') === $eventId) {
                return false; // duplicate
            }
        }

        // Prepend (newest first)
        array_unshift($notifs, $event);

        // Enforce max cap — trim oldest
        if (count($notifs) > NOTIF_MAX_PER_USER) {
            $notifs = array_slice($notifs, 0, NOTIF_MAX_PER_USER);
        }

        notifWriteFile($userId, $notifs);
        return true;
    } finally {
        notifUnlock($lock);
    }
}

/**
 * Record a notification event in the outbox when the helper is loaded.
 *
 * The outbox is best-effort: notification delivery must continue even if
 * persistence for later replay is unavailable.
 */
function notifQueueOutbox(?mysqli $conn, string $eventType, array $payload): void {
    if (!$conn instanceof mysqli || !function_exists('outboxQueueEvent')) {
        return;
    }

    try {
        outboxQueueEvent($conn, $eventType, $payload);
    } catch (Throwable $e) {
        if (function_exists('logMessage')) {
            $eventId = is_array($payload['event'] ?? null) ? (string) (($payload['event']['event_id'] ?? 'unknown')) : 'unknown';
            logMessage('ERROR', sprintf('[OUTBOX_FAIL] event_id=%s type=%s retry=%d error="%s"', $eventId, $eventType, 0, $e->getMessage()), [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'retry_count' => 0,
                'exception_type' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

/**
 * Publish a notification to all members of a group except the actor.
 *
 * @param mysqli $conn        DB connection (to look up group members)
 * @param int    $groupId     Group ID
 * @param int    $excludeUserId  User to exclude (the actor)
 * @param string $message     Human-readable message
 * @param string $type        Notification type
 * @param int    $refId       Reference entity ID
 */
function notifPublishToGroup(mysqli $conn, int $groupId, int $excludeUserId, string $message, string $type, int $refId): void {
    // Rate limit per group
    if (!notifCheckGroupRate($groupId)) {
        return;
    }

    $ts = time();
    $eventId = notifEventId($type, $excludeUserId, $refId, $ts);

    $event = [
        'event_id' => $eventId,
        'type'     => $type,
        'message'  => $message,
        'group_id' => $groupId,
        'actor_id' => $excludeUserId,
        'ref_id'   => $refId,
        'ts'       => $ts,
    ];

    notifQueueOutbox($conn, 'notification.group', [
        'mode' => 'group',
        'group_id' => $groupId,
        'exclude_user_id' => $excludeUserId,
        'event' => $event,
    ]);

    // Fetch group members
    $stmt = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ? AND user_id != ?');
    $stmt->bind_param('ii', $groupId, $excludeUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        notifPublish((int)$row['user_id'], $event);
    }
    $stmt->close();
}

/**
 * Publish a notification to specific user IDs (e.g. after group deletion).
 *
 * @param int[]  $userIds     Recipient user IDs
 * @param int    $actorId     Actor user ID
 * @param string $message     Human-readable message
 * @param string $type        Notification type
 * @param int    $refId       Reference entity ID
 * @param int    $groupId     Source group ID
 */
function notifPublishToUsers(array $userIds, int $actorId, string $message, string $type, int $refId, int $groupId, ?mysqli $conn = null): void {
    $ts = time();
    $eventId = notifEventId($type, $actorId, $refId, $ts);

    $event = [
        'event_id' => $eventId,
        'type'     => $type,
        'message'  => $message,
        'group_id' => $groupId,
        'actor_id' => $actorId,
        'ref_id'   => $refId,
        'ts'       => $ts,
    ];

    notifQueueOutbox($conn, 'notification.users', [
        'mode' => 'users',
        'user_ids' => array_values(array_map('intval', $userIds)),
        'event' => $event,
    ]);

    foreach ($userIds as $uid) {
        notifPublish((int)$uid, $event);
    }
}

/**
 * Get all notifications for a user (with expired ones purged).
 *
 * @param int $userId
 * @param int $limit  Max notifications to return
 * @return array
 */
function notifList(int $userId, int $limit = 50): array {
    $lock = notifLock($userId);
    if (!$lock) return [];

    try {
        $notifs = notifReadFile($userId);
        $before = count($notifs);
        notifPurgeExpired($notifs);

        // Write back if we purged anything
        if (count($notifs) !== $before) {
            notifWriteFile($userId, $notifs);
        }

        return array_slice($notifs, 0, $limit);
    } finally {
        notifUnlock($lock);
    }
}

/**
 * Get notification count for a user.
 */
function notifCount(int $userId): int {
    return count(notifList($userId));
}

/**
 * Get the latest notification for a user (for popup detection).
 */
function notifLatest(int $userId): ?array {
    $notifs = notifList($userId, 1);
    return $notifs[0] ?? null;
}

/**
 * Consume (delete) a single notification by event_id.
 * Immediate consumption — the notification is destroyed upon read.
 *
 * @param int    $userId
 * @param string $eventId
 * @return bool  True if found and removed
 */
function notifConsume(int $userId, string $eventId): bool {
    $lock = notifLock($userId);
    if (!$lock) return false;

    try {
        $notifs = notifReadFile($userId);
        notifPurgeExpired($notifs);

        $found = false;
        $notifs = array_values(array_filter($notifs, function ($n) use ($eventId, &$found) {
            if (($n['event_id'] ?? '') === $eventId) {
                $found = true;
                return false; // remove it
            }
            return true;
        }));

        if ($found) {
            notifWriteFile($userId, $notifs);
        }
        return $found;
    } finally {
        notifUnlock($lock);
    }
}

/**
 * Consume (delete) ALL notifications for a user.
 *
 * @param int $userId
 * @return int Number of notifications removed
 */
function notifConsumeAll(int $userId): int {
    $lock = notifLock($userId);
    if (!$lock) return 0;

    try {
        $notifs = notifReadFile($userId);
        notifPurgeExpired($notifs);
        $count = count($notifs);

        if ($count > 0) {
            notifWriteFile($userId, []);
        }
        return $count;
    } finally {
        notifUnlock($lock);
    }
}

/**
 * Cleanup: remove stale per-user files that are empty or fully expired.
 * Called periodically (e.g. on list endpoint).
 */
function notifCleanupStaleFiles(): void {
    if (!is_dir(NOTIF_DIR)) return;
    $now = time();

    foreach (glob(NOTIF_DIR . '/user_*.json') as $file) {
        $data = file_get_contents($file);
        if ($data === false || $data === '' || $data === '[]') {
            @unlink($file);
            continue;
        }
        $notifs = json_decode($data, true);
        if (!is_array($notifs) || empty($notifs)) {
            @unlink($file);
            continue;
        }
        // Check if all expired
        $cutoff = $now - NOTIF_TTL_SECONDS;
        $allExpired = true;
        foreach ($notifs as $n) {
            if (($n['ts'] ?? 0) >= $cutoff) {
                $allExpired = false;
                break;
            }
        }
        if ($allExpired) {
            @unlink($file);
        }
    }

    // Cleanup stale rate files (older than 2 minutes)
    foreach (glob(NOTIF_DIR . '/rate_group_*.json') as $file) {
        if (filemtime($file) < $now - 120) {
            @unlink($file);
        }
    }
}
