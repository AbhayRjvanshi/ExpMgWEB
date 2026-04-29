<?php

require_once __DIR__ . '/notifications_config.php';
require_once __DIR__ . '/notification_store.php';

/**
 * Helper functions for publishing notifications from services or tests.
 *
 * In 'file' mode, these simply delegate to notifPublishToGroup() and
 * notifPublishToUsers(). In 'redis' mode, they use RedisNotificationService
 * when available, and fall back to the file-backed helpers on failure.
 */

/**
 * Publish a group-scoped notification to all members except the actor.
 *
 * @param mysqli $conn
 * @param int    $groupId
 * @param int    $actorId
 * @param string $type
 * @param string $message
 * @param int    $refId
 * @return bool
 */
function publishGroupNotification(
    mysqli $conn,
    int $groupId,
    int $actorId,
    string $type,
    string $message,
    int $refId
): bool {
    // Redis backend path (optional)
    if (NOTIFICATIONS_BACKEND === 'redis' && class_exists('RedisNotificationService')) {
        try {
            $service = new RedisNotificationService($conn);

            // Build recipient list from current group members
            $stmt = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $groupId);
                $stmt->execute();
                $res = $stmt->get_result();
                $recipients = [];
                while ($row = $res->fetch_assoc()) {
                    $recipients[] = (int) $row['user_id'];
                }
                $stmt->close();

                return $service->publish($recipients, $type, $message, $actorId, $refId, $groupId);
            }
        } catch (\Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'Redis group notification failed, falling back to file store', [
                    'group_id' => $groupId,
                    'error'    => $e->getMessage(),
                ]);
            }
            // fall through to file backend
        }
    }

    // File-backed fallback
    notifPublishToGroup($conn, $groupId, $actorId, $message, $type, $refId);
    return true;
}

/**
 * Publish a notification to an explicit set of user IDs.
 *
 * @param mysqli $conn
 * @param array  $userIds
 * @param int    $actorId
 * @param int    $groupId
 * @param string $type
 * @param string $message
 * @param int    $refId
 * @return bool
 */
function publishNotificationToUsers(
    mysqli $conn,
    array $userIds,
    int $actorId,
    int $groupId,
    string $type,
    string $message,
    int $refId
): bool {
    if (NOTIFICATIONS_BACKEND === 'redis' && class_exists('RedisNotificationService')) {
        try {
            $service = new RedisNotificationService($conn);
            return $service->publish($userIds, $type, $message, $actorId, $refId, $groupId);
        } catch (\Throwable $e) {
            if (function_exists('logMessage')) {
                logMessage('WARNING', 'Redis multi-user notification failed, falling back to file store', [
                    'group_id' => $groupId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    notifPublishToUsers($userIds, $actorId, $message, $type, $refId, $groupId, $conn);
    return true;
}

