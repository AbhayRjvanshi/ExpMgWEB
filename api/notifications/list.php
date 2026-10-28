<?php
/**
 * api/notifications/list.php — Fetch today's notifications for the logged-in user.
 * GET params (all optional):
 *   unread_only=1  — only unread
 *   limit=N        — max items (default 30)
 * Returns JSON: { ok, notifications[], unread_count }
 * Only returns notifications created today. Older ones are auto-cleaned.
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

$userId     = (int) $_SESSION['user_id'];
$unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] == '1';
$limit      = min(100, max(1, (int)($_GET['limit'] ?? 30)));

// GET must be read-only: retention cleanup lives in
// scripts/cleanup_notifications.php (cron), never in the request path.

// Total unread count (today only, SARGable range)
$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0 AND created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)');
$stmt->bind_param('i', $userId);
$stmt->execute();
$unreadCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Fetch today's notifications (SARGable range)
$sql = 'SELECT id, message, type, reference_id, is_read, created_at
        FROM notifications
        WHERE user_id = ? AND created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
if ($unreadOnly) $sql .= ' AND is_read = 0';
$sql .= ' ORDER BY created_at DESC LIMIT ?';

$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $userId, $limit);
$stmt->execute();
$result = $stmt->get_result();
$notifs = [];
while ($row = $result->fetch_assoc()) {
    $row['is_read'] = (int)$row['is_read'];
    $notifs[] = $row;
}
$stmt->close();

echo json_encode([
    'ok'            => true,
    'notifications' => $notifs,
    'unread_count'  => $unreadCount
]);
