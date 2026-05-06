<?php
/**
 * api/notifications/history.php — Fetch last 7 days of notifications.
 * GET params (optional):
 *   limit=N  — max items (default 100)
 * Returns JSON: { ok, notifications[] }
 * Auto-cleans notifications older than 7 days.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

$userId = (int) $_SESSION['user_id'];
$limit  = min(200, max(1, (int)($_GET['limit'] ?? 100)));

// Cleanup notifications older than 7 days
$conn->query("DELETE FROM notifications WHERE DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 6 DAY)");

// Fetch last 7 days of notifications
$sql = "SELECT id, message, type, reference_id, is_read, created_at
        FROM notifications
        WHERE user_id = ? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        ORDER BY created_at DESC
        LIMIT ?";
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

echo json_encode(['ok' => true, 'notifications' => $notifs]);
