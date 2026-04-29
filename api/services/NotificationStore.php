<?php

/**
 * NotificationStore — backend contract for ephemeral notifications.
 *
 * Implementations provide a consistent read/consume API regardless of
 * whether storage is file-based or Redis-backed.
 */
interface NotificationStore
{
    /**
     * Get unread count and latest notification for a user.
     *
     * Expected shape: ['ok' => bool, 'count' => int, 'latest' => array|null]
     */
    public function getUnreadCount(int $userId): array;

    /**
     * List notifications for a user.
     *
     * Expected shape:
     *   [
     *     'ok'            => bool,
     *     'notifications' => array,
     *     'unread_count'  => int,
     *     'pagination'    => array
     *   ]
     */
    public function listNotifications(int $userId, int $limit = 30, int $page = 1): array;

    /**
     * Consume (delete) a single notification or all for a user.
     */
    public function consume(int $userId, ?string $eventId = null, bool $all = false): array;

    /**
     * Optional stats for monitoring (backend-specific).
     */
    public function getStats(): array;
}

