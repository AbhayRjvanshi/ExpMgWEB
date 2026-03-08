<?php
/**
 * api/notifications/list.php — Fetch today's notifications for the logged-in user.
 * GET params (all optional):
 *   unread_only=1  — only unread
 *   limit=N        — max items (default 30)
 * Returns JSON: { ok, notifications[], unread_count }
 * Only returns notifications created today. Older ones are auto-cleaned.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$userId     = (int) $_SESSION['user_id'];
$unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] == '1';
$limit      = min(100, max(1, (int)($_GET['limit'] ?? 30)));

// Cleanup old notifications (older than 7 days)
$conn->query("DELETE FROM notifications WHERE DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 6 DAY)");

// Total unread count (today only)
$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0 AND DATE(created_at) = CURDATE()');
$stmt->bind_param('i', $userId);
$stmt->execute();
$unreadCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Fetch today's notifications
$sql = 'SELECT id, message, type, reference_id, is_read, created_at
        FROM notifications
        WHERE user_id = ? AND DATE(created_at) = CURDATE()';
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
