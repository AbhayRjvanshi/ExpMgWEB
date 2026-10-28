<?php
/**
 * api/notifications/list.php — Fetch today's notifications for the logged-in user.
 * GET params (all optional):
 *   unread_only=1  — only unread
 *   page=N         — 1-based page number (default 1)
 *   limit=N        — max items per page (default 30, cap 50)
 * Returns JSON: { ok, notifications[], unread_count, pagination }
 * Only returns notifications created today. Older ones are auto-cleaned.
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';

requireAuth();

$userId     = (int) $_SESSION['user_id'];
$unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] == '1';
// Default page size is 30 here (the notifications panel expects it); the
// other list endpoints use 500/1000 because their frontend never pages.
list($page, $limit, $offset) = parsePagination(30, 50);

// GET must be read-only: retention cleanup lives in
// scripts/cleanup_notifications.php (cron), never in the request path.

// Today's range predicate, shared by the count and the page query.
$where = 'user_id = ? AND created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
if ($unreadOnly) $where .= ' AND is_read = 0';

// Total unread count (today only, SARGable range)
$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0 AND created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)');
$stmt->bind_param('i', $userId);
$stmt->execute();
$unreadCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Total rows matching the current filter, for the pagination metadata
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE $where");
$stmt->bind_param('i', $userId);
$stmt->execute();
$totalCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Fetch today's notifications (SARGable range)
$sql = "SELECT id, message, type, reference_id, is_read, created_at
        FROM notifications
        WHERE $where
        ORDER BY created_at DESC, id DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('iii', $userId, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
$notifs = [];
while ($row = $result->fetch_assoc()) {
    $row['is_read'] = (int)$row['is_read'];
    // Compatibility alias: the ephemeral notification store (unwired, KD-05)
    // keys events by a string event_id, while MySQL uses the row id. Consumers
    // and the frontend speak event_id, so expose the id under both names.
    $row['event_id'] = (int)$row['id'];
    $notifs[] = $row;
}
$stmt->close();

echo json_encode([
    'ok'            => true,
    'notifications' => $notifs,
    'unread_count'  => $unreadCount,
    'pagination'    => paginationMeta($page, $limit, $totalCount)
]);
