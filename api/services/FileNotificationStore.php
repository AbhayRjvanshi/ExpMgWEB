<?php

require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/notification_store.php';
require_once __DIR__ . '/NotificationStore.php';

/**
 * FileNotificationStore — NotificationStore implementation backed by JSON files.
 *
 * Uses the existing helpers from api/helpers/notification_store.php:
 *  - notifList(), notifCount(), notifLatest()
 *  - notifConsume(), notifConsumeAll(), notifCleanupStaleFiles()
 */
class FileNotificationStore implements NotificationStore
{
    public function __construct()
    {
    }

    public function getUnreadCount(int $userId): array
    {
        $count  = notifCount($userId);
        $latest = notifLatest($userId);

        $latestFormatted = null;
        if ($latest) {
            $latestFormatted = [
                'event_id'   => $latest['event_id'],
                'message'    => $latest['message'],
                'type'       => $latest['type'],
                'group_id'   => $latest['group_id'] ?? null,
                'ref_id'     => $latest['ref_id'] ?? null,
                'created_at' => date('Y-m-d H:i:s', $latest['ts']),
            ];
        }

        return ['ok' => true, 'count' => $count, 'latest' => $latestFormatted];
    }

    public function listNotifications(int $userId, int $limit = 30, int $page = 1): array
    {
        // Get all notifications and apply TTL purge
        $all   = notifList($userId);
        $total = count($all);

        // Paginate
        $offset = ($page - 1) * $limit;
        $slice  = array_slice($all, $offset, $limit);

        // Format for API response
        $notifs = [];
        foreach ($slice as $n) {
            $notifs[] = [
                'event_id'   => $n['event_id'],
                'message'    => $n['message'],
                'type'       => $n['type'],
                'group_id'   => $n['group_id'] ?? null,
                'ref_id'     => $n['ref_id'] ?? null,
                'created_at' => date('Y-m-d H:i:s', $n['ts']),
            ];
        }

        // Periodic cleanup of stale user files
        if (mt_rand(1, 20) === 1) {
            notifCleanupStaleFiles();
        }

        return [
            'ok'            => true,
            'notifications' => $notifs,
            'unread_count'  => $total,
            'pagination'    => paginationMeta($page, $limit, $total),
        ];
    }

    public function consume(int $userId, ?string $eventId = null, bool $all = false): array
    {
        if ($all) {
            $removed = notifConsumeAll($userId);
            return ['ok' => true, 'consumed' => $removed];
        }

        if ($eventId) {
            $found = notifConsume($userId, $eventId);
            if (!$found) {
                return ['ok' => false, 'error' => 'Notification not found.'];
            }
        } else {
            return ['ok' => false, 'error' => 'Event ID is required.'];
        }

        return ['ok' => true];
    }

    public function getStats(): array
    {
        // File backend has no external memory stats; return a minimal payload.
        return [
            'ok'              => true,
            'redis_connected' => false,
            'memory'          => null,
        ];
    }
}

