<?php
/**
 * api/notifications/history.php — Fetch last 7 days of notifications.
 * GET params (optional):
 *   limit=N  — max items (default 100)
 * Returns JSON: { ok, notifications[] }
 * Read-only: retention cleanup runs from scripts/cleanup_notifications.php.
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';

requireAuth();

$userId = (int) $_SESSION['user_id'];
// 7-day history is unbounded in principle, so it is paginated but with a high
// default ceiling — the history drawer expects the whole window, not 20 rows.
list($page, $limit, $offset) = parsePagination(500, 1000);

// GET must be read-only: retention cleanup lives in
// scripts/cleanup_notifications.php (cron), never in the request path.

// Fetch last 7 days of notifications. SARGable range predicate on the bare
// created_at column so MySQL can use idx_notif_created (a DATE() wrapper
// forces a full scan).
$since = 'user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)';

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE $since");
$stmt->bind_param('i', $userId);
$stmt->execute();
$totalCount = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$sql = "SELECT id, message, type, reference_id, is_read, created_at
        FROM notifications
        WHERE $since
        ORDER BY created_at DESC, id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iii', $userId, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
$notifs = [];
while ($row = $result->fetch_assoc()) {
    $row['is_read'] = (int)$row['is_read'];
    // Same compat alias as list.php: consumers speak event_id, MySQL has id.
    $row['event_id'] = (int)$row['id'];
    $notifs[] = $row;
}
$stmt->close();

echo json_encode([
    'ok'            => true,
    'notifications' => $notifs,
    'pagination'    => paginationMeta($page, $limit, $totalCount)
]);
